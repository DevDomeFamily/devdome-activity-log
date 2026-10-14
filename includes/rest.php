<?php
/**
 * REST controller — namespace devdome-activity-log/v1. Powers the Activity Log tab:
 * GET /events (validated, filtered, bounded log query) and GET /filters (dropdown facets).
 * The shared query builder devdacti_query_events() also feeds the CSV export in
 * admin.php. Every route: permission_callback = capability check, nonce via X-WP-Nonce
 * (cookie auth), all input sanitized + allowlisted, all SQL prepared, no-store on the way out.
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', 'devdacti_register_routes');

/** The one namespace this plugin owns; also what the no-store dispatch filter matches on. */
function devdacti_rest_namespace()
{
    return 'devdome-activity-log/v1';
}

/**
 * Stamp no-store on EVERY response from this namespace, including 400/403/500 errors.
 *
 * Route-level stamping missed the error paths: a WP_Error is turned into a response after
 * the callback returns, so a validation failure — which still discloses which filters exist
 * — was leaving without cache directives.
 */
function devdacti_no_store_dispatch($response, $server, $request)
{
    if (is_object($request) && method_exists($request, 'get_route')
        && 0 === strpos((string) $request->get_route(), '/' . devdacti_rest_namespace())) {
        devdacti_no_store($response);
    }
    return $response;
}
add_filter('rest_post_dispatch', 'devdacti_no_store_dispatch', 10, 3);

function devdacti_register_routes()
{
    $ns = devdacti_rest_namespace();

    register_rest_route($ns, '/events', array(
        'methods'             => 'GET',
        'callback'            => 'devdacti_rest_events',
        'permission_callback' => 'devdacti_rest_permission',
    ));

    register_rest_route($ns, '/filters', array(
        'methods'             => 'GET',
        'callback'            => 'devdacti_rest_filters',
        'permission_callback' => 'devdacti_rest_permission',
    ));
}

/** Permission: the READ capability only. (Cookie-auth nonce is validated by core.) */
function devdacti_rest_permission(WP_REST_Request $request)
{
    if (!devdacti_can('read')) {
        return new WP_Error('devdacti_forbidden', __('You do not have permission.', 'devdome-activity-log'), array('status' => 403));
    }
    return true;
}

/** Human label for a subject type; falls back to the raw key. */
function devdacti_object_type_label($type)
{
    $labels = array(
        'post'       => __('Post', 'devdome-activity-log'),
        'attachment' => __('Media', 'devdome-activity-log'),
        'user'       => __('User', 'devdome-activity-log'),
        'plugin'     => __('Plugin', 'devdome-activity-log'),
        'theme'      => __('Theme', 'devdome-activity-log'),
        'core'       => __('WordPress core', 'devdome-activity-log'),
        'option'     => __('Setting', 'devdome-activity-log'),
        'comment'    => __('Comment', 'devdome-activity-log'),
        'session'    => __('Session', 'devdome-activity-log'),
        'policy'     => __('Activity Log policy', 'devdome-activity-log'),
    );
    $type = (string) $type;
    return isset($labels[$type]) ? $labels[$type] : $type;
}

/** The valid object (subject) types an event row can carry. */
function devdacti_object_types()
{
    return array('post', 'attachment', 'user', 'plugin', 'theme', 'core', 'option', 'comment', 'session', 'policy');
}

/** Severity ladder, low to high. */
function devdacti_severities()
{
    return array('info', 'notice', 'warning', 'critical');
}

/** Human labels for the catalog groups (shared by /filters and the admin group dropdown). */
function devdacti_group_labels()
{
    return array(
        'auth'     => __('Authentication', 'devdome-activity-log'),
        'content'  => __('Content', 'devdome-activity-log'),
        'plugins'  => __('Plugins & themes', 'devdome-activity-log'),
        'users'    => __('Users', 'devdome-activity-log'),
        'media'    => __('Media', 'devdome-activity-log'),
        'comments' => __('Comments', 'devdome-activity-log'),
        'settings' => __('Settings', 'devdome-activity-log'),
        'audit'    => __('Activity Log itself', 'devdome-activity-log'),
    );
}

