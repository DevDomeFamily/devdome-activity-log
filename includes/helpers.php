<?php
/**
 * Shared utilities: the single event writer (devdacti_log — the ONLY insert path in
 * the plugin), IP capture + anonymization, the canonical action catalog with group
 * mapping, exclusion gates, and the CSV formula-injection guard.
 */

defined('ABSPATH') || exit;

/** Events table name. */
function devdacti_events_table()
{
    global $wpdb;
    return $wpdb->prefix . 'devdacti_events';
}

/**
 * Unicode-safe substring. PHP does not guarantee the mbstring extension, and this plugin
 * declares only "Requires PHP: 7.4" — an unconditional mb_substr() is a fatal error on a
 * host without it. The fallback is character-exact (PCRE's /u splitter), not a byte cut.
 */
function devdacti_substr($value, $start, $length)
{
    if (function_exists('mb_substr')) {
        // mb_substr carries an invalid trailing byte through as though it were a character,
        // so its result needs the same shed as the fallback's byte cut.
        return devdacti_utf8_shed(mb_substr((string) $value, $start, $length, 'UTF-8'));
    }
    return devdacti_substr_fallback($value, $start, $length);
}

/** The mbstring-absent branch of devdacti_substr(), tested directly. */
function devdacti_substr_fallback($value, $start, $length)
{
    $value = (string) $value;
    if ($value === '') {
        return '';
    }
    $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($chars)) {
        return implode('', array_slice($chars, $start, $length));
    }
    // Not valid UTF-8: byte-cut, then shed the trailing partial sequence.
    return devdacti_utf8_shed(substr($value, $start, $length));
}

/**
 * Drop a trailing partial sequence so a truncated value can never be stored as a broken
 * code point. At most 3 continuation bytes to shed.
 */
function devdacti_utf8_shed($out)
{
    $out = (string) $out;
    for ($i = 0; $i < 4 && $out !== '' && 1 !== preg_match('//u', $out); $i++) {
        $out = (string) substr($out, 0, -1);
    }
    return $out;
}

/**
 * Write one event row. The only non-aggregated INSERT path in the plugin.
 *
 * @param array $args {
 *   @type string $action       REQUIRED. Canonical snake_case action key (see catalog).
 *   @type string $object_type  The SUBJECT type: post|attachment|user|plugin|theme|core|option|comment|session|policy.
 *   @type int    $object_id    Subject id. Default 0.
 *   @type string $object_title Subject label (post title, plugin name, username, option name). Truncated to 191.
 *   @type string $details      Free-text details string. Redacted, then truncated to 2000 chars.
 *   @type int    $user_id      ACTOR id. Default the current user.
 *   @type string $user_login   Actor login ('' for anonymous).
 *   @type string $user_role    Actor primary role ('' anonymous, 'system' for cron/CLI actor).
 *   @type array  $user_roles   All actor roles, for the exclusion gate. Default derived.
 *   @type string $outcome      success|failure. Default 'success'.
 *   @type string $severity     Default derived from the action catalog.
 *   @type string $ip           Default devdacti_ip() (already anonymized when the setting is ON).
 *   @type int    $counter      Default 1. Only the failed-login aggregator passes >1 semantics.
 * }
 * @return array Typed result: ok (bool), id (int), skipped (string reason or ''), error (string).
 *               A write failure is NEVER silent: it is counted in the operational status store.
 */
