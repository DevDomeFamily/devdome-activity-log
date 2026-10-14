<?php
/**
 * Activation / deactivation lifecycle: create the custom tables (settings + events),
 * seed defaults, schedule the daily retention purge, provision new multisite blogs.
 * Deactivation is non-destructive (schedules only); full removal is in uninstall.php.
 */

defined('ABSPATH') || exit;

/**
 * The events-table schema, in one place so activation, migration and the health check all
 * describe the same table.
 *
 * bucket_key / bucket_scope / first_seen / last_seen are schema 2 (aggregation): the UNIQUE
 * bucket_key is what makes the failed-login upsert atomic. It is NULLable and MySQL treats
 * every NULL in a unique index as distinct, so ordinary one-off events are unaffected.
 *
 * peer_ip / actor_type / channel / request_id / outcome / severity / site_id are schema 3
 * (attribution): who acted, through which door, on which site, with what result — 0.1.2
 * could not separate the actor from the subject at all.
 */
function devdacti_events_schema_sql($table, $charset_collate)
{
    // all datetimes UTC; counter only >1 for aggregated bucket rows
    return "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        created_at datetime NOT NULL,
        first_seen datetime NULL DEFAULT NULL,
        last_seen datetime NULL DEFAULT NULL,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        user_login varchar(60) NOT NULL DEFAULT '',
        user_role varchar(60) NOT NULL DEFAULT '',
        actor_type varchar(20) NOT NULL DEFAULT '',
        channel varchar(20) NOT NULL DEFAULT '',
        request_id varchar(32) NOT NULL DEFAULT '',
        ip varchar(45) NOT NULL DEFAULT '',
        peer_ip varchar(45) NOT NULL DEFAULT '',
        object_type varchar(32) NOT NULL DEFAULT '',
        object_id bigint(20) unsigned NOT NULL DEFAULT 0,
        object_title varchar(191) NOT NULL DEFAULT '',
        action varchar(64) NOT NULL DEFAULT '',
        outcome varchar(16) NOT NULL DEFAULT 'success',
        severity varchar(16) NOT NULL DEFAULT 'info',
        site_id bigint(20) unsigned NOT NULL DEFAULT 1,
        details text NULL,
        counter int unsigned NOT NULL DEFAULT 1,
        bucket_key varchar(64) NULL DEFAULT NULL,
        bucket_scope varchar(64) NULL DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uq_bucket_key (bucket_key),
        KEY idx_created_at (created_at),
        KEY idx_user_id (user_id),
        KEY idx_action (action),
        KEY idx_object_type (object_type),
        KEY idx_bucket_scope (bucket_scope),
        KEY idx_action_created (action, created_at),
        KEY idx_user_created (user_id, created_at),
        KEY idx_object_created (object_type, object_id, created_at),
        KEY idx_severity_created (severity, created_at),
        KEY idx_request_id (request_id)
    ) $charset_collate;";
}

/** Every column the plugin's queries rely on. Used by the health check. */
function devdacti_events_required_columns()
{
    return array(
        'id', 'created_at', 'first_seen', 'last_seen', 'user_id', 'user_login', 'user_role',
        'actor_type', 'channel', 'request_id', 'ip', 'peer_ip', 'object_type', 'object_id',
        'object_title', 'action', 'outcome', 'severity', 'site_id', 'details', 'counter',
        'bucket_key', 'bucket_scope',
    );
}

/**
 * Every index the plugin's guarantees rest on, with its exact ordered column list (mirrors
 * devdacti_events_schema_sql()). uq_bucket_key is the atomicity guarantee itself; the composites
 * are what keep the bounded queries bounded. Verification compares the WHOLE definition: name,
 * uniqueness, column order, and no prefix length (an index on bucket_key(1) has the right name
 * and lets every flood row through).
 */