/** Validate a Y-m-d date string; returns the string or '' when invalid. */
function devdacti_valid_date($value)
{
    $value = sanitize_text_field((string) $value);
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('!Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : '';
}

/** Hard bounds on a query. Beyond these the answer is a validation error, not a slow scan. */
function devdacti_query_limits()
{
    return array(
        'max_page'       => 1000,
        'max_per_page'   => 100,
        'max_range_days' => 366,
        'min_search'     => 2,
        'max_search'     => 100,
    );
}

/**
 * Validate a filter set BEFORE it reaches the database.
 *
 * 0.1.2 silently returned an empty page for a reversed date range, an unknown action or a
 * page number in the millions: indistinguishable from "nothing happened". Every one of those
 * is now a named error the caller can show.
 *
 * @return array[] list of array(code, message); empty when the filters are usable
 */
function devdacti_validate_query(array $f)
{
    $limits = devdacti_query_limits();
    $errors = array();

    $page = isset($f['page']) ? (int) $f['page'] : 1;
    if ($page < 1 || $page > $limits['max_page']) {
        $errors[] = array('code' => 'page', 'message' => sprintf(
            /* translators: %d: highest page number the log will serve. */
            __('Page must be between 1 and %d. Narrow the filters or use a date range instead of paging deeper.', 'devdome-activity-log'),
            $limits['max_page']
        ));
    }

    if (isset($f['per_page'])) {
        $per = (int) $f['per_page'];
        if ($per < 1 || $per > $limits['max_per_page']) {
            $errors[] = array('code' => 'per_page', 'message' => sprintf(
                /* translators: %d: highest number of rows per page. */
                __('Rows per page must be between 1 and %d.', 'devdome-activity-log'),
                $limits['max_per_page']
            ));
        }
    }

    $raw_from = isset($f['from']) ? trim((string) $f['from']) : '';
    $raw_to   = isset($f['to']) ? trim((string) $f['to']) : '';
    $from     = devdacti_valid_date($raw_from);
    $to       = devdacti_valid_date($raw_to);

    if ('' !== $raw_from && '' === $from) {
        $errors[] = array('code' => 'from', 'message' => __('The "from" date is not a valid YYYY-MM-DD date.', 'devdome-activity-log'));
    }
    if ('' !== $raw_to && '' === $to) {
        $errors[] = array('code' => 'to', 'message' => __('The "to" date is not a valid YYYY-MM-DD date.', 'devdome-activity-log'));
    }
    if ('' !== $from && '' !== $to) {
        if (strtotime($from) > strtotime($to)) {
            $errors[] = array('code' => 'range', 'message' => __('The "from" date is later than the "to" date, so this filter can never match anything.', 'devdome-activity-log'));
        } elseif ((strtotime($to) - strtotime($from)) > $limits['max_range_days'] * DAY_IN_SECONDS) {
            $errors[] = array('code' => 'range', 'message' => sprintf(
                /* translators: %d: maximum number of days one query may span. */
                __('A single query may span at most %d days.', 'devdome-activity-log'),
                $limits['max_range_days']
            ));
        }
    }

    $search = isset($f['search']) ? trim((string) $f['search']) : '';
    if ('' !== $search) {
        $length = strlen($search);
        if ($length < $limits['min_search']) {
            $errors[] = array('code' => 'search', 'message' => sprintf(
                /* translators: %d: minimum number of characters for a search term. */
                __('Search needs at least %d characters. A one-character search reads the whole table.', 'devdome-activity-log'),
                $limits['min_search']
            ));
        } elseif ($length > $limits['max_search']) {
            $errors[] = array('code' => 'search', 'message' => sprintf(
                /* translators: %d: maximum number of characters for a search term. */
                __('Search terms are limited to %d characters.', 'devdome-activity-log'),
                $limits['max_search']
            ));
        }
    }

    $action = isset($f['action']) ? sanitize_key((string) $f['action']) : '';
    if ('' !== $action && '' === devdacti_group_for_action($action)) {
        $errors[] = array('code' => 'action', 'message' => __('That action is not one this plugin records.', 'devdome-activity-log'));
    }
    $group = isset($f['group']) ? sanitize_key((string) $f['group']) : '';
    if ('' !== $group && !isset(devdacti_actions_catalog()[$group])) {
        $errors[] = array('code' => 'group', 'message' => __('That activity group does not exist.', 'devdome-activity-log'));
    }
    $object_type = isset($f['object_type']) ? sanitize_key((string) $f['object_type']) : '';
    if ('' !== $object_type && !in_array($object_type, devdacti_object_types(), true)) {
        $errors[] = array('code' => 'object_type', 'message' => __('That object type does not exist.', 'devdome-activity-log'));
    }
    $severity = isset($f['severity']) ? sanitize_key((string) $f['severity']) : '';
    if ('' !== $severity && !in_array($severity, devdacti_severities(), true)) {
        $errors[] = array('code' => 'severity', 'message' => __('That severity does not exist.', 'devdome-activity-log'));
    }

    return $errors;
}

/**
 * Shared query builder used by GET /events AND the CSV export in admin.php.
 * Sanitizes + allowlists every filter itself (defense in depth — callers pass
 * request values through). Dates come in as site-timezone Y-m-d and are converted
 * to the UTC stored in created_at; `to` is inclusive (strictly before the next day).
 *
 * A database or schema failure is reported through the `error` key and NEVER as an empty
 * result: an audit log that renders "no events" when the table is gone is worse than useless.
 *
 * @param array $f {
 *   page, per_page, user, group, action, object_type, severity, from, to, search
 *   @type string $since_utc     exact UTC lower bound 'Y-m-d H:i:s' (abilities: last N hours)
 *   @type int  $max_id         snapshot upper bound: rows added later are excluded
 *   @type string $cursor_created keyset cursor — created_at of the last row read
 *   @type int  $cursor_id      keyset cursor — id of the last row read
 *   @type bool $skip_total     do not run COUNT(*) (export pages must not repeat it)
 * }
 * @return array { rows: array[], total: int, occurrences: int, error: string }
 */
function devdacti_query_events(array $f)
{
    global $wpdb;
    $table = devdacti_events_table();
    $catalog = devdacti_actions_catalog();

    $schema = devdacti_schema_status();
    if (empty($schema['ok'])) {
        return array('rows' => array(), 'total' => 0, 'occurrences' => 0, 'error' => (string) $schema['error']);
    }

    $page = isset($f['page']) ? max(1, (int) $f['page']) : 1;
    $per = isset($f['per_page']) ? (int) $f['per_page'] : 25;
    $per = max(1, min(5000, $per > 0 ? $per : 25));

    // Guaranteed placeholder so $wpdb->prepare() always has at least one bound value.
    $where = array('id > %d');
    $params = array(0);

    // Snapshot bound: an export that runs while events keep arriving must see one fixed
    // set of rows, or later pages shift and rows are duplicated or skipped. A caller that
    // ASKED for a snapshot and got 0 (a failed MAX(id)) must not silently degrade into an
    // unbounded read — 0 is honoured literally and returns nothing.
    if (isset($f['max_id'])) {
        $where[] = 'id <= %d';
        $params[] = (int) $f['max_id'];
    }

    // Keyset cursor on the (created_at, id) sort order — no growing OFFSET.
    if (!empty($f['cursor_created']) && !empty($f['cursor_id'])) {
        $where[] = '(created_at < %s OR (created_at = %s AND id < %d))';
        $params[] = (string) $f['cursor_created'];
        $params[] = (string) $f['cursor_created'];
        $params[] = (int) $f['cursor_id'];
    }

    $user = isset($f['user']) ? (int) $f['user'] : 0;
    if ($user > 0) {
        $where[] = 'user_id = %d';
        $params[] = $user;
    }

    // Exact actor login (a deleted account has no id left, only the name its rows carry).
    $login = isset($f['login']) ? devdacti_substr(sanitize_user((string) $f['login']), 0, 60) : '';
    if ('' !== $login) {
        $where[]  = 'user_login = %s';
        $params[] = $login;
    }

    // Both filters apply: a valid action narrows to that action, a valid group to its action list. A pair that does not
    // match (group=users, action=login_failed) returns nothing; the screen keeps them consistent, the API stays honest.
    $action = isset($f['action']) ? sanitize_key((string) $f['action']) : '';
    $group = isset($f['group']) ? sanitize_key((string) $f['group']) : '';
    if ($action !== '' && devdacti_group_for_action($action) !== '') {
        $where[] = 'action = %s';
        $params[] = $action;
    }
    if ($group !== '' && isset($catalog[$group])) {
        $keys = array_keys($catalog[$group]);
        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));
        $where[] = "action IN ({$placeholders})";
        $params = array_merge($params, $keys);
    }

    $object_type = isset($f['object_type']) ? sanitize_key((string) $f['object_type']) : '';
    if ($object_type !== '' && in_array($object_type, devdacti_object_types(), true)) {
        $where[] = 'object_type = %s';
        $params[] = $object_type;
    }

    $severity = isset($f['severity']) ? sanitize_key((string) $f['severity']) : '';
    if ($severity !== '' && in_array($severity, devdacti_severities(), true)) {
        $where[] = 'severity = %s';
        $params[] = $severity;
    }
    if (!empty($f['severity_in']) && is_array($f['severity_in'])) {
        $list = array_values(array_intersect($f['severity_in'], devdacti_severities()));
        if ($list) {
            $where[] = 'severity IN (' . implode(', ', array_fill(0, count($list), '%s')) . ')';
            $params = array_merge($params, $list);
        }
    }

    // Exact UTC lower bound (the abilities' "last N hours" window); dates below stay site-timezone days.
    if (!empty($f['since_utc']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $f['since_utc'])) {
        $where[]  = 'created_at >= %s';
        $params[] = (string) $f['since_utc'];
    }

    $from = isset($f['from']) ? devdacti_valid_date($f['from']) : '';
    if ($from !== '') {
        $where[] = 'created_at >= %s';
        $params[] = get_gmt_from_date($from . ' 00:00:00', 'Y-m-d H:i:s');
    }
    $to = isset($f['to']) ? devdacti_valid_date($f['to']) : '';
    if ($to !== '') {
        // Inclusive end date: strictly before the start of the NEXT site-timezone day.
        $next = gmdate('Y-m-d', strtotime($to . ' +1 day'));
        $where[] = 'created_at < %s';
        $params[] = get_gmt_from_date($next . ' 00:00:00', 'Y-m-d H:i:s');
    }

    $search = isset($f['search']) ? sanitize_text_field((string) $f['search']) : '';
    $search = devdacti_substr($search, 0, 100);
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where[] = '(object_title LIKE %s OR details LIKE %s OR user_login LIKE %s)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $where_sql = implode(' AND ', $where);

    $total       = 0;
    $occurrences = 0;
    if (empty($f['skip_total'])) {
        devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal events table count; WHERE values bound via prepare.
        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS row_count, COALESCE(SUM(counter), 0) AS occurrence_count FROM {$table} WHERE {$where_sql}",
            $params
        ), ARRAY_A);
        if (!is_array($totals) || (string) $wpdb->last_error !== '') {
            return array('rows' => array(), 'total' => 0, 'occurrences' => 0, 'error' => (string) $wpdb->last_error);
        }
        // Stored rows and underlying occurrences are DIFFERENT numbers: one bucket row can
        // stand for 10,000 attempts. The UI must be able to show both.
        $total       = (int) $totals['row_count'];
        $occurrences = (int) $totals['occurrence_count'];
    }

    $page_params = $params;
    $page_params[] = $per;
    $offset_sql = '';
    if (empty($f['cursor_id'])) {
        $offset_sql = ' OFFSET %d';
        $page_params[] = ($page - 1) * $per;
    }
    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // $where_sql is built above from fixed column comparisons with %s/%d placeholders only; $offset_sql is '' or ' OFFSET %d'.
    $page_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d" . $offset_sql; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- placeholder-only fragments, bound below.
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal events table page read; all values bound via prepare.
    $rows = $wpdb->get_results($wpdb->prepare($page_sql, $page_params), ARRAY_A);
    if ((string) $wpdb->last_error !== '') {
        return array('rows' => array(), 'total' => 0, 'occurrences' => 0, 'error' => (string) $wpdb->last_error);
    }

    $out = array();
    $date_format = get_option('date_format') . ' H:i';
    $can_ip = devdacti_can('view_ip');
    foreach ((array) $rows as $r) {
        $out[] = array(
            'id'              => (int) $r['id'],
            'created_at'      => (string) $r['created_at'],
            'created_display' => (string) get_date_from_gmt((string) $r['created_at'], $date_format),
            // Repeated events: when the bucket was first and last seen, kept apart from
            // created_at so a counter of 500 does not pretend to be one instant.
            'first_seen'      => isset($r['first_seen']) ? (string) $r['first_seen'] : '',
            'last_seen'       => isset($r['last_seen']) ? (string) $r['last_seen'] : '',
            'user_id'         => (int) $r['user_id'],
            'user_login'      => (string) $r['user_login'],
            'user_role'       => (string) $r['user_role'],
            'actor_type'      => isset($r['actor_type']) ? (string) $r['actor_type'] : '',
            'channel'         => isset($r['channel']) ? (string) $r['channel'] : '',
            'request_id'      => isset($r['request_id']) ? (string) $r['request_id'] : '',
            'ip'              => devdacti_display_ip((string) $r['ip'], $can_ip),
            'peer_ip'         => isset($r['peer_ip']) ? devdacti_display_ip((string) $r['peer_ip'], $can_ip) : '',
            'object_type'     => (string) $r['object_type'],
            'object_id'       => (int) $r['object_id'],
            'object_title'    => (string) $r['object_title'],
            'action'          => (string) $r['action'],
            'action_label'    => devdacti_action_label((string) $r['action']),
            'group'           => devdacti_group_for_action((string) $r['action']),
            'outcome'         => isset($r['outcome']) ? (string) $r['outcome'] : '',
            'severity'        => isset($r['severity']) ? (string) $r['severity'] : '',
            'site_id'         => isset($r['site_id']) ? (int) $r['site_id'] : 0,
            'details'         => devdacti_display_details($r),
            'counter'         => (int) $r['counter'],
        );
    }

    return array('rows' => $out, 'total' => $total, 'occurrences' => $occurrences, 'error' => '');
}

