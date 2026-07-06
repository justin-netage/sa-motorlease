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
    define( 'SA_VF_VERSION', '1.6.3' );
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

/**
 * Region navigator for a location category page. Instead of filtering, the
 * Region dropdown lets the visitor jump between the location archives of the
 * current province (so they can't wander outside e.g. Gauteng). Returns
 * [ 'current' => term_id, 'options' => [ [id,name,url], ... ] ] or null when
 * the category has no usable location tree.
 */
function sa_vf_region_nav( $category_id ) {
    $category_id = (int) $category_id;
    if ( ! $category_id || ! term_exists( $category_id, 'product_cat' ) ) return null;

    // Top-most ancestor is the province; if none, the term is itself a province.
    $ancestors   = get_ancestors( $category_id, 'product_cat', 'taxonomy' );
    $province_id = $ancestors ? (int) end( $ancestors ) : $category_id;
    $province    = get_term( $province_id, 'product_cat' );
    if ( ! $province || is_wp_error( $province ) ) return null;

    $options = [];

    // "All <Province>" first — links back to the province archive.
    $prov_link = get_term_link( $province );
    if ( ! is_wp_error( $prov_link ) ) {
        $options[] = [ 'id' => $province_id, 'name' => 'All ' . $province->name, 'url' => $prov_link ];
    }

    // Each area under the province.
    $areas = get_terms( [
        'taxonomy'   => 'product_cat',
        'parent'     => $province_id,
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ] );
    if ( ! is_wp_error( $areas ) ) {
        foreach ( $areas as $a ) {
            $link = get_term_link( $a );
            if ( is_wp_error( $link ) ) continue;
            $options[] = [ 'id' => (int) $a->term_id, 'name' => $a->name, 'url' => $link ];
        }
    }

    if ( count( $options ) < 2 ) return null; // nothing meaningful to navigate

    return [ 'current' => $category_id, 'options' => $options ];
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

/** Distinct term slugs present across a set of product IDs for a taxonomy. */
function sa_vf_distinct_term_slugs( array $ids, $taxonomy ) {
    if ( ! $ids || ! taxonomy_exists( $taxonomy ) ) return [];
    $slugs = wp_get_object_terms( $ids, $taxonomy, [ 'fields' => 'slugs' ] );
    return is_wp_error( $slugs ) ? [] : array_values( array_unique( $slugs ) );
}

/** Which km buckets are represented across a set of product IDs. */
function sa_vf_available_km_buckets( array $ids ) {
    if ( ! $ids || ! taxonomy_exists( 'pa_kilometers' ) ) return [];
    $names = wp_get_object_terms( $ids, 'pa_kilometers', [ 'fields' => 'names' ] );
    if ( is_wp_error( $names ) ) return [];
    $out = [];
    foreach ( sa_vf_km_buckets() as $b ) {
        foreach ( $names as $name ) {
            $km = (int) preg_replace( '/[^0-9]/', '', $name );
            if ( $km >= $b['min'] && $km < $b['max'] ) { $out[] = $b['key']; break; }
        }
    }
    return $out;
}

/**
 * For each facet, the option values still possible given the OTHER active
 * filters (a facet's own selection is excluded so the user can switch within
 * it). Powers hiding impossible options in the dropdowns.
 */
function sa_vf_available( array $args ) {
    $full_ids = sa_vf_matching_ids( $args );
    $avail    = [];

    foreach ( sa_vf_facets() as $facet ) {
        $k = $facet['key'];
        if ( ! empty( $args['facets'][ $k ] ) ) {
            $a2 = $args;
            unset( $a2['facets'][ $k ] ); // ignore this facet's own selection
            $ids = sa_vf_matching_ids( $a2 );
        } else {
            $ids = $full_ids;
        }
        $avail[ $k ] = sa_vf_distinct_term_slugs( $ids, $facet['tax'] );
    }

    // Km buckets (exclude the km selection itself).
    if ( $args['km'] !== '' ) {
        $a2 = $args;
        $a2['km'] = '';
        $km_ids = sa_vf_matching_ids( $a2 );
    } else {
        $km_ids = $full_ids;
    }
    $avail['km'] = sa_vf_available_km_buckets( $km_ids );

    return $avail;
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
    $result['available'] = sa_vf_available( $args );
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

    // On a category page the Region dropdown navigates between location
    // archives (scoped to the current province) instead of filtering.
    $region_nav = $initial['category'] ? sa_vf_region_nav( $initial['category'] ) : null;

    wp_localize_script( 'sa-vehicle-filter', 'SA_VF', [
        'ajax_url'   => admin_url( 'admin-ajax.php' ),
        'nonce'      => wp_create_nonce( 'sa_vf' ),
        'price'      => $bounds,
        'available'  => sa_vf_available( $initial ), // options still possible on load
        'per_page'   => (int) SA_VF_PER_PAGE,
        'sort'       => sa_vf_sort_options(),
        'category'   => (int) $initial['category'], // keeps AJAX requests locked to the location
    ] );

    ob_start();
    include __DIR__ . '/vehicle-filter-template.php';
    return ob_get_clean();
}

/* ===========================================================================
 * Featured listings carousel (category-scoped)
 * ======================================================================== */

/**
 * Product IDs for the featured strip: WooCommerce "Featured" products in the
 * given category, topped up with the newest vehicles in that category when
 * fewer than $limit are flagged. category_id 0 = whole catalogue.
 */
/** tax_query clause excluding sold vehicles, or null if the taxonomy is absent. */
function sa_vf_not_sold_clause() {
    if ( ! taxonomy_exists( 'pa_sold' ) ) return null;
    return [ 'taxonomy' => 'pa_sold', 'field' => 'name', 'terms' => 'Yes', 'operator' => 'NOT IN' ];
}

function sa_vf_featured_ids( $category_id = 0, $limit = 8 ) {
    $limit = max( 1, (int) $limit );

    $base = [];
    if ( $category_id ) {
        $base[] = [
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => (int) $category_id,
            'include_children' => true,
        ];
    }
    // Never feature sold vehicles.
    $ns = sa_vf_not_sold_clause();
    if ( $ns ) $base[] = $ns;

    $featured = $base;
    $featured[] = [ 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => 'featured' ];
    if ( count( $featured ) > 1 ) $featured = array_merge( [ 'relation' => 'AND' ], $featured );

    $ids = get_posts( [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'tax_query'      => $featured,
    ] );

    // Top up with the newest vehicles in the same scope if needed.
    if ( count( $ids ) < $limit ) {
        $fill_args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $limit - count( $ids ),
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post__not_in'   => $ids ?: [ 0 ],
        ];
        if ( $base ) $fill_args['tax_query'] = ( count( $base ) > 1 ) ? array_merge( [ 'relation' => 'AND' ], $base ) : $base;
        $ids = array_merge( $ids, get_posts( $fill_args ) );
    }

    return $ids;
}

