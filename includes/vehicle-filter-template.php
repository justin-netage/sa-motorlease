<?php
/**
 * Markup for the [sa_vehicle_filter] shortcode.
 *
 * In scope: $initial (parsed args), $result (initial query result),
 *           $bounds (price min/max), $atts.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$facets     = sa_vf_facets();
$sel        = $initial['facets'];
$price_min  = $initial['price_min'] !== null ? $initial['price_min'] : $bounds['min'];
$price_max  = $initial['price_max'] !== null ? $initial['price_max'] : $bounds['max'];
?>
<div class="sa-vf" data-price-min="<?php echo esc_attr( $bounds['min'] ); ?>" data-price-max="<?php echo esc_attr( $bounds['max'] ); ?>">

    <button type="button" class="sa-vf-toggle" aria-expanded="false">
        <span class="sa-vf-toggle__icon">&#9776;</span> Filter Vehicles
    </button>

    <div class="sa-vf__layout">

        <aside class="sa-vf__sidebar" id="sa-vf-sidebar">
            <h2 class="sa-vf__heading">Filter Options</h2>
            <form class="sa-vf-form" onsubmit="return false;">

                <?php foreach ( $facets as $facet ) :
                    $k     = $facet['key'];
                    $terms = ( $k === 'year' ) ? sa_vf_year_terms() : sa_vf_terms( $facet['tax'] );
                    if ( ! $terms ) continue;
                    $current = $sel[ $k ] ?? '';
                    ?>
                    <div class="sa-vf-field">
                        <select class="sa-vf-select" name="<?php echo esc_attr( $k ); ?>" data-facet="<?php echo esc_attr( $k ); ?>">
                            <option value=""><?php echo esc_html( $facet['label'] ); ?></option>
                            <?php foreach ( $terms as $t ) : ?>
                                <option value="<?php echo esc_attr( $t['slug'] ); ?>" <?php selected( $current, $t['slug'] ); ?>>
                                    <?php echo esc_html( $t['name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>

                <?php // Mileage buckets ?>
                <div class="sa-vf-field">
                    <select class="sa-vf-select" name="km" data-facet="km">
                        <option value="">Kilometers</option>
                        <?php foreach ( sa_vf_km_buckets() as $b ) : ?>
                            <option value="<?php echo esc_attr( $b['key'] ); ?>" <?php selected( $initial['km'], $b['key'] ); ?>>
                                <?php echo esc_html( $b['label'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php // Monthly price range ?>
                <div class="sa-vf-field sa-vf-field--price">
                    <div class="sa-vf-range" data-min="<?php echo esc_attr( $bounds['min'] ); ?>" data-max="<?php echo esc_attr( $bounds['max'] ); ?>">
                        <div class="sa-vf-range__track"><div class="sa-vf-range__fill"></div></div>
                        <input type="range" class="sa-vf-range__input sa-vf-range__min"
                               min="<?php echo esc_attr( $bounds['min'] ); ?>" max="<?php echo esc_attr( $bounds['max'] ); ?>"
                               value="<?php echo esc_attr( $price_min ); ?>" step="1">
                        <input type="range" class="sa-vf-range__input sa-vf-range__max"
                               min="<?php echo esc_attr( $bounds['min'] ); ?>" max="<?php echo esc_attr( $bounds['max'] ); ?>"
                               value="<?php echo esc_attr( $price_max ); ?>" step="1">
                    </div>
                    <div class="sa-vf-range__labels">
                        <span>R <span class="sa-vf-range__minval"><?php echo esc_html( $price_min ); ?></span></span>
                        <span class="sa-vf-range__dash">–</span>
                        <span class="sa-vf-range__maxval"><?php echo esc_html( $price_max ); ?></span>
                    </div>
                    <div class="sa-vf-range__unit">per month</div>
                    <input type="hidden" name="price_min" value="<?php echo esc_attr( $price_min ); ?>">
                    <input type="hidden" name="price_max" value="<?php echo esc_attr( $price_max ); ?>">
                </div>

                <?php // Hide sold ?>
                <div class="sa-vf-field sa-vf-field--check">
                    <label class="sa-vf-check">
                        <input type="checkbox" name="hide_sold" value="1" <?php checked( $initial['hide_sold'] ); ?>>
                        <span>Hide sold vehicles</span>
                    </label>
                </div>

                <div class="sa-vf-actions">
                    <button type="button" class="sa-vf-btn sa-vf-btn--filter">Filter</button>
                    <button type="button" class="sa-vf-btn sa-vf-btn--clear">Clear</button>
                </div>
            </form>
        </aside>

        <section class="sa-vf__results">
            <div class="sa-vf__topbar">
                <span class="sa-vf__count"><?php echo esc_html( $result['showing'] ); ?></span>
                <label class="sa-vf__sortwrap">
                    <span>Sort By</span>
                    <select class="sa-vf-select sa-vf__sort" name="sort">
                        <?php foreach ( sa_vf_sort_options() as $val => $label ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $initial['sort'], $val ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="sa-vf__grid" aria-live="polite"><?php echo $result['html']; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>

            <div class="sa-vf__pager" data-page="<?php echo esc_attr( $result['page'] ); ?>" data-pages="<?php echo esc_attr( $result['pages'] ); ?>"></div>

            <div class="sa-vf__loading" hidden><span class="sa-vf__spinner"></span> Loading…</div>
        </section>

    </div>
</div>
