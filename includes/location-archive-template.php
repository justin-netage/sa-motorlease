<?php
/**
 * Template for WooCommerce location (product_cat) archives, e.g.
 * /listings/gauteng/sandton/. Rendered via the template_include filter in
 * vehicle-filter.php — shows the custom vehicle filter locked to the current
 * province/area, inside the active theme's header/footer.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$term    = get_queried_object();
$term_id = ( $term && ! is_wp_error( $term ) && isset( $term->term_id ) ) ? (int) $term->term_id : 0;

get_header();
?>
<div class="sa-vf-archive">
    <?php
    if ( $term_id ) {
        $title = single_term_title( '', false );

        // Include the parent province for area pages, for a clearer heading.
        $suffix = '';
        if ( ! empty( $term->parent ) ) {
            $parent = get_term( $term->parent, 'product_cat' );
            if ( $parent && ! is_wp_error( $parent ) ) {
                $suffix = ', ' . $parent->name;
            }
        }

        // Our own featured strip, scoped to this location.
        echo sa_vf_render_featured( $term_id, 8, 'Featured Listings' ); // phpcs:ignore WordPress.Security.EscapeOutput

        echo '<h1 class="sa-vf-archive__title">Vehicles in ' . esc_html( $title . $suffix ) . '</h1>';

        // Keep the category's intro text/disclaimer, but strip any embedded
        // shortcodes (old carousel / WBW "Filter Vehicles" button) so they
        // don't reappear inside our takeover.
        $desc = strip_shortcodes( term_description( $term_id, 'product_cat' ) );
        if ( trim( wp_strip_all_tags( $desc ) ) !== '' ) {
            echo '<div class="sa-vf-archive__desc">' . wp_kses_post( $desc ) . '</div>';
        }

        echo do_shortcode( '[sa_vehicle_filter category="' . $term_id . '"]' );
    } else {
        echo do_shortcode( '[sa_vehicle_filter]' );
    }
    ?>
</div>
<?php
get_footer();
