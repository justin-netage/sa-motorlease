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
    // Child (area) → "Vehicles - Sandton"; parent (province) → "Gauteng".
    $title    = $term->parent ? 'Vehicles - ' . $term->name : $term->name;
    $subtitle = sa_vf_location_subtitle( $term );

    echo sa_vf_render_listings( $term_id, $title, $subtitle, [ // phpcs:ignore WordPress.Security.EscapeOutput
        'featured'    => true,
        'breadcrumbs' => true,
        'disclaimer'  => true,
    ] );
} else {
    echo do_shortcode( '[sa_vehicle_listings]' );
}

get_footer();