function devdacti_log(array $args)
{
    global $wpdb;

    // Recursion guard — never log our own log writes. Inserts go straight to the custom
    // table via $wpdb->insert so no WP hooks re-fire, but the guard makes it structurally
    // impossible anyway. try/finally: an exception (or an early return added later) must
    // never leave the writer permanently busy for the rest of the request.
    static $busy = false;
    if ($busy) {
        return devdacti_write_result(false, 0, 'busy');
    }
    $busy = true;

    try {
        $action = isset($args['action']) ? sanitize_key($args['action']) : '';
        if ($action === '') {
            return devdacti_write_result(false, 0, 'no_action');
        }

        // Protected events (audit policy always; authentication, users and plugin lifecycle
        // while protected mode is on) ignore BOTH suppression gates. A policy that could
        // hide changes to itself would make the whole log unfalsifiable.
        $protected = devdacti_action_protected($action);

        // Group gate — a disabled event group writes nothing.
        if (!$protected && !devdacti_group_enabled(devdacti_group_for_action($action))) {
            return devdacti_write_result(false, 0, 'group_disabled');
        }

        $actor      = devdacti_resolve_actor($args);
        $user_id    = $actor['user_id'];
        $user_login = $actor['user_login'];
        $user_role  = $actor['user_role'];

        // Exclusion gate — excluded users/roles are never logged.
        if (!$protected && devdacti_user_excluded($user_id, $user_login, $actor['user_roles'])) {
            return devdacti_write_result(false, 0, 'excluded');
        }

        $ip   = array_key_exists('ip', $args) ? (string) $args['ip'] : devdacti_ip();
        $peer = array_key_exists('peer_ip', $args) ? (string) $args['peer_ip'] : devdacti_peer_ip_display();

        $object_type  = isset($args['object_type']) ? sanitize_key($args['object_type']) : '';
        $object_id    = isset($args['object_id']) ? (int) $args['object_id'] : 0;
        $object_title = devdacti_redact(isset($args['object_title']) ? (string) $args['object_title'] : '');
        $details      = devdacti_redact(isset($args['details']) ? (string) $args['details'] : '');
        $counter      = isset($args['counter']) ? max(1, (int) $args['counter']) : 1;
        $outcome      = (isset($args['outcome']) && 'failure' === $args['outcome']) ? 'failure' : 'success';
        if (function_exists('devdacti_db_guard_active') && devdacti_db_guard_active() && devdacti_db_guard_failed()) {
            // DESIGN.md 24: a query already failed inside this action, so the action ends with the database banner.
            // The caller's outcome is its own verified result (a stored setting is stored); the row carries the
            // context so nobody reads it as a clean run, and the failed write itself is on the ledger.
            $details = trim(__('(a database query failed earlier in this action; the action ended with a database error)', 'devdome-activity-log') . ' ' . $details);
        }
        $severity     = isset($args['severity']) ? sanitize_key($args['severity']) : devdacti_action_severity($action);
        $now          = gmdate('Y-m-d H:i:s');

        devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write-only audit insert to internal prefix table; values bound by $wpdb->insert.
        $ok = $wpdb->insert(
            devdacti_events_table(),
            array(
                'created_at'   => $now,
                'first_seen'   => $now,
                'last_seen'    => $now,
                'user_id'      => $user_id,
                'user_login'   => devdacti_substr(devdacti_redact($user_login), 0, 60),
                'user_role'    => devdacti_substr(devdacti_redact($user_role), 0, 60),
                'ip'           => devdacti_substr($ip, 0, 45),
                'peer_ip'      => devdacti_substr($peer, 0, 45),
                'object_type'  => devdacti_substr($object_type, 0, 32),
                'object_id'    => $object_id,
                'object_title' => devdacti_substr($object_title, 0, 191),
                'action'       => $action,
                'details'      => devdacti_substr($details, 0, 2000),
                'counter'      => $counter,
                'actor_type'   => devdacti_actor_type($user_id),
                'channel'      => devdacti_request_channel(),
                'request_id'   => devdacti_request_id(),
                'outcome'      => $outcome,
                'severity'     => $severity,
                'site_id'      => devdacti_site_id(),
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d')
        );

        if ($ok === false) {
            // NEVER silently discarded: the audit-log user must be able to see that events
            // were lost. Recorded outside the (possibly broken) events table. The error is
            // captured first: recording it is itself an option write that may clear it.
            $db_error = (string) $wpdb->last_error;
            devdacti_record_write_failure($action, $db_error);
            return devdacti_write_result(false, 0, '', $db_error);
        }

        devdacti_invalidate_stats();
        return devdacti_write_result(true, (int) $wpdb->insert_id);
    } finally {
        $busy = false;
    }
}

/**
 * Resolve the actor for an event: supplied explicitly, or the current user
 * (the auth tracker supplies it itself; wp_login fires before the current user is set).
 */
function devdacti_resolve_actor(array $args)
{
    if (array_key_exists('user_id', $args)) {
        $role  = isset($args['user_role']) ? (string) $args['user_role'] : '';
        $roles = isset($args['user_roles']) && is_array($args['user_roles'])
            ? array_values(array_map('strval', $args['user_roles']))
            : ('' !== $role ? array($role) : array());
        return array(
            'user_id'    => (int) $args['user_id'],
            'user_login' => isset($args['user_login']) ? (string) $args['user_login'] : '',
            'user_role'  => $role,
            'user_roles' => $roles,
        );
    }
    $user       = wp_get_current_user();
    $user_id    = $user instanceof WP_User ? (int) $user->ID : 0;
    $user_login = ($user_id > 0) ? (string) $user->user_login : '';
    // ALL roles, not just the first: 0.1.2 compared one role against the exclusion list, so a
    // user with roles [subscriber, editor] was logged even when editor was excluded.
    $roles      = ($user_id > 0) ? devdacti_roles_of($user) : array();
    $user_role  = $roles ? $roles[0] : '';
    if ($user_id === 0 && ((defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI))) {
        $user_role = 'system';
        $roles     = array('system');
    }
    return array('user_id' => $user_id, 'user_login' => $user_login, 'user_role' => $user_role, 'user_roles' => $roles);
}

/**
 * ONE atomic bucket upsert. The whole point of this function is that it is a SINGLE
 * statement: the counter is incremented by the database, never read into PHP first, and
 * the UNIQUE bucket_key makes a duplicate row impossible. Returns the affected-row count
 * (1 = a new bucket row was created, 2 = an existing one was incremented) or false.
 */
function devdacti_bucket_upsert(array $row)
{
    global $wpdb;
    $table = devdacti_events_table();

    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; every value bound via prepare. Single-statement upsert: this is the atomicity guarantee, it must not be split.
    return $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table}
         (created_at, first_seen, last_seen, user_id, user_login, user_role, ip, peer_ip, object_type, object_id, object_title, action, details, counter, bucket_key, bucket_scope, actor_type, channel, request_id, outcome, severity, site_id)
         VALUES (%s, %s, %s, %d, %s, %s, %s, %s, %s, %d, %s, %s, %s, 1, %s, %s, %s, %s, %s, %s, %s, %d)
         ON DUPLICATE KEY UPDATE counter = counter + 1, last_seen = VALUES(last_seen)",
        $row['now'],
        $row['now'],
        $row['now'],
        (int) $row['user_id'],
        devdacti_substr(devdacti_redact($row['user_login']), 0, 60),
        devdacti_substr(devdacti_redact($row['user_role']), 0, 60),
        devdacti_substr($row['ip'], 0, 45),
        devdacti_substr($row['peer_ip'], 0, 45),
        devdacti_substr($row['object_type'], 0, 32),
        (int) $row['object_id'],
        devdacti_substr($row['object_title'], 0, 191),
        $row['action'],
        devdacti_substr($row['details'], 0, 2000),
        $row['bucket_key'],
        $row['bucket_scope'],
        $row['actor_type'],
        $row['channel'],
        $row['request_id'],
        $row['outcome'],
        $row['severity'],
        (int) $row['site_id']
    ));
}

