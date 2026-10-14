<?php
/**
 * WordPress Abilities API layer (WordPress 6.9+): DevDome Activity Log exposed as typed, discoverable
 * abilities for AI agents and MCP clients (through the official WordPress MCP Adapter).
 *
 * Coverage = every question and every action the plugin's own screen offers, audited against the code
 * (2026-10-12): the Dashboard numbers (get-status), the Activity Log tab with every filter (get-events and the
 * four shaped reads an agent asks most: recent activity, one user, logins, plugin / theme / core changes),
 * a review feed (find-suspicious-changes), the Settings tab (get-settings, update-settings through the same
 * devdacti_apply_policy() the screen uses, with the same coverage-weakening confirmation), the manual purge
 * (run-purge, destructive, confirm: true) and the storage repair (repair-storage, confirm: true).
 * Every read of event rows is recorded exactly like the screen's read (log_read, one row per reader per hour).
 * Each ability checks the SAME capability as the screen action it mirrors (read / manage / purge / repair),
 * so a role that may read the log cannot change its policy through an agent. Every answer passes the
 * database guard (DESIGN.md 24) and the output sanitizer (no absolute path, no raw e-mail address).
 * On WordPress older than 6.9 the API does not exist and this file registers nothing.
 */

defined('ABSPATH') || exit;

function devdacti_ability_ids()
{
    return array(
        'devdome-activity-log/get-status',
        'devdome-activity-log/get-settings',
        'devdome-activity-log/get-events',
        'devdome-activity-log/get-recent-activity',
        'devdome-activity-log/get-user-activity',
        'devdome-activity-log/get-login-events',
        'devdome-activity-log/get-plugin-changes',
        'devdome-activity-log/find-suspicious-changes',
        'devdome-activity-log/update-settings',
        'devdome-activity-log/run-purge',
        'devdome-activity-log/repair-storage',
    );
}

add_action('wp_abilities_api_categories_init', 'devdacti_register_ability_category');
function devdacti_register_ability_category()
{
    if (!function_exists('wp_register_ability_category')) {
        return;
    }
    wp_register_ability_category('devdome-activity-log', array(
        'label'       => __('DevDome Activity Log', 'devdome-activity-log'),
        'description' => __('A local audit log of logins, content, user, plugin, theme, media, comment and key-setting changes on this WordPress site: read it with filters, review failed logins and privilege changes, and manage its retention and coverage policy.', 'devdome-activity-log'),
    ));
}

/* ------------------------------ permissions ------------------------------ */

function devdacti_ability_can_read()   { return devdacti_can('read'); }
function devdacti_ability_can_manage() { return devdacti_can('manage'); }
function devdacti_ability_can_purge()  { return devdacti_can('purge'); }
function devdacti_ability_can_repair() { return devdacti_can('repair'); }

/** read / modify / destroy annotations; $idempotent overrides the default for that kind. */
function devdacti_ability_meta($kind, $idempotent = null)
{
    $map = array(
        'read'    => array('readonly' => true,  'destructive' => false, 'idempotent' => true),
        'modify'  => array('readonly' => false, 'destructive' => false, 'idempotent' => true),
        'destroy' => array('readonly' => false, 'destructive' => true,  'idempotent' => true),
    );
    $ann = isset($map[$kind]) ? $map[$kind] : $map['modify'];
    if (null !== $idempotent) {
        $ann['idempotent'] = (bool) $idempotent;
    }
    return array(
        'public'       => true,
        'show_in_rest' => true,
        'annotations'  => $ann,
        'mcp'          => array('type' => 'tool'),
    );
}

/** confirm: true or a WP_Error the agent must show the user. */
function devdacti_ability_confirmed($input, $why)
{
    if (is_array($input) && isset($input['confirm']) && true === $input['confirm']) {
        return true;
    }
    return new WP_Error('devdacti_confirm_required', $why . ' ' . __('Pass confirm: true after the site owner agreed.', 'devdome-activity-log'));
}

/**
 * Output sanitizer at the ability boundary: no credential, no absolute server path and no raw e-mail address
 * reach an agent. Event rows are already redacted at write time and e-mails in user events are masked; this is
 * the net, and it covers every string: values, map KEYS (a targeted account name is a key) and error data.
 */
function devdacti_ability_strip($v)
{
    if ($v instanceof WP_Error) {
        $e = new WP_Error();
        foreach ($v->get_error_codes() as $code) {
            $data = $v->get_error_data($code);
            foreach ($v->get_error_messages($code) as $m) {
                $e->add($code, devdacti_ability_strip($m), null === $data ? null : devdacti_ability_strip($data));
            }
        }
        return $e;
    }
    if (is_object($v)) {
        return (object) devdacti_ability_strip(get_object_vars($v));
    }
    if (is_array($v)) {
        $out = array();
        foreach ($v as $k => $x) {
            $key = is_string($k) ? devdacti_ability_strip_text($k) : $k;
            if (is_string($key) && array_key_exists($key, $out)) {
                // Two different sensitive keys collapse to the same placeholder: keep both values.
                $n = 2;
                while (array_key_exists($key . ' #' . $n, $out)) {
                    $n++;
                }
                $key .= ' #' . $n;
            }
            $out[$key] = devdacti_ability_strip($x);
        }
        return $out;
    }
    if (!is_string($v) || '' === $v) {
        return $v;
    }
    return devdacti_ability_strip_text($v);
}

/** One string through the net: secrets (the storage redactor), e-mail addresses, absolute paths. */
function devdacti_ability_strip_text($v)
{
    $v = (string) $v;
    if ('' === $v) {
        return $v;
    }
    $v = devdacti_redact($v);
    $v = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '<email>', $v);
    if (defined('ABSPATH') && '' !== (string) ABSPATH && false !== strpos($v, (string) ABSPATH)) {
        $v = str_replace((string) ABSPATH, '<site>/', $v);
    }
    // Any absolute Unix path of two or more segments (/etc/passwd, /var/www/html/wp-config.php); a plugin
    // basename (akismet/akismet.php) has no leading slash and is kept.
    $v = preg_replace('~(?<![\w:/.])(?:/[A-Za-z0-9._-]+){2,}/?~', '<path>', $v);
    $v = preg_replace('~(?<![\w])[A-Za-z]:[\\\\/][^\s"\'<>]*~', '<path>', $v);
    // UNC paths: \\server\share\... (two leading backslashes, then at least one more segment).
    $v = preg_replace('~(?<![\w])\\\\\\\\[^\s"\'<>\\\\]+\\\\[^\s"\'<>]*~', '<path>', $v);
    return $v;
}

