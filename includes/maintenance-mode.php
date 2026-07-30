<?php
/**
 * Maintenance mode — takes the public site offline behind a branded holding
 * page while administrators keep working on the real site.
 *
 * Design notes (why it looks the way it does):
 *  - The gate runs on `template_redirect`, which only fires for front-end page
 *    requests. That deliberately leaves wp-admin, wp-login.php, WP-Cron,
 *    admin-ajax and the REST API alone: the vehicle import crons keep running
 *    and an admin can always reach Settings to switch the toggle back off,
 *    even from a logged-out browser.
 *  - Anyone with `manage_options` passes straight through, so enabling
 *    maintenance mode can never lock the operator out of their own site.
 *  - The page is self-contained (inline CSS, no theme, no enqueued assets) so
 *    it renders identically no matter what state the theme or plugins are in,
 *    which is usually exactly why maintenance mode got switched on.
 *  - HTTP 503 + Retry-After + noindex is the correct "temporarily down"
 *    signal; a 200 would invite search engines to index the holding page.
 *
 * Admins can preview the page without taking the site down by appending
 * `?sa_maintenance_preview=1` to any front-end URL (link on the Settings page).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Is maintenance mode switched on in settings? */
function sa_motorlease_maintenance_enabled() {
    if ( ! function_exists( 'sa_motorlease_get_setting' ) ) return false;
    return (bool) sa_motorlease_get_setting( 'maintenance_enabled', 0 );
}

/**
 * Requests that must never see the holding page: administrators (so they can
 * turn it off again and keep testing), WP-Cron and WP-CLI.
 */
function sa_motorlease_maintenance_can_bypass() {
    if ( defined( 'WP_CLI' ) && WP_CLI )                              return true;
    if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )      return true;
    if ( defined( 'DOING_CRON' ) && DOING_CRON )                      return true;
    return current_user_can( 'manage_options' );
}

/* ===========================================================================
 * Front-end gate
 * ======================================================================== */

add_action( 'template_redirect', function () {
    // Admin-only live preview of the holding page, even when the toggle is off.
    $preview = ! empty( $_GET['sa_maintenance_preview'] ) && current_user_can( 'manage_options' );

    if ( ! $preview ) {
        if ( ! sa_motorlease_maintenance_enabled() )    return;
        if ( sa_motorlease_maintenance_can_bypass() )   return;
        // Leave robots.txt reachable so crawler directives still resolve while
        // the rest of the site answers 503.
        if ( is_robots() )                              return;
    }

    sa_motorlease_render_maintenance_page( $preview );
}, 0 );

/**
 * Output the holding page and stop. Sends 503 unless this is an admin preview,
 * in which case a normal 200 keeps the browser from treating it as an error.
 */
