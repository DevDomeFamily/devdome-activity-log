<?php
/**
 * Audit policy (audit 4, batch 3): capability separation, protected (non-suppressible)
 * events, evidence-based severity, request attribution, and the audit-policy event writer.
 *
 * 0.1.2 had ONE filtered capability for reading the log, exporting personal data, changing
 * coverage, excluding actors and shortening retention. Anyone allowed to look at the
 * dashboard was also allowed to destroy the evidence in it, and none of those changes were
 * themselves recorded. This file splits those powers apart and makes every policy change,
 * export and purge leave a record that the same policy cannot switch off.
 */

defined('ABSPATH') || exit;

/**
 * The distinct powers this plugin grants. Each maps to its own capability so an agency can
 * hand out "read the log" without also handing out "export it" or "delete it".
 *
 * read      view the dashboard, the log table and the REST feed
 * export    download the CSV (personal data leaves the site)
 * manage    change retention, groups, exclusions, proxy and privacy policy
 * purge     run destructive retention work by hand
 * integrity see the schema/write diagnostics
 * repair    re-apply the storage schema after a failed migration
 * view_ip   see the stored IP in full rather than masked
 *
 * These are REAL capabilities, granted to the administrator role on install and migration
 * (devdacti_grant_caps()). Mapping them all onto manage_options — as 0.2.0-dev did — would
 * have made the separation cosmetic: handing an editor "read" would have handed them nothing,
 * and every reader would still hold purge.
 */
function devdacti_capabilities()
{
    return array(
        'read'      => 'devdacti_read',
        'export'    => 'devdacti_export',
        'manage'    => 'devdacti_manage',
        'purge'     => 'devdacti_purge_log',
        'integrity' => 'devdacti_integrity',
        'repair'    => 'devdacti_repair',
        'view_ip'   => 'devdacti_view_ip',
    );
}

/**
 * Grant every power to the administrator role. Called from the per-site install, so it also
 * runs on a normal update and on each multisite blog. Additive and idempotent.
 */
function devdacti_grant_caps()
{
    if (!function_exists('get_role')) {
        return null; // no role API here (outside WordPress): nothing to grant, nothing failed
    }
    $role = get_role('administrator');
    if (!$role) {
        return false;
    }
    foreach (devdacti_capabilities() as $cap) {
        if (!$role->has_cap($cap)) {
            $role->add_cap($cap);
        }
    }
    // Read back from the STORED roles option: add_cap() mutates the object before the option write, so the object
    // says yes even when the write failed; the option only carries what was really persisted.
    $stored = (function_exists('wp_roles') && isset(wp_roles()->role_key)) ? get_option(wp_roles()->role_key) : false;
    if (!is_array($stored) || !isset($stored['administrator']['capabilities']) || !is_array($stored['administrator']['capabilities'])) {
        return false;
    }
    foreach (devdacti_capabilities() as $cap) {
        if (empty($stored['administrator']['capabilities'][$cap])) {
            return false;
        }
    }
    return true;
}

/**
 * Capability for one power. The legacy `devdacti_capability` filter now scopes READ only:
 * a site that widened it to let editors look at the log must not thereby have granted them
 * export and purge. Each power has its own `devdacti_capability_<power>` filter.
 */
function devdacti_capability($context = 'read')
{
    $caps    = devdacti_capabilities();
    $context = isset($caps[$context]) ? $context : 'read';
    $cap     = $caps[$context];

    if ('read' === $context) {
        $cap = apply_filters('devdacti_capability', $cap);
    }

    return (string) apply_filters('devdacti_capability_' . $context, $cap, $context);
}

/**
 * Whether the current user holds one specific power.
 *
 * manage_options is a second route on purpose: the site owner always holds every power, and
 * an install whose role grant has not run yet (or a site where the caps were removed by hand)
 * must not lock its own administrator out of the log. The separation still works, because a
 * custom role granted devdacti_read holds READ and nothing else.
 */
