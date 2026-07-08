<?php
/**
 * Fired when the plugin is uninstalled. Removes AuthorLift's data and any
 * scheduled cron event.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('authorlift_data');

$timestamp = wp_next_scheduled('authorlift_run_due_posts');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'authorlift_run_due_posts');
}
