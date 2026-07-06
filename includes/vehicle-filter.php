<?php
/**
 * SA Motorlease — Custom Vehicle Filter
 * ---------------------------------------------------------------------------
 * A self-contained replacement for the WBW / WooBeWoo Product Filter used on
 * the vehicles archive. Renders a filter sidebar + AJAX-driven results grid via
 * the [sa_vehicle_filter] shortcode, querying the pa_* attribute taxonomies and
 * _price meta the PaceApp importer already writes.
 *
 * Nothing here touches the WBW indexing code — the two can run side by side
 * while the site is switched over.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'SA_VF_VERSION' ) ) {
    // Bump to bust the browser cache when editing the JS/CSS.
    define( 'SA_VF_VERSION', '1.0.7' );
}

/**
 * Facet definition. Order here is the order rendered in the sidebar.
 * `key`   – request/query-var key
 * `label` – dropdown placeholder
 * `tax`   – WooCommerce attribute taxonomy backing the facet
 */
function sa_vf_facets() {
    return [
        [ 'key' => 'condition',    'label' => 'New or Used',  'tax' => 'pa_new-or-used' ],
        [ 'key' => 'make',         'label' => 'Make',         'tax' => 'pa_make' ],
        [ 'key' => 'model',        'label' => 'Model',        'tax' => 'pa_model' ],
        [ 'key' => 'region',       'label' => 'Region',       'tax' => 'pa_region' ],
        [ 'key' => 'transmission', 'label' => 'Transmission', 'tax' => 'pa_transmission' ],
        [ 'key' => 'body_type',    'label' => 'Body Type',    'tax' => 'pa_vehicle-type' ],
        [ 'key' => 'fuel',         'label' => 'Fuel',         'tax' => 'pa_fuel' ],
        [ 'key' => 'year',         'label' => 'Year Model',   'tax' => 'pa_vehicle-year' ],
    ];
}

/** Mileage buckets. Upper bound is exclusive. */
function sa_vf_km_buckets() {
    return [
        [ 'key' => '0-25000',      'label' => 'Under 25 000 km',      'min' => 0,      'max' => 25000 ],
        [ 'key' => '25000-50000',  'label' => '25 000 – 50 000 km',   'min' => 25000,  'max' => 50000 ],
        [ 'key' => '50000-75000',  'label' => '50 000 – 75 000 km',   'min' => 50000,  'max' => 75000 ],
        [ 'key' => '75000-100000', 'label' => '75 000 – 100 000 km',  'min' => 75000,  'max' => 100000 ],
        [ 'key' => '100000-150000','label' => '100 000 – 150 000 km', 'min' => 100000, 'max' => 150000 ],
        [ 'key' => '150000-',      'label' => 'Over 150 000 km',      'min' => 150000, 'max' => PHP_INT_MAX ],
    ];
}

/** Sort options: key => human label. */
function sa_vf_sort_options() {
    return [
        'featured'   => 'Featured',
        'price_asc'  => 'Price: Low to High',
        'price_desc' => 'Price: High to Low',
        'year_desc'  => 'Year: Newest First',
        'year_asc'   => 'Year: Oldest First',
        'km_asc'     => 'Mileage: Lowest First',
        'newest'     => 'Recently Added',
    ];
}

const SA_VF_PER_PAGE = 15;

/* ===========================================================================
 * Data helpers (cached)
 * ======================================================================== */

/** Non-empty terms for a taxonomy as [ ['slug','name'], ... ] ordered by name. */
function sa_vf_terms( $taxonomy ) {
    if ( ! taxonomy_exists( $taxonomy ) ) return [];
    $terms = get_terms( [
        'taxonomy'   => $taxonomy,
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ] );
    if ( is_wp_error( $terms ) ) return [];
    $out = [];
    foreach ( $terms as $t ) {
        $out[] = [ 'slug' => $t->slug, 'name' => $t->name ];
    }
    return $out;
}