function devdacti_events_index_definitions()
{
    return array(
        'PRIMARY'              => array('unique' => true,  'columns' => array('id')),
        'uq_bucket_key'        => array('unique' => true,  'columns' => array('bucket_key')),
        'idx_created_at'       => array('unique' => false, 'columns' => array('created_at')),
        'idx_user_id'          => array('unique' => false, 'columns' => array('user_id')),
        'idx_action'           => array('unique' => false, 'columns' => array('action')),
        'idx_object_type'      => array('unique' => false, 'columns' => array('object_type')),
        'idx_bucket_scope'     => array('unique' => false, 'columns' => array('bucket_scope')),
        'idx_action_created'   => array('unique' => false, 'columns' => array('action', 'created_at')),
        'idx_user_created'     => array('unique' => false, 'columns' => array('user_id', 'created_at')),
        'idx_object_created'   => array('unique' => false, 'columns' => array('object_type', 'object_id', 'created_at')),
        'idx_severity_created' => array('unique' => false, 'columns' => array('severity', 'created_at')),
        'idx_request_id'       => array('unique' => false, 'columns' => array('request_id')),
    );
}

function devdacti_events_required_indexes()
{
    return array_keys(devdacti_events_index_definitions());
}

/** Settings seeded on install. Never overwrites a value that already exists. */
function devdacti_default_settings()
{
    return array(
        'retention_days'   => 90,          // 1..3650, presets 30 | 90 | 180 | 365
        'anonymize_ip'     => 1,           // default ON
        'protect_critical' => 1,           // auth/users/plugins events cannot be suppressed
        'trusted_proxies'  => '',          // no proxy is trusted until one is configured
        'forwarded_header' => '',          // and no forwarded header is read until selected
        'group_auth'       => 1,
        'group_content'    => 1,
        'group_plugins'    => 1,           // plugins + themes + core (system tracker)
        'group_users'      => 1,
        'group_media'      => 1,
        'group_comments'   => 1,
        'group_settings'   => 1,           // core-options allowlist
        'excluded_roles'   => array(),     // role slugs never logged
        'excluded_users'   => array(),     // user logins never logged
    );
}

/**
 * Run a callback on every site in the network, in bounded batches.
 *
 * get_sites(number => 0) loads every blog id in the network into memory at once, which is
 * exactly what a large network cannot afford. Each batch is switched into and restored.
 */
function devdacti_each_site($callback, $batch = 100)
{
    global $wpdb;
    $batch  = max(1, (int) $batch);
    $offset = 0;
    do {
        devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
        $ids = get_sites(array('fields' => 'ids', 'number' => $batch, 'offset' => $offset));
        if (!is_array($ids) || devdacti_db_failed()) {
            devdacti_record_write_failure('site_list', 'the site list could not be read: ' . (string) $wpdb->last_error);
            return false; // a failed site query answers an EMPTY list; stop rather than skip the rest of the network
        }
        foreach ($ids as $blog_id) {
            switch_to_blog((int) $blog_id);
            call_user_func($callback, (int) $blog_id);
            restore_current_blog();
        }
        $offset += $batch;
    } while (count($ids) === $batch);
}

function devdacti_activate($network_wide = false)
{
    // Network-wide multisite activation: run the per-site install on every site
    // (mirrors the switch_to_blog() loop in uninstall.php so the lifecycle is symmetric).
    if (is_multisite() && $network_wide) {
        devdacti_each_site('devdacti_install_site');
        return;
    }
    devdacti_install_site();
}

