<?php
/**
 * Plugin Name: SA Motorlease
 * Description: Combined SA Motorlease plugin. Imports vehicles from the PaceApp feed into WooCommerce (create/update/prune + image repair), and provides lead qualification (REST + DB table), Gravity Forms #5 forwarding, application/qualification frontend scripts, vehicle-locations carousel data, sold-product/duplicate/missing-feed cleanup utilities, attribute backfills and CSV export.
 * Version: 2.4.0
 * Author: Net Age
 */

if (!defined('ABSPATH')) exit;

define( 'SA_MOTORLEASE_VERSION', '2.4.0' );
define( 'SA_MOTORLEASE_FILE', __FILE__ );
define( 'SA_MOTORLEASE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SA_MOTORLEASE_URL', plugin_dir_url( __FILE__ ) );

require_once SA_MOTORLEASE_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

$sa_motorlease_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/justin-netage/sa-motorlease/',
    SA_MOTORLEASE_FILE,
    'sa-motorlease'
);
$sa_motorlease_update_checker->setBranch( 'main' );
$sa_motorlease_update_checker->getVcsApi()->enableReleaseAssets();

// Custom vehicle filter — self-contained [sa_vehicle_filter] shortcode that
// replaces the WBW / WooBeWoo Product Filter on the vehicles archive.
require_once SA_MOTORLEASE_DIR . 'includes/vehicle-filter.php';

// === CONFIG =================================================================

// New product create cap per run
if (!defined('VI_MAX_VEHICLES_PER_RUN')) define('VI_MAX_VEHICLES_PER_RUN', 10);
// Existing product update cap per run
if (!defined('VI_MAX_UPDATES_PER_RUN')) define('VI_MAX_UPDATES_PER_RUN', 20);
// Maximum wall clock seconds allowed for one import run (safety for cron)
if (!defined('VI_MAX_RUNTIME_SEC')) define('VI_MAX_RUNTIME_SEC', 360);
// Separate time budgets for create and update passes
if (!defined('VI_MAX_CREATE_SEC')) define('VI_MAX_CREATE_SEC', 300);
if (!defined('VI_MAX_UPDATE_SEC')) define('VI_MAX_UPDATE_SEC', 300);
// Enable/disable pruning of products not in the feed (true = remove)
if (!defined('VI_PRUNE_NONFEED_PRODUCTS')) define('VI_PRUNE_NONFEED_PRODUCTS', true);
// true = don't delete, only log (for pruning or post-attach remove-on-no-images)
if (!defined('VI_PRUNE_DRY_RUN')) define('VI_PRUNE_DRY_RUN', false);

// === Central logger + retention =============================================
//
// All plugin logging routes through sa_motorlease_log() and lands in a single
// rolling file: sa-motorlease.log (in this plugin directory). Each line is
// tagged with [channel] and [LEVEL]. Lines below SA_MOTORLEASE_LOG_LEVEL are
// silently dropped, so flipping the threshold to DEBUG temporarily restores
// verbose tracing without touching call sites.
//
// A daily WP-cron event (sa_motorlease_log_rotate_daily) trims any line
// older than SA_MOTORLEASE_LOG_RETENTION_DAYS days.

if (!defined('SA_MOTORLEASE_LOG_ERROR')) define('SA_MOTORLEASE_LOG_ERROR', 10);
if (!defined('SA_MOTORLEASE_LOG_WARN'))  define('SA_MOTORLEASE_LOG_WARN',  20);
if (!defined('SA_MOTORLEASE_LOG_INFO'))  define('SA_MOTORLEASE_LOG_INFO',  30);
if (!defined('SA_MOTORLEASE_LOG_DEBUG')) define('SA_MOTORLEASE_LOG_DEBUG', 40);

// Threshold: lines AT THIS LEVEL OR MORE IMPORTANT (smaller number) are written.
if (!defined('SA_MOTORLEASE_LOG_LEVEL'))           define('SA_MOTORLEASE_LOG_LEVEL', SA_MOTORLEASE_LOG_WARN);
if (!defined('SA_MOTORLEASE_LOG_RETENTION_DAYS'))  define('SA_MOTORLEASE_LOG_RETENTION_DAYS', 21);

function sa_motorlease_log_path() {
    if (!defined('VEHICLE_IMPORT_PLUGIN_FILE')) {
        define('VEHICLE_IMPORT_PLUGIN_FILE', __FILE__);
    }
    $dir = plugin_dir_path(VEHICLE_IMPORT_PLUGIN_FILE);
    if (substr($dir, -1) !== DIRECTORY_SEPARATOR) $dir .= DIRECTORY_SEPARATOR;
    return $dir . 'sa-motorlease.log';
}

function sa_motorlease_log($channel, $level, $msg) {
    if ((int)$level > (int)SA_MOTORLEASE_LOG_LEVEL) return;
    static $labels = [
        SA_MOTORLEASE_LOG_ERROR => 'ERROR',
        SA_MOTORLEASE_LOG_WARN  => 'WARN',
        SA_MOTORLEASE_LOG_INFO  => 'INFO',
        SA_MOTORLEASE_LOG_DEBUG => 'DEBUG',
    ];
    $label = isset($labels[$level]) ? $labels[$level] : 'INFO';
    $line  = sprintf('[%s] [%s] [%s] %s%s',
        date('Y-m-d H:i:s'), $channel, $label, $msg, PHP_EOL);
    $file  = sa_motorlease_log_path();
    if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
        error_log('[SA Motorlease][WRITE FAIL] ' . $msg);
    }
}

/**
 * Auto-classify legacy messages that don't pass an explicit level. Errors and
 * skips/failures map to ERROR/WARN so they pass the default threshold; the
 * "✅ done" / "Updated X" success spam classifies as INFO and is dropped.
 */
function sa_motorlease_log_classify($msg) {
    $s = strtolower((string)$msg);
    if (strpos($s, '[error') !== false || strpos($s, 'fatal') !== false || strpos($s, 'exception') !== false) {
        return SA_MOTORLEASE_LOG_ERROR;
    }
    if (
        strpos($msg, '❌') !== false ||
        strpos($s, 'failed')   !== false ||
        strpos($s, 'error')    !== false ||
        strpos($s, '[skip')    !== false ||
        strpos($s, 'invalid')  !== false ||
        strpos($s, 'wp_error') !== false ||
        strpos($s, 'unable')   !== false ||
        strpos($s, 'missing')  !== false ||
        strpos($s, 'no images') !== false ||
        strpos($s, 'not found') !== false ||
        strpos($s, 'non-200')   !== false
    ) {
        return SA_MOTORLEASE_LOG_WARN;
    }
    return SA_MOTORLEASE_LOG_INFO;
}

/**
 * Trim sa-motorlease.log to the last SA_MOTORLEASE_LOG_RETENTION_DAYS days.
 * Cheap short-circuit if the first line is already within window.
 */
function sa_motorlease_log_rotate() {
    $file = sa_motorlease_log_path();
    if (!file_exists($file) || filesize($file) === 0) return;
    $cutoff = strtotime('-' . (int)SA_MOTORLEASE_LOG_RETENTION_DAYS . ' days');
    if ($cutoff === false) return;

    // Peek at the first line; if it's already within window, nothing to do.
    $peek = @fopen($file, 'r');
    if (!$peek) return;
    $first = fgets($peek);
    fclose($peek);
    if ($first && preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $first, $m)) {
        if (strtotime($m[1]) >= $cutoff) return;
    }

    $tmp = $file . '.tmp';
    $in  = @fopen($file, 'r');
    $out = @fopen($tmp, 'w');
    if (!$in || !$out) {
        if ($in)  fclose($in);
        if ($out) fclose($out);
        return;
    }
    $keeping = false;
    while (($line = fgets($in)) !== false) {
        if (!$keeping && preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
            if (strtotime($m[1]) < $cutoff) continue;
            $keeping = true;
        }
        fputs($out, $line);
    }
    fclose($in);
    fclose($out);

    // Atomic-ish swap. Windows rename() fails when target exists.
    if (PHP_OS_FAMILY === 'Windows' && file_exists($file)) @unlink($file);
    @rename($tmp, $file);
}

add_action('sa_motorlease_log_rotate_daily', 'sa_motorlease_log_rotate');
add_action('init', function () {
    if (!wp_next_scheduled('sa_motorlease_log_rotate_daily')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'sa_motorlease_log_rotate_daily');
    }
});

// === Legacy logger shims (route everything through sa_motorlease_log) =======

function vehicle_import_log_path() {
    // Back-compat: external callers still resolve a path even though we no
    // longer write to vehicle-importer.log. Return the new consolidated file.
    return sa_motorlease_log_path();
}

function log_import_update($msg){
    sa_motorlease_log('import', sa_motorlease_log_classify($msg), $msg);
}

function log_skip_product($sku, $reason, $extra = []){
    $payload = '[SKIP ' . $sku . '] ' . $reason;
    if (!empty($extra)) $payload .= ' ' . wp_json_encode($extra);
    sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, $payload);
}

// === Currency formatting =====================================================

function format_to_currency_value($val) {
    $n = floatval($val);
    // Thousands separated by commas, no decimals
    return 'R' . number_format($n, 0, '.', ',');
}

function wbw_index_after_import_smart($timeout = 60) {
    $lock_key = 'wbw_indexing_lock';
    if (get_transient($lock_key)) {
        log_import_update('[WBW Index] Skipping: lock present (recent run).');
        return false;
    }
    set_transient($lock_key, 1, 5 * MINUTE_IN_SECONDS);

    // WBW's meta controller checks current_user_can('manage_options') internally.
    // In cron context there is no logged-in user, so impersonate the first admin.
    if ( ! is_user_logged_in() ) {
        $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
        if ( ! empty( $admins ) ) {
            wp_set_current_user( $admins[0] );
            log_import_update( '[WBW Index] Set current user to admin ID ' . $admins[0] . ' for indexing.' );
        }
    }

    // Always try direct call first — WBW plugins are loaded in both cron and
    // non-cron contexts, so this works reliably without needing auth cookies.
    if (wbw_try_direct_indexing_wpf()) {
        log_import_update('[WBW Index] Direct indexing OK.');
        return true;
    }

    // Fall back to AJAX only when the framework classes aren't available in
    // the current process (e.g. WBW inactive or not yet bootstrapped).
    $ctx = (function_exists('wp_doing_cron') && wp_doing_cron()) ? 'cron' : 'non-cron';
    log_import_update("[WBW Index] Direct indexing unavailable ({$ctx}); falling back to admin-ajax.");
    $ok = wbw_try_ajax_indexing_wpf($timeout);
    log_import_update($ok ? '[WBW Index] AJAX indexing OK.' : '[WBW Index] AJAX indexing failed.');
    return $ok;
}

/**
 * Safely call a WBW AJAX-style method without letting it kill the current page.
 *
 * doMetaIndexingFree() ends with wp_send_json() → wp_die() → exit().
 * We intercept wp_die via a filter, capture the JSON it was about to send,
 * and return it as a string so the caller can parse progress info.
 *
 * Returns the captured JSON string, or empty string on plain return.
 */
function wbw_safe_call( callable $fn ): string {
    $captured = '';

    // Override wp_die so exit() doesn't kill the page.
    $die_handler = static function( $msg ) use ( &$captured ) {
        $captured = is_string( $msg ) ? $msg : wp_json_encode( $msg );
        throw new \RuntimeException( '__wbw_die_intercepted__' );
    };
    $filter_fn = static function() use ( $die_handler ) { return $die_handler; };
    add_filter( 'wp_die_handler', $filter_fn, PHP_INT_MAX );

    ob_start();
    try {
        $fn();
        $captured = ob_get_clean() ?: $captured;
    } catch ( \RuntimeException $e ) {
        $buffered = ob_get_clean();
        if ( $e->getMessage() !== '__wbw_die_intercepted__' ) {
            remove_filter( 'wp_die_handler', $filter_fn, PHP_INT_MAX );
            throw $e;
        }
        $captured = $captured ?: $buffered;
    } catch ( \Throwable $e ) {
        ob_get_clean();
        remove_filter( 'wp_die_handler', $filter_fn, PHP_INT_MAX );
        throw $e;
    }

    remove_filter( 'wp_die_handler', $filter_fn, PHP_INT_MAX );
    return (string) $captured;
}

/** Direct call (works whenever WBW classes are loaded, e.g. admin or after plugins_loaded) */
function wbw_try_direct_indexing_wpf() {
    try {
        // function_exists() tests for functions, not classes — use class_exists().
        if ( ! class_exists( 'FrameWpf' ) ) {
            return false;
        }

        $frame = FrameWpf::_();
        $meta  = $frame ? $frame->getModule( 'meta' ) : null;
        if ( ! $meta || ! method_exists( $meta, 'getController' ) ) {
            return false;
        }

        $ctrl = $meta->getController();
        if ( ! $ctrl || ! method_exists( $ctrl, 'doMetaIndexingFree' ) ) {
            return false;
        }

        log_import_update( '[WBW Index] Direct call → doMetaIndexingFree() (loop until done)' );

        // WBW indexes in pages (typically 50–100 products per call).
        // We must loop until done == total to index ALL products.
        $page       = 1;
        $max_pages  = 200; // safety cap: 200 × 50 = 10 000 products
        $done_total = 0;
        $grand_total = 0;

        // wp_send_json() uses wp_die() only when wp_doing_ajax() is true;
        // otherwise it calls native die and kills the script. Ensure DOING_AJAX
        // is defined so wbw_safe_call() can capture the response.
        if ( ! defined( 'DOING_AJAX' ) ) {
            define( 'DOING_AJAX', true );
        }

        // Force a full rebuild; WBW reads these from $_REQUEST.
        $_REQUEST['force']    = 1;
        $_REQUEST['per_page'] = 50;

        // doMetaIndexing(false) checks current_user_can('manage_options') and
        // calls wp_die() with no message on failure (captured as '' by wbw_safe_call).
        // Grant the capability for the duration of each call — callers already
        // enforce admin auth before reaching this function.
        $cap_filter = static function( $allcaps ) {
            $allcaps['manage_options'] = true;
            return $allcaps;
        };

        while ( $page <= $max_pages ) {
            $_REQUEST['page'] = $page;

            add_filter( 'user_has_cap', $cap_filter, PHP_INT_MAX );
            $output = wbw_safe_call( static function() use ( $ctrl ) {
                $ctrl->doMetaIndexingFree();
            } );
            remove_filter( 'user_has_cap', $cap_filter, PHP_INT_MAX );

            log_import_update( "[WBW Index] Page {$page} response: " . substr( $output, 0, 200 ) );

            // Parse WBW's JSON response to track progress.
            $resp = json_decode( $output, true );
            $data = $resp['data'] ?? $resp ?? [];

            $done  = (int) ( $data['done']  ?? $data['indexed'] ?? 0 );
            $total = (int) ( $data['total'] ?? $data['all']     ?? 0 );

            if ( $grand_total === 0 && $total > 0 ) {
                $grand_total = $total;
            }
            $done_total += $done;

            // Stop when WBW says we're finished, or when it returns no progress info.
            if ( $total > 0 && $done_total >= $total ) {
                break;
            }
            if ( $total === 0 || $done === 0 ) {
                // No pagination info — single-shot call; we're done.
                break;
            }

            $page++;
        }

        log_import_update( "[WBW Index] Loop complete: done={$done_total}, total={$grand_total}, pages={$page}" );
        return true;

    } catch ( \Throwable $e ) {
        log_import_update( '[WBW Index] Direct call exception: ' . $e->getMessage() );
    }
    return false;
}

/**
 * AJAX fallback — only reached when WBW classes aren't loaded in the current
 * process (e.g. a cron with no admin bootstrap).  HTTP 200 alone is not
 * proof of success; we also inspect the response body for WBW error markers.
 */
function wbw_try_ajax_indexing_wpf($timeout = 60) {
    $url  = admin_url('admin-ajax.php');
    $args = [
        'action'  => 'wpf',
        'mod'     => 'meta',
        'reqType' => 'ajax',
        'action2' => 'doMetaIndexingFree',
        'pl'      => 'wpf',
        'force'   => 1,
    ];
    $headers = [
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept'           => 'application/json,text/plain,*/*',
    ];

    $r    = wp_remote_post( $url, [ 'timeout' => (int) $timeout, 'headers' => $headers, 'body' => $args ] );
    $code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
    $body = is_wp_error( $r ) ? '' : wp_remote_retrieve_body( $r );
    log_import_update( sprintf( '[WBW Index][AJAX] POST => HTTP %d  body: %s', $code, substr( $body, 0, 200 ) ) );

    if ( is_wp_error( $r ) || $code !== 200 ) {
        return false;
    }
    // WBW returns JSON; treat "false" / error markers as failure.
    if ( $body === 'false' || strpos( $body, '"success":false' ) !== false ) {
        return false;
    }
    return true;
}

// === Attribute helpers ======================================================

function register_attribute_not_exists($attribute_name) {
    global $wpdb;
    $attribute_slug = wc_sanitize_taxonomy_name($attribute_name);
    $taxonomy = 'pa_' . $attribute_slug;
    $exists = $wpdb->get_var($wpdb->prepare("
        SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies
        WHERE attribute_name = %s", $attribute_slug));
    if (!$exists) {
        $wpdb->insert("{$wpdb->prefix}woocommerce_attribute_taxonomies", [
            'attribute_name'    => $attribute_slug,
            'attribute_label'   => $attribute_name,
            'attribute_type'    => 'select',
            'attribute_orderby' => 'menu_order',
            'attribute_public'  => 0
        ]);
        delete_transient('wc_attribute_taxonomies');
        log_import_update("Registered new attribute: $attribute_name ($taxonomy)");
    }
}

function add_vehicle_location_category( $product_id, $area, $province ) {
    if ( ! get_post( $product_id ) ) return;

    $area     = sanitize_text_field( trim( $area ) );
    $province = sanitize_text_field( trim( $province ) );
    $term_ids = [];

    // Province (parent)
    if ( $province ) {
        $parent = get_term_by( 'name', $province, 'product_cat' );
        if ( ! $parent ) {
            $insert = wp_insert_term( $province, 'product_cat', ['slug' => sanitize_title( $province )] );
            if ( is_wp_error( $insert ) ) {
                error_log( "add_vehicle_location_category: ERROR creating province '{$province}': " . $insert->get_error_message() );
            } else {
                $parent = get_term_by( 'term_id', $insert['term_id'], 'product_cat' );
            }
        }
        if ( $parent && ! is_wp_error( $parent ) ) {
            $term_ids[] = intval( $parent->term_id );
        }
    }

    // Area (child)
    if ( $area ) {
        $child = get_term_by( 'name', $area, 'product_cat' );
        if ( ! $child ) {
            $args = [ 'slug' => sanitize_title( $area ) ];
            if ( ! empty( $parent ) ) $args['parent'] = intval( $parent->term_id );
            $insert = wp_insert_term( $area, 'product_cat', $args );
            if ( is_wp_error( $insert ) ) {
                if ( 'term_exists' !== $insert->get_error_code() ) {
                    error_log( "add_vehicle_location_category: ERROR creating area '{$area}': " . $insert->get_error_message() );
                }
                $child = get_term_by( 'name', $area, 'product_cat' );
            } else {
                $child = get_term_by( 'term_id', $insert['term_id'], 'product_cat' );
            }
        }
        if ( $child && ! is_wp_error( $child ) ) {
            $term_ids[] = intval( $child->term_id );
        }
    }

    if ( ! empty( $term_ids ) ) {
        wp_set_object_terms( $product_id, $term_ids, 'product_cat', false );
    }
}

function remove_product_attribute_by_name($product_id, $attr_name) {
    $product = wc_get_product($product_id);
    if (!$product) return;

    $attributes = $product->get_attributes();

    foreach ($attributes as $key => $attribute) {
        if (strcasecmp($attribute->get_name(), $attr_name) === 0) {
            unset($attributes[$key]);
            $product->set_attributes($attributes);
            $product->save();
            return;
        }
    }
}

function add_attribute_to_vehicle_data($product_id, $attribute_name, $attribute_value) {
    if (!get_post($product_id)) return;
    $attribute_slug = wc_sanitize_taxonomy_name($attribute_name);
    $taxonomy = 'pa_' . $attribute_slug;

    if (!taxonomy_exists($taxonomy)) {
        register_taxonomy(
            $taxonomy,
            'product',
            [
                'hierarchical' => false,
                'label'        => ucfirst($attribute_name),
                'query_var'    => true,
                'rewrite'      => ['slug' => sanitize_title($attribute_name)],
            ]
        );
    }

    if (!is_array($attribute_value)) $attribute_value = [$attribute_value];

    $clean_terms = [];
    foreach ($attribute_value as $value) {
        $value = sanitize_text_field((string)$value);
        if ($value === '') continue;
        if (!term_exists($value, $taxonomy)) {
            wp_insert_term($value, $taxonomy);
        }
        $clean_terms[] = $value;
    }

    if ($clean_terms) {
        wp_set_object_terms($product_id, $clean_terms, $taxonomy, false);

        $product_attributes = get_post_meta($product_id, '_product_attributes', true) ?: [];
        $product_attributes[$taxonomy] = [
            'name'         => $taxonomy,
            'value'        => '',
            'is_visible'   => 1,
            'is_variation' => 0,
            'is_taxonomy'  => 1,
        ];
        update_post_meta($product_id, '_product_attributes', $product_attributes);
    }
}

// === Image helpers ==========================================================

$image_labels = [
    'x_studio_vp_front_photo'         => 'Front Photo',
    'x_studio_vp_front_feature_photo' => 'Front Feature Photo',
    'x_studio_vp_left_side_photo'     => 'Left Side Photo',
    'x_studio_vp_right_side_photo'    => 'Right Side Photo',
    'x_studio_vp_back_photo'          => 'Back Photo',
    'x_studio_vp_back_feature_photo'  => 'Back Feature Photo',
    'x_studio_vp_interior_photo'      => 'Interior',
    'x_studio_front_seats'            => 'Front Seats',
    'x_studio_rear_seats'             => 'Rear Seats',
    'x_studio_boot_loadbin'           => 'Boot / Loadbin',
    'x_studio_vehicle_dashboard'      => 'Dashboard',
    'x_studio_vp_spare_wheel'         => 'Spare Wheel',
    'x_studio_vp_wheel_example'       => 'Wheel Example',
];

function decode_feed_image($raw) {
    if (empty($raw)) return false;
    if (preg_match('#^data:image/[^;]+;base64,#i', $raw)) {
        $raw = preg_replace('#^data:image/[^;]+;base64,#i', '', $raw);
    }
    $step1 = base64_decode($raw, true);
    if ($step1 === false) return false;
    $step2 = base64_decode($step1, true);
    return ($step2 !== false) ? $step2 : $step1;
}

function vp_guess_image_ext_from_bytes($bytes) {
    $info = @getimagesizefromstring($bytes);
    if (!$info || empty($info['mime'])) return ['mime' => 'image/jpeg', 'ext' => 'jpg'];
    $mime = strtolower($info['mime']);
    $map  = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    return ['mime' => $mime, 'ext' => $map[$mime] ?? 'jpg'];
}

// Always load media libs in cron / non-admin contexts
function vi_ensure_media_libs_loaded() {
    // Paths exist on both single/multisite
    if ( ! function_exists('wp_handle_sideload') ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if ( ! function_exists('media_handle_sideload') ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }
    if ( ! function_exists('wp_generate_attachment_metadata') ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
}

function vp_save_bytes_as_attachment($bytes, $filename_base, $parent_post_id, $alt_text = '') {
    if (!$bytes) return 0;
    if (!function_exists('wp_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    vi_ensure_media_libs_loaded();

    $det = vp_guess_image_ext_from_bytes($bytes);
    $ext = $det['ext'];
    $mime = $det['mime'];

    $uploads = wp_upload_dir();
    $uniq = wp_unique_filename($uploads['path'], "{$filename_base}.{$ext}");
    $upload = wp_upload_bits($uniq, null, $bytes);
    if (!empty($upload['error'])) {
        log_import_update('Image upload failed: ' . $upload['error']);
        return 0;
    }

    $file_path = $upload['file'];
    $file_url  = $upload['url'];

    $attachment = [
        'post_mime_type' => $mime,
        'post_title'     => sanitize_text_field($filename_base),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_parent'    => $parent_post_id,
        'guid'           => $file_url,
    ];

    $attach_id = wp_insert_attachment($attachment, $file_path, $parent_post_id);
    if (is_wp_error($attach_id) || !$attach_id) {
        log_import_update('Attachment insert failed.');
        @unlink($file_path);
        return 0;
    }

    $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
    wp_update_attachment_metadata($attach_id, $attach_data);

    if ($alt_text !== '') {
        update_post_meta($attach_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
    }

    return $attach_id;
}

// Fetch JSON with cURL and log full timing diagnostics (hard timeouts)
function vi_fetch_json_curl($url, $context = 'generic', $timeout = 30) {
    if (!function_exists('curl_init')) {
        log_import_update("cURL not available; WP HTTP fallback for [$context]");
        $t0 = microtime(true);
        $resp = wp_remote_get($url, ['timeout' => $timeout, 'headers' => ['Accept' => 'application/json']]);
        $ms = round((microtime(true) - $t0) * 1000);
        if (is_wp_error($resp)) {
            log_import_update("WP HTTP [$context] error in {$ms}ms: " . $resp->get_error_message());
            return null;
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        log_import_update("WP HTTP [$context] code=$code body_len=" . strlen((string)$body) . " in {$ms}ms");
        if ($code >= 200 && $code < 300) {
            $json = json_decode($body, true);
            if (!is_array($json)) {
                log_import_update("WP HTTP [$context] JSON decode failed (first 120): " . substr((string)$body, 0, 120));
                return null;
            }
            return $json;
        }
        return null;
    }

    $headers = [
        'Accept: application/json',
        'Connection: close',
        'Expect:' // disable 100-continue stalls
    ];

    $url_with_cb = $url . (strpos($url, '?') === false ? '?' : '&') . '_cb=' . time();

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL               => $url_with_cb,
        CURLOPT_HTTPGET           => true,
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_FOLLOWLOCATION    => true,
        CURLOPT_MAXREDIRS         => 3,
        CURLOPT_TIMEOUT           => $timeout,
        CURLOPT_CONNECTTIMEOUT    => 10,
        CURLOPT_NOSIGNAL          => true,
        CURLOPT_DNS_CACHE_TIMEOUT => 60,
        CURLOPT_USERAGENT         => 'VehicleImporter/1.0',
        CURLOPT_ENCODING          => '',
        CURLOPT_HTTPHEADER        => $headers,
        CURLOPT_SSL_VERIFYPEER    => true,
        CURLOPT_SSL_VERIFYHOST    => 2,
        CURLOPT_IPRESOLVE         => CURL_IPRESOLVE_WHATEVER,
        CURLOPT_HTTP_VERSION      => CURL_HTTP_VERSION_1_1,
        CURLOPT_LOW_SPEED_LIMIT   => 1024,
        CURLOPT_LOW_SPEED_TIME    => 20,
        CURLOPT_TCP_KEEPALIVE     => 1,
        CURLOPT_TCP_KEEPIDLE      => 15,
        CURLOPT_TCP_KEEPINTVL     => 15,
    ]);

    $t0 = microtime(true);
    log_import_update("cURL [$context] START url=" . $url_with_cb);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_errno($ch);
    $errm = curl_error($ch);
    curl_close($ch);
    $elapsed_ms = round((microtime(true) - $t0) * 1000);
    log_import_update("cURL [$context] END in {$elapsed_ms}ms");

    $parts = [
        "code=" . ($info['http_code'] ?? 0),
        "len=" . strlen((string)$body),
        "ip=" . ($info['primary_ip'] ?? 'n/a'),
        "namelookup=" . (isset($info['namelookup_time']) ? round($info['namelookup_time']*1000) : 'n/a') . "ms",
        "connect=" . (isset($info['connect_time']) ? round($info['connect_time']*1000) : 'n/a') . "ms",
        "appconnect=" . (isset($info['appconnect_time']) ? round($info['appconnect_time']*1000) : 'n/a') . "ms",
        "starttransfer=" . (isset($info['starttransfer_time']) ? round($info['starttransfer_time']*1000) : 'n/a') . "ms",
        "total=" . (isset($info['total_time']) ? round($info['total_time']*1000) : $elapsed_ms) . "ms",
        "ctype=" . ($info['content_type'] ?? 'n/a'),
    ];
    if ($err) { $parts[] = "curl_errno=$err"; $parts[] = "curl_error=$errm"; }
    log_import_update("cURL [$context] " . implode(' ', $parts));

    if ($err) return null;
    $code = (int)($info['http_code'] ?? 0);
    if ($code < 200 || $code >= 300) return null;

    $json = json_decode($body, true);
    if (!is_array($json)) {
        log_import_update("cURL [$context] JSON decode failed (first 120): " . substr((string)$body, 0, 120));
        return null;
    }
    return $json;
}

// ---------- Image collect + attach pipeline (robust) ------------------------

/**
 * Fetch images once and return prepared decodable bytes in display order.
 * Returns ['featured'=>[...], 'gallery'=>[...]]; each item: ['key','label','base','bytes']
 */
function vi_collect_usable_images($vehicle_id, $vehicle_title, $image_labels) {
    $endpoint = sprintf('https://paceapp-server.azurewebsites.net/api/entity/paceWebPrepImages/id/%s', rawurlencode($vehicle_id));
    log_import_update("Images: fetching (collect) $endpoint");

    $json = vi_fetch_json_curl($endpoint, 'images-collect:' . $vehicle_id, 30);
    if ($json === null) {
        log_import_update("Images-collect: fetch failed for vehicle {$vehicle_id}");
        return ['featured'=>[], 'gallery'=>[]];
    }

    $images_obj = $json;
    if (isset($json[0]) && is_array($json[0])) $images_obj = $json[0];
    if (isset($images_obj['images']) && is_array($images_obj['images'])) $images_obj = $images_obj['images'];
    if (!is_array($images_obj) || empty($images_obj)) {
        log_import_update("Images-collect: empty/invalid images object for vehicle {$vehicle_id}");
        return ['featured'=>[], 'gallery'=>[]];
    }

    $ordered_keys = array_keys($image_labels);
    $featured_key = 'x_studio_vp_front_feature_photo';
    if (in_array($featured_key, $ordered_keys, true)) {
        $ordered_keys = array_values(array_unique(array_merge([$featured_key], $ordered_keys)));
    } else {
        array_unshift($ordered_keys, $featured_key);
    }

    $prepared = ['featured'=>[], 'gallery'=>[]];
    foreach ($ordered_keys as $key) {
        if (!array_key_exists($key, $images_obj)) continue;
        $raw = $images_obj[$key];

        if ($raw === null) continue;
        if (is_string($raw)) {
            $trim = strtolower(trim($raw));
            if ($trim === '' || $trim === 'none' || $trim === 'null' || $trim === '[]' || $trim === '{}') continue;
        }

        $bytes = decode_feed_image($raw);
        if ($bytes === false || strlen($bytes) < 64) continue;

        $label = $image_labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
        $prepared_item = [
            'key'   => $key,
            'label' => trim($vehicle_title ? "{$vehicle_title} – {$label}" : $label),
            'base'  => sanitize_title($vehicle_title ? "{$vehicle_title} {$label}" : $label) ?: 'vehicle-image',
            'bytes' => $bytes,
        ];

        if ($key === $featured_key && empty($prepared['featured'])) {
            $prepared['featured'][] = $prepared_item;
        } else {
            $prepared['gallery'][]  = $prepared_item;
        }
    }

    if (empty($prepared['featured']) && !empty($prepared['gallery'])) {
        $first = array_shift($prepared['gallery']);
        $prepared['featured'][] = $first;
    }

    return $prepared;
}

/**
 * Compute a single fingerprint hash for a prepared-images array.
 * Used to detect whether the feed images have changed since last import.
 */
function vi_hash_prepared_images($prepared) {
    $parts = [];
    foreach (array_merge($prepared['featured'], $prepared['gallery']) as $img) {
        $parts[] = md5($img['bytes']);
    }
    return md5(implode(',', $parts));
}

/**
 * Attach already-prepared images to a product.
 * Returns ['featured_id'=>int, 'gallery_ids'=>int[]]
 */
function vi_attach_prepared_images($product_id, $prepared) {
    // Lock: prevent image sync cron from processing this product while we attach
    set_transient("vi_attach_lock_{$product_id}", time(), 600); // 10 min TTL

    // lightweight watchdog for this phase
    $mark = "vi_attach_watchdog_{$product_id}";
    update_option($mark, time(), false);
    register_shutdown_function(function () use ($mark, $product_id) {
        $still = get_option($mark);
        if ($still) {
            $e = error_get_last();
            $msg = $e ? (' type=' . $e['type'] . ' msg=' . $e['message'] . ' at ' . $e['file'] . ':' . $e['line']) : ' (no last error)';
            log_import_update("WATCHDOG: attach died for product {$product_id}.{$msg}");
            delete_option($mark);
        }
    });

    $featured_id = 0;
    $gallery_ids = [];

    // 1) Featured
    if (!empty($prepared['featured'])) {
        $f = $prepared['featured'][0];
        $fid = vp_save_bytes_as_attachment($f['bytes'], $f['base'], $product_id, $f['label']);
        if ($fid) {
            set_post_thumbnail($product_id, $fid);
            $readback = (int) get_post_meta($product_id, '_thumbnail_id', true);
            if ($readback === $fid) {
                log_import_update("Images: featured saved attach_id={$fid}");
            } else {
                log_import_update("Images: featured meta FAILED to persist for attach_id={$fid} (readback={$readback})");
            }
            update_post_meta($fid, '_vi_image_md5', md5($f['bytes']));
            $featured_id = $fid;
        } else {
            log_import_update("Images: FAILED to save featured");
        }
    }

    // 2) Gallery attachments
    foreach ($prepared['gallery'] as $g) {
        $gid = vp_save_bytes_as_attachment($g['bytes'], $g['base'], $product_id, $g['label']);
        if ($gid) {
            update_post_meta($gid, '_vi_image_md5', md5($g['bytes']));
            $gallery_ids[] = (int) $gid;
            log_import_update("Images: gallery saved attach_id={$gid}");
        }
    }

    // Normalize set
    $gallery_ids = array_values(array_unique(array_filter(array_map('intval', $gallery_ids))));
    if ($featured_id) {
        $gallery_ids = array_values(array_diff($gallery_ids, [$featured_id]));
    }

    // 3) Write gallery meta (with retries + verification)
    $csv = implode(',', $gallery_ids);
    log_import_update("Images: about to write gallery meta for product {$product_id} (count=" . count($gallery_ids) . ")");

    $ok = false;

    // Try #1: write meta directly
    try {
        if ($csv !== '') {
            update_post_meta($product_id, '_product_image_gallery', $csv);
        } else {
            delete_post_meta($product_id, '_product_image_gallery');
        }

        // clear caches/transients so reads reflect latest
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }
        clean_post_cache($product_id);

        // verify
        $readback = (string) get_post_meta($product_id, '_product_image_gallery', true);
        if ($readback === $csv) {
            log_import_update("Images: gallery meta set for product {$product_id} -> {$csv}");
            $ok = true;
        } else {
            log_import_update("Images: gallery meta verify mismatch for product {$product_id} (wrote='{$csv}' read='{$readback}')");
        }
    } catch (Throwable $e) {
        log_import_update("Images: meta write threw for product {$product_id}: " . $e->getMessage());
    }

    // Try #2: WooCommerce setter fallback
    if (!$ok) {
        try {
            $product = wc_get_product($product_id);
            if ($product) {
                $product->set_gallery_image_ids($gallery_ids);
                $product->save();

                if (function_exists('wc_delete_product_transients')) {
                    wc_delete_product_transients($product_id);
                }
                clean_post_cache($product_id);

                // verify again via meta (WC also writes the same meta)
                $readback = (string) get_post_meta($product_id, '_product_image_gallery', true);
                if ($readback === $csv) {
                    log_import_update("Images: gallery set via WC setter for product {$product_id} -> {$readback}");
                    $ok = true;
                } else {
                    log_import_update("Images: WC setter verify mismatch for product {$product_id} (expected='{$csv}' read='{$readback}')");
                }
            } else {
                log_import_update("Images: WC product fetch failed during setter fallback ({$product_id})");
            }
        } catch (Throwable $e) {
            log_import_update("Images: WC setter threw for product {$product_id}: " . $e->getMessage());
        }
    }

    // Try #3: last-chance meta write again if still not OK
    if (!$ok) {
        try {
            if ($csv !== '') {
                update_post_meta($product_id, '_product_image_gallery', $csv);
            } else {
                delete_post_meta($product_id, '_product_image_gallery');
            }
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }
            clean_post_cache($product_id);

            $readback = (string) get_post_meta($product_id, '_product_image_gallery', true);
            if ($readback === $csv) {
                log_import_update("Images: gallery meta set on retry for product {$product_id} -> {$csv}");
                $ok = true;
            }
        } catch (Throwable $e) {
            log_import_update("Images: meta retry threw for product {$product_id}: " . $e->getMessage());
        }
    }

    // Log final state
    if (!$ok) {
        log_import_update("Images: FAILED to persist gallery meta for product {$product_id}; featured_id={$featured_id}, csv='{$csv}'");
    }

    delete_option($mark);

    // Store combined image hash so subsequent runs can detect feed image changes
    update_post_meta($product_id, '_vi_images_hash', vi_hash_prepared_images($prepared));

    // Release the attach lock so sync cron can process this product now
    delete_transient("vi_attach_lock_{$product_id}");

    return ['featured_id' => (int)$featured_id, 'gallery_ids' => $gallery_ids];
}