/**
 * Slider shell used by the featured and qualified carousels.
 *
 * @param string $items_html rendered .sa-vf-featured__item markup (may be '')
 * @param string $title      heading ('' to omit)
 * @param bool   $full       full-bleed (edge-to-edge) width
 * @param array  $attrs      extra attributes for the wrapper (e.g. data-qualified)
 * @param string $msg        placeholder/status text shown when there are no items yet
 * @param string $extra_class extra class(es) for the wrapper (e.g. sa-vf-featured--panel)
 */
function sa_vf_featured_shell( $items_html, $title = '', $full = false, $attrs = [], $msg = '', $extra_class = '' ) {
    $classes = 'sa-vf-featured' . ( $full ? ' sa-vf-featured--full' : '' ) . ( $extra_class ? ' ' . $extra_class : '' );
    $attr_str = '';
    foreach ( $attrs as $k => $v ) {
        $attr_str .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
    }

    ob_start(); ?>
    <div class="<?php echo esc_attr( $classes ); ?>"<?php echo $attr_str; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
        <?php if ( $title !== '' ) : ?>
            <h2 class="sa-vf-featured__title"><span><?php echo esc_html( $title ); ?></span></h2>
        <?php endif; ?>
        <div class="sa-vf-featured__viewport">
            <button type="button" class="sa-vf-featured__nav sa-vf-featured__nav--prev" aria-label="Previous vehicles">&#8249;</button>
            <div class="sa-vf-featured__track"><?php echo $items_html; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
            <button type="button" class="sa-vf-featured__nav sa-vf-featured__nav--next" aria-label="More vehicles">&#8250;</button>
        </div>
        <p class="sa-vf-featured__msg"<?php echo $msg === '' ? ' hidden' : ''; ?>><?php echo esc_html( $msg ); ?></p>
    </div>
    <?php
    return ob_get_clean();
}

