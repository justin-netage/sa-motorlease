<?php
/**
 * NEW badge on the single product page.
 *
 * The listing cards (vehicle-filter.php, sa_vf_render_card) badge new
 * vehicles; this is the same badge on the vehicle's own page. The theme's
 * product-image.php override prints its Sold label just inside the
 * `.product-images` box and then fires `flatsome_sale_flash`, so the badge is
 * hooked there — no template edit needed, and it lands in the same positioned
 * container the Sold label uses.
 *
 * Same rule as the cards: at most one badge, SOLD wins. The theme prints SOLD;
 * this only prints NEW when the vehicle is not sold.
 *
 * The CSS mirrors the theme's `.sold-label` (Customizer custom CSS) so the two
 * badges sit identically, and is printed inline on product pages only — a few
 * lines are not worth a request.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Whether the product carries the "new" term of the New-or-Used attribute. */
function sa_motorlease_product_is_new( $product_id ) {
    if ( ! taxonomy_exists( 'pa_new-or-used' ) ) return false;
    $slugs = wc_get_product_terms( $product_id, 'pa_new-or-used', [ 'fields' => 'slugs' ] );
    return is_array( $slugs ) && in_array( 'new', $slugs, true );
}

/** Whether the product is flagged sold (pa_sold = Yes), as the theme checks it. */
function sa_motorlease_product_is_sold( $product_id ) {
    if ( ! taxonomy_exists( 'pa_sold' ) ) return false;
    $names = wc_get_product_terms( $product_id, 'pa_sold', [ 'fields' => 'names' ] );
    return is_array( $names ) && in_array( 'Yes', $names, true );
}

add_action( 'flatsome_sale_flash', function () {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) return;
    $id = get_the_ID();
    if ( ! $id || sa_motorlease_product_is_sold( $id ) || ! sa_motorlease_product_is_new( $id ) ) return;
    echo '<span class="new-label">New</span>';
}, 5 );

add_action( 'wp_head', function () {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) return;
    ?>
<style id="sa-motorlease-new-label">
.product-images .new-label {
    position: absolute;
    top: 16px;
    right: 0;
    background-color: #003b65;
    color: #fff;
    padding: 5px 10px;
    font-weight: 900;
    font-size: 0.9rem;
    border-radius: 4px 0 0 4px;
    z-index: 22;
    text-transform: uppercase;
}
</style>
    <?php
} );