/**
 * Sync images for a product by diffing existing attachments against fresh feed images.
 * Only uploads new/changed images and deletes removed ones — avoids re-uploading unchanged images.
 * Returns ['featured_id'=>int, 'gallery_ids'=>int[], 'added'=>int, 'removed'=>int, 'kept'=>int]
 */
function vi_diff_sync_product_images($product_id, $prepared) {
    $product_id = (int) $product_id;

    // Build ordered list of fresh images with their MD5s
    $fresh_images = [];
    foreach (array_merge($prepared['featured'], $prepared['gallery']) as $img) {
        $fresh_images[] = array_merge($img, ['md5' => md5($img['bytes'])]);
    }

    if (empty($fresh_images)) {
        log_import_update("Images-diff: no fresh images for product {$product_id} — skipping.");
        return ['featured_id' => 0, 'gallery_ids' => [], 'added' => 0, 'removed' => 0, 'kept' => 0];
    }

    // Collect existing attachment MD5 → attachment_id map
    $existing_md5_map = vi_collect_existing_image_md5s($product_id);

    // Track which existing attachments are still used
    $keep_ids   = [];
    $final_ids  = []; // ordered attachment IDs matching feed order
    $added      = 0;

    foreach ($fresh_images as $img) {
        $md5 = $img['md5'];
        if (isset($existing_md5_map[$md5]) && !in_array($existing_md5_map[$md5], $keep_ids, true)) {
            // Existing attachment matches — keep it
            $aid = $existing_md5_map[$md5];
            $keep_ids[]  = $aid;
            $final_ids[] = $aid;
            log_import_update("Images-diff: kept attach_id={$aid} (md5={$md5})");
        } else {
            // New image — upload it
            $aid = vp_save_bytes_as_attachment($img['bytes'], $img['base'], $product_id, $img['label']);
            if ($aid) {
                update_post_meta($aid, '_vi_image_md5', $md5);
                $final_ids[] = $aid;
                $added++;
                log_import_update("Images-diff: uploaded new attach_id={$aid} (md5={$md5})");
            } else {
                log_import_update("Images-diff: FAILED to upload image (md5={$md5})");
            }
        }
    }

    // Delete attachments that are no longer in the feed
    $removed = 0;
    foreach ($existing_md5_map as $md5 => $aid) {
        if (!in_array($aid, $keep_ids, true)) {
            wp_delete_attachment($aid, true);
            $removed++;
            log_import_update("Images-diff: deleted stale attach_id={$aid} (md5={$md5})");
        }
    }

    // Set featured = first image, gallery = rest
    $featured_id = !empty($final_ids) ? $final_ids[0] : 0;
    $gallery_ids = array_slice($final_ids, 1);

    if ($featured_id) {
        set_post_thumbnail($product_id, $featured_id);
        $readback = (int) get_post_meta($product_id, '_thumbnail_id', true);
        if ($readback !== $featured_id) {
            log_import_update("Images: reorder featured meta FAILED to persist for attach_id={$featured_id} (readback={$readback})");
        }
    } else {
        delete_post_thumbnail($product_id);
    }

    // Write gallery meta (same retry pattern as vi_attach_prepared_images)
    $csv = implode(',', $gallery_ids);
    update_post_meta($product_id, '_product_image_gallery', $csv !== '' ? $csv : '');
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($product_id);
    }
    clean_post_cache($product_id);
    log_import_update("Images-diff: product {$product_id} — featured={$featured_id}, gallery=" . ($csv ?: '(none)'));

    // Update the overall hash
    update_post_meta($product_id, '_vi_images_hash', vi_hash_prepared_images($prepared));

    $kept = count($keep_ids);
    log_import_update("Images-diff: product {$product_id} summary — kept={$kept}, added={$added}, removed={$removed}");
    return ['featured_id' => $featured_id, 'gallery_ids' => $gallery_ids, 'added' => $added, 'removed' => $removed, 'kept' => $kept];
}

// (Optional) watchdog remains available for deeper crash tracing
function vi_images_watchdog_begin($vehicle_id, $product_id) {
    $mark = "vi_img_watchdog_{$product_id}_{$vehicle_id}";
    update_option($mark, time(), false);
    register_shutdown_function(function () use ($mark, $vehicle_id, $product_id) {
        $still = get_option($mark);
        if ($still) {
            $e = error_get_last();
            $msg = $e ? (' type=' . $e['type'] . ' msg=' . $e['message'] . ' at ' . $e['file'] . ':' . $e['line']) : ' (no last error)';
            log_import_update("WATCHDOG: images aborted for vehicle {$vehicle_id} (product {$product_id}).{$msg}");
            delete_option($mark);
        }
    });
    return $mark;
}
function vi_images_watchdog_end($mark) { delete_option($mark); }

// === Deletion helpers =======================================================

function vi_delete_product_and_images($product_id) {
    $product_id = intval($product_id);
    if (!$product_id || get_post_type($product_id) !== 'product') return;

    $to_delete = [];

    $thumb_id = get_post_thumbnail_id($product_id);
    if ($thumb_id) $to_delete[] = intval($thumb_id);

    $gallery_csv = get_post_meta($product_id, '_product_image_gallery', true);
    if (is_string($gallery_csv) && trim($gallery_csv) !== '') {
        foreach (explode(',', $gallery_csv) as $id) {
            $aid = intval($id);
            if ($aid) $to_delete[] = $aid;
        }
    }

    $to_delete = array_values(array_unique(array_filter(array_map('intval', $to_delete))));
    foreach ($to_delete as $aid) {
        if (get_post_type($aid) === 'attachment') {
            wp_delete_attachment($aid, true);
        }
    }

    wp_delete_post($product_id, true);
    log_import_update("Prune: deleted product {$product_id} and its images.");
}

/**
 * Purge ONLY the product's images (featured + gallery), keep the product.
 */
function vi_purge_product_images($product_id) {
    $product_id = (int) $product_id;
    if (!$product_id || get_post_type($product_id) !== 'product') return;

    $delete_ids = [];

    // Featured
    $thumb_id = (int) get_post_thumbnail_id($product_id);
    if ($thumb_id) $delete_ids[] = $thumb_id;
    delete_post_thumbnail($product_id);

    // Gallery
    $csv = (string) get_post_meta($product_id, '_product_image_gallery', true);
    if ($csv !== '') {
        foreach (explode(',', $csv) as $id) {
            $aid = (int) $id;
            if ($aid) $delete_ids[] = $aid;
        }
    }
    delete_post_meta($product_id, '_product_image_gallery');

    // De-dupe and delete attachments
    $delete_ids = array_values(array_unique(array_filter(array_map('intval', $delete_ids))));
    foreach ($delete_ids as $aid) {
        if (get_post_type($aid) === 'attachment') {
            wp_delete_attachment($aid, true);
        }
    }

    // Clear caches/transients
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($product_id);
    }
    clean_post_cache($product_id);
    log_import_update("Update: purged images for product {$product_id} (".count($delete_ids)." attachments removed).");
}

/**
 * Quick probe to confirm at least one decodable image exists for vehicle_id.
 */
function vi_vehicle_has_images($vehicle_id) {
    $endpoint = sprintf(
        'https://paceapp-server.azurewebsites.net/api/entity/paceWebPrepImages/id/%s',
        rawurlencode($vehicle_id)
    );

    $json = vi_fetch_json_curl($endpoint, 'has_images:' . $vehicle_id, 20);
    if ($json === null) {
        log_import_update("has_images: fetch failed for vehicle {$vehicle_id}");
        return false;
    }

    // Accept different feed shapes: [{...}], {...}, {...,"images":{...}}
    $images_obj = $json;
    if (isset($json[0]) && is_array($json[0])) {
        $images_obj = $json[0];
    }
    if (isset($images_obj['images']) && is_array($images_obj['images'])) {
        $images_obj = $images_obj['images'];
    }

    // Must be a non-empty array
    if (!is_array($images_obj) || empty($images_obj)) {
        log_import_update("has_images: empty images object for vehicle {$vehicle_id}");
        return false;
    }

    // Find first non-empty, decodable image and bail early
    foreach ($images_obj as $key => $raw) {
        if ($raw === null) continue;
        if (is_string($raw)) {
            $trim = strtolower(trim($raw));
            if ($trim === '' || $trim === 'none' || $trim === '[]' || $trim === '{}') continue;
        }
        $bytes = decode_feed_image($raw);
        if ($bytes !== false && strlen($bytes) >= 64) {
            return true; // found at least one valid image
        }
    }

    log_import_update("has_images: no decodable images for vehicle {$vehicle_id}");
    return false;
}

// === Feed helpers ============================================================

/**
 * Fetch and parse the vehicle feed. Returns array of items on success, or null on failure.
 * Also returns the feed_sku_set for prune logic.
 */
function vi_fetch_feed() {
    $feed_url = 'https://paceapp-server.azurewebsites.net/api/entity/paceWebPrepData';
    log_import_update('Fetch feed: ' . $feed_url);

    $t0 = microtime(true);
    $response = wp_remote_get($feed_url, ['timeout' => 45, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($response)) {
        log_import_update('Feed request failed: ' . $response->get_error_message());
        return null;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $elapsed_ms = round((microtime(true) - $t0) * 1000);
    log_import_update("Feed HTTP {$code}; body_len=" . strlen((string)$body) . " in {$elapsed_ms}ms");

    if ($code !== 200) {
        log_import_update('Feed HTTP not 200; aborting.');
        return null;
    }

    $items = json_decode($body, true);
    if (!is_array($items)) {
        log_import_update('Feed JSON parse failed or not array.');
        return null;
    }

    $total = count($items);
    log_import_update("Feed parsed OK; items={$total}");

    $feed_skus = [];
    foreach ($items as $row) {
        if (!empty($row['id'])) $feed_skus[] = (string) $row['id'];
    }

    return ['items' => $items, 'feed_sku_set' => array_fill_keys($feed_skus, true)];
}

// === Create: import new vehicles from feed ==================================

function vi_create_new_products() {
    global $image_labels;

    $lock_key = 'vi_create_running_lock';
    if (get_transient($lock_key)) {
        sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, '=== Create SKIPPED: already running (lock present). ===');
        return;
    }
    set_transient($lock_key, function_exists('getmypid') ? (getmypid() ?: 1) : 1, VI_MAX_CREATE_SEC + 60);

    @ignore_user_abort(true);
    if (function_exists('set_time_limit')) @set_time_limit(VI_MAX_CREATE_SEC + 30);
    $run_t0 = microtime(true);
    $time_up = function() use ($run_t0) { return (microtime(true) - $run_t0) >= VI_MAX_CREATE_SEC; };

    sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, '=== Create START ===');

    $feed = vi_fetch_feed();
    if ($feed === null) {
        sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, '=== Create END (feed error) ===');
        delete_transient($lock_key);
        return;
    }

    $items = $feed['items'];
    $create_limit = (int) VI_MAX_VEHICLES_PER_RUN;
    if ($create_limit > 0) sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, "Per-run create limit: VI_MAX_VEHICLES_PER_RUN={$create_limit}");

    $created = 0;

    foreach ($items as $idx => $data) {
        if ($time_up()) { sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, "Time budget reached during CREATE pass."); break; }
        if ($create_limit > 0 && $created >= $create_limit) { sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, "Create cap reached; stopping."); break; }

        $sku   = isset($data['id']) ? (string)$data['id'] : '';
        $name  = isset($data['vehicle_description']) ? (string)$data['vehicle_description'] : '';
        $price = isset($data['rental']) ? floatval($data['rental']) : null;

        if ($sku === '' || $name === '' || $price === null) {
            log_skip_product($sku ?: '(no id)', 'missing_required_fields', ['name'=>$name, 'price'=>$price]);
            continue;
        }

        $existing_id = wc_get_product_id_by_sku($sku);
        if ($existing_id) continue; // already exists

        // Prepare images ONCE and decide before creating product
        $vehicle_title   = $data['vehicle_description'] ?? '';
        $prepared_images = vi_collect_usable_images($sku, $vehicle_title, $image_labels);

        if (empty($prepared_images['featured']) && empty($prepared_images['gallery'])) {
            log_skip_product($sku, 'no_images_in_feed');
            continue;
        }

        // Re-check SKU after image fetch (race-condition guard)
        $recheck_id = wc_get_product_id_by_sku($sku);
        if ($recheck_id) {
            log_import_update("Duplicate prevented: sku={$sku} was created (product_id={$recheck_id}) while images were being fetched; skipping.");
            continue;
        }

        // Create product
        $t_create = microtime(true);
        try {
            $product = new WC_Product_Simple();
            $product->set_name(sanitize_text_field($name));
            $product->set_sku(sanitize_text_field($sku));
            $product->set_price($price);
            $product->set_regular_price($price);
            $product->set_stock_quantity(1);
            $product->set_manage_stock(true);
            $product->set_stock_status('instock');
            $product->set_status('publish');
            if (!empty($data['vehicle_description'])) {
                $product->set_description(wp_kses_post($data['vehicle_description']));
            }

            $product_id = $product->save();

            $vehicle_id = sanitize_title( (string) ($data['vehicle_id'] ?? '') );
            if ( $vehicle_id ) {
                $base_slug = sanitize_title( $name );
                $new_slug  = "{$base_slug}-{$vehicle_id}";
                wp_update_post(['ID' => $product_id, 'post_name' => $new_slug]);
            }

            $ms = round((microtime(true) - $t_create) * 1000);
            log_import_update("Created product_id={$product_id} for sku={$sku} in {$ms}ms");

            // Attributes
            $attributes = [
                'Vehicle Year'    => $data['year_of_manufacture'] ?? '',
                'Make'            => $data['make'] ?? '',
                'Model'           => $data['model'] ?? '',
                'Vehicle Type'    => $data['vehicle_type'] ?? '',
                'Seats'           => $data['number_of_seats'] ?? '',
                'Upholstery'      => $data['upholstery'] ?? '',
                'Transmission'    => $data['transmission'] ?? '',
                'Fuel'            => $data['fuel_type'] ?? '',
                'Kilometers'      => $data['saml_prep_odo_reading'] ?? '',
                'Color'           => $data['colour'] ?? '',
                'Initiation Fee'  => format_to_currency_value($data['deposit'] ?? 0),
                'New or Used'     => $data['vehicle_condition'] ?? '',
                'Region'          => $data['vp_location'] ?? '',
                'Write Date'      => $data['write_date'] ?? '',
                'Registration'    => $data['license_plate'] ?? '',
                'Sold'            => isset($data['sold']) ? ((bool)$data['sold'] ? 'Yes' : 'No') : 'No',
                'Rebate Target'   => $data['rebate_target'] ?? '',
            ];
            $deposit_special = isset($data['deposit_special']) ? floatval($data['deposit_special']) : 0.0;
            if ($deposit_special > 0) {
                $attributes['Initiation Fee Special'] = format_to_currency_value($deposit_special);
            }

            $attr_count_before = 0; $attr_count_after = 0;
            foreach ($attributes as $attr_name => $attr_value) {
                $attr_count_before++;
                $attr_value = is_array($attr_value) ? $attr_value : trim((string)$attr_value);
                if ($attr_value === '' || (is_array($attr_value) && empty(array_filter($attr_value)))) continue;
                register_attribute_not_exists($attr_name);
                add_attribute_to_vehicle_data($product_id, $attr_name, $attr_value);
                $attr_count_after++;
            }
            log_import_update("Attributes set for product_id={$product_id} (requested={$attr_count_before}, applied={$attr_count_after})");

            // Categories (area/province)
            $area     = $data['vp_location'] ?? '';
            $province = $data['province']     ?? '';
            log_import_update("Categories: province=\"{$province}\" area=\"{$area}\"");
            add_vehicle_location_category($product_id, $area, $province);
            log_import_update("Categories assigned for product_id={$product_id}");

            // Attach images
            $img_t0 = microtime(true);
            $attach = vi_attach_prepared_images($product_id, $prepared_images);
            $img_ms = round((microtime(true) - $img_t0) * 1000);
            log_import_update("Images: end (prepared) for product_id={$product_id} in {$img_ms}ms");

            // Post-attach sanity: if nothing attached, remove the product
            if (empty($attach['featured_id']) && empty($attach['gallery_ids'])) {
                if (defined('VI_PRUNE_DRY_RUN') && VI_PRUNE_DRY_RUN) {
                    log_import_update("Post-attach DRY-RUN: would remove product_id={$product_id} (no images attached)");
                } else {
                    log_import_update("Post-attach: removing product_id={$product_id} (no images attached)");
                    vi_delete_product_and_images($product_id);
                    continue;
                }
            }

            // Store write_date for future update detection
            $curr_write = isset($data['write_date']) ? (string) $data['write_date'] : '';
            if ($curr_write !== '') {
                update_post_meta($product_id, '_vi_write_date', $curr_write);
            }

            $created++;
            log_import_update("SUCCESS: product_id={$product_id} sku={$sku} (created_count={$created})");

        } catch (Throwable $e) {
            log_import_update("ERROR creating product for sku={$sku}: " . $e->getMessage());
        }
    }

    // Signal that a reindex is needed; the update cron (30 min later) will
    // trigger the actual index pass. This avoids two heavy index runs per hour.
    update_option('vi_wbw_reindex_needed', time());

    $run_s = round((microtime(true) - $run_t0), 2);
    sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, "=== Create END — created={$created}, elapsed={$run_s}s ===");
    delete_transient($lock_key);
}

// === Update: sync existing products from feed ===============================