/** Year terms sorted numerically descending. */
function sa_vf_year_terms() {
    $terms = sa_vf_terms( 'pa_vehicle-year' );
    usort( $terms, function ( $a, $b ) {
        return intval( $b['name'] ) <=> intval( $a['name'] );
    } );
    return $terms;
}

/** min/max monthly price across published vehicles (cached 15 min). */
function sa_vf_price_bounds() {
    $cached = get_transient( 'sa_vf_price_bounds' );
    if ( is_array( $cached ) ) return $cached;

    global $wpdb;
    $row = $wpdb->get_row(
        "SELECT MIN(CAST(pm.meta_value AS DECIMAL(12,2))) AS min_p,
                MAX(CAST(pm.meta_value AS DECIMAL(12,2))) AS max_p
           FROM {$wpdb->postmeta} pm
           INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
          WHERE pm.meta_key = '_price'
            AND pm.meta_value <> ''
            AND p.post_type = 'product'
            AND p.post_status = 'publish'"
    );

    $min = $row && $row->min_p !== null ? (int) floor( $row->min_p ) : 0;
    $max = $row && $row->max_p !== null ? (int) ceil( $row->max_p )  : 25000;
    if ( $max <= $min ) $max = $min + 1000;

    $bounds = [ 'min' => $min, 'max' => $max ];
    set_transient( 'sa_vf_price_bounds', $bounds, 15 * MINUTE_IN_SECONDS );
    return $bounds;
}

/**
 * Map of make-slug => [ model-slug => model-name ] so the Model dropdown can
 * narrow to the chosen Make client-side. Cached 15 min.
 */
function sa_vf_make_model_map() {
    $cached = get_transient( 'sa_vf_make_model_map' );
    if ( is_array( $cached ) ) return $cached;

    $ids = get_posts( [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ] );

    $map = [];
    foreach ( $ids as $id ) {
        $makes  = get_the_terms( $id, 'pa_make' );
        $models = get_the_terms( $id, 'pa_model' );
        if ( ! $makes || is_wp_error( $makes ) || ! $models || is_wp_error( $models ) ) continue;
        foreach ( $makes as $mk ) {
            if ( ! isset( $map[ $mk->slug ] ) ) $map[ $mk->slug ] = [];
            foreach ( $models as $md ) {
                $map[ $mk->slug ][ $md->slug ] = $md->name;
            }
        }
    }
    // Sort each make's models by name.
    foreach ( $map as &$models ) {
        asort( $models );
    }
    unset( $models );

    set_transient( 'sa_vf_make_model_map', $map, 15 * MINUTE_IN_SECONDS );
    return $map;
}

/** Resolve a km bucket key to the pa_kilometers term_ids whose value falls in range. */
function sa_vf_km_term_ids( $bucket_key ) {
    $bucket = null;
    foreach ( sa_vf_km_buckets() as $b ) {
        if ( $b['key'] === $bucket_key ) { $bucket = $b; break; }
    }
    if ( ! $bucket || ! taxonomy_exists( 'pa_kilometers' ) ) return [];

    $terms = get_terms( [ 'taxonomy' => 'pa_kilometers', 'hide_empty' => true ] );
    if ( is_wp_error( $terms ) ) return [];

    $ids = [];
    foreach ( $terms as $t ) {
        $km = (int) preg_replace( '/[^0-9]/', '', $t->name );
        if ( $km >= $bucket['min'] && $km < $bucket['max'] ) {
            $ids[] = (int) $t->term_id;
        }
    }
    return $ids;
}

/** Flush the cached facet/price/model data (call after an import). */
function sa_vf_flush_caches() {
    delete_transient( 'sa_vf_price_bounds' );
    delete_transient( 'sa_vf_make_model_map' );
}
// Piggyback on the importer's reindex signal so facets stay fresh.
add_action( 'wbw_custom_index_cron', 'sa_vf_flush_caches' );