/** The per-site install body: tables + defaults + purge schedule. */
function devdacti_install_site()
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $p = $wpdb->prefix . 'devdacti_';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // name/value settings store
    dbDelta("CREATE TABLE {$p}settings (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        setting_name varchar(191) NOT NULL,
        setting_value longtext NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY setting_name (setting_name)
    ) $charset_collate;");

    dbDelta(devdacti_events_schema_sql($p . 'events', $charset_collate));

    // Seed defaults only when missing (reactivation/upgrade never wipes).
    $table = $wpdb->prefix . 'devdacti_settings';
    $seed_failed = false;
    foreach (devdacti_default_settings() as $name => $value) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; values bound via prepare; one-time seed.
        $seeded = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (setting_name, setting_value) VALUES (%s, %s)",
            $name,
            is_array($value) ? serialize($value) : $value
        ));
        if (false === $seeded) {
            $seed_failed = true;
            devdacti_record_write_failure('settings_seed', 'default setting ' . $name . ' could not be seeded: ' . (string) $wpdb->last_error);
        }
    }

    unset($GLOBALS['devdacti_cache']);

    // The six powers are real capabilities; the administrator role holds all of them.
    $caps_ok = devdacti_grant_caps();

    // 0.1.2 wrote a cross-plugin connection marker. The shim is gone from the code, so the
    // stale marker is dropped here rather than only at uninstall — it must never be read
    // again, and it must never be mistaken for Analytics' own connection stamp.
    delete_option('devdacti_connected_shim');

    devdacti_schedule_purge();

    // Stamp the schema LAST, and only when the storage VERIFIED: both tables present, every
    // column, every index, the bucket key unique. An interrupted or refused migration leaves the
    // old version, so the next request runs this idempotent body again, and the health probe
    // keeps reporting the log as not trustworthy until then.
    delete_transient('devdacti_schema_status');
    $verified = devdacti_storage_verify();
    if ($seed_failed) {
        // Tables without their default policy rows are not a verified install: the stamp waits for the next run.
        $verified = array('ok' => false, 'error' => 'the default settings could not be seeded');
    } elseif (false === $caps_ok) {
        devdacti_record_write_failure('grant_caps', 'the administrator role could not be given the plugin capabilities');
        $verified = array('ok' => false, 'error' => 'the administrator role could not be given the plugin capabilities');
    }
    $stamped  = false;
    if ($verified['ok']) {
        update_option('devdacti_schema_version', DEVDACTI_SCHEMA, false);
        // update_option() answers false for "unchanged" as well as "refused": read the stamp back instead.
        $stamped = ((int) get_option('devdacti_schema_version', 0) === DEVDACTI_SCHEMA);
        if (!$stamped) {
            devdacti_record_write_failure('install', 'schema stamp could not be written');
        }
    } else {
        devdacti_record_write_failure('install', 'storage not verified after install: ' . $verified['error']);
    }
    delete_transient('devdacti_schema_status');
    devdacti_invalidate_stats();
    return $verified['ok'] && $stamped;
}

/**
 * Schedule the daily purge idempotently and CHECK the result — an unscheduled purge means
 * retention silently stops happening, which is exactly the kind of quiet failure this
 * plugin must not have.
 */
function devdacti_schedule_purge()
{
    if (wp_next_scheduled('devdacti_purge')) {
        return true;
    }
    $scheduled = wp_schedule_event(time() + 3600, 'daily', 'devdacti_purge');
    if (false === $scheduled || (function_exists('is_wp_error') && is_wp_error($scheduled))) {
        devdacti_record_write_failure('retention_schedule', 'wp_schedule_event() refused the daily retention purge');
        return false;
    }
    return true;
}

/**
 * Idempotent schema migration on a NORMAL plugin update — activation hooks do not fire when
 * WordPress updates an already-active plugin, which is exactly how a site ends up running new
 * code against an old table. Runs per site (the option is per site), so multisite is covered.
 */