/* ------------------------------ shared shapes ------------------------------ */

/** Hours window -> the exact UTC lower bound the query builder takes. 1 hour to 366 days. */
function devdacti_ability_hours($input, $default)
{
    $h = isset($input['hours']) ? (int) $input['hours'] : (int) $default;
    if ($h < 1 || $h > 8784) {
        return new WP_Error('devdacti_invalid_input', __('hours must be between 1 and 8784 (366 days).', 'devdome-activity-log'));
    }
    return $h;
}

function devdacti_ability_since($hours)
{
    return gmdate('Y-m-d H:i:s', time() - (int) $hours * HOUR_IN_SECONDS);
}

/** limit -> per_page within the query bounds (1..100). */
function devdacti_ability_limit($input, $default = 50)
{
    $l = isset($input['limit']) ? (int) $input['limit'] : (int) $default;
    if ($l < 1 || $l > 100) {
        return new WP_Error('devdacti_invalid_input', __('limit must be between 1 and 100.', 'devdome-activity-log'));
    }
    return $l;
}

/** The event-row fields an agent gets: the REST row minus the display date (agents want UTC). */
function devdacti_ability_row(array $r)
{
    return array(
        'id'           => (int) $r['id'],
        'created_at'   => (string) $r['created_at'],
        'first_seen'   => (string) $r['first_seen'],
        'last_seen'    => (string) $r['last_seen'],
        'severity'     => (string) $r['severity'],
        'outcome'      => (string) $r['outcome'],
        'action'       => (string) $r['action'],
        'action_label' => (string) $r['action_label'],
        'group'        => (string) $r['group'],
        'user_id'      => (int) $r['user_id'],
        'user_login'   => (string) $r['user_login'],
        'user_role'    => (string) $r['user_role'],
        'actor_type'   => (string) $r['actor_type'],
        'channel'      => (string) $r['channel'],
        'request_id'   => (string) $r['request_id'],
        'ip'           => (string) $r['ip'],
        'object_type'  => (string) $r['object_type'],
        'object_id'    => (int) $r['object_id'],
        'object_title' => (string) $r['object_title'],
        'details'      => (string) $r['details'],
        'occurrences'  => (int) $r['counter'],
    );
}

/**
 * Validate + run one filtered query and shape the answer. Shared by every event-reading ability.
 * Records the read (the same log_read row the screen writes). A failed query is an error, never an empty list.
 */
function devdacti_ability_query(array $filters)
{
    $invalid = devdacti_validate_query($filters);
    if ($invalid) {
        $messages = array();
        foreach ($invalid as $e) {
            $messages[] = $e['message'];
        }
        return new WP_Error('devdacti_invalid_input', implode(' ', $messages), array('fields' => wp_list_pluck($invalid, 'code')));
    }
    $result = devdacti_query_events($filters);
    devdacti_log_read_access(empty($result['error'])); // recorded with the real outcome, after the read
    if (!empty($result['error'])) {
        return new WP_Error('devdacti_query_failed', __('The activity log could not be read. Recording may also be affected.', 'devdome-activity-log'));
    }
    $rows = array();
    foreach ($result['rows'] as $r) {
        $rows[] = devdacti_ability_row($r);
    }
    $per = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 25;
    return array(
        'total'       => (int) $result['total'],
        'occurrences' => (int) $result['occurrences'],
        'page'        => isset($filters['page']) ? max(1, (int) $filters['page']) : 1,
        'per_page'    => $per,
        'pages'       => (int) ceil($result['total'] / $per),
        'events'      => $rows,
    );
}

/** Policy settings as the agent sees them (the Settings tab, one shape for get-settings and the read-back). */
function devdacti_ability_settings()
{
    $snap = devdacti_policy_snapshot();
    $out  = array(
        'retention_days'   => (int) $snap['retention_days'],
        'anonymize_ip'     => !empty($snap['anonymize_ip']),
        'protect_critical' => !empty($snap['protect_critical']),
        'trusted_proxies'  => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $snap['trusted_proxies'])), 'strlen')),
        'forwarded_header' => (string) $snap['forwarded_header'],
        'groups'           => array(),
        'excluded_roles'   => array_values(array_map('strval', (array) $snap['excluded_roles'])),
        'excluded_users'   => array_values(array_map('strval', (array) $snap['excluded_users'])),
        'protected_groups' => devdacti_protected_groups(),
    );
    foreach (array_keys(devdacti_group_labels()) as $g) {
        if ('audit' === $g) {
            continue;
        }
        $out['groups'][$g] = devdacti_group_enabled($g);
    }
    return $out;
}

/** Strict switch value: bool, 0/1 or the spellings true/false/yes/no/on/off; anything else = null (refused). */
function devdacti_ability_bool($v)
{
    if (is_bool($v)) {
        return $v;
    }
    if (is_int($v) && (0 === $v || 1 === $v)) {
        return 1 === $v;
    }
    if (is_string($v)) {
        $s = strtolower(trim($v));
        if (in_array($s, array('1', 'true', 'yes', 'on'), true)) {
            return true;
        }
        if (in_array($s, array('0', 'false', 'no', 'off'), true)) {
            return false;
        }
    }
    return null;
}

/* ------------------------------ handlers: reads ------------------------------ */