/**
 * Atomic aggregated write for repeatable, largely unauthenticated events (failed logins,
 * password-reset requests). Replaces the 0.1.2 SELECT -> counter+1 -> UPDATE sequence,
 * which under concurrency both duplicated bucket rows (every worker missed the SELECT)
 * and lost increments (every worker wrote the same counter+1).
 *
 * @param array $args {
 *   @type string $action           REQUIRED canonical action key.
 *   @type string $subject          REQUIRED normalized subject (attempted login, target user).
 *   @type string $ip_exact         Exact validated client IP; '' when unknown.
 *   @type string $ip_display       Stored (possibly anonymized) IP.
 *   @type int    $max_rows         Distinct bucket rows allowed per source per hour. 0 = uncapped.
 *   @type string $overflow_subject Subject the capped overflow bucket uses.
 * }
 * @return array Typed result plus created (bool) and overflowed (bool).
 */
function devdacti_log_aggregated(array $args)
{
    global $wpdb;

    $result = devdacti_write_result(false, 0, 'no_action');
    $result['created']    = false;
    $result['overflowed'] = false;

    $action = isset($args['action']) ? sanitize_key($args['action']) : '';
    if ($action === '') {
        return $result;
    }
    $protected = devdacti_action_protected($action);
    if (!$protected && !devdacti_group_enabled(devdacti_group_for_action($action))) {
        $result['skipped'] = 'group_disabled';
        return $result;
    }

    $actor = devdacti_resolve_actor($args);
    if (!$protected && devdacti_user_excluded($actor['user_id'], $actor['user_login'], $actor['user_roles'])) {
        $result['skipped'] = 'excluded';
        return $result;
    }

    // The subject of an aggregated event is attacker-supplied (an attempted username), so it
    // is redacted before it becomes both a stored value and half of the bucket identity.
    $subject   = devdacti_substr(devdacti_redact(isset($args['subject']) ? (string) $args['subject'] : ''), 0, 191);
    $ip_exact  = isset($args['ip_exact']) ? (string) $args['ip_exact'] : '';
    $hour      = gmdate('Y-m-d H:00:00');
    $scope     = devdacti_bucket_scope($action, devdacti_ip_fingerprint($ip_exact), $hour);
    $key       = devdacti_bucket_key($scope, $subject);

    $row = array(
        'now'          => gmdate('Y-m-d H:i:s'),
        'user_id'      => $actor['user_id'],
        'user_login'   => $actor['user_login'],
        'user_role'    => $actor['user_role'],
        'ip'           => isset($args['ip_display']) ? (string) $args['ip_display'] : '',
        'peer_ip'      => isset($args['peer_ip']) ? (string) $args['peer_ip'] : devdacti_peer_ip_display(),
        'object_type'  => isset($args['object_type']) ? sanitize_key((string) $args['object_type']) : '',
        'object_id'    => isset($args['object_id']) ? (int) $args['object_id'] : 0,
        'object_title' => $subject,
        'action'       => $action,
        'details'      => devdacti_redact(isset($args['details']) ? (string) $args['details'] : ''),
        'bucket_key'   => $key,
        'bucket_scope' => $scope,
        'actor_type'   => devdacti_actor_type($actor['user_id']),
        'channel'      => devdacti_request_channel(),
        'request_id'   => devdacti_request_id(),
        'outcome'      => (isset($args['outcome']) && 'success' === $args['outcome']) ? 'success' : 'failure',
        'severity'     => isset($args['severity']) ? sanitize_key((string) $args['severity']) : devdacti_action_severity($action),
        'site_id'      => devdacti_site_id(),
    );

    $affected = devdacti_bucket_upsert($row);
    if ($affected === false) {
        $db_error = (string) $wpdb->last_error; // before the diagnostic write can clear it
        devdacti_record_write_failure($action, $db_error);
        $result['error'] = $db_error;
        $result['skipped'] = '';
        return $result;
    }

    $result['ok']      = true;
    $result['skipped'] = '';
    $result['created'] = ((int) $affected === 1);

    // Row cap per source per hour. The subject half of the bucket key is attacker-chosen,
    // so rotating usernames would otherwise mint a fresh row per attempt. Enforced only
    // when this call actually created a row, and enforced atomically: reclaim the slot with
    // a conditional DELETE and fold the single attempt into the overflow bucket ONLY if the
    // DELETE matched. A worker that incremented the row in between keeps it, and no attempt
    // is ever counted twice or dropped.
    $cap      = isset($args['max_rows']) ? (int) $args['max_rows'] : 0;
    $overflow = isset($args['overflow_subject']) ? (string) $args['overflow_subject'] : '';
    if ($result['created'] && $cap > 0 && $overflow !== '' && $subject !== $overflow) {
        $table = devdacti_events_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed count on the internal prefix table; value bound via prepare; must see live rows.
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE bucket_scope = %s", $scope));
        if (null === $count && '' !== (string) $wpdb->last_error) {
            // A failed count is not "no overflow": the attempt stays recorded as its own row and the failed read is diagnosed.
            devdacti_record_write_failure($action . '_overflow_count', (string) $wpdb->last_error);
        }
        $rows = (int) $count;
        if ($rows > $cap) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; values bound via prepare. counter = 1 makes the reclaim conditional.
            $removed = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE bucket_key = %s AND counter = 1", $key));
            if ((int) $removed === 1) {
                $row['object_title'] = $overflow;
                $row['bucket_key']   = devdacti_bucket_key($scope, $overflow);
                $row['details']      = isset($args['overflow_details']) ? devdacti_redact((string) $args['overflow_details']) : $row['details']; // redacted like the first write
                // The original row is already DELETEd at this point: if the fold-in fails
                // the attempt is gone for good, so the failure is recorded rather than
                // dropped on the floor.
                $folded = devdacti_bucket_upsert($row);
                if (false === $folded) {
                    $db_error = (string) $wpdb->last_error; // before the diagnostic write can clear it
                    devdacti_record_write_failure($action . '_overflow', $db_error);
                    $result['error']   = $db_error;
                    $result['ok']      = false; // the attempt was reclaimed and not folded in: it is lost
                    $result['created'] = false;
                } else {
                    $result['overflowed'] = true;
                }
            }
        }
    }

    devdacti_invalidate_stats();
    return $result;
}