/** Render the featured strip. Returns '' when there are no products. */
function sa_vf_render_featured( $category_id = 0, $limit = 8, $title = 'Featured Listings', $full = false, $attrs = [], $extra_class = '' ) {
    $ids = sa_vf_featured_ids( $category_id, $limit );
    if ( ! $ids ) return '';

    $items = '';
    foreach ( $ids as $id ) {
        $items .= '<div class="sa-vf-featured__item">' . sa_vf_render_card( $id ) . '</div>';
    }
    return sa_vf_featured_shell( $items, $title, $full, $attrs, '', $extra_class );
}

/**
 * Interpret a `bg` attribute → the panel class(es) and any inline background.
 *  '' / 'none'            → no panel (transparent)
 *  'translucent'/'glass'  → frosted translucent panel (CSS-driven)
 *  a colour (#fff, hex8…) → solid/custom panel with inline background
 */
function sa_vf_panel_bg( $bg ) {
    $b = strtolower( trim( (string) $bg ) );
    if ( $b === '' || $b === 'none' )              return [ 'class' => '', 'inline' => '' ];
    if ( $b === 'translucent' || $b === 'glass' )  return [ 'class' => 'sa-vf-featured--panel sa-vf-featured--glass', 'inline' => '' ];
    return [ 'class' => 'sa-vf-featured--panel', 'inline' => trim( (string) $bg ) ];
}

/** Inline style (max-width + optional background) for a carousel container. */
function sa_vf_container_style( $max_width, $full, $bg = '' ) {
    $parts = [];
    $max_width = trim( (string) $max_width );
    if ( ! $full && $max_width !== '' ) {
        if ( is_numeric( $max_width ) ) $max_width .= 'px';
        $parts[] = 'max-width:' . $max_width;
    }
    $bg = trim( (string) $bg );
    if ( $bg !== '' && strtolower( $bg ) !== 'none' ) {
        $parts[] = 'background:' . $bg;
    }
    return implode( ';', $parts );
}

add_shortcode( 'sa_featured_vehicles', 'sa_vf_featured_shortcode' );

function sa_vf_featured_shortcode( $atts ) {
    $atts = shortcode_atts( [
        'category'  => '',                // product_cat id/slug; empty = current archive term
        'limit'     => 8,
        'title'     => 'Featured Listings',
        'full'      => 'no',              // "yes" = full-bleed width
        'max_width' => '',                // e.g. 1100 or 1100px to tighten the container
        'bg'        => '',                // panel background colour ('' / none = transparent)
    ], $atts, 'sa_featured_vehicles' );

    wp_enqueue_style( 'sa-vehicle-filter' );
    wp_enqueue_script( 'sa-vehicle-filter' ); // drives the draggable slider + arrows

    $cat_id = 0;
    if ( $atts['category'] !== '' ) {
        $p = sa_vf_parse_args( [ 'category' => (string) $atts['category'] ] );
        $cat_id = $p['category'];
    } elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
        $term = get_queried_object();
        if ( $term && ! is_wp_error( $term ) && isset( $term->term_id ) ) {
            $cat_id = (int) $term->term_id;
        }
    }

    $full  = in_array( strtolower( (string) $atts['full'] ), [ 'yes', '1', 'true', 'full' ], true );
    $bg    = sa_vf_panel_bg( $atts['bg'] );
    $style = sa_vf_container_style( $atts['max_width'], $full, $bg['inline'] );

    return sa_vf_render_featured(
        $cat_id, (int) $atts['limit'], $atts['title'], $full,
        $style ? [ 'style' => $style ] : [],
        $bg['class']
    );
}

