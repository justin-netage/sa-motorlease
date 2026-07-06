<?php
/**
 * Template for WooCommerce location (product_cat) archives, e.g.
 * /listings/gauteng/sandton/. Rendered via the template_include filter in
 * vehicle-filter.php — shows the custom vehicle filter locked to the current
 * province/area, inside the active theme's header/footer.
 *
 * Uses the same sa_vf_render_listings() composition as the [sa_vehicle_listings]
 * shortcode, so the main listings page and the location archives are identical.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$term    = get_queried_object();
$term_id = ( $term && ! is_wp_error( $term ) && isset( $term->term_id ) ) ? (int) $term->term_id : 0;

get_header();

if ( $term_id ) {
    $title  = single_term_title( '', false );

    // Include the parent province for area pages, for a clearer heading.
    $suffix = '';
    if ( ! empty( $term->parent ) ) {
        $parent = get_term( $term->parent, 'product_cat' );
        if ( $parent && ! is_wp_error( $parent ) ) {
            $suffix = ', ' . $parent->name;
        }
    }

    echo sa_vf_render_listings( $term_id, 'Vehicles in ' . $title . $suffix, [ // phpcs:ignore WordPress.Security.EscapeOutput
        'featured'    => true,
        'breadcrumbs' => true,
        'disclaimer'  => true,
        'intro'       => true,
    ] );
} else {
    echo do_shortcode( '[sa_vehicle_listings]' );
}

get_footer();
