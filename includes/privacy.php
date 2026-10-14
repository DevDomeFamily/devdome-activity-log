<?php
/**
 * Privacy handling (audit 4, batch 5): redaction before storage, capability-scoped display
 * of identifying fields, and the WordPress personal-data exporter/eraser.
 *
 * The honest position, which the readme now states too: this table holds personal data.
 * Truncating an IP reduces precision, it does not anonymise a record that also carries a
 * username, a user id, a role, a timestamp and a behavioural history. Nothing in this file
 * claims otherwise; it reduces what is collected and controls who can see it.
 */

defined('ABSPATH') || exit;

/**
 * Strip credential-shaped material from anything about to be stored.
 *
 * Attempted usernames, option values, post titles and URLs are all attacker-reachable and
 * routinely carry a password typed into the wrong field, a reset token in a query string or
 * an API key pasted into a title. The original logical text is preserved apart from the
 * secret itself, which is replaced by a marker so the evidence still reads correctly.
 */
function devdacti_redact($text)
{
    $text = (string) $text;
    if ('' === $text) {
        return $text;
    }

    // Credentials embedded in a URL (https://user:pass@host/...): an allowlisted option such as siteurl can carry them.
    $text = (string) preg_replace('#(://)[^/\s:@"\']+:[^/\s@"\']+@#', '$1[redacted]@', $text);

    // PEM blocks (private keys, certificates): the whole block, terminated or truncated.
    $text = (string) preg_replace('/-----BEGIN [A-Z0-9 ]+-----[\s\S]*?-----END [A-Z0-9 ]+-----/', '[redacted]', $text);
    $text = (string) preg_replace('/-----BEGIN [A-Z0-9 ]+-----[\s\S]*$/', '[redacted]', $text);

    // Authorization credentials as a whole: "Authorization: Bearer <token>" and "Basic <base64>" are two
    // words, and a per-word rule would leave the credential standing after the scheme.
    $text = (string) preg_replace('/((?:proxy-)?authorization["\']?\s*[=:]\s*)(?:bearer|basic|digest|token|negotiate|ntlm|oauth)\s+[^\s,;&"\'<>]+/i', '$1[redacted]', $text);
    $text = (string) preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]{16,}/i', '[redacted]', $text);
    $text = (string) preg_replace('/\bBasic\s+(?=[A-Za-z0-9+\/=]*[0-9+\/=])[A-Za-z0-9+\/]{8,}={0,2}(?![A-Za-z0-9])/i', '[redacted]', $text);

    // Configuration literals that carry a credential: define('DB_PASSWORD', '...'), define("AUTH_KEY", "..."),
    // $db_password = '...'; the quoted value may span lines (a pasted wp-config.php, a salt block).
    $text = (string) preg_replace(
        '/(define\s*\(\s*["\'][A-Za-z0-9_]*(?:PASS|PASSWORD|PASSWD|PWD|SECRET|KEY|TOKEN|SALT|AUTH|NONCE)[A-Za-z0-9_]*["\']\s*,\s*)(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')/is',
        '$1\'[redacted]\'',
        $text
    );
    $text = (string) preg_replace(
        '/(\$[A-Za-z0-9_]*(?:pass|password|passwd|pwd|secret|token|api_?key|private_?key|salt|auth)[A-Za-z0-9_]*\s*=\s*)(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')/is',
        '$1\'[redacted]\'',
        $text
    );

    // Environment literals that carry a credential: putenv("DB_PASSWORD=...") / putenv('TOKEN=...'). Each quote
    // character has its own rule, so the value runs to ITS closing quote (an opposite quote inside the secret,
    // an escaped quote or a newline does not end it early).
    $text = (string) preg_replace('/(putenv\s*\(\s*"[A-Za-z0-9_]*(?:PASS|PASSWORD|PASSWD|PWD|SECRET|KEY|TOKEN|SALT|AUTH|NONCE)[A-Za-z0-9_]*=)(?:[^"\\\\]|\\\\.)*(")/is', '$1[redacted]$2', $text);
    $text = (string) preg_replace('/(putenv\s*\(\s*\'[A-Za-z0-9_]*(?:PASS|PASSWORD|PASSWD|PWD|SECRET|KEY|TOKEN|SALT|AUTH|NONCE)[A-Za-z0-9_]*=)(?:[^\'\\\\]|\\\\.)*(\')/is', '$1[redacted]$2', $text);

    // Prefixed secret keys: user_pass=, db_password=, AUTH_KEY=, SECURE_AUTH_KEY=, my_token=, api-secret= (the
    // prefix is one or more words joined by _ or -). "auth" and "key" alone stay out (group_auth=1, meta_key=x are
    // ordinary names); the strong words and the *_key forms WordPress uses for its salts are in.
    $text = (string) preg_replace(
        '/(\b(?:[A-Za-z0-9]+(?:[_-][A-Za-z0-9]+)*[_-](?:pass|password|passwd|pwd|secret|token|salt)|(?:[A-Za-z0-9]+(?:[_-][A-Za-z0-9]+)*[_-])?(?:api|auth|private|secret|access|secure[_-]?auth|logged[_-]?in|nonce)[_-]?key)(?:\\\\?["\'])?\s*[=:]\s*)(?:\\\\"(?:[^"\\\\]|\\\\.)*?\\\\"|"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[^\s&;,"\']+)/i',
        '$1[redacted]',
        $text
    );

    // key=value pairs whose key names a secret (query strings, form dumps, log lines) and the
    // same keys quoted as JSON ("password":"...", including JSON escaped inside a string).
    // The lookbehind keeps ordinary names that merely END with one of these words —
    // "group_auth", "shortcode", "passthrough" — out of the match.
    $text = (string) preg_replace(
        '/(?<![A-Za-z0-9_])((?:pwd|pass|password|passwd|token|access[_-]?token|refresh[_-]?token|auth|authorization|api[_-]?key|apikey|secret|client[_-]?secret|private[_-]?key|nonce|_wpnonce|session|otp|mfa|2fa|code|signature|sig)(?:\\\\?["\'])?\s*[=:]\s*)(?:\\\\"(?:[^"\\\\]|\\\\.)*?\\\\"|"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[^\s&;,"\']+)/i',
        '$1[redacted]',
        $text
    );

    // JSON web tokens and bearer credentials
    $text = (string) preg_replace('/\b(?:Bearer\s+)?[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/', '[redacted]', $text);

    // WordPress/phpass/bcrypt password hashes
    $text = (string) preg_replace('/\$(?:P|H|2[aby]|wp)\$[.\/A-Za-z0-9$]{10,}/', '[redacted]', $text);

    // long hex/base32 blobs: application passwords, api keys, raw hashes
    $text = (string) preg_replace('/\b[A-Fa-f0-9]{32,}\b/', '[redacted]', $text);

    return $text;
}