/* ===========================================================================
 * Qualified-vehicles carousel (matches the lead's monthly rental limit)
 * ======================================================================== */

/** Vehicle IDs with _price <= $limit, one per model, priciest-affordable first. */
function sa_vf_qualified_ids( $limit, $per = 15 ) {
    $limit = (float) $limit;
    if ( $limit <= 0 ) return [];

    $q_args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_key'       => '_price',
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
        'meta_query'     => [ [
            'key'     => '_price',
            'value'   => $limit,
            'compare' => '<=',
            'type'    => 'NUMERIC',
        ] ],
    ];
    $ns = sa_vf_not_sold_clause();
    if ( $ns ) $q_args['tax_query'] = [ $ns ]; // exclude sold vehicles
    $q = new WP_Query( $q_args );

    $out = [];
    $seen = [];
    foreach ( $q->posts as $id ) {
        $terms = get_the_terms( $id, 'pa_model' );
        $model = ( $terms && ! is_wp_error( $terms ) ) ? reset( $terms )->slug : '';
        if ( $model !== '' ) {
            if ( isset( $seen[ $model ] ) ) continue;
            $seen[ $model ] = 1;
        }
        $out[] = (int) $id;
        if ( count( $out ) >= $per ) break;
    }
    return $out;
}

add_action( 'wp_ajax_sa_vf_qualified',        'sa_vf_ajax_qualified' );
add_action( 'wp_ajax_nopriv_sa_vf_qualified', 'sa_vf_ajax_qualified' );

function sa_vf_ajax_qualified() {
    $limit = isset( $_POST['rental_limit'] ) ? floatval( $_POST['rental_limit'] ) : 0;

    // Fall back to a lead_id lookup, mirroring /qualified-vehicles.
    if ( $limit <= 0 && ! empty( $_POST['lead_id'] ) ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'lead_qualifications';
        $db_limit = $wpdb->get_var( $wpdb->prepare(
            "SELECT rental_limit FROM {$table} WHERE lead_id = %d", (int) $_POST['lead_id']
        ) );
        if ( $db_limit !== null ) $limit = (float) $db_limit;
    }

    $per = isset( $_POST['limit'] ) ? max( 1, min( 30, (int) $_POST['limit'] ) ) : 15;
    $ids = sa_vf_qualified_ids( $limit, $per );

    $html = '';
    foreach ( $ids as $id ) {
        $html .= '<div class="sa-vf-featured__item">' . sa_vf_render_card( $id ) . '</div>';
    }
    wp_send_json_success( [ 'html' => $html, 'count' => count( $ids ) ] );
}

add_shortcode( 'sa_qualified_vehicles', function ( $atts ) {
    $atts = shortcode_atts( [
        'title'     => '',       // no title by default
        'full'      => 'no',
        'limit'     => 15,
        'max_width' => '1100',       // tighter container by default; '' for the standard 1360
        'bg'        => 'translucent', // frosted see-through panel; 'none' or a colour to change
    ], $atts, 'sa_qualified_vehicles' );

    wp_enqueue_style( 'sa-vehicle-filter' );
    wp_enqueue_script( 'sa-vehicle-filter' );
    wp_localize_script( 'sa-vehicle-filter', 'SA_VF_Q', [ 'ajax_url' => admin_url( 'admin-ajax.php' ) ] );

    $full  = in_array( strtolower( (string) $atts['full'] ), [ 'yes', '1', 'true', 'full' ], true );
    $bg    = sa_vf_panel_bg( $atts['bg'] );
    $style = sa_vf_container_style( $atts['max_width'], $full, $bg['inline'] );

    $attrs = [
        'data-qualified' => '1',
        'data-limit'     => (int) $atts['limit'],
    ];
    if ( $style ) $attrs['style'] = $style;

    // Empty shell; JS reads the lead's rental limit and fills the track.
    return sa_vf_featured_shell( '', $atts['title'], $full, $attrs, 'Loading vehicles in your budget…', $bg['class'] );
} );

/* ===========================================================================
 * Disclaimer + breadcrumb shortcodes
 * ======================================================================== */