/** GET /events — validated, paginated, filtered log rows for the Activity Log table. */
function devdacti_rest_events(WP_REST_Request $request)
{
    $filters = array(
        'page'        => (int) $request->get_param('page') ? (int) $request->get_param('page') : 1,
        'per_page'    => (int) $request->get_param('per_page') ? (int) $request->get_param('per_page') : 25,
        'user'        => (int) $request->get_param('user'),
        'group'       => (string) $request->get_param('group'),
        'action'      => (string) $request->get_param('action'),
        'object_type' => (string) $request->get_param('object_type'),
        'severity'    => (string) $request->get_param('severity'),
        'from'        => (string) $request->get_param('from'),
        'to'          => (string) $request->get_param('to'),
        'search'      => (string) $request->get_param('search'),
    );

    // Fail loudly on an impossible query instead of returning an empty page that reads
    // exactly like "nothing happened".
    $invalid = devdacti_validate_query($filters);
    if ($invalid) {
        $messages = array();
        foreach ($invalid as $e) {
            $messages[] = $e['message'];
        }
        return new WP_Error(
            'devdacti_invalid_query',
            implode(' ', $messages),
            array('status' => 400, 'fields' => wp_list_pluck($invalid, 'code'))
        );
    }

    $result = devdacti_query_events($filters);

    // Reading the log is itself an audited act (bucketed per reader per hour), recorded with the real outcome.
    devdacti_log_read_access(empty($result['error']));

    // Fail visible: a broken table or query must reach the client as an error status, never
    // as a 200 with an empty row list that looks like a quiet, healthy site.
    if (!empty($result['error'])) {
        return new WP_Error(
            'devdacti_query_failed',
            __('The activity log could not be read. Recording may also be affected.', 'devdome-activity-log'),
            array('status' => 500)
        );
    }

    $per      = (int) $filters['per_page'];
    $response = rest_ensure_response(array(
        'rows'        => $result['rows'],
        'total'       => (int) $result['total'],
        'occurrences' => (int) $result['occurrences'],
        'pages'       => (int) ceil($result['total'] / max(1, $per)),
    ));
    return devdacti_no_store($response);
}

