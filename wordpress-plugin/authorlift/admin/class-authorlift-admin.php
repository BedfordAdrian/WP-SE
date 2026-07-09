<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin integration: registers the top-level menu page, enqueues the dashboard
 * assets, and renders the SPA shell. The frontend talks to the REST API using
 * the localized root URL + nonce.
 */
class AuthorLift_Admin {

    const HOOK_SUFFIX = 'toplevel_page_authorlift';

    public static function menu() {
        add_menu_page(
            __('AuthorLift', 'authorlift'),
            __('AuthorLift', 'authorlift'),
            'manage_options',
            'authorlift',
            array('AuthorLift_Admin', 'render'),
            'dashicons-megaphone',
            58
        );
    }

    public static function enqueue($hook) {
        if ($hook !== self::HOOK_SUFFIX) {
            return;
        }
        // Version assets by file mtime so an in-place plugin update always busts
        // the browser cache (otherwise a stale app.js/styles.css can linger).
        $jsPath = AUTHORLIFT_DIR . 'admin/js/app.js';
        $cssPath = AUTHORLIFT_DIR . 'admin/css/styles.css';
        $jsVer = file_exists($jsPath) ? (string) filemtime($jsPath) : AUTHORLIFT_VERSION;
        $cssVer = file_exists($cssPath) ? (string) filemtime($cssPath) : AUTHORLIFT_VERSION;
        wp_enqueue_style('authorlift', AUTHORLIFT_URL . 'admin/css/styles.css', array(), $cssVer);
        wp_enqueue_script('authorlift', AUTHORLIFT_URL . 'admin/js/app.js', array(), $jsVer, true);
        // serverVersion is printed inline (PHP), so it is always current even if
        // the app.js file itself is served stale from a cache — letting the
        // dashboard detect and warn about a cached/old script.
        wp_localize_script('authorlift', 'AuthorLiftConfig', array(
            'root' => esc_url_raw(rest_url('authorlift/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'serverVersion' => AUTHORLIFT_VERSION,
        ));
    }

    public static function render() {
        ?>
        <div id="authorlift-app">
            <div class="app-shell">
                <aside class="sidebar">
                    <div class="brand">
                        <div class="brand-logo">🚀</div>
                        <div>
                            <div class="brand-name">AuthorLift</div>
                            <div class="brand-sub">Author marketing</div>
                        </div>
                    </div>
                    <ul class="nav" id="nav">
                        <li><a href="#/overview" data-view="overview"><span class="ico">📊</span> Overview</a></li>
                        <li><a href="#/studio" data-view="studio"><span class="ico">✍️</span> Content Studio</a></li>
                        <li><a href="#/campaigns" data-view="campaigns"><span class="ico">🚀</span> Campaigns</a></li>
                        <li><a href="#/calendar" data-view="calendar"><span class="ico">🗓️</span> Calendar</a></li>
                        <li><a href="#/books" data-view="books"><span class="ico">📚</span> Books</a></li>
                        <li><a href="#/analytics" data-view="analytics"><span class="ico">📈</span> Analytics</a></li>
                        <li><a href="#/settings" data-view="settings"><span class="ico">⚙️</span> Settings</a></li>
                    </ul>
                    <div class="sidebar-foot" id="foot-author">—</div>
                </aside>
                <main class="main" id="main">
                    <div class="loading">Loading…</div>
                </main>
            </div>
            <div id="toast"></div>
            <div id="modal-root"></div>
        </div>
        <?php
    }
}