/* ===========================================================================
 * Query
 * ======================================================================== */

/**
 * Read + sanitize filter args from an associative array (POST or GET).
 * Returns a normalized args array used by both the query and the template.
 */
function sa_vf_parse_args( array $src ) {
    // Only scalar values are meaningful here; ignore array/object injection.
    $str = function ( $key ) use ( $src ) {
        return ( isset( $src[ $key ] ) && is_scalar( $src[ $key ] ) )
            ? sanitize_text_field( wp_unslash( (string) $src[ $key ] ) )
            : '';
    };

    $args = [
        'sort'     => 'featured',
        'page'     => 1,
        'hide_sold'=> in_array( $str( 'hide_sold' ), [ '1', 'on', 'true', 'yes' ], true ),
        'km'       => $str( 'km' ),
        'price_min'=> ( $str( 'price_min' ) !== '' ) ? (int) $str( 'price_min' ) : null,
        'price_max'=> ( $str( 'price_max' ) !== '' ) ? (int) $str( 'price_max' ) : null,
        'category' => 0, // product_cat term the whole view is locked to (location archives)
        'facets'   => [],
    ];

    // Category scope may arrive as a term id or slug; resolve to an id.
    $cat = $str( 'category' );
    if ( $cat !== '' ) {
        if ( ctype_digit( $cat ) && term_exists( (int) $cat, 'product_cat' ) ) {
            $args['category'] = (int) $cat;
        } else {
            $term = get_term_by( 'slug', sanitize_title( $cat ), 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) $args['category'] = (int) $term->term_id;
        }
    }

    $sort = $str( 'sort' );
    if ( $sort !== '' && array_key_exists( $sort, sa_vf_sort_options() ) ) {
        $args['sort'] = $sort;
    }
    $page = $str( 'page' );
    if ( $page !== '' ) {
        $args['page'] = max( 1, (int) $page );
    }
    foreach ( sa_vf_facets() as $facet ) {
        $val = $str( $facet['key'] );
        if ( $val !== '' ) {
            $args['facets'][ $facet['key'] ] = sanitize_title( $val );
        }
    }
    return $args;
}

/** Build the WP_Query tax/meta query and return matching product IDs (unpaginated). */
function sa_vf_matching_ids( array $args ) {
    $tax_query = [ 'relation' => 'AND' ];

    foreach ( sa_vf_facets() as $facet ) {
        $k = $facet['key'];
        if ( ! empty( $args['facets'][ $k ] ) && taxonomy_exists( $facet['tax'] ) ) {
            $tax_query[] = [
                'taxonomy' => $facet['tax'],
                'field'    => 'slug',
                'terms'    => $args['facets'][ $k ],
            ];
        }
    }

    if ( ! empty( $args['km'] ) ) {
        $km_ids = sa_vf_km_term_ids( $args['km'] );
        // No terms in the bucket → guaranteed empty result.
        $tax_query[] = $km_ids
            ? [ 'taxonomy' => 'pa_kilometers', 'field' => 'term_id', 'terms' => $km_ids ]
            : [ 'taxonomy' => 'pa_kilometers', 'field' => 'term_id', 'terms' => [ 0 ] ];
    }

    if ( $args['hide_sold'] && taxonomy_exists( 'pa_sold' ) ) {
        $tax_query[] = [
            'taxonomy' => 'pa_sold',
            'field'    => 'name',
            'terms'    => 'Yes',
            'operator' => 'NOT IN',
        ];
    }

    // Location scope (province/area archives). include_children=true so a
    // province term also covers all of its area sub-terms.
    if ( ! empty( $args['category'] ) ) {
        $tax_query[] = [
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => (int) $args['category'],
            'include_children' => true,
        ];
    }

    $meta_query = [];
    if ( $args['price_min'] !== null || $args['price_max'] !== null ) {
        $min = $args['price_min'] !== null ? $args['price_min'] : 0;
        $max = $args['price_max'] !== null ? $args['price_max'] : PHP_INT_MAX;
        $meta_query[] = [
            'key'     => '_price',
            'value'   => [ $min, $max ],
            'compare' => 'BETWEEN',
            'type'    => 'NUMERIC',
        ];
    }

    $q_args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'ignore_sticky_posts' => true,
    ];
    if ( count( $tax_query ) > 1 ) $q_args['tax_query']   = $tax_query;
    if ( $meta_query )             $q_args['meta_query']  = $meta_query;

    $q = new WP_Query( $q_args );
    return $q->posts;
}