/** Mask an email for storage/display: keeps the shape, drops the identity. */
function devdacti_mask_email($email)
{
    $email = (string) $email;
    $at    = strpos($email, '@');
    if (false === $at || $at < 1) {
        return $email !== '' ? '***' : '';
    }
    $local  = substr($email, 0, $at);
    $domain = substr($email, $at + 1);
    $dot    = strrpos($domain, '.');
    $tld    = (false !== $dot) ? substr($domain, $dot) : '';
    return substr($local, 0, 1) . '***@' . substr($domain, 0, 1) . '***' . $tld;
}

/** The two names an anonymous attempt can call this account by: the normalised login and the normalised email. */
function devdacti_subject_names($user)
{
    $login = devdacti_normalize_login((string) $user->user_login);
    $email = isset($user->user_email) ? devdacti_normalize_login((string) $user->user_email) : '';
    return array($login, '' !== $email ? $email : $login);
}

/** Mask every email address inside free text (a display name or website field can carry one). */
function devdacti_mask_emails_in($text)
{
    return (string) preg_replace_callback('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', function ($m) {
        return devdacti_mask_email($m[0]);
    }, (string) $text);
}

/**
 * The IP as this viewer may see it. Reading the log does not by itself grant the right to
 * see a full client address; that is its own capability.
 */
function devdacti_display_ip($ip, $can_view_full = null)
{
    $ip = (string) $ip;
    if ('' === $ip) {
        return '';
    }
    if (null === $can_view_full) {
        $can_view_full = devdacti_can('view_ip');
    }
    if ($can_view_full) {
        return $ip;
    }
    $masked = devdacti_anonymize_ip($ip);
    return ('' !== $masked) ? $masked : '';
}

/**
 * Every field this plugin stores, why it is stored, and what it means for a data subject.
 * Rendered on the Settings screen and mirrored in readme.txt — one source, not two claims.
 *
 * @return array[] field, purpose
 */
