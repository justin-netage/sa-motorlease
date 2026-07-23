<?php
/**
 * Background WebP conversion + serving (replaces the CompressX plugin).
 *
 * Design constraints (why this looks the way it does):
 *  - The importer's diff sync is content-addressed on the RAW feed bytes
 *    (`_vi_image_md5` / `_vi_images_hash`), so original JPEG/PNG files and
 *    attachment URLs are never touched. WebP copies are generated as
 *    `{file}.webp` siblings and swapped in at render time only.
 *  - The site sits behind Cloudflare, which does not vary its cache on the
 *    Accept header for standard plans. URL-swapping (distinct .webp URLs in
 *    the markup) is therefore used instead of .htaccess content negotiation,
 *    so each variant caches under its own URL.
 *  - Conversion runs from a batched, time-budgeted cron (newest attachments
 *    first) rather than inside the import pipeline, so import runs keep their
 *    existing wall-clock budgets. New vehicle images pick up a WebP copy on
 *    the next tick (≤5 min).
 *
 * Config (override via wp-config.php constants):
 *   SA_WEBP_QUALITY     - lossy quality 0-100        (default: 80)
 *   SA_WEBP_BATCH_SIZE  - attachments per cron tick  (default: 20)
 *   SA_WEBP_MAX_SECONDS - time cap per tick in s     (default: 45)
 *   SA_WEBP_DISABLE     - true = stop serving .webp  (files are kept)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function sa_webp_log( $msg ) {
    if ( function_exists( 'sa_motorlease_log' ) && function_exists( 'sa_motorlease_log_classify' ) ) {
        sa_motorlease_log( 'webp', sa_motorlease_log_classify( $msg ), $msg );
    }
}

/* ===========================================================================
 * Capability + conversion primitives
 * ======================================================================== */

/** 'imagick' | 'gd' | false — which WebP encoder is available. */
function sa_webp_method() {
    static $method = null;
    if ( $method !== null ) return $method;

    if ( class_exists( 'Imagick' ) && in_array( 'WEBP', Imagick::queryFormats( 'WEBP' ), true ) ) {
        return $method = 'imagick';
    }
    if ( function_exists( 'imagewebp' ) ) {
        $gd = function_exists( 'gd_info' ) ? gd_info() : [];
        if ( ! empty( $gd['WebP Support'] ) ) return $method = 'gd';
    }
    return $method = false;
}

/**
 * Convert one JPEG/PNG file to a `{file}.webp` sibling.
 * Returns 'converted' | 'current' (up-to-date copy already there) |
 * 'skipped_larger' (WebP would be bigger — not kept) | 'error'.
 */
function sa_webp_convert_file( $src ) {
    if ( ! file_exists( $src ) ) return 'error';

    $dest = $src . '.webp';
    if ( file_exists( $dest ) && filemtime( $dest ) >= filemtime( $src ) ) {
        return 'current';
    }

    $quality = defined( 'SA_WEBP_QUALITY' ) ? (int) SA_WEBP_QUALITY : 80;
    $method  = sa_webp_method();
    $ok      = false;

    try {
        if ( $method === 'imagick' ) {
            $im = new Imagick( $src );
            $im->setImageFormat( 'webp' );
            $im->setImageCompressionQuality( $quality );
            $im->stripImage();
            $ok = $im->writeImage( $dest );
            $im->clear();
        } elseif ( $method === 'gd' ) {
            $type = wp_check_filetype( $src )['ext'] ?? '';
            $img  = in_array( $type, [ 'jpg', 'jpeg', 'jpe' ], true )
                ? @imagecreatefromjpeg( $src )
                : @imagecreatefrompng( $src );
            if ( $img ) {
                if ( ! imageistruecolor( $img ) ) imagepalettetotruecolor( $img );
                imagealphablending( $img, false );
                imagesavealpha( $img, true );
                $ok = imagewebp( $img, $dest, $quality );
                imagedestroy( $img );
            }
        }
    } catch ( Exception $e ) {
        sa_webp_log( 'Convert failed for ' . basename( $src ) . ': ' . $e->getMessage() );
    }

    if ( ! $ok || ! file_exists( $dest ) ) {
        if ( file_exists( $dest ) ) @unlink( $dest );
        return 'error';
    }

    // Keep the copy only when it actually saves bytes; the render-time swap
    // simply serves the original wherever no .webp sibling exists.
    if ( filesize( $dest ) >= filesize( $src ) ) {
        @unlink( $dest );
        return 'skipped_larger';
    }
    return 'converted';
}