function devdacti_ability_get_status($input = array())
{
    $schema = devdacti_schema_status();
    $writes = devdacti_write_status();
    $stats  = devdacti_dashboard_stats();
    $ret    = devdacti_retention_status();
    $failed = devdacti_failed_login_assessment((int) $stats['failed_24h']);
    $counts_failed = !empty($stats['error']);
    $overall = 'good';
    if (empty($schema['ok']) || $writes['failures'] > 0 || $counts_failed) {
        $overall = 'critical';
    } elseif ('good' !== $failed['status']) {
        $overall = 'urgent' === $failed['status'] ? 'high' : 'medium';
    }
    return array(
        'recording'              => !empty($schema['ok']) && 0 === (int) $writes['failures'] && !$counts_failed,
        'status'                 => $overall,
        'storage_ok'             => !empty($schema['ok']) && !$counts_failed,
        'storage_error'          => (string) ($counts_failed ? $stats['error'] : $schema['error']),
        'schema_version'         => (int) $schema['schema'],
        'discarded_writes'       => (int) $writes['failures'],
        'last_write_error'       => (string) $writes['last_error'],
        'events_24h'             => (int) $stats['events_24h'],
        'logins_24h'             => (int) $stats['logins_24h'],
        'failed_logins_24h'      => (int) $stats['failed_24h'],
        'failed_login_status'    => (string) $failed['status'],
        'failed_login_threshold' => (int) $failed['threshold'],
        'active_users_7d'        => (int) $stats['active_users_7d'],
        'retention_days'         => (int) devdacti_retention_days(),
        'retention_last_run'     => (string) $ret['last_run'],
        'retention_last_error'   => (string) $ret['last_error'],
        'retention_next_run'     => (int) $ret['next_run'],
        'retention_backlog'      => (bool) $ret['backlog'],
        'protected_groups'       => devdacti_protected_groups(),
        'plugin_version'         => defined('DEVDACTI_VERSION') ? DEVDACTI_VERSION : '',
    );
}

function devdacti_ability_get_settings($input = array())
{
    $catalog = array();
    foreach (devdacti_actions_catalog() as $group => $actions) {
        $catalog[$group] = array_keys($actions);
    }
    return array(
        'settings'          => devdacti_ability_settings(),
        'roles_available'   => array_map('strval', array_keys(wp_roles()->roles)),
        'headers_available' => array_keys(devdacti_forwarded_headers()),
        'retention_range'   => array('min' => 1, 'max' => 3650),
        'actions_recorded'  => $catalog,
    );
}

function devdacti_ability_get_events($input = array())
{
    $input   = is_array($input) ? $input : array();
    $filters = array(
        'page'        => isset($input['page']) ? (int) $input['page'] : 1,
        'per_page'    => isset($input['per_page']) ? (int) $input['per_page'] : 25,
        'user'        => isset($input['user_id']) ? (int) $input['user_id'] : 0,
        'group'       => isset($input['group']) ? (string) $input['group'] : '',
        'action'      => isset($input['action']) ? (string) $input['action'] : '',
        'object_type' => isset($input['object_type']) ? (string) $input['object_type'] : '',
        'severity'    => isset($input['severity']) ? (string) $input['severity'] : '',
        'from'        => isset($input['from']) ? (string) $input['from'] : '',
        'to'          => isset($input['to']) ? (string) $input['to'] : '',
        'search'      => isset($input['search']) ? (string) $input['search'] : '',
    );
    return devdacti_ability_query($filters);
}

function devdacti_ability_get_recent_activity($input = array())
{
    $input = is_array($input) ? $input : array();
    $hours = devdacti_ability_hours($input, 24);
    if (is_wp_error($hours)) {
        return $hours;
    }
    $limit = devdacti_ability_limit($input, 50);
    if (is_wp_error($limit)) {
        return $limit;
    }
    $filters = array('per_page' => $limit, 'since_utc' => devdacti_ability_since($hours));
    if (!empty($input['group'])) {
        $filters['group'] = (string) $input['group'];
    }
    $r = devdacti_ability_query($filters);
    if (is_wp_error($r)) {
        return $r;
    }
    $by_group = array();
    foreach ($r['events'] as $row) {
        $by_group[$row['group']] = isset($by_group[$row['group']]) ? $by_group[$row['group']] + 1 : 1;
    }
    return array('hours' => $hours, 'total' => $r['total'], 'occurrences' => $r['occurrences'], 'shown' => count($r['events']), 'by_group_shown' => $by_group, 'events' => $r['events']);
}

function devdacti_ability_get_user_activity($input = array())
{
    $input   = is_array($input) ? $input : array();
    $user_id = isset($input['user_id']) ? (int) $input['user_id'] : 0;
    $login   = isset($input['login']) && is_string($input['login']) ? sanitize_user(trim($input['login'])) : '';
    if ($user_id <= 0 && '' === $login) {
        return new WP_Error('devdacti_invalid_input', __('Pass user_id or login.', 'devdome-activity-log'));
    }
    if ($user_id <= 0) {
        $u = get_user_by('login', $login);
        if (!$u) {
            // A deleted account still has rows under its old login: search is the only handle left.
            $hours = devdacti_ability_hours($input, 720);
            if (is_wp_error($hours)) {
                return $hours;
            }
            $limit = devdacti_ability_limit($input, 50);
            if (is_wp_error($limit)) {
                return $limit;
            }
            // Exact login match in SQL, so the total and the pages cover every row of that name.
            $r = devdacti_ability_query(array('per_page' => $limit, 'since_utc' => devdacti_ability_since($hours), 'login' => $login));
            if (is_wp_error($r)) {
                return $r;
            }
            return array('user_id' => 0, 'login' => $login, 'account_exists' => false, 'hours' => $hours, 'total' => $r['total'], 'shown' => count($r['events']), 'events' => $r['events']);
        }
        $user_id = (int) $u->ID;
        $login   = (string) $u->user_login;
    }
    $hours = devdacti_ability_hours($input, 720);
    if (is_wp_error($hours)) {
        return $hours;
    }
    $limit = devdacti_ability_limit($input, 50);
    if (is_wp_error($limit)) {
        return $limit;
    }
    $r = devdacti_ability_query(array('per_page' => $limit, 'since_utc' => devdacti_ability_since($hours), 'user' => $user_id));
    if (is_wp_error($r)) {
        return $r;
    }
    $u = get_userdata($user_id);
    $by_action = array();
    foreach ($r['events'] as $row) {
        $by_action[$row['action']] = isset($by_action[$row['action']]) ? $by_action[$row['action']] + 1 : 1;
    }
    return array(
        'user_id'        => $user_id,
        'login'          => $u ? (string) $u->user_login : $login,
        'account_exists' => (bool) $u,
        'roles'          => $u ? devdacti_roles_of($u) : array(),
        'hours'          => $hours,
        'total'          => $r['total'],
        'shown'          => count($r['events']),
        'by_action_shown' => $by_action,
        'events'         => $r['events'],
    );
}