function devdacti_data_inventory()
{
    return array(
        array(__('Date and time (UTC), first and last seen', 'devdome-activity-log'), __('When the action happened, and for repeated events the window it covers.', 'devdome-activity-log')),
        array(__('Actor user ID, username and roles', 'devdome-activity-log'), __('Who performed the action. Personal data.', 'devdome-activity-log')),
        array(__('Actor type and request channel', 'devdome-activity-log'), __('Whether a person, cron or WP-CLI acted, and whether the request came through the admin, REST, XML-RPC or an application password.', 'devdome-activity-log')),
        array(__('Request ID', 'devdome-activity-log'), __('Groups the rows that belong to one request. Not linked to any identity outside this table.', 'devdome-activity-log')),
        array(__('Client IP and transport peer IP', 'devdome-activity-log'), __('Where the request came from. Personal data, truncated by default; the untruncated address is never stored when truncation is on.', 'devdome-activity-log')),
        array(__('Subject type, ID and title', 'devdome-activity-log'), __('What was acted on: a post, a user, a plugin, an option. May contain a username or a post title.', 'devdome-activity-log')),
        array(__('Action, outcome, severity and occurrence count', 'devdome-activity-log'), __('What happened, whether it succeeded, how serious it is and how often it repeated.', 'devdome-activity-log')),
        array(__('Details', 'devdome-activity-log'), __('A short description of the change. Credential-shaped values are redacted before storage; post bodies are compared by hash and never stored.', 'devdome-activity-log')),
        array(__('Site ID', 'devdome-activity-log'), __('Which site in a multisite network the event belongs to.', 'devdome-activity-log')),
    );
}

/* ------------------------------- exporter -------------------------------- */

add_filter('wp_privacy_personal_data_exporters', 'devdacti_register_exporter');
add_filter('wp_privacy_personal_data_erasers', 'devdacti_register_eraser');

function devdacti_register_exporter($exporters)
{
    $exporters['devdome-activity-log'] = array(
        'exporter_friendly_name' => __('DevDome Activity Log', 'devdome-activity-log'),
        'callback'               => 'devdacti_personal_data_exporter',
    );
    return $exporters;
}

function devdacti_register_eraser($erasers)
{
    $erasers['devdome-activity-log'] = array(
        'eraser_friendly_name' => __('DevDome Activity Log', 'devdome-activity-log'),
        'callback'             => 'devdacti_personal_data_eraser',
    );
    return $erasers;
}

/** Rows recorded for one account, 100 per page, in WordPress' export format. */
function devdacti_personal_data_exporter($email_address, $page = 1)
{
    global $wpdb;

    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    $user = get_user_by('email', $email_address);
    if (!$user && devdacti_db_failed()) {
        // The users table could not be read: that is not "no such account".
        devdacti_record_write_failure('personal_data_exporter_lookup', (string) $wpdb->last_error);
        return new WP_Error('devdacti_export_failed', __('The account could not be looked up, so this export is incomplete. Retry once the database is healthy.', 'devdome-activity-log'));
    }
    if (!$user) {
        return array('data' => array(), 'done' => true);
    }

    $schema = devdacti_schema_status();
    if (empty($schema['ok'])) {
        // Never report "nothing recorded" when the table cannot be read: WordPress shows a
        // WP_Error to the requester and the export stays incomplete.
        return new WP_Error('devdacti_export_failed', (string) $schema['error']);
    }

    $page  = max(1, (int) $page);
    $per   = 100;
    $table = devdacti_events_table();
    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // The eraser pseudonymises rows where this account is the ACTOR and rows where it is the
    // SUBJECT. The export has to cover exactly the same set, or the two disagree about what
    // "this person's data" is.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal events table; values bound via prepare; personal-data export must read live rows.
    // Three kinds of rows are this person's: rows they performed (actor), rows about their account (subject,
    // object_type user) and the anonymous attempts that NAME their account (failed logins and reset requests,
    // object_type session, no actor). The eraser below pseudonymises exactly the same three.
    // A failed login can name the account by its email address as well as by its login.
    $names = devdacti_subject_names($user);
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE (user_id = %d OR (object_type = %s AND object_id = %d) OR (object_type = %s AND user_id = 0 AND object_title IN (%s, %s))) ORDER BY id ASC LIMIT %d OFFSET %d",
        (int) $user->ID,
        'user',
        (int) $user->ID,
        'session',
        $names[0],
        $names[1],
        $per,
        ($page - 1) * $per
    ), ARRAY_A);
    if ((string) $wpdb->last_error !== '' || !is_array($rows)) {
        // A read failure must never be reported to the requester as "nothing recorded".
        devdacti_record_write_failure('personal_data_exporter', (string) $wpdb->last_error);
        return new WP_Error('devdacti_export_failed', __('The activity log could not be read, so this export is incomplete. Retry once the log is healthy.', 'devdome-activity-log'));
    }

    $export = array();
    foreach ($rows as $r) {
        // The actor columns belong to whoever performed the action. On a row where this person is only the SUBJECT
        // (an administrator changed their role, an unknown visitor tried their password) they are someone else's
        // username, role and address and are not exported to this person.
        $is_actor = (int) $r['user_id'] === (int) $user->ID;
        $export[] = array(
            'group_id'    => 'devdome-activity-log',
            'group_label' => __('Activity log', 'devdome-activity-log'),
            'item_id'     => 'devdacti-event-' . (int) $r['id'],
            'data'        => array(
                array('name' => __('Date (UTC)', 'devdome-activity-log'), 'value' => (string) $r['created_at']),
                array('name' => __('Action', 'devdome-activity-log'), 'value' => devdacti_action_label((string) $r['action'])),
                array('name' => __('Outcome', 'devdome-activity-log'), 'value' => (string) (isset($r['outcome']) ? $r['outcome'] : '')),
                array('name' => __('Username', 'devdome-activity-log'), 'value' => $is_actor ? (string) $r['user_login'] : __('(another account or an anonymous visitor)', 'devdome-activity-log')),
                array('name' => __('Role', 'devdome-activity-log'), 'value' => $is_actor ? (string) $r['user_role'] : ''),
                array('name' => __('IP address', 'devdome-activity-log'), 'value' => $is_actor ? (string) $r['ip'] : ''),
                array('name' => __('Subject', 'devdome-activity-log'), 'value' => trim((string) $r['object_type'] . ' ' . (string) $r['object_title'])),
                array('name' => __('Details', 'devdome-activity-log'), 'value' => devdacti_display_details($r)),
                array('name' => __('Occurrences', 'devdome-activity-log'), 'value' => (string) (int) $r['counter']),
            ),
        );
    }

    return array('data' => $export, 'done' => count($rows) < $per);
}