function devdacti_can($context = 'read')
{
    if (current_user_can(devdacti_capability($context))) {
        return true;
    }
    return current_user_can('manage_options');
}

/**
 * Groups whose events can never be suppressed. `audit` is unconditional — a policy change
 * that could hide policy changes would make the whole log unfalsifiable. The rest are
 * covered by the protected-policy mode, which is ON by default.
 */
function devdacti_protected_groups()
{
    $groups = array('audit');
    if (devdacti_get_int('protect_critical', 1) === 1) {
        $groups = array_merge($groups, array('auth', 'users', 'plugins'));
    }
    return $groups;
}

/** True when this action's group cannot be switched off or excluded away. */
function devdacti_action_protected($action)
{
    $group = devdacti_group_for_action((string) $action);
    return '' !== $group && in_array($group, devdacti_protected_groups(), true);
}

/**
 * Severity, derived from the action itself — not from a guess about intent. One failed
 * login is a `warning` because it is worth seeing; it is never described as an attack.
 */
function devdacti_action_severity($action)
{
    static $map = null;
    if (null === $map) {
        $map = array(
            // things that change who can do what, or that destroy evidence
            'audit_policy_changed'      => 'critical',
            'log_purged'                => 'critical',
            'log_erased'                => 'critical',
            'user_role_changed'         => 'critical',
            'user_deleted'              => 'critical',
            'user_email_changed'        => 'critical',
            // things worth reviewing. Creating an account, granting a secondary role and a
            // completed password reset are ROUTINE administration: calling every one of them
            // critical is what made the old review feed unreadable, and an unreadable feed
            // hides the escalation that matters.
            'user_created'              => 'warning',
            'user_role_added'           => 'warning',
            'user_role_removed'         => 'warning',
            'password_reset_completed'  => 'warning',
            'log_repaired'              => 'warning',
            'login_failed'              => 'warning',
            'password_reset_requested'  => 'warning',
            'plugin_activated'          => 'warning',
            'plugin_deactivated'        => 'warning',
            'plugin_installed'          => 'warning',
            'plugin_deleted'            => 'warning',
            'theme_installed'           => 'warning',
            'theme_deleted'             => 'warning',
            'theme_switched'            => 'warning',
            'option_updated'            => 'warning',
            'log_exported'              => 'warning',
            'schema_migrated'           => 'warning',
            'user_profile_updated'      => 'notice',
            'plugin_updated'            => 'notice',
            'theme_updated'             => 'notice',
            'core_updated'              => 'notice',
            'media_deleted'             => 'notice',
            'post_deleted'              => 'notice',
        );
    }
    $action = (string) $action;
    return isset($map[$action]) ? $map[$action] : 'info';
}

/**
 * Record that someone READ the log (batch 3: read access is itself auditable).
 *
 * Bucketed per reader per hour, so a dashboard left open on a refresh cycle leaves ONE row
 * with a counter instead of a row per request — a read log that floods the log it describes
 * is worse than no read log at all.
 */
function devdacti_log_read_access($ok = true)
{
    if (!function_exists('devdacti_log_aggregated')) {
        return;
    }
    $actor = devdacti_resolve_actor(array());
    devdacti_log_aggregated(array(
        'action'      => 'log_read',
        // The outcome is part of the bucket identity (the subject), so a failed read is its own row, never a +1 on
        // an earlier successful one.
        'subject'     => ('' !== $actor['user_login'] ? $actor['user_login'] : __('(anonymous)', 'devdome-activity-log')) . ($ok ? '' : ' ' . __('(failed read)', 'devdome-activity-log')),
        'object_type' => 'policy',
        'ip_exact'    => devdacti_client_ip_exact(),
        'ip_display'  => devdacti_ip(),
        'outcome'     => $ok ? 'success' : 'failure',
        'details'     => $ok ? __('The activity log was read', 'devdome-activity-log') : __('The activity log could not be read (the query failed)', 'devdome-activity-log'),
    ));
}

/** The severities the dashboard's review feed shows. Policy, not a hard-coded action list. */
function devdacti_review_severities()
{
    return apply_filters('devdacti_review_severities', array('warning', 'critical'));
}

