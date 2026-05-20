=== SA Motorlease ===
Contributors: netage
Tags: woocommerce, vehicles, importer, paceapp, gravityforms
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.2.0
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
