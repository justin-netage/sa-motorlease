<?php
/**
 * Markup for the [sa_vehicle_filter] shortcode.
 *
 * In scope: $initial (parsed args), $result (initial query result), $atts.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$facets = sa_vf_facets();
$sel    = $initial['facets'];
?>
<div class="sa-vf">

    <button type="button" class="sa-vf-toggle" aria-expanded="false" aria-controls="sa-vf-sidebar">
        <span class="sa-vf-toggle__icon">&#9776;</span> Filters
        <span class="sa-vf-toggle__count" hidden>0</span>
    </button>

    <div class="sa-vf__layout">

        <aside class="sa-vf__sidebar" id="sa-vf-sidebar">
            <div class="sa-vf__drawer-head">
                <span class="sa-vf__drawer-title">Filters</span>
                <button type="button" class="sa-vf__drawer-close" aria-label="Close filters">&times;</button>
            </div>
            <div class="sa-vf__sidebar-scroll">
            <h2 class="sa-vf__heading">Filter Options</h2>
            <form class="sa-vf-form" onsubmit="return false;">

                <?php foreach ( $facets as $facet ) :
                    $k = $facet['key'];

                    // On a category page the Region field becomes a location
                    // navigator: choosing a region redirects to that archive
                    // (scoped to the current province) instead of filtering.
                    if ( $k === 'region' && ! empty( $region_nav ) ) : ?>
                        <div class="sa-vf-field">
                            <select class="sa-vf-select" data-region-nav="1" aria-label="Region">
                                <?php foreach ( $region_nav['options'] as $opt ) : ?>
                                    <option value="<?php echo esc_url( $opt['url'] ); ?>" <?php selected( $region_nav['current'], $opt['id'] ); ?>>
                                        <?php echo esc_html( $opt['name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php continue;
                    endif;

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

                <?php // Monthly payment buckets ?>
                <div class="sa-vf-field sa-vf-field--price">
                    <span class="sa-vf-price__legend">Monthly Payment</span>
                    <div class="sa-vf-price" role="radiogroup" aria-label="Monthly Payment">
                        <label class="sa-vf-radio">
                            <input type="radio" name="price" value="" <?php checked( $initial['price'], '' ); ?>>
                            <span>Any</span>
                        </label>
                        <?php foreach ( sa_vf_price_buckets() as $b ) : ?>
                            <label class="sa-vf-radio">
                                <input type="radio" name="price" value="<?php echo esc_attr( $b['key'] ); ?>" <?php checked( $initial['price'], $b['key'] ); ?>>
                                <span><?php echo esc_html( $b['label'] ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php // Availability — "Available Only" is on by default; unchecking shows sold vehicles ?>
                <div class="sa-vf-field sa-vf-field--check">
                    <label class="sa-vf-check">
                        <input type="checkbox" name="available_only" value="1" <?php checked( $initial['hide_sold'] ); ?>>
                        <span>Available Only</span>
                    </label>
                    <p class="sa-vf-check__hint">Uncheck to show sold vehicles</p>
                </div>

                <div class="sa-vf-actions">
                    <button type="button" class="sa-vf-btn sa-vf-btn--filter">Filter</button>
                    <button type="button" class="sa-vf-btn sa-vf-btn--clear">Clear</button>
                </div>
            </form>
            </div><?php // .sa-vf__sidebar-scroll ?>
            <div class="sa-vf__drawer-foot">
                <button type="button" class="sa-vf-btn sa-vf-btn--clear sa-vf__drawer-clear">Clear</button>
                <button type="button" class="sa-vf-btn sa-vf__drawer-apply">Show results</button>
            </div>
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

            <div class="sa-vf__loading" hidden>
                <div class="sa-vf__loading-inner">
                    <span class="sa-vf__spinner"></span>
                    <span>Loading vehicles…</span>
                </div>
            </div>
        </section>

    </div>
</div>
