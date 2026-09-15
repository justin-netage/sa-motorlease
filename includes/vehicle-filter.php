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
    define( 'SA_VF_VERSION', '2.0.1' );
}

/**
 * How long a cached index/client blob may live without being invalidated.
 *
 * This is a fail-safe, not the primary freshness mechanism — the version stamp
 * is. It caps the blast radius when a version bump is missed: at DAY_IN_SECONDS
 * a stale catalogue survived a full day, which is precisely how long new
 * vehicles stayed invisible. An hour keeps the worst case to an hour, and a
 * rebuild is cheap enough (a handful of bulk queries) that expiring more often
 * costs little.
 */
if ( ! defined( 'SA_VF_CACHE_TTL' ) ) {
    define( 'SA_VF_CACHE_TTL', HOUR_IN_SECONDS );
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
        // Region filters on the product_cat location tree (province → area) the
        // importer assigns, not the pa_region attribute — so choosing a province
        // includes every area beneath it. `category => true` marks it as a
        // category filter (matched against a vehicle's expanded category set)
        // rather than a WooCommerce attribute.
        [ 'key' => 'region',       'label' => 'Region',       'tax' => 'product_cat', 'category' => true ],
        [ 'key' => 'transmission', 'label' => 'Transmission', 'tax' => 'pa_transmission' ],
        [ 'key' => 'body_type',    'label' => 'Body Type',    'tax' => 'pa_vehicle-type' ],
        [ 'key' => 'fuel',         'label' => 'Fuel',         'tax' => 'pa_fuel' ],
        [ 'key' => 'year',         'label' => 'Year Model',   'tax' => 'pa_vehicle-year' ],
    ];
}

/**
 * Location categories as a flat, hierarchical option list for the Region
 * dropdown: each top-level province (depth 0) followed by its areas (depth 1).
 * Values are product_cat term ids. Excludes WooCommerce's default category.
 */
