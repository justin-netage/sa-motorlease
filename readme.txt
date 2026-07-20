=== SA Motorlease ===
Contributors: netage
Tags: woocommerce, vehicles, importer, paceapp, gravityforms
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.6.7
License: GPLv2 or later

Combined SA Motorlease plugin: PaceApp vehicle importer plus lead-qualification, application forwarding and frontend helpers for the SA Motorlease site.

== Description ==

This plugin merges two previously-separate plugins (sa-motorlease-product-importer and sa-motorlease-vehicle-importer) into a single bundle.

**Vehicle importer (vi_* pipeline)**

* Hourly create + update crons (offset 32 min) that pull from the PaceApp feed
* Per-run create/update caps and wall-clock budgets
* Embedded-image decoding from the feed and diff-based gallery sync
* Image-repair cron and bulk image-sync admin UI
* WBW index trigger after each run
* Optional pruning of products not in the feed
* Per-plugin log files

**Lead qualification and application flow**

* `lead_qualifications` DB table (created on activation)
* REST endpoints: `/samotorlease/v1/qualify-lead`, `/qualified-vehicles`, `/partial-save`, `/update-deposit-special`, `/custom-api/v1/sold-products`
* Gravity Form #5 forwarding to PaceApp `paceWebUpdateLead`
* Frontend script enqueues for the qualification, results and application pages
* Vehicle-locations carousel data (Flickity)
* `[qualified_results_vehicles]` and `[cheapest_price]` shortcodes
* WooCommerce "no products found" override

**Maintenance / admin URL triggers** (all gated by `manage_options` or `manage_woocommerce`)

* `?update_license_plates`, `?deduplicate_products`, `?export_attributes`
* `?fix_broken_images`, `?test_image_feed=<sku>`
* `?run_payments_backfill`, `?format_deposit_special`, `?cleanup_initiation_fee_special`
* `?cleanup_sold_products`, `?update_sold_from_feed`, `?remove_by_reg_cleanup`
* `?log_missing_products`, `?remove_missing_products`, `?backfill_image_alt`

== Installation ==

1. Upload the `sa-motorlease` folder to `/wp-content/plugins/`.
2. Deactivate any previous `SA Motorlease Product Importer` or `Vehicle Importer` plugins.
3. Activate **SA Motorlease** in **Plugins**.

== Updates ==

This plugin self-updates via [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker), pointed at https://github.com/justin-netage/sa-motorlease (branch `main`, release assets). To ship an update: bump the `Version:` header and `SA_MOTORLEASE_VERSION` constant, commit, then publish a GitHub Release whose tag matches the new version. A workflow attaches the build zip automatically.

== Changelog ==

= 2.6.7 =
* **Fixed remaining iPhone/Safari "Something went wrong" error on the application form.** The 2.6.6 fix removed a duplicate-submission race, which revealed the underlying cause: the first `partial-save` request genuinely fails on iPhone/Safari (the duplicate had been accidentally providing a working retry). The request now retries once automatically before showing an error.

= 2.6.6 =
* **Fixed iPhone/Safari application form bugs.** The "Next" button on page 1 had no in-flight guard, so a slow mobile connection plus no visual feedback could let a second tap fire a duplicate partial-save request; if the two raced, one could return an error while the other silently succeeded and advanced the form, showing a false "Something went wrong" error even though the application went through. The final Submit button on page 2 also showed its loading overlay before Gravity Forms validation ran and never cleared it on a validation failure, leaving it stuck over the page so a corrected resubmission's tap didn't register — it's now cleared on every form re-render.

= 2.6.5 =
* **Mobile filter button is bigger and more noticeable.** The floating "Filters" button is wider (min-width + more horizontal padding), has a bolder shadow, and gets a subtle pulsing glow so it's easier to spot once it appears past the featured carousel.

= 2.6.4 =
* **Slimmer mobile filter button, hidden over the featured carousel.** The floating "Filters" button on mobile is now more compact (less padding/font size) and stays hidden until the visitor scrolls past the featured-vehicles carousel at the top of the listings page, instead of floating over it immediately.

= 2.6.3 =
* **Featured / other sliders de-dupe by brand by default.** The `[sa_featured_vehicles]` carousels now show at most one card per **make** (brand) — so a strip won't fill with three Citroens even when they're different model lines (C3, C3 Plus, …). The granularity is configurable per slider via a new `dedupe` attribute: `dedupe="make"` (default, one per brand), `dedupe="make_model"` (one per make+model — the 2.6.2 behaviour), or `dedupe="none"`. Make/model still come from the cached index, so there's no extra query cost.

