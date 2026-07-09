<?php
/**
 * Plugin Name:       AuthorLift
 * Plugin URI:        https://github.com/BedfordAdrian/WP-SE
 * Description:       Social media marketing for authors — generate on-brand posts, plan launch campaigns, schedule &amp; publish through a pluggable publisher, and measure the sales bump. Ships with a simulated publisher (clearly labelled); real network adapters plug in.
 * Version:           1.2.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Mo Fanning
 * License:           MIT
 * Text Domain:       authorlift
 *
 * NOTE ON HONESTY: the bundled publisher is "simulated" — it does not post to
 * live social networks, and the seeded engagement/sales are illustrative sample
 * data, clearly labelled in the UI. Real network adapters implement the same
 * interface and can be registered via the `authorlift_register_publishers` hook.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AUTHORLIFT_VERSION', '1.2.2');
define('AUTHORLIFT_DIR', plugin_dir_path(__FILE__));
define('AUTHORLIFT_URL', plugin_dir_url(__FILE__));
define('AUTHORLIFT_OPTION', 'authorlift_data');
define('AUTHORLIFT_CRON_HOOK', 'authorlift_run_due_posts');
define('AUTHORLIFT_CRON_SCHEDULE', 'authorlift_five_minutes');

require_once AUTHORLIFT_DIR . 'includes/helpers.php';
require_once AUTHORLIFT_DIR . 'includes/class-authorlift-store.php';
require_once AUTHORLIFT_DIR . 'includes/class-authorlift-domain.php';
require_once AUTHORLIFT_DIR . 'includes/class-authorlift-content.php';
require_once AUTHORLIFT_DIR . 'includes/class-authorlift-planner.php';
require_once AUTHORLIFT_DIR . 'includes/class-authorlift-metrics.php';
require_once AUTHORLIFT_DIR . 'includes/class-authorlift-scheduler.php';
require_once AUTHORLIFT_DIR . 'includes/class-authorlift-seed.php';
require_once AUTHORLIFT_DIR . 'includes/class-authorlift-rest.php';
require_once AUTHORLIFT_DIR . 'admin/class-authorlift-admin.php';

/**
 * Activation: seed a demo profile if empty and schedule the publishing cron.
 */
function authorlift_activate() {
    $store = AuthorLift_Store::instance();
    if (!$store->get_author()) {
        AuthorLift_Seed::run($store);
    }
    if (!wp_next_scheduled(AUTHORLIFT_CRON_HOOK)) {
        wp_schedule_event(time() + 300, AUTHORLIFT_CRON_SCHEDULE, AUTHORLIFT_CRON_HOOK);
    }
}
register_activation_hook(__FILE__, 'authorlift_activate');

function authorlift_deactivate() {
    $timestamp = wp_next_scheduled(AUTHORLIFT_CRON_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, AUTHORLIFT_CRON_HOOK);
    }
}
register_deactivation_hook(__FILE__, 'authorlift_deactivate');

// Custom 5-minute cron schedule for the scheduler.
add_filter('cron_schedules', function ($schedules) {
    $schedules[AUTHORLIFT_CRON_SCHEDULE] = array(
        'interval' => 300,
        'display'  => __('Every 5 minutes (AuthorLift)', 'authorlift'),
    );
    return $schedules;
});

// Publish due posts on the cron tick.
add_action(AUTHORLIFT_CRON_HOOK, function () {
    AuthorLift_Scheduler::run_due(AuthorLift_Store::instance());
});

// REST API.
add_action('rest_api_init', function () {
    (new AuthorLift_REST())->register_routes();
});

// Admin menu + assets.
add_action('admin_menu', array('AuthorLift_Admin', 'menu'));
add_action('admin_enqueue_scripts', array('AuthorLift_Admin', 'enqueue'));