function vi_update_existing_products() {
    global $image_labels;

    $lock_key = 'vi_update_running_lock';
    if (get_transient($lock_key)) {
        sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, '=== Update SKIPPED: already running (lock present). ===');
        return;
    }
    set_transient($lock_key, function_exists('getmypid') ? (getmypid() ?: 1) : 1, VI_MAX_UPDATE_SEC + 60);

    @ignore_user_abort(true);
    if (function_exists('set_time_limit')) @set_time_limit(VI_MAX_UPDATE_SEC + 30);
    $run_t0 = microtime(true);
    $time_up = function() use ($run_t0) { return (microtime(true) - $run_t0) >= VI_MAX_UPDATE_SEC; };

    sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, '=== Update START ===');

    $feed = vi_fetch_feed();
    if ($feed === null) {
        sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, '=== Update END (feed error) ===');
        delete_transient($lock_key);
        return;
    }

    $items        = $feed['items'];
    $feed_sku_set = $feed['feed_sku_set'];
    $update_limit = (int) VI_MAX_UPDATES_PER_RUN;
    if ($update_limit > 0) sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, "Per-run update limit: VI_MAX_UPDATES_PER_RUN={$update_limit}");

    $updated   = 0;
    $processed = 0;

    foreach ($items as $idx => $data) {
        if ($time_up()) { sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, "Time budget reached during UPDATE pass."); break; }

        $processed++;
        $sku   = isset($data['id']) ? (string)$data['id'] : '';
        $name  = isset($data['vehicle_description']) ? (string)$data['vehicle_description'] : '';
        $price = isset($data['rental']) ? floatval($data['rental']) : null;

        if ($sku === '') continue;

        $existing_id = wc_get_product_id_by_sku($sku);
        if (!$existing_id) continue;

        $curr_write = isset($data['write_date']) ? (string) $data['write_date'] : '';
        $prev_write = (string) get_post_meta($existing_id, '_vi_write_date', true);

        if ($curr_write === '' || $curr_write === $prev_write) {
            log_skip_product($sku, 'product_already_exists_write_date_unchanged', ['product_id' => $existing_id, 'write_date' => $prev_write]);
            continue;
        }

        if ($update_limit > 0 && $updated >= $update_limit) {
            sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, "Update cap reached; skipping further updates this run.");
            break;
        }

        log_import_update("Update: write_date changed for sku={$sku} product_id={$existing_id} (old='{$prev_write}' new='{$curr_write}')");

        // === Update scalars ===
        $product = wc_get_product($existing_id);
        if ($product) {
            $dirty = false;

            if ($name !== '' && $product->get_name() !== $name) {
                $product->set_name(sanitize_text_field($name));
                $dirty = true;
            }
            if ($price !== null && floatval($product->get_regular_price()) !== $price) {
                $product->set_price($price);
                $product->set_regular_price($price);
                $dirty = true;
            }
            $desc = (string)($data['vehicle_description'] ?? '');
            if ($desc !== '' && $product->get_description() !== $desc) {
                $product->set_description(wp_kses_post($desc));
                $dirty = true;
            }
            if ($dirty) {
                $product->save();
                log_import_update("Update: scalars saved for product_id={$existing_id}");
            }
        }

        // === Update attributes ===
        $attributes = [
            'Vehicle Year'    => $data['year_of_manufacture'] ?? '',
            'Make'            => $data['make'] ?? '',
            'Model'           => $data['model'] ?? '',
            'Vehicle Type'    => $data['vehicle_type'] ?? '',
            'Seats'           => $data['number_of_seats'] ?? '',
            'Upholstery'      => $data['upholstery'] ?? '',
            'Transmission'    => $data['transmission'] ?? '',
            'Fuel'            => $data['fuel_type'] ?? '',
            'Kilometers'      => $data['saml_prep_odo_reading'] ?? '',
            'Color'           => $data['colour'] ?? '',
            'Initiation Fee'  => format_to_currency_value($data['deposit'] ?? 0),
            'New or Used'     => $data['vehicle_condition'] ?? '',
            'Region'          => $data['vp_location'] ?? '',
            'Write Date'      => $data['write_date'] ?? '',
            'Registration'    => $data['license_plate'] ?? '',
            'Sold'            => isset($data['sold']) ? ((bool)$data['sold'] ? 'Yes' : 'No') : 'No',
            'Rebate Target'   => $data['rebate_target'] ?? '',
        ];
        $deposit_special = isset($data['deposit_special']) ? floatval($data['deposit_special']) : 0.0;

        if ($deposit_special > 0) {
            $attributes['Initiation Fee Special'] = format_to_currency_value($deposit_special);
        } else {
            remove_product_attribute_by_name($existing_id, 'Initiation Fee Special');
        }

        $req = 0; $applied = 0;
        foreach ($attributes as $attr_name => $attr_value) {
            $req++;
            $attr_value = is_array($attr_value) ? $attr_value : trim((string)$attr_value);
            if ($attr_value === '' || (is_array($attr_value) && empty(array_filter($attr_value)))) continue;
            register_attribute_not_exists($attr_name);
            add_attribute_to_vehicle_data($existing_id, $attr_name, $attr_value);
            $applied++;
        }
        log_import_update("Update: attributes set for product_id={$existing_id} (requested={$req}, applied={$applied})");

        // === Update categories ===
        $area     = $data['vp_location'] ?? '';
        $province = $data['province']     ?? '';
        add_vehicle_location_category($existing_id, $area, $province);
        log_import_update("Update: categories synced for product_id={$existing_id}");

        // === Sync images — only upload changed, keep unchanged ===
        $vehicle_title   = $data['vehicle_description'] ?? get_the_title($existing_id);
        $prepared_images = vi_collect_usable_images($sku, $vehicle_title, $image_labels);

        if (!empty($prepared_images['featured']) || !empty($prepared_images['gallery'])) {
            $fresh_hash  = vi_hash_prepared_images($prepared_images);
            $stored_hash = (string) get_post_meta($existing_id, '_vi_images_hash', true);

            if ($fresh_hash !== $stored_hash) {
                $diff_result = vi_diff_sync_product_images($existing_id, $prepared_images);
                log_import_update("Update: images synced for product_id={$existing_id} (sku={$sku}) — added={$diff_result['added']}, removed={$diff_result['removed']}, kept={$diff_result['kept']}.");
            } else {
                log_import_update("Update: images unchanged for product_id={$existing_id} (sku={$sku}) — skipping re-upload (hash={$stored_hash}).");
            }
        } else {
            // Feed returned no images — don't advance write_date so the next
            // run retries image sync rather than treating the product as done.
            log_import_update("Update: write_date changed but no images available in feed for sku={$sku} — keeping existing images, deferring write_date.");
            $updated++;
            continue;
        }

        // === Persist new write_date marker ===
        update_post_meta($existing_id, '_vi_write_date', $curr_write);
        $updated++;
    }

    // === PRUNE: remove products not present in the current feed ===
    if (VI_PRUNE_NONFEED_PRODUCTS && !$time_up()) {
        $dry = (defined('VI_PRUNE_DRY_RUN') && VI_PRUNE_DRY_RUN);
        log_import_update('Prune: checking store for products not in feed SKUs... (mode=' . ($dry ? 'DRY-RUN' : 'LIVE') . ')');

        $existing_products = get_posts([
            'post_type'      => 'product',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $pruned = 0;
        foreach ($existing_products as $pid) {
            if ($time_up()) {
                log_import_update("Time budget reached during PRUNE pass.");
                break;
            }

            $sku = get_post_meta($pid, '_sku', true);
            $wc_product = wc_get_product($pid);
            if ( ! $wc_product ) {
                log_import_update("Prune: skipping product_id={$pid} — wc_get_product() returned false (broken postmeta?)");
                continue;
            }
            $skip_removal = $wc_product->get_attribute('Skip Removal');

            if (strcasecmp(trim($skip_removal), 'yes') === 0) {
                log_import_update("Prune: skipping product_id={$pid} (SKU={$sku}) — marked 'Skip Removal: Yes'");
                continue;
            }

            if ($sku && !isset($feed_sku_set[(string)$sku])) {
                if ($dry) {
                    log_import_update("Prune DRY-RUN: would remove product_id={$pid} (sku={$sku})");
                } else {
                    log_import_update("Prune: SKU {$sku} not in feed → removing product_id={$pid}");
                    vi_delete_product_and_images($pid);
                    $pruned++;
                }
            }
        }

        if ($dry) {
            log_import_update("Prune DRY-RUN complete: reviewed=" . count($existing_products) . " (no deletions performed)");
        } else {
            log_import_update("Prune complete: removed={$pruned}, kept=" . (count($existing_products) - $pruned));
        }
    }

    // Trigger reindex
    wbw_index_after_import_smart(60);
    update_option('vi_wbw_reindex_needed', time());
    if (!wp_next_scheduled('wbw_custom_index_cron')) {
        wp_schedule_single_event(time() + 60, 'wbw_custom_index_cron');
        log_import_update('[WBW Index] Scheduled wbw_custom_index_cron in 60s.');
    }

    $run_s = round((microtime(true) - $run_t0), 2);
    sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, "=== Update END — updated={$updated}, processed={$processed}/{" . count($items) . "}, elapsed={$run_s}s ===");
    delete_transient($lock_key);
}

// === Legacy wrapper (calls both) ============================================

function import_vehicles_from_feed() {
    log_import_update('=== Legacy import_vehicles_from_feed() called — dispatching to separate create + update functions ===');
    vi_create_new_products();
    vi_update_existing_products();
}

// === HOURLY CRON ============================================================

// Create runs hourly (new vehicles)
add_action('vi_create_hourly_event', 'vi_create_new_products');
// Update runs hourly (existing vehicles), offset by 30 min
add_action('vi_update_hourly_event', 'vi_update_existing_products');
// Legacy hook still works
add_action('vehicle_importer_hourly_event', 'import_vehicles_from_feed');

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('vi_create_hourly_event')) {
        wp_schedule_event(time() + 120, 'hourly', 'vi_create_hourly_event');
    }
    if (!wp_next_scheduled('vi_update_hourly_event')) {
        wp_schedule_event(time() + 1920, 'hourly', 'vi_update_hourly_event'); // 32 min offset
    }
    // Keep legacy hook for backward compat but don't double-schedule
    $legacy = wp_next_scheduled('vehicle_importer_hourly_event');
    if ($legacy) {
        wp_unschedule_event($legacy, 'vehicle_importer_hourly_event');
    }
    log_import_update('Activation: separate create/update crons scheduled.');
});

register_deactivation_hook(__FILE__, function () {
    foreach ([
        'vi_create_hourly_event',
        'vi_update_hourly_event',
        'vehicle_importer_hourly_event',
        'vehicle_images_repair_event',
        'vi_image_sync_cron',
        'set_sold_date_cron_event',
        'delete_expired_sold_products_event',
        'fix_and_replace_broken_images',
        'sa_motorlease_log_rotate_daily',
    ] as $hook) {
        wp_clear_scheduled_hook($hook);
    }
    log_import_update('Deactivation: all crons unscheduled.');
});

add_action('init', function () {
    // Unschedule legacy combined hook if still present
    $legacy = wp_next_scheduled('vehicle_importer_hourly_event');
    if ($legacy) {
        wp_unschedule_event($legacy, 'vehicle_importer_hourly_event');
    }
    if (!wp_next_scheduled('vi_create_hourly_event')) {
        wp_schedule_event(time() + 120, 'hourly', 'vi_create_hourly_event');
        log_import_update('Init: create cron scheduled.');
    }
    if (!wp_next_scheduled('vi_update_hourly_event')) {
        wp_schedule_event(time() + 1920, 'hourly', 'vi_update_hourly_event');
        log_import_update('Init: update cron scheduled.');
    }
});

// === Image Repair Logger (now routes through central logger) ================

if (!function_exists('vehicle_image_repair_log_path')) {
    function vehicle_image_repair_log_path() {
        // Back-compat shim: external callers still get a valid path.
        return sa_motorlease_log_path();
    }
}
if (!function_exists('log_image_repair')) {
    function log_image_repair($msg) {
        sa_motorlease_log('image-repair', sa_motorlease_log_classify($msg), $msg);
    }
}

// === Small helpers used by repair ===========================================

if (!function_exists('vi_collect_existing_image_md5s')) {
    function vi_collect_existing_image_md5s($product_id) {
        $hash_to_attach = [];
        $attach_ids = [];

        $thumb_id = get_post_thumbnail_id($product_id);
        if ($thumb_id) $attach_ids[] = (int) $thumb_id;

        $csv = (string) get_post_meta($product_id, '_product_image_gallery', true);
        if ($csv !== '') {
            foreach (explode(',', $csv) as $id) {
                $id = (int) $id;
                if ($id) $attach_ids[] = $id;
            }
        }

        $attach_ids = array_values(array_unique(array_filter(array_map('intval', $attach_ids))));
        foreach ($attach_ids as $aid) {
            if (get_post_type($aid) !== 'attachment') continue;
            $md5 = get_post_meta($aid, '_vi_image_md5', true);
            if (!$md5) {
                $path = get_attached_file($aid);
                if ($path && file_exists($path)) {
                    $data = @file_get_contents($path);
                    if ($data !== false) {
                        $md5 = md5($data);
                        update_post_meta($aid, '_vi_image_md5', $md5);
                    }
                }
            }
            if ($md5) $hash_to_attach[$md5] = $aid;
        }
        return $hash_to_attach;
    }
}

/**
 * Validate product images and return detailed status.
 * Checks if featured/gallery attachment IDs actually exist and have valid files.
 * Returns: ['featured_valid' => bool, 'gallery_valid_count' => int, 'gallery_expected_ids' => int[], 'orphan_ids' => int[], 'needs_repair' => bool]
 */
if (!function_exists('vi_validate_product_images')) {
    function vi_validate_product_images($product_id, $expected_image_count = 0) {
        $result = [
            'featured_valid'      => false,
            'gallery_valid_count' => 0,
            'gallery_expected_ids'=> [],
            'orphan_ids'          => [],
            'needs_repair'        => false,
        ];

        // Check featured image
        $featured_id = (int) get_post_thumbnail_id($product_id);
        if ($featured_id) {
            if (get_post_type($featured_id) === 'attachment') {
                $path = get_attached_file($featured_id);
                if ($path && file_exists($path)) {
                    $result['featured_valid'] = true;
                } else {
                    $result['orphan_ids'][] = $featured_id;
                    log_image_repair("product {$product_id}: featured attach_id={$featured_id} file missing");
                }
            } else {
                $result['orphan_ids'][] = $featured_id;
                log_image_repair("product {$product_id}: featured attach_id={$featured_id} is not an attachment");
            }
        }

        // Check gallery images
        $csv = (string) get_post_meta($product_id, '_product_image_gallery', true);
        if ($csv !== '') {
            foreach (explode(',', $csv) as $id) {
                $aid = (int) $id;
                if (!$aid) continue;

                $result['gallery_expected_ids'][] = $aid;

                if (get_post_type($aid) === 'attachment') {
                    $path = get_attached_file($aid);
                    if ($path && file_exists($path)) {
                        $result['gallery_valid_count']++;
                    } else {
                        $result['orphan_ids'][] = $aid;
                        log_image_repair("product {$product_id}: gallery attach_id={$aid} file missing");
                    }
                } else {
                    $result['orphan_ids'][] = $aid;
                    log_image_repair("product {$product_id}: gallery attach_id={$aid} is not an attachment");
                }
            }
        }

        // Determine if repair is needed
        // Needs repair if: no valid featured, no valid gallery, has orphan IDs, or fewer images than expected
        if (!$result['featured_valid']) {
            $result['needs_repair'] = true;
        } elseif ($result['gallery_valid_count'] === 0) {
            $result['needs_repair'] = true;
        } elseif (!empty($result['orphan_ids'])) {
            $result['needs_repair'] = true;
        } elseif ($expected_image_count > 0 && ($result['gallery_valid_count'] + 1) < $expected_image_count) {
            // +1 for featured; if we have fewer than expected, trigger repair
            $result['needs_repair'] = true;
        }

        return $result;
    }
}

/**
 * Clean orphan attachment references from a product's gallery meta.
 */
if (!function_exists('vi_clean_orphan_gallery_ids')) {
    function vi_clean_orphan_gallery_ids($product_id) {
        $csv = (string) get_post_meta($product_id, '_product_image_gallery', true);
        if ($csv === '') return;

        $valid_ids = [];
        foreach (explode(',', $csv) as $id) {
            $aid = (int) $id;
            if (!$aid) continue;
            if (get_post_type($aid) === 'attachment') {
                $path = get_attached_file($aid);
                if ($path && file_exists($path)) {
                    $valid_ids[] = $aid;
                }
            }
        }

        $new_csv = implode(',', $valid_ids);
        if ($new_csv !== $csv) {
            if ($new_csv !== '') {
                update_post_meta($product_id, '_product_image_gallery', $new_csv);
            } else {
                delete_post_meta($product_id, '_product_image_gallery');
            }
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }
            clean_post_cache($product_id);
            log_image_repair("product {$product_id}: cleaned orphan gallery IDs (was='{$csv}' now='{$new_csv}')");
        }

        // Also check featured
        $featured_id = (int) get_post_thumbnail_id($product_id);
        if ($featured_id) {
            $is_valid = false;
            if (get_post_type($featured_id) === 'attachment') {
                $path = get_attached_file($featured_id);
                if ($path && file_exists($path)) {
                    $is_valid = true;
                }
            }
            if (!$is_valid) {
                delete_post_thumbnail($product_id);
                log_image_repair("product {$product_id}: removed orphan featured attach_id={$featured_id}");
            }
        }
    }
}

if (!function_exists('vi_attach_prepared_images_repair')) {
    function vi_attach_prepared_images_repair($product_id, $vehicle_title, $prepared) {
        $existing_hashes = vi_collect_existing_image_md5s($product_id);

        $featured_already = (int) get_post_thumbnail_id($product_id);
        $featured_id = $featured_already ?: 0;
        $gallery_ids = [];

        $current_csv = (string) get_post_meta($product_id, '_product_image_gallery', true);
        if ($current_csv !== '') {
            foreach (explode(',', $current_csv) as $id) {
                $id = (int) $id;
                if ($id) $gallery_ids[] = $id;
            }
        }

        // Featured (only if none set)
        if (!$featured_id && !empty($prepared['featured'])) {
            $f = $prepared['featured'][0];
            $md5 = md5($f['bytes']);
            if (!isset($existing_hashes[$md5])) {
                $fid = vp_save_bytes_as_attachment($f['bytes'], $f['base'], $product_id, $f['label']);
                if ($fid) {
                    set_post_thumbnail($product_id, $fid);
                    $readback = (int) get_post_meta($product_id, '_thumbnail_id', true);
                    if ($readback === $fid) {
                        log_image_repair("product {$product_id}: set featured attach_id={$fid}");
                    } else {
                        log_image_repair("product {$product_id}: featured meta FAILED to persist for attach_id={$fid} (readback={$readback})");
                    }
                    update_post_meta($fid, '_vi_image_md5', $md5);
                    $featured_id = $fid;
                } else {
                    log_image_repair("product {$product_id}: FAILED saving featured");
                }
            } else {
                $maybe = (int) $existing_hashes[$md5];
                if ($maybe) {
                    set_post_thumbnail($product_id, $maybe);
                    $readback = (int) get_post_meta($product_id, '_thumbnail_id', true);
                    if ($readback === $maybe) {
                        log_image_repair("product {$product_id}: reused existing featured attach_id={$maybe}");
                    } else {
                        log_image_repair("product {$product_id}: featured meta FAILED to persist for reused attach_id={$maybe} (readback={$readback})");
                    }
                    $featured_id = $maybe;
                }
            }
        }

        // Gallery (append any md5 not present yet)
        $added = 0;
        foreach ($prepared['gallery'] as $entry) {
            $md5 = md5($entry['bytes']);
            if (isset($existing_hashes[$md5])) {
                $aid = (int) $existing_hashes[$md5];
                if ($aid && $aid !== $featured_id && !in_array($aid, $gallery_ids, true)) {
                    $gallery_ids[] = $aid;
                }
                continue;
            }
            $gid = vp_save_bytes_as_attachment($entry['bytes'], $entry['base'], $product_id, $entry['label']);
            if ($gid) {
                update_post_meta($gid, '_vi_image_md5', $md5);
                if ($gid !== $featured_id) $gallery_ids[] = $gid;
                $added++;
                log_image_repair("product {$product_id}: added gallery attach_id={$gid}");
            } else {
                log_image_repair("product {$product_id}: FAILED saving gallery image");
            }
        }

        // Persist gallery (robust)
        $gallery_ids = array_values(array_unique(array_filter(array_map('intval', $gallery_ids))));
        $csv = implode(',', $gallery_ids);

        if ($csv !== '') {
            update_post_meta($product_id, '_product_image_gallery', $csv);
        } else {
            delete_post_meta($product_id, '_product_image_gallery');
        }
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }
        clean_post_cache($product_id);

        $readback = (string) get_post_meta($product_id, '_product_image_gallery', true);
        if ($readback !== $csv) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product->set_gallery_image_ids($gallery_ids);
                $product->save();
                if (function_exists('wc_delete_product_transients')) {
                    wc_delete_product_transients($product_id);
                }
                clean_post_cache($product_id);
                $readback = (string) get_post_meta($product_id, '_product_image_gallery', true);
            }
        }

        if ($readback === $csv) {
            log_image_repair("product {$product_id}: gallery OK (" . count($gallery_ids) . " images; added={$added})");
        } else {
            log_image_repair("product {$product_id}: gallery PERSIST FAILED (expected='{$csv}' read='{$readback}')");
        }

        // Store combined image hash so import pass can detect future feed image changes
        update_post_meta($product_id, '_vi_images_hash', vi_hash_prepared_images($prepared));

        return ['featured_id' => (int) $featured_id, 'gallery_ids' => $gallery_ids];
    }
}

if (!function_exists('vi_prepare_images_from_feed_for_vehicle')) {
    function vi_prepare_images_from_feed_for_vehicle($vehicle_id, $vehicle_title = '') {
        global $image_labels;

        $endpoint = sprintf('https://paceapp-server.azurewebsites.net/api/entity/paceWebPrepImages/id/%s', rawurlencode($vehicle_id));
        log_image_repair("fetching (collect) {$endpoint}");

        $json = vi_fetch_json_curl($endpoint, 'images-collect:' . $vehicle_id, 30);
        if ($json === null) {
            log_image_repair("vehicle {$vehicle_id}: fetch failed");
            return ['featured' => [], 'gallery' => []];
        }

        $obj = $json;
        if (isset($json[0]) && is_array($json[0])) $obj = $json[0];
        if (isset($obj['images']) && is_array($obj['images'])) $obj = $obj['images'];

        if (!is_array($obj) || empty($obj)) {
            log_image_repair("vehicle {$vehicle_id}: images empty/invalid");
            return ['featured' => [], 'gallery' => []];
        }

        $ordered_keys = array_keys($image_labels);
        $featured_key = 'x_studio_vp_front_feature_photo';
        if (in_array($featured_key, $ordered_keys, true)) {
            $ordered_keys = array_values(array_unique(array_merge([$featured_key], $ordered_keys)));
        } else {
            array_unshift($ordered_keys, $featured_key);
        }

        $featured = [];
        $gallery  = [];

        foreach ($ordered_keys as $key) {
            if (!array_key_exists($key, $obj)) continue;
            $raw = $obj[$key];
            if ($raw === null) continue;
            if (is_string($raw)) {
                $trim = strtolower(trim($raw));
                if ($trim === '' || $trim === 'none' || $trim === '[]' || $trim === '{}') continue;
            }
            $bytes = decode_feed_image($raw);
            if ($bytes === false || strlen($bytes) < 64) continue;

            $label = $image_labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
            $base  = sanitize_title($vehicle_title ? "{$vehicle_title} {$label}" : $label) ?: 'vehicle-image';

            $entry = ['bytes' => $bytes, 'base' => $base, 'label' => ($vehicle_title ? "{$vehicle_title} – {$label}" : $label)];

            if ($key === $featured_key && empty($featured)) {
                $featured[] = $entry;
            } else {
                $gallery[]  = $entry;
            }
        }

        // Fallback: promote first gallery image to featured if none set
        if (empty($featured) && !empty($gallery)) {
            $featured[] = array_shift($gallery);
        }

        return ['featured' => $featured, 'gallery' => $gallery];
    }
}

// === Repair runner ==========================================================

if (!function_exists('repair_missing_vehicle_images')) {
    /**
     * Scan products and repair those with missing/orphan images.
     *
     * @param int $limit Max products to scan (-1 = all products). Default 200.
     * @param int $offset Skip this many products (for pagination). Default 0.
     * @param string|null $specific_sku If set, only repair this specific SKU.
     */
    function repair_missing_vehicle_images($limit = 200, $offset = 0, $specific_sku = null) {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $limit_display = $limit > 0 ? $limit : 'ALL';
        log_image_repair("=== Repair START (limit={$limit_display}, offset={$offset}" . ($specific_sku ? ", sku={$specific_sku}" : "") . ") ===");

        // Build query args
        $args = [
            'post_type'      => 'product',
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => $limit > 0 ? (int)$limit : -1,
            'offset'         => (int)$offset,
            'fields'         => 'ids',
            'orderby'        => 'ID',  // Use ID for consistent pagination
            'order'          => 'ASC',
        ];

        // If specific SKU requested, find that product only
        if ($specific_sku !== null) {
            $args['meta_query'] = [
                [
                    'key'   => '_sku',
                    'value' => $specific_sku,
                ]
            ];
            $args['posts_per_page'] = 1;
            $args['offset'] = 0;
        }

        $product_ids = get_posts($args);
        $total_scanned = count($product_ids);
        $repaired = 0;
        $skipped_no_sku = 0;
        $skipped_ok = 0;
        $skipped_no_feed = 0;

        log_image_repair("Found {$total_scanned} products to scan");

        foreach ($product_ids as $pid) {
            $sku = (string) get_post_meta($pid, '_sku', true);
            if ($sku === '') {
                $skipped_no_sku++;
                continue;
            }

            // Skip if the create process is still attaching images to this product
            if (get_transient("vi_attach_lock_{$pid}")) {
                log_image_repair("product {$pid} (sku={$sku}): skipped repair — image attach in progress");
                continue;
            }

            // First, clean any orphan attachment references
            vi_clean_orphan_gallery_ids($pid);

            // Now validate the product's images with the new robust check
            $validation = vi_validate_product_images($pid);

            if (!$validation['needs_repair']) {
                $skipped_ok++;
                continue;
            }

            $title = get_the_title($pid);
            log_image_repair("product {$pid} (sku={$sku}) NEEDS REPAIR: " .
                "featured_valid=" . ($validation['featured_valid'] ? 'yes' : 'NO') .
                ", gallery_valid=" . $validation['gallery_valid_count'] .
                ", orphans=" . count($validation['orphan_ids']));

            // Fetch images from feed
            $prepared = vi_prepare_images_from_feed_for_vehicle($sku, $title);
            if (empty($prepared['featured']) && empty($prepared['gallery'])) {
                log_image_repair("product {$pid} (sku={$sku}): feed has no usable images; skipping");
                $skipped_no_feed++;
                continue;
            }

            $feed_image_count = count($prepared['featured']) + count($prepared['gallery']);
            log_image_repair("product {$pid} (sku={$sku}): feed has {$feed_image_count} images available");

            // Perform the repair; hold the lock so sync doesn't collide.
            set_transient( "vi_attach_lock_{$pid}", time(), 120 );
            vi_attach_prepared_images_repair($pid, $title, $prepared);
            delete_transient( "vi_attach_lock_{$pid}" );
            $repaired++;

            log_image_repair("product {$pid} (sku={$sku}): repair completed");
        }

        log_image_repair("=== Repair END — scanned={$total_scanned}, repaired={$repaired}, skipped_ok={$skipped_ok}, skipped_no_sku={$skipped_no_sku}, skipped_no_feed={$skipped_no_feed} ===");

        return [
            'scanned'        => $total_scanned,
            'repaired'       => $repaired,
            'skipped_ok'     => $skipped_ok,
            'skipped_no_sku' => $skipped_no_sku,
            'skipped_no_feed'=> $skipped_no_feed,
        ];
    }
}

// === Hook: WP-Cron event for repair (manual or schedule yourself) ===========
add_action('vehicle_images_repair_event', function () {
    log_image_repair('WP-Cron fired: vehicle_images_repair_event');
    // Scan up to 200 products per cron run (increased from 50)
    repair_missing_vehicle_images(200);
});

// Always ensure it's scheduled (hourly). No activation hook needed.
add_action('init', function () {
    // If WP-Cron is disabled, log once so you know why it won't run automatically.
    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
        static $logged = false;
        if (!$logged) {
            log_image_repair('WARNING: DISABLE_WP_CRON is true — WP-Cron will not run automatically.');
            $logged = true;
        }
        // You can still trigger manually via WP-CLI/REST.
        return;
    }

    // Schedule if missing. wp_next_scheduled prevents duplicates.
    if (!wp_next_scheduled('vehicle_images_repair_event')) {
        // Delay first run by 2 minutes, then run hourly
        wp_schedule_event(time() + 120, 'hourly', 'vehicle_images_repair_event');
        log_image_repair('Scheduled vehicle_images_repair_event (hourly) from init.');
    }
});

// === URL trigger for manual image repair ====================================
// Usage:
//   ?vi_repair_images=1           - Scan all products (up to 500)
//   ?vi_repair_images=1&limit=100 - Scan up to 100 products
//   ?vi_repair_images=1&sku=ABC123 - Repair specific SKU only
//   ?vi_repair_images=1&offset=100 - Skip first 100 products (pagination)
add_action('init', function () {
    if (!isset($_GET['vi_repair_images'])) {
        return;
    }

    // Only admins allowed
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        status_header(403);
        exit('Forbidden - admin access required');
    }
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'sa_motorlease_admin_action')) {
        wp_die('Security check failed. Use the <a href="' . esc_url(admin_url('admin.php?page=sa-motorlease-status')) . '">SA Motorlease Status</a> page.', 'Forbidden', ['response' => 403]);
    }

    log_image_repair('URL trigger activated: ?vi_repair_images=1');

    // Parse parameters
    $limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 500;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $sku    = isset($_GET['sku']) ? sanitize_text_field($_GET['sku']) : null;

    // Run the repair
    $result = repair_missing_vehicle_images($limit, $offset, $sku);

    // Output results
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== Vehicle Image Repair Results ===\n\n";

    if ($sku) {
        echo "Mode: Single SKU repair\n";
        echo "SKU: {$sku}\n\n";
    } else {
        echo "Mode: Batch scan\n";
        echo "Limit: {$limit}\n";
        echo "Offset: {$offset}\n\n";
    }

    echo "Products scanned:    {$result['scanned']}\n";
    echo "Products repaired:   {$result['repaired']}\n";
    echo "Skipped (OK):        {$result['skipped_ok']}\n";
    echo "Skipped (no SKU):    {$result['skipped_no_sku']}\n";
    echo "Skipped (no feed):   {$result['skipped_no_feed']}\n\n";

    echo "Log file: " . vehicle_image_repair_log_path() . "\n";

    if (!$sku && $result['scanned'] >= $limit) {
        $next_offset = $offset + $limit;
        $next_url    = add_query_arg([
            'vi_repair_images' => '1',
            'limit'            => $limit,
            'offset'           => $next_offset,
            '_wpnonce'         => wp_create_nonce('sa_motorlease_admin_action'),
        ], admin_url());
        echo "\nMore products may exist. To continue:\n";
        echo esc_url_raw($next_url) . "\n";
    }

    exit;
}, 20);

