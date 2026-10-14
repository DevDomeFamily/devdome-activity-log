<?php
/**
 * Full data removal — runs only when the plugin is DELETED (not on deactivate).
 * Drops the custom tables, deletes options + the cached hub summary, clears scheduled hooks,
 * sweeps transients, loops multisite, coordinates shared-core cleanup, ends with wp_cache_flush().
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

/** Per-site cleanup (called once per site on multisite, or once on single-site). */
function devdacti_uninstall_site()
{
    global $wpdb;
    $p = $wpdb->prefix . 'devdacti_';

    foreach (array('events', 'settings') as $t) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix + hardcoded table name; DROP TABLE cannot be prepared/cached.
        $wpdb->query("DROP TABLE IF EXISTS {$p}{$t}");
    }

    delete_option('devdacti_hub_summary');
    delete_option('devdacti_schema_version');
    delete_option('devdacti_write_status');
    delete_option('devdacti_retention_status');
    delete_option('devdacti_migrating');
    // 0.1.2 shipped a shared-core connection shim (removed in this release); drop its marker.
    delete_option('devdacti_connected_shim');

    // Every transient this plugin sets, by name. The 0.1.2 sweep used a LIKE with manual
    // backslash escaping, whose behaviour depends on the server's SQL mode — and it never
    // touched an external object cache at all. delete_transient() handles both.
    foreach (array('devdacti_dash_stats', 'devdacti_schema_status', 'devdacti_pending_weaken') as $transient) {
        delete_transient($transient);
    }
    // The rejected-save proposal is kept per user (devdacti_pending_weaken_<user id>, five minutes): find the names in the
    // options table, then delete each through delete_transient() so an external object cache is cleared too.
    $pending = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_devdacti_pending_weaken_%'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall sweep of this plugin's own transient names.
    foreach ((array) $pending as $option_name) {
        delete_transient(substr((string) $option_name, strlen('_transient_')));
    }
    // The self-hosted updater caches its manifest under a hashed key, per site and (on
    // multisite) network wide. Both spellings, or the cache outlives the plugin.
    $updater_cache = 'devdome_upd_' . md5('devdome-activity-log');
    delete_transient($updater_cache);
    if (is_multisite()) {
        delete_site_transient($updater_cache);
    }

    // The capabilities this plugin added are its own; leaving them behind would litter every role editor on the
    // site for ever. Any role may have been granted one (the readme says so), so every role is cleaned.
    $devdacti_caps = array(
        'devdacti_read', 'devdacti_export', 'devdacti_manage',
        'devdacti_purge_log', 'devdacti_integrity', 'devdacti_repair',
        'devdacti_view_ip',
    );
    foreach (array_keys(wp_roles()->roles) as $devdacti_role_key) {
        $role = get_role($devdacti_role_key);
        if (!$role) {
            continue;
        }
        foreach ($devdacti_caps as $cap) {
            if ($role->has_cap($cap)) {
                $role->remove_cap($cap);
            }
        }
    }

    wp_clear_scheduled_hook('devdacti_purge');
}

global $wpdb;
if (is_multisite()) {
    // In batches: a large network must never be loaded into memory in one list.
    $offset = 0;
    do {
        $wpdb->last_error = '';
        $sites = get_sites(array('fields' => 'ids', 'number' => 100, 'offset' => $offset));
        if (!is_array($sites) || '' !== (string) $wpdb->last_error) { // a failed site query answers an EMPTY list
            // The site list could not be read: stop here. The sites not yet reached keep their data and the shared
            // core stays, which a re-install picks up; continuing would remove the network pieces under them.
            return;
        }
        foreach ($sites as $blog_id) {
            switch_to_blog((int) $blog_id);
            devdacti_uninstall_site();
            restore_current_blog();
        }
        $offset += 100;
    } while (count($sites) === 100);
} else {
    devdacti_uninstall_site();
}

// Coordinate shared-core cleanup (removes the core cron/option only if this is the LAST DevDome
// plugin still installed, so removing this one never orphans the core for the others).
require_once __DIR__ . '/lib/devdome-core/uninstall.php';
devdcorev1_uninstall_cleanup('devdome-activity-log/devdome-activity-log.php');

wp_cache_flush();