function sa_vf_region_options() {
    if ( ! taxonomy_exists( 'product_cat' ) ) return [];
    $provinces = get_terms( [
        'taxonomy'   => 'product_cat',
        'parent'     => 0,
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ] );
    if ( is_wp_error( $provinces ) || ! $provinces ) return [];

    $out = [];
    foreach ( $provinces as $prov ) {
        if ( $prov->slug === 'uncategorized' ) continue;
        $out[] = [ 'id' => (int) $prov->term_id, 'name' => $prov->name, 'depth' => 0 ];

        $areas = get_terms( [
            'taxonomy'   => 'product_cat',
            'parent'     => $prov->term_id,
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );
        if ( ! is_wp_error( $areas ) ) {
            foreach ( $areas as $a ) {
                $out[] = [ 'id' => (int) $a->term_id, 'name' => $a->name, 'depth' => 1 ];
            }
        }
    }
    return $out;
}

/** Where the R500 steps give way to R2,000 steps, and the two step sizes. */
const SA_VF_PRICE_STEP_BREAK = 8000;
const SA_VF_PRICE_STEP_LOW   = 500;
const SA_VF_PRICE_STEP_HIGH  = 2000;

/**
 * Max-price options, derived from the catalogue rather than fixed.
 *
 * Each option is a ceiling, not a band: "Up to R10,000" returns every vehicle
 * priced R10,000 or less. The ladder starts at the first round figure that
 * covers the cheapest vehicle on the site and climbs in R500 steps to R8,000,
 * then in R2,000 steps until it covers the dearest — fine steps low down where
 * affordability is tight, coarser at the top where it isn't.
 *
 * The buckets are therefore nested rather than disjoint, which is why the two
 * availability passes (sa_vf_available() here, bucketsPresent() in the JS)
 * count a row against every bucket it falls in instead of stopping at the
 * first. Stopping early would mark only the lowest matching ceiling as
 * available and hide every option above it.
 *
 * Bounds stay half-open — `min` inclusive, `max` exclusive — because that is
 * the contract the rest of the filter (and the km buckets) work to. `max` sits
 * a cent above the ceiling so a vehicle priced at exactly the ceiling is
 * included, which is what "up to" means to a visitor.
 *
 * The top option is open-ended rather than stopping at its own label. The
 * ladder is rebuilt whenever a vehicle changes, so a dearer import should
 * widen it immediately; the open top means that even if a rebuild were missed,
 * no vehicle can sit above every option and become unreachable.
 *
 * Site-wide by design: the location archives lock the view to a province or
 * area, but every page offers the same ladder and the availability pass hides
 * the ceilings with nothing under them in the current scope.
 */
function sa_vf_price_buckets() {
    $memo =& sa_vf_memo();
    if ( array_key_exists( 'price_buckets', $memo ) ) return $memo['price_buckets'];

    list( $low, $high ) = sa_vf_price_range();

    // Nothing priced yet (empty catalogue, or a cold cache mid-import): fall
    // back to the fixed ladder so the dropdown still renders something usable.
    if ( $low === null ) {
        return $memo['price_buckets'] = sa_vf_price_buckets_fallback();
    }

    $money = function ( $n ) { return 'R' . number_format( $n, 0, '.', ',' ); };

    // Lowest ceiling worth offering: the first grid line at or above the
    // cheapest vehicle, so the first option always has something under it.
    // Above the break the lines are measured from the break itself, so they
    // stay round (8000, 10000, 12000…).
    if ( $low <= SA_VF_PRICE_STEP_BREAK ) {
        $cap = (int) ceil( $low / SA_VF_PRICE_STEP_LOW ) * SA_VF_PRICE_STEP_LOW;
    } else {
        $cap = SA_VF_PRICE_STEP_BREAK
             + (int) ceil( ( $low - SA_VF_PRICE_STEP_BREAK ) / SA_VF_PRICE_STEP_HIGH ) * SA_VF_PRICE_STEP_HIGH;
    }

    $buckets = [];
    while ( true ) {
        $last = ( $cap >= $high ); // this ceiling already covers the dearest vehicle

        $buckets[] = [
            'key'   => '0-' . $cap,
            'label' => 'Up to ' . $money( $cap ),
            'min'   => 0,
            'max'   => $last ? PHP_INT_MAX : $cap + 0.01,
        ];

        if ( $last ) break;
        $cap += ( $cap < SA_VF_PRICE_STEP_BREAK ) ? SA_VF_PRICE_STEP_LOW : SA_VF_PRICE_STEP_HIGH;
    }

    return $memo['price_buckets'] = $buckets;
}

/**
 * Cheapest and dearest monthly price in the catalogue as [ low, high ], or
 * [ null, null ] when nothing is priced.
 *
 * Sold vehicles count towards both ends: the ladder shouldn't rearrange itself
 * when a visitor toggles "Available Only", and the availability pass already
 * hides whichever ceilings have nothing under them in view.
 */
function sa_vf_price_range() {
    $memo =& sa_vf_memo();
    if ( array_key_exists( 'price_range', $memo ) ) return $memo['price_range'];

    $low = null;
    $high = null;
    foreach ( sa_vf_index() as $r ) {
        if ( $r['price'] === null ) continue;
        $p = (float) $r['price'];
        if ( $p <= 0 ) continue; // placeholder/zero prices aren't a real floor
        if ( $low === null  || $p < $low  ) $low  = $p;
        if ( $high === null || $p > $high ) $high = $p;
    }

    return $memo['price_range'] = [ $low, $high ];
}

/** The fixed ladder used before the catalogue is readable. */
function sa_vf_price_buckets_fallback() {
    return [
        [ 'key' => '0-6000',  'label' => 'Up to R6,000',  'min' => 0, 'max' => 6000.01 ],
        [ 'key' => '0-8000',  'label' => 'Up to R8,000',  'min' => 0, 'max' => 8000.01 ],
        [ 'key' => '0-10000', 'label' => 'Up to R10,000', 'min' => 0, 'max' => 10000.01 ],
        [ 'key' => '0-12000', 'label' => 'Up to R12,000', 'min' => 0, 'max' => 12000.01 ],
        [ 'key' => '0-14000', 'label' => 'Up to R14,000', 'min' => 0, 'max' => 14000.01 ],
        [ 'key' => '0-16000', 'label' => 'Up to R16,000', 'min' => 0, 'max' => PHP_INT_MAX ],
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

/* ===========================================================================
 * Vehicle index — the whole catalogue (price, sold, km, year, facet slugs and
 * category tree) pre-computed into one cached array. Every filter/sort/facet
 * request then runs entirely in-memory over this array with no per-request
 * database work, so the filter responds instantly. The index is versioned and
 * rebuilt whenever a vehicle changes (see the invalidation hooks below), which
 * keeps it in lock-step with the PaceApp importer.
 * ======================================================================== */

/** Monotonic version stamp; every vehicle change bumps it, busting the index. */
function sa_vf_index_version() {
    return (int) get_option( 'sa_vf_index_ver', 0 );
}

/**
 * Cache-key version: the data version plus the asset/schema version, so a plugin
 * update that changes the index shape invalidates a still-warm cache on deploy
 * (not just on the next vehicle change).
 */
function sa_vf_cache_version() {
    return sa_vf_index_version() . '-' . SA_VF_VERSION;
}

/**
 * Per-request memo store for everything derived from the index.
 *
 * These were function-local statics. They are pooled here so a flush can drop
 * them all at once: an import mutates the catalogue *after* some of them are
 * already populated, and a stale memo would then be re-cached under the new
 * version — the exact staleness the flush exists to prevent.
 */
function &sa_vf_memo() {
    static $memo = [];
    return $memo;
}

/** Drop every per-request memo, so the next read rebuilds from source. */
function sa_vf_reset_memo() {
    $memo =& sa_vf_memo();
    $memo = [];
}

/**
 * Invalidate the index. Debounced to one bump per request so a bulk import
 * touching hundreds of products only stamps a single new version (the rebuild
 * itself is lazy — it happens on the next read, not here).
 *
 * Registered directly as a hook callback, so it must take no meaningful
 * arguments: WordPress passes the post id to several of these hooks, and any
 * first parameter would arrive truthy. Use sa_vf_force_bump_index_version()
 * to bump again within the same request.
 */
function sa_vf_bump_index_version() {
    $memo =& sa_vf_memo();
    if ( ! empty( $memo['bumped'] ) ) return;
    $memo['bumped'] = true;
    update_option( 'sa_vf_index_ver', sa_vf_index_version() + 1, false );
}

/**
 * Bump the version even when this request already bumped once.
 *
 * The debounce above is what let a partial catalogue go stale for a full day:
 * an import run saves N products but stamps a single version, and any request
 * that rebuilt the index midway cached an incomplete catalogue *under that same
 * version*, where it then read as fresh until the transient expired. Re-arming
 * at the end of a run stamps a version no mid-run rebuild can already hold.
 */
function sa_vf_force_bump_index_version() {
    $memo =& sa_vf_memo();
    unset( $memo['bumped'] );
    sa_vf_bump_index_version();
}

// Bump on any change to a vehicle: create/update/delete, term reassignment
// (make/model/sold/…) and the image/price meta the importer rewrites.
add_action( 'save_post_product',        'sa_vf_bump_index_version' );
add_action( 'woocommerce_update_product','sa_vf_bump_index_version' );
add_action( 'woocommerce_new_product',   'sa_vf_bump_index_version' );
add_action( 'woocommerce_delete_product','sa_vf_bump_index_version' );
add_action( 'woocommerce_trash_product', 'sa_vf_bump_index_version' );
add_action( 'untrashed_post', function ( $post_id ) {
    if ( get_post_type( $post_id ) === 'product' ) sa_vf_bump_index_version();
} );
add_action( 'set_object_terms', function ( $object_id ) {
    if ( get_post_type( $object_id ) === 'product' ) sa_vf_bump_index_version();
} );
$sa_vf_meta_bump = function ( $meta_id, $post_id, $meta_key ) {
    if ( in_array( $meta_key, [ '_price', '_thumbnail_id', '_product_image_gallery' ], true )
        && get_post_type( $post_id ) === 'product' ) {
        sa_vf_bump_index_version();
    }
};
add_action( 'updated_post_meta', $sa_vf_meta_bump, 10, 3 );
add_action( 'added_post_meta',   $sa_vf_meta_bump, 10, 3 );
add_action( 'deleted_post_meta', function ( $meta_ids, $post_id, $meta_key ) {
    if ( in_array( $meta_key, [ '_thumbnail_id', '_product_image_gallery' ], true )
        && get_post_type( $post_id ) === 'product' ) {
        sa_vf_bump_index_version();
    }
}, 10, 3 );

/** The cached vehicle index (rows in catalogue/date-DESC order). */
function sa_vf_index() {
    $memo =& sa_vf_memo();
    if ( array_key_exists( 'index', $memo ) ) return $memo['index']; // one build per request

    $ver  = sa_vf_cache_version();
    $rows = sa_vf_index_read( $ver );
    if ( $rows === null ) {
        $rows = sa_vf_build_index();
        sa_vf_index_write( $rows, $ver );
    }
    return $memo['index'] = $rows;
}

/**
 * Decode a cached, compressed blob (prefix "g:" gzip+base64, "r:" raw) under
 * $key, or null on miss/stale. Compression keeps the value small enough to
 * survive object-cache size limits (e.g. Memcached's ~1 MB cap) and ASCII-safe
 * for the wp_options longtext column.
 */
function sa_vf_blob_read( $key, $ver ) {
    $blob = get_transient( $key );
    if ( ! is_string( $blob ) || strlen( $blob ) < 2 ) return null;

    $tag  = substr( $blob, 0, 2 );
    $body = substr( $blob, 2 );
    if ( $tag === 'g:' ) {
        if ( ! function_exists( 'gzuncompress' ) ) return null;
        $body = @gzuncompress( (string) base64_decode( $body ) );
        if ( $body === false ) return null;
    } elseif ( $tag !== 'r:' ) {
        return null;
    }

    $data = is_string( $body ) ? @unserialize( $body ) : false;
    if ( ! is_array( $data ) || ! isset( $data['ver'], $data['rows'] ) || $data['ver'] !== $ver ) {
        return null;
    }
    return $data['rows'];
}

/** Store $rows under $key, compressed when zlib is available. */
function sa_vf_blob_write( $key, array $rows, $ver ) {
    $body = serialize( [ 'ver' => $ver, 'rows' => $rows ] );
    if ( function_exists( 'gzcompress' ) ) {
        $z = gzcompress( $body, 6 );
        if ( $z !== false ) {
            set_transient( $key, 'g:' . base64_encode( $z ), SA_VF_CACHE_TTL );
            return;
        }
    }
    set_transient( $key, 'r:' . $body, SA_VF_CACHE_TTL );
}

// Thin wrappers so callers (and the diagnostic) read clearly.
function sa_vf_index_read( $ver ) { return sa_vf_blob_read( 'sa_vf_index', $ver ); }
function sa_vf_index_write( array $rows, $ver ) { sa_vf_blob_write( 'sa_vf_index', $rows, $ver ); }

/**
 * The full client-side dataset: every published vehicle with its filter fields
 * (price, sold, km, year, facet slugs, category tree) plus its pre-rendered card
 * HTML. Shipped to the browser once so all filtering/sorting/paging happens
 * client-side with no admin-ajax round-trip. Cached (compressed) per index
 * version and warmed after each import, exactly like the index itself.
 */
function sa_vf_client_data() {
    $memo =& sa_vf_memo();
    if ( array_key_exists( 'client', $memo ) ) return $memo['client'];

    $ver  = sa_vf_cache_version();
    $rows = sa_vf_blob_read( 'sa_vf_client', $ver );
    if ( $rows === null ) {
        $rows = [];
        foreach ( sa_vf_index() as $r ) {
            $rows[] = [
                'id'    => $r['id'],
                'price' => $r['price'],           // number|null
                'sold'  => $r['sold'] ? 1 : 0,
                'km'    => $r['km'],               // number|null
                'year'  => $r['year'],             // number|null
                'f'     => (object) $r['facets'],  // { facetKey: [slugs] }
                'c'     => $r['cats'],             // [ term ids, incl. ancestors ]
                'h'     => sa_vf_render_card( $r['id'] ),
            ];
        }
        sa_vf_blob_write( 'sa_vf_client', $rows, $ver );
    }
    return $memo['client'] = $rows;
}

/** Price/km bucket list flattened for JS (open-ended max sent as null = ∞). */
function sa_vf_buckets_for_js( array $buckets ) {
    $out = [];
    foreach ( $buckets as $b ) {
        $out[] = [
            'key' => $b['key'],
            'min' => $b['min'],
            'max' => $b['max'] >= PHP_INT_MAX ? null : $b['max'],
        ];
    }
    return $out;
}

/**
 * Normalise a post-query result to a list of integer IDs.
 *
 * Every product query below asks for `fields => ids`, but that is a request,
 * not a guarantee: a `pre_get_posts` callback in the theme or another plugin
 * can reset it, and that hook fires even though get_posts() defaults
 * suppress_filters to true. The result then arrives as WP_Post objects, and
 * PHP 8 fatals the moment one is used as an array key ("Cannot access offset
 * of type WP_Post on array") — a 500 on the listings archives rather than
 * anything the caller can anticipate. Casting at each query boundary keeps the
 * rest of the file working in plain ints whatever the query hands back.
 */
function sa_vf_post_ids( $posts ) {
    if ( ! is_array( $posts ) ) return [];
    $ids = [];
    foreach ( $posts as $p ) {
        $ids[] = $p instanceof WP_Post ? (int) $p->ID : (int) $p;
    }
    return $ids;
}

/** The index keyed by product id, for O(1) lookups during sort/render. */
function sa_vf_index_by_id() {
    $memo =& sa_vf_memo();
    if ( array_key_exists( 'by_id', $memo ) ) return $memo['by_id'];
    $map = [];
    foreach ( sa_vf_index() as $r ) $map[ $r['id'] ] = $r;
    return $memo['by_id'] = $map;
}

/**
 * Build the index in a handful of bulk queries (all terms in one call, all
 * prices in one SQL) rather than per-product lookups. Runs only on a cache miss
 * or right after an import, never on a normal filter request.
 */
function sa_vf_build_index() {
    $ids = sa_vf_post_ids( get_posts( [
        'post_type'           => 'product',
        'post_status'         => 'publish',
        'posts_per_page'      => -1,
        'fields'              => 'ids',
        'no_found_rows'       => true,
        'ignore_sticky_posts' => true,
        'orderby'             => 'date',
        'order'               => 'DESC',
    ] ) );
    if ( ! $ids ) return [];

    // Every taxonomy we need, fetched for all products in a single query.
    $taxes = [];
    foreach ( sa_vf_facets() as $f ) $taxes[] = $f['tax'];
    $taxes = array_merge( $taxes, [ 'pa_kilometers', 'pa_sold', 'product_cat' ] );
    $taxes = array_values( array_unique( array_filter( $taxes, 'taxonomy_exists' ) ) );

    $by_obj = [];
    if ( $taxes ) {
        $terms = wp_get_object_terms( $ids, $taxes, [ 'fields' => 'all_with_object_id' ] );
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $t ) {
                $by_obj[ $t->object_id ][ $t->taxonomy ][] = $t;
            }
        }
    }

    // All monthly prices in one SQL.
    global $wpdb;
    $price_by_id = [];
    $in = implode( ',', array_map( 'intval', $ids ) );
    if ( $in !== '' ) {
        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
              WHERE meta_key = '_price' AND post_id IN ($in)"
        );
        foreach ( $rows as $row ) $price_by_id[ (int) $row->post_id ] = $row->meta_value;
    }

    // Memoise product_cat ancestor chains so a province term matches products
    // filed under its area children (WooCommerce include_children behaviour).
    $anc = [];
    $ancestors = function ( $tid ) use ( &$anc ) {
        if ( ! isset( $anc[ $tid ] ) ) $anc[ $tid ] = get_ancestors( $tid, 'product_cat', 'taxonomy' );
        return $anc[ $tid ];
    };

    $num = function ( $name ) { return (int) preg_replace( '/[^0-9]/', '', (string) $name ); };

    $index = [];
    foreach ( $ids as $id ) {
        $ot = $by_obj[ $id ] ?? [];

        $facets = [];
        foreach ( sa_vf_facets() as $f ) {
            if ( ! empty( $f['category'] ) ) continue; // Region matches on cats, below
            $slugs = [];
            foreach ( ( $ot[ $f['tax'] ] ?? [] ) as $t ) $slugs[] = $t->slug;
            if ( $slugs ) $facets[ $f['key'] ] = $slugs;
        }

        $km   = ! empty( $ot['pa_kilometers'] )  ? $num( reset( $ot['pa_kilometers'] )->name )  : null;
        $year = ! empty( $ot['pa_vehicle-year'] ) ? $num( reset( $ot['pa_vehicle-year'] )->name ) : null;

        $sold = false;
        foreach ( ( $ot['pa_sold'] ?? [] ) as $t ) {
            if ( strcasecmp( $t->name, 'Yes' ) === 0 ) { $sold = true; break; }
        }

        $cats = [];
        foreach ( ( $ot['product_cat'] ?? [] ) as $t ) {
            $cats[ (int) $t->term_id ] = true;
            foreach ( $ancestors( $t->term_id ) as $a ) $cats[ (int) $a ] = true;
        }

        $price = ( array_key_exists( $id, $price_by_id ) && $price_by_id[ $id ] !== '' )
            ? (float) $price_by_id[ $id ] : null;

        $index[] = [
            'id'     => (int) $id,
            'price'  => $price,
            'sold'   => $sold,
            'km'     => $km,
            'year'   => $year,
            'facets' => $facets,
            'cats'   => array_keys( $cats ),
        ];
    }
    return $index;
}