// === URL trigger: run create/update import in-process =======================
// Bypasses WP-Cron / WP-Crontrol entirely (no loopback request), so hosts with
// a broken self-loopback can still trigger an import from the browser.
// Usage:
//   ?vi_run_import=1                - run create then update
//   ?vi_run_import=1&mode=create    - create only
//   ?vi_run_import=1&mode=update    - update only
//   ?vi_run_import=1&clear_locks=1  - delete stuck create/update lock transients first
add_action('init', function () {
    if (!isset($_GET['vi_run_import'])) return;

    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        status_header(403);
        exit('Forbidden - admin access required');
    }
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'sa_motorlease_admin_action')) {
        wp_die('Security check failed. Use the <a href="' . esc_url(admin_url('admin.php?page=sa-motorlease-status')) . '">SA Motorlease Status</a> page.', 'Forbidden', ['response' => 403]);
    }

    $mode = isset($_GET['mode']) ? sanitize_key($_GET['mode']) : 'both';
    if (!in_array($mode, ['create', 'update', 'both'], true)) $mode = 'both';
    $clear_locks = !empty($_GET['clear_locks']);

    @ignore_user_abort(true);
    if (function_exists('set_time_limit')) @set_time_limit(VI_MAX_CREATE_SEC + VI_MAX_UPDATE_SEC + 60);

    header('Content-Type: text/plain; charset=utf-8');
    echo "=== vi_run_import (mode={$mode}, clear_locks=" . ($clear_locks ? 'yes' : 'no') . ") ===\n\n";
    @ob_implicit_flush(true);
    while (ob_get_level() > 0) { @ob_end_flush(); }

    // Surface fatals inline + to the log. PHP swallows fatals into the WP
    // generic error screen by default, which is what the operator just hit;
    // this captures error_get_last() at shutdown and dumps it where it's
    // actually readable.
    register_shutdown_function(function () {
        $e = error_get_last();
        if (!$e) return;
        if (!in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        $msg = sprintf('[FATAL] %s in %s:%d', $e['message'], $e['file'], $e['line']);
        sa_motorlease_log('import', SA_MOTORLEASE_LOG_ERROR, "vi_run_import: {$msg}");
        // Clear locks so the next attempt isn't blocked by a stuck transient.
        delete_transient('vi_create_running_lock');
        delete_transient('vi_update_running_lock');
        // Best-effort: also print to the response if headers/buffer still allow it.
        echo "\n\n!!! FATAL !!!\n{$msg}\nLocks cleared.\n";
    });

    if ($clear_locks) {
        $had_create = (bool) get_transient('vi_create_running_lock');
        $had_update = (bool) get_transient('vi_update_running_lock');
        delete_transient('vi_create_running_lock');
        delete_transient('vi_update_running_lock');
        echo "Locks cleared (create_was_set=" . ($had_create ? 'yes' : 'no') . ", update_was_set=" . ($had_update ? 'yes' : 'no') . ")\n\n";
        sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, "URL trigger: locks cleared (create={$had_create}, update={$had_update})");
    }

    sa_motorlease_log('import', SA_MOTORLEASE_LOG_WARN, "URL trigger: vi_run_import mode={$mode}");

    $run = function ($label, $fn) {
        echo "--- running {$label}() ---\n";
        $t0 = microtime(true);
        try {
            $fn();
            printf("elapsed: %.2fs (ok)\n\n", microtime(true) - $t0);
        } catch (\Throwable $e) {
            $msg = sprintf('%s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
            sa_motorlease_log('import', SA_MOTORLEASE_LOG_ERROR, "vi_run_import {$label} threw: {$msg}");
            // Release the lock the function set before throwing.
            delete_transient($label === 'vi_create_new_products' ? 'vi_create_running_lock' : 'vi_update_running_lock');
            printf("elapsed: %.2fs (THROWN)\n\n!!! EXCEPTION !!!\n%s\n\n%s\n\n", microtime(true) - $t0, $msg, $e->getTraceAsString());
        }
    };

    if ($mode === 'create' || $mode === 'both') $run('vi_create_new_products', 'vi_create_new_products');
    if ($mode === 'update' || $mode === 'both') $run('vi_update_existing_products', 'vi_update_existing_products');

    echo "Log file: " . sa_motorlease_log_path() . "\n";
    echo "Done. Tail the log for full output.\n";
    exit;
}, 20);

// === Bulk image sync (detect + apply feed image changes) ====================

/**
 * Scan products and update images that have changed in the feed.
 * Unlike repair_missing_vehicle_images(), this detects *content* changes
 * via the stored _vi_images_hash, not just missing/broken attachments.
 *
 * @param int         $limit        Max products to scan (-1 = all). Default 200.
 * @param int         $offset       Skip this many products (for pagination). Default 0.
 * @param string|null $specific_sku If set, only sync this SKU.
 * @param bool        $dry_run      When true, report what would change but make no writes.
 * @return array      Summary counts.
 */
function vi_bulk_sync_vehicle_images($limit = 200, $offset = 0, $specific_sku = null, $dry_run = false, $max_seconds = 0) {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    global $image_labels;

    $t0   = microtime(true);
    $mode = $dry_run ? 'DRY-RUN' : 'LIVE';
    $limit_display = $limit > 0 ? $limit : 'ALL';
    log_image_repair("=== Image Sync START [{$mode}] (limit={$limit_display}, offset={$offset}" . ($specific_sku ? ", sku={$specific_sku}" : '') . ($max_seconds > 0 ? ", max_seconds={$max_seconds}" : '') . ') ===');

    $args = [
        'post_type'      => 'product',
        'post_status'    => ['publish', 'draft', 'private'],
        'posts_per_page' => $limit > 0 ? (int)$limit : -1,
        'offset'         => (int)$offset,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];

    if ($specific_sku !== null) {
        $args['meta_query'] = [['key' => '_sku', 'value' => $specific_sku]];
        $args['posts_per_page'] = 1;
        $args['offset'] = 0;
    }

    $product_ids    = get_posts($args);
    $total_scanned  = count($product_ids);
    $updated        = 0;
    $unchanged      = 0;
    $no_feed        = 0;
    $skipped_no_sku = 0;
    $processed      = 0;

    log_image_repair("Found {$total_scanned} products to scan");

    foreach ($product_ids as $pid) {
        if ($max_seconds > 0 && (microtime(true) - $t0) >= $max_seconds) {
            log_image_repair("Image Sync [{$mode}]: time limit {$max_seconds}s reached; stopped after {$processed}/{$total_scanned} products");
            break;
        }
        $processed++;

        $sku = (string) get_post_meta($pid, '_sku', true);
        if ($sku === '') {
            $skipped_no_sku++;
            continue;
        }

        // Skip if the create process is still attaching images to this product
        if (get_transient("vi_attach_lock_{$pid}")) {
            log_image_repair("product {$pid} (sku={$sku}): skipped — image attach in progress");
            continue;
        }

        $title       = get_the_title($pid);
        $stored_hash = (string) get_post_meta($pid, '_vi_images_hash', true);

        // Fetch fresh images from feed
        $fresh_prepared = vi_collect_usable_images($sku, $title, $image_labels);

        if (empty($fresh_prepared['featured']) && empty($fresh_prepared['gallery'])) {
            log_image_repair("product {$pid} (sku={$sku}): no images in feed — skipping");
            $no_feed++;
            continue;
        }

        $fresh_hash = vi_hash_prepared_images($fresh_prepared);

        if ($stored_hash === '') {
            // No stored hash yet — compare feed images against currently-attached
            // images by MD5 set so we don't blindly replace images that are current.
            $existing_md5s = array_keys(vi_collect_existing_image_md5s($pid));
            $feed_md5s     = array_map(
                fn($img) => md5($img['bytes']),
                array_merge($fresh_prepared['featured'], $fresh_prepared['gallery'])
            );
            sort($existing_md5s);
            sort($feed_md5s);

            if ($existing_md5s === $feed_md5s) {
                // Attachments already match feed — backfill hash and move on
                if (!$dry_run) {
                    update_post_meta($pid, '_vi_images_hash', $fresh_hash);
                }
                log_image_repair("product {$pid} (sku={$sku}): images already match feed — hash backfilled" . ($dry_run ? ' [DRY-RUN: hash not stored]' : ''));
                $unchanged++;
            } else {
                if ($dry_run) {
                    log_image_repair("product {$pid} (sku={$sku}): [DRY-RUN] images differ from feed — would update");
                } else {
                    log_image_repair("product {$pid} (sku={$sku}): images differ from feed — syncing per-image");
                    set_transient( "vi_attach_lock_{$pid}", time(), 120 );
                    vi_diff_sync_product_images($pid, $fresh_prepared);
                    delete_transient( "vi_attach_lock_{$pid}" );
                }
                $updated++;
            }
            continue;
        }

        if ($fresh_hash === $stored_hash) {
            log_image_repair("product {$pid} (sku={$sku}): images unchanged (hash={$stored_hash})");
            $unchanged++;
            continue;
        }

        // Hash mismatch — images have changed in the feed
        if ($dry_run) {
            log_image_repair("product {$pid} (sku={$sku}): [DRY-RUN] images CHANGED (old={$stored_hash} new={$fresh_hash}) — no changes made");
        } else {
            log_image_repair("product {$pid} (sku={$sku}): images CHANGED (old={$stored_hash} new={$fresh_hash}) — syncing per-image");
            set_transient( "vi_attach_lock_{$pid}", time(), 120 );
            vi_diff_sync_product_images($pid, $fresh_prepared);
            delete_transient( "vi_attach_lock_{$pid}" );
        }
        $updated++;
    }

    log_image_repair("=== Image Sync END [{$mode}] — scanned={$total_scanned}, processed={$processed}, updated={$updated}, unchanged={$unchanged}, no_feed={$no_feed}, no_sku={$skipped_no_sku} ===");

    return [
        'mode'           => $mode,
        'scanned'        => $total_scanned,
        'processed'      => $processed,
        'updated'        => $updated,
        'unchanged'      => $unchanged,
        'no_feed'        => $no_feed,
        'skipped_no_sku' => $skipped_no_sku,
    ];
}

// === URL trigger: progress page for bulk image sync =========================
// ?vi_sync_images=1           - live sync (progress page)
// ?vi_sync_images=1&dry_run=1 - dry run (progress page, no writes)
// ?vi_sync_images=1&sku=ABC   - single SKU
add_action('init', function () {
    if (!isset($_GET['vi_sync_images'])) return;

    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        status_header(403);
        exit('Forbidden - admin access required');
    }
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'sa_motorlease_admin_action')) {
        wp_die('Security check failed. Use the <a href="' . esc_url(admin_url('admin.php?page=sa-motorlease-status')) . '">SA Motorlease Status</a> page.', 'Forbidden', ['response' => 403]);
    }

    $dry_run = !empty($_GET['dry_run']);
    $sku     = isset($_GET['sku']) ? sanitize_text_field($_GET['sku']) : null;

    // Total product count for progress bar
    $counts  = wp_count_posts('product');
    $total   = (int)($counts->publish ?? 0)
             + (int)($counts->draft   ?? 0)
             + (int)($counts->private ?? 0);
    if ($sku) $total = 1;

    $mode_label  = $dry_run ? 'DRY RUN'  : 'LIVE';
    $mode_color  = $dry_run ? '#d29922'  : '#238636';
    $sku_js      = $sku ? json_encode($sku) : 'null';
    $total_js    = (int)$total;
    $dry_run_js  = $dry_run ? 'true' : 'false';

    header('Content-Type: text/html; charset=utf-8');
    // Prevent nginx/Apache from buffering the full page before sending
    header('X-Accel-Buffering: no');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vehicle Image Sync</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Courier New',monospace;background:#0d1117;color:#c9d1d9;padding:24px;font-size:14px}
