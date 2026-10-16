<?php
/**
 * Settings storage — a single name/value table (the suite's Bot Protection /
 * Redirect Manager convention) with a process-level read cache. Arrays are
 * serialized on write and unserialized with allowed_classes => false on read.
 */

defined('ABSPATH') || exit;

function devdacti_settings_table()
{
    global $wpdb;
    return $wpdb->prefix . 'devdacti_settings';
}

/** Read a setting, falling back to $default when unset. Arrays are unserialized. */
function devdacti_get_setting($name, $default = '')
{
    global $wpdb;

    // One cache per site: after switch_to_blog() the prefix, the table and the policy are another site's.
    $blog = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    if (!isset($GLOBALS['devdacti_cache']) || !is_array($GLOBALS['devdacti_cache']) || !isset($GLOBALS['devdacti_cache_blog']) || (int) $GLOBALS['devdacti_cache_blog'] !== $blog) {
        $GLOBALS['devdacti_cache']      = array();
        $GLOBALS['devdacti_cache_blog'] = $blog;
        $table = devdacti_settings_table();
        devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; whole-table read cached for the request in $GLOBALS.
        $rows = $wpdb->get_results("SELECT setting_name, setting_value FROM $table", ARRAY_A);
        if (!is_array($rows) || (string) $wpdb->last_error !== '') {
            // The policy could not be read. Readers get defaults for THIS call, nothing is cached (the
            // next read tries again), and devdacti_policy_readable() says so to anything destructive.
            $GLOBALS['devdacti_cache_failed'] = true;
            unset($GLOBALS['devdacti_cache']);
            // The table itself is gone (plugin files copied into place or restored without the activation hook,
            // audit 2026-10-16: a site ran the plugin as active with no tables and a failing query on every
            // request) while the schema stamp still says "current", so devdacti_maybe_upgrade() never rebuilt
            // it. Drop the stamp: the next load runs the idempotent install again and stamps only when verified.
            if (false !== stripos((string) $wpdb->last_error, "doesn't exist") || false !== stripos((string) $wpdb->last_error, 'does not exist')) {
                delete_option('devdacti_schema_version');
            }
            return $default;
        }
        $GLOBALS['devdacti_cache_failed'] = false;
        foreach ($rows as $r) {
            $GLOBALS['devdacti_cache'][$r['setting_name']] = $r['setting_value'];
        }
    }

    if (!array_key_exists($name, $GLOBALS['devdacti_cache'])) {
        return $default;
    }

    $value = $GLOBALS['devdacti_cache'][$name];
    // Only a serialized value is unserialized (a plain value through unserialize() warns under a debug handler,
    // and the @ does not silence a registered one: Bot Protection review, 29 Sep 2026).
    if (is_serialized($value)) {
        $unser = unserialize($value, array('allowed_classes' => false));
        if ($unser !== false || $value === 'b:0;') {
            return $unser;
        }
    }
    return $value;
}

/**
 * Write a setting (insert or update). Arrays are serialized. Invalidates the cache.
 *
 * @return bool True when the row was written. A false return is a real storage failure and
 *              callers must surface it — announcing "Settings saved." after a failed write
 *              tells an administrator their audit policy changed when it did not.
 */
/**
 * Write several settings in ONE statement, so either the whole policy lands or none of it does
 * (a multi-row INSERT ... ON DUPLICATE KEY UPDATE is atomic on InnoDB). Returns true only when
 * the statement succeeded.
 *
 * @param array $values name => value
 */
/**
 * DESIGN.md 24.5: a settings write is proved by reading the rows back; SQL "success" alone is not. True only when every
 * name is present with exactly the stored string (Codex 1.0.1 round 1).
 *
 * @param array $stored name => the string that was written
 */