/** Numeric value of a product's single-value attribute term, or null. */
function sa_vf_term_num( $id, $taxonomy ) {
    $terms = get_the_terms( $id, $taxonomy );
    if ( ! $terms || is_wp_error( $terms ) ) return null;
    $t = reset( $terms );
    return (int) preg_replace( '/[^0-9]/', '', $t->name );
}

/** Sort matching IDs in-PHP (catalogue is small) honouring the sort key + sold-last. */
function sa_vf_sort_ids( array $ids, $sort ) {
    // Pre-compute the keys once.
    $meta = [];
    foreach ( $ids as $i => $id ) {
        $meta[ $id ] = [
            'price' => (float) get_post_meta( $id, '_price', true ),
            'order' => $i, // original (featured/menu) order fallback
        ];
    }
    if ( in_array( $sort, [ 'year_desc', 'year_asc' ], true ) ) {
        foreach ( $ids as $id ) $meta[ $id ]['year'] = (int) sa_vf_term_num( $id, 'pa_vehicle-year' );
    }
    if ( $sort === 'km_asc' ) {
        foreach ( $ids as $id ) $meta[ $id ]['km'] = (int) sa_vf_term_num( $id, 'pa_kilometers' );
    }

    // Sold-last: sold vehicles always sink below available ones.
    $sold = [];
    foreach ( $ids as $id ) {
        $terms = get_the_terms( $id, 'pa_sold' );
        $sold[ $id ] = ( $terms && ! is_wp_error( $terms ) && strcasecmp( reset( $terms )->name, 'Yes' ) === 0 ) ? 1 : 0;
    }

    usort( $ids, function ( $a, $b ) use ( $meta, $sold, $sort ) {
        if ( $sold[ $a ] !== $sold[ $b ] ) return $sold[ $a ] <=> $sold[ $b ];
        switch ( $sort ) {
            case 'price_asc':  return $meta[ $a ]['price'] <=> $meta[ $b ]['price'];
            case 'price_desc': return $meta[ $b ]['price'] <=> $meta[ $a ]['price'];
            case 'year_desc':  return $meta[ $b ]['year']  <=> $meta[ $a ]['year'];
            case 'year_asc':   return $meta[ $a ]['year']  <=> $meta[ $b ]['year'];
            case 'km_asc':     return $meta[ $a ]['km']    <=> $meta[ $b ]['km'];
            case 'newest':     return $b <=> $a; // higher post ID = more recent
            case 'featured':
            default:           return $meta[ $a ]['order'] <=> $meta[ $b ]['order'];
        }
    } );
    return $ids;
}

/**
 * Run a full filter query and return a structured result:
 * [ 'html', 'total', 'page', 'pages', 'per_page', 'showing' ]
 */