function devdacti_maybe_upgrade()
{
    $from = (int) get_option('devdacti_schema_version', 0);
    if ($from === DEVDACTI_SCHEMA) {
        return;
    }

    // Single-flight lock: add_option() fails when the row already exists, so only one
    // concurrent request migrates. A stale lock (crashed worker) expires after 5 minutes.
    $lock = (int) get_option('devdacti_migrating', 0);
    if ($lock > 0 && (time() - $lock) < 5 * MINUTE_IN_SECONDS) {
        return;
    }
    if ($lock > 0) {
        // A stale lock is taken over by ONE request: the conditional UPDATE matches only the value this request read.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-set on the options row; update_option() cannot express it
        $won = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) time(), 'devdacti_migrating', (string) $lock));
        wp_cache_delete('devdacti_migrating', 'options');
        if (1 !== (int) $won) {
            return;
        }
    } elseif (!add_option('devdacti_migrating', time(), '', false)) {
        return;
    }

    $verified = devdacti_install_site();
    delete_option('devdacti_migrating');

    // A storage migration is a lifecycle boundary: it belongs in the evidence, with its REAL outcome.
    if ($from > 0 && function_exists('devdacti_log_audit')) {
        devdacti_log_audit(
            'schema_migrated',
            __('Activity Log storage', 'devdome-activity-log'),
            $verified
                ? sprintf(
                    /* translators: 1: previous storage schema number, 2: new storage schema number. */
                    __('Storage schema migrated from version %1$d to %2$d', 'devdome-activity-log'),
                    $from,
                    DEVDACTI_SCHEMA
                )
                : sprintf(
                    /* translators: 1: previous storage schema number, 2: new storage schema number. */
                    __('Storage schema migration from version %1$d to %2$d did not verify; the schema was not stamped and will be retried', 'devdome-activity-log'),
                    $from,
                    DEVDACTI_SCHEMA
                ),
            $verified ? 'success' : 'failure'
        );
    }
}
add_action('plugins_loaded', 'devdacti_maybe_upgrade', 5);

/**
 * A blog created after network activation must be ready to record immediately — 0.1.2 only
 * provisioned the sites that existed at activation time, so every later blog logged nothing.
 */
function devdacti_new_site($new_site)
{
    if (!function_exists('is_plugin_active_for_network')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!is_plugin_active_for_network(plugin_basename(DEVDACTI_FILE))) {
        return;
    }
    $blog_id = is_object($new_site) ? (int) $new_site->blog_id : (int) $new_site;
    switch_to_blog($blog_id);
    devdacti_install_site();
    restore_current_blog();
}
add_action('wp_initialize_site', 'devdacti_new_site', 900, 1);

/**
 * Low-cost schema health probe. A missing or incompatible events table must never be
 * presented as an empty healthy log, so every reader consults this first.
 *
 * @return array ok (bool), error (string), missing (string[])
 */
/**
 * Inspect the storage itself: the events table (every column, every index, the bucket key
 * really UNIQUE on bucket_key) and the settings table (its two columns). No cache, no stamp:
 * this is what the install verifies before it stamps and what the health probe reads.
 *
 * @return array ok (bool), error (string), missing (string[]), missing_key (string[])
 */