/**
 * Display text for an event's details column. An aggregated bucket row cannot carry the
 * live count in its stored text (the counter is only ever incremented by the database),
 * so the count is rendered from the counter at read time and can never go stale.
 */
function devdacti_display_details(array $row)
{
    $details = isset($row['details']) ? (string) $row['details'] : '';
    $counter = isset($row['counter']) ? (int) $row['counter'] : 1;
    $bucket  = isset($row['bucket_key']) ? (string) $row['bucket_key'] : '';
    if ($counter < 2 || $bucket === '') {
        return $details;
    }
    $action = isset($row['action']) ? (string) $row['action'] : '';
    if ($action === 'login_failed') {
        return sprintf(
            /* translators: %d: number of failed login attempts recorded in this hourly bucket. */
            __('%d failed login attempts in this hour', 'devdome-activity-log'),
            $counter
        );
    }
    if ($action === 'password_reset_requested') {
        return sprintf(
            /* translators: %d: number of password reset requests recorded in this hourly bucket. */
            __('%d password reset requests in this hour', 'devdome-activity-log'),
            $counter
        );
    }
    return $details;
}

/** Build the writer's typed result. */
function devdacti_write_result($ok, $id = 0, $skipped = '', $error = '')
{
    return array(
        'ok'      => (bool) $ok,
        'id'      => (int) $id,
        'skipped' => (string) $skipped,
        'error'   => (string) $error,
    );
}