/** Look up a max-price / km bucket definition by its key (or null). */
function sa_vf_price_bucket_by_key( $key ) {
    if ( $key === '' ) return null;
    foreach ( sa_vf_price_buckets() as $b ) if ( $b['key'] === $key ) return $b;
    return null;
}
function sa_vf_km_bucket_by_key( $key ) {
    if ( $key === '' ) return null;
    foreach ( sa_vf_km_buckets() as $b ) if ( $b['key'] === $key ) return $b;
    return null;
}

/** Log to the plugin log if it is loaded; a no-op standalone. */
function sa_vf_log( $msg ) {
    if ( function_exists( 'log_import_update' ) ) {
        log_import_update( '[SA VF] ' . $msg );
    }
}

/**
 * Purge the host's full-page cache.
 *
 * This is the step that was missing entirely, and without it everything else
 * here is invisible to the public. The vehicle grid is not fetched at render
 * time — the whole catalogue is inlined into the page HTML by the shortcode
 * below and filtered client-side. So a cached page *is* a frozen vehicle list,
 * and rebuilding the index behind it changes nothing an anonymous visitor sees:
 * logged-in users bypass the page cache and get fresh stock, everyone else gets
 * yesterday's until the cache expires on its own.
 *
 * Worse, the two caches hold each other in place. The index rebuild is lazy —
 * it happens on read — but while the page cache is serving anonymous traffic,
 * PHP never runs on that page, so nothing reads the index and it never
 * rebuilds. Only a cache expiry or a logged-in visit breaks the cycle.
 *
 * Purging the full page cache rather than named URLs is deliberate: the
 * catalogue is embedded in the listing page, the location archives and any page
 * carrying a vehicle shortcode, and enumerating those correctly is a guessing
 * game that fails silently when someone adds a page. Callers only invoke this
 * when the catalogue actually changed (see the import passes), so on a quiet
 * feed it never fires.
 *
 * Guarded by class/method checks throughout: Kinsta's mu-plugin is absent on
 * local and staging, and its purge API has shifted across versions.
 */