function sa_vf_run_query( array $args ) {
    $ids = sa_vf_matching_ids( $args );
    $ids = sa_vf_sort_ids( $ids, $args['sort'] );

    $total    = count( $ids );
    $per_page = SA_VF_PER_PAGE;
    $pages    = max( 1, (int) ceil( $total / $per_page ) );
    $page     = min( $args['page'], $pages );
    $offset   = ( $page - 1 ) * $per_page;
    $slice    = array_slice( $ids, $offset, $per_page );

    ob_start();
    if ( $slice ) {
        foreach ( $slice as $id ) {
            echo sa_vf_render_card( $id ); // phpcs:ignore WordPress.Security.EscapeOutput
        }
    } else {
        echo '<div class="sa-vf-empty">No vehicles match your filters. Try widening your search.</div>';
    }
    $html = ob_get_clean();

    $showing_from = $total ? $offset + 1 : 0;
    $showing_to   = min( $offset + $per_page, $total );

    return [
        'html'     => $html,
        'total'    => $total,
        'page'     => $page,
        'pages'    => $pages,
        'per_page' => $per_page,
        'showing'  => $total
            ? sprintf( 'Showing %d–%d of %d results', $showing_from, $showing_to, $total )
            : 'No results',
    ];
}

/* ===========================================================================
 * Card renderer
 * ======================================================================== */

function sa_vf_render_card( $id ) {
    $product = wc_get_product( $id );
    if ( ! $product ) return '';

    $title = $product->get_name();
    $link  = get_permalink( $id );
    $price = (float) $product->get_price();

    $main_id  = $product->get_image_id();
    $gallery  = $product->get_gallery_image_ids();
    $hover_id = $gallery[0] ?? $main_id;

    $img = $main_id
        ? wp_get_attachment_image_url( $main_id, 'woocommerce_thumbnail' )
        : wc_placeholder_img_src( 'woocommerce_thumbnail' );
    $hover = $hover_id ? wp_get_attachment_image_url( $hover_id, 'woocommerce_thumbnail' ) : $img;

    $km_val = sa_vf_term_num( $id, 'pa_kilometers' );

    $sold_terms = get_the_terms( $id, 'pa_sold' );
    $is_sold = $sold_terms && ! is_wp_error( $sold_terms ) && strcasecmp( reset( $sold_terms )->name, 'Yes' ) === 0;

    $has_hover = $hover && $hover !== $img;

    // `skip-lazy`/`no-lazy` opt these images out of theme/plugin lazy-loaders
    // (WP Rocket, Jetpack, a3 Lazy Load, …) that would otherwise swap the src
    // for a grey placeholder and leave it there.
    ob_start(); ?>
    <a class="sa-vf-card<?php echo $is_sold ? ' is-sold' : ''; ?>" href="<?php echo esc_url( $link ); ?>">
        <div class="sa-vf-card__media">
            <img class="sa-vf-card__img skip-lazy no-lazy"
                 src="<?php echo esc_url( $img ); ?>"
                 alt="<?php echo esc_attr( $title ); ?>"
                 loading="eager" decoding="async" data-skip-lazy="1">
            <?php if ( $has_hover ) : ?>
                <img class="sa-vf-card__img sa-vf-card__img--hover skip-lazy no-lazy"
                     src="<?php echo esc_url( $hover ); ?>"
                     alt="" aria-hidden="true" loading="lazy" decoding="async" data-skip-lazy="1">
            <?php endif; ?>
            <?php if ( $is_sold ) : ?>
                <span class="sa-vf-card__sold">SOLD</span>
            <?php endif; ?>
        </div>
        <div class="sa-vf-card__body">
            <h3 class="sa-vf-card__title"><?php echo esc_html( $title ); ?></h3>
            <div class="sa-vf-card__price">
                <strong>R<?php echo esc_html( number_format( $price, 0, '.', ',' ) ); ?></strong>
                <span class="sa-vf-card__pm">p/m</span>
                <span class="sa-vf-card__rtb">- RENT TO BUY</span>
            </div>
            <?php if ( $km_val ) : ?>
                <span class="sa-vf-card__km"><?php echo esc_html( number_format( $km_val, 0, '.', ',' ) ); ?>km</span>
            <?php endif; ?>
        </div>
    </a>
    <?php
    return ob_get_clean();
}

/* ===========================================================================
 * AJAX endpoint
 * ======================================================================== */