h1{font-size:18px;color:#f0f6fc;margin-bottom:16px;display:flex;align-items:center;gap:10px}
.badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:12px;color:#0d1117;background:<?= $mode_color ?>}
#status{color:#8b949e;font-size:13px;margin-bottom:8px}
.prog-wrap{background:#161b22;border-radius:6px;height:8px;margin:8px 0 4px}
.prog-bar{background:#238636;height:8px;border-radius:6px;width:0;transition:width .4s}
.prog-label{font-size:12px;color:#8b949e;margin-bottom:16px}
.stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.stat{background:#161b22;border:1px solid #30363d;border-radius:6px;padding:10px 16px;min-width:110px}
.stat-label{font-size:10px;color:#8b949e;text-transform:uppercase;letter-spacing:.5px}
.stat-value{font-size:26px;font-weight:700;color:#f0f6fc;margin-top:2px}
.has-value{color:#3fb950 !important}
.has-value.warn{color:#d29922 !important}
#log{background:#010409;border:1px solid #30363d;border-radius:6px;height:380px;overflow-y:auto;padding:12px;font-size:12px;line-height:1.7}
.lb{color:#444;border-top:1px solid #21262d;padding-top:3px;margin-top:3px}
.lb:first-child{border-top:none}
.lc{color:#d29922}
.li{color:#58a6ff}
.le{color:#f85149}
.ld{color:#3fb950;font-weight:700;margin-top:6px}
</style>
</head>
<body>
<h1>Vehicle Image Sync <span class="badge"><?= esc_html($mode_label) ?></span></h1>
<div id="status">Starting…</div>
<div class="prog-wrap"><div class="prog-bar" id="pb"></div></div>
<div class="prog-label" id="pl">0 / <?= $total_js ?> products</div>
<div class="stats">
  <div class="stat"><div class="stat-label">Updated</div><div class="stat-value" id="s-updated">0</div></div>
  <div class="stat"><div class="stat-label">Unchanged</div><div class="stat-value" id="s-unchanged">0</div></div>
  <div class="stat"><div class="stat-label">No Feed</div><div class="stat-value" id="s-no_feed">0</div></div>
  <div class="stat"><div class="stat-label">No SKU</div><div class="stat-value" id="s-skipped_no_sku">0</div></div>
</div>
<div id="log"></div>
<script>
const TOTAL   = <?= $total_js ?>;
const DRY_RUN = <?= $dry_run_js ?>;
const SKU     = <?= $sku_js ?>;
const NONCE   = <?= wp_json_encode(wp_create_nonce('sa_motorlease_admin_action')) ?>;
const BATCH   = 20;

let offset = 0, batch = 0;
const counts = {updated:0,unchanged:0,no_feed:0,skipped_no_sku:0};

const log = document.getElementById('log');
function addLog(html, cls) {
    const d = document.createElement('div');
    d.className = cls || 'lb';
    d.innerHTML = html;
    log.appendChild(d);
    log.scrollTop = log.scrollHeight;
}
function bump(key, n) {
    if (!n) return;
    counts[key] += n;
    const el = document.getElementById('s-' + key);
    el.textContent = counts[key];
    el.classList.add('has-value');
    if (key === 'no_feed' || key === 'skipped_no_sku') el.classList.add('warn');
}

async function runBatch() {
    batch++;
    const p = new URLSearchParams({vi_sync_batch:1, offset, limit:BATCH});
    if (DRY_RUN) p.set('dry_run', 1);
    if (SKU)     p.set('sku', SKU);
    if (NONCE)   p.set('_wpnonce', NONCE);

    document.getElementById('status').textContent =
        'Processing batch ' + batch + '… (' + offset + ' / ' + (TOTAL || '?') + ' products)';

    let data;
    try {
        const r = await fetch('?' + p.toString());
        if (!r.ok) throw new Error('HTTP ' + r.status);
        data = await r.json();
    } catch(e) {
        addLog('&#9888; Request failed: ' + e.message, 'le');
        document.getElementById('status').textContent = 'Error — see above';
        return;
    }

    // update counters
    for (const k of Object.keys(counts)) bump(k, data[k]);

    // batch summary line
    const parts = [];
    if (data.updated)   parts.push('<span class="lc">' + data.updated + ' updated</span>');
    if (data.unchanged) parts.push(data.unchanged + ' unchanged');
    if (data.no_feed)   parts.push(data.no_feed + ' no feed');
    addLog('Batch ' + batch + ' (offset ' + offset + '): ' +
        (parts.length ? parts.join(', ') : 'nothing to do'));

    offset += (data.processed || 0);

    // progress bar
    const pct = TOTAL > 0 ? Math.min(100, Math.round(offset / TOTAL * 100)) : 0;
    document.getElementById('pb').style.width = pct + '%';
    document.getElementById('pl').textContent  = offset + ' / ' + (TOTAL || '?') + ' products';

    if (data.done) {
        document.getElementById('pb').style.width = '100%';
        document.getElementById('status').textContent = 'Complete \u2713';
        addLog('Done — ' + offset + ' products scanned' + (DRY_RUN ? ' (DRY RUN — no changes made)' : ''), 'ld');
    } else {
        setTimeout(runBatch, 100);
    }
}

runBatch();
</script>
</body></html>
<?php
    exit;
}, 20);

// === JSON batch endpoint for the sync progress page =========================
// Called by the progress page JS — not intended for direct use.
add_action('init', function () {
    if (!isset($_GET['vi_sync_batch'])) return;

    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        status_header(403);
        wp_send_json_error('Forbidden', 403);
        exit;
    }
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'sa_motorlease_admin_action')) {
        status_header(403);
        wp_send_json_error('Security check failed', 403);
        exit;
    }

    $limit   = isset($_GET['limit'])  ? max(1, min(50, (int)$_GET['limit'])) : 20;
    $offset  = isset($_GET['offset']) ? max(0, (int)$_GET['offset'])         : 0;
    $sku     = isset($_GET['sku'])    ? sanitize_text_field($_GET['sku'])     : null;
    $dry_run = !empty($_GET['dry_run']);

    $result = vi_bulk_sync_vehicle_images($limit, $offset, $sku, $dry_run, 25);

    // done = reached end of catalogue (got fewer results than a full batch)
    // AND no time-guard interruption (processed everything we fetched)
    $result['done'] = ($result['scanned'] < $limit && $result['processed'] >= $result['scanned']);

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}, 20);

// === URL trigger: view feed images inline (no upload) =======================
// ?vi_view_feed_images=1            - SKU input form
// ?vi_view_feed_images=1&sku=ABC    - decode + render images for that SKU
add_action('init', function () {
    if (!isset($_GET['vi_view_feed_images'])) return;

    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        status_header(403);
        exit('Forbidden - admin access required');
    }

    global $image_labels;
    $sku = isset($_GET['sku']) ? sanitize_text_field($_GET['sku']) : '';

    $prepared = null;
    if ($sku !== '') {
        $prepared = vi_collect_usable_images($sku, '', $image_labels);
    }

    header('Content-Type: text/html; charset=utf-8');
    header('X-Accel-Buffering: no');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>View Feed Images</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Courier New',monospace;background:#0d1117;color:#c9d1d9;padding:24px;font-size:14px}
h1{font-size:18px;color:#f0f6fc;margin-bottom:16px}
h2{font-size:14px;color:#f0f6fc;margin:20px 0 10px;text-transform:uppercase;letter-spacing:.5px}
form{background:#161b22;border:1px solid #30363d;border-radius:6px;padding:16px;margin-bottom:20px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
label{font-size:12px;color:#8b949e;text-transform:uppercase;letter-spacing:.5px}
input[type=text]{background:#010409;border:1px solid #30363d;border-radius:6px;color:#c9d1d9;padding:8px 10px;font-family:inherit;font-size:14px;min-width:240px}
button{background:#238636;color:#0d1117;border:0;border-radius:6px;padding:8px 16px;font-family:inherit;font-size:13px;font-weight:700;cursor:pointer}
button:hover{background:#2ea043}
.meta{color:#8b949e;font-size:12px;margin-bottom:14px}
.notice{background:#161b22;border:1px solid #30363d;border-radius:6px;padding:14px;color:#d29922}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
.card{background:#161b22;border:1px solid #30363d;border-radius:6px;padding:10px}
.card.featured{border-color:#3fb950}
.card img{width:100%;height:auto;display:block;border-radius:4px;background:#010409}
.card .lbl{font-size:12px;color:#8b949e;margin-top:8px;word-break:break-word}
.card .key{font-size:10px;color:#58a6ff;margin-top:2px;font-family:monospace}
.badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;color:#0d1117;background:#3fb950;margin-left:6px;vertical-align:middle}
</style>
</head>
<body>
<h1>View Feed Images</h1>
<form method="get" action="">
    <input type="hidden" name="vi_view_feed_images" value="1">
    <label for="sku">SKU / vehicle_id:</label>
    <input type="text" id="sku" name="sku" value="<?= esc_attr($sku) ?>" autofocus>
    <button type="submit">View Images</button>
</form>
<?php if ($sku === ''): ?>
    <div class="meta">Enter a vehicle SKU or vehicle_id to fetch and render its images directly from the PaceApp feed. Nothing is uploaded.</div>
<?php else:
    $featured = is_array($prepared['featured'] ?? null) ? $prepared['featured'] : [];
    $gallery  = is_array($prepared['gallery']  ?? null) ? $prepared['gallery']  : [];
    $total    = count($featured) + count($gallery);
?>
    <div class="meta">SKU: <strong><?= esc_html($sku) ?></strong> &mdash; <?= (int)$total ?> image(s) decoded from feed</div>
    <?php if ($total === 0): ?>
        <div class="notice">No images found in feed for SKU <?= esc_html($sku) ?>. Check the SKU is correct and that the feed endpoint returned data (see <code>vehicle-importer.log</code>).</div>
    <?php else: ?>
        <?php if (!empty($featured)): ?>
            <h2>Featured</h2>
            <div class="grid">
            <?php foreach ($featured as $item):
                $info = vp_guess_image_ext_from_bytes($item['bytes']);
                $src  = 'data:' . $info['mime'] . ';base64,' . base64_encode($item['bytes']);
            ?>
                <div class="card featured">
                    <img src="<?= esc_attr($src) ?>" alt="<?= esc_attr($item['label']) ?>">
                    <div class="lbl"><?= esc_html($item['label']) ?><span class="badge">FEATURED</span></div>
                    <div class="key"><?= esc_html($item['key']) ?></div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($gallery)): ?>
            <h2>Gallery</h2>
            <div class="grid">
            <?php foreach ($gallery as $item):
                $info = vp_guess_image_ext_from_bytes($item['bytes']);
                $src  = 'data:' . $info['mime'] . ';base64,' . base64_encode($item['bytes']);
            ?>
                <div class="card">
                    <img src="<?= esc_attr($src) ?>" alt="<?= esc_attr($item['label']) ?>">
                    <div class="lbl"><?= esc_html($item['label']) ?></div>
                    <div class="key"><?= esc_html($item['key']) ?></div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</body></html>
<?php
    exit;
}, 20);

// === Incremental image sync cron ============================================
// Processes a small batch per tick using a stored offset so large catalogues
// are covered over many runs without any single run timing out.
//
// Defaults (override via wp-config.php constants):
//   VI_IMAGE_SYNC_BATCH_SIZE  - products per cron tick  (default: 20)
//   VI_IMAGE_SYNC_MAX_SECONDS - time cap per tick in s  (default: 50)

add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['vi_five_minutes'])) {
        $schedules['vi_five_minutes'] = [
            'interval' => 300,
            'display'  => 'Every 5 Minutes (Vehicle Image Sync)',
        ];
    }
    return $schedules;
});

add_action('vi_image_sync_cron', function () {
    $batch_size  = defined('VI_IMAGE_SYNC_BATCH_SIZE')  ? (int)VI_IMAGE_SYNC_BATCH_SIZE  : 20;
    $max_seconds = defined('VI_IMAGE_SYNC_MAX_SECONDS') ? (int)VI_IMAGE_SYNC_MAX_SECONDS : 50;
    $offset_key  = 'vi_image_sync_offset';
    $offset      = (int) get_option($offset_key, 0);

    log_image_repair("Image Sync Cron: tick start (offset={$offset}, batch={$batch_size}, max_seconds={$max_seconds})");

    $result = vi_bulk_sync_vehicle_images($batch_size, $offset, null, false, $max_seconds);

    $advanced = $result['processed'];
    if ($advanced < 1) {
        // Nothing left to process — reset for next cycle
        update_option($offset_key, 0, false);
        log_image_repair("Image Sync Cron: nothing processed — offset reset to 0 (next full cycle)");
        return;
    }

    $new_offset = $offset + $advanced;

    // If we got fewer products than the batch size we've reached the end of the catalogue
    if ($result['scanned'] < $batch_size) {
        update_option($offset_key, 0, false);
        log_image_repair("Image Sync Cron: end of catalogue reached — offset reset to 0 (cycle complete)");
    } else {
        update_option($offset_key, $new_offset, false);
        log_image_repair("Image Sync Cron: tick done — next offset={$new_offset}");
    }
});

// Ensure the cron event is scheduled
add_action('init', function () {
    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) return;
    if (!wp_next_scheduled('vi_image_sync_cron')) {
        wp_schedule_event(time() + 60, 'vi_five_minutes', 'vi_image_sync_cron');
        log_image_repair('Scheduled vi_image_sync_cron (every 5 minutes).');
    }
});

add_action('init', function() {

    if (!current_user_can('administrator')) return;
    if (!isset($_GET['wbw_cleanup_ghosts'])) return;
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'sa_motorlease_admin_action')) {
        wp_die('Security check failed. Use the <a href="' . esc_url(admin_url('admin.php?page=sa-motorlease-status')) . '">SA Motorlease Status</a> page.', 'Forbidden', ['response' => 403]);
    }

    global $wpdb;

    $index_table = $wpdb->prefix . 'wbw_index_products';

    // Delete index rows for products that no longer exist
    $wpdb->query("
        DELETE FROM $index_table
        WHERE product_id NOT IN (
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'product'
            AND post_status IN ('publish','private')
        )
    ");

    echo "WBW ghost products cleaned ✅";
    exit;
});

// ============================================================================
// WBW Product Filter - Custom Index Trigger (URL + Cron)
// ============================================================================

// --- Logger ---------------------------------------------------------------
if ( ! function_exists( 'wbw_custom_index_log' ) ) {
    function wbw_custom_index_log( $msg ) {
        $file = plugin_dir_path( __FILE__ ) . 'wbw-custom-index.log';
        $line = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] [WBW custom index] ' . $msg . PHP_EOL;
        @file_put_contents( $file, $line, FILE_APPEND );
        // Always mirror to PHP error log so it's visible even if the file can't be written.
        error_log( '[WBW custom index] ' . $msg );
    }
}

// --- Core runner: call WBW meta controller indexer ------------------------
if ( ! function_exists( 'wbw_run_custom_indexing' ) ) {
    function wbw_run_custom_indexing() {

        wbw_custom_index_log( '--- START custom index run ---' );

        // Give it some breathing room
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        // Ensure Woo Product Filter framework is loaded
        if ( ! class_exists( 'FrameWpf' ) || ! method_exists( 'FrameWpf', '_' ) ) {
            wbw_custom_index_log( 'FrameWpf not loaded - Woo Product Filter inactive?' );
            wbw_custom_index_log( '--- END custom index run (no FrameWpf) ---' );
            return;
        }

        $frame = FrameWpf::_();
        if ( ! $frame || ! method_exists( $frame, 'getModule' ) ) {
            wbw_custom_index_log( 'Frame invalid or getModule() missing' );
            wbw_custom_index_log( '--- END custom index run (no module access) ---' );
            return;
        }

        // Get the meta module (MetaWpf)
        $meta_module = $frame->getModule( 'meta' );
        if ( ! $meta_module || ! is_object( $meta_module ) ) {
            wbw_custom_index_log( 'Meta module not found' );
            wbw_custom_index_log( '--- END custom index run (no meta module) ---' );
            return;
        }

        wbw_custom_index_log( 'Meta module: ' . get_class( $meta_module ) );

        $did_anything = false;

        // Use the meta controller's doMetaIndexingFree(), called via wbw_safe_call()
        // so that wp_die() / exit() inside the AJAX handler doesn't kill our page,
        // and loop through all pages so every product gets indexed (not just page 1).
        if ( method_exists( $meta_module, 'getController' ) ) {
            $ctrl = $meta_module->getController();

            if ( $ctrl && is_object( $ctrl ) ) {
                wbw_custom_index_log( 'Meta controller: ' . get_class( $ctrl ) );

                $method = null;
                if ( method_exists( $ctrl, 'doMetaIndexingFree' ) ) {
                    $method = 'doMetaIndexingFree';
                } elseif ( method_exists( $ctrl, 'doMetaIndexing' ) ) {
                    $method = 'doMetaIndexing';
                }

                if ( $method ) {
                    $cu = wp_get_current_user();
                    wbw_custom_index_log( "Calling controller->{$method}() as user ID={$cu->ID} ({$cu->user_login})" );

                    // wp_send_json() branches on wp_doing_ajax(): if true it calls
                    // wp_die() which wbw_safe_call() intercepts; if false it calls
                    // PHP's native die and kills the script. Define DOING_AJAX so
                    // the capture works even outside a real admin-ajax.php request.
                    if ( ! defined( 'DOING_AJAX' ) ) {
                        define( 'DOING_AJAX', true );
                    }

                    // Force a full rebuild and page through all products.
                    $_REQUEST['force']    = 1;
                    $_REQUEST['per_page'] = 50;

                    // doMetaIndexing(false) checks current_user_can('manage_options')
                    // and calls wp_die() with no message on failure, which wbw_safe_call()
                    // captures as an empty string. Grant the capability for the duration
                    // of the call — the outer callers already enforce admin auth.
                    $cap_filter = static function( $allcaps ) {
                        $allcaps['manage_options'] = true;
                        return $allcaps;
                    };

                    $page        = 1;
                    $max_pages   = 200;
                    $done_total  = 0;
                    $grand_total = 0;

                    while ( $page <= $max_pages ) {
                        $_REQUEST['page'] = $page;
                        try {
                            add_filter( 'user_has_cap', $cap_filter, PHP_INT_MAX );
                            $output = wbw_safe_call( static function() use ( $ctrl, $method ) {
                                $ctrl->$method();
                            } );
                        } catch ( Throwable $e ) {
                            remove_filter( 'user_has_cap', $cap_filter, PHP_INT_MAX );
                            wbw_custom_index_log( "Error on page {$page}: " . $e->getMessage() );
                            break;
                        }
                        remove_filter( 'user_has_cap', $cap_filter, PHP_INT_MAX );

                        wbw_custom_index_log( "Page {$page} → " . substr( $output, 0, 200 ) );

                        $resp  = json_decode( $output, true );
                        $data  = $resp['data'] ?? $resp ?? [];
                        $done  = (int) ( $data['done']  ?? $data['indexed'] ?? 0 );
                        $total = (int) ( $data['total'] ?? $data['all']     ?? 0 );

                        if ( $grand_total === 0 && $total > 0 ) {
                            $grand_total = $total;
                        }
                        $done_total += $done;

                        if ( $total > 0 && $done_total >= $total ) {
                            break;
                        }
                        if ( $total === 0 || $done === 0 ) {
                            break; // single-shot or unparseable — stop here
                        }
                        $page++;
                    }

                    wbw_custom_index_log( "Loop done: done={$done_total} total={$grand_total} pages={$page}" );
                    $did_anything = true;
                } else {
                    wbw_custom_index_log( 'No doMetaIndexing* method on meta controller' );
                }
            } else {
                wbw_custom_index_log( 'getController() returned nothing usable' );
            }
        } else {
            wbw_custom_index_log( 'meta module has no getController() method' );
        }

        if ( ! $did_anything ) {
            wbw_custom_index_log( 'WARNING: No controller indexing method actually ran.' );
        }

        wbw_custom_index_log( '--- END custom index run ---' );
    }
}

// --- URL trigger: ?wbw_test_index=1 ---------------------------------------
add_action( 'init', function() {

    if ( ! isset( $_GET['wbw_test_index'] ) ) {
        return;
    }

    // Only admins allowed
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        status_header( 403 );
        exit( 'Forbidden' );
    }

    wbw_custom_index_log( 'URL trigger activated (?wbw_test_index=1)' );

    wbw_run_custom_indexing();

    header( 'Content-Type: text/plain; charset=utf-8' );
    echo "WBW indexing trigger executed.\n";
    echo "Check log file:\n";
    echo plugin_dir_path( __FILE__ ) . "wbw-custom-index.log\n";
    exit;

}, 50 );

// --- URL trigger: ?wbw_test_cron_reindex=1 ---------------------------------
// Simulates the exact cron code path: clears the current user then
// re-impersonates admin, identical to what wbw_custom_index_cron does.
// Use this to verify the cron fix works before waiting for a real cron run.
add_action( 'init', function() {

    if ( ! isset( $_GET['wbw_test_cron_reindex'] ) ) {
        return;
    }

    // Only admins allowed (verified before we drop the session below)
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        status_header( 403 );
        exit( 'Forbidden' );
    }

    wbw_custom_index_log( 'URL trigger activated (?wbw_test_cron_reindex=1) — simulating cron context' );

    // Drop to no-user context, exactly as cron runs.
    wp_set_current_user( 0 );

    // Now run the same code the cron handler uses.
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
    if ( ! empty( $admins ) ) {
        wp_set_current_user( $admins[0] );
        wbw_custom_index_log( 'Re-impersonated admin ID ' . $admins[0] . ' (cron simulation)' );
    } else {
        wbw_custom_index_log( 'WARNING: no admin user found for impersonation' );
    }

    wbw_run_custom_indexing();

    header( 'Content-Type: text/plain; charset=utf-8' );
    echo "WBW cron-path reindex executed.\n";
    echo "Check log file:\n";
    echo plugin_dir_path( __FILE__ ) . "wbw-custom-index.log\n";
    exit;

}, 50 );

// --- WP-Cron reindex event --------------------------------------------------
// wbw_index_after_import_smart() uses wbw_safe_call() which intercepts
// wp_die() and loops through all product pages safely, so it works in cron
// context as long as WBW is an active plugin (plugins are loaded in cron).
// Falls back to admin-ajax automatically when FrameWpf isn't available.
add_action( 'wbw_custom_index_cron', function() {
    wbw_custom_index_log( 'Cron: wbw_custom_index_cron fired' );
    delete_option( 'vi_wbw_reindex_needed' );
    // WBW checks current_user_can('manage_options'); impersonate an admin in cron context.
    if ( ! is_user_logged_in() ) {
        $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
        if ( ! empty( $admins ) ) {
            wp_set_current_user( $admins[0] );
            wbw_custom_index_log( 'Set current user to admin ID ' . $admins[0] . ' for indexing.' );
        }
    }
    // Call wbw_run_custom_indexing() directly rather than wbw_index_after_import_smart()
    // so the 5-minute lock set by the end-of-import attempt doesn't block this fallback.
    wbw_run_custom_indexing();
} );

// --- Deferred reindex on admin_init ----------------------------------------
// Fallback for sites with WP-Cron disabled. The import sets
// 'vi_wbw_reindex_needed'; we consume it on the next admin page load.
add_action( 'admin_init', function() {
    // Skip AJAX requests — doMetaIndexingFree() calls exit(), which would
    // kill an unrelated AJAX call happening to fire admin_init first.
    if ( wp_doing_ajax() ) {
        return;
    }
    if ( ! get_option( 'vi_wbw_reindex_needed' ) ) {
        return;
    }
    delete_option( 'vi_wbw_reindex_needed' );
    wbw_custom_index_log( 'admin_init deferred reindex triggered' );
    wbw_run_custom_indexing();
} );

// ============================================================
// ONE-TIME MIGRATION: backfill Rebate Target on existing products
// Runs once on init; guarded by a WP option flag.
// ============================================================

function vi_migrate_rebate_target() {
    if ( get_option( 'vi_rebate_target_migration_done' ) ) {
        return;
    }
    // Rate-limit retries so a broken feed doesn't slow every page load.
    if ( get_transient( 'vi_rebate_migration_retry_lock' ) ) {
        return;
    }

    log_import_update( '=== Rebate Target migration START ===' );

    $feed_url = 'https://paceapp-server.azurewebsites.net/api/entity/paceWebPrepData';
    $response = wp_remote_get( $feed_url, [ 'timeout' => 45, 'headers' => [ 'Accept' => 'application/json' ] ] );

    if ( is_wp_error( $response ) ) {
        log_import_update( 'Rebate Target migration: feed request failed: ' . $response->get_error_message() );
        set_transient( 'vi_rebate_migration_retry_lock', 1, HOUR_IN_SECONDS );
        return;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        log_import_update( "Rebate Target migration: feed HTTP {$code}; aborting." );
        set_transient( 'vi_rebate_migration_retry_lock', 1, HOUR_IN_SECONDS );
        return;
    }

    $items = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $items ) ) {
        log_import_update( 'Rebate Target migration: feed JSON parse failed.' );
        set_transient( 'vi_rebate_migration_retry_lock', 1, HOUR_IN_SECONDS );
        return;
    }

    // Build SKU -> rebate_target map
    $rebate_map = [];
    foreach ( $items as $row ) {
        $sku = isset( $row['id'] ) ? (string) $row['id'] : '';
        if ( $sku === '' ) continue;
        $rebate_map[ $sku ] = isset( $row['rebate_target'] ) ? trim( (string) $row['rebate_target'] ) : '';
    }

    log_import_update( 'Rebate Target migration: feed items=' . count( $items ) . ', SKUs with data=' . count( $rebate_map ) );

    // Query all published vehicle products
    $product_ids = get_posts( [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    $total    = count( $product_ids );
    $applied  = 0;
    $skipped  = 0;

    register_attribute_not_exists( 'Rebate Target' );

    foreach ( $product_ids as $product_id ) {
        $sku = get_post_meta( $product_id, '_sku', true );
        if ( empty( $sku ) || ! isset( $rebate_map[ $sku ] ) ) {
            $skipped++;
            continue;
        }

        $rebate_value = $rebate_map[ $sku ];
        if ( $rebate_value === '' ) {
            $skipped++;
            continue;
        }

        add_attribute_to_vehicle_data( $product_id, 'Rebate Target', $rebate_value );
        $applied++;
    }

    log_import_update( "Rebate Target migration: total={$total}, applied={$applied}, skipped={$skipped}" );
    log_import_update( '=== Rebate Target migration END ===' );

    update_option( 'vi_rebate_target_migration_done', '1', false );
}

add_action( 'init', 'vi_migrate_rebate_target', 20 );


// ============================================================================
// === MERGED FROM sa-motorlease-product-importer.php =========================
// === Lead qualification, GF#5 forwarder, frontend enqueues, REST endpoints,
// === sold/duplicate/missing cleanup, attribute backfills, CSV export, image
// === repair fallback. The old import pipeline (import_vehicle_feed_as_products,
// === process_single_vehicle_data, update_product_if_newer, upload_image_from_url,
// === the location backfill, add_location_category, add_one_time_cron_schedule,
// === my_custom_shortcode) was dropped — replaced by the vi_* import pipeline.
// ============================================================================

// ---------------------------------------------------------------------------
// --- Lead qualifications DB table
// ---------------------------------------------------------------------------
/** START Database Tables */

register_activation_hook(__FILE__, 'sa_motorlease_product_importer_create_lead_table');

// ---------------------------------------------------------------------------
// --- Lead qualifications table creator
// ---------------------------------------------------------------------------
function sa_motorlease_product_importer_create_lead_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'lead_qualifications'; // Adjust table name as needed
    $charset_collate = $wpdb->get_charset_collate();

    // Check if the table already exists
    $table_exists = $wpdb->get_var( $wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $table_name
    ));

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    // dbDelta cannot add the lead_id unique key while duplicate lead_id rows
    // exist (test/sandbox PACE environments return the same lead_id for every
    // submission, so copied-over staging data accumulates duplicates and the
    // ADD UNIQUE KEY fails on every activation). When the key is still
    // missing, collapse duplicates first, keeping the newest row per lead_id
    // to match the upsert semantics used on insert.
    if ( $table_exists ) {
        $has_uniq = $wpdb->get_var( $wpdb->prepare(
            "SHOW INDEX FROM `$table_name` WHERE Key_name = %s",
            'lead_id_uniq'
        ));
        if ( ! $has_uniq ) {
            $removed = $wpdb->query(
                "DELETE older FROM `$table_name` older
                 INNER JOIN `$table_name` newer
                    ON newer.lead_id = older.lead_id AND newer.id > older.id"
            );
            if ( $removed ) {
                sa_motorlease_log_lead( 'activate', SA_MOTORLEASE_LOG_WARN,
                    sprintf( 'Removed %d duplicate lead_id row(s) from %s before adding lead_id_uniq.',
                        (int) $removed, $table_name ) );
            }
        }
    }

    // Always run dbDelta so it can add missing columns/keys to existing tables.
    // dbDelta is strict about layout: the primary key must be its own line in
    // the exact form "PRIMARY KEY  (id)" (two spaces) — declared inline on the
    // column it re-issues a failing ALTER on every activation. Types must also
    // match what MySQL reports back (BOOLEAN is stored as tinyint(1)).
    $sql = "CREATE TABLE $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        lead_id bigint(20) NOT NULL,
        state varchar(50) NOT NULL,
        qualify_salary tinyint(1) NOT NULL,
        qualify_license tinyint(1) NOT NULL,
        qualify_area tinyint(1) NOT NULL,
        rental_limit decimal(10,2) NOT NULL,
        fast_track tinyint(1) NOT NULL,
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY lead_id_uniq (lead_id)
    ) $charset_collate;";

    dbDelta( $sql );
}

// ---------------------------------------------------------------------------
// --- ensure_media_libs_loaded helper
// ---------------------------------------------------------------------------
// Put this near the top of the plugin (after ABSPATH is available).
if ( ! function_exists('ensure_media_libs_loaded') ) {
    function ensure_media_libs_loaded() {
        // file.php has wp_handle_sideload(), etc.
        if ( ! function_exists('wp_handle_sideload') ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        // media.php has media_handle_sideload(), media_sideload_image(), etc.
        if ( ! function_exists('media_handle_sideload') ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        // image.php has wp_generate_attachment_metadata(), WP_Image_Editor, etc.
        if ( ! function_exists('wp_generate_attachment_metadata') ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
    }
}

// ---------------------------------------------------------------------------
// --- Legacy loggers (log_to_file, log_update)
// ---------------------------------------------------------------------------
function log_to_file($message, $filename = 'import_log.log') {
    // Channel name comes from the legacy filename so existing greps stay useful.
    $channel = preg_replace('/\.log$/', '', $filename) ?: 'import';
    sa_motorlease_log($channel, sa_motorlease_log_classify($message), $message);
}

function log_update($message) {
    sa_motorlease_log('product-updates', sa_motorlease_log_classify($message), $message);
}

// ---------------------------------------------------------------------------
// --- format_currency_value helper
// ---------------------------------------------------------------------------
function format_currency_value($value) {
    // Remove any non-numeric characters (except '.')
    $cleaned_value = preg_replace('/[^0-9.]/', '', $value);

    // Check if the cleaned value is numeric
    if (is_numeric($cleaned_value)) {
        // Format the value as currency
        $formatted_value = 'R' . number_format((float) $cleaned_value, 0, '.', ',');

        // Replace the comma with an HTML entity to avoid splitting
        return str_replace(',', '&#44;', $formatted_value); // Encoded comma
    }

    // Return the original value if not numeric
    return $value;
}

// ---------------------------------------------------------------------------
// --- add_attribute_to_product_data + register_attribute_if_not_exists
// ---------------------------------------------------------------------------
function add_attribute_to_product_data($product_id, $attribute_name, $attribute_value) {
    if (!get_post($product_id)) {
        return;
    }

    // Sanitize the attribute name and create the taxonomy slug
    $attribute_slug = wc_sanitize_taxonomy_name($attribute_name);
    $taxonomy = 'pa_' . $attribute_slug;

    // Register the taxonomy if it doesn't exist
    if (!taxonomy_exists($taxonomy)) {
        register_taxonomy(
            $taxonomy,
            'product',
            [
                'hierarchical' => false,
                'label'        => ucfirst($attribute_name),
                'query_var'    => true,
                'rewrite'      => ['slug' => sanitize_title($attribute_name)],
            ]
        );
    }

    // Ensure the value is treated as a single term
    if (!is_array($attribute_value)) {
        $attribute_value = [$attribute_value]; // Convert to array if it's a single value
    }

    // Add terms to the taxonomy
    foreach ($attribute_value as $value) {
        $value = sanitize_text_field($value);
        if (!term_exists($value, $taxonomy)) {
            wp_insert_term($value, $taxonomy);
        }
    }

    // Assign the terms to the product
    wp_set_object_terms($product_id, $attribute_value, $taxonomy);

    // Update the product attributes meta
    $product_attributes = get_post_meta($product_id, '_product_attributes', true) ?: [];
    $product_attributes[$taxonomy] = [
        'name'         => $taxonomy,
        'value'        => '', // WooCommerce will use the assigned terms
        'is_visible'   => 1,
        'is_variation' => 0,
        'is_taxonomy'  => 1,
    ];

    update_post_meta($product_id, '_product_attributes', $product_attributes);
}

function register_attribute_if_not_exists($attribute_name) {
    global $wpdb;

    $attribute_slug = wc_sanitize_taxonomy_name($attribute_name);
    $taxonomy = 'pa_' . $attribute_slug;

    // Check if the attribute already exists
    $exists = $wpdb->get_var($wpdb->prepare("
        SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies 
        WHERE attribute_name = %s", $attribute_slug));

    if (!$exists) {
        // Add the attribute to WooCommerce
        $wpdb->insert("{$wpdb->prefix}woocommerce_attribute_taxonomies", [
            'attribute_name'    => $attribute_slug,
            'attribute_label'   => $attribute_name,
            'attribute_type'    => 'select',
            'attribute_orderby' => 'menu_order',
            'attribute_public'  => 0
        ]);

        // Flush WooCommerce cache
        delete_transient('wc_attribute_taxonomies');

        log_update("Registered new attribute: $attribute_name ($taxonomy)");
    }
}

// ---------------------------------------------------------------------------
// --- Sold-date cron & expired-sold-product cleanup
// ---------------------------------------------------------------------------
function schedule_sold_date_cron() {
    if (!wp_next_scheduled('set_sold_date_cron_event')) {
        wp_schedule_event(time(), 'daily', 'set_sold_date_cron_event');
    }
}
add_action('init', 'schedule_sold_date_cron');
add_action('set_sold_date_cron_event', 'set_sold_date_for_sold_products');

function set_sold_date_for_sold_products() {
    $args = [
        'limit' => -1,
    ];

    $products = wc_get_products($args);

    foreach ($products as $wc_product) {
        if (!$wc_product instanceof WC_Product) continue;

        $product_id = $wc_product->get_id();
        $sold_value = $wc_product->get_attribute('pa_sold');

        if (strtolower($sold_value) !== 'yes') continue;

        // Get raw attribute terms (taxonomy-based)
        $existing_terms = wp_get_post_terms($product_id, 'pa_sold-date', ['fields' => 'names']);

        if (!empty($existing_terms)) {
            // Already has a sold date
            continue;
        }

        $sold_date_value = current_time('Y-m-d');

        register_attribute_if_not_exists('Sold Date');
        add_attribute_to_product_data($product_id, 'Sold Date', [$sold_date_value]);

        log_to_file("✅ Set Sold Date for product ID: $product_id", 'sold-vehicles.log');
    }
}

// Schedule cron job to delete expired sold products
function schedule_sold_product_cleanup() {
    if (!wp_next_scheduled('delete_expired_sold_products_event')) {
        wp_schedule_event(time(), 'daily', 'delete_expired_sold_products_event');
    }
}
add_action('init', 'schedule_sold_product_cleanup');
add_action('delete_expired_sold_products_event', 'delete_expired_sold_products');

function delete_expired_sold_products() {
    $seven_days_ago = strtotime('-7 days');

    // Fetch all products
    $args = [
        'limit'  => -1, // Get all products
    ];

    $products = wc_get_products($args);

    foreach ($products as $wc_product) {
        if (!$wc_product instanceof WC_Product) continue;

        // Use wp_get_post_terms so we get individual term names, not a
        // comma-joined string (which breaks strtotime on multi-term values).
        $pid        = $wc_product->get_id();
        $date_terms = wp_get_post_terms( $pid, 'pa_sold-date', [ 'fields' => 'names' ] );
        $sold_date  = ( is_array( $date_terms ) && ! empty( $date_terms ) ) ? $date_terms[0] : '';

        $dt = $sold_date ? DateTime::createFromFormat( 'Y-m-d', $sold_date ) : false;
        if ( $dt && $dt->getTimestamp() < $seven_days_ago ) {
            wp_delete_post( $pid, true );
            log_to_file( "Deleted product ID: {$pid} marked as sold for more than 7 days.", 'sold-vehicles.log' );
        }
    }
}

// ---------------------------------------------------------------------------
// --- /sold-products REST endpoint
// ---------------------------------------------------------------------------
add_action('rest_api_init', function () {
    register_rest_route('custom-api/v1', '/sold-products', [
        'methods'  => 'GET',
        'callback' => 'get_sold_products',
        'permission_callback' => '__return_true' // Make it public (secure if needed)
    ]);
});

function get_sold_products() {
    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'tax_query'      => [
            [
                'taxonomy' => 'pa_sold', // WooCommerce attribute taxonomy is prefixed with "pa_"
                'field'    => 'name',
                'terms'    => 'Yes'
            ]
        ],
        'fields' => 'ids' // We only need the IDs initially
    ];

    $product_ids = get_posts($args);
    $results = [];

    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);
        if ($product) {
            $results[] = [
                'id'   => $product->get_id(),
                'name' => $product->get_name()
            ];
        }
    }

    return rest_ensure_response($results);
}

// ---------------------------------------------------------------------------
// --- License plate updater
// ---------------------------------------------------------------------------
function log_license_plate_update($message) {
    sa_motorlease_log('license-plate', sa_motorlease_log_classify($message), $message);
}

// Update existing products with license plate attribute from live feed
function update_all_existing_products_with_license_plate_from_feed() {
    $domain = 'https://paceapp-server.azurewebsites.net';
    $api_endpoint = $domain . '/api/entity/paceWebPrepData';
    $json_data = file_get_contents($api_endpoint);

    if ($json_data === false) {
        error_log('Failed to fetch data from API endpoint.');
        log_license_plate_update('Failed to fetch data from API endpoint.');
        return;
    }

    $feed_data = json_decode($json_data, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('Invalid JSON format from API endpoint.');
        log_license_plate_update('Invalid JSON format from API endpoint.');
        return;
    }

    $count = 0;

    foreach ($feed_data as $item) {
        if (empty($item['id']) || empty($item['license_plate'])) {
            continue;
        }

        $product_id = wc_get_product_id_by_sku($item['id']);
        if (!$product_id) {
            log_license_plate_update("Product with SKU {$item['id']} not found.");
            continue;
        }

        $license_plate = sanitize_text_field($item['license_plate']);

        add_attribute_to_product_data($product_id, 'Registration', $license_plate);
        log_license_plate_update("Updated product ID $product_id with Registration: $license_plate");
        $count++;
    }

    log_license_plate_update("Total products updated: $count");
}

// ---------------------------------------------------------------------------
// --- Duplicate-SKU remover
// ---------------------------------------------------------------------------
function remove_duplicate_products_by_sku() {
    $products = wc_get_products([
        'status' => 'publish',
        'limit' => -1,
        'return' => 'ids'
    ]);

    $sku_map = [];
    $deleted = 0;

    foreach ($products as $product_id) {
        $sku = get_post_meta($product_id, '_sku', true);
        if (empty($sku)) continue;

        if (!isset($sku_map[$sku])) {
            $sku_map[$sku] = $product_id;
        } else {
            // Remove images
            $attachment_ids = get_posts([
                'post_type'   => 'attachment',
                'posts_per_page' => -1,
                'post_parent' => $product_id,
                'fields'      => 'ids'
            ]);

            foreach ($attachment_ids as $attachment_id) {
                wp_delete_attachment($attachment_id, true);
            }

            // Trash the duplicate product
            wp_trash_post($product_id);
            log_license_plate_update("Deleted duplicate product ID $product_id with SKU $sku");
            $deleted++;
        }
    }

    log_license_plate_update("Total duplicate products removed: $deleted");
}

// ---------------------------------------------------------------------------
// --- Product attributes CSV exporter
// ---------------------------------------------------------------------------
function export_product_attributes_csv() {
    $filename = plugin_dir_path(__FILE__) . 'product-attributes-export.csv';
    $file = fopen($filename, 'w');

    $exclude_labels = ['Payments', 'Price']; // labels to exclude
    $products = wc_get_products(['status' => 'publish', 'limit' => -1]);
    $attribute_names = [];

    foreach ($products as $product) {
        $attributes = $product->get_attributes();
        foreach ($attributes as $attr) {
            $label = wc_attribute_label($attr->get_name());
            if (!in_array($label, $exclude_labels)) {
                $attribute_names[$label] = true;
            }
        }
    }

    $attribute_names = array_keys($attribute_names);
    sort($attribute_names);

    $header = array_merge(['sku', 'price'], $attribute_names);
    fputcsv($file, $header, ';');

    foreach ($products as $product) {
        $sku = $product->get_sku();
        $price = $product->get_price();
        $attributes = $product->get_attributes();
        $row = array_fill_keys($attribute_names, '');
        $row = array_merge(['sku' => $sku, 'price' => $price], $row);

        foreach ($attributes as $attr) {
            $label = wc_attribute_label($attr->get_name());
            $slug = $attr->get_name();

            if (in_array($label, $exclude_labels)) {
                continue; // skip excluded ones
            }

            $options = $attr->get_options();

            if ($attr->is_taxonomy()) {
                $terms = array_map(function ($val) use ($attr) {
                    if (is_numeric($val)) {
                        $term = get_term(intval($val), $attr->get_name());
                    } else {
                        $term = get_term_by('slug', $val, $attr->get_name());
                    }
                    if (!$term) return $val;
                    return html_entity_decode($term->name, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }, $options);
            } else {
                $terms = array_map(function ($val) {
                    $val = html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    return sanitize_text_field($val);
                }, $options);
            }

            $row[$label] = implode(',', $terms);
        }

        fputcsv($file, $row, ';');
    }

    fclose($file);
    log_license_plate_update("Exported product attributes to CSV.");
}  

// ---------------------------------------------------------------------------
// --- Admin router: update_license_plates / deduplicate_products / export_attributes
// ---------------------------------------------------------------------------
add_action('admin_init', function () {

    if (isset($_GET['update_license_plates']) && current_user_can('manage_woocommerce')) {
        check_admin_referer('sa_motorlease_admin_action');
        update_all_existing_products_with_license_plate_from_feed();
        wp_die('License plates updated from feed. Check log file for details.');
    }

    if (isset($_GET['deduplicate_products']) && current_user_can('manage_woocommerce')) {
        check_admin_referer('sa_motorlease_admin_action');
        remove_duplicate_products_by_sku();
        wp_die('Duplicate products removed. Check log file for details.');
    }

    if (isset($_GET['export_attributes']) && current_user_can('manage_woocommerce')) {
        check_admin_referer('sa_motorlease_admin_action');
        export_product_attributes_csv();
        $filename = plugin_dir_path(__FILE__) . 'product-attributes-export.csv';
        $download_name = 'product-attributes-export.csv';
    
        if (file_exists($filename)) {
            header('Content-Description: File Transfer');
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $download_name . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filename));
            readfile($filename);
            exit;
        } else {
            wp_die('CSV export failed: file not found.');
        }
    }
});

// ---------------------------------------------------------------------------
// --- Frontend JS enqueues (form, qualification, application)
// ---------------------------------------------------------------------------
add_action('wp_enqueue_scripts', 'samotorlease_enqueue_form_script');
function samotorlease_enqueue_form_script() {
    if (is_page('see-if-you-qualify') || is_front_page()) {
        // Only enqueue on pages where Gravity Form is shown, or enqueue conditionally if needed
        wp_enqueue_script(
            'lead-qualification',
            plugin_dir_url(__FILE__) . 'assets/js/lead-qualification.js',
            ['jquery'],                // Dependencies
            '1.2.5',                     // Version — bumped: tighten ID/Passport to 9-13 alphanumeric
            true                      // Load in footer
        );
    }
}

add_action('wp_enqueue_scripts', 'samotorlease_enqueue_qualification_script');
function samotorlease_enqueue_qualification_script() {
    if (is_page('see-if-you-qualify-result')) {
        // Only enqueue on pages where Gravity Form is shown, or enqueue conditionally if needed
        wp_enqueue_script(
            'qualification-results',
            plugin_dir_url(__FILE__) . 'assets/js/qualification-results.js',
            ['jquery'],                // Dependencies
            '1.1.0',                     // Version — bumped: drop cookie, use localStorage (fix HTTP 431)
            true                      // Load in footer
        );
    }
}

add_action('wp_enqueue_scripts', 'samotorlease_enqueue_application_script');
function samotorlease_enqueue_application_script() {
    if (is_page('apply-now')) {
        // Only enqueue on pages where Gravity Form is shown, or enqueue conditionally if needed

        
        // Select2 CSS + JS (use CDN or local paths)
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], null, true);

        wp_enqueue_script(
            'application-form',
            plugin_dir_url(__FILE__) . 'assets/js/application-form.js',
            ['jquery'],                // Dependencies
            '1.1.0',                     // Version — bumped: drop cookie, use localStorage (fix HTTP 431)
            true                      // Load in footer
        );


    }
}

// ---------------------------------------------------------------------------
// --- /qualify-lead REST endpoint
// ---------------------------------------------------------------------------
add_action('rest_api_init', function () {
    register_rest_route('samotorlease/v1', '/qualify-lead', [
        'methods' => 'POST',
        'callback' => 'samotorlease_qualify_lead',
        'permission_callback' => '__return_true',
    ]);
});

function samotorlease_qualify_lead( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'lead_qualifications';

    // Helper to normalize to boolean
    $to_bool = function( $v ) {
        return filter_var( $v, FILTER_VALIDATE_BOOLEAN );
    };

    // Raw inputs
    $location_raw       = $request->get_param('location');        // exact <option value> (e.g., "Other")
    $other_location_raw = $request->get_param('other_location');  // text when Other selected
    $e_hailing_raw      = $request->get_param('e_hailing');       // radio yes/no -> boolean

    // Normalize/sanitize
    $location     = sanitize_text_field( (string) $location_raw );
    $is_other     = is_string($location_raw) && strcasecmp(trim($location_raw), 'Other') === 0;
    $other_loc    = $is_other ? sanitize_text_field( (string) $other_location_raw ) : '';
    $e_hailing    = $to_bool( $e_hailing_raw );

    // Build payload for external API
    $data = [
        'name'             => sanitize_text_field( $request->get_param('name') ),
        'surname'          => sanitize_text_field( $request->get_param('surname') ),
        'id_number'        => sanitize_text_field( $request->get_param('id_number') ),
        'cellphone_number' => sanitize_text_field( $request->get_param('cellphone_number') ),
        'your_email'       => sanitize_email( $request->get_param('your_email') ),
        'location'         => $location,     // exact select value (e.g. "Other")
        'other_location'   => $other_loc,    // only filled when location === "Other"
        'e_hailing'        => $e_hailing,    // boolean
        'take_home'        => preg_replace('/[^\d.]/', '', (string) $request->get_param('take_home') ),
        'valid_license'    => $to_bool( $request->get_param('valid_license') ),
        'initiation_fee'   => $to_bool( $request->get_param('initiation_fee') ),
        'accept_terms'     => $to_bool( $request->get_param('accept_terms') ),
    ];

    // Server-side validation. Belt-and-braces: the JS validator should already
    // have blocked invalid submissions, but a direct POST (or stale cached JS)
    // must not reach the PACE API with missing/invalid required fields.
    $validation_error = sa_motorlease_validate_qualify_payload( $data );
    if ( $validation_error !== null ) {
        sa_motorlease_log_lead( 'qualify-lead', SA_MOTORLEASE_LOG_WARN,
            sprintf( 'Rejected before PACE: %s request=%s',
                $validation_error, wp_json_encode( $data ) ) );
        return new WP_REST_Response( [ 'error' => $validation_error ], 400 );
    }

    // Settings-driven kill switch — lets an admin pause posting to PACE
    // without disabling the whole plugin (useful while debugging).
    if ( ! sa_motorlease_pace_posting_enabled() ) {
        sa_motorlease_log_lead( 'qualify-lead', SA_MOTORLEASE_LOG_WARN,
            'PACE posting disabled via settings; rejected lead submission.' );
        return new WP_REST_Response( [
            'error' => 'Lead submissions are temporarily disabled. Please try again later or contact us.',
        ], 503 );
    }

    sa_motorlease_log_lead( 'qualify-lead', SA_MOTORLEASE_LOG_INFO,
        'POST paceWebCreateLead request=' . wp_json_encode( sa_motorlease_mask_pii( $data ) ) );

    // External API request
    $response = wp_remote_post(
        sa_motorlease_pace_url( '/api/entity/paceWebCreateLead' ),
        [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($data),
            'timeout' => 30,
        ]
    );

    if (is_wp_error($response)) {
        sa_motorlease_log_lead('qualify-lead', SA_MOTORLEASE_LOG_ERROR,
            sprintf('WP_Error from paceWebCreateLead: %s request=%s',
                $response->get_error_message(), sa_motorlease_log_truncate( wp_json_encode( $data ) )));
        return new WP_REST_Response(['error' => 'API connection failed'], 500);
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body    = wp_remote_retrieve_body($response);
    $body        = json_decode($raw_body, true);

    if ($status_code < 200 || $status_code >= 300) {
        sa_motorlease_log_lead('qualify-lead', SA_MOTORLEASE_LOG_WARN,
            sprintf('paceWebCreateLead HTTP %d request=%s response=%s',
                $status_code, sa_motorlease_log_truncate( wp_json_encode( $data ) ), substr($raw_body, 0, 1000)));
    }

    if (empty($body['lead_id'])) {
        $upstream_msg = isset($body['message']) ? $body['message'] : 'unknown';
        sa_motorlease_log_lead('qualify-lead', SA_MOTORLEASE_LOG_WARN,
            sprintf('paceWebCreateLead missing lead_id; upstream=%s http=%d request=%s response=%s',
                $upstream_msg, $status_code, sa_motorlease_log_truncate( wp_json_encode( $data ) ), substr($raw_body, 0, 1000)));
        return new WP_REST_Response([
            'error'    => 'Invalid API response (missing lead_id)',
            'upstream' => $upstream_msg,
        ], 400);
    }

    // Save response summary (extend if you add columns for e_hailing/other_location).
    // Upsert keyed on lead_id_uniq: test/sandbox PACE environments return the
    // same lead_id for every submission, so a plain insert would hit the
    // unique key and turn every repeat submission into a 500.
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO `$table`
            (lead_id, state, qualify_salary, qualify_license, qualify_area, rental_limit, fast_track)
         VALUES (%d, %s, %d, %d, %d, %f, %d)
         ON DUPLICATE KEY UPDATE
            state           = VALUES(state),
            qualify_salary  = VALUES(qualify_salary),
            qualify_license = VALUES(qualify_license),
            qualify_area    = VALUES(qualify_area),
            rental_limit    = VALUES(rental_limit),
            fast_track      = VALUES(fast_track)",
        (int) $body['lead_id'],
        isset($body['state']) ? sanitize_text_field($body['state']) : 'unknown',
        filter_var($body['qualify_salary'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        filter_var($body['qualify_license'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        filter_var($body['qualify_area'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        floatval($body['rental_limit'] ?? 0),
        filter_var($body['fast_track'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0
    ) );

    if ($wpdb->last_error) {
        sa_motorlease_log_lead('qualify-lead', SA_MOTORLEASE_LOG_ERROR,
            sprintf('lead_qualifications insert failed (lead_id=%d): %s',
                (int) $body['lead_id'], $wpdb->last_error));
        return new WP_REST_Response(['error' => $wpdb->last_error], 500);
    }

    sa_motorlease_log_lead('qualify-lead', SA_MOTORLEASE_LOG_INFO,
        sprintf('OK http=%d response=%s', $status_code, wp_json_encode([
            'lead_id'         => $body['lead_id'] ?? null,
            'state'           => $body['state'] ?? null,
            'qualify_salary'  => $body['qualify_salary'] ?? null,
            'qualify_license' => $body['qualify_license'] ?? null,
            'qualify_area'    => $body['qualify_area'] ?? null,
            'rental_limit'    => $body['rental_limit'] ?? null,
            'fast_track'      => $body['fast_track'] ?? null,
        ])));

    return new WP_REST_Response($body, 200);
}

// ---------------------------------------------------------------------------
// --- Gravity Form forwarder (default form ID 5; configurable in Settings)
// ---------------------------------------------------------------------------
add_action( 'init', function () {
    $form_id = (int) sa_motorlease_get_setting( 'gf_forwarder_form_id', 5 );
    if ( $form_id > 0 ) {
        add_action( 'gform_after_submission_' . $form_id, 'gf5_forward_to_external_api', 10, 2 );
    }
}, 5 );
function gf5_forward_to_external_api( $entry, $form ) {

    // Keep PHP alive even if the browser closes after form submit.
    @set_time_limit( 0 );
    ignore_user_abort( true );

    $lead_id   = intval( rgar( $entry, '42' ) );
    $proof_raw = rgar( $entry, '35' );
    $proof     = filter_var( $proof_raw, FILTER_VALIDATE_BOOLEAN ) ? 'true' : 'false';
    $external  = sa_motorlease_pace_url( '/api/entity/paceWebUpdateLead' );

    // Helper: POST a payload to paceWebUpdateLead, log the call, and log any error.
    // The bank-statement base64 blob is replaced with a placeholder in logs so
    // log lines stay small and customer document bytes are not persisted.
    $send = function ( array $data, string $context ) use ( $external, $lead_id ) {
        $loggable = $data;
        if ( isset( $loggable['bank_statements']['objData'] ) ) {
            $loggable['bank_statements'] = sprintf( '<%d file(s) omitted>',
                count( $loggable['bank_statements']['objData'] ) );
        }
        $masked_request = wp_json_encode( sa_motorlease_mask_pii( $loggable ) );

        sa_motorlease_log_lead( 'gf5-forward', SA_MOTORLEASE_LOG_INFO,
            sprintf( 'POST paceWebUpdateLead %s (lead_id=%d) request=%s',
                $context, $lead_id, $masked_request ) );

        $response = wp_remote_post( $external, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $data, JSON_UNESCAPED_SLASHES ),
            'timeout' => 120,
        ] );

        if ( is_wp_error( $response ) ) {
            sa_motorlease_log_lead( 'gf5-forward', SA_MOTORLEASE_LOG_ERROR,
                sprintf( 'WP_Error paceWebUpdateLead %s (lead_id=%d): %s request=%s',
                    $context, $lead_id, $response->get_error_message(),
                    sa_motorlease_log_truncate( wp_json_encode( $loggable ) ) ) );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 ) {
            sa_motorlease_log_lead( 'gf5-forward', SA_MOTORLEASE_LOG_WARN,
                sprintf( 'paceWebUpdateLead HTTP %d %s (lead_id=%d) request=%s response=%s',
                    $code, $context, $lead_id,
                    sa_motorlease_log_truncate( wp_json_encode( $loggable ) ),
                    substr( $body, 0, 1000 ) ) );
        } else {
            sa_motorlease_log_lead( 'gf5-forward', SA_MOTORLEASE_LOG_INFO,
                sprintf( 'OK %s lead_id=%d http=%d response=%s',
                    $context, $lead_id, $code, substr( $body, 0, 500 ) ) );
        }
    };

    // 1) Send the base lead data (no files).
    $send( [
        'lead_id'           => $lead_id,
        'home_address'      => [
            'street'   => rgar( $entry, '44' ),
            'suburb'   => rgar( $entry, '45' ),
            'city'     => rgar( $entry, '46' ),
            'province' => rgar( $entry, '47' ),
            'country'  => 'South Africa',
        ],
        'occupation'        => rgar( $entry, '18' ),
        'employment_status' => rgar( $entry, '20' ),
        'employer_name'     => rgar( $entry, '21' ),
        'work_email'        => rgar( $entry, '23' ),
        'work_phone'        => rgar( $entry, '24' ),
        'work_address'      => [
            'street'   => rgar( $entry, '48' ),
            'suburb'   => rgar( $entry, '49' ),
            'city'     => rgar( $entry, '50' ),
            'province' => rgar( $entry, '51' ),
            'country'  => 'South Africa',
        ],
        'proof_address'     => $proof,
        'required_by'       => rgar( $entry, '38' ),
        'bank_statements'   => [ 'objData' => [] ],
    ], 'base-data' );

    // 2) Send each bank statement file as a separate request to keep
    //    memory usage bounded — one file at a time rather than all at once.
    $upload  = wp_upload_dir();
    $baseurl = trailingslashit( $upload['baseurl'] );
    $basedir = trailingslashit( $upload['basedir'] );
    $max_bytes = 32 * 1024 * 1024; // 32 MB per file

    foreach ( [ 32, 33, 34 ] as $fid ) {
        $urls_string = rgar( $entry, (string) $fid );
        if ( ! $urls_string ) {
            continue;
        }
        $urls = array_filter( array_map( 'trim', explode( ',', $urls_string ) ) );
        foreach ( $urls as $url ) {
            $clean = strtok( $url, '?' );
            if ( strpos( $clean, $baseurl ) !== 0 ) {
                continue;
            }
            $rel  = substr( $clean, strlen( $baseurl ) );
            $full = $basedir . $rel;
            if ( ! file_exists( $full ) ) {
                continue;
            }
            if ( filesize( $full ) > $max_bytes ) {
                sa_motorlease_log( 'gf5-forward', SA_MOTORLEASE_LOG_WARN,
                    sprintf( 'Skipping oversized bank statement (lead_id=%d, file=%s, size=%d bytes)',
                        $lead_id, basename( $full ), filesize( $full ) ) );
                continue;
            }
            $mime = wp_check_filetype( $full )['type'] ?? 'application/octet-stream';
            $b64  = base64_encode( file_get_contents( $full ) );
            $send( [
                'lead_id'         => $lead_id,
                'bank_statements' => [ 'objData' => [ [ 'data' => "data:{$mime};base64,{$b64}" ] ] ],
            ], 'bank-statement-field-' . $fid );
            unset( $b64 ); // release memory before next file
        }
    }
}

// ---------------------------------------------------------------------------
// --- Vehicle-locations carousel script + Flickity
// ---------------------------------------------------------------------------
function sl_enqueue_vehicle_locations_script() {
    // 1. grab top-level (province) terms that have products
    $provinces = get_terms( [
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'parent'     => 0,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ] );

    $data = [];
    foreach ( $provinces as $prov ) {

        // **SKIP “Cars For Sale” entirely**
        if ( 'Cars For Sale' === $prov->name ) {
            continue;
        }

        // fetch only non-empty child terms (areas)
        $areas = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'parent'     => $prov->term_id,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );

        $childs = [];
        foreach ( $areas as $area ) {

            if ( 'Salt River' === $area->name ) {
                continue;
            }
            
            $childs[] = [
                'name' => $area->name,
                'url'  => get_term_link( $area ),
            ];
        }

        $data[] = [
            'name'   => $prov->name,
            'url'    => get_term_link( $prov ),
            'areas'  => $childs,
        ];
    }

    // Flickity CSS
    wp_enqueue_style( 'flickity-css',
      'https://unpkg.com/flickity@2/dist/flickity.min.css',
      [], null
    );
    // Flickity JS
    wp_enqueue_script( 'flickity-js',
      'https://unpkg.com/flickity@2/dist/flickity.pkgd.min.js',
      [], null, true
    );

    // enqueue your script (use plugin_dir_url for correct URL)
    wp_enqueue_script(
        'vehicle-locations',
        plugin_dir_url( __FILE__ ) . 'assets/js/vehicle-locations.js',
        [ 'jquery','flickity-js' ],
        '1.0.4',
        true
    );
    wp_localize_script( 'vehicle-locations', 'VehicleLocationsData', $data );
}
add_action( 'wp_enqueue_scripts', 'sl_enqueue_vehicle_locations_script' );

// ---------------------------------------------------------------------------
// --- WooCommerce no-products-found override
// ---------------------------------------------------------------------------
add_action( 'init', function() {
    remove_action( 'woocommerce_no_products_found', 'wc_no_products_found' );
});

// Add your custom message with button
add_action( 'woocommerce_no_products_found', function() {
    echo '<div class="woocommerce-info" style="text-align: center;">';
    echo '<p>Unfortunately we currently have no vehicles listed in this area. Please click below to speak to a consultant and find a vehicle near you.</p>';
    echo '<a href="/contact" class="button secondary box-shadow-5-hover">Get In Touch</a>';
    echo '</div>';
});

// ---------------------------------------------------------------------------
// --- qualified_results_vehicles shortcode
// ---------------------------------------------------------------------------
add_shortcode('qualified_results_vehicles','qm_qualified_results_vehicles_container');
function qm_qualified_results_vehicles_container($atts){
  return '<div id="qualifiedVehiclesCarousel"></div>';
}

// ---------------------------------------------------------------------------
// --- /qualified-vehicles REST endpoint
// ---------------------------------------------------------------------------
add_action( 'rest_api_init', function() {
    register_rest_route( 'samotorlease/v1', '/qualified-vehicles', [
        'methods'             => 'GET',
        'callback'            => 'samotorlease_get_qualified_vehicles',
        'permission_callback' => '__return_true',
    ] );
} );

function samotorlease_get_qualified_vehicles( WP_REST_Request $request ) {
    // 1) Grab rental_limit from query or fallback to lead_id lookup:
    $limit = $request->get_param( 'rental_limit' );
    if ( $limit !== null ) {
        $limit = floatval( $limit );
    } else {
        // fallback
        $lead_id = intval( $request->get_param( 'lead_id' ) );
        if ( ! $lead_id ) {
            return new WP_REST_Response( [ 'error' => 'Missing rental_limit or lead_id' ], 400 );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'lead_qualifications';
        $db_limit = $wpdb->get_var( $wpdb->prepare(
            "SELECT rental_limit FROM {$table} WHERE lead_id = %d",
            $lead_id
        ) );
        if ( $db_limit === null ) {
            return new WP_REST_Response( [ 'error' => 'Lead not found' ], 404 );
        }
        $limit = floatval( $db_limit );
    }

    // 2) Serve from cache when possible — this endpoint is hit on every
    //    results-page load by every visitor, and the catalogue changes hourly.
    $cache_key = 'samotorlease_qv_' . md5( (string) $limit );
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return rest_ensure_response( $cached );
    }

    // 3) Query products whose _price <= $limit
    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_key'       => '_price',
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
        'meta_query'     => [[
            'key'     => '_price',
            'value'   => $limit,
            'compare' => '<=',
            'type'    => 'NUMERIC',
        ]],
    ];
    $q = new WP_Query( $args );

    // 4) Build response
    $out = [];
    if ( $q->have_posts() ) {
        $seen_models = [];

        foreach ( $q->posts as $post ) {
            $p = wc_get_product( $post->ID );
            if ( ! $p ) {
                continue;
            }

            $model = $p->get_attribute( 'model' );
            $price = $p->get_price();

            // One row per distinct model.
            if ( ! $model || in_array( $model, $seen_models, true ) ) {
                continue;
            }

            $seen_models[] = $model;

            $main_id   = $p->get_image_id();
            $gallery   = $p->get_gallery_image_ids();
            $hover_id  = $gallery[0] ?? $main_id;

            $out[] = [
                'id'    => $p->get_id(),
                'title' => $p->get_name(),
                'price' => wc_price( $price ),
                'link'  => $p->get_permalink(),
                'images'  => [
                    'main'  => wp_get_attachment_image_url( $main_id,  'woocommerce_thumbnail' ),
                    'hover' => wp_get_attachment_image_url( $hover_id, 'woocommerce_thumbnail' ),
                ],
            ];
        }
    }

    set_transient( $cache_key, $out, 5 * MINUTE_IN_SECONDS );

    return rest_ensure_response( $out );
}

// ---------------------------------------------------------------------------
// --- is_invalid_image_attachment helper
// ---------------------------------------------------------------------------
function is_invalid_image_attachment($attachment_id) {
    $file_path = get_attached_file($attachment_id);
    if (!file_exists($file_path) || filesize($file_path) === 0) {
        return true;
    }

    $image_info = @getimagesize($file_path);
    return $image_info === false;
}

// ---------------------------------------------------------------------------
// --- process_images_for_existing_vehicle (legacy single-vehicle image rebuilder)
// ---------------------------------------------------------------------------
function process_images_for_existing_vehicle($sku, $domain) {
    $product_id = wc_get_product_id_by_sku($sku);
    if (!$product_id) {
        log_to_file("[Image Fix] No product found for SKU: $sku", "image_fix.log");
        return;
    }

    $product = wc_get_product($product_id);

    // Fetch image feed for this SKU
    $image_feed_url = $domain . "/api/entity/paceWebPrepImages/id/" . $sku;
    $image_feed_data = file_get_contents($image_feed_url);

    if ($image_feed_data === false) {
        log_to_file("[Image Fix] Failed to fetch image feed for SKU: $sku", "image_fix.log");
        return;
    }

    $image_feed = json_decode($image_feed_data, true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($image_feed[0]['images'])) {
        log_to_file("[Image Fix] Invalid or empty image feed for SKU: $sku", "image_fix.log");
        return;
    }

    $image_keys = [
        'x_studio_vp_front_photo',
        'x_studio_vp_front_feature_photo',
        'x_studio_vp_left_side_photo',
        'x_studio_vp_right_side_photo',
        'x_studio_vp_back_photo',
        'x_studio_vp_back_feature_photo',
        'x_studio_vp_interior_photo',
        'x_studio_front_seats',
        'x_studio_rear_seats',
        'x_studio_boot_loadbin',
        'x_studio_vehicle_dashboard',
        'x_studio_vp_spare_wheel',
        'x_studio_vp_wheel_example',
    ];

    foreach ($image_keys as $key) {
        $encoded = $image_feed[0]['images'][$key] ?? '';
        if (empty($encoded)) continue;

        $decoded_text = base64_decode($encoded, true);
        $image_data = base64_decode($decoded_text, true);

        if (!$image_data) {
            log_to_file("[Image Fix] Failed to decode image for $key (SKU: $sku)", "image_fix.log");
            continue;
        }

        $upload_dir = wp_upload_dir();
        $filename = sanitize_file_name($key . '-' . $sku . '.jpg');
        $file_path = $upload_dir['path'] . '/' . $filename;

        if (file_put_contents($file_path, $image_data) === false) {
            log_to_file("[Image Fix] Failed to write file for $key (SKU: $sku)", "image_fix.log");
            continue;
        }

        $filetype = wp_check_filetype($filename, null);
        $attachment = [
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name($filename),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $file_path, $product_id);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
        wp_update_attachment_metadata($attach_id, $attach_data);

        if ($key === 'x_studio_vp_front_feature_photo') {
            set_post_thumbnail($product_id, $attach_id);
            log_to_file("[Image Fix] Set featured image (SKU: $sku, ID: $attach_id)", "image_fix.log");
        } else {
            $gallery_ids = $product->get_gallery_image_ids();
            $gallery_ids[] = $attach_id;
            $product->set_gallery_image_ids($gallery_ids);
            $product->save();
            log_to_file("[Image Fix] Added gallery image (SKU: $sku, ID: $attach_id)", "image_fix.log");
        }
    }
}

// ---------------------------------------------------------------------------
// --- fix_and_replace_broken_images action + ?fix_broken_images trigger + deactivation
// ---------------------------------------------------------------------------
add_action('fix_and_replace_broken_images', function () {
    ensure_media_libs_loaded();
    $domain = 'https://paceapp-server.azurewebsites.net';
    $fixed = 0;
    $skipped = 0;

    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ];

    $products = get_posts($args);

    foreach ($products as $product_id) {
        $sku = get_post_meta($product_id, '_sku', true);
        if (!$sku) {
            log_to_file("[Repair] No SKU for product ID $product_id", 'image_fix.log');
            $skipped++;
            continue;
        }

        $product = wc_get_product($product_id);
        $changed = false;

        $has_featured = has_post_thumbnail($product_id);
        $gallery_ids = $product->get_gallery_image_ids();
        $has_gallery = !empty($gallery_ids);

        // Case 1: No images at all
        if (!$has_featured && !$has_gallery) {
            log_to_file("[Repair] No images found for SKU: $sku — adding from feed", 'image_fix.log');
            process_images_for_existing_vehicle($sku, $domain);
            $fixed++;
            continue;
        }

        // Check featured image
        $thumbnail_id = get_post_thumbnail_id($product_id);
        if ($thumbnail_id && is_invalid_image_attachment($thumbnail_id)) {
            wp_delete_attachment($thumbnail_id, true);
            delete_post_thumbnail($product_id);
            $changed = true;
            log_to_file("[Repair] Deleted invalid featured image for SKU: $sku", 'image_fix.log');
        }

        // Check gallery images
        $valid_gallery_ids = [];
        foreach ($gallery_ids as $attach_id) {
            if (is_invalid_image_attachment($attach_id)) {
                wp_delete_attachment($attach_id, true);
                log_to_file("[Repair] Deleted invalid gallery image (ID: $attach_id) for SKU: $sku", 'image_fix.log');
                $changed = true;
            } else {
                $valid_gallery_ids[] = $attach_id;
            }
        }

        $product->set_gallery_image_ids($valid_gallery_ids);

        if ($changed) {
            process_images_for_existing_vehicle($sku, $domain);
            $product->save();
            $fixed++;
        } else {
            $skipped++;
        }
    }

    log_to_file("[Repair] ✅ Image repair complete. Fixed: $fixed | Skipped: $skipped", 'image_fix.log');
});

add_action('init', function () {
    if (isset($_GET['fix_broken_images']) && current_user_can('manage_options') && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'sa_motorlease_admin_action')) {
        if (!wp_next_scheduled('fix_and_replace_broken_images')) {
            wp_schedule_single_event(time() + 5, 'fix_and_replace_broken_images');
            echo '✅ One-time repair job scheduled.';
        } else {
            echo '⚠️ Already scheduled.';
        }
        exit;
    }
});


// ---------------------------------------------------------------------------
// --- Admin URL: ?test_image_feed
// ---------------------------------------------------------------------------
add_action('init', function () {
    if (!isset($_GET['test_image_feed']) || !current_user_can('manage_options')) return;

    $sku = sanitize_text_field($_GET['test_image_feed']);
    $domain = 'https://paceapp-server.azurewebsites.net'; // ✅ replace this with your actual domain
    $image_feed_url = $domain . "/api/entity/paceWebPrepImages/id/" . $sku;

    echo "<pre>";
    echo "🔍 Fetching image feed for SKU: $sku\n";
    echo "📡 URL: $image_feed_url\n\n";

    $json = file_get_contents($image_feed_url);
    if ($json === false) {
        echo "❌ Failed to fetch URL\n";
        exit;
    }

    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($data[0]['images'])) {
        echo "❌ Invalid or empty JSON\n";
        exit;
    }

    $images = $data[0]['images'];
    $image_keys = array_keys($images);

    echo "✅ Found " . count($image_keys) . " image keys:\n\n";

    foreach ($image_keys as $key) {
        $base64 = $images[$key];
        echo "- $key: ";

        if (empty($base64)) {
            echo "⚠️ empty\n";
            continue;
        }

        $decoded_1 = base64_decode($base64, true);
        $decoded_2 = base64_decode($decoded_1, true);

        if ($decoded_2 && strlen($decoded_2) > 1000) {
            echo "✅ Valid (double-decoded, size: " . strlen($decoded_2) . " bytes)\n";
        } elseif ($decoded_1 && strlen($decoded_1) > 1000) {
            echo "✅ Valid (single-decoded, size: " . strlen($decoded_1) . " bytes)\n";
        } else {
            echo "❌ Invalid or too small\n";
        }
    }

    echo "\n🏁 Done.";
    exit;
});

// ---------------------------------------------------------------------------
// --- update_number_of_payments_attribute action
// ---------------------------------------------------------------------------
add_action('update_number_of_payments_attribute', function () {
    $updated = 0;
    $skipped = 0;

    // Fetch the feed once and index it by SKU.
    $feed_url = 'https://paceapp-server.azurewebsites.net/api/entity/paceWebPrepData';
    $feed_raw = file_get_contents($feed_url);
    if (!$feed_raw) {
        log_to_file("[Backfill] Failed to fetch feed", 'backfill_payments.log');
        return;
    }
    $feed_data = json_decode($feed_raw, true);
    if (!is_array($feed_data)) {
        log_to_file("[Backfill] Failed to decode feed JSON", 'backfill_payments.log');
        return;
    }
    $feed_by_sku = [];
    foreach ($feed_data as $entry) {
        if (isset($entry['id'])) {
            $feed_by_sku[(string)$entry['id']] = $entry;
        }
    }

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ];

    $products = get_posts($args);

    foreach ($products as $product_id) {
        $sku = get_post_meta($product_id, '_sku', true);
        if (!$sku) {
            $skipped++;
            continue;
        }

        $vehicle = $feed_by_sku[(string)$sku] ?? null;

        if (!$vehicle || empty($vehicle['rebate_target'])) {
            $skipped++;
            continue;
        }

        $attribute_value = $vehicle['rebate_target'] . ' On Time Payments';

        register_attribute_if_not_exists('Number Of Payments');
        add_attribute_to_product_data($product_id, 'Number Of Payments', $attribute_value);
        $product = wc_get_product($product_id);
        $product->save();
        log_to_file("[Backfill] Set 'Number Of Payments' for SKU: $sku → $attribute_value", 'backfill_payments.log');
        $updated++;
    }

    log_to_file("[Backfill] ✅ Done. Updated: $updated | Skipped: $skipped", 'backfill_payments.log');
});

// ---------------------------------------------------------------------------
// --- Admin URL: ?run_payments_backfill
// ---------------------------------------------------------------------------
add_action('init', function () {
    if (isset($_GET['run_payments_backfill']) && current_user_can('manage_options') && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'sa_motorlease_admin_action')) {
        if (!wp_next_scheduled('update_number_of_payments_attribute')) {
            wp_schedule_single_event(time() + 5, 'update_number_of_payments_attribute');
            echo '✅ One-time payments backfill scheduled.';
        } else {
            echo '⚠️ Already scheduled.';
        }
        exit;
    }
});

// ---------------------------------------------------------------------------
// --- Admin URL: ?format_deposit_special
// ---------------------------------------------------------------------------
add_action('init', function () {
    if (isset($_GET['format_deposit_special']) && current_user_can('manage_options') && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'sa_motorlease_admin_action')) {
        $updated = 0;
        $skipped = 0;

        $products = get_posts([
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ]);

        foreach ($products as $product_id) {
            // Get the current value from taxonomy term
            $terms = wp_get_post_terms($product_id, 'pa_initiation-fee-special', ['fields' => 'names']);
            if (empty($terms)) {
                $skipped++;
                continue;
            }

            $raw_value = $terms[0];
            $formatted_value = format_currency_value($raw_value);

            // Skip if already formatted
            if ($formatted_value === $raw_value) {
                $skipped++;
                continue;
            }

            // Re-add the attribute with formatted value
            add_attribute_to_product_data($product_id, 'Initiation Fee Special', $formatted_value);

            $product = wc_get_product($product_id);
            if ($product) {
                $product->save();
                log_to_file("[Format] Updated 'Initiation Fee Special' for SKU: {$product->get_sku()} → $formatted_value");
                $updated++;
            }
        }

        log_to_file("[Format] ✅ Initiation Fee Special formatting complete. Updated: $updated | Skipped: $skipped");
        echo "✅ Initiation Fee Special formatting complete. Updated: $updated, Skipped: $skipped.";
        exit;
    }
});

// ---------------------------------------------------------------------------
// --- Admin URL: ?cleanup_initiation_fee_special
// ---------------------------------------------------------------------------
add_action('init', function () {
    if (isset($_GET['cleanup_initiation_fee_special']) && current_user_can('manage_options') && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'sa_motorlease_admin_action')) {
        @set_time_limit(0);
        ignore_user_abort(true);

        $removed = 0;
        $skipped = 0;

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ];

        $products = get_posts($args);

        // Build taxonomy key once
        $attribute_slug = wc_sanitize_taxonomy_name('Initiation Fee Special'); // -> initiation-fee-special
        $taxonomy       = 'pa_' . $attribute_slug;

        foreach ($products as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) { $skipped++; continue; }

            // Grab the attributes array from postmeta
            $product_attributes = get_post_meta($product_id, '_product_attributes', true);
            $attr_exists_in_meta = is_array($product_attributes) && isset($product_attributes[$taxonomy]);

            // Get assigned terms (full objects so we can remove by ID)
            $terms = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'all']);
            if (is_wp_error($terms)) { $skipped++; continue; }

            $has_terms = !empty($terms);

            // Helper: normalize a value to check "emptiness"
            $normalize = static function($val) {
                $val = trim((string)$val);
                // strip "R " or "R" and spaces
                $val = preg_replace('/^R\s*/i', '', $val);
                return $val;
            };

            $is_empty_like = false;
            if ($has_terms) {
                // Check the first term's *name* for empty-like values
                $first = $normalize($terms[0]->name ?? '');
                $empty_set = ['', '0', '0.00', '0.0', '0,00']; // include common variants
                $is_empty_like = in_array($first, $empty_set, true);
            }

            // Removal conditions:
            //  A) Attribute exists in meta but there are NO terms assigned  -> remove attribute from meta
            //  B) There ARE terms but the value is an empty-like value      -> remove terms and meta
            $should_remove_for_no_terms = ($attr_exists_in_meta && !$has_terms);
            $should_remove_for_empty_val = ($has_terms && $is_empty_like);

            if ($should_remove_for_no_terms || $should_remove_for_empty_val) {
                // 1) Remove assigned terms (if any)
                if ($has_terms) {
                    $term_ids = wp_list_pluck($terms, 'term_id');
                    wp_remove_object_terms($product_id, $term_ids, $taxonomy);
                }

                // 2) Remove from _product_attributes
                if ($attr_exists_in_meta) {
                    unset($product_attributes[$taxonomy]);
                    update_post_meta($product_id, '_product_attributes', $product_attributes);
                }

                // 3) Log
                error_log(
                    "[Cleanup] Removed 'Initiation Fee Special' from product ID: {$product_id}\n",
                    3,
                    WP_CONTENT_DIR . '/initiation_fee_cleanup.log'
                );

                $removed++;
            } else {
                // Nothing to do for this product
                $skipped++;
            }
        }

        echo "✅ Cleanup complete. Removed: {$removed} | Skipped: {$skipped}";
        exit;
    }
});

