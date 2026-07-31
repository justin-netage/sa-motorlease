<?php
/**
 * Site notice — a thin banner above the navbar plus a once-per-session popup,
 * for telling visitors something is going on *without* taking the site down.
 *
 * Maintenance mode (includes/maintenance-mode.php) is the blunt instrument: the
 * whole public site answers 503 behind a holding page. This is the soft version
 * for the cases where the site works but something behind it doesn't — a PACE
 * outage where lead submissions are failing, a scheduled window that hasn't
 * started yet, a partial degradation. Visitors keep browsing; they just get
 * told.
 *
 * Design notes:
 *  - Content defaults to the maintenance heading/message, so an operator who
 *    has already written those doesn't write them twice. Filling in the notice
 *    fields overrides them.
 *  - The banner carries the heading only (it has to stay thin) with a "More
 *    info" control that opens the popup; the popup carries the full message.
 *  - "Once per session" is tracked in sessionStorage, not a cookie or PHP
 *    session, so the markup is identical for every visitor and stays safe to
 *    serve from a full-page cache.
 *  - The dismissal key includes a hash of the notice content, so editing the
 *    message re-shows the popup to people who already dismissed the old one.
 *  - Everything is inline (no enqueued assets) like the maintenance page. The
 *    notice is temporary by nature and only renders while it's switched on, so
 *    there's nothing to cache-bust and no request to spend.
 *
 * Admins can preview without switching it on by appending `?sa_notice_preview=1`
 * to any front-end URL (link on the Settings page).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ===========================================================================
 * Settings accessors
 * ======================================================================== */

/** Is the site notice switched on? */
function sa_motorlease_notice_enabled() {
    if ( ! function_exists( 'sa_motorlease_get_setting' ) ) return false;
    return (bool) sa_motorlease_get_setting( 'notice_enabled', 0 );
}

/**
 * Notice heading — falls back to the maintenance heading so the two features
 * share one piece of copy unless the operator deliberately splits them.
 */
function sa_motorlease_notice_heading() {
    $own = trim( (string) sa_motorlease_get_setting( 'notice_heading', '' ) );
    return $own !== '' ? $own : trim( (string) sa_motorlease_get_setting( 'maintenance_heading', '' ) );
}

/** Notice body — falls back to the maintenance message. */
function sa_motorlease_notice_message() {
    $own = trim( (string) sa_motorlease_get_setting( 'notice_message', '' ) );
    return $own !== '' ? $own : trim( (string) sa_motorlease_get_setting( 'maintenance_message', '' ) );
}

function sa_motorlease_notice_banner_enabled() {
    return (bool) sa_motorlease_get_setting( 'notice_banner', 1 );
}

function sa_motorlease_notice_popup_enabled() {
    return (bool) sa_motorlease_get_setting( 'notice_popup', 1 );
}

/**
 * Short hash of everything a visitor would see. Used as the sessionStorage
 * value so an edited notice counts as a new notice rather than staying
 * dismissed for anyone mid-session.
 */
function sa_motorlease_notice_version() {
    return substr( md5(
        sa_motorlease_notice_heading() . '|' .
        sa_motorlease_notice_message() . '|' .
        ( sa_motorlease_notice_banner_enabled() ? '1' : '0' ) .
        ( sa_motorlease_notice_popup_enabled()  ? '1' : '0' )
    ), 0, 8 );
}

/** Admin-only live preview, even while the toggle is off. */
function sa_motorlease_notice_is_preview() {
    return ! empty( $_GET['sa_notice_preview'] ) && current_user_can( 'manage_options' );
}

/**
 * Should this request render the notice? Requires the toggle (or a preview),
 * at least one of the two surfaces, and something to actually say.
 */
function sa_motorlease_notice_should_render() {
    if ( ! sa_motorlease_notice_enabled() && ! sa_motorlease_notice_is_preview() ) return false;
    if ( ! sa_motorlease_notice_banner_enabled() && ! sa_motorlease_notice_popup_enabled() ) return false;
    return sa_motorlease_notice_heading() !== '' || sa_motorlease_notice_message() !== '';
}

/* ===========================================================================
 * Front-end output
 * ======================================================================== */

/**
 * The banner belongs at the very top of <body>, above the theme's header, which
 * is exactly what wp_body_open() is for. Flatsome calls it — but a child theme
 * with a hand-rolled header.php might not, so wp_footer re-checks and moves a
 * late-rendered banner into place rather than dropping it silently.
 */
add_action( 'wp_body_open', function () {
    if ( ! sa_motorlease_notice_should_render() )   return;
    if ( ! sa_motorlease_notice_banner_enabled() )  return;

    echo sa_motorlease_notice_banner_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped internally.
}, 5 );

