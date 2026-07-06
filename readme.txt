=== SA Motorlease ===
Contributors: netage
Tags: woocommerce, vehicles, importer, paceapp, gravityforms
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.4.0
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

= 2.4.0 =
New feature: a self-contained custom vehicle filter that replaces the third-party WBW / WooBeWoo Product Filter on the vehicles listing, with richer filtering than the plugin it supersedes. The existing WBW reindex/cleanup integration is left in place so the two can run side by side while the site is switched over.

* **`[sa_vehicle_filter]` shortcode.** Renders a filter sidebar plus an AJAX-driven results grid. Drop it on any page (e.g. a new Vehicles page) to run alongside the WBW filter, then switch over once you're happy. No theme template changes required. Accepts an optional `category="<product_cat id or slug>"` attribute to lock the whole view to one location.
* **Location category archives.** WooCommerce `product_cat` archives (the importer's province/area terms, e.g. `/listings/gauteng/sandton/`) are rendered through the custom filter, pre-scoped to that location, via a `template_include` takeover that keeps the theme header/footer and the SEO-friendly term URL, `<title>` and description. The location scope is fixed for the page and preserved on every AJAX request. The takeover also renders a category-scoped featured strip at the top and strips embedded shortcodes from the term description so any legacy carousel/filter button doesn't reappear.
* **`[sa_featured_vehicles]` shortcode.** A draggable featured-listings slider (prev/next arrows + pointer drag) scoped to a category — shows WooCommerce "Featured" products (falling back to the newest vehicles in that category), rendered with the exact same card component as the filter grid. Attributes: `category` (id/slug; defaults to the current archive term), `limit` (default 8), `title` (default "Featured Listings").
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