function devdacti_ability_get_login_events($input = array())
{
    $input = is_array($input) ? $input : array();
    $hours = devdacti_ability_hours($input, 24);
    if (is_wp_error($hours)) {
        return $hours;
    }
    $limit = devdacti_ability_limit($input, 50);
    if (is_wp_error($limit)) {
        return $limit;
    }
    $type = isset($input['type']) ? sanitize_key((string) $input['type']) : 'all';
    $auth = array_keys(devdacti_actions_catalog()['auth']);
    if ('all' !== $type && !in_array($type, $auth, true)) {
        return new WP_Error('devdacti_invalid_input', sprintf(
            /* translators: %s: the allowed values. */
            __('type must be one of: %s.', 'devdome-activity-log'),
            'all, ' . implode(', ', $auth)
        ));
    }
    $since   = devdacti_ability_since($hours);
    $filters = array('per_page' => $limit, 'since_utc' => $since, 'group' => 'auth');
    if ('all' !== $type) {
        $filters['action'] = $type;
    }
    $r = devdacti_ability_query($filters);
    if (is_wp_error($r)) {
        return $r;
    }
    // The failed-login picture the Dashboard shows, aggregated in SQL over the WHOLE window: attempts are
    // the SUM of the bucket counters (one row can stand for 10,000 attempts), targets are the attempted
    // account names, sources the addresses. A failed aggregate is an error, never a zero.
    $failed = devdacti_failed_login_totals($since);
    if ('' !== $failed['error']) {
        return new WP_Error('devdacti_query_failed', __('The activity log could not be read. Recording may also be affected.', 'devdome-activity-log'));
    }
    $assessment = devdacti_failed_login_assessment($failed['attempts']);
    // Addresses obey the same capability as every event row: a reader without the IP power gets the
    // truncated form, and addresses that collapse into one truncated key keep their combined count.
    $can_ip  = devdacti_can('view_ip');
    $sources = array();
    foreach ($failed['sources'] as $ip => $n) {
        $k = devdacti_display_ip((string) $ip, $can_ip);
        $sources[$k] = (isset($sources[$k]) ? $sources[$k] : 0) + (int) $n;
    }
    arsort($sources);
    return array(
        'hours'                  => $hours,
        'type'                   => $type,
        'total'                  => $r['total'],
        'occurrences'            => $r['occurrences'],
        'failed_attempts'        => $failed['attempts'],
        'failed_login_status'    => (string) $assessment['status'],
        'failed_login_threshold' => (int) $assessment['threshold'],
        'targeted_accounts'      => $failed['targets'],
        'source_addresses'       => $sources,
        'events'                 => $r['events'],
    );
}

function devdacti_ability_get_plugin_changes($input = array())
{
    $input = is_array($input) ? $input : array();
    $hours = devdacti_ability_hours($input, 168);
    if (is_wp_error($hours)) {
        return $hours;
    }
    $limit = devdacti_ability_limit($input, 50);
    if (is_wp_error($limit)) {
        return $limit;
    }
    $r = devdacti_ability_query(array('per_page' => $limit, 'since_utc' => devdacti_ability_since($hours), 'group' => 'plugins'));
    if (is_wp_error($r)) {
        return $r;
    }
    $by_action = array();
    foreach ($r['events'] as $row) {
        $by_action[$row['action']] = isset($by_action[$row['action']]) ? $by_action[$row['action']] + 1 : 1;
    }
    return array('hours' => $hours, 'total' => $r['total'], 'shown' => count($r['events']), 'by_action_shown' => $by_action, 'events' => $r['events']);
}

/**
 * The review feed as findings: warning and critical rows of the window, grouped by action, plus the
 * failed-login assessment. Evidence, not diagnosis: it counts and names, it never calls anything an attack.
 */
function devdacti_ability_find_suspicious_changes($input = array())
{
    $input = is_array($input) ? $input : array();
    $hours = devdacti_ability_hours($input, 24);
    if (is_wp_error($hours)) {
        return $hours;
    }
    $since = devdacti_ability_since($hours);
    $result = devdacti_query_events(array('per_page' => 100, 'since_utc' => $since, 'severity_in' => devdacti_review_severities()));
    devdacti_log_read_access(empty($result['error'])); // recorded with the real outcome, after the read
    if (!empty($result['error'])) {
        return new WP_Error('devdacti_query_failed', __('The activity log could not be read. Recording may also be affected.', 'devdome-activity-log'));
    }
    $groups = array();
    foreach ($result['rows'] as $r) {
        $a = (string) $r['action'];
        if (!isset($groups[$a])) {
            $groups[$a] = array('action' => $a, 'action_label' => (string) $r['action_label'], 'severity' => (string) $r['severity'], 'rows' => 0, 'occurrences' => 0, 'examples' => array());
        }
        $groups[$a]['rows']++;
        $groups[$a]['occurrences'] += (int) $r['counter'];
        if (count($groups[$a]['examples']) < 5) {
            $groups[$a]['examples'][] = devdacti_ability_row($r);
        }
    }
    $findings = array();
    foreach ($groups as $g) {
        $findings[] = $g;
    }
    // Attempts over the whole window, not the reviewed sample.
    $failed = devdacti_failed_login_totals($since);
    if ('' !== $failed['error']) {
        return new WP_Error('devdacti_query_failed', __('The activity log could not be read. Recording may also be affected.', 'devdome-activity-log'));
    }
    $attempts = $failed['attempts'];
    usort($findings, function ($a, $b) {
        $rank = array('critical' => 0, 'warning' => 1, 'notice' => 2, 'info' => 3);
        $c = $rank[$a['severity']] <=> $rank[$b['severity']];
        return 0 !== $c ? $c : ($b['occurrences'] <=> $a['occurrences']);
    });
    $assessment = devdacti_failed_login_assessment($attempts);
    return array(
        'hours'                  => $hours,
        'clean'                  => empty($findings),
        'reviewed_rows'          => count($result['rows']),
        'rows_truncated'         => (int) $result['total'] > count($result['rows']),
        'failed_attempts'        => $attempts,
        'failed_login_status'    => (string) $assessment['status'],
        'failed_login_threshold' => (int) $assessment['threshold'],
        'findings'               => $findings,
        'note'                   => __('Counts, not verdicts: a handful of failed logins is usually a mistyped password. Review the examples and the full rows with get-events before acting.', 'devdome-activity-log'),
    );
}

/* ------------------------------ handlers: writes ------------------------------ */