add_action( 'wp_ajax_sa_vf_query',        'sa_vf_ajax_query' );
add_action( 'wp_ajax_nopriv_sa_vf_query', 'sa_vf_ajax_query' );

function sa_vf_ajax_query() {
    // Public, read-only endpoint (only surfaces already-public published
    // products) — mirrors the /qualified-vehicles REST route. Not nonce-gated
    // so it keeps working behind full-page caching where a localized nonce
    // would otherwise go stale and 403 every filter request.
    $args   = sa_vf_parse_args( $_POST );
    $result = sa_vf_run_query( $args );
    wp_send_json_success( $result );
}

/* ===========================================================================
 * Assets + shortcode
 * ======================================================================== */

function sa_vf_register_assets() {
    wp_register_style(
        'sa-vehicle-filter',
        SA_MOTORLEASE_URL . 'assets/css/vehicle-filter.css',
        [],
        SA_VF_VERSION
    );
    wp_register_script(
        'sa-vehicle-filter',
        SA_MOTORLEASE_URL . 'assets/js/vehicle-filter.js',
        [],
        SA_VF_VERSION,
        true
    );

    // On location archives we render the filter ourselves (below), so make sure
    // the assets land in <head> rather than relying on the shortcode enqueue.
    if ( function_exists( 'is_product_category' ) && is_product_category() ) {
        wp_enqueue_style( 'sa-vehicle-filter' );
        wp_enqueue_script( 'sa-vehicle-filter' );
    }
}
add_action( 'wp_enqueue_scripts', 'sa_vf_register_assets' );

/* ===========================================================================
 * Location category archives → render the filter, scoped to that province/area
 * ======================================================================== */

/**
 * Take over WooCommerce product_cat archives (the importer's province/area
 * location terms, e.g. /listings/gauteng/sandton/) and render the custom
 * filter locked to that location instead of the theme's default product loop.
 * Keeps the theme header/footer — and the SEO-friendly term URL + <title>.
 */
add_filter( 'template_include', 'sa_vf_location_archive_template', 99 );
function sa_vf_location_archive_template( $template ) {
    if ( is_admin() || ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
        return $template;
    }
    $custom = SA_MOTORLEASE_DIR . 'includes/location-archive-template.php';
    return file_exists( $custom ) ? $custom : $template;
}

add_shortcode( 'sa_vehicle_filter', 'sa_vf_shortcode' );

function sa_vf_shortcode( $atts ) {
    $atts = shortcode_atts( [
        'per_page' => SA_VF_PER_PAGE,
        'category' => '', // product_cat id or slug to lock the whole view to (location pages)
    ], $atts, 'sa_vehicle_filter' );

    wp_enqueue_style( 'sa-vehicle-filter' );
    wp_enqueue_script( 'sa-vehicle-filter' );

    // Seed the initial (server-rendered) state from the URL so the page is
    // shareable and works before JS boots.
    $initial = sa_vf_parse_args( $_GET );

    // A category set on the shortcode always wins over the URL — it's the fixed
    // location scope for that page/archive and must not be user-removable.
    if ( $atts['category'] !== '' ) {
        $scope = sa_vf_parse_args( [ 'category' => (string) $atts['category'] ] );
        $initial['category'] = $scope['category'];
    }

    $result = sa_vf_run_query( $initial );
    $bounds = sa_vf_price_bounds();

    wp_localize_script( 'sa-vehicle-filter', 'SA_VF', [
        'ajax_url'   => admin_url( 'admin-ajax.php' ),
        'nonce'      => wp_create_nonce( 'sa_vf' ),
        'price'      => $bounds,
        'models'     => sa_vf_make_model_map(),
        'per_page'   => (int) SA_VF_PER_PAGE,
        'sort'       => sa_vf_sort_options(),
        'category'   => (int) $initial['category'], // keeps AJAX requests locked to the location
    ] );

    ob_start();
    include __DIR__ . '/vehicle-filter-template.php';
    return ob_get_clean();
}