add_action( 'wp_footer', function () {
    if ( ! sa_motorlease_notice_should_render() ) return;

    // Theme never called wp_body_open() — emit the banner here and hoist it to
    // the top of <body> so it still lands above the navbar.
    $needs_hoist = sa_motorlease_notice_banner_enabled() && ! did_action( 'wp_body_open' );
    if ( $needs_hoist ) {
        echo sa_motorlease_notice_banner_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped internally.
    }

    sa_motorlease_render_notice_assets( $needs_hoist );
}, 5 );

/** Thin banner markup. Heading only — the full message lives in the popup. */
function sa_motorlease_notice_banner_html() {
    $heading = sa_motorlease_notice_heading();
    $message = sa_motorlease_notice_message();

    // No heading set: fall back to the message as plain text so the banner
    // still says something rather than rendering an empty bar.
    $text = $heading !== '' ? $heading : wp_strip_all_tags( $message );
    if ( $text === '' ) return '';

    $show_more = sa_motorlease_notice_popup_enabled() && $message !== '';

    ob_start();
    ?>
    <div class="sa-notice-banner" id="sa-notice-banner" role="status">
        <div class="sa-notice-banner__inner">
            <svg class="sa-notice-banner__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9"></circle>
                <polyline points="12 7 12 12 15.5 14"></polyline>
            </svg>
            <span class="sa-notice-banner__text"><?php echo esc_html( $text ); ?></span>
            <?php if ( $show_more ) : ?>
                <button type="button" class="sa-notice-banner__more" data-sa-notice-open>More info</button>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/** Popup markup, styles and behaviour. */
function sa_motorlease_render_notice_assets( $hoist_banner = false ) {
    $heading = sa_motorlease_notice_heading();
    $message = sa_motorlease_notice_message();
    $popup   = sa_motorlease_notice_popup_enabled() && $message !== '';
    ?>
<style id="sa-notice-css">
    /* Brand tokens mirror the maintenance page so the two read as one system. */
    .sa-notice-banner, .sa-notice-modal {
        --sa-n-orange: #f47b24;
        --sa-n-orange-dark: #d9661a;
        --sa-n-navy: #003b65;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }
    [hidden] { display: none !important; }

    /* --- Thin banner ---------------------------------------------------- */
    .sa-notice-banner {
        position: relative;
        z-index: 1000;
        background: var(--sa-n-navy);
        color: #fff;
        font-size: 14px;
        line-height: 1.4;
    }
    .sa-notice-banner__inner {
        max-width: 1200px;
        margin: 0 auto;
        padding: 9px 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        text-align: center;
    }
    .sa-notice-banner__icon { width: 17px; height: 17px; flex: 0 0 auto; color: var(--sa-n-orange); }
    .sa-notice-banner__text { font-weight: 600; }
    .sa-notice-banner__more {
        flex: 0 0 auto;
        background: transparent;
        border: 1px solid rgba(255, 255, 255, .45);
        color: #fff;
        font: inherit;
        font-size: 12px;
        font-weight: 600;
        line-height: 1;
        padding: 5px 11px;
        border-radius: 999px;
        cursor: pointer;
    }
    .sa-notice-banner__more:hover,
    .sa-notice-banner__more:focus { background: var(--sa-n-orange); border-color: var(--sa-n-orange); color: #fff; }
    @media (max-width: 600px) {
        .sa-notice-banner { font-size: 13px; }
        .sa-notice-banner__inner { padding: 8px 12px; gap: 7px; }
        .sa-notice-banner__icon { display: none; }
    }

    /* --- Popup ----------------------------------------------------------- */
    .sa-notice-modal {
        position: fixed;
        inset: 0;
        z-index: 100000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .sa-notice-modal__backdrop { position: absolute; inset: 0; background: rgba(0, 20, 40, .55); }
    .sa-notice-modal__card {
        position: relative;
        width: 100%;
        max-width: 460px;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 24px 60px rgba(0, 20, 40, .35);
        overflow: hidden;
        text-align: center;
    }
    .sa-notice-modal__accent { height: 5px; background: var(--sa-n-orange); }
    .sa-notice-modal__x {
        position: absolute;
        top: 12px;
        right: 12px;
        width: 30px;
        height: 30px;
        border: 0;
        background: transparent;
        color: #6b7280;
        font-size: 24px;
        line-height: 1;
        cursor: pointer;
        border-radius: 50%;
    }
    .sa-notice-modal__x:hover { background: #f3f4f6; color: var(--sa-n-navy); }
    .sa-notice-modal__body { padding: 34px 30px 30px; }
    .sa-notice-modal__icon {
        width: 56px;
        height: 56px;
        margin: 0 auto 18px;
        border-radius: 50%;
        background: rgba(244, 123, 36, .12);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .sa-notice-modal__icon svg { width: 28px; height: 28px; stroke: var(--sa-n-orange); }
    .sa-notice-modal h2 {
        margin: 0 0 12px;
        font-size: 22px;
        line-height: 1.3;
        color: var(--sa-n-navy);
    }
    .sa-notice-modal__msg { font-size: 15px; line-height: 1.6; color: #374151; }
    .sa-notice-modal__msg p { margin: 0 0 10px; }
    .sa-notice-modal__msg p:last-child { margin-bottom: 0; }
    .sa-notice-modal__msg a { color: var(--sa-n-orange-dark); }
    .sa-notice-modal__ok {
        margin-top: 22px;
        background: var(--sa-n-orange);
        border: 0;
        color: #fff;
        font: inherit;
        font-weight: 700;
        padding: 11px 26px;
        border-radius: 6px;
        cursor: pointer;
    }
    .sa-notice-modal__ok:hover { background: var(--sa-n-orange-dark); }
    body.sa-notice-open { overflow: hidden; }
    @media (max-width: 480px) {
        .sa-notice-modal__body { padding: 30px 20px 26px; }
        .sa-notice-modal h2 { font-size: 19px; }
    }
</style>

<?php if ( $popup ) : ?>
    <div class="sa-notice-modal" id="sa-notice-modal" hidden>
        <div class="sa-notice-modal__backdrop" data-sa-notice-close></div>
        <div class="sa-notice-modal__card" role="dialog" aria-modal="true" aria-labelledby="sa-notice-title">
            <div class="sa-notice-modal__accent"></div>
            <button type="button" class="sa-notice-modal__x" data-sa-notice-close aria-label="Close">&times;</button>
            <div class="sa-notice-modal__body">
                <div class="sa-notice-modal__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="9"></circle>
                        <polyline points="12 7 12 12 15.5 14"></polyline>
                    </svg>
                </div>
                <h2 id="sa-notice-title"><?php echo esc_html( $heading !== '' ? $heading : 'Please note' ); ?></h2>
                <div class="sa-notice-modal__msg"><?php echo wpautop( wp_kses_post( $message ) ); ?></div>
                <button type="button" class="sa-notice-modal__ok" data-sa-notice-close>Got it</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script id="sa-notice-js">
(function () {
    <?php if ( $hoist_banner ) : ?>
    // Theme didn't call wp_body_open(), so the banner rendered down in the
    // footer. Move it to the top of <body> where it belongs.
    var banner = document.getElementById('sa-notice-banner');
    if (banner && document.body && document.body.firstChild !== banner) {
        document.body.insertBefore(banner, document.body.firstChild);
    }
    <?php endif; ?>

    var modal = document.getElementById('sa-notice-modal');
    if (!modal) return;

    var KEY = 'sa_notice_seen';
    var VER = <?php echo wp_json_encode( sa_motorlease_notice_version() ); ?>;

    // Probe storage rather than assuming it: Safari private browsing and
    // locked-down corporate profiles can throw on write. Without somewhere to
    // record the dismissal we don't auto-open at all — re-popping the modal on
    // every page view would be worse than not showing it, and the banner is
    // still there to carry the message.
    var store = null;
    try {
        window.sessionStorage.setItem('sa_notice_probe', '1');
        window.sessionStorage.removeItem('sa_notice_probe');
        store = window.sessionStorage;
    } catch (e) {}

    var lastFocus = null;

    function open() {
        lastFocus = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('sa-notice-open');
        var ok = modal.querySelector('.sa-notice-modal__ok') || modal.querySelector('[data-sa-notice-close]');
        if (ok) ok.focus();
    }

    function close() {
        modal.hidden = true;
        document.body.classList.remove('sa-notice-open');
        if (store) { try { store.setItem(KEY, VER); } catch (e) {} }
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    document.addEventListener('click', function (ev) {
        var t = ev.target;
        if (!t || !t.closest) return;
        if (t.closest('[data-sa-notice-close]')) { close(); return; }
        if (t.closest('[data-sa-notice-open]'))  { ev.preventDefault(); open(); }
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !modal.hidden) close();
    });

    // Once per session. A changed VER counts as a new notice, so editing the
    // message re-shows it to people who already dismissed the previous one.
    if (store && store.getItem(KEY) !== VER) open();
})();
</script>
    <?php
}

/* ===========================================================================
 * Admin reminder
 * ======================================================================== */

/**
 * A notice about a two-hour outage is embarrassing if it's still up next week,
 * so every admin screen carries a reminder while it's live — same rationale as
 * the maintenance-mode notice.
 */
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! sa_motorlease_notice_enabled() )       return;

    $url = admin_url( 'admin.php?page=' . SA_MOTORLEASE_SETTINGS_SLUG );
    printf(
        '<div class="notice notice-warning"><p><strong>SA Motorlease: the site notice is live.</strong> Visitors see &ldquo;%s&rdquo; on every page. <a href="%s">Review or switch it off</a></p></div>',
        esc_html( sa_motorlease_notice_heading() ),
        esc_url( $url )
    );
} );