// ---------------------------------------------------------------------------
// --- /partial-save REST endpoint
// ---------------------------------------------------------------------------
add_action('rest_api_init', function () {
    register_rest_route('samotorlease/v1', '/partial-save', [
        'methods'  => 'POST',
        'callback' => 'samotorlease_handle_partial_save',
        'permission_callback' => '__return_true',
    ]);
});

function samotorlease_handle_partial_save(WP_REST_Request $request) {
    $data = $request->get_json_params();

    if (empty($data['lead_id'])) {
        sa_motorlease_log_lead('partial-save', SA_MOTORLEASE_LOG_WARN,
            'Missing lead_id in incoming request. request=' . wp_json_encode( $data ?: [] ));
        return new WP_REST_Response(['error' => 'Missing lead ID'], 400);
    }

    $lead_id = (int) $data['lead_id'];

    // Whitelist keys forwarded to PACE — never proxy arbitrary client-supplied fields.
    $address_keys = ['street', 'suburb', 'city', 'province', 'country'];
    $allowed = [
        'lead_id'           => $lead_id,
        'home_address'      => array_intersect_key((array)($data['home_address'] ?? []), array_flip($address_keys)),
        'occupation'        => isset($data['occupation'])        ? (string)$data['occupation']        : null,
        'employment_status' => isset($data['employment_status']) ? (string)$data['employment_status'] : null,
        'employer_name'     => isset($data['employer_name'])     ? (string)$data['employer_name']     : null,
        'work_email'        => isset($data['work_email'])        ? (string)$data['work_email']        : null,
        'work_phone'        => isset($data['work_phone'])        ? (string)$data['work_phone']        : null,
        'work_address'      => array_intersect_key((array)($data['work_address'] ?? []), array_flip($address_keys)),
        'proof_address'     => isset($data['proof_address'])     ? (string)$data['proof_address']     : null,
        'bank_statements'   => isset($data['bank_statements'])   ? $data['bank_statements']           : null,
    ];
    // Drop keys that were absent in the original payload to avoid sending nulls to PACE.
    $allowed = array_filter($allowed, static fn($v) => $v !== null);

    $json_payload = wp_json_encode($allowed, JSON_UNESCAPED_SLASHES);

    sa_motorlease_log_lead('partial-save', SA_MOTORLEASE_LOG_INFO,
        sprintf('POST paceWebUpdateLead (lead_id=%d) request=%s',
            $lead_id, wp_json_encode( sa_motorlease_mask_pii( $data ) )));

    $external_url = sa_motorlease_pace_url( '/api/entity/paceWebUpdateLead' );
    $response = wp_remote_post($external_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => $json_payload,
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        sa_motorlease_log_lead('partial-save', SA_MOTORLEASE_LOG_ERROR,
            sprintf('WP_Error from paceWebUpdateLead (lead_id=%d): %s request=%s',
                $lead_id, $error_message, sa_motorlease_log_truncate( $json_payload )));
        return new WP_REST_Response(['error' => $error_message], 500);
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($code < 200 || $code >= 300) {
        sa_motorlease_log_lead('partial-save', SA_MOTORLEASE_LOG_WARN,
            sprintf('paceWebUpdateLead HTTP %d (lead_id=%d) request=%s response=%s',
                $code, $lead_id, sa_motorlease_log_truncate( $json_payload ), substr($body, 0, 1000)));
    } else {
        sa_motorlease_log_lead('partial-save', SA_MOTORLEASE_LOG_INFO,
            sprintf('OK lead_id=%d http=%d response=%s',
                $lead_id, $code, substr($body, 0, 500)));
    }

    return new WP_REST_Response([
        'message'  => 'Forwarded to external API',
        'status'   => $code,
        'response' => json_decode($body, true),
    ], $code);
}

// ---------------------------------------------------------------------------
// --- /update-deposit-special REST endpoint
// ---------------------------------------------------------------------------
add_action('rest_api_init', function () {
    register_rest_route('samotorlease/v1', '/update-deposit-special', [
        'methods'             => 'GET',
        'callback'            => 'update_initiation_fee_special_for_existing_products',
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ]);
});

function update_initiation_fee_special_for_existing_products() {
    $domain = 'https://paceapp-server.azurewebsites.net';
    $api_endpoint = $domain . '/api/entity/paceWebPrepData';
    $json_data = file_get_contents($api_endpoint);

    if ($json_data === false) {
        log_to_file('Failed to fetch data from API endpoint.', 'update_deposit_special.log');
        return new WP_REST_Response(['error' => 'Failed to fetch feed'], 500);
    }

    $feed_data = json_decode($json_data, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_to_file('Invalid JSON format from API endpoint.', 'update_deposit_special.log');
        return new WP_REST_Response(['error' => 'Invalid JSON'], 500);
    }

    $updated_count = 0;

    foreach ($feed_data as $vehicle) {
        $sku = $vehicle['id'];
        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id || !isset($vehicle['deposit_special'])) {
            continue;
        }

        $raw_special = $vehicle['deposit_special'];
        if (!$raw_special || $raw_special == 0) {
            continue; // skip empty or 0 values
        }

        $formatted_value = format_currency_value($raw_special); // e.g., R15,000

        // Add attribute
        add_attribute_to_product_data($product_id, 'Initiation Fee Special', $formatted_value);
        log_to_file("✅ Updated 'Initiation Fee Special' for SKU: {$sku} → {$formatted_value}", 'update_deposit_special.log');
        $updated_count++;
    }

    return new WP_REST_Response(['updated' => $updated_count]);
}