/**
 * Failed-login assessment. 0.1.2 dropped suite health to 70 and said "brute-force attack"
 * for a single mistyped password. Health now moves only past a stated threshold, and the
 * copy states the count and the threshold rather than diagnosing an attack.
 *
 * @return array status (good|warn|urgent), score (int), threshold (int)
 */
function devdacti_failed_login_assessment($count)
{
    $count  = max(0, (int) $count);
    $warn   = max(1, (int) apply_filters('devdacti_failed_login_warn_threshold', 20));
    $urgent = max($warn + 1, (int) apply_filters('devdacti_failed_login_urgent_threshold', 100));

    if ($count >= $urgent) {
        return array('status' => 'urgent', 'score' => 40, 'threshold' => $urgent);
    }
    if ($count >= $warn) {
        return array('status' => 'warn', 'score' => 70, 'threshold' => $warn);
    }
    return array('status' => 'good', 'score' => 100, 'threshold' => $warn);
}

/**
 * One correlation id per request. Several WordPress hooks routinely describe one
 * user-visible action; a shared request id is what lets an investigator tell "three rows,
 * one action" from "three actions".
 */
function devdacti_request_id()
{
    static $id = '';
    if ('' === $id) {
        try {
            $id = bin2hex(random_bytes(8));
        } catch (Exception $e) {
            $id = substr(hash('sha256', uniqid('', true)), 0, 16);
        }
    }
    return $id;
}

/** Which door the request came through. Part of attribution, not decoration. */
function devdacti_request_channel()
{
    if (defined('WP_CLI') && WP_CLI) {
        return 'cli';
    }
    if (defined('DOING_CRON') && DOING_CRON) {
        return 'cron';
    }
    if (function_exists('did_action') && did_action('application_password_did_authenticate')) {
        return 'app_password';
    }
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
        return 'xmlrpc';
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return 'rest';
    }
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return 'ajax';
    }
    return 'web';
}

/** user | system (cron/CLI) | anonymous. */
function devdacti_actor_type($user_id)
{
    if ((int) $user_id > 0) {
        return 'user';
    }
    $channel = devdacti_request_channel();
    return in_array($channel, array('cli', 'cron'), true) ? 'system' : 'anonymous';
}

/**
 * Human labels for the machine values a row stores.
 *
 * actor_type, channel, severity and outcome are stored in English because they are
 * identifiers, not prose. Printing them raw in the UI left a German or Arabic admin reading
 * "app_password" and "anonymous"; this is the one place they become words.
 *
 * @return array<string,string> raw value => label
 */
function devdacti_value_labels()
{
    return array(
        // actor_type
        'user'         => __('User', 'devdome-activity-log'),
        'system'       => __('System', 'devdome-activity-log'),
        'anonymous'    => __('Anonymous', 'devdome-activity-log'),
        // channel
        'web'          => __('Web', 'devdome-activity-log'),
        'ajax'         => __('Admin AJAX', 'devdome-activity-log'),
        'rest'         => __('REST API', 'devdome-activity-log'),
        'xmlrpc'       => __('XML-RPC', 'devdome-activity-log'),
        'cron'         => __('Scheduled task', 'devdome-activity-log'),
        'cli'          => __('WP-CLI', 'devdome-activity-log'),
        'app_password' => __('Application password', 'devdome-activity-log'),
        // severity
        'info'         => __('Info', 'devdome-activity-log'),
        'notice'       => __('Notice', 'devdome-activity-log'),
        'warning'      => __('Warning', 'devdome-activity-log'),
        'critical'     => __('Critical', 'devdome-activity-log'),
        // outcome
        'success'      => __('Success', 'devdome-activity-log'),
        'failure'      => __('Failure', 'devdome-activity-log'),
    );
}

/** One label, falling back to the raw value rather than to nothing. */
function devdacti_value_label($value)
{
    $labels = devdacti_value_labels();
    $value  = (string) $value;
    return isset($labels[$value]) ? $labels[$value] : $value;
}