function devdacti_ability_update_settings($input = array())
{
    $input = is_array($input) ? $input : array();
    $after   = devdacti_policy_snapshot();
    $touched = 0;

    if (array_key_exists('retention_days', $input)) {
        $d = $input['retention_days'];
        if (!is_int($d) && !(is_string($d) && ctype_digit($d))) {
            return new WP_Error('devdacti_invalid_input', __('retention_days must be a whole number of days between 1 and 3650.', 'devdome-activity-log'));
        }
        if (!devdacti_valid_retention((int) $d)) {
            return new WP_Error('devdacti_invalid_input', __('retention_days must be between 1 and 3650.', 'devdome-activity-log'));
        }
        $after['retention_days'] = (int) $d;
        $touched++;
    }
    foreach (array('anonymize_ip', 'protect_critical') as $k) {
        if (array_key_exists($k, $input)) {
            $b = devdacti_ability_bool($input[$k]);
            if (null === $b) {
                /* translators: %s: a setting key. */
                return new WP_Error('devdacti_invalid_input', sprintf(__('%s must be true or false.', 'devdome-activity-log'), $k));
            }
            $after[$k] = $b ? 1 : 0;
            $touched++;
        }
    }
    if (array_key_exists('groups', $input)) {
        $g = $input['groups'];
        if (!is_array($g)) {
            return new WP_Error('devdacti_invalid_input', __('groups must be an object of group => true/false.', 'devdome-activity-log'));
        }
        $known = array_diff(array_keys(devdacti_group_labels()), array('audit'));
        foreach ($g as $name => $on) {
            $name = sanitize_key((string) $name);
            if (!in_array($name, $known, true)) {
                /* translators: 1: the unknown group, 2: the known groups. */
                return new WP_Error('devdacti_invalid_input', sprintf(__('Unknown group "%1$s". Groups: %2$s.', 'devdome-activity-log'), $name, implode(', ', $known)));
            }
            $b = devdacti_ability_bool($on);
            if (null === $b) {
                /* translators: %s: a group key. */
                return new WP_Error('devdacti_invalid_input', sprintf(__('groups.%s must be true or false.', 'devdome-activity-log'), $name));
            }
            $after['group_' . $name] = $b ? 1 : 0;
            $touched++;
        }
        // While Always Record Security Events stays on, a protected group cannot be stored as off (the screen posts
        // it locked). Switching protection off in the same call is the explicit, confirmed way to get there.
        if (!empty($after['protect_critical'])) {
            foreach (devdacti_protected_groups() as $pg) {
                if (isset($after['group_' . $pg]) && 0 === (int) $after['group_' . $pg]) {
                    /* translators: %s: a group key. */
                    return new WP_Error('devdacti_invalid_input', sprintf(__('groups.%s is protected while protect_critical is true: set protect_critical to false in the same call (confirm: true) to switch it off.', 'devdome-activity-log'), $pg));
                }
            }
        }
    }
    if (array_key_exists('excluded_roles', $input)) {
        $list = $input['excluded_roles'];
        if (!is_array($list) || count(array_filter($list, 'is_string')) !== count($list)) {
            return new WP_Error('devdacti_invalid_input', __('excluded_roles must be a list of role slugs.', 'devdome-activity-log'));
        }
        $allowed = array_map('strval', array_keys(wp_roles()->roles));
        $roles   = array_values(array_unique(array_map('sanitize_key', $list)));
        $refused = array_diff($roles, $allowed);
        if ($refused) {
            /* translators: 1: the refused role slugs, 2: the roles registered on this site. */
            return new WP_Error('devdacti_invalid_input', sprintf(__('excluded_roles refused: %1$s. Roles on this site: %2$s.', 'devdome-activity-log'), implode(', ', $refused), implode(', ', $allowed)));
        }
        $after['excluded_roles'] = $roles;
        $touched++;
    }
    if (array_key_exists('excluded_users', $input)) {
        $list = $input['excluded_users'];
        if (!is_array($list) || count(array_filter($list, 'is_string')) !== count($list)) {
            return new WP_Error('devdacti_invalid_input', __('excluded_users must be a list of usernames.', 'devdome-activity-log'));
        }
        $users = array();
        foreach ($list as $login) {
            $login = sanitize_user(trim($login));
            if ('' !== $login) {
                $users[] = $login;
            }
        }
        $after['excluded_users'] = array_values(array_unique($users));
        $touched++;
    }
    if (array_key_exists('trusted_proxies', $input)) {
        $list = $input['trusted_proxies'];
        if (!is_array($list) || count(array_filter($list, 'is_string')) !== count($list)) {
            return new WP_Error('devdacti_invalid_input', __('trusted_proxies must be a list of IP addresses or CIDR ranges.', 'devdome-activity-log'));
        }
        $raw     = implode("\n", array_map('trim', $list));
        $invalid = devdacti_invalid_proxies($raw);
        if ($invalid) {
            /* translators: %s: the entries that are not valid addresses or CIDR ranges. */
            return new WP_Error('devdacti_invalid_input', sprintf(__('trusted_proxies refused: %s (not an IP address or CIDR range).', 'devdome-activity-log'), implode(', ', $invalid)));
        }
        $after['trusted_proxies'] = sanitize_textarea_field($raw);
        $touched++;
    }
    if (array_key_exists('forwarded_header', $input)) {
        $h = is_string($input['forwarded_header']) ? sanitize_text_field($input['forwarded_header']) : null;
        if ('' !== $h && (null === $h || !isset(devdacti_forwarded_headers()[$h]))) {
            /* translators: %s: the header names the plugin can read. */
            return new WP_Error('devdacti_invalid_input', sprintf(__('forwarded_header must be empty or one of: %s.', 'devdome-activity-log'), implode(', ', array_keys(devdacti_forwarded_headers()))));
        }
        $after['forwarded_header'] = $h;
        $touched++;
    }
    if (0 === $touched) {
        return new WP_Error('devdacti_invalid_input', __('Pass at least one setting to change.', 'devdome-activity-log'));
    }
    // A forwarded header without a trusted range is never believed; say so instead of letting the agent think it works.
    if ('' !== $after['forwarded_header'] && '' === trim((string) $after['trusted_proxies'])) {
        return new WP_Error('devdacti_invalid_input', __('forwarded_header needs at least one trusted_proxies range; without one the header is never read.', 'devdome-activity-log'));
    }

    $confirmed = isset($input['confirm']) && true === $input['confirm'];
    $r = devdacti_apply_policy($after, $confirmed);
    if (!empty($r['needs_confirm'])) {
        return new WP_Error('devdacti_confirm_required', __('This change reduces what gets recorded:', 'devdome-activity-log') . ' ' . implode(' ', $r['weakenings']) . ' ' . __('Pass confirm: true after the site owner agreed. Nothing was changed.', 'devdome-activity-log'), array('weakenings' => $r['weakenings']));
    }
    $now = devdacti_ability_settings();
    if (!$r['ok']) {
        return new WP_Error('devdacti_save_failed', sprintf(
            /* translators: %d: number of settings that could not be stored. */
            __('%d settings could not be stored or verified. The policy in force is the one returned here.', 'devdome-activity-log'),
            (int) $r['failed']
        ), array('settings' => $now));
    }
    return array('updated' => true, 'changes' => (int) $r['changes'], 'weakenings_confirmed' => $r['weakenings'], 'settings' => $now);
}