/** Configured listings disclaimer text (falls back to empty). */
function sa_vf_get_disclaimer() {
    return function_exists( 'sa_motorlease_get_setting' )
        ? (string) sa_motorlease_get_setting( 'listings_disclaimer', '' )
        : '';
}

add_shortcode( 'sa_listings_disclaimer', function () {
    $text = trim( sa_vf_get_disclaimer() );
    if ( $text === '' ) return '';
    wp_enqueue_style( 'sa-vehicle-filter' );
    return '<div class="sa-vf-disclaimer">' . wp_kses_post( wpautop( $text ) ) . '</div>';
} );

add_shortcode( 'sa_breadcrumbs', function () {
    wp_enqueue_style( 'sa-vehicle-filter' );

    $sep   = ' <span class="sa-vf-crumbs__sep">/</span> ';
    $items = [];
    $link  = function ( $label, $url ) {
        return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
    };
    $current = function ( $label ) {
        return '<span class="sa-vf-crumbs__current">' . esc_html( $label ) . '</span>';
    };

    // Home
    $items[] = $link( 'Home', home_url( '/' ) );

    // Listings root: the configured page, else fall back to the page at
    // slug "listings" so the crumb still appears before it's set in settings.
    $lp = function_exists( 'sa_motorlease_get_setting' ) ? (int) sa_motorlease_get_setting( 'listings_page_id', 0 ) : 0;
    if ( ! $lp ) {
        $auto = get_page_by_path( 'listings' );
        if ( $auto ) $lp = (int) $auto->ID;
    }
    $is_cat = function_exists( 'is_product_category' ) && is_product_category();

    if ( $lp && get_post_status( $lp ) ) {
        $on_listings = is_page( $lp ) && ! $is_cat;
        $items[] = $on_listings ? $current( get_the_title( $lp ) ) : $link( get_the_title( $lp ), get_permalink( $lp ) );
    }

    // Category ancestors + current term (Home / Listings / <Parent> / <Child>).
    if ( $is_cat ) {
        $term = get_queried_object();
        if ( $term && ! is_wp_error( $term ) ) {
            foreach ( array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) as $aid ) {
                $a = get_term( $aid, 'product_cat' );
                if ( ! $a || is_wp_error( $a ) ) continue;
                $al = get_term_link( $a );
                $items[] = is_wp_error( $al ) ? $current( $a->name ) : $link( $a->name, $al );
            }
            $items[] = $current( $term->name );
        }
    }

    return '<nav class="sa-vf-crumbs">' . implode( $sep, $items ) . '</nav>';
} );

/** "Sandton, East Rand, and Pretoria" from a list of names. */
function sa_vf_human_join( array $items ) {
    $items = array_values( array_filter( array_map( 'trim', $items ) ) );
    $n = count( $items );
    if ( $n === 0 ) return '';
    if ( $n === 1 ) return $items[0];
    if ( $n === 2 ) return $items[0] . ' and ' . $items[1];
    $last = array_pop( $items );
    return implode( ', ', $items ) . ', and ' . $last;
}

/**
 * Auto subtitle for a location term:
 *  - parent (has children): "Explore Our Gauteng Vehicle Listings: Sandton, East Rand, and Pretoria"
 *  - child  (has parent):   "Browse Our Sandton Collection – Available Across All Gauteng Branches"
 * A non-empty term description (shortcodes stripped) overrides this.
 */
function sa_vf_location_subtitle( $term ) {
    if ( ! $term || is_wp_error( $term ) ) return '';

    $custom = strip_shortcodes( term_description( $term->term_id, 'product_cat' ) );
    $custom = trim( wp_strip_all_tags( $custom ) );
    if ( $custom !== '' ) return $custom;

    $children = get_terms( [
        'taxonomy'   => 'product_cat',
        'parent'     => $term->term_id,
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ] );
    $child_names = ( ! is_wp_error( $children ) ) ? wp_list_pluck( $children, 'name' ) : [];

    if ( $child_names ) {
        return sprintf( 'Explore Our %s Vehicle Listings: %s', $term->name, sa_vf_human_join( $child_names ) );
    }

    $anc      = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
    $province = $anc ? get_term( end( $anc ), 'product_cat' ) : null;
    if ( $province && ! is_wp_error( $province ) ) {
        return sprintf( 'Browse Our %s Collection – Available Across All %s Branches', $term->name, $province->name );
    }
    return sprintf( 'Browse Our %s Collection', $term->name );
}