/** Personal data must not be cached by a proxy, a browser or a service worker. */
function devdacti_no_store($response)
{
    if (is_object($response) && method_exists($response, 'header')) {
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
    }
    return $response;
}


/**
 * The failed-login picture over a whole window, aggregated in SQL: total attempts (bucket counters summed),
 * the ten most targeted account names and the ten most frequent source addresses. A failed query is an
 * `error`, never a zero.
 *
 * @return array { attempts: int, targets: array<string,int>, sources: array<string,int>, error: string }
 */
function devdacti_failed_login_totals($since_utc)
{
    global $wpdb;
    $table = devdacti_events_table();
    $out   = array('attempts' => 0, 'targets' => array(), 'sources' => array(), 'error' => '');

    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal events table sum of aggregated failed-login counters; values bound via prepare.
    $sum = $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(counter), 0) FROM {$table} WHERE action = 'login_failed' AND created_at >= %s", (string) $since_utc));
    if (null === $sum || (string) $wpdb->last_error !== '') {
        $out['error'] = (string) $wpdb->last_error !== '' ? (string) $wpdb->last_error : 'failed-login total unavailable';
        return $out;
    }
    $out['attempts'] = (int) $sum;

    foreach (array('targets' => 'object_title', 'sources' => 'ip') as $key => $column) {
        devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal events table grouped sum; $column is one of two fixed names; values bound via prepare.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT {$column} AS k, SUM(counter) AS n FROM {$table} WHERE action = 'login_failed' AND created_at >= %s GROUP BY {$column} ORDER BY n DESC LIMIT 10", (string) $since_utc), ARRAY_A);
        if (!is_array($rows) || (string) $wpdb->last_error !== '') {
            $out['error'] = (string) $wpdb->last_error !== '' ? (string) $wpdb->last_error : 'failed-login ' . $key . ' unavailable';
            return $out;
        }
        foreach ($rows as $row) {
            // Sources are addresses: shown as this viewer may see them (view_ip), so two addresses that mask to the
            // same prefix add up under that prefix.
            $k = 'sources' === $key ? devdacti_display_ip((string) $row['k']) : (string) $row['k'];
            $out[$key][$k] = (isset($out[$key][$k]) ? (int) $out[$key][$k] : 0) + (int) $row['n'];
        }
    }
    return $out;
}