// ---------------------------------------------------------------------------
// --- Admin URL & handler: cleanup_sold_products
// ---------------------------------------------------------------------------
add_action('init', function () {
    if (isset($_GET['cleanup_sold_products']) && current_user_can('manage_options') && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'sa_motorlease_admin_action')) {
        cleanup_sold_products();
        echo '✅ Sold product cleanup triggered. Check `sold_cleanup.log` in uploads folder.';
        exit;
    }
});

function cleanup_sold_products() {
    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ];

    $products = get_posts($args);
    $count_deleted = 0;

    log_to_file("🔍 Starting sold products cleanup", 'sold_cleanup.log');

    foreach ($products as $product_id) {
        $terms = wp_get_post_terms($product_id, 'pa_sold', ['fields' => 'names']);

        if (in_array('Yes', $terms)) {
            $sku = get_post_meta($product_id, '_sku', true);

            // Delete images
            $thumbnail_id = get_post_thumbnail_id($product_id);
            if ($thumbnail_id) {
                wp_delete_attachment($thumbnail_id, true);
                log_to_file("🖼 Deleted featured image for SKU: $sku", 'sold_cleanup.log');
            }

            $product = wc_get_product($product_id);
            if ($product) {
                foreach ($product->get_gallery_image_ids() as $attach_id) {
                    wp_delete_attachment($attach_id, true);
                    log_to_file("🖼 Deleted gallery image ID: $attach_id for SKU: $sku", 'sold_cleanup.log');
                }
            }

            // Delete product
            wp_delete_post($product_id, true);
            log_to_file("🗑 Deleted product ID: $product_id (SKU: $sku)", 'sold_cleanup.log');
            $count_deleted++;
        }
    }

    log_to_file("✅ Cleanup complete. Total deleted: $count_deleted", 'sold_cleanup.log');
}

// ---------------------------------------------------------------------------
// --- cleanup_sold_status_from_feed + ?update_sold_from_feed
// ---------------------------------------------------------------------------
function cleanup_sold_status_from_feed() {
    $feed_url = 'https://paceapp-server.azurewebsites.net/api/entity/paceWebPrepData';

    $json = file_get_contents($feed_url);
    if ($json === false) {
        log_to_file("❌ Failed to fetch feed data", 'sold-feed-cleanup.log');
        return;
    }

    $feed_data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($feed_data)) {
        log_to_file("❌ Invalid JSON feed format", 'sold-feed-cleanup.log');
        return;
    }

    // Create a map of [SKU => sold status]
    $sold_map = [];
    foreach ($feed_data as $entry) {
        if (isset($entry['id']) && isset($entry['sold'])) {
            $sold_map[$entry['id']] = $entry['sold'];
        }
    }

    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ];

    $products = get_posts($args);
    $updated = 0;

    foreach ($products as $product_id) {
        $sku = get_post_meta($product_id, '_sku', true);
        if (!$sku || !isset($sold_map[$sku])) continue;

        $is_sold_in_feed = $sold_map[$sku];
        $current_sold_attr = wp_get_post_terms($product_id, 'pa_sold', ['fields' => 'names']);

        if ($is_sold_in_feed && !in_array('Yes', $current_sold_attr)) {
            // Register attribute if needed
            register_attribute_if_not_exists('Sold');

            // Update Sold attribute
            add_attribute_to_product_data($product_id, 'Sold', ['Yes']);
            log_to_file("✅ Updated 'Sold' to Yes for SKU: $sku (Product ID: $product_id)", 'sold-feed-cleanup.log');
            $updated++;

            // Also add Sold Date if not already set
            $sold_date_attr = wp_get_post_terms($product_id, 'pa_sold-date', ['fields' => 'names']);
            if (empty($sold_date_attr)) {
                $today = current_time('Y-m-d');
                register_attribute_if_not_exists('Sold Date');
                add_attribute_to_product_data($product_id, 'Sold Date', [$today]);
                log_to_file("📅 Set Sold Date for SKU: $sku (Product ID: $product_id)", 'sold-feed-cleanup.log');
            }
        }
    }

    log_to_file("🔁 Cleanup complete. Updated products: $updated", 'sold-feed-cleanup.log');
}

add_action('init', function () {
    if (isset($_GET['update_sold_from_feed']) && current_user_can('manage_options') && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'sa_motorlease_admin_action')) {
        cleanup_sold_status_from_feed();
        echo '✅ Sold status cleanup complete. Check `sold-feed-cleanup.log`.';
        exit;
    }
});

// ---------------------------------------------------------------------------
// --- ?remove_by_reg_cleanup + remove_products_by_registration
// ---------------------------------------------------------------------------
add_action('init', function () {
    if (isset($_GET['remove_by_reg_cleanup']) && current_user_can('manage_options') && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'sa_motorlease_admin_action')) {
        // ✅ Replace this with your actual list of registrations
        $registrations_to_remove = [];

        remove_products_by_registration($registrations_to_remove);

        echo '✅ Registration-based cleanup complete. Check registration_cleanup.log in uploads folder.';
        exit;
    }
});

// Helper function to remove matching products
function remove_products_by_registration($registration_list) {
    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ];

    $products = get_posts($args);
    $removed = 0;

    foreach ($products as $product_id) {
        $registration_attr = wp_get_post_terms($product_id, 'pa_registration', ['fields' => 'names']);
        $registration_val = !empty($registration_attr[0]) ? strtoupper(trim($registration_attr[0])) : '';

        if (in_array($registration_val, array_map('strtoupper', $registration_list))) {
            $sku = get_post_meta($product_id, '_sku', true);

            // Delete featured image
            $thumb_id = get_post_thumbnail_id($product_id);
            if ($thumb_id) {
                wp_delete_attachment($thumb_id, true);
                log_to_file("🖼 Deleted featured image for SKU: $sku", 'registration_cleanup.log');
            }

            // Delete gallery images
            $product = wc_get_product($product_id);
            if ($product) {
                foreach ($product->get_gallery_image_ids() as $img_id) {
                    wp_delete_attachment($img_id, true);
                    log_to_file("🖼 Deleted gallery image ID: $img_id for SKU: $sku", 'registration_cleanup.log');
                }
            }

            wp_delete_post($product_id, true);
            log_to_file("🗑 Deleted product ID: $product_id (SKU: $sku, Reg: $registration_val)", 'registration_cleanup.log');
            $removed++;
        }
    }

    log_to_file("✅ Cleanup complete. Total deleted: $removed", 'registration_cleanup.log');
}

// ---------------------------------------------------------------------------
// --- ?log_missing_products + log_products_missing_from_feed
// ---------------------------------------------------------------------------
add_action('init', function () {
    if (isset($_GET['log_missing_products']) && current_user_can('manage_options') && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'sa_motorlease_admin_action')) {
        log_products_missing_from_feed();
        echo '✅ Product log complete. Check `product_cleanup.log` in uploads folder.';
        exit;
    }
});

function log_products_missing_from_feed() {
    $domain = 'https://paceapp-server.azurewebsites.net';
    $feed_url = $domain . '/api/entity/paceWebPrepData';

    $json = file_get_contents($feed_url);
    if ($json === false) {
        log_to_file('❌ Failed to fetch vehicle feed.', 'product_cleanup.log');
        return;
    }

    $feed = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($feed)) {
        log_to_file('❌ Invalid feed format or JSON error.', 'product_cleanup.log');
        return;
    }

    // Build list of valid SKUs from the feed
    $valid_skus = array_map(function ($item) {
        return $item['id'];
    }, $feed);

    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ];

    $products = get_posts($args);
    $log_count = 0;

    foreach ($products as $product_id) {
        $sku = get_post_meta($product_id, '_sku', true);
        if (!in_array($sku, $valid_skus)) {
            $title = get_the_title($product_id);
            log_to_file("📌 Would remove product: ID {$product_id}, SKU {$sku}, Title: {$title}", 'product_cleanup.log');
            $log_count++;
        }
    }

    log_to_file("🧾 Total products that would be removed: $log_count", 'product_cleanup.log');
}

// ---------------------------------------------------------------------------
// --- cheapest_price shortcode
// ---------------------------------------------------------------------------
function get_cheapest_product_price_plain_shortcode() {
    $args = [
        'status'   => 'publish',
        'limit'    => -1,
        'orderby'  => 'meta_value_num',
        'order'    => 'ASC',
        'meta_key' => '_price',
        'return'   => 'ids',
    ];

    $products = wc_get_products($args);

    if (!empty($products)) {
        foreach ($products as $product_id) {
            $product = wc_get_product($product_id);
            if ($product && $product->get_price() > 0) {
                return number_format($product->get_price(), 2, '.', ',');
            }
        }
    }

    return '';
}

add_shortcode('cheapest_price', 'get_cheapest_product_price_plain_shortcode');

// ---------------------------------------------------------------------------
// --- ?remove_missing_products + remove_missing_products_from_feed
// ---------------------------------------------------------------------------
add_action('init', function () {
    if (isset($_GET['remove_missing_products']) && current_user_can('manage_options') && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'sa_motorlease_admin_action')) {
        remove_missing_products_from_feed();
        echo '✅ Product cleanup complete. Check `product_cleanup.log` in uploads folder.';
        exit;
    }
});

function remove_missing_products_from_feed() {
    $domain = 'https://paceapp-server.azurewebsites.net';
    $feed_url = $domain . '/api/entity/paceWebPrepData';

    $json = file_get_contents($feed_url);
    if ($json === false) {
        log_to_file('❌ Failed to fetch vehicle feed.', 'product_cleanup.log');
        return;
    }

    $feed = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($feed)) {
        log_to_file('❌ Invalid feed format or JSON error.', 'product_cleanup.log');
        return;
    }

    $valid_skus = array_map(function ($item) {
        return $item['id'];
    }, $feed);

    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ];

    $products = get_posts($args);
    $deleted_count = 0;

    foreach ($products as $product_id) {
        $sku = get_post_meta($product_id, '_sku', true);
        if (!in_array($sku, $valid_skus)) {
            wp_delete_post($product_id, true);
            log_to_file("🗑 Deleted product with SKU: $sku (not found in feed)", 'product_cleanup.log');
            $deleted_count++;
        }
    }

    log_to_file("✅ Cleanup done. Total products deleted: $deleted_count", 'product_cleanup.log');
}

// ---------------------------------------------------------------------------
// --- ?backfill_image_alt + update_all_vehicle_image_alts
// ---------------------------------------------------------------------------
add_action('init', function () {
    if (isset($_GET['backfill_image_alt']) && current_user_can('manage_options') && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'sa_motorlease_admin_action')) {
        update_all_vehicle_image_alts();
        echo '✅ Image ALT update complete. Check `image_alt_backfill.log` in uploads folder.';
        exit;
    }
});

function update_all_vehicle_image_alts() {
    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ];

    $products = get_posts($args);

    foreach ($products as $product_id) {
        $product = wc_get_product($product_id);
        if (!$product) continue;

        $sku         = $product->get_sku() ?: 'No SKU';
        $title       = $product->get_name();
        $base_label  = $title ?: "Vehicle {$sku}";

        // --- Featured image
        $thumb_id = get_post_thumbnail_id($product_id);
        if ($thumb_id) {
            $alt_text = "{$base_label} - Featured Image";
            update_post_meta($thumb_id, '_wp_attachment_image_alt', $alt_text);
            log_to_file("✅ Set ALT for featured image ID: $thumb_id to '{$alt_text}'", 'image_alt_backfill.log');
        }

        // --- Gallery images
        $gallery_ids = $product->get_gallery_image_ids();
        foreach ($gallery_ids as $index => $img_id) {
            // Try to infer label from filename
            $filename = basename(get_attached_file($img_id));
            $label    = infer_label_from_filename($filename) ?: "Gallery Image " . ($index + 1);

            $alt_text = "{$base_label} - {$label}";
            update_post_meta($img_id, '_wp_attachment_image_alt', $alt_text);
            log_to_file("✅ Set ALT for gallery image ID: $img_id to '{$alt_text}'", 'image_alt_backfill.log');
        }
    }
}

// ---------------------------------------------------------------------------
// --- infer_label_from_filename helper
// ---------------------------------------------------------------------------
function infer_label_from_filename($filename) {
    $map = [
        'front_feature' => 'Front Feature',
        'front_photo'   => 'Front Photo',
        'left_side'     => 'Left Side',
        'right_side'    => 'Right Side',
        'back_feature'  => 'Back Feature',
        'back_photo'    => 'Back Photo',
        'interior'      => 'Interior',
        'dashboard'     => 'Dashboard',
        'seats'         => 'Seats',
        'boot'          => 'Boot',
        'spare'         => 'Spare Wheel',
        'wheel'         => 'Wheel',
    ];

    foreach ($map as $key => $label) {
        if (stripos($filename, $key) !== false) return $label;
    }

    return null;
}

// ============================================================================
// === SETTINGS + STATUS ADMIN UI =============================================
// ============================================================================
//
// Single top-level admin menu "SA Motorlease" with two subpages:
//   - Settings: stores plugin config in the single option sa_motorlease_settings
//     (PACE base URL, kill switch, qualify form IDs, GF forwarder form ID,
//     log level, log retention days).
//   - Status: read-only diagnostics — versions, scheduled cron events,
//     lead-qualifications table summary, log tail.
//
// Settings are read via sa_motorlease_get_setting() everywhere they matter;
// constants defined at the top of the file continue to provide the same
// defaults when the option is unset.

const SA_MOTORLEASE_SETTINGS_OPTION = 'sa_motorlease_settings';
const SA_MOTORLEASE_SETTINGS_SLUG   = 'sa-motorlease-settings';
const SA_MOTORLEASE_STATUS_SLUG     = 'sa-motorlease-status';

function sa_motorlease_default_settings() {
    return [
        'pace_base_url'        => 'https://paceapp-server.azurewebsites.net',
        'pace_enabled'         => 1,
        'qualify_form_ids'     => '1,3',
        'gf_forwarder_form_id' => 5,
        'log_level'            => SA_MOTORLEASE_LOG_WARN,
        'log_retention_days'   => 21,
        'listings_disclaimer'  => 'Please note all vehicle listings are subject to availability and may change without prior notice. Images are for illustration purposes only and may differ from the actual vehicle offered. Prices, specifications, and features are provided as a guide and may vary depending on stock, condition, and applicable fees. Placement on this page does not guarantee that a vehicle is currently available',
        'listings_page_id'     => 0,
    ];
}

function sa_motorlease_get_settings() {
    $stored = get_option( SA_MOTORLEASE_SETTINGS_OPTION, [] );
    if ( ! is_array( $stored ) ) $stored = [];
    return array_merge( sa_motorlease_default_settings(), $stored );
}

function sa_motorlease_get_setting( $key, $fallback = null ) {
    $s = sa_motorlease_get_settings();
    return array_key_exists( $key, $s ) ? $s[ $key ] : $fallback;
}

function sa_motorlease_pace_base_url() {
    $url = (string) sa_motorlease_get_setting( 'pace_base_url', 'https://paceapp-server.azurewebsites.net' );
    return untrailingslashit( $url );
}

/**
 * Join an endpoint path onto the configured PACE base URL, preserving any
 * query string the admin attached to the base (e.g. ?env=test). Naive string
 * concatenation would place the query before the path and break the URL.
 *
 *   pace_base_url = https://host?env=test
 *   path          = /api/entity/paceWebCreateLead
 *   result        = https://host/api/entity/paceWebCreateLead?env=test
 */
function sa_motorlease_pace_url( $path = '' ) {
    $base  = sa_motorlease_get_setting( 'pace_base_url', 'https://paceapp-server.azurewebsites.net' );
    $parts = wp_parse_url( (string) $base );

    if ( ! $parts || empty( $parts['host'] ) ) {
        // Fallback: best-effort concat if the configured value is unparseable.
        return rtrim( (string) $base, '/' ) . $path;
    }

    $scheme    = $parts['scheme'] ?? 'https';
    $host      = $parts['host'];
    $port      = isset( $parts['port'] )  ? ':' . $parts['port']           : '';
    $base_path = isset( $parts['path'] )  ? rtrim( $parts['path'], '/' )   : '';
    $query     = isset( $parts['query'] ) ? '?' . $parts['query']          : '';

    if ( $path !== '' && $path[0] !== '/' ) {
        $path = '/' . $path;
    }

    return $scheme . '://' . $host . $port . $base_path . $path . $query;
}

function sa_motorlease_pace_posting_enabled() {
    return (bool) sa_motorlease_get_setting( 'pace_enabled', 1 );
}

/**
 * Validate the qualify-lead payload before it leaves us for the PACE API.
 * Returns null if valid, or a human-readable error message describing the
 * first problem. Messages are intentionally short — the JS surfaces them
 * verbatim to the user via the form's error banner.
 */
function sa_motorlease_validate_qualify_payload( array $data ) {
    $required_text = [
        'name'             => 'First name',
        'surname'          => 'Surname',
        'cellphone_number' => 'Phone',
        'your_email'       => 'Email',
        'location'         => 'Location',
        'take_home'        => 'Take-home pay',
    ];
    foreach ( $required_text as $key => $label ) {
        if ( ! isset( $data[ $key ] ) || trim( (string) $data[ $key ] ) === '' ) {
            return $label . ' is required.';
        }
    }

    $id = isset( $data['id_number'] ) ? trim( (string) $data['id_number'] ) : '';
    if ( $id === '' ) {
        return 'ID/Passport is required.';
    }
    // Accept SA IDs (13 digits), SA passports (9 alphanumeric), and most
    // international passports (9 alphanumeric). Mirrors the JS idRx in
    // lead-qualification.js so client and server agree. PACE runs the
    // authoritative check on its end.
    if ( ! preg_match( '/^[A-Za-z0-9]{9,13}$/', $id ) ) {
        return 'ID/Passport must be 9-13 letters or digits.';
    }

    if ( ! is_email( $data['your_email'] ) ) {
        return 'Please enter a valid email address.';
    }

    // Phone: strip formatting, then accept either national (0XXXXXXXXX)
    // or international (27XXXXXXXXX) digit shapes. Mirrors isValidSAPhone()
    // in lead-qualification.js so client and server agree.
    $phone_digits = preg_replace( '/\D+/', '', (string) $data['cellphone_number'] );
    if ( ! preg_match( '/^(0\d{9}|27\d{9})$/', $phone_digits ) ) {
        return 'Please enter a valid SA phone number.';
    }

    if ( empty( $data['accept_terms'] ) ) {
        return 'You must accept the terms.';
    }

    // "Other" location selected → other_location text must be filled in.
    if ( strcasecmp( trim( (string) $data['location'] ), 'Other' ) === 0
         && trim( (string) ( $data['other_location'] ?? '' ) ) === '' ) {
        return 'Please tell us where you are based.';
    }

    return null;
}