function sa_vf_purge_page_cache() {
    $purged = false;

    // Kinsta: the mu-plugin exposes a Cache_Purge instance on a global.
    if ( class_exists( '\Kinsta\Cache' ) && isset( $GLOBALS['kinsta_cache'] ) ) {
        $kc = $GLOBALS['kinsta_cache'];
        if ( isset( $kc->kinsta_cache_purge ) && is_object( $kc->kinsta_cache_purge ) ) {
            $purge = $kc->kinsta_cache_purge;
            // Prefer the page-cache-only call — the object/transient layer is
            // ours and we have just rewritten it, so dropping it would only
            // force a needless rebuild.
            foreach ( [ 'purge_complete_full_page_cache', 'purge_complete_caches' ] as $method ) {
                if ( method_exists( $purge, $method ) ) {
                    try {
                        $purge->$method();
                        $purged = true;
                        sa_vf_log( "Kinsta page cache purged via {$method}()." );
                    } catch ( Throwable $e ) {
                        sa_vf_log( "Kinsta purge {$method}() threw: " . $e->getMessage() );
                    }
                    break;
                }
            }
            if ( ! $purged ) {
                sa_vf_log( 'Kinsta cache object found but no known purge method — API changed?' );
            }
        }
    }

    if ( ! $purged ) {
        sa_vf_log( 'No host page cache purged (Kinsta mu-plugin not detected).' );
    }

    // Record the version this purge covered, so the shutdown catch-all below
    // knows the current catalogue has already been published and does not purge
    // a second time for the same change.
    $memo =& sa_vf_memo();
    $memo['purged_ver'] = sa_vf_index_version();

    // Let other cache layers hook in without editing this file.
    do_action( 'sa_vf_page_cache_purged', $purged );

    return $purged;
}

/**
 * Catch-all: purge at end of request whenever the catalogue changed and nothing
 * has purged at that version yet.
 *
 * Wiring the purge into the two import passes was not enough. Plenty of other
 * things mutate vehicles and bump the index version — the 5-minute image sync,
 * the image-repair and broken-image crons, the daily sold-date pass, the daily
 * expired-sold deletion (which changes the vehicle *count*), and any manual
 * product edit in wp-admin. Each of those left the index correct and the cached
 * HTML stale, which is the same symptom the import fix was meant to end: right
 * when logged in, behind when not.
 *
 * Hooking the version bump itself rather than each individual caller means a
 * future cron cannot reintroduce this by forgetting to flush. The bump is
 * debounced to once per request, so this fires at most once per request, and
 * only on requests that actually wrote to a vehicle.
 */
add_action( 'shutdown', function () {
    $memo =& sa_vf_memo();
    if ( empty( $memo['bumped'] ) ) {
        return; // nothing touched a vehicle this request
    }
    if ( isset( $memo['purged_ver'] ) && $memo['purged_ver'] === sa_vf_index_version() ) {
        return; // already published at this version (the import path)
    }
    sa_vf_log( 'Catalogue changed outside an import pass — purging page cache.' );
    sa_vf_purge_page_cache();
}, 99 );

/**
 * Flush + warm the index, then drop the page cache that embeds it.
 *
 * Order matters. The version is force-bumped first so no mid-import rebuild can
 * already be holding the version we are about to write under. The index is then
 * warmed, and only once it is hot do we purge the page cache — purging first
 * would open a window where an anonymous visitor triggers a duplicate cold
 * build.
 */
function sa_vf_flush_caches() {
    sa_vf_drop_caches();

    $count = count( sa_vf_index() ); // warm the index
    sa_vf_client_data();             // warm the client dataset
    sa_vf_log( sprintf( 'Index rebuilt at version %d — %d vehicle(s).', sa_vf_index_version(), $count ) );
}

/**
 * The invalidation half of a flush: bump the version, drop every cached
 * derivative and purge the page cache — without warming anything.
 *
 * Purge before warming, not after.
 *
 * The warm renders a card for every vehicle in the catalogue, so it is by
 * far the most expensive step in a flush — and an import run is exactly where
 * the PHP timeout bites. A purge queued behind the warm never happens when the
 * request is killed part-way through it, leaving the transients dropped but
 * the stale HTML still being served: index correct, page wrong. Purging first
 * risks only a short window in which one visitor triggers a cold build;
 * purging last risks a day of stale stock for everyone.
 */
function sa_vf_drop_caches() {
    // Drop per-request memos before anything else: during an import these are
    // already populated with the pre-import catalogue, and warming from them
    // would re-cache stale rows under the fresh version.
    sa_vf_reset_memo();

    sa_vf_force_bump_index_version();
    delete_transient( 'sa_vf_index' );
    delete_transient( 'sa_vf_client' );
    // Drop legacy caches from earlier versions of this file.
    delete_transient( 'sa_vf_price_bounds' );
    delete_transient( 'sa_vf_make_model_map' );

    sa_vf_purge_page_cache();
}

/**
 * Purge from inside a pass that deletes vehicles one at a time.
 *
 * The prune pass and the expired-sold cron each delete N products in a loop and
 * flush once at the end. A product page goes 404 the instant its row is gone,
 * but the cached listing grid kept linking to it until that end-of-run flush —
 * minutes later when the loop is slow (each deletion also removes attachments),
 * or never when the run is killed first. Visitors clicking through from the
 * grid in that window got the theme 404. Calling this after each deletion
 * shrinks the window to at most $min_interval seconds.
 *
 * Throttled so a mass prune does not hit the host purge API once per vehicle,
 * and deliberately *not* recorded as the request's final purge: the shutdown
 * catch-all must still fire for whatever was deleted after the last mid-run
 * purge if the run dies before its own flush.
 *
 * @param int $min_interval Seconds between purges within one request. The
 *                          first call in a request always purges.
 */