/**
 * Convert the original + every registered sub-size of one attachment.
 * Marks `_sa_webp_done` so the cron query skips it on later passes.
 */
function sa_webp_convert_attachment( $att_id ) {
    $file = get_attached_file( $att_id );
    if ( ! $file ) {
        update_post_meta( $att_id, '_sa_webp_done', 'no-file' );
        return [ 'converted' => 0, 'failed' => 1 ];
    }

    $paths = [ $file ];
    $meta  = wp_get_attachment_metadata( $att_id );
    if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
        $dir = dirname( $file );
        foreach ( $meta['sizes'] as $size ) {
            if ( ! empty( $size['file'] ) ) $paths[] = $dir . '/' . $size['file'];
        }
    }

    $counts = [ 'converted' => 0, 'failed' => 0 ];
    foreach ( array_unique( $paths ) as $path ) {
        $r = sa_webp_convert_file( $path );
        if ( $r === 'error' ) $counts['failed']++;
        if ( $r === 'converted' ) $counts['converted']++;
    }

    update_post_meta( $att_id, '_sa_webp_done', time() );
    return $counts;
}

/* ===========================================================================
 * Batched cron (newest attachments first, wall-clock budget per tick)
 * ======================================================================== */

add_action( 'init', function () {
    if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) return;
    if ( ! wp_next_scheduled( 'sa_webp_convert_cron' ) ) {
        wp_schedule_event( time() + 90, 'vi_five_minutes', 'sa_webp_convert_cron' );
        sa_webp_log( 'Scheduled sa_webp_convert_cron (every 5 minutes).' );
    }
} );

register_deactivation_hook( SA_MOTORLEASE_FILE, function () {
    wp_clear_scheduled_hook( 'sa_webp_convert_cron' );
} );

add_action( 'sa_webp_convert_cron', function () {
    if ( ! sa_webp_method() ) {
        sa_webp_log( 'WebP tick skipped: no encoder available (need Imagick or GD with WebP support).' );
        return;
    }

    $batch  = defined( 'SA_WEBP_BATCH_SIZE' )  ? (int) SA_WEBP_BATCH_SIZE  : 20;
    $budget = defined( 'SA_WEBP_MAX_SECONDS' ) ? (int) SA_WEBP_MAX_SECONDS : 45;
    $start  = microtime( true );

    $ids = get_posts( [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => [ 'image/jpeg', 'image/png' ],
        'posts_per_page' => $batch,
        'orderby'        => 'ID',
        'order'          => 'DESC', // newest first so fresh vehicle imports convert soonest
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [ [ 'key' => '_sa_webp_done', 'compare' => 'NOT EXISTS' ] ],
    ] );

    if ( ! $ids ) return; // steady state — everything converted

    $done = 0; $files = 0; $failed = 0;
    foreach ( $ids as $id ) {
        $c = sa_webp_convert_attachment( $id );
        $done++;
        $files  += $c['converted'];
        $failed += $c['failed'];
        if ( ( microtime( true ) - $start ) > $budget ) break;
    }

    sa_webp_log( sprintf(
        'WebP tick: %d attachment(s) processed (%d file(s) converted, %d failed) in %.1fs (method=%s).',
        $done, $files, $failed, microtime( true ) - $start, sa_webp_method()
    ) );
} );

// Thumbnail regeneration (or any metadata rebuild) replaces sub-size files in
// place — drop the done-marker so the cron refreshes the now-stale copies.
add_filter( 'wp_generate_attachment_metadata', function ( $meta, $att_id ) {
    delete_post_meta( $att_id, '_sa_webp_done' );
    return $meta;
}, 20, 2 );