function devdacti_storage_verify()
{
    global $wpdb;
    $table = devdacti_events_table();

    $suppress = $wpdb->suppress_errors(true);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection of this plugin's own table; the name is built from $wpdb->prefix.
    $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`");
    $wpdb->suppress_errors($suppress);

    $columns = is_array($columns) ? $columns : array();
    if (!$columns) {
        return array(
            'ok'          => false,
            'present'     => false,
            'error'       => __('The Activity Log events table is missing or unreadable. No activity is being recorded.', 'devdome-activity-log'),
            'missing'     => devdacti_events_required_columns(),
            'missing_key' => devdacti_events_required_indexes(),
        );
    }
    $missing = array_values(array_diff(devdacti_events_required_columns(), $columns));

    // Columns alone are not health. The atomic failed-login upsert depends on the UNIQUE
    // uq_bucket_key index, and every bounded query depends on the composite indexes: a
    // table that lost them still answers SHOW COLUMNS perfectly while silently double
    // counting floods and full-scanning the log. The index is read in full: its NAME present
    // but non-unique, or on another column, is the same failure.
    $suppress = $wpdb->suppress_errors(true);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection of this plugin's own table; the name is built from $wpdb->prefix.
    $index_rows = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
    $wpdb->suppress_errors($suppress);
    $defs = devdacti_index_definitions($index_rows);
    $missing_key = array();
    foreach (devdacti_events_index_definitions() as $name => $want) {
        if (!isset($defs[$name])) {
            $missing_key[] = $name;
        } elseif ($defs[$name]['unique'] !== $want['unique'] || $defs[$name]['columns'] !== $want['columns'] || $defs[$name]['prefixed']) {
            $missing_key[] = $name . ' (wrong definition)';
        }
    }

    // The settings table holds the policy; without it every reader runs on defaults, and without
    // its unique name index the upsert that writes the policy duplicates rows instead of updating.
    $settings_table = devdacti_settings_table();
    $suppress = $wpdb->suppress_errors(true);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection of this plugin's own table; the name is built from $wpdb->prefix.
    $settings_columns = $wpdb->get_col("SHOW COLUMNS FROM `{$settings_table}`");
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection of this plugin's own table; the name is built from $wpdb->prefix.
    $settings_index_rows = $wpdb->get_results("SHOW INDEX FROM `{$settings_table}`", ARRAY_A);
    $wpdb->suppress_errors($suppress);
    $settings_columns = is_array($settings_columns) ? $settings_columns : array();
    $settings_missing = array_values(array_diff(array('setting_name', 'setting_value'), $settings_columns));
    foreach ($settings_missing as $c) {
        $missing[] = 'settings.' . $c;
    }
    if ($settings_columns) {
        $sdefs = devdacti_index_definitions($settings_index_rows);
        if (!isset($sdefs['setting_name']) || !($sdefs['setting_name']['unique'] && array('setting_name') === $sdefs['setting_name']['columns'] && !$sdefs['setting_name']['prefixed'])) {
            $missing_key[] = 'settings.setting_name (must be unique on setting_name)';
            $settings_missing[] = 'setting_name index';
        }
    }

    $ok = ($missing === array()) && ($missing_key === array());
    if ($ok) {
        $error = '';
    } elseif ($settings_missing !== array()) {
        $error = __('The Activity Log settings table is missing or unreadable. The policy cannot be stored.', 'devdome-activity-log');
    } elseif ($missing !== array()) {
        $error = __('The Activity Log events table is out of date. Some activity may not be recorded.', 'devdome-activity-log');
    } else {
        $error = __('The Activity Log events table is missing indexes it depends on. Repeated events may be counted more than once and queries will be slow.', 'devdome-activity-log');
    }
    return array('ok' => $ok, 'present' => true, 'error' => $error, 'missing' => $missing, 'missing_key' => $missing_key);
}

/** SHOW INDEX rows -> name => { unique: bool, columns: string[] in index order, prefixed: bool (any Sub_part) }. */
function devdacti_index_definitions($rows)
{
    $defs = array();
    foreach (is_array($rows) ? $rows : array() as $row) {
        if (!isset($row['Key_name'])) {
            continue;
        }
        $name = (string) $row['Key_name'];
        if (!isset($defs[$name])) {
            $defs[$name] = array('unique' => true, 'columns' => array(), 'prefixed' => false);
        }
        if (!isset($row['Non_unique']) || 0 !== (int) $row['Non_unique']) {
            $defs[$name]['unique'] = false;
        }
        if (isset($row['Sub_part']) && null !== $row['Sub_part'] && '' !== (string) $row['Sub_part']) {
            $defs[$name]['prefixed'] = true;
        }
        $seq = isset($row['Seq_in_index']) ? (int) $row['Seq_in_index'] : count($defs[$name]['columns']) + 1;
        $defs[$name]['columns'][$seq] = isset($row['Column_name']) ? (string) $row['Column_name'] : '';
    }
    foreach ($defs as $name => $d) {
        ksort($defs[$name]['columns']);
        $defs[$name]['columns'] = array_values($defs[$name]['columns']);
    }
    return $defs;
}

function devdacti_schema_status($force = false)
{
    $cached = $force ? false : get_transient('devdacti_schema_status');
    if (is_array($cached) && isset($cached['ok'])) {
        return $cached;
    }

    $status = devdacti_storage_verify();

    // A stamped schema older than this build means a migration was interrupted; the
    // migration runner will re-apply it, but until it does the log is not trustworthy.
    $stamped = (int) get_option('devdacti_schema_version', 0);
    if ($status['ok'] && $stamped < DEVDACTI_SCHEMA) {
        $status['ok']    = false;
        $status['error'] = __('The Activity Log storage schema is older than this version of the plugin. A migration has not finished.', 'devdome-activity-log');
    }
    $status['schema'] = $status['present'] ? $stamped : 0;
    unset($status['present']);

    set_transient('devdacti_schema_status', $status, 5 * MINUTE_IN_SECONDS);
    return $status;
}

function devdacti_deactivate($network_wide = false)
{
    // Non-destructive: settings AND the recorded events survive a toggle.
    // Only clear schedules so nothing runs while the plugin is off — on a network
    // deactivation that means EVERY site's schedule, not just the current one.
    if (is_multisite() && $network_wide) {
        devdacti_each_site(function () {
            wp_clear_scheduled_hook('devdacti_purge'); // batched like activation and uninstall, never the whole network in one list
        });
    } else {
        wp_clear_scheduled_hook('devdacti_purge');
    }
    wp_cache_flush();
}

/** Retention window in days: presets plus any validated custom value. */
function devdacti_retention_days()
{
    $days = devdacti_get_int('retention_days', 90);
    return devdacti_valid_retention($days) ? $days : 90;
}

/** 1 day to 10 years. Anything else is a typo, not a policy. */
function devdacti_valid_retention($days)
{
    $days = (int) $days;
    return $days >= 1 && $days <= 3650;
}

/** Operational retention status: last run, last error, rows deleted, backlog, next run. */
function devdacti_retention_status()
{
    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    $status = get_option('devdacti_retention_status', array());
    $unread = devdacti_db_failed();
    if (!is_array($status)) {
        $status = array();
    }
    return array(
        'last_run'     => isset($status['last_run']) ? (string) $status['last_run'] : '',
        'last_success' => isset($status['last_success']) ? (string) $status['last_success'] : '',
        'last_error'   => $unread ? __('The retention status could not be read.', 'devdome-activity-log') : (isset($status['last_error']) ? (string) $status['last_error'] : ''), // a failed read is not "never ran, no error"
        'deleted'      => isset($status['deleted']) ? (int) $status['deleted'] : 0,
        'backlog'      => !empty($status['backlog']),
        'next_run'     => (int) wp_next_scheduled('devdacti_purge'),
    );
}

/**
 * Re-apply the storage schema (batch 2 repair action). Capability-gated and nonce-checked by
 * its handler; idempotent, and it never touches a stored event — dbDelta only adds what the
 * table is missing. The repair itself is recorded in the unsuppressible audit group.
 *
 * @return array before/after health, repaired (bool)
 */
function devdacti_repair_schema()
{
    $before = devdacti_schema_status(true);
    devdacti_install_site();
    $after  = devdacti_schema_status(true);

    devdacti_log_audit(
        'log_repaired',
        __('Activity Log storage', 'devdome-activity-log'),
        empty($after['ok'])
            ? __('A storage repair was run and the events table is still not healthy', 'devdome-activity-log')
            : __('A storage repair was run and the events table is healthy', 'devdome-activity-log'),
        empty($after['ok']) ? 'failure' : 'success'
    );

    return array(
        'before'   => $before,
        'after'    => $after,
        'repaired' => (empty($before['ok']) && !empty($after['ok'])),
    );
}

/** How many rows the CURRENT policy would delete. Powers the manual-purge preview. */
function devdacti_retention_backlog()
{
    global $wpdb;
    $events = devdacti_events_table();
    $cutoff = gmdate('Y-m-d H:i:s', time() - devdacti_retention_days() * DAY_IN_SECONDS);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed count on the internal events table; cutoff bound via prepare.
    $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$events} WHERE created_at < %s", $cutoff));
    return (null === $count) ? -1 : (int) $count;
}

/**
 * Daily retention purge (cron). Deletes events older than the retention window in bounded
 * chunks so a huge backlog can never lock the table for long — and, when the chunk budget
 * runs out, schedules its own continuation instead of leaving a permanent backlog that
 * would silently never catch up.
 *
 * @return array deleted (int), error (string), backlog (bool)
 */
function devdacti_purge_cron()
{
    global $wpdb;

    // The window comes from the policy. If the policy cannot be read, the default 90 days is NOT a
    // policy: a site set to 365 days would lose nine months of evidence. Zero deletes, an error.
    if (!devdacti_policy_readable()) {
        $error = __('The retention policy could not be read from the database, so nothing was deleted.', 'devdome-activity-log');
        devdacti_record_write_failure('retention_purge', 'policy unreadable: ' . $error);
        $previous = get_option('devdacti_retention_status', array());
        $previous = is_array($previous) ? $previous : array();
        $previous['last_run']   = gmdate('Y-m-d H:i:s');
        $previous['last_error'] = devdacti_substr(devdacti_redact($error), 0, 200);
        update_option('devdacti_retention_status', $previous, false);
        return array('deleted' => 0, 'error' => $error, 'backlog' => false);
    }

    $days   = devdacti_retention_days();
    $events = devdacti_events_table();
    $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

    $removed  = 0;
    $error    = '';
    $backlog  = false;
    for ($i = 0; $i < 20; $i++) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; cutoff bound via prepare; chunked retention purge on an uncacheable audit table.
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $events WHERE created_at < %s LIMIT 5000",
            $cutoff
        ));
        if ($deleted === false) {
            $error = (string) $wpdb->last_error;
            devdacti_record_write_failure('retention_purge', $error);
            break;
        }
        $removed += (int) $deleted;
        if ($deleted !== 5000) {
            break;
        }
        if ($i === 19) {
            // Budget exhausted with rows still older than the cutoff: continue soon rather
            // than wait a whole day and accumulate a backlog that never drains. If the
            // continuation cannot be scheduled the backlog becomes permanent, so a refusal
            // is recorded rather than swallowed.
            $backlog   = true;
            $continued = wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, 'devdacti_purge');
            if (false === $continued || (function_exists('is_wp_error') && is_wp_error($continued))) {
                $error = __('The retention purge could not schedule its continuation, so older events are still waiting.', 'devdome-activity-log');
                devdacti_record_write_failure('retention_continuation', 'wp_schedule_single_event() refused the retention continuation');
            }
        }
    }

    if ($removed > 0) {
        devdacti_invalidate_stats();
        // Deleting evidence is itself evidence — and it is in the unsuppressible audit group.
        // The record is checked: a purge that left no record is reported as a failed purge.
        $recorded = devdacti_log_audit(
            'log_purged',
            __('Retention purge', 'devdome-activity-log'),
            sprintf(
                /* translators: 1: number of events deleted, 2: retention window in days. */
                __('%1$d events older than %2$d days were deleted by the retention policy', 'devdome-activity-log'),
                $removed,
                $days
            ) . ('' !== $error ? '; ' . $error : ''),
            '' !== $error ? 'failure' : 'success' // a purge that could not continue is not a successful purge
        );
        if (empty($recorded['ok'])) {
            $error = sprintf(
                /* translators: %d: number of events deleted. */
                __('%d events were deleted but the purge could not be recorded in the log.', 'devdome-activity-log'),
                $removed
            );
            devdacti_record_write_failure('retention_purge_record', $error);
        }
    }

    $previous = get_option('devdacti_retention_status', array());
    $previous = is_array($previous) ? $previous : array();
    $now      = gmdate('Y-m-d H:i:s');

    update_option('devdacti_retention_status', array(
        'last_run'     => $now,
        // A run that failed must not refresh "last success": a permanently failing purge
        // showing a fresh date is exactly the silent failure this plugin must not have.
        'last_success' => ('' === $error) ? $now : (isset($previous['last_success']) ? (string) $previous['last_success'] : ''),
        'last_error'   => devdacti_substr(devdacti_redact($error), 0, 200),
        'deleted'      => $removed,
        'backlog'      => $backlog,
    ), false);

    return array('deleted' => $removed, 'error' => $error, 'backlog' => $backlog);
}
add_action('devdacti_purge', 'devdacti_purge_cron');