/**
 * GET /filters — dropdown feed: users seen in the log plus the static catalogs.
 *
 * The user facet is the 200 most recently active accounts, not every account that has ever
 * appeared. `users_truncated` says so, and the UI prints it rather than implying the list
 * is complete.
 */
function devdacti_rest_filters(WP_REST_Request $request)
{
    global $wpdb;
    $table = devdacti_events_table();

    $schema = devdacti_schema_status();
    if (empty($schema['ok'])) {
        return new WP_Error(
            'devdacti_query_failed',
            (string) $schema['error'],
            array('status' => 500)
        );
    }

    $limit = 200;
    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal events table facet read; values bound via prepare.
    $users = $wpdb->get_results($wpdb->prepare(
        "SELECT user_id, user_login, MAX(id) AS last_id FROM {$table} WHERE user_id > %d GROUP BY user_id, user_login ORDER BY last_id DESC LIMIT %d",
        0,
        $limit + 1
    ), ARRAY_A);
    if ((string) $wpdb->last_error !== '' || !is_array($users)) {
        // A failed facet read is a database error, never an empty user list.
        return new WP_Error(
            'devdacti_query_failed',
            __('The activity log could not be read. Recording may also be affected.', 'devdome-activity-log'),
            array('status' => 500)
        );
    }

    $user_list = array();
    foreach ((array) $users as $u) {
        $user_list[] = array(
            'id'    => (int) $u['user_id'],
            'login' => (string) $u['user_login'],
        );
    }
    $truncated = (count($user_list) > $limit);
    $user_list = array_slice($user_list, 0, $limit);
    usort($user_list, function ($a, $b) {
        return strcasecmp($a['login'], $b['login']);
    });

    $response = rest_ensure_response(array(
        'users'           => $user_list,
        'users_truncated' => $truncated,
        'groups'          => devdacti_group_labels(),
        'actions'         => devdacti_actions_catalog(),
        'object_types'    => devdacti_object_types(),
        'severities'      => devdacti_severities(),
    ));
    return devdacti_no_store($response);
}