// When WP deletes an image file (attachment delete / importer stale-image
// cleanup), remove its .webp sibling too.
add_filter( 'wp_delete_file', function ( $file ) {
    if ( $file && file_exists( $file . '.webp' ) ) @unlink( $file . '.webp' );
    return $file;
} );

/* ===========================================================================
 * Render-time URL swap (serving)
 * ======================================================================== */

/** Swap an uploads URL for its .webp sibling when the converted file exists. */
function sa_webp_swap_url( $url ) {
    static $base = null;
    if ( $base === null ) {
        $u    = wp_upload_dir();
        $base = [ $u['baseurl'], $u['basedir'] ];
    }
    if ( ! is_string( $url ) || strpos( $url, $base[0] ) !== 0 ) return $url;

    $rel = substr( $url, strlen( $base[0] ) );
    if ( ( $q = strpos( $rel, '?' ) ) !== false ) $rel = substr( $rel, 0, $q );

    return file_exists( $base[1] . $rel . '.webp' ) ? $base[0] . $rel . '.webp' : $url;
}

/** Swap everywhere except real admin screens (admin-ajax renders front-end cards). */
function sa_webp_should_serve() {
    if ( defined( 'SA_WEBP_DISABLE' ) && SA_WEBP_DISABLE ) return false;
    return ! is_admin() || wp_doing_ajax();
}

// Covers wp_get_attachment_image_url()/get_the_post_thumbnail()/Woo loops+galleries.
add_filter( 'wp_get_attachment_image_src', function ( $image ) {
    if ( ! sa_webp_should_serve() || ! is_array( $image ) || empty( $image[0] ) ) return $image;
    $image[0] = sa_webp_swap_url( $image[0] );
    return $image;
} );

add_filter( 'wp_calculate_image_srcset', function ( $sources ) {
    if ( ! sa_webp_should_serve() || ! is_array( $sources ) ) return $sources;
    foreach ( $sources as &$s ) {
        if ( ! empty( $s['url'] ) ) $s['url'] = sa_webp_swap_url( $s['url'] );
    }
    return $sources;
} );

/* ===========================================================================
 * LCP: high-priority first row of theme/Woo product thumbnails
 *
 * The listings pages render their product grids through the theme's
 * WooCommerce loop (wp_get_attachment_image). Core only puts
 * fetchpriority="high" on the very first content image, but with a multi-
 * column first row the LCP element is often the 2nd–5th thumbnail, so
 * Lighthouse keeps flagging "fetchpriority=high should be applied". Mark the
 * whole first row. Lives in this always-loaded module (NOT vehicle-filter.php,
 * which is gated behind a setting that is off in production — the 2.6.10
 * placement there meant the filter never ran on the live site).
 * ======================================================================== */

add_filter( 'wp_get_attachment_image_attributes', 'sa_ml_priority_loop_thumbs', 20, 3 );

function sa_ml_priority_loop_thumbs( $attr, $attachment, $size ) {
    static $count = 0;

    if ( is_admin() || wp_doing_ajax() || $size !== 'woocommerce_thumbnail' ) {
        return $attr;
    }
    if ( $count >= 5 ) return $attr; // first visible row: 5 cols desktop, 2 mobile
    $count++;

    $attr['fetchpriority'] = 'high';
    $attr['loading']       = 'eager'; // high priority + lazy is contradictory
    return $attr;
}

/* ===========================================================================
 * Status page data
 * ======================================================================== */

/** Counts for the admin status page: eligible / converted / pending. */
function sa_webp_status_counts() {
    global $wpdb;
    $eligible = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_type = 'attachment' AND post_status = 'inherit'
           AND post_mime_type IN ('image/jpeg','image/png')"
    );
    $done = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
         WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
           AND p.post_mime_type IN ('image/jpeg','image/png')",
        '_sa_webp_done'
    ) );
    return [
        'eligible' => $eligible,
        'done'     => $done,
        'pending'  => max( 0, $eligible - $done ),
        'method'   => sa_webp_method(),
    ];
}