function sa_vf_purge_page_cache_during_deletes( $min_interval = 30 ) {
    static $last_at = null; // a static, not a memo: sa_vf_drop_caches() wipes the memo
    $now = microtime( true );
    if ( $last_at !== null && ( $now - $last_at ) < $min_interval ) {
        return false;
    }
    $last_at = $now;

    // A full drop, not a bare page purge: the version bump is debounced to one
    // per request, so after the first deletion the index transient would be
    // rebuilt under a version that later deletions never move — and a cold
    // page build after a bare purge would read that stale index straight back.
    sa_vf_drop_caches();

    $memo =& sa_vf_memo();
    unset( $memo['purged_ver'] ); // keep the shutdown catch-all armed
    return true;
}
// Legacy signal from the retired WBW indexer. Kept so any event still queued in
// wp_cron from before this release resolves to the current flush; the import
// passes now call sa_vf_flush_caches() directly rather than relying on it.
add_action( 'wbw_custom_index_cron', 'sa_vf_flush_caches' );

/**
 * Manual rebuild trigger for admins: visit any wp-admin URL with
 * ?sa_vf_rebuild_index=1 to force the index (and response cache) to rebuild
 * immediately. Normally you never need this — the index rebuilds itself on the
 * next request after any vehicle change and is warmed after each import — but
 * it's handy for a one-off refresh or to confirm the vehicle count.
 */