/**
 * Bounded operational status store for write failures. Deliberately a small fixed-shape
 * option, NOT an event row — the events table is exactly what may be broken.
 */
function devdacti_record_write_failure($action, $error)
{
    $status = get_option('devdacti_write_status', array());
    if (!is_array($status)) {
        $status = array();
    }
    $failures = isset($status['failures']) ? (int) $status['failures'] : 0;
    update_option('devdacti_write_status', array(
        'failures'    => min(4294967295, $failures + 1),
        'last_action' => sanitize_key($action),
        'last_error'  => devdacti_substr(devdacti_redact((string) $error), 0, 200), // redact FIRST: truncation can cut a quoted secret open
        'last_at'     => gmdate('Y-m-d H:i:s'),
    ), false);
}

/** Read the operational write status (0 failures when nothing has ever failed). */
function devdacti_write_status()
{
    $read_error = '';
    if (function_exists('devdacti_db_reset_error')) {
        devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    }
    $status = get_option('devdacti_write_status', array());
    if (function_exists('devdacti_db_failed') && devdacti_db_failed()) {
        $read_error = __('The write ledger could not be read.', 'devdome-activity-log'); // a failed read is not "no failures"
    }
    if (!is_array($status)) {
        $status = array();
    }
    return array(
        'failures'    => isset($status['failures']) ? (int) $status['failures'] : 0,
        'last_action' => isset($status['last_action']) ? (string) $status['last_action'] : '',
        'last_error'  => isset($status['last_error']) ? (string) $status['last_error'] : '',
        'last_at'     => isset($status['last_at']) ? (string) $status['last_at'] : '',
        'error'       => $read_error,
    );
}

/**
 * Drop the cached dashboard numbers. Called after every committed event, purge,
 * configuration change and migration so the dashboard can never show a stale zero.
 */
function devdacti_invalidate_stats()
{
    if (get_transient('devdacti_dash_stats') !== false) {
        delete_transient('devdacti_dash_stats');
    }
}

/**
 * The client IP as STORED, with truncation applied when the setting is ON.
 * Resolution (which address is the client at all) lives in includes/proxy.php.
 */
function devdacti_ip()
{
    return devdacti_ip_for_storage(devdacti_client_ip_exact());
}

/** The transport peer as stored — kept separate from the resolved client. */
function devdacti_peer_ip_display()
{
    return devdacti_ip_for_storage(devdacti_peer_ip());
}

/** Apply the site's truncation policy to one validated address. */
function devdacti_ip_for_storage($ip)
{
    $ip = (string) $ip;
    if ($ip === '') {
        return '';
    }
    if (devdacti_get_int('anonymize_ip', 1) === 1) {
        return devdacti_anonymize_ip($ip);
    }
    return $ip;
}