function devdacti_ability_run_purge($input = array())
{
    $backlog = devdacti_retention_backlog();
    $ok = devdacti_ability_confirmed($input, sprintf(
        /* translators: 1: number of events older than the window, 2: retention window in days. */
        __('This permanently deletes the %1$s events older than %2$d days. It is recorded in the log and cannot be undone without a database backup.', 'devdome-activity-log'),
        $backlog < 0 ? __('unknown number of', 'devdome-activity-log') : (string) $backlog,
        (int) devdacti_retention_days()
    ));
    if (is_wp_error($ok)) {
        return $ok;
    }
    $r = devdacti_purge_cron();
    if ('' !== (string) $r['error']) {
        return new WP_Error('devdacti_purge_failed', sprintf(
            /* translators: 1: number of events deleted before the failure, 2: the database error. */
            __('%1$d events were deleted, then the purge failed: %2$s', 'devdome-activity-log'),
            (int) $r['deleted'],
            (string) $r['error']
        ), array('deleted' => (int) $r['deleted']));
    }
    return array('deleted' => (int) $r['deleted'], 'backlog_remaining' => (bool) $r['backlog'], 'retention_days' => (int) devdacti_retention_days(), 'status' => devdacti_retention_status());
}

function devdacti_ability_repair_storage($input = array())
{
    $ok = devdacti_ability_confirmed($input, __('A repair re-applies the storage schema with dbDelta: it adds missing columns and indexes and may alter a column definition to match the schema. It deletes no stored event, but a column change or a new index on a large table rewrites or locks the table for a while: take a database backup first.', 'devdome-activity-log'));
    if (is_wp_error($ok)) {
        return $ok;
    }
    $r = devdacti_repair_schema();
    return array(
        'repaired'   => (bool) $r['repaired'],
        'healthy'    => !empty($r['after']['ok']),
        'before'     => array('ok' => !empty($r['before']['ok']), 'error' => (string) $r['before']['error'], 'missing_columns' => (array) $r['before']['missing'], 'missing_indexes' => (array) $r['before']['missing_key']),
        'after'      => array('ok' => !empty($r['after']['ok']), 'error' => (string) $r['after']['error'], 'missing_columns' => (array) $r['after']['missing'], 'missing_indexes' => (array) $r['after']['missing_key']),
    );
}

/* ------------------------------ registration ------------------------------ */