/** The blog this event belongs to (1 on single site). Multisite queries need it. */
function devdacti_site_id()
{
    return function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
}

/**
 * Write an audit-policy event. Group `audit` is unconditionally protected, so these rows
 * are written even when every other group is off and the actor is on the exclusion list.
 */
function devdacti_log_audit($action, $subject, $details, $outcome = 'success')
{
    return devdacti_log(array(
        'action'       => $action,
        'object_type'  => 'policy',
        'object_title' => $subject,
        'details'      => $details,
        'outcome'      => $outcome,
    ));
}

/** The policy values a change is diffed against. One list, used by the save handler. */
function devdacti_policy_settings()
{
    return array(
        'retention_days', 'anonymize_ip', 'protect_critical', 'trusted_proxies',
        'group_auth', 'group_content', 'group_plugins', 'group_users',
        'group_media', 'group_comments', 'group_settings',
        'excluded_roles', 'excluded_users', 'forwarded_header',
    );
}

/** Current value of every policy setting, for before/after comparison. */
function devdacti_policy_snapshot()
{
    $out = array();
    foreach (devdacti_policy_settings() as $name) {
        if ('excluded_roles' === $name || 'excluded_users' === $name) {
            $out[$name] = devdacti_get_array($name, array());
        } elseif ('trusted_proxies' === $name || 'forwarded_header' === $name) {
            $out[$name] = (string) devdacti_get_setting($name, '');
        } else {
            $out[$name] = devdacti_get_int($name, 'retention_days' === $name ? 90 : 1);
        }
    }
    return $out;
}

/** Printable form of a policy value for the before/after detail string. */
function devdacti_policy_value_text($value)
{
    if (is_array($value)) {
        return $value ? implode(', ', array_map('strval', $value)) : '(none)';
    }
    if ('' === (string) $value) {
        return '(none)';
    }
    return (string) $value;
}

/**
 * Which parts of a proposed policy REDUCE audit coverage. Weakening the audit policy is a
 * decision, not a checkbox: the save handler refuses until it is explicitly confirmed.
 *
 * @return string[] human descriptions, empty when the change does not weaken coverage
 */
function devdacti_policy_weakenings(array $before, array $after)
{
    $out = array();

    if (isset($before['retention_days'], $after['retention_days']) && (int) $after['retention_days'] < (int) $before['retention_days']) {
        $out[] = sprintf(
            /* translators: 1: previous retention in days, 2: new retention in days. */
            __('Retention drops from %1$d to %2$d days, which deletes older evidence.', 'devdome-activity-log'),
            (int) $before['retention_days'],
            (int) $after['retention_days']
        );
    }

    if (!empty($before['protect_critical']) && empty($after['protect_critical'])) {
        $out[] = __('Protected event groups are being unlocked, so authentication, user and plugin events become suppressible.', 'devdome-activity-log');
    }

    foreach (devdacti_group_labels() as $group => $label) {
        $key = 'group_' . $group;
        if (!empty($before[$key]) && empty($after[$key])) {
            /* translators: %s: event group label. */
            $out[] = sprintf(__('The "%s" event group is being switched off.', 'devdome-activity-log'), $label);
        }
    }

    $new_roles = array_diff((array) (isset($after['excluded_roles']) ? $after['excluded_roles'] : array()), (array) (isset($before['excluded_roles']) ? $before['excluded_roles'] : array()));
    foreach ($new_roles as $role) {
        /* translators: %s: role slug being excluded from logging. */
        $out[] = sprintf(__('The role "%s" is being excluded from logging.', 'devdome-activity-log'), $role);
    }

    $new_users = array_diff((array) (isset($after['excluded_users']) ? $after['excluded_users'] : array()), (array) (isset($before['excluded_users']) ? $before['excluded_users'] : array()));
    foreach ($new_users as $login) {
        /* translators: %s: username being excluded from logging. */
        $out[] = sprintf(__('The user "%s" is being excluded from logging.', 'devdome-activity-log'), $login);
    }

    return $out;
}