add_action( 'admin_init', function () {
    if ( empty( $_GET['sa_vf_rebuild_index'] ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    sa_vf_flush_caches(); // bump version + drop caches + warm at the new version
    wp_die(
        esc_html( sprintf(
            'SA Motorlease: vehicle filter index rebuilt — %d vehicle(s) indexed (version %d).',
            count( sa_vf_index() ),
            sa_vf_index_version()
        ) ),
        'Index rebuilt',
        [ 'response' => 200 ]
    );
} );

/**
 * Timing diagnostic for admins: visit any wp-admin URL with ?sa_vf_diag=1 to
 * see where a filter request spends its time and — crucially — whether the
 * cached index is actually being reused between requests or rebuilt every time.
 * Reload the page: if "Index version" climbs on its own, something is bumping
 * the version on every request (constant rebuild).
 */
add_action( 'admin_init', function () {
    if ( empty( $_GET['sa_vf_diag'] ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $ms   = function ( $t ) { return sprintf( '%.1f ms', $t * 1000 ); };
    $ver  = sa_vf_index_version(); // numeric data version (for display)
    $cver = sa_vf_cache_version(); // composite key used for cache freshness
    $out  = [];

    $out[] = 'SA Motorlease — vehicle filter diagnostics';
    $out[] = str_repeat( '=', 52 );

    // Is a persistent object cache in play (Redis/Memcached, with size limits)?
    $ext = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
    $out[] = 'Persistent object cache: ' . ( $ext ? 'YES (Redis/Memcached or similar)' : 'no — transients live in wp_options' );

    // Is the cached index present and fresh RIGHT NOW (i.e. reused across
    // requests)? This is read before anything rebuilds, so it reflects what an
    // AJAX request would see.
    $t0    = microtime( true );
    $fresh = sa_vf_index_read( $cver ) !== null;
    $tread = microtime( true ) - $t0;
    $out[] = sprintf( 'Index version: %d (cache key %s)', $ver, $cver );
    $out[] = 'Cached index fresh & reusable: ' . ( $fresh
        ? 'YES — served from cache (' . $ms( $tread ) . ' to read)'
        : 'NO — every request rebuilds it  <<< this is the problem' );

    // If it's not fresh, is the transient even in the DB, and at what version?
    // Reading the raw option bypasses the transient API so we can tell a failed
    // write apart from a version mismatch (something bumping the version).
    if ( ! $fresh ) {
        $raw = get_option( '_transient_sa_vf_index' );
        if ( $raw === false ) {
            $out[] = '  → raw transient in wp_options: MISSING (the write is not persisting / being cleared)';
        } else {
            $sver = 'unknown';
            if ( is_string( $raw ) && strlen( $raw ) > 2 ) {
                $tag = substr( $raw, 0, 2 );
                $b   = substr( $raw, 2 );
                if ( $tag === 'g:' && function_exists( 'gzuncompress' ) ) {
                    $b = @gzuncompress( (string) base64_decode( $b ) );
                }
                $d = is_string( $b ) ? @unserialize( $b ) : false;
                if ( is_array( $d ) && isset( $d['ver'] ) ) $sver = (string) $d['ver'];
            }
            $out[] = sprintf( '  → raw transient in wp_options: present, stored at version %s vs current %s %s',
                $sver, $cver, ( $sver !== (string) $cver ) ? '(VERSION MISMATCH — a hook is bumping it)' : '(same version — cleared between requests?)' );
        }
    }

    // Cold build cost + size.
    $t0       = microtime( true );
    $rows     = sa_vf_build_index();
    $build    = microtime( true ) - $t0;
    $ser      = serialize( $rows );
    $raw_kb   = strlen( $ser ) / 1024;
    $gz_kb    = function_exists( 'gzcompress' ) ? strlen( gzcompress( $ser, 6 ) ) / 1024 : -1;
    $out[]    = sprintf( 'Vehicles indexed: %d', count( $rows ) );
    $out[]    = 'Index build (cold): ' . $ms( $build );
    $out[]    = sprintf( 'Index size: %.0f KB raw%s', $raw_kb, $gz_kb >= 0 ? sprintf( ' / %.0f KB gzipped', $gz_kb ) : '' );

    // Does the actual (compressed) index blob persist in the cache backend?
    // (Memcached silently drops values over ~1 MB, which would force a rebuild
    // each request.) Test the exact string sa_vf_index_write() would store.
    $body   = serialize( [ 'ver' => $ver, 'rows' => $rows ] );
    $stored = function_exists( 'gzcompress' ) ? 'g:' . base64_encode( gzcompress( $body, 6 ) ) : 'r:' . $body;
    set_transient( 'sa_vf_diag_probe', $stored, 120 );
    $rt        = get_transient( 'sa_vf_diag_probe' );
    $persisted = is_string( $rt ) && $rt === $stored;
    delete_transient( 'sa_vf_diag_probe' );
    $out[] = sprintf( 'Stored index blob: %.0f KB (what actually goes in the cache)', strlen( $stored ) / 1024 );
    $out[] = 'That blob round-trips through the cache: ' . ( $persisted
        ? 'OK — the cache keeps it'
        : 'FAILED — the cache is dropping it (size limit?) <<< forces rebuild every request' );

    // Per-request query timings with the index warm.
    $args = sa_vf_parse_args( [] );
    $t0 = microtime( true ); $ids = sa_vf_matching_ids( $args ); $tfilter = microtime( true ) - $t0;
    $t0 = microtime( true ); $ids = sa_vf_sort_ids( $ids, 'featured' ); $tsort = microtime( true ) - $t0;
    $t0 = microtime( true ); $av  = sa_vf_available( $args ); $tavail = microtime( true ) - $t0;
    $t0 = microtime( true ); $res = sa_vf_run_query( $args ); $trun = microtime( true ) - $t0;
    $out[] = '';
    $out[] = 'Per-request work (default view, index warm):';
    $out[] = '  match/filter: ' . $ms( $tfilter );
    $out[] = '  sort:         ' . $ms( $tsort );
    $out[] = '  availability: ' . $ms( $tavail );
    $out[] = '  run_query (+ 15-card render): ' . $ms( $trun );
    $out[] = sprintf( '  results: %d', $res['total'] );

    $pk    = sa_vf_payload_key( $args );
    $out[] = 'Response cache for default view: ' . ( get_transient( $pk ) !== false ? 'present' : 'absent' );

    $out[] = '';
    $out[] = 'How to read this:';
    $out[] = '  • "Cached index fresh" = NO, or "round-trips" = FAILED → the index';
    $out[] = '     is rebuilt every request; that build time is your 3–5s.';
    $out[] = '  • Everything fast here but the live filter still slow → the cost is';
    $out[] = '     WordPress/admin-ajax bootstrap (theme + other plugins), not this code.';
    $out[] = '  • Reload this page a few times: if "Index version" keeps rising with no';
    $out[] = '     edits, a hook is bumping the version every request.';

    wp_die(
        '<pre style="font:13px/1.6 ui-monospace,monospace;padding:16px;white-space:pre-wrap">' . esc_html( implode( "\n", $out ) ) . '</pre>',
        'SA VF diagnostics',
        [ 'response' => 200 ]
    );
} );

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
        // "Available Only" is OFF by default — sold vehicles are listed alongside
        // available ones (badged SOLD) unless the visitor opts to hide them
        // (available_only=1). Links shared while the old default was in force
        // carry show_sold=1, which now resolves to the same view either way.
        'hide_sold'=> in_array( $str( 'available_only' ), [ '1', 'on', 'true', 'yes' ], true ),
        'km'       => $str( 'km' ),
        'price'    => '', // max-price ceiling key (see sa_vf_price_buckets)
        'region'   => 0, // product_cat location term the visitor chose in the Region filter
        'category' => 0, // product_cat term the whole view is locked to (location archives)
        'facets'   => [],
    ];

    // Max price ceiling — validated against the known set.
    $price = $str( 'price' );
    if ( $price !== '' ) {
        foreach ( sa_vf_price_buckets() as $b ) {
            if ( $b['key'] === $price ) { $args['price'] = $price; break; }
        }
    }

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
        // Region (category facet) is a product_cat term id, handled below.
        if ( ! empty( $facet['category'] ) ) {
            $val = $str( $facet['key'] );
            if ( $val !== '' && ctype_digit( $val ) && term_exists( (int) $val, 'product_cat' ) ) {
                $args['region'] = (int) $val;
            }
            continue;
        }
        $val = $str( $facet['key'] );
        if ( $val !== '' ) {
            $args['facets'][ $facet['key'] ] = sanitize_title( $val );
        }
    }
    return $args;
}

/**
 * Filter the cached index to the rows matching $args. Pure in-memory work — the
 * facets are ANDed, the price/km buckets are half-open [min, max) ranges, and
 * the category match uses the pre-expanded ancestor set so a province term
 * covers its area children.
 */
function sa_vf_filter_rows( array $args ) {
    $rows   = sa_vf_index();
    $facets = ! empty( $args['facets'] ) ? $args['facets'] : [];
    $price  = sa_vf_price_bucket_by_key( $args['price'] ?? '' );
    $km     = sa_vf_km_bucket_by_key( $args['km'] ?? '' );
    $region = (int) ( $args['region'] ?? 0 );
    $cat    = (int) ( $args['category'] ?? 0 );
    $hide   = ! empty( $args['hide_sold'] );

    $out = [];
    foreach ( $rows as $r ) {
        if ( $hide && $r['sold'] ) continue;
        if ( $cat && ! in_array( $cat, $r['cats'], true ) ) continue;
        // Region: the chosen province/area (and its children, via the expanded
        // cats) must be one of the vehicle's location categories.
        if ( $region && ! in_array( $region, $r['cats'], true ) ) continue;
        if ( $price && ( $r['price'] === null || $r['price'] < $price['min'] || $r['price'] >= $price['max'] ) ) continue;
        if ( $km && ( $r['km'] === null || $r['km'] < $km['min'] || $r['km'] >= $km['max'] ) ) continue;

        $ok = true;
        foreach ( $facets as $k => $slug ) {
            if ( $slug === '' ) continue;
            if ( ! in_array( $slug, $r['facets'][ $k ] ?? [], true ) ) { $ok = false; break; }
        }
        if ( $ok ) $out[] = $r;
    }
    return $out;
}

/** Matching product IDs (unpaginated), in catalogue order. */
function sa_vf_matching_ids( array $args ) {
    $ids = [];
    foreach ( sa_vf_filter_rows( $args ) as $r ) $ids[] = $r['id'];
    return $ids;
}

/**
 * For each facet, the option values still possible given the OTHER active
 * filters (a facet's own selection is excluded so the user can switch within
 * it). Powers hiding impossible options in the dropdowns.
 */
function sa_vf_available( array $args ) {
    $full  = sa_vf_filter_rows( $args );
    $avail = [];

    foreach ( sa_vf_facets() as $facet ) {
        $k = $facet['key'];
        if ( ! empty( $facet['category'] ) ) continue; // Region handled below
        if ( ! empty( $args['facets'][ $k ] ) ) {
            $a2 = $args;
            unset( $a2['facets'][ $k ] ); // ignore this facet's own selection
            $rows = sa_vf_filter_rows( $a2 );
        } else {
            $rows = $full;
        }
        $slugs = [];
        foreach ( $rows as $r ) {
            foreach ( ( $r['facets'][ $k ] ?? [] ) as $s ) $slugs[ $s ] = 1;
        }
        $avail[ $k ] = array_keys( $slugs );
    }

    // Region (location categories still reachable, excluding its own selection).
    if ( ! empty( $args['region'] ) ) {
        $a2 = $args;
        $a2['region'] = 0;
        $rows = sa_vf_filter_rows( $a2 );
    } else {
        $rows = $full;
    }
    $region_set = [];
    foreach ( $rows as $r ) {
        foreach ( $r['cats'] as $cid ) $region_set[ (string) $cid ] = 1;
    }
    $avail['region'] = array_keys( $region_set );

    // Km buckets (exclude the km selection itself).
    if ( ( $args['km'] ?? '' ) !== '' ) {
        $a2 = $args;
        $a2['km'] = '';
        $rows = sa_vf_filter_rows( $a2 );
    } else {
        $rows = $full;
    }
    $km_set = [];
    foreach ( $rows as $r ) {
        if ( $r['km'] === null ) continue;
        foreach ( sa_vf_km_buckets() as $b ) {
            if ( $r['km'] >= $b['min'] && $r['km'] < $b['max'] ) { $km_set[ $b['key'] ] = 1; break; }
        }
    }
    $avail['km'] = array_keys( $km_set );

    // Max-price ceilings (exclude the price selection itself), so the dropdown
    // hides ceilings with nothing under them given the other filters. The
    // ceilings are nested, so a row counts towards every one it falls under —
    // stopping at the first would leave only the lowest ceiling available.
    if ( ( $args['price'] ?? '' ) !== '' ) {
        $a2 = $args;
        $a2['price'] = '';
        $rows = sa_vf_filter_rows( $a2 );
    } else {
        $rows = $full;
    }
    $price_set = [];
    $price_buckets = sa_vf_price_buckets();
    foreach ( $rows as $r ) {
        if ( $r['price'] === null ) continue;
        foreach ( $price_buckets as $b ) {
            if ( $r['price'] >= $b['min'] && $r['price'] < $b['max'] ) $price_set[ $b['key'] ] = 1;
        }
    }
    $avail['price'] = array_keys( $price_set );

    return $avail;
}

/** Numeric value of a product's single-value attribute term, or null. */
function sa_vf_term_num( $id, $taxonomy ) {
    $terms = get_the_terms( $id, $taxonomy );
    if ( ! $terms || is_wp_error( $terms ) ) return null;
    $t = reset( $terms );
    return (int) preg_replace( '/[^0-9]/', '', $t->name );
}

/**
 * Sort matching IDs from the index (no DB) honouring the sort key.
 *
 * Sold vehicles are NOT demoted. They previously sorted below every available
 * one, which pushed them onto the last page — and with sold stock now shown by
 * default, that would mean a visitor sorting by price got a price-ordered list
 * that silently excluded the sold vehicles until they paged to the very end.
 * They now take their natural place in the chosen order, distinguished by the
 * SOLD badge on the card rather than by position.
 */
function sa_vf_sort_ids( array $ids, $sort ) {
    $map = sa_vf_index_by_id();
    $pos = array_flip( $ids ); // incoming catalogue order → 'featured' fallback

    usort( $ids, function ( $a, $b ) use ( $map, $pos, $sort ) {
        $ra = $map[ $a ] ?? null;
        $rb = $map[ $b ] ?? null;

        switch ( $sort ) {
            case 'price_asc':  return (float) ( $ra['price'] ?? 0 ) <=> (float) ( $rb['price'] ?? 0 );
            case 'price_desc': return (float) ( $rb['price'] ?? 0 ) <=> (float) ( $ra['price'] ?? 0 );
            case 'year_desc':  return (int)   ( $rb['year']  ?? 0 ) <=> (int)   ( $ra['year']  ?? 0 );
            case 'year_asc':   return (int)   ( $ra['year']  ?? 0 ) <=> (int)   ( $rb['year']  ?? 0 );
            case 'km_asc':     return (int)   ( $ra['km']    ?? 0 ) <=> (int)   ( $rb['km']    ?? 0 );
            case 'newest':     return $b <=> $a; // higher post ID = more recent
            case 'featured':
            default:           return ( $pos[ $a ] ?? 0 ) <=> ( $pos[ $b ] ?? 0 );
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
        // On the initial server render the first row of cards is a likely LCP
        // candidate, so raise its fetch priority. AJAX re-renders happen after
        // first paint and never need it.
        $priority_first = wp_doing_ajax() ? 0 : 4;
        foreach ( array_values( $slice ) as $i => $id ) {
            echo sa_vf_render_card( $id, $i < $priority_first ); // phpcs:ignore WordPress.Security.EscapeOutput
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

function sa_vf_render_card( $id, $priority = false ) {
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

    // Prefer the pre-computed index (no extra term queries); fall back to a
    // direct lookup for any id that isn't a published vehicle in the index.
    $row = sa_vf_index_by_id()[ $id ] ?? null;
    if ( $row ) {
        $km_val  = $row['km'];
        $is_sold = $row['sold'];
    } else {
        $km_val     = sa_vf_term_num( $id, 'pa_kilometers' );
        $sold_terms = get_the_terms( $id, 'pa_sold' );
        $is_sold    = $sold_terms && ! is_wp_error( $sold_terms ) && strcasecmp( reset( $sold_terms )->name, 'Yes' ) === 0;
    }

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
                 loading="eager" decoding="async" data-skip-lazy="1"<?php echo $priority ? ' fetchpriority="high"' : ''; ?>>
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

/** Cache key for a query payload — the index version + the output-affecting args. */
function sa_vf_payload_key( array $args ) {
    $facets = $args['facets'] ?? [];
    ksort( $facets );
    $norm = [
        'sort'      => $args['sort'] ?? 'featured',
        'page'      => (int) ( $args['page'] ?? 1 ),
        'hide_sold' => ! empty( $args['hide_sold'] ) ? 1 : 0,
        'km'        => $args['km'] ?? '',
        'price'     => $args['price'] ?? '',
        'region'    => (int) ( $args['region'] ?? 0 ),
        'category'  => (int) ( $args['category'] ?? 0 ),
        'facets'    => $facets,
    ];
    return 'sa_vf_pl_' . sa_vf_cache_version() . '_' . md5( wp_json_encode( $norm ) );
}

/**
 * The full response (results HTML + available options) for a set of args,
 * cached per index-version so repeated/identical requests — paging, toggling
 * back and forth, common filter combos — return instantly without re-rendering.
 * Invalidated automatically when the index version bumps (i.e. on any vehicle
 * change), so it never serves stale stock.
 */
function sa_vf_payload( array $args ) {
    $key    = sa_vf_payload_key( $args );
    $cached = get_transient( $key );
    if ( is_array( $cached ) ) return $cached;

    $result = sa_vf_run_query( $args );
    $result['available'] = sa_vf_available( $args );
    set_transient( $key, $result, 30 * MINUTE_IN_SECONDS );
    return $result;
}

function sa_vf_ajax_query() {
    // Public, read-only endpoint (only surfaces already-public published
    // products) — mirrors the /qualified-vehicles REST route. Not nonce-gated
    // so it keeps working behind full-page caching where a localized nonce
    // would otherwise go stale and 403 every filter request.
    $t0   = microtime( true );
    $args = sa_vf_parse_args( $_POST );
    $data = sa_vf_payload( $args );

    // Timing breakdown so the real cost is visible in the Network tab:
    //   boot_ms = WordPress + theme + all plugins initialising BEFORE this
    //             handler runs (the admin-ajax bootstrap). If this dominates,
    //             the slowness is the site's stack, not the filter.
    //   work_ms = this handler's own work (query + render, or cache hit).
    $req = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $t0;
    $data['_t'] = [
        'boot_ms' => (int) round( ( $t0 - $req ) * 1000 ),
        'work_ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
    ];
    wp_send_json_success( $data );
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

    // Same cached payload the AJAX endpoint returns, so the first paint is
    // instant and the availability map is computed once, not twice.
    $result = sa_vf_payload( $initial );

    // On a category page the Region dropdown navigates between location
    // archives (scoped to the current province) instead of filtering.
    $region_nav = $initial['category'] ? sa_vf_region_nav( $initial['category'] ) : null;

    // Ship the whole catalogue to the browser so filtering/sorting/paging run
    // client-side with no admin-ajax round-trip (each of which pays the full
    // WordPress bootstrap). The first page is still server-rendered below for
    // SEO and the no-JS fallback; JS takes over on load.
    wp_localize_script( 'sa-vehicle-filter', 'SA_VF', [
        'vehicles'     => sa_vf_client_data(),
        'perPage'      => (int) SA_VF_PER_PAGE,
        'sort'         => sa_vf_sort_options(),
        'priceBuckets' => sa_vf_buckets_for_js( sa_vf_price_buckets() ),
        'kmBuckets'    => sa_vf_buckets_for_js( sa_vf_km_buckets() ),
        'category'     => (int) $initial['category'], // location scope (province/area)
        // Kept for any external caller / graceful fallback; the client no longer
        // needs a round-trip for normal filtering.
        'ajax_url'     => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'sa_vf' ),
    ] );

    ob_start();
    include __DIR__ . '/vehicle-filter-template.php';
    return ob_get_clean();
}

/* ===========================================================================
 * Featured listings carousel (category-scoped)
 * ======================================================================== */

/** tax_query clause excluding sold vehicles, or null if the taxonomy is absent. */
function sa_vf_not_sold_clause() {
    if ( ! taxonomy_exists( 'pa_sold' ) ) return null;
    return [ 'taxonomy' => 'pa_sold', 'field' => 'name', 'terms' => 'Yes', 'operator' => 'NOT IN' ];
}

/**
 * De-dupe key for a vehicle at the requested granularity, read from the cached
 * index (no extra queries):
 *   'make'       → one card per make (brand)
 *   'make_model' → one card per make + model
 *   'none' / other → '' (no de-dup)
 * Returns '' when the vehicle lacks the needed attribute, so it's treated as
 * unique rather than collapsed with others.
 */
function sa_vf_dedupe_key( $id, $mode ) {
    if ( $mode !== 'make' && $mode !== 'make_model' ) return '';

    // Belt-and-braces: the callers normalise their query results, but an
    // object reaching the array lookup below is a fatal, not a warning.
    $id = $id instanceof WP_Post ? (int) $id->ID : (int) $id;

    $row = sa_vf_index_by_id()[ $id ] ?? null;
    if ( $row ) {
        $mk = $row['facets']['make'][0]  ?? '';
        $md = $row['facets']['model'][0] ?? '';
    } else {
        $mkt = get_the_terms( $id, 'pa_make' );
        $mk  = ( $mkt && ! is_wp_error( $mkt ) ) ? reset( $mkt )->slug : '';
        $mdt = ( $mode === 'make_model' ) ? get_the_terms( $id, 'pa_model' ) : false;
        $md  = ( $mdt && ! is_wp_error( $mdt ) ) ? reset( $mdt )->slug : '';
    }

    if ( $mode === 'make' ) return $mk === '' ? '' : 'mk:' . $mk;
    return ( $mk === '' && $md === '' ) ? '' : 'mm:' . $mk . '|' . $md;
}

/** Normalise a dedupe mode from a shortcode attribute. */
function sa_vf_dedupe_mode( $val ) {
    $v = strtolower( trim( (string) $val ) );
    if ( in_array( $v, [ 'make_model', 'make-model', 'model' ], true ) ) return 'make_model';
    if ( in_array( $v, [ 'none', 'off', 'no', '0' ], true ) )            return 'none';
    return 'make'; // default: one card per brand
}

/**
 * Product IDs for the featured strip: every WooCommerce "Featured" product in
 * the given category (hand-picked, so never collapsed or capped), topped up
 * with the newest vehicles in that category when fewer than $limit are
 * flagged. category_id 0 = whole catalogue.
 */
function sa_vf_featured_ids( $category_id = 0, $limit = 8, $dedupe = 'make' ) {
    $limit = max( 1, (int) $limit );
    // Pull a generous top-up pool so that, after collapsing repeats, we can
    // still fill the slider with distinct vehicles.
    $pool = max( $limit * 8, 48 );

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

    // Flagged vehicles are a deliberate merchant choice: show every one of
    // them, newest first, regardless of $limit or the de-dupe mode. (Both used
    // to apply here too, so with 17 flagged vehicles across 9 makes the strip
    // showed 8 — one per make — and padded the rest with unflagged stock.)
    // The strip only *renders* $limit at a time — see sa_vf_render_featured.
    $out = sa_vf_post_ids( get_posts( [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'tax_query'      => $featured,
    ] ) );
    $out = array_values( array_unique( array_map( 'intval', $out ) ) );

    // The de-dupe only shapes the top-up: seed it with the makes / models the
    // flagged vehicles already cover so the filler adds variety, not repeats.
    $seen = [];
    foreach ( $out as $id ) {
        $key = sa_vf_dedupe_key( $id, $dedupe );
        if ( $key !== '' ) $seen[ $key ] = 1;
    }
    $take = function ( $ids ) use ( &$out, &$seen, $limit, $dedupe ) {
        foreach ( $ids as $id ) {
            if ( count( $out ) >= $limit ) break;
            if ( in_array( (int) $id, $out, true ) ) continue;
            $key = sa_vf_dedupe_key( $id, $dedupe );
            if ( $key !== '' ) {
                if ( isset( $seen[ $key ] ) ) continue; // already showing this make / make+model
                $seen[ $key ] = 1;
            }
            $out[] = (int) $id;
        }
    };

    if ( count( $out ) < $limit ) {
        $fill_args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $pool,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post__not_in'   => $out ?: [ 0 ],
        ];
        if ( $base ) $fill_args['tax_query'] = ( count( $base ) > 1 ) ? array_merge( [ 'relation' => 'AND' ], $base ) : $base;
        $take( sa_vf_post_ids( get_posts( $fill_args ) ) );
    }

    return $out;
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

/**
 * Render the featured strip. Returns '' when there are no products.
 *
 * Only the first $limit cards are in the page; the rest ride along as IDs in
 * data-more and are fetched a batch at a time (sa_vf_ajax_cards) as the
 * visitor keeps clicking / dragging towards the end of the strip. Keeps the
 * initial HTML the same size however many vehicles are flagged.
 */
function sa_vf_render_featured( $category_id = 0, $limit = 8, $title = 'Featured Listings', $full = false, $attrs = [], $extra_class = '', $dedupe = 'make' ) {
    $limit = max( 1, (int) $limit );
    $ids   = sa_vf_featured_ids( $category_id, $limit, $dedupe );
    if ( ! $ids ) return '';

    $first = array_slice( $ids, 0, $limit );
    $more  = array_slice( $ids, $limit );

    $items = '';
    // The strip renders above the grid, so its first couple of cards are the
    // usual LCP candidates on archive pages — fetch those at high priority.
    foreach ( $first as $i => $id ) {
        $items .= '<div class="sa-vf-featured__item">' . sa_vf_render_card( $id, $i < 2 ) . '</div>';
    }
    if ( $more ) {
        $attrs['data-more']  = implode( ',', $more );
        $attrs['data-batch'] = $limit;
        $attrs['data-ajax']  = admin_url( 'admin-ajax.php' );
    }
    return sa_vf_featured_shell( $items, $title, $full, $attrs, '', $extra_class );
}

add_action( 'wp_ajax_sa_vf_cards',        'sa_vf_ajax_cards' );
add_action( 'wp_ajax_nopriv_sa_vf_cards', 'sa_vf_ajax_cards' );

/**
 * Next batch of carousel cards for a comma-separated list of product IDs, in
 * the order given. Public product markup only, so — like sa_vf_qualified —
 * not nonce-gated (the calling page is served from the full-page cache).
 * IDs that are no longer live products (sold-and-deleted since the page was
 * cached) are simply skipped.
 */
function sa_vf_ajax_cards() {
    $raw = isset( $_POST['ids'] ) ? (string) wp_unslash( $_POST['ids'] ) : '';
    $ids = array_values( array_unique( array_filter( array_map( 'intval', explode( ',', $raw ) ) ) ) );
    $ids = array_slice( $ids, 0, 30 );

    $html  = '';
    $count = 0;
    foreach ( $ids as $id ) {
        if ( get_post_type( $id ) !== 'product' || get_post_status( $id ) !== 'publish' ) continue;
        $card = sa_vf_render_card( $id );
        if ( $card === '' ) continue;
        $html .= '<div class="sa-vf-featured__item">' . $card . '</div>';
        $count++;
    }
    wp_send_json_success( [ 'html' => $html, 'count' => $count ] );
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
        'limit'     => 8,                 // cards per batch; flagged vehicles all show (loaded as the visitor scrolls), newest stock tops up to this
        'title'     => 'Featured Listings',
        'full'      => 'no',              // "yes" = full-bleed width
        'max_width' => '',                // e.g. 1100 or 1100px to tighten the container
        'bg'        => '',                // panel background colour ('' / none = transparent)
        'dedupe'    => 'make',            // top-up only: 'make' (one per brand), 'make_model', or 'none'
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
        $bg['class'], sa_vf_dedupe_mode( $atts['dedupe'] )
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
    foreach ( sa_vf_post_ids( $q->posts ) as $id ) {
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