/* ===========================================================================
 * Full listings page composition (shared by the main page shortcode and the
 * location-archive takeover, so both look identical)
 * ======================================================================== */

/**
 * Render a complete listings block: featured slider → header (title, optional
 * subtitle, breadcrumb) → disclaimer → the filter itself.
 *
 * When $subtitle is set the header stacks (title / subtitle / breadcrumb),
 * matching the location category pages; otherwise the breadcrumb sits to the
 * far right of the title (main listings page).
 *
 * @param int    $cat_id   product_cat term to scope everything to (0 = whole site)
 * @param string $title    H1 text ('' to omit)
 * @param string $subtitle descriptive line under the title ('' to omit)
 * @param array  $show     toggles: featured, breadcrumbs, disclaimer
 */
function sa_vf_render_listings( $cat_id = 0, $title = '', $subtitle = '', $show = [] ) {
    $show = array_merge( [
        'featured'    => true,
        'breadcrumbs' => true,
        'disclaimer'  => true,
    ], $show );

    $cat_id = (int) $cat_id;

    ob_start();
    echo '<div class="sa-vf-archive">';

    if ( $show['featured'] ) {
        echo sa_vf_render_featured( $cat_id, 8, 'Featured Listings' ); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    echo '<div class="sa-vf-archive__head">';
    // Title left, breadcrumb far right on the same row.
    echo '<div class="sa-vf-archive__head-row">';
    if ( $title !== '' ) {
        echo '<h1 class="sa-vf-archive__title">' . esc_html( $title ) . '</h1>';
    }
    if ( $show['breadcrumbs'] ) {
        echo do_shortcode( '[sa_breadcrumbs]' );
    }
    echo '</div>';
    if ( $subtitle !== '' ) {
        echo '<p class="sa-vf-archive__subtitle">' . wp_kses_post( $subtitle ) . '</p>';
    }
    echo '</div>';

    if ( $show['disclaimer'] ) {
        echo do_shortcode( '[sa_listings_disclaimer]' );
    }

    echo do_shortcode( '[sa_vehicle_filter' . ( $cat_id ? ' category="' . $cat_id . '"' : '' ) . ']' );

    echo '</div>';
    return ob_get_clean();
}

add_shortcode( 'sa_vehicle_listings', function ( $atts ) {
    $atts = shortcode_atts( [
        'title'       => 'Vehicle Listings',
        'subtitle'    => '',
        'category'    => '',
        'featured'    => 'yes',
        'breadcrumbs' => 'yes',
        'disclaimer'  => 'yes',
    ], $atts, 'sa_vehicle_listings' );

    $cat_id = 0;
    if ( $atts['category'] !== '' ) {
        $p = sa_vf_parse_args( [ 'category' => (string) $atts['category'] ] );
        $cat_id = $p['category'];
    } elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
        $term = get_queried_object();
        if ( $term && ! is_wp_error( $term ) && isset( $term->term_id ) ) {
            $cat_id = (int) $term->term_id;
        }
    }

    // Auto title/subtitle for a category context unless overridden.
    $title    = $atts['title'];
    $subtitle = $atts['subtitle'];
    if ( $cat_id ) {
        $term = get_term( $cat_id, 'product_cat' );
        if ( $term && ! is_wp_error( $term ) ) {
            if ( $title === 'Vehicle Listings' ) {
                $title = $term->parent ? 'Vehicles - ' . $term->name : $term->name;
            }
            if ( $subtitle === '' ) {
                $subtitle = sa_vf_location_subtitle( $term );
            }
        }
    }

    $yes = function ( $v ) { return ! in_array( strtolower( (string) $v ), [ 'no', '0', 'false', '' ], true ); };

    return sa_vf_render_listings( $cat_id, $title, $subtitle, [
        'featured'    => $yes( $atts['featured'] ),
        'breadcrumbs' => $yes( $atts['breadcrumbs'] ),
        'disclaimer'  => $yes( $atts['disclaimer'] ),
    ] );
} );