/**
 * Eraser. Security evidence is pseudonymised, not deleted: removing the record of who
 * changed a role would hand every attacker a supported way to erase their own trail. The
 * account identifiers are cleared and the erasure itself is recorded.
 */
function devdacti_personal_data_eraser($email_address, $page = 1)
{
    global $wpdb;

    $result = array(
        'items_removed'  => false,
        'items_retained' => false,
        'messages'       => array(),
        'done'           => true,
    );

    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    $user = get_user_by('email', $email_address);
    if (!$user && devdacti_db_failed()) {
        devdacti_record_write_failure('personal_data_eraser_lookup', (string) $wpdb->last_error);
        return new WP_Error('devdacti_erase_failed', __('The account could not be looked up, so this erasure did not run. Retry once the database is healthy.', 'devdome-activity-log'));
    }
    if (!$user) {
        return $result;
    }

    $schema = devdacti_schema_status();
    if (empty($schema['ok'])) {
        return new WP_Error('devdacti_erase_failed', __('The activity log could not be read, so nothing was changed. Retry once the log is healthy.', 'devdome-activity-log'));
    }

    $table = devdacti_events_table();

    // Every UPDATE below is checked. The writes run in an order that keeps this account's rows
    // findable (by user_id) until the very last statement clears it, so a failure at any step
    // leaves nothing a retry could no longer reach, and is reported as an error instead of a
    // completed erasure.
    $failed = function ($step) {
        global $wpdb;
        devdacti_record_write_failure('personal_data_eraser', $step . ': ' . (string) $wpdb->last_error);
        return new WP_Error('devdacti_erase_failed', __('The activity log could not be updated, so this erasure did not complete. Nothing is reported as erased; retry once the log is healthy.', 'devdome-activity-log'));
    };

    // Rows this account performed itself (their own login, logout, password change) carry the
    // account's own name in object_title as well — as the login, or as the display name on
    // comment and profile rows. Both have to be cleared BEFORE the pseudonymisation below,
    // which clears the user_id these rows are found by.
    $own_names = array((string) $user->user_login);
    if (isset($user->display_name) && '' !== (string) $user->display_name) {
        $own_names[] = (string) $user->display_name;
    }
    // How many rows are this person's, counted ONCE and BEFORE the statements below move them (the statements
    // overlap: a self-login row is an actor row AND carries the own name, so summing their counts would double it).
    $names = devdacti_subject_names($user);
    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal events table
    $affected = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE user_id = %d OR (object_type = %s AND object_id = %d) OR (object_type = %s AND user_id = 0 AND object_title IN (%s, %s))",
        (int) $user->ID,
        'user',
        (int) $user->ID,
        'session',
        $names[0],
        $names[1]
    ));
    if (null === $affected || devdacti_db_failed()) {
        return $failed('count');
    }
    $affected = (int) $affected;

    // This actor's own log_read rows name them with a derived title ("login (failed read)"), so they are cleared by
    // ACTION before the user_id they are found by is cleared below.
    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal events table pseudonymisation
    $own_reads = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET object_title = %s WHERE user_id = %d AND action = %s AND object_title <> %s",
        '(erased)',
        (int) $user->ID,
        'log_read',
        '(erased)'
    ));
    if (false === $own_reads) {
        return $failed('own_read_rows');
    }

    foreach (array_unique(array_filter($own_names)) as $own_name) {
        devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal events table pseudonymisation; values bound via prepare.
        $titles = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET object_title = %s WHERE user_id = %d AND object_title = %s",
            '(erased)',
            (int) $user->ID,
            $own_name
        ));
        if (false === $titles) {
            return $failed('own_titles');
        }
    }

    // details is free text: it can carry a display name, a previous email, a nicename or a
    // profile URL. The action, subject, outcome and timestamp columns are the security
    // evidence and are kept; the free-text sentence about this person is not.
    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal events table pseudonymisation; values bound via prepare.
    $details = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET details = %s WHERE user_id = %d AND details <> %s",
        '(erased)',
        (int) $user->ID,
        ''
    ));
    if (false === $details) {
        return $failed('own_details');
    }

    // Rows where this account is the SUBJECT rather than the actor (someone else changed
    // their role, edited their profile) carry the username in object_title and their old and
    // new profile values in details. Who performed the action, when, and against which user
    // id is kept — that is the evidence; the person's own values are not.
    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal events table pseudonymisation; values bound via prepare.
    $subjects = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET object_title = %s, details = %s WHERE object_type = %s AND object_id = %d",
        '(erased)',
        '(erased)',
        'user',
        (int) $user->ID
    ));
    if (false === $subjects) {
        return $failed('subject_rows');
    }

    // Anonymous attempts that NAME this account (failed logins, reset requests: object_type session, no actor)
    // carry the username in object_title; the exporter lists them, so the eraser clears them too.
    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal events table pseudonymisation
    $named = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET object_title = %s WHERE object_type = %s AND user_id = 0 AND object_title IN (%s, %s)",
        '(erased)',
        'session',
        $names[0],
        $names[1]
    ));
    if (false === $named) {
        return $failed('named_rows');
    }

    // A subject row with no actor (the person's own anonymous reset request) stored the requester's address: it is
    // this person's address as far as anyone can tell, so it goes too. Rows an administrator performed keep theirs.
    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal events table pseudonymisation
    $anon = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET ip = %s, peer_ip = %s WHERE object_type = %s AND object_id = %d AND user_id = 0",
        '',
        '',
        'user',
        (int) $user->ID
    ));
    if (false === $anon) {
        return $failed('anonymous_subject_rows');
    }

    // LAST: clear the identifiers every statement above found the rows by.
    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal events table pseudonymisation; values bound via prepare.
    $changed = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET user_login = %s, user_id = 0, ip = %s, peer_ip = %s WHERE user_id = %d",
        '(erased)',
        '',
        '',
        (int) $user->ID
    ));
    if (false === $changed) {
        return $failed('actor_rows');
    }
    $changed = $affected; // distinct rows, counted before the overlapping statements ran

    if ((int) $changed > 0) {
        $result['items_retained'] = true;
        $result['messages'][]     = sprintf(
            /* translators: %d: number of activity-log records pseudonymised. */
            __('%d activity log records were pseudonymised: the username, user ID and IP address were cleared. The security record of what happened is retained, because deleting it would remove the site\'s evidence of account and configuration changes.', 'devdome-activity-log'),
            (int) $changed
        );
        $recorded = devdacti_log_audit(
            'log_erased',
            __('Personal data erasure', 'devdome-activity-log'),
            sprintf(
                /* translators: %d: number of records pseudonymised. */
                __('%d records pseudonymised for a personal-data erasure request', 'devdome-activity-log'),
                (int) $changed
            )
        );
        if (empty($recorded['ok'])) {
            // The data IS pseudonymised; what is missing is the record of it. Said out loud, counted as a
            // lost write (the suite health panel turns urgent), never passed off as a complete audit trail.
            devdacti_record_write_failure('personal_data_eraser_record', (string) (isset($recorded['error']) ? $recorded['error'] : 'erasure record not written'));
            $result['messages'][] = __('The erasure itself completed, but the activity log could not record it: the audit trail of this erasure is incomplete. The failed write is counted on the Activity Log dashboard.', 'devdome-activity-log');
        }
        devdacti_invalidate_stats();
    }

    return $result;
}