/**
 * Site-secret keyed fingerprint of the EXACT client address, used for aggregation
 * equality ONLY. Aggregating on the displayed (truncated) IP merged a whole /24 or
 * /48 into one attacker identity; a keyed HMAC keeps them apart without ever putting
 * the exact address in a column. The key is a WordPress salt, so it never leaves the site.
 */
function devdacti_ip_fingerprint($exact_ip)
{
    $exact_ip = (string) $exact_ip;
    if ($exact_ip === '') {
        return 'no-ip';
    }
    $key = function_exists('wp_salt') ? (string) wp_salt('auth') : 'devdacti-fallback-key';
    return hash_hmac('sha256', $exact_ip, $key);
}

/**
 * Aggregation bucket identity.
 *
 * scope = one action + one protected source identity + one UTC clock hour. Every bucket
 *         row of a flood shares it, so the per-source row cap is a single indexed count.
 * key   = scope + the normalized subject. UNIQUE in the schema, which is what makes the
 *         upsert atomic: two concurrent workers cannot create two rows for one bucket.
 */
function devdacti_bucket_scope($action, $ip_fingerprint, $hour_utc)
{
    return hash('sha256', $action . '|' . $ip_fingerprint . '|' . $hour_utc);
}

/** The unique bucket key for one subject inside a scope. */
function devdacti_bucket_key($scope, $subject)
{
    return hash('sha256', $scope . '|' . $subject);
}

/**
 * Deterministic normalization of an attempted login.
 *
 * sanitize_user( …, true ) strips every non-ASCII byte, so "админ", "<script>" and "\x00"
 * all collapsed to one misleading subject (often the empty string) and were aggregated
 * together. Anything that does not survive sanitization keeps a stable, redacted
 * identifier derived from the raw bytes instead, so distinct hostile inputs stay distinct
 * and no hostile byte is stored.
 */
function devdacti_normalize_login($raw)
{
    // Redact FIRST: a password typed into the username field is the classic way a secret
    // lands in an audit log, and sanitize_user() would strip the "password=" shape that
    // makes it recognisable before the redactor ever saw it.
    $raw   = devdacti_redact((string) $raw);
    $clean = devdacti_substr(sanitize_user($raw, true), 0, 60);
    if ($clean !== '') {
        return $clean;
    }
    if (trim($raw) === '') {
        return '(empty)';
    }
    return '(unprintable:' . substr(hash('sha256', $raw), 0, 12) . ')';
}

/**
 * Anonymize an IP: IPv4 zeroes the last octet (1.2.3.4 -> 1.2.3.0); IPv6 keeps the
 * first 3 hextets and zeroes the rest (2001:db8:85a3:... -> 2001:db8:85a3::).
 * Invalid input returns ''.
 */
function devdacti_anonymize_ip($ip)
{
    $ip = (string) $ip;

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $parts = explode('.', $ip);
        $parts[3] = '0';
        return implode('.', $parts);
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $bin = inet_pton($ip);
        if ($bin === false) {
            return '';
        }
        // Keep the first 3 hextets (6 bytes), zero the remaining 10 bytes.
        $anon = substr($bin, 0, 6) . str_repeat("\0", 10);
        $out  = inet_ntop($anon);
        return ($out === false) ? '' : $out;
    }

    return '';
}

/** Map an action key to its group key via the catalog; '' when unknown. */
function devdacti_group_for_action($action)
{
    static $map = null;
    if ($map === null) {
        $map = array();
        foreach (devdacti_actions_catalog() as $group => $actions) {
            foreach ($actions as $key => $label) {
                $map[$key] = $group;
            }
        }
    }
    return isset($map[$action]) ? $map[$action] : '';
}

/** Whether an event group is enabled. Unknown group => true (never silently drop). */
function devdacti_group_enabled($group)
{
    $group = (string) $group;
    if ($group === '' || !array_key_exists($group, devdacti_actions_catalog())) {
        return true;
    }
    // A protected group is on regardless of the stored setting.
    if (in_array($group, devdacti_protected_groups(), true)) {
        return true;
    }
    return devdacti_get_int('group_' . $group, 1) === 1;
}

/**
 * Whether this actor is excluded from logging (by login or by ANY of their roles).
 *
 * 0.1.2 compared only the primary role, so a user holding [subscriber, editor] was still
 * logged when "editor" was on the exclusion list — the setting silently did not do what it
 * said. $roles accepts a string for callers that only know one role.
 */