function sa_motorlease_render_maintenance_page( $preview = false ) {
    if ( ! headers_sent() ) {
        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow', true );
        if ( ! $preview ) {
            status_header( 503 );
            header( 'Retry-After: 3600' );
        }
    }

    $heading = (string) sa_motorlease_get_setting( 'maintenance_heading', '' );
    $message = (string) sa_motorlease_get_setting( 'maintenance_message', '' );
    $name    = get_bloginfo( 'name', 'display' );

    // Site logo (Flatsome sets the core custom_logo theme mod). Falls back to
    // the site name rendered as text, so the page never shows a broken image.
    $logo_id  = (int) get_theme_mod( 'custom_logo' );
    $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';

    ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( ( $heading !== '' ? $heading : 'Maintenance' ) . ' — ' . $name ); ?></title>
<?php if ( function_exists( 'wp_site_icon' ) ) wp_site_icon(); ?>
<style>
    /* Brand tokens mirror assets/css/vehicle-filter.css so the holding page
       reads as part of the SA Motorlease site rather than a generic error. */
    :root {
        --sa-orange: #f47b24;
        --sa-orange-dark: #d9661a;
        --sa-navy: #003b65;
        --sa-navy-deep: #002a49;
        --sa-muted: #6b7280;
        --sa-border: #d9dde2;
        --sa-radius: 6px;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 32px 20px;
        background: var(--sa-navy);
        background-image: linear-gradient(160deg, var(--sa-navy) 0%, var(--sa-navy-deep) 100%);
        color: var(--sa-navy);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        line-height: 1.6;
        -webkit-font-smoothing: antialiased;
    }
    .sa-mt {
        width: 100%;
        max-width: 620px;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 24px 60px rgba(0, 20, 40, .35);
        overflow: hidden;
        text-align: center;
    }
    /* Orange keyline at the top of the card — the site's accent colour. */
    .sa-mt__accent { height: 5px; background: var(--sa-orange); }
    .sa-mt__body { padding: 44px 36px 40px; }
    .sa-mt__logo { max-width: 240px; height: auto; margin: 0 auto 28px; display: block; }
    .sa-mt__brand {
        margin: 0 0 24px;
        font-size: 22px;
        font-weight: 700;
        letter-spacing: .02em;
        text-transform: uppercase;
        color: var(--sa-navy);
    }
    .sa-mt__icon {
        width: 64px;
        height: 64px;
        margin: 0 auto 22px;
        border-radius: 50%;
        background: rgba(244, 123, 36, .12);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .sa-mt__icon svg { width: 32px; height: 32px; stroke: var(--sa-orange); }
    .sa-mt h1 {
        margin: 0 0 14px;
        font-size: 28px;
        line-height: 1.25;
        color: var(--sa-navy);
    }
    .sa-mt__msg { margin: 0; font-size: 16px; color: #374151; }
    .sa-mt__msg p { margin: 0 0 12px; }
    .sa-mt__msg p:last-child { margin-bottom: 0; }
    .sa-mt__msg a { color: var(--sa-orange-dark); }
    .sa-mt__foot {
        margin-top: 30px;
        padding-top: 22px;
        border-top: 1px solid var(--sa-border);
        font-size: 13px;
        color: var(--sa-muted);
    }
    .sa-mt__foot a { color: var(--sa-navy); text-decoration: none; font-weight: 600; }
    .sa-mt__foot a:hover { color: var(--sa-orange-dark); }
    .sa-mt__preview {
        background: var(--sa-orange);
        color: #fff;
        font-size: 13px;
        font-weight: 600;
        padding: 9px 16px;
    }
    @media (max-width: 480px) {
        .sa-mt__body { padding: 34px 22px 30px; }
        .sa-mt h1 { font-size: 23px; }
    }
</style>
</head>
<body>
    <main class="sa-mt">
        <div class="sa-mt__accent"></div>
        <?php if ( $preview ) : ?>
            <div class="sa-mt__preview">Administrator preview — the site is not actually offline unless the toggle is on.</div>
        <?php endif; ?>
        <div class="sa-mt__body">
            <?php if ( $logo_url ) : ?>
                <img class="sa-mt__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $name ); ?>">
            <?php else : ?>
                <p class="sa-mt__brand"><?php echo esc_html( $name ); ?></p>
            <?php endif; ?>

            <div class="sa-mt__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="9"></circle>
                    <polyline points="12 7 12 12 15.5 14"></polyline>
                </svg>
            </div>

            <?php if ( $heading !== '' ) : ?>
                <h1><?php echo esc_html( $heading ); ?></h1>
            <?php endif; ?>

            <?php if ( $message !== '' ) : ?>
                <div class="sa-mt__msg"><?php echo wpautop( wp_kses_post( $message ) ); ?></div>
            <?php endif; ?>

            <p class="sa-mt__foot">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $name ); ?></a>
            </p>
        </div>
    </main>
</body>
</html>
    <?php
    exit;
}

/* ===========================================================================
 * Admin reminder
 * ======================================================================== */

/**
 * Maintenance mode is easy to leave on by accident, so every admin screen
 * carries a persistent warning while it's active.
 */
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) )   return;
    if ( ! sa_motorlease_maintenance_enabled() )    return;

    $url = admin_url( 'admin.php?page=' . SA_MOTORLEASE_SETTINGS_SLUG );
    printf(
        '<div class="notice notice-warning"><p><strong>SA Motorlease: maintenance mode is ON.</strong> Visitors see the maintenance page and the site returns HTTP 503. <a href="%s">Turn it off</a></p></div>',
        esc_url( $url )
    );
} );