// --- Lead endpoint activity logging -----------------------------------------
//
// The three lead endpoints (/qualify-lead, /partial-save, GF5 forwarder) carry
// every real user submission, so we want a permanent activity trail in
// sa-motorlease.log even when the global log threshold is dialled down. The
// sa_motorlease_log_lead() helper bypasses SA_MOTORLEASE_LOG_LEVEL — every
// entry it writes lands on disk, severity tagged so the Status page can still
// tell errors from successes at a glance.
//
// PII (name, surname, ID number, phone, email, address streets) is masked on
// success entries and left intact on WARN/ERROR entries so we can debug what
// the user actually sent when something goes wrong. Retention (default 21
// days) limits exposure of the raw PII window.

/**
 * Truncate a string destined for a log line so a single WARN/ERROR entry
 * can't carry a multi-MB payload into sa-motorlease.log. The Status page
 * tails this file and an unbounded line would force it to allocate a huge
 * buffer just to find the surrounding lines.
 */
function sa_motorlease_log_truncate( $s, $max = 4096 ) {
    $s   = (string) $s;
    $len = strlen( $s );
    if ( $len <= $max ) return $s;
    return substr( $s, 0, $max ) . sprintf( '… (truncated, %d of %d bytes shown)', $max, $len );
}

function sa_motorlease_log_lead( $channel, $level, $msg ) {
    static $labels = [
        SA_MOTORLEASE_LOG_ERROR => 'ERROR',
        SA_MOTORLEASE_LOG_WARN  => 'WARN',
        SA_MOTORLEASE_LOG_INFO  => 'INFO',
        SA_MOTORLEASE_LOG_DEBUG => 'DEBUG',
    ];
    $label = isset( $labels[ $level ] ) ? $labels[ $level ] : 'INFO';
    $line  = sprintf( '[%s] [%s] [%s] %s%s',
        date( 'Y-m-d H:i:s' ), $channel, $label, $msg, PHP_EOL );
    $file  = sa_motorlease_log_path();
    if ( @file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ) === false ) {
        error_log( '[SA Motorlease][WRITE FAIL] ' . $msg );
    }
}

/**
 * Recursively walk a lead/application payload and mask the PII fields by
 * key name. Returns a new array — does not mutate the input. Use for INFO
 * (success) logging only; raw payloads stay intact for WARN/ERROR entries.
 */
function sa_motorlease_mask_pii( $value ) {
    static $pii_keys = [
        'name'             => 'name',
        'surname'          => 'name',
        'id_number'        => 'id',
        'cellphone_number' => 'phone',
        'work_phone'       => 'phone',
        'your_email'       => 'email',
        'work_email'       => 'email',
        'street'           => 'address',
    ];
    if ( ! is_array( $value ) ) {
        return $value;
    }
    $out = [];
    foreach ( $value as $k => $v ) {
        if ( is_string( $k ) && isset( $pii_keys[ $k ] ) && is_scalar( $v ) ) {
            $out[ $k ] = sa_motorlease_mask_by_kind( $pii_keys[ $k ], (string) $v );
        } elseif ( is_array( $v ) ) {
            $out[ $k ] = sa_motorlease_mask_pii( $v );
        } else {
            $out[ $k ] = $v;
        }
    }
    return $out;
}

function sa_motorlease_mask_by_kind( $kind, $value ) {
    if ( $value === '' ) return '';
    switch ( $kind ) {
        case 'email':
            $at = strrpos( $value, '@' );
            return $at !== false ? '***' . substr( $value, $at ) : '***';
        case 'phone':
            $digits = preg_replace( '/\D+/', '', $value );
            return strlen( $digits ) >= 4 ? '*****' . substr( $digits, -4 ) : '*****';
        case 'id':
            return strlen( $value ) >= 4 ? '*********' . substr( $value, -4 ) : '*****';
        case 'name':
            $len = strlen( $value );
            return $value[0] . ( $len > 1 ? str_repeat( '*', $len - 1 ) : '' );
        case 'address':
            return '*** (' . strlen( $value ) . ' chars)';
    }
    return '***';
}

// --- Admin menu -------------------------------------------------------------

add_action( 'admin_menu', function () {
    add_menu_page(
        'SA Motorlease',
        'SA Motorlease',
        'manage_options',
        SA_MOTORLEASE_SETTINGS_SLUG,
        'sa_motorlease_render_settings_page',
        'dashicons-car',
        58
    );
    add_submenu_page(
        SA_MOTORLEASE_SETTINGS_SLUG,
        'SA Motorlease Settings',
        'Settings',
        'manage_options',
        SA_MOTORLEASE_SETTINGS_SLUG,
        'sa_motorlease_render_settings_page'
    );
    add_submenu_page(
        SA_MOTORLEASE_SETTINGS_SLUG,
        'SA Motorlease Status',
        'Status',
        'manage_options',
        SA_MOTORLEASE_STATUS_SLUG,
        'sa_motorlease_render_status_page'
    );
} );

// --- Settings registration --------------------------------------------------

add_action( 'admin_init', function () {
    register_setting(
        SA_MOTORLEASE_SETTINGS_OPTION,
        SA_MOTORLEASE_SETTINGS_OPTION,
        [
            'type'              => 'array',
            'sanitize_callback' => 'sa_motorlease_sanitize_settings',
            'default'           => sa_motorlease_default_settings(),
        ]
    );

    add_settings_section(
        'sa_motorlease_section_pace',
        'PACE API',
        function () {
            echo '<p>Endpoint the plugin posts lead and application data to. Toggle posting off to stop reaching the PACE server without disabling the plugin.</p>';
        },
        SA_MOTORLEASE_SETTINGS_SLUG
    );
    add_settings_section(
        'sa_motorlease_section_forms',
        'Gravity Forms',
        function () {
            echo '<p>Which Gravity Forms the plugin watches. The qualify form IDs are the lead-qualification forms (default 1 and 3). The forwarder form ID is the apply-now form (default 5) whose submissions are sent on to PACE.</p>';
        },
        SA_MOTORLEASE_SETTINGS_SLUG
    );
    add_settings_section(
        'sa_motorlease_section_logging',
        'Logging',
        function () {
            echo '<p>Threshold and retention for <code>sa-motorlease.log</code>. Lower numeric levels are more important — ERROR (10) is always written, DEBUG (40) is the loudest.</p>';
        },
        SA_MOTORLEASE_SETTINGS_SLUG
    );
    add_settings_section(
        'sa_motorlease_section_listings',
        'Vehicle Listings',
        function () {
            echo '<p>Front-end vehicle filter / listings options.</p>';
        },
        SA_MOTORLEASE_SETTINGS_SLUG
    );

    $f = function ( $id, $title, $cb, $section ) {
        add_settings_field( $id, $title, $cb, SA_MOTORLEASE_SETTINGS_SLUG, $section );
    };

    $f( 'pace_base_url', 'PACE base URL', 'sa_motorlease_field_pace_base_url',   'sa_motorlease_section_pace' );
    $f( 'pace_enabled',  'Post to PACE',  'sa_motorlease_field_pace_enabled',    'sa_motorlease_section_pace' );

    $f( 'qualify_form_ids',     'Qualify form IDs',  'sa_motorlease_field_qualify_form_ids',     'sa_motorlease_section_forms' );
    $f( 'gf_forwarder_form_id', 'Forwarder form ID', 'sa_motorlease_field_gf_forwarder_form_id', 'sa_motorlease_section_forms' );

    $f( 'log_level',          'Log level',           'sa_motorlease_field_log_level',          'sa_motorlease_section_logging' );
    $f( 'log_retention_days', 'Log retention days',  'sa_motorlease_field_log_retention_days', 'sa_motorlease_section_logging' );

    $f( 'listings_page_id',    'Listings page',       'sa_motorlease_field_listings_page',       'sa_motorlease_section_listings' );
    $f( 'listings_disclaimer', 'Listings disclaimer', 'sa_motorlease_field_listings_disclaimer', 'sa_motorlease_section_listings' );
} );

function sa_motorlease_sanitize_settings( $input ) {
    $defaults = sa_motorlease_default_settings();
    $out      = sa_motorlease_get_settings(); // start from current stored values

    if ( isset( $input['pace_base_url'] ) ) {
        $url = esc_url_raw( trim( (string) $input['pace_base_url'] ) );
        $out['pace_base_url'] = $url !== '' ? untrailingslashit( $url ) : $defaults['pace_base_url'];
    }

    $out['pace_enabled'] = ! empty( $input['pace_enabled'] ) ? 1 : 0;

    if ( isset( $input['qualify_form_ids'] ) ) {
        $ids = array_filter( array_map( 'intval',
            array_map( 'trim', explode( ',', (string) $input['qualify_form_ids'] ) )
        ) );
        $out['qualify_form_ids'] = $ids ? implode( ',', $ids ) : $defaults['qualify_form_ids'];
    }

    if ( isset( $input['gf_forwarder_form_id'] ) ) {
        $out['gf_forwarder_form_id'] = max( 0, (int) $input['gf_forwarder_form_id'] );
    }

    if ( isset( $input['log_level'] ) ) {
        $lvl = (int) $input['log_level'];
        $out['log_level'] = in_array( $lvl, [ 10, 20, 30, 40 ], true ) ? $lvl : $defaults['log_level'];
    }

    if ( isset( $input['log_retention_days'] ) ) {
        $out['log_retention_days'] = max( 1, min( 365, (int) $input['log_retention_days'] ) );
    }

    if ( isset( $input['listings_disclaimer'] ) ) {
        $out['listings_disclaimer'] = wp_kses_post( trim( (string) $input['listings_disclaimer'] ) );
    }

    if ( isset( $input['listings_page_id'] ) ) {
        $out['listings_page_id'] = max( 0, (int) $input['listings_page_id'] );
    }

    return $out;
}

// --- Settings field renderers ----------------------------------------------

function sa_motorlease_field_pace_base_url() {
    $val = esc_attr( sa_motorlease_get_setting( 'pace_base_url' ) );
    printf(
        '<input type="url" class="regular-text code" name="%s[pace_base_url]" value="%s" placeholder="https://paceapp-server.azurewebsites.net">',
        esc_attr( SA_MOTORLEASE_SETTINGS_OPTION ), $val
    );
    echo '<p class="description">No trailing slash. A query string is allowed and will be preserved on every endpoint — e.g. <code>https://paceapp-server.azurewebsites.net?env=test</code> produces calls like <code>.../api/entity/paceWebCreateLead?env=test</code>. Use the preview host (<code>https://paceapp-server-preview.azurewebsites.net</code>) to test changes against the staging API.</p>';
}

function sa_motorlease_field_pace_enabled() {
    $on = (bool) sa_motorlease_get_setting( 'pace_enabled' );
    printf(
        '<label><input type="checkbox" name="%s[pace_enabled]" value="1" %s> Send lead submissions to PACE</label>',
        esc_attr( SA_MOTORLEASE_SETTINGS_OPTION ), checked( $on, true, false )
    );
    echo '<p class="description">Uncheck to stop the <code>/qualify-lead</code> endpoint from posting outbound. Users will see a "temporarily disabled" message.</p>';
}

function sa_motorlease_field_qualify_form_ids() {
    $val = esc_attr( sa_motorlease_get_setting( 'qualify_form_ids' ) );
    printf(
        '<input type="text" class="regular-text code" name="%s[qualify_form_ids]" value="%s" placeholder="1,3">',
        esc_attr( SA_MOTORLEASE_SETTINGS_OPTION ), $val
    );
    echo '<p class="description">Comma-separated Gravity Form IDs that run the lead-qualification JS. The JS itself still maps each form\'s individual input IDs; this list is informational/status-only at the moment.</p>';
}

function sa_motorlease_field_gf_forwarder_form_id() {
    $val = (int) sa_motorlease_get_setting( 'gf_forwarder_form_id' );
    printf(
        '<input type="number" class="small-text" name="%s[gf_forwarder_form_id]" value="%d" min="0" step="1">',
        esc_attr( SA_MOTORLEASE_SETTINGS_OPTION ), $val
    );
    echo '<p class="description">The form whose <code>gform_after_submission_{ID}</code> hook forwards its data to <code>paceWebUpdateLead</code>. Set to 0 to disable forwarding entirely.</p>';
}

function sa_motorlease_field_log_level() {
    $val     = (int) sa_motorlease_get_setting( 'log_level' );
    $choices = [ 10 => 'ERROR', 20 => 'WARN', 30 => 'INFO', 40 => 'DEBUG' ];
    echo '<select name="' . esc_attr( SA_MOTORLEASE_SETTINGS_OPTION ) . '[log_level]">';
    foreach ( $choices as $k => $label ) {
        printf( '<option value="%d" %s>%s (%d)</option>',
            $k, selected( $val, $k, false ), esc_html( $label ), $k );
    }
    echo '</select>';
    echo '<p class="description">Note: the <code>SA_MOTORLEASE_LOG_LEVEL</code> constant defined at the top of the plugin file takes precedence over this setting when present.</p>';
}

function sa_motorlease_field_log_retention_days() {
    $val = (int) sa_motorlease_get_setting( 'log_retention_days' );
    printf(
        '<input type="number" class="small-text" name="%s[log_retention_days]" value="%d" min="1" max="365" step="1"> days',
        esc_attr( SA_MOTORLEASE_SETTINGS_OPTION ), $val
    );
    echo '<p class="description">The daily rotate job trims log lines older than this. The constant <code>SA_MOTORLEASE_LOG_RETENTION_DAYS</code>, if defined, takes precedence.</p>';
}

function sa_motorlease_field_listings_page() {
    $val = (int) sa_motorlease_get_setting( 'listings_page_id' );
    wp_dropdown_pages( [
        'name'              => esc_attr( SA_MOTORLEASE_SETTINGS_OPTION ) . '[listings_page_id]',
        'selected'          => $val,
        'show_option_none'  => '— None —',
        'option_none_value' => 0,
    ] );
    echo '<p class="description">The main vehicles page (where you placed <code>[sa_vehicle_listings]</code>). Used as the "Listings" step in the breadcrumb on the listings page and the location category pages.</p>';
}

function sa_motorlease_field_listings_disclaimer() {
    $val = (string) sa_motorlease_get_setting( 'listings_disclaimer' );
    printf(
        '<textarea class="large-text" rows="5" name="%s[listings_disclaimer]">%s</textarea>',
        esc_attr( SA_MOTORLEASE_SETTINGS_OPTION ), esc_textarea( $val )
    );
    echo '<p class="description">Shown beneath the title on the vehicle listings page and the location category pages. Place it anywhere with the <code>[sa_listings_disclaimer]</code> shortcode. Basic HTML is allowed; leave blank to hide it.</p>';
}

// --- Settings page renderer -------------------------------------------------

function sa_motorlease_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    ?>
    <div class="wrap">
        <h1>SA Motorlease — Settings</h1>
        <form action="options.php" method="post">
            <?php
            settings_fields( SA_MOTORLEASE_SETTINGS_OPTION );
            do_settings_sections( SA_MOTORLEASE_SETTINGS_SLUG );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// --- Status page renderer ---------------------------------------------------

function sa_motorlease_render_status_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    global $wpdb;
    $settings   = sa_motorlease_get_settings();
    $log_path   = sa_motorlease_log_path();
    $log_size   = file_exists( $log_path ) ? size_format( filesize( $log_path ) ) : '—';
    $log_mtime  = file_exists( $log_path ) ? date_i18n( 'Y-m-d H:i:s', filemtime( $log_path ) ) : '—';

    $cron_hooks = [
        'sa_motorlease_log_rotate_daily'   => 'Log rotation (daily)',
        'vi_create_hourly_event'           => 'Vehicle create (hourly)',
        'vi_update_hourly_event'           => 'Vehicle update (hourly)',
        'vehicle_images_repair_event'      => 'Image repair (hourly)',
        'vi_image_sync_cron'               => 'Image sync (5 min)',
        'wbw_custom_index_cron'            => 'WBW reindex',
        'set_sold_date_cron_event'         => 'Set sold date (daily)',
        'delete_expired_sold_products_event'=> 'Delete expired sold (daily)',
        'fix_and_replace_broken_images'    => 'Fix broken images',
        'update_number_of_payments_attribute' => 'Update # of payments',
    ];

    $lead_table  = $wpdb->prefix . 'lead_qualifications';
    $lead_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lead_table ) ) === $lead_table;
    $lead_count  = $lead_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$lead_table`" ) : 0;
    $lead_latest = $lead_exists ? $wpdb->get_row( "SELECT lead_id, state, created_at FROM `$lead_table` ORDER BY id DESC LIMIT 1" ) : null;

    // Tail the log (last ~50 lines) without loading the entire file.
    $log_tail = '';
    if ( file_exists( $log_path ) && filesize( $log_path ) > 0 ) {
        $log_tail = sa_motorlease_tail_file( $log_path, 50 );
    }

    // Lead endpoint activity — same log file, filtered to the three channels
    // that carry user submissions. We grab a larger tail and filter in PHP so
    // even quiet periods on lead traffic still produce a useful read.
    $lead_channels = [ 'qualify-lead', 'partial-save', 'gf5-forward' ];
    $lead_tail     = '';
    $lead_counts   = [ 'ERROR' => 0, 'WARN' => 0, 'INFO' => 0 ];
    if ( file_exists( $log_path ) && filesize( $log_path ) > 0 ) {
        // 1 MB cap on the bytes we read for the channel-filter view. Plenty
        // for thousands of normal log lines; a hard ceiling against tail
        // having to allocate a 50+ MB buffer when WARN entries are fat.
        $bigger    = sa_motorlease_tail_file( $log_path, 2000, 1048576 );
        $matched   = [];
        foreach ( preg_split( '/\r?\n/', $bigger ) as $line ) {
            if ( $line === '' ) continue;
            $is_lead = false;
            foreach ( $lead_channels as $ch ) {
                if ( strpos( $line, '[' . $ch . ']' ) !== false ) { $is_lead = true; break; }
            }
            if ( ! $is_lead ) continue;
            $matched[] = $line;
            if ( strpos( $line, '[ERROR]' ) !== false )      $lead_counts['ERROR']++;
            elseif ( strpos( $line, '[WARN]' ) !== false )   $lead_counts['WARN']++;
            elseif ( strpos( $line, '[INFO]' ) !== false )   $lead_counts['INFO']++;
        }
        $lead_tail = implode( "\n", array_slice( $matched, -50 ) );
    }

    $pace_base = sa_motorlease_pace_base_url();
    ?>
    <div class="wrap">
        <h1>SA Motorlease — Status</h1>

        <h2 class="title">Environment</h2>
        <table class="widefat striped" style="max-width:900px">
            <tbody>
                <tr><th style="width:240px">Plugin version</th><td><code><?php echo esc_html( SA_MOTORLEASE_VERSION ); ?></code></td></tr>
                <tr><th>WordPress</th><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
                <tr><th>PHP</th><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
                <tr><th>WooCommerce</th><td><?php echo class_exists( 'WooCommerce' ) ? esc_html( WC()->version ) : 'not active'; ?></td></tr>
                <tr><th>Gravity Forms</th><td><?php
                    if ( class_exists( 'GFForms' ) && property_exists( 'GFForms', 'version' ) ) {
                        echo esc_html( GFForms::$version );
                    } elseif ( class_exists( 'GFCommon' ) && property_exists( 'GFCommon', 'version' ) ) {
                        echo esc_html( GFCommon::$version );
                    } else {
                        echo class_exists( 'GFForms' ) ? 'active' : 'not active';
                    }
                ?></td></tr>
            </tbody>
        </table>

        <h2 class="title">Effective configuration</h2>
        <table class="widefat striped" style="max-width:900px">
            <tbody>
                <tr><th style="width:240px">PACE base URL</th><td><code><?php echo esc_html( $pace_base ); ?></code></td></tr>
                <tr><th>Example endpoint</th><td><code><?php echo esc_html( sa_motorlease_pace_url( '/api/entity/paceWebCreateLead' ) ); ?></code></td></tr>
                <tr><th>PACE posting</th><td><?php echo sa_motorlease_pace_posting_enabled() ? '<span style="color:#137333">enabled</span>' : '<span style="color:#b91c1c">disabled</span>'; ?></td></tr>
                <tr><th>Qualify form IDs</th><td><code><?php echo esc_html( $settings['qualify_form_ids'] ); ?></code></td></tr>
                <tr><th>Forwarder form ID</th><td><code><?php echo (int) $settings['gf_forwarder_form_id']; ?></code> (hook: <code>gform_after_submission_<?php echo (int) $settings['gf_forwarder_form_id']; ?></code>)</td></tr>
                <tr><th>Log level</th><td><?php echo (int) SA_MOTORLEASE_LOG_LEVEL; ?> (constant) / <?php echo (int) $settings['log_level']; ?> (setting)</td></tr>
                <tr><th>Log retention</th><td><?php echo (int) SA_MOTORLEASE_LOG_RETENTION_DAYS; ?> days (constant) / <?php echo (int) $settings['log_retention_days']; ?> days (setting)</td></tr>
            </tbody>
        </table>

        <h2 class="title">Lead qualifications</h2>
        <table class="widefat striped" style="max-width:900px">
            <tbody>
                <tr><th style="width:240px">Table</th><td><code><?php echo esc_html( $lead_table ); ?></code> <?php echo $lead_exists ? '' : '<span style="color:#b91c1c">(missing)</span>'; ?></td></tr>
                <tr><th>Rows</th><td><?php echo number_format_i18n( $lead_count ); ?></td></tr>
                <?php if ( $lead_latest ) : ?>
                    <tr><th>Latest lead</th><td>#<?php echo (int) $lead_latest->lead_id; ?> · state <code><?php echo esc_html( $lead_latest->state ); ?></code> · <?php echo esc_html( $lead_latest->created_at ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h2 class="title">Scheduled events</h2>
        <table class="widefat striped" style="max-width:900px">
            <thead><tr><th>Hook</th><th>Description</th><th>Next run</th></tr></thead>
            <tbody>
            <?php foreach ( $cron_hooks as $hook => $label ) :
                $ts = wp_next_scheduled( $hook );
                $when = $ts ? date_i18n( 'Y-m-d H:i:s', $ts ) . ' (' . human_time_diff( time(), $ts ) . ')' : '<em>not scheduled</em>';
            ?>
                <tr>
                    <td><code><?php echo esc_html( $hook ); ?></code></td>
                    <td><?php echo esc_html( $label ); ?></td>
                    <td><?php echo wp_kses_post( $when ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2 class="title">Lead endpoint activity</h2>
        <p>
            Filtered tail of <code>sa-motorlease.log</code> for channels
            <code>[qualify-lead]</code>, <code>[partial-save]</code>, and
            <code>[gf5-forward]</code>.
            Recent counts (last 2,000 log lines):
            <strong style="color:#b91c1c"><?php echo (int) $lead_counts['ERROR']; ?> error</strong>,
            <strong style="color:#a16207"><?php echo (int) $lead_counts['WARN']; ?> warn</strong>,
            <strong style="color:#137333"><?php echo (int) $lead_counts['INFO']; ?> ok</strong>.
            Successful submissions log a masked summary; warnings and errors include the full request/response so you can debug what was actually sent.
        </p>
        <?php if ( $lead_tail !== '' ) : ?>
            <pre style="background:#0d1117;color:#c9d1d9;padding:14px;border-radius:6px;max-height:420px;overflow:auto;font-size:12px;line-height:1.5;"><?php echo esc_html( $lead_tail ); ?></pre>
        <?php else : ?>
            <p><em>No lead endpoint activity in the recent log window.</em></p>
        <?php endif; ?>

        <h2 class="title">Log file</h2>
        <p>
            <code><?php echo esc_html( $log_path ); ?></code>
            — size <strong><?php echo esc_html( $log_size ); ?></strong>,
            last write <strong><?php echo esc_html( $log_mtime ); ?></strong>
        </p>
        <?php if ( $log_tail !== '' ) : ?>
            <pre style="background:#0d1117;color:#c9d1d9;padding:14px;border-radius:6px;max-height:360px;overflow:auto;font-size:12px;line-height:1.5;"><?php echo esc_html( $log_tail ); ?></pre>
        <?php else : ?>
            <p><em>Log file is empty or not yet written.</em></p>
        <?php endif; ?>

        <h2 class="title">Tools</h2>
        <p style="color:#666;font-size:13px;">All links below include a security token and expire after 1 day. Do not share these URLs.</p>
        <?php
        $tools = [
            // [ label, query_arg, description, dangerous, new_tab ]
            [ 'Run import now (create+update)',  'vi_run_import',                  'Run create + update import in-process. Bypasses WP-Cron loopback. Tail sa-motorlease.log for output.', false, true  ],
            [ 'Image sync (progress UI)',        'vi_sync_images',                 'Open the live image-sync progress page (runs in your browser tab).',   false, true  ],
            [ 'Image repair (progress UI)',      'vi_repair_images',               'Open the image-repair progress page (runs in your browser tab).',      false, true  ],
            [ 'Cleanup sold products',          'cleanup_sold_products',          'Mark in-feed sold vehicles and clean up sold status.',                  false, false ],
            [ 'Update sold status from feed',   'update_sold_from_feed',          'Re-sync sold/available status against the live PACE feed.',             false, false ],
            [ 'Log missing products',           'log_missing_products',           'Write a log of products absent from the feed (no writes).',             false, false ],
            [ 'Remove missing products',        'remove_missing_products',        'Delete WooCommerce products that are no longer in the PACE feed.',      true,  false ],
            [ 'Remove products by reg',         'remove_by_reg_cleanup',          'Strip registration-number attributes from products.',                   false, false ],
            [ 'Format deposit special',         'format_deposit_special',         'Re-format the Initiation Fee Special attribute values.',                false, false ],
            [ 'Cleanup initiation fee special', 'cleanup_initiation_fee_special', 'Remove Initiation Fee Special attributes from products.',               false, false ],
            [ 'Fix broken images',              'fix_broken_images',              'Trigger the image-repair cron immediately.',                             false, false ],
            [ 'Backfill image ALT text',        'backfill_image_alt',             'Set ALT text on all vehicle images that are missing it.',                false, false ],
            [ 'Payments backfill',              'run_payments_backfill',          'Schedule the Number of Payments attribute backfill cron.',              false, false ],
            [ 'WBW cleanup ghosts',             'wbw_cleanup_ghosts',             'Remove WBW index rows for products that no longer exist.',              false, false ],
            [ 'Deduplicate products',           'deduplicate_products',           'Remove duplicate WooCommerce products with the same SKU.',              true,  false ],
            [ 'Update license plates',          'update_license_plates',          'Backfill license plate attributes from the PACE feed.',                 false, false ],
        ];
        ?>
        <table class="widefat striped" style="max-width:900px">
            <thead><tr><th style="width:220px">Action</th><th>Description</th><th style="width:80px"></th></tr></thead>
            <tbody>
            <?php foreach ( $tools as [ $label, $param, $desc, $danger, $new_tab ] ) :
                $url = wp_nonce_url( add_query_arg( $param, '1', admin_url() ), 'sa_motorlease_admin_action' );
            ?>
                <tr>
                    <td><strong><?php echo esc_html( $label ); ?></strong></td>
                    <td style="font-size:13px;color:#555"><?php echo esc_html( $desc ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( $url ); ?>"
                           class="button <?php echo $danger ? 'button-primary' : 'button-secondary'; ?>"
                           <?php if ( $new_tab ) : ?>target="_blank" rel="noopener"<?php endif; ?>
                           <?php if ( $danger ) : ?>
                           onclick="return confirm(<?php echo wp_json_encode( 'Run "' . $label . '"? This cannot be undone.' ); ?>)"
                           <?php endif; ?>>
                            <?php echo $new_tab ? 'Open' : 'Run'; ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Return the last $lines lines of a file, reading at most $max_bytes from the
 * end in a single fread. The byte cap is the safety net — large log files
 * with very long lines (e.g. WARN entries that dump full request payloads)
 * could otherwise force a line-count-driven tail to read tens of MB and OOM
 * the Status page render.
 */
function sa_motorlease_tail_file( $file, $lines = 50, $max_bytes = 262144 ) {
    $fh = @fopen( $file, 'rb' );
    if ( ! $fh ) return '';

    fseek( $fh, 0, SEEK_END );
    $filesize = ftell( $fh );
    if ( $filesize === 0 ) { fclose( $fh ); return ''; }

    $read = (int) min( $filesize, max( 1024, $max_bytes ) );
    fseek( $fh, -$read, SEEK_END );
    $buffer = fread( $fh, $read );
    fclose( $fh );
    if ( $buffer === false || $buffer === '' ) return '';

    // If we didn't read from the start of the file, drop the (likely partial)
    // first line so we don't return a fragment.
    if ( $read < $filesize ) {
        $nl = strpos( $buffer, "\n" );
        if ( $nl !== false ) $buffer = substr( $buffer, $nl + 1 );
    }

    $out = explode( "\n", $buffer );
    if ( count( $out ) > $lines ) $out = array_slice( $out, -$lines );
    return implode( "\n", $out );
}