add_action('wp_abilities_api_init', 'devdacti_register_abilities');
function devdacti_register_abilities()
{
    if (!function_exists('wp_register_ability')) {
        return;
    }
    $empty = array('type' => 'object', 'properties' => array(), 'additionalProperties' => false);
    $bool  = function ($desc = '') { return array('type' => 'boolean', 'description' => $desc); };
    $int   = function ($desc = '') { return array('type' => 'integer', 'description' => $desc); };
    $str   = function ($desc = '') { return array('type' => 'string', 'description' => $desc); };
    $strs  = function ($desc = '') { return array('type' => 'array', 'items' => array('type' => 'string'), 'description' => $desc); };
    $hours = function ($default) { return array('type' => 'integer', 'minimum' => 1, 'maximum' => 8784, 'default' => $default, 'description' => 'Look back this many hours (1 to 8784 = 366 days).'); };
    $limit = array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50, 'description' => 'Rows to return (1 to 100), newest first.');
    $groups = array_diff(array_keys(devdacti_group_labels()), array('audit'));

    $row = array('type' => 'object', 'properties' => array(
        'id' => $int(), 'created_at' => $str('UTC, Y-m-d H:i:s.'), 'first_seen' => $str(), 'last_seen' => $str(),
        'severity' => array('type' => 'string', 'enum' => devdacti_severities()), 'outcome' => array('type' => 'string', 'enum' => array('success', 'failure')),
        'action' => $str(), 'action_label' => $str(), 'group' => $str(),
        'user_id' => $int('0 = nobody authenticated (a failed login names the attempted account in object_title, not here).'), 'user_login' => $str(), 'user_role' => $str(),
        'actor_type' => array('type' => 'string', 'enum' => array('user', 'system', 'anonymous', '')), 'channel' => $str('web, ajax, rest, xmlrpc, cron, cli or app_password.'), 'request_id' => $str('Rows sharing one request_id came from one request.'),
        'ip' => $str('As stored: truncated unless IP truncation is off; masked for callers without the IP capability.'),
        'object_type' => $str(), 'object_id' => $int(), 'object_title' => $str(), 'details' => $str('Redacted sentence.'), 'occurrences' => $int('> 1 when repeated attempts were counted into this one row.'),
    ));
    $page_out = array('type' => 'object', 'properties' => array('total' => $int('Stored rows matching.'), 'occurrences' => $int('Underlying occurrences (bucket counters summed).'), 'page' => $int(), 'per_page' => $int(), 'pages' => $int(), 'events' => array('type' => 'array', 'items' => $row)));
    $window_out = function ($extra) use ($int, $row) {
        return array('type' => 'object', 'properties' => array_merge(array('hours' => $int(), 'total' => $int(), 'events' => array('type' => 'array', 'items' => $row)), $extra));
    };
    $settings_out = array('type' => 'object', 'properties' => array(
        'retention_days' => $int(), 'anonymize_ip' => $bool(), 'protect_critical' => $bool(), 'trusted_proxies' => $strs(), 'forwarded_header' => $str(),
        'groups' => array('type' => 'object', 'description' => 'group => recording on/off (protected groups read true whatever is stored).'),
        'excluded_roles' => $strs(), 'excluded_users' => $strs(), 'protected_groups' => $strs(),
    ));
    $status_out = array('type' => 'object', 'properties' => array(
        'recording' => $bool('true when the table is healthy and no write was lost.'), 'status' => array('type' => 'string', 'enum' => array('good', 'medium', 'high', 'critical')),
        'storage_ok' => $bool(), 'storage_error' => $str(), 'schema_version' => $int(), 'discarded_writes' => $int(), 'last_write_error' => $str(),
        'events_24h' => $int(), 'logins_24h' => $int(), 'failed_logins_24h' => $int(), 'failed_login_status' => array('type' => 'string', 'enum' => array('good', 'warn', 'urgent')), 'failed_login_threshold' => $int(),
        'active_users_7d' => $int(), 'retention_days' => $int(), 'retention_last_run' => $str(), 'retention_last_error' => $str(), 'retention_next_run' => $int(), 'retention_backlog' => $bool(),
        'protected_groups' => $strs(), 'plugin_version' => $str(),
    ));

    // Every answer passes the guard (DESIGN.md 24: a failed query = a database error, never "done") and the sanitizer.
    $guarded = function ($cb) {
        return function ($input = array()) use ($cb) {
            devdacti_db_guard_begin();
            try {
                $r = call_user_func($cb, $input);
                if (devdacti_db_guard_active()) {
                    $r = new WP_Error('devdacti_db_error', devdacti_db_guard_message());
                }
            } finally {
                devdacti_db_guard_end();
            }
            return devdacti_ability_strip($r);
        };
    };
    $reg = function ($id, $label, $desc, $in, $out, $cb, $kind, $perm, $idempotent = null) use ($guarded) {
        wp_register_ability($id, array(
            'label'               => $label,
            'description'         => $desc,
            'category'            => 'devdome-activity-log',
            'input_schema'        => $in,
            'output_schema'       => $out,
            'execute_callback'    => $guarded($cb),
            'permission_callback' => $perm,
            'meta'                => devdacti_ability_meta($kind, $idempotent),
        ));
    };

    $reg('devdome-activity-log/get-status', __('Get Activity Log status', 'devdome-activity-log'),
        __('The Dashboard numbers and the health of the log: whether events are being recorded (table healthy, no lost writes), events and logins in 24 hours, failed login attempts in 24 hours against this site\'s threshold, active users in 7 days, retention window and last purge. Read-only. Call this first.', 'devdome-activity-log'),
        $empty, $status_out, 'devdacti_ability_get_status', 'read', 'devdacti_ability_can_read');
    $reg('devdome-activity-log/get-settings', __('Get Activity Log settings', 'devdome-activity-log'),
        __('The audit policy: retention days, IP truncation, protected groups, trusted proxies and forwarded header, which event groups record, excluded roles and users, plus the roles and header names this site offers and the complete list of actions the plugin records per group. Read-only.', 'devdome-activity-log'),
        $empty, array('type' => 'object', 'properties' => array('settings' => $settings_out, 'roles_available' => $strs(), 'headers_available' => $strs(), 'retention_range' => array('type' => 'object'), 'actions_recorded' => array('type' => 'object', 'description' => 'group => list of action keys.'))),
        'devdacti_ability_get_settings', 'read', 'devdacti_ability_can_read');
    $reg('devdome-activity-log/get-events', __('Search the activity log', 'devdome-activity-log'),
        __('The Activity Log tab with every filter: user, activity group, one action, object type, severity, a site-timezone date range (at most 366 days) and a search over subject, details and username (2 to 100 characters). Newest first, paged (at most 100 rows per page, 1000 pages). An impossible filter is refused with a message rather than answered with an empty page. Reading is recorded. Read-only.', 'devdome-activity-log'),
        array('type' => 'object', 'properties' => array(
            'page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 1), 'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25),
            'user_id' => $int('Only events by this user ID.'), 'group' => array('type' => 'string', 'enum' => array_keys(devdacti_group_labels()), 'description' => 'One event group.'),
            'action' => $str('One action key (see get-settings actions_recorded); wins over group.'), 'object_type' => array('type' => 'string', 'enum' => devdacti_object_types()),
            'severity' => array('type' => 'string', 'enum' => devdacti_severities()), 'from' => $str('YYYY-MM-DD, site timezone, inclusive.'), 'to' => $str('YYYY-MM-DD, site timezone, inclusive.'),
            'search' => $str('Case-insensitive match on subject, details and username; 2 to 100 characters.'),
        ), 'additionalProperties' => false),
        $page_out, 'devdacti_ability_get_events', 'read', 'devdacti_ability_can_read');
    $reg('devdome-activity-log/get-recent-activity', __('Get recent activity', 'devdome-activity-log'),
        __('What happened on this site in the last N hours (default 24): the newest rows across every group, or one group, with a count per group. Read-only.', 'devdome-activity-log'),
        array('type' => 'object', 'properties' => array('hours' => $hours(24), 'limit' => $limit, 'group' => array('type' => 'string', 'enum' => array_keys(devdacti_group_labels()))), 'additionalProperties' => false),
        $window_out(array('occurrences' => $int(), 'shown' => $int(), 'by_group_shown' => array('type' => 'object'))), 'devdacti_ability_get_recent_activity', 'read', 'devdacti_ability_can_read');
    $reg('devdome-activity-log/get-user-activity', __('Get one user\'s activity', 'devdome-activity-log'),
        __('Everything one account did in the last N hours (default 720 = 30 days), by user ID or username, with a count per action and the account\'s current roles. A deleted account is found by its old username. Read-only.', 'devdome-activity-log'),
        array('type' => 'object', 'properties' => array('user_id' => $int(), 'login' => $str('Username, when the ID is not known.'), 'hours' => $hours(720), 'limit' => $limit), 'additionalProperties' => false),
        $window_out(array('user_id' => $int(), 'login' => $str(), 'account_exists' => $bool(), 'roles' => $strs(), 'shown' => $int(), 'by_action_shown' => array('type' => 'object'))), 'devdacti_ability_get_user_activity', 'read', 'devdacti_ability_can_read');
    $reg('devdome-activity-log/get-login-events', __('Get login events', 'devdome-activity-log'),
        __('Logins, logouts, failed logins and password resets of the last N hours (default 24), plus the failed-login picture the Dashboard shows: total attempts (repeated attempts are counted into one row each), the status against this site\'s threshold, the ten most targeted accounts and the ten most frequent source addresses. Counts, not verdicts. Read-only.', 'devdome-activity-log'),
        array('type' => 'object', 'properties' => array('hours' => $hours(24), 'limit' => $limit, 'type' => array('type' => 'string', 'enum' => array_merge(array('all'), array_keys(devdacti_actions_catalog()['auth'])), 'default' => 'all')), 'additionalProperties' => false),
        $window_out(array('type' => $str(), 'occurrences' => $int(), 'failed_attempts' => $int(), 'failed_login_status' => $str(), 'failed_login_threshold' => $int(), 'targeted_accounts' => array('type' => 'object'), 'source_addresses' => array('type' => 'object'))),
        'devdacti_ability_get_login_events', 'read', 'devdacti_ability_can_read');
    $reg('devdome-activity-log/get-plugin-changes', __('Get plugin, theme and core changes', 'devdome-activity-log'),
        __('Plugin installs, updates, activations, deactivations and deletions, theme installs, updates, deletions and switches, and core updates of the last N hours (default 168 = 7 days), with a count per action. Read-only.', 'devdome-activity-log'),
        array('type' => 'object', 'properties' => array('hours' => $hours(168), 'limit' => $limit), 'additionalProperties' => false),
        $window_out(array('shown' => $int(), 'by_action_shown' => array('type' => 'object'))), 'devdacti_ability_get_plugin_changes', 'read', 'devdacti_ability_can_read');
    $reg('devdome-activity-log/find-suspicious-changes', __('Find events worth reviewing', 'devdome-activity-log'),
        __('The Dashboard\'s review feed for the last N hours (default 24) as findings: every warning or critical event (role changes, user deletions, email changes, policy changes, purges, failed logins, new accounts, plugin and theme lifecycle, option changes, exports) grouped by action with counts and up to five example rows, worst first, plus the failed-login assessment. Evidence, not diagnosis. Read-only.', 'devdome-activity-log'),
        array('type' => 'object', 'properties' => array('hours' => $hours(24)), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('hours' => $int(), 'clean' => $bool(), 'reviewed_rows' => $int(), 'rows_truncated' => $bool('true when more than 100 rows matched; narrow the window.'), 'failed_attempts' => $int(), 'failed_login_status' => $str(), 'failed_login_threshold' => $int(),
            'findings' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array('action' => $str(), 'action_label' => $str(), 'severity' => $str(), 'rows' => $int(), 'occurrences' => $int(), 'examples' => array('type' => 'array', 'items' => $row)))), 'note' => $str())),
        'devdacti_ability_find_suspicious_changes', 'read', 'devdacti_ability_can_read');
    $reg('devdome-activity-log/update-settings', __('Update Activity Log settings', 'devdome-activity-log'),
        __('Change the audit policy; only the keys you pass change, through the same save the Settings tab uses. The changed values are written in one statement and each is then recorded with the previous and new value; a write that failed is recorded as a failure with the attempted values. A change that reduces coverage (shorter retention, a group switched off, an actor excluded, protected groups unlocked) is refused with the list until confirm: true is passed. Needs the manage capability.', 'devdome-activity-log'),
        array('type' => 'object', 'properties' => array(
            'retention_days' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 3650), 'anonymize_ip' => $bool('Truncate IP addresses before storing them.'), 'protect_critical' => $bool('Authentication, user and plugin events cannot be switched off or excluded away.'),
            'groups' => array('type' => 'object', 'description' => 'group => true/false for: ' . implode(', ', $groups) . '.'),
            'excluded_roles' => $strs('Role slugs never logged (replaces the list).'), 'excluded_users' => $strs('Usernames never logged (replaces the list).'),
            'trusted_proxies' => $strs('IP addresses or CIDR ranges whose forwarded header is believed (replaces the list).'), 'forwarded_header' => array('type' => 'string', 'enum' => array_merge(array(''), array_keys(devdacti_forwarded_headers())), 'description' => 'Empty = read no forwarded header.'),
            'confirm' => $bool('Required (true) only when the change reduces audit coverage.'),
        ), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('updated' => $bool(), 'changes' => $int(), 'weakenings_confirmed' => $strs(), 'settings' => $settings_out)),
        'devdacti_ability_update_settings', 'modify', 'devdacti_ability_can_manage');
    $reg('devdome-activity-log/run-purge', __('Delete events older than the retention window now', 'devdome-activity-log'),
        __('Runs the retention purge immediately: deletes every event older than the retention window in bounded chunks, recorded in the log. Destructive; requires confirm: true. Needs the purge capability.', 'devdome-activity-log'),
        array('type' => 'object', 'properties' => array('confirm' => $bool('Must be true: the deleted events are gone for good.')), 'required' => array('confirm'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('deleted' => $int(), 'backlog_remaining' => $bool('true when a continuation was scheduled for the rest.'), 'retention_days' => $int(), 'status' => array('type' => 'object'))),
        'devdacti_ability_run_purge', 'destroy', 'devdacti_ability_can_purge', false);
    $reg('devdome-activity-log/repair-storage', __('Repair the events table', 'devdome-activity-log'),
        __('Re-applies the storage schema with dbDelta when get-status reports the table unhealthy: missing columns and indexes are added and a column definition may be altered to match the schema; no stored event is deleted. A column change or a new index on a large table rewrites or locks it, so the site owner needs a database backup first; requires confirm: true. Recorded in the log. Needs the repair capability.', 'devdome-activity-log'),
        array('type' => 'object', 'properties' => array('confirm' => $bool('Must be true: the site owner has a database backup and accepts a possible table lock.')), 'required' => array('confirm'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('repaired' => $bool(), 'healthy' => $bool(), 'before' => array('type' => 'object'), 'after' => array('type' => 'object'))),
        'devdacti_ability_repair_storage', 'modify', 'devdacti_ability_can_repair', false);
}

/**
 * MCP Adapter: list every Activity Log ability as a tool on the default server (next to its discover / execute
 * meta-tools). Harmless when the adapter is not installed.
 */
add_filter('mcp_adapter_default_server_config', 'devdacti_mcp_default_server_tools');
function devdacti_mcp_default_server_tools($config)
{
    if (!is_array($config)) {
        return $config;
    }
    $tools = isset($config['tools']) && is_array($config['tools']) ? $config['tools'] : array();
    $config['tools'] = array_values(array_unique(array_merge($tools, devdacti_ability_ids())));
    return $config;
}