= 2.6.2 =
* **Featured / other sliders no longer repeat the same vehicle.** The `[sa_featured_vehicles]` carousels show at most one card per make + model. It draws from the WooCommerce "Featured" products first, then tops up with the newest vehicles in scope, skipping duplicates (make/model taken from the cached index, so no extra queries). Vehicles with no make/model set are still shown. (Superseded by 2.6.3's brand-level default.)

= 2.6.1 =
* **Region filters by location category, not the attribute.** The Region dropdown now filters on the product-category location tree the importer assigns (province → area) instead of the `pa_region` product attribute. Choosing a parent province (e.g. Gauteng) now correctly returns vehicles in all of its child areas (Sandton, Pretoria, …), and the dropdown lists provinces with their areas indented beneath them. On the location archive pages the Region field keeps behaving as the province navigator.
* **Cache busts on plugin update.** The index and browser dataset caches now key on the plugin's asset version as well as the data version, so a plugin update that changes their shape refreshes them on the next page load instead of waiting for the next stock change.

= 2.6.0 =
Makes the vehicle filter instant on any host by moving filtering into the browser.

* **Client-side filtering.** The whole published catalogue (each vehicle's price, sold status, mileage, year, facet slugs, category tree and pre-rendered card HTML) is shipped to the browser once with the page. Changing a filter, sort or page now happens entirely in JavaScript with **no `admin-ajax.php` round-trip** — so it's instant even on sites where WordPress takes a couple of seconds to bootstrap each request (which diagnostics showed was the real cause of the multi-second waits, not the filter's own query). The first page is still server-rendered for SEO and the no-JS fallback, and shareable filter URLs still work.
* **Dataset cache.** That browser dataset is built once, stored compressed alongside the index, versioned the same way, and re-warmed after each import — so it always matches current stock without a per-request rebuild.

= 2.5.3 =
Adds timing instrumentation to pinpoint slow filter responses.

* **Per-request timing in the AJAX response.** The filter's AJAX reply now carries a `_t` object with `boot_ms` (WordPress + theme + all other plugins initialising before the handler runs) and `work_ms` (this plugin's own query/render). Viewing it in the Network tab shows immediately whether slowness is the site's bootstrap or this filter.
* **Deeper index diagnostic.** When `?sa_vf_diag=1` reports the index isn't being reused, it now also reads the raw transient from `wp_options` and reports whether the write is missing entirely or stored at a mismatched version — distinguishing a cache-persistence problem from something bumping the version every request.

= 2.5.2 =
Diagnoses and hardens the filter's index cache after reports of slow filtering on sites with a persistent object cache.

* **Compressed index cache.** The vehicle index is now stored gzip-compressed (and base64-encoded so it's safe in both `wp_options` and object caches). A large catalogue previously produced an index bigger than the ~1 MB value limit some object caches (e.g. Memcached) enforce; the backend silently dropped it, so the index was rebuilt on *every* request — which is what made filtering take several seconds. Compression brings it well under that limit so it actually persists and is reused.
* **Timing diagnostic.** Admins can visit any wp-admin URL with `?sa_vf_rebuild_index=1` (rebuild) or `?sa_vf_diag=1` (diagnose). The diagnostic reports whether the cached index is being reused or rebuilt each request, the build time, the stored blob size, whether that blob survives a round-trip through the cache, and a per-request timing breakdown — so the source of any slowness is unambiguous.

= 2.5.1 =
* **Monthly Payment is now a dropdown.** The monthly-payment filter matches the other facets — a single select field ("Monthly Payment" → the bucket options) instead of a radio list. Impossible ranges grey out based on the other active filters, exactly like the Kilometers dropdown.
* **Manual index rebuild.** Admins can force the filter's vehicle index to rebuild by visiting any wp-admin URL with `?sa_vf_rebuild_index=1` (it reports how many vehicles were indexed). The index still rebuilds automatically after any vehicle change and is warmed after each import, so this is only for a one-off refresh.

= 2.5.0 =
Custom vehicle filter: new Monthly Payment filter, an admin-only enable switch, and an instant-loading rebuild.

Filter UX:

* **Monthly Payment filter.** The dual price slider is replaced with a set of monthly-payment buckets (Under R6,000, R6,001–R7,999, R8,000–R9,999, R10,000–R12,999, R13,000–R15,999, R16,000+) chosen with radio buttons — clearer and faster to tap than dragging a slider.
* **"Available Only" toggle.** The "Hide sold vehicles" checkbox becomes a pre-checked **Available Only** toggle: sold vehicles are hidden by default, and unchecking it shows them.
* **Floating mobile filter button.** On phones the filter opener is now a floating action button pinned to the bottom of the screen, with a badge showing how many filters are active.
* **Admin-only enable switch.** The custom vehicle filter can be turned on from the Vehicle Listings settings section. That switch is only visible to — and only changeable by — the designated admin (jake@netage.co.za); the `SA_MOTORLEASE_ENABLE_VEHICLE_FILTER` constant still forces it on when defined.

Performance:

* **In-memory vehicle index.** The whole published catalogue (price, sold status, mileage, year, every facet slug and the category tree) is pre-computed once into a single cached array. Filtering, faceting ("which options are still possible") and sorting now run entirely in memory with no per-request database queries — previously each request ran a full unpaginated `WP_Query` per selected facet plus a `get_post_meta`/`get_the_terms` for every matching vehicle, which is what made the filter slow.
* **Versioned cache that tracks the importer.** The index carries a version stamp that bumps on any vehicle change — create/update/delete, term reassignment (make/model/sold/…), and the price/image meta the PaceApp importer rewrites — so filter results never go stale. After an import the reindex signal rebuilds and re-warms the cache immediately, so the first visitor afterwards doesn't pay a cold rebuild.
* **Response cache.** Each filter response (results HTML + available options) is cached per index-version, so paging and common filter combinations return with no rendering at all. It clears automatically whenever the index version changes.

= 2.4.3 =
Cache-busting for changed vehicle images. The client updates photos often, and reusing the same media URL let Cloudflare / browsers keep serving the previous picture even after the file was correctly replaced on the server.

* **Content-addressed image filenames.** `vp_save_bytes_as_attachment()` now appends a short hash of the image bytes to the filename (e.g. `2021-renault-kwid-front-feature-photo-a1b2c3d4e5.jpg`). A changed image therefore always lands on a brand-new URL the CDN/browser has never cached, so it appears immediately with no cache purge required. Identical bytes still resolve to the same filename, so re-imports of an unchanged image never create duplicates. Existing attachments keep their current URLs; this applies to images uploaded from now on (i.e. the next time an image actually changes).

= 2.4.2 =
Follow-up to 2.4.1: fixes the underlying reason a changed feed image still would not attach, and adds an on-screen diagnostic so the cause is visible without server log access.

* **Uploader accepts WebP (and the common image types) in cron.** `vp_save_bytes_as_attachment()` now applies a scoped `upload_mimes` allowance around `wp_upload_bits()`. On a hardened install — or in WP-Cron, where there is no logged-in user — WordPress otherwise rejects any extension not in `get_allowed_mime_types()` (notably WebP) with "Sorry, this file type is not permitted", which failed the upload and, under 2.4.1's fail-safe, left the product on its old image. The upload-failure log line now also records the filename and mime.
* **Truthful sync counts.** The bulk sync now uses the diff result's `ok` flag: a run that rolled back (upload failure) is reported as **Failed**, not **Updated**. The progress page gained a red "Failed" tile so a silent rollback is obvious instead of showing a misleading "1 updated".
* **Read-only diagnostic endpoint.** `?vi_diag_images=1&sku=ABC` renders, without changing anything: the product(s) for that SKU, each current attachment (stored `_vi_image_md5` meta vs the real md5 of the file on disk, whether the file exists, and whether it is still in the feed), every feed image with its detected type and md5, the keep/upload decision the sync would make, and a live upload test — both as the site is configured now and with the 2.4.2 mime allowance — so a blocked file type or a stale hash is immediately visible.

= 2.4.1 =
Bugfix release: fixes vehicle images that changed in the PACE feed but never updated on the site, and could not be repaired by re-running the image sync.

* **Fail-safe image diff sync.** `vi_diff_sync_product_images()` used to delete the old attachments and advance the stored `_vi_images_hash` even when uploading the new feed images failed (a transient upload/DB/disk error makes `vp_save_bytes_as_attachment()` return `0`). That left the product on old/broken images while the hash claimed "current", so every cron tick and every manual `?vi_sync_images=1` run reported "unchanged" and the product could never self-heal. The diff sync now aborts on any upload failure: it keeps the product's current images, rolls back the partial uploads, and leaves the hash untouched so the next tick retries the whole set.
* **Hash guarded in the create/repair paths too.** The import-attach and image-repair passes only advance `_vi_images_hash` once the featured image and gallery meta have actually persisted, so a failed write no longer poisons the hash.
* **`force` re-sync flag.** `?vi_sync_images=1&sku=ABC&force=1` (and the batch endpoint's `force=1`) bypasses the stored-hash short-circuit and re-diffs the product against the live feed — the recovery path for any product already stuck with a hash that wrongly claims "current".
* **Custom vehicle filter gated off by default.** The 2.4.0 custom vehicle filter (shortcodes, AJAX endpoint and location-archive template takeover) is now behind the `SA_MOTORLEASE_ENABLE_VEHICLE_FILTER` feature flag, which defaults to `false`, so this release ships the image-sync fix without switching the front-end filter live while it's still being finalised. Re-enable it by defining `SA_MOTORLEASE_ENABLE_VEHICLE_FILTER` as `true` (e.g. in `wp-config.php`) or flipping the default in the plugin bootstrap.

= 2.4.0 =
New feature: a self-contained custom vehicle filter that replaces the third-party WBW / WooBeWoo Product Filter on the vehicles listing, with richer filtering than the plugin it supersedes. The existing WBW reindex/cleanup integration is left in place so the two can run side by side while the site is switched over.

* **`[sa_vehicle_filter]` shortcode.** Renders a filter sidebar plus an AJAX-driven results grid. Drop it on any page (e.g. a new Vehicles page) to run alongside the WBW filter, then switch over once you're happy. No theme template changes required. Accepts an optional `category="<product_cat id or slug>"` attribute to lock the whole view to one location.
* **Location category archives.** WooCommerce `product_cat` archives (the importer's province/area terms, e.g. `/listings/gauteng/sandton/`) are rendered through the custom filter, pre-scoped to that location, via a `template_include` takeover that keeps the theme header/footer and the SEO-friendly term URL, `<title>` and description. The location scope is fixed for the page and preserved on every AJAX request. The takeover also renders a category-scoped featured strip at the top and strips embedded shortcodes from the term description so any legacy carousel/filter button doesn't reappear.
* **`[sa_featured_vehicles]` shortcode.** A draggable featured-listings slider (prev/next arrows + pointer drag) scoped to a category — shows WooCommerce "Featured" products (falling back to the newest vehicles in that category), rendered with the exact same card component as the filter grid. Attributes: `category` (id/slug; defaults to the current archive term), `limit` (default 8), `title` (default "Featured Listings", pass `title=""` to omit), `full` (`yes` = full-bleed, edge-to-edge width).
* **`[sa_qualified_vehicles]` shortcode.** The same slider on the lead-qualification results page, filled client-side from the visitor's qualified monthly rental limit (read from the stored `lead_qualification` or a `?rental_limit=` URL param) — vehicles priced at or below the limit, one per model. Attributes: `title` (empty by default), `full`, `limit` (default 15). Shows a friendly message when there's no stored qualification or no matching vehicles.
* **`[sa_listings_disclaimer]` and `[sa_breadcrumbs]` shortcodes.** The disclaimer text is editable in **SA Motorlease → Settings → Vehicle Listings** (`listings_disclaimer`) and output by `[sa_listings_disclaimer]`; `[sa_breadcrumbs]` renders WooCommerce breadcrumbs.
* **`[sa_vehicle_listings]` shortcode.** One shortcode for the full listings page — featured slider, title + breadcrumb row, disclaimer and the filter — sharing the exact composition (`sa_vf_render_listings()`) used by the location-archive takeover, so the main `/listings` page and the category pages look identical. Attributes: `title` (default "Vehicle Listings"), `category`, and `featured`/`breadcrumbs`/`disclaimer` (yes/no toggles).
* **Region navigator.** On a location category page the Region dropdown lists the current province's areas (plus "All &lt;Province&gt;") and redirects to the chosen archive instead of filtering, so visitors stay within the province. On the main listings page Region filters as normal.
* **Facets.** New or Used, Make, Model, Region, Transmission, Body Type, Fuel and Year Model dropdowns, all populated from the `pa_*` attribute taxonomies the PaceApp importer already writes (empty facets are hidden automatically). Mileage is offered as sensible ranges (resolved to the underlying `pa_kilometers` terms), and the monthly rent-to-buy price is a dual-handle range slider bounded to the live catalogue min/max.
* **Better filter functions than WBW.** Dependent Make → Model narrowing, instant (no page reload) AJAX filtering, a live result count, seven sort modes (price, year, mileage, recently added), a "hide sold vehicles" toggle with sold cars always sorted last, numbered pagination and shareable/bookmarkable URL state (filters serialise to the query string).
* **Performance & safety.** Facet options, make/model map and price bounds are cached for 15 minutes and flushed on the importer's post-import reindex signal. The AJAX endpoint is public and read-only (surfaces only already-public published products, mirroring `/qualified-vehicles`) so it survives full-page caching. All request input is sanitised; output is escaped.

= 2.3.1 =
Bugfix release: fixes the plugin-activation database errors seen on environments where the `wp_lead_qualifications` table already exists (e.g. staging copies of production data).

* **dbDelta schema.** The `lead_qualifications` schema now follows dbDelta conventions (`PRIMARY KEY  (id)` on its own line, column types as MySQL reports them back), stopping the "Multiple primary key defined" ALTER error on every activation.
* **Duplicate lead_ids.** When the `lead_id_uniq` index is missing, activation now collapses duplicate `lead_id` rows (newest row kept, removed count logged) before dbDelta adds the unique key — fixes "Duplicate entry ... for key 'lead_id_uniq'" on data copied from test environments whose PACE sandbox returns the same lead_id for every submission.
* **Lead upsert.** `/qualify-lead` now upserts (`INSERT ... ON DUPLICATE KEY UPDATE` keyed on `lead_id`), so repeat submissions returning the same lead_id update the existing qualification row instead of failing the unique key with a 500.

= 2.3.0 =
Stable release of the 2.3.0 line (promotes 2.3.0-beta.1 and 2.3.0-beta.2). Bundles the post-2.2.7 plugin audit fixes — REST endpoint and admin URL trigger hardening, PII handling, performance and reliability work — together with the tightened ID/Passport validation. Includes everything from 2.2.4–2.2.7 (passport ID support, autofill revalidation, lead endpoint logging, Status page log tail truncation, PII masking). Stable tag is now 2.3.0.

* **ID/Passport validation.** Tightened the lead qualification form validation from 6-13 to 9-13 alphanumeric characters, rejecting inputs shorter than a valid SA passport / ID. Applied to both the client-side `idRx` in lead-qualification.js and the matching server-side check in the `/qualify-lead` REST endpoint so they stay in sync. Bumped the enqueued lead-qualification.js version to 1.2.5 to bust browser caches.
* **Security — REST endpoints.** `/update-deposit-special` now requires `manage_options` (was public). `/partial-save` whitelists the keys forwarded to PACE (`lead_id`, addresses, employment, bank_statements) so arbitrary client-supplied fields can no longer be proxied upstream.
* **Security — admin URL triggers.** All 15 destructive admin URL triggers now verify a nonce (`?cleanup_sold_products`, `?remove_missing_products`, `?wbw_cleanup_ghosts`, `?vi_repair_images`, `?vi_sync_images`, `?vi_sync_batch`, and 9 others). Status page gains a Tools section that generates each link with `wp_nonce_url()`.
* **PII.** Lead qualification data (ID number, phone, email, take-home pay) is no longer persisted in `localStorage` — `sessionStorage` only. Existing values in `localStorage` are evicted on load.
* **Gravity Forms #5 forwarder.** Bank statements (fields 32/33/34) are now sent as separate `paceWebUpdateLead` requests (one per file) instead of buffering all files into a single JSON payload. Drops `JSON_PRETTY_PRINT`. Per-file cap raised to 32 MB. Adds `ignore_user_abort` + `set_time_limit(0)` so the chain survives the user closing the browser after submit. Logging integrates with the 2.2.5 lead-endpoint pipeline (PII masking, base64 blobs omitted from log entries).
* **Performance.** `/qualified-vehicles` REST response is now cached per `rental_limit` for 5 minutes. `update_number_of_payments_attribute` fetches the PACE feed once before the product loop (was re-fetching inside every iteration — O(N²)). WBW reindex no longer triggered twice per import cycle.
* **Correctness.** `wc_get_product()` returning `false` no longer fatals the update prune loop. `/qualified-vehicles` dedup no longer drops distinct models that share a price. The query is restricted to `post_status=publish`. `delete_expired_sold_products` uses `wp_get_post_terms` + `DateTime::createFromFormat` instead of `strtotime` on a comma-joined term string.
* **Reliability.** Plugin deactivation now consolidated into one hook that unschedules all 9 of the plugin's cron events (was missing image sync, repair, sold-date and expired-sold). `_vi_write_date` no longer advanced when the feed returns no images for a product — next run will retry image sync. `vi_attach_lock` set by the sync and repair mutation paths, not just the create path. `wp_lead_qualifications` table gains `UNIQUE KEY` on `lead_id` to prevent duplicates on JS retry. `vi_migrate_rebate_target` throttles retries to once per hour on feed failure (was retrying on every page load).
* **Logging.** Removed unconditional `error_log()` on every `/qualified-vehicles` REST hit.

= 2.2.8 =
* ID/Passport field on the lead qualification form now requires 9-13 alphanumeric characters (previously 6-13). Client-side (lead-qualification.js) and server-side `/qualify-lead` validators updated together. Bumped the enqueued lead-qualification.js version to 1.2.5 to bust browser caches.

= 2.2.3 =
* Drop the client- and server-side SA ID Luhn checksum from the lead qualification flow — it was rejecting real ID numbers and leaving the submit button stuck disabled. The validator now only requires the field to be filled in and exactly 13 digits; PACE itself validates the ID against the Home Affairs database.

= 2.2.2 =
* Looser phone-number validation on the lead qualification form. Numbers like `+27 71 234 5678`, `(071) 234-5678`, `+27-71-234-5678` are now accepted — non-digit characters are stripped and only the digit shape is checked (`0XXXXXXXXX` or `27XXXXXXXXX`). Previously a number with spaces or punctuation would silently fail validation and leave the submit button disabled with no obvious explanation. Server-side validator updated to match.

= 2.2.1 =
* PACE base URL setting now accepts a query string (e.g. `?env=test`) and preserves it on every endpoint call. Previously the query string was concatenated before the path and produced broken URLs.
* Status page now also shows the fully-resolved example endpoint so the configured URL can be verified at a glance.

= 2.2.0 =
* New top-level **SA Motorlease** admin menu with **Settings** and **Status** subpages.
* Settings (stored in option `sa_motorlease_settings`): PACE base URL, "post to PACE" kill switch, qualify form IDs, Gravity Forms forwarder form ID, log level, log retention days. Wired into the `/qualify-lead`, `/partial-save` and GF forwarder code paths.
* Status page: plugin / WP / PHP / WooCommerce / Gravity Forms versions, effective configuration, lead-qualifications row count + latest entry, scheduled cron next-run times, log file size + last-50-line tail.
* **Bug fix:** the ID Number field on the lead qualification form was declared `type: 'id'` but `validateAllFields()` had no `case 'id'`, so empty or malformed IDs fell through validation and were forwarded to `paceWebCreateLead`. Added 13-digit + SA Luhn mod-10 checksum validation client-side, and matching server-side validation in the `/qualify-lead` REST endpoint that rejects with a user-visible error before the PACE call. Bumped the enqueued lead-qualification.js version to 1.2.0 to bust browser caches.

= 2.1.0 =
* Consolidated logging. All 19 legacy log files now route through a single rolling `sa-motorlease.log` with `[channel][LEVEL]` tags.
* Default log level is now WARN — chatty success messages ("✅ Updated…", "✅ Saved…") are dropped. Override with `define('SA_MOTORLEASE_LOG_LEVEL', SA_MOTORLEASE_LOG_INFO)` (or `_DEBUG`) for temporary verbose tracing.
* Daily WP-cron event trims any line older than 21 days. Override with `define('SA_MOTORLEASE_LOG_RETENTION_DAYS', 14)` etc.
* Lead-qualification, Gravity Form #5 forwarder and partial-save endpoints no longer dump the full request + response payload on every call — they only log on WP_Error, non-2xx upstream responses, or wpdb errors.
* Removed the per-request `lead-debug.txt` "RECEIVED REQUEST" write.

= 2.0.0 =
* Initial merged release combining sa-motorlease-product-importer (1.1.0) and sa-motorlease-vehicle-importer (1.2.2). Old import pipeline removed; new vi_* pipeline retained.
