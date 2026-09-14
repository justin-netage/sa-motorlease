<?php
/**
 * Redirect dead vehicle URLs to live stock instead of serving a 404.
 *
 * Vehicles are hard-deleted the moment they leave the PACE feed (the prune
 * pass) or a week after they sell (the daily expiry cron). Nothing about that
 * is announced to anyone holding the old URL: Google keeps the page indexed
 * for weeks, visitors share vehicle links on WhatsApp, browsers keep history,
 * and an anonymous visitor can be served a cached listing page rendered before
 * the deletion. Every one of those landed on the theme's generic 404.
 *
 * This turns any 404 under the product permalink base into a 301 to the
 * listings page — narrowed to the vehicle's make when the slug names one that
 * still has stock — so the visitor lands on comparable vehicles and search
 * engines are told to drop the dead URL.
 *
 * Only genuine 404s are touched: anything WordPress resolves (a live product,
 * a page, an archive) never reaches this. The redirect is sent with no-cache
 * headers so neither the browser nor the edge cache pins it: a slug without a
 * vehicle-id suffix can be reissued to a new vehicle later, and a permanently
 * cached 301 would then shadow a live page.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Product permalink base ("listing"), from WooCommerce when it is loaded. */
function sa_motorlease_product_base_slug() {
    $slug = 'listing';
    if ( function_exists( 'wc_get_permalink_structure' ) ) {
        $s = wc_get_permalink_structure();
        if ( ! empty( $s['product_rewrite_slug'] ) ) {
            $slug = (string) $s['product_rewrite_slug'];
        }
    }
    // The base may carry a %product_cat% placeholder; only its first segment
    // is a literal.
    $slug = trim( $slug, '/' );
    $slug = explode( '/', $slug )[0];
    return $slug !== '' ? $slug : 'listing';
}

/**
 * The listings page URL: the page chosen in settings, else the page at slug
 * "listings", else the WooCommerce shop page, else home. Same resolution the
 * breadcrumb shortcode uses, so the two never disagree about where "Listings"
 * is.
 */
function sa_motorlease_listings_url() {
    $lp = function_exists( 'sa_motorlease_get_setting' ) ? (int) sa_motorlease_get_setting( 'listings_page_id', 0 ) : 0;
    if ( ! $lp ) {
        $auto = get_page_by_path( 'listings' );
        if ( $auto ) $lp = (int) $auto->ID;
    }
    if ( $lp && get_post_status( $lp ) === 'publish' ) {
        return get_permalink( $lp );
    }
    if ( function_exists( 'wc_get_page_permalink' ) ) {
        $shop = wc_get_page_permalink( 'shop' );
        if ( $shop ) return $shop;
    }
    return home_url( '/' );
}

/**
 * The pa_make term slug a vehicle slug names, or '' when none matches.
 *
 * Vehicle slugs are sanitize_title() of the PACE description plus the vehicle
 * id — "2025-citroen-c3-1-2-plus-5mt-23389" — so the make is somewhere in the
 * hyphenated run, not at a fixed position. Match every make that still has
 * live stock as a whole hyphen-delimited sequence and keep the longest, so
 * "land-rover" beats "rover" and "mercedes-benz" beats a bare "benz".
 */
function sa_motorlease_make_from_vehicle_slug( $slug ) {
    if ( ! taxonomy_exists( 'pa_make' ) ) return '';

    $terms = get_terms( [ 'taxonomy' => 'pa_make', 'hide_empty' => true, 'fields' => 'slugs' ] );
    if ( ! is_array( $terms ) || empty( $terms ) ) return '';

    $best = '';
    foreach ( $terms as $term_slug ) {
        $term_slug = (string) $term_slug;
        if ( $term_slug === '' || strlen( $term_slug ) <= strlen( $best ) ) continue;
        if ( preg_match( '/(^|-)' . preg_quote( $term_slug, '/' ) . '(-|$)/', $slug ) ) {
            $best = $term_slug;
        }
    }
    return $best;
}

add_action( 'template_redirect', function () {
    if ( ! is_404() ) return;

    $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    $path = (string) wp_parse_url( $uri, PHP_URL_PATH );
    if ( $path === '' ) return;

    // Strip a subdirectory install's base path so the match is site-relative.
    $home_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
    $rel = trim( $path, '/' );
    if ( $home_path !== '' && strpos( $rel, $home_path . '/' ) === 0 ) {
        $rel = substr( $rel, strlen( $home_path ) + 1 );
    }

    $base = sa_motorlease_product_base_slug();
    if ( ! preg_match( '#^' . preg_quote( $base, '#' ) . '/(.+)$#', $rel, $m ) ) return;

    // The vehicle slug is the last segment; anything before it is the base
    // (possibly with a %product_cat% expansion).
    $segments = array_values( array_filter( explode( '/', $m[1] ), 'strlen' ) );
    if ( empty( $segments ) ) return;
    $slug = sanitize_title( end( $segments ) );

    $target = sa_motorlease_listings_url();
    $make   = sa_motorlease_make_from_vehicle_slug( $slug );
    if ( $make !== '' ) {
        $target = add_query_arg( 'make', $make, $target );
    }

    // Never loop: if the listings URL somehow resolves back here, leave the 404.
    if ( trim( (string) wp_parse_url( $target, PHP_URL_PATH ), '/' ) === trim( $path, '/' ) ) return;

    nocache_headers();
    wp_safe_redirect( $target, 301 );
    exit;
}, 5 );