function devdacti_settings_written(array $stored)
{
    global $wpdb;
    if (!$stored) {
        return true;
    }
    $table  = devdacti_settings_table();
    $names  = array_map('strval', array_keys($stored));
    $places = implode(', ', array_fill(0, count($names), '%s'));
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; placeholder-only fragment; the read-back must see the row, never a cache.
    $rows = $wpdb->get_results($wpdb->prepare("SELECT setting_name, setting_value FROM $table WHERE setting_name IN ($places)", $names), ARRAY_A);
    if (!is_array($rows)) {
        return false;
    }
    $got = array();
    foreach ($rows as $row) {
        if (isset($row['setting_name'])) {
            $got[(string) $row['setting_name']] = isset($row['setting_value']) ? (string) $row['setting_value'] : null;
        }
    }
    foreach ($stored as $name => $value) {
        if (!array_key_exists((string) $name, $got) || $got[(string) $name] !== (string) $value) {
            return false;
        }
    }
    return true;
}

function devdacti_update_settings(array $values)
{
    global $wpdb;
    if (!$values) {
        return true;
    }
    if (function_exists('devdacti_db_guard_active') && devdacti_db_guard_active()) {
        return false; // DESIGN.md 24: no write after a failed query inside this action
    }
    $table  = devdacti_settings_table();
    $places = array();
    $params = array();
    foreach ($values as $name => $value) {
        $places[] = '(%s, %s)';
        $params[] = (string) $name;
        $params[] = is_array($value) ? serialize($value) : (string) $value;
    }
    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // $places is a list of literal '(%s, %s)' pairs; every value is bound through prepare() below.
    $upsert_sql = "INSERT INTO $table (setting_name, setting_value) VALUES " . implode(', ', $places) . ' ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- placeholder-only fragments.
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; one atomic multi-row upsert, values bound via prepare.
    $ok = $wpdb->query($wpdb->prepare($upsert_sql, $params));
    unset($GLOBALS['devdacti_cache']);
    if (false === $ok) {
        return false;
    }
    $stored = array();
    foreach ($values as $name => $value) {
        $stored[(string) $name] = is_array($value) ? serialize($value) : (string) $value;
    }
    if (!devdacti_settings_written($stored)) {
        return false; // the rows do not hold what was written: never report "Saved."
    }
    devdacti_invalidate_stats();
    return true;
}

function devdacti_update_setting($name, $value)
{
    global $wpdb;
    if (function_exists('devdacti_db_guard_active') && devdacti_db_guard_active()) {
        return false; // DESIGN.md 24: no write after a failed query inside this action
    }
    $table = devdacti_settings_table();
    $stored = is_array($value) ? serialize($value) : $value;

    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; values bound via prepare; settings table is not cacheable.
    $ok = $wpdb->query($wpdb->prepare(
        "INSERT INTO $table (setting_name, setting_value) VALUES (%s, %s)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        $name,
        $stored
    ));

    unset($GLOBALS['devdacti_cache']);
    if ($ok === false) {
        return false;
    }
    if (!devdacti_settings_written(array((string) $name => (string) $stored))) {
        return false; // the row does not hold what was written: never report "Saved."
    }
    // A group/exclusion/retention change alters what the dashboard is counting.
    devdacti_invalidate_stats();
    return true;
}

/** Convenience: read an array setting, always returning an array. */
function devdacti_get_array($name, $default = array())
{
    $v = devdacti_get_setting($name, $default);
    return is_array($v) ? $v : (array) $default;
}

/** Read an integer setting. */
function devdacti_get_int($name, $default = 0)
{
    return (int) devdacti_get_setting($name, $default);
}

/**
 * Can the policy be read RIGHT NOW? A fresh read of the settings table (the request cache is
 * dropped first). Anything that deletes evidence asks this before acting: a retention window
 * that fell back to its default because the table could not be read must never drive a DELETE.
 */
function devdacti_policy_readable()
{
    if (function_exists('devdacti_db_guard_active') && devdacti_db_guard_active()) {
        return false;
    }
    unset($GLOBALS['devdacti_cache']);
    devdacti_get_setting('retention_days', 90);
    return empty($GLOBALS['devdacti_cache_failed']);
}