/**
 * Apply a validated policy: the ONE write path the Settings screen and the update-settings ability share.
 *
 * Refuses (nothing written) when the change weakens coverage and $confirmed is false, so the caller can show
 * the list. Otherwise every changed value is recorded in the unsuppressible audit group BEFORE it is applied,
 * then written; a failed write is itself recorded.
 *
 * @param array $after     full policy as devdacti_posted_policy() / the ability builds it (name => value)
 * @param bool  $confirmed the owner acknowledged the weakenings
 * @return array { ok: bool, needs_confirm: bool, weakenings: string[], changes: int, failed: int }
 */
function devdacti_apply_policy(array $after, $confirmed)
{
    // The diff below decides whether the change weakens coverage and what is recorded as the previous value.
    // A snapshot built on defaults because the settings table could not be read is not the policy in force:
    // refuse, write nothing. (A fresh read; the request cache is dropped first.)
    if (!devdacti_policy_readable()) {
        return array('ok' => false, 'needs_confirm' => false, 'weakenings' => array(), 'changes' => 0, 'failed' => count($after), 'unreadable' => true);
    }
    $before     = devdacti_policy_snapshot();
    $weakenings = devdacti_policy_weakenings($before, $after);
    if ($weakenings && !$confirmed) {
        return array('ok' => false, 'needs_confirm' => true, 'weakenings' => $weakenings, 'changes' => 0, 'failed' => 0);
    }

    // Only the values that differ are written, all of them in ONE statement: the policy in force is
    // either the old one or the new one, never a mix. The records follow the outcome: one audit row per
    // changed value on success, one failure row naming every attempted value when the write failed.
    // Either way the attempt is on record, in the group the policy itself cannot suppress.
    $changed = array();
    foreach ($after as $name => $value) {
        if (!array_key_exists($name, $before) || devdacti_policy_value_text($before[$name]) === devdacti_policy_value_text($value)) {
            continue;
        }
        $changed[$name] = $value;
    }
    if (!$changed) {
        return array('ok' => true, 'needs_confirm' => false, 'weakenings' => $weakenings, 'changes' => 0, 'failed' => 0);
    }

    $written = devdacti_update_settings($changed);

    $audit_failed = 0;
    if ($written) {
        foreach ($changed as $name => $value) {
            $recorded = devdacti_log_audit(
                'audit_policy_changed',
                $name,
                sprintf(
                    /* translators: 1: setting name, 2: previous value, 3: new value. */
                    __('%1$s: "%2$s" -> "%3$s"', 'devdome-activity-log'),
                    $name,
                    devdacti_policy_value_text($before[$name]),
                    devdacti_policy_value_text($value)
                )
            );
            if (empty($recorded['ok'])) {
                $audit_failed++; // the change IS stored; what is missing is its record (devdacti_log counted the lost write)
            }
        }
    } else {
        $attempted = array();
        foreach ($changed as $name => $value) {
            $attempted[] = $name . ': "' . devdacti_policy_value_text($before[$name]) . '" -> "' . devdacti_policy_value_text($value) . '"';
        }
        devdacti_log_audit(
            'audit_policy_changed',
            __('Activity Log settings', 'devdome-activity-log'),
            sprintf(
                /* translators: 1: number of settings that could not be stored, 2: the attempted changes. */
                __('%1$d settings could not be stored or verified; the stored policy may differ from what was attempted: %2$s', 'devdome-activity-log'),
                count($changed),
                implode('; ', $attempted)
            ),
            'failure'
        );
    }

    return array('ok' => $written, 'needs_confirm' => false, 'weakenings' => $weakenings, 'changes' => count($changed), 'failed' => $written ? 0 : count($changed), 'audit_failed' => $audit_failed);
}

/** Roles that can administer the site — excluding one of these is called out separately. */
function devdacti_privileged_roles()
{
    return apply_filters('devdacti_privileged_roles', array('administrator', 'super_admin'));
}