function devdacti_user_excluded($user_id, $login, $roles)
{
    $excluded_users = devdacti_get_array('excluded_users', array());
    if ($login !== '' && in_array($login, $excluded_users, true)) {
        return true;
    }
    $excluded_roles = devdacti_get_array('excluded_roles', array());
    if (!$excluded_roles) {
        return false;
    }
    foreach ((array) $roles as $role) {
        if ('' !== (string) $role && in_array((string) $role, $excluded_roles, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Canonical action catalog: group => array(action => human label).
 * The single source of truth for group gating, filter dropdowns and labels.
 */
function devdacti_actions_catalog()
{
    return array(
        'auth' => array(
            'login'                     => __('Login', 'devdome-activity-log'),
            'logout'                    => __('Logout', 'devdome-activity-log'),
            'login_failed'              => __('Login failed', 'devdome-activity-log'),
            'password_reset_requested'  => __('Password reset requested', 'devdome-activity-log'),
            'password_reset_completed'  => __('Password reset completed', 'devdome-activity-log'),
        ),
        'content' => array(
            'post_created'        => __('Post created', 'devdome-activity-log'),
            'post_updated'        => __('Post updated', 'devdome-activity-log'),
            'post_status_changed' => __('Post status changed', 'devdome-activity-log'),
            'post_trashed'        => __('Post trashed', 'devdome-activity-log'),
            'post_restored'       => __('Post restored', 'devdome-activity-log'),
            'post_deleted'        => __('Post deleted', 'devdome-activity-log'),
        ),
        'plugins' => array(
            'plugin_activated'   => __('Plugin activated', 'devdome-activity-log'),
            'plugin_deactivated' => __('Plugin deactivated', 'devdome-activity-log'),
            'plugin_installed'   => __('Plugin installed', 'devdome-activity-log'),
            'plugin_updated'     => __('Plugin updated', 'devdome-activity-log'),
            'plugin_deleted'     => __('Plugin deleted', 'devdome-activity-log'),
            'theme_installed'    => __('Theme installed', 'devdome-activity-log'),
            'theme_updated'      => __('Theme updated', 'devdome-activity-log'),
            'theme_deleted'      => __('Theme deleted', 'devdome-activity-log'),
            'theme_switched'     => __('Theme switched', 'devdome-activity-log'),
            'core_updated'       => __('Core updated', 'devdome-activity-log'),
        ),
        'users' => array(
            'user_created'         => __('User created', 'devdome-activity-log'),
            'user_deleted'         => __('User deleted', 'devdome-activity-log'),
            'user_role_changed'    => __('User role changed', 'devdome-activity-log'),
            'user_role_added'      => __('User role added', 'devdome-activity-log'),
            'user_role_removed'    => __('User role removed', 'devdome-activity-log'),
            'user_email_changed'   => __('User email changed', 'devdome-activity-log'),
            'user_profile_updated' => __('User profile updated', 'devdome-activity-log'),
        ),
        'media' => array(
            'media_uploaded' => __('Media uploaded', 'devdome-activity-log'),
            'media_deleted'  => __('Media deleted', 'devdome-activity-log'),
        ),
        'comments' => array(
            'comment_approved'   => __('Comment approved', 'devdome-activity-log'),
            'comment_unapproved' => __('Comment unapproved', 'devdome-activity-log'),
            'comment_spammed'    => __('Comment marked as spam', 'devdome-activity-log'),
            'comment_trashed'    => __('Comment trashed', 'devdome-activity-log'),
            'comment_restored'   => __('Comment restored', 'devdome-activity-log'),
        ),
        'settings' => array(
            'option_updated' => __('Setting updated', 'devdome-activity-log'),
        ),
        // The audit group is NEVER suppressible: these are the records of the log changing
        // itself, and a policy able to hide them would make every other row unfalsifiable.
        'audit' => array(
            'audit_policy_changed' => __('Activity Log policy changed', 'devdome-activity-log'),
            'log_exported'         => __('Activity Log exported', 'devdome-activity-log'),
            'log_purged'           => __('Activity Log purged', 'devdome-activity-log'),
            'log_erased'           => __('Activity Log records pseudonymised', 'devdome-activity-log'),
            'log_repaired'         => __('Activity Log storage repaired', 'devdome-activity-log'),
            'log_read'             => __('Activity Log opened', 'devdome-activity-log'),
            'schema_migrated'      => __('Activity Log storage migrated', 'devdome-activity-log'),
        ),
    );
}

/** Human label for an action key; falls back to the raw key. */
function devdacti_action_label($action)
{
    foreach (devdacti_actions_catalog() as $actions) {
        if (isset($actions[$action])) {
            return $actions[$action];
        }
    }
    return (string) $action;
}

/** First role of a WP_User ('' when it has none). */
function devdacti_role_of($user)
{
    $roles = devdacti_roles_of($user);
    return $roles ? (string) $roles[0] : '';
}

/** EVERY role of a WP_User. The exclusion gate must see all of them, not just the first. */
function devdacti_roles_of($user)
{
    if ($user instanceof WP_User && !empty($user->roles) && is_array($user->roles)) {
        return array_values(array_map('strval', $user->roles));
    }
    return array();
}

/**
 * CSV formula-injection guard.
 *
 * 0.1.2 looked at the first BYTE only, so "=cmd" was neutralised but " =cmd", "\n=cmd" and
 * a BOM- or bidi-prefixed formula were not — spreadsheet applications skip that leading
 * material and evaluate what follows. This walks past every character a spreadsheet ignores
 * (ASCII whitespace and control codes, NBSP and the Unicode space family, the BOM, bidi
 * marks and other invisible formatting) and neutralises the first meaningful character.
 *
 * The value itself is never altered: a leading apostrophe is prepended, which every
 * supported spreadsheet reads as "this cell is text". No evidence is stripped.
 */
function devdacti_csv_guard($value)
{
    $value = (string) $value;
    if ($value === '') {
        return $value;
    }

    $skip = '[\s\x00-\x20\x7F\x{00A0}\x{1680}\x{180E}\x{2000}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202F}\x{205F}-\x{2064}\x{206A}-\x{206F}\x{3000}\x{FEFF}]*';
    $hit  = preg_match('/^' . $skip . '[=+\-@|\x{FF1D}\x{FF0B}]/u', $value);
    if (false === $hit) {
        // The /u matcher refused the subject, so the bytes are not valid UTF-8 and nothing
        // here can reason about where the value really starts. Guard it rather than let
        // malformed input slip past by breaking the matcher.
        return "'" . $value;
    }
    if (1 !== $hit) {
        return $value;
    }

    return "'" . $value;
}

/**
 * 4 headline numbers, cached for 5 minutes (the hub tiles read the same transient).
 *
 * A failed COUNT is NOT a zero: the answer carries `error` and is never cached, so the next
 * request queries again instead of serving "0 events" for five minutes from a broken table.
 */
function devdacti_dashboard_stats()
{
    $stats = get_transient('devdacti_dash_stats');
    if (is_array($stats) && isset($stats['events_24h'])) {
        return $stats;
    }

    global $wpdb;
    $table = devdacti_events_table();
    $day_ago = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
    $week_ago = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);

    $queries = array(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal events table count; result cached in a transient below.
        'events_24h'      => $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $day_ago),
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal events table count; result cached in a transient below.
        'logins_24h'      => $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE action = 'login' AND created_at >= %s", $day_ago),
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal events table sum (aggregated failed-login counters); result cached in a transient below.
        'failed_24h'      => $wpdb->prepare("SELECT COALESCE(SUM(counter), 0) FROM {$table} WHERE action = 'login_failed' AND created_at >= %s", $day_ago),
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal events table count; result cached in a transient below.
        'active_users_7d' => $wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE user_id > 0 AND created_at >= %s", $week_ago),
    );
    $stats = array('error' => '');
    foreach ($queries as $key => $sql) {
        devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; internal events table; result cached in a transient below.
        $value = $wpdb->get_var($sql);
        if (null === $value || (string) $wpdb->last_error !== '') {
            // Unknown, not zero: every number is reported as unavailable and nothing is cached.
            $error = (string) $wpdb->last_error !== '' ? (string) $wpdb->last_error : __('The activity log could not be counted.', 'devdome-activity-log');
            return array('events_24h' => 0, 'logins_24h' => 0, 'failed_24h' => 0, 'active_users_7d' => 0, 'error' => $error);
        }
        $stats[$key] = (int) $value;
    }
    set_transient('devdacti_dash_stats', $stats, 5 * MINUTE_IN_SECONDS);
    return $stats;
}
