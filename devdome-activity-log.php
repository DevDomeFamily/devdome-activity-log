<?php
/*
Plugin Name: DevDome Activity Log: Track WordPress User Activity
Plugin URI: https://devdome.com/
Description: A local audit log of logins, content, user, plugin, theme, media, comment and key-setting changes, with atomic failed-login counting and an honest coverage list. Part of the DevDome suite.
Version: 1.0.1
Author: DevDome
Author URI: https://devdome.com
Text Domain: devdome-activity-log
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 6.0
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) {
    exit;
}

// The WordPress.org zip ships this marker file (defines DEVDCOREV1_WPORG_BUILD) so the
// same codebase can switch off self-hosted updates and other non-wp.org behavior.
if (file_exists(__DIR__ . '/wporg-build.php')) {
    require __DIR__ . '/wporg-build.php';
}

define('DEVDACTI_VERSION', '1.0.1');
// Storage schema revision. Bumped whenever the events/settings tables change; the migration
// runner in includes/install.php re-applies the schema on a normal update, not only on activation.
define('DEVDACTI_SCHEMA', 3);
define('DEVDACTI_DIR', plugin_dir_path(__FILE__));
define('DEVDACTI_URL', plugin_dir_url(__FILE__));
define('DEVDACTI_FILE', __FILE__);
define('DEVDACTI_PAGE', 'devdome-activity-log');

// Shared DevDome core (vendored, version-guarded; only the highest copy across all installed
// DevDome plugins actually loads). Provides the suite hub, account/licensing seam, report
// contract and shared cron. Loaded FIRST, above every other require.
require_once DEVDACTI_DIR . 'lib/devdome-core/loader.php';

require_once DEVDACTI_DIR . 'includes/db-guard.php'; // DESIGN.md 24 / 24.5: failed-query guard + proved option writes
// Request-wide database guard (DESIGN.md 24): every failed query is recorded before wpdb::query() clears it.
add_filter('query', 'devdacti_db_guard_record', 1);

// Trackers load UNCONDITIONALLY — auth/content hooks fire on the front end too.
require_once DEVDACTI_DIR . 'includes/settings.php';
require_once DEVDACTI_DIR . 'includes/install.php';
require_once DEVDACTI_DIR . 'includes/helpers.php';
require_once DEVDACTI_DIR . 'includes/policy.php';
require_once DEVDACTI_DIR . 'includes/proxy.php';
require_once DEVDACTI_DIR . 'includes/privacy.php';
require_once DEVDACTI_DIR . 'includes/tracker-auth.php';
require_once DEVDACTI_DIR . 'includes/tracker-content.php';
require_once DEVDACTI_DIR . 'includes/tracker-system.php';
require_once DEVDACTI_DIR . 'includes/tracker-users.php';
require_once DEVDACTI_DIR . 'includes/rest.php';
require_once DEVDACTI_DIR . 'includes/abilities.php'; // WordPress Abilities API (6.9+): registers nothing on older versions

// "Report this error" (core 1.7.0, DESIGN.md 26): a report carries the plugin's operational state (counts and flags,
// never an event row), and every text passes the plugin's own redactor before it leaves the site.
add_filter('devdcorev1_error_report_log', function ($lines, $plugin) {
    if ($plugin !== 'devdome-activity-log') {
        return $lines;
    }
    $schema = function_exists('devdacti_schema_status') ? devdacti_schema_status() : array();
    $writes = function_exists('devdacti_write_status') ? devdacti_write_status() : array();
    $ret    = function_exists('devdacti_retention_status') ? devdacti_retention_status() : array();
    $stats  = get_transient('devdacti_dash_stats');
    return array(
        'schema=' . (int) get_option('devdacti_schema_version', 0) . ' schema_ok=' . (int) !empty($schema['ok']) . ' missing=' . wp_json_encode(isset($schema['missing']) ? $schema['missing'] : array()) . ' missing_key=' . wp_json_encode(isset($schema['missing_key']) ? $schema['missing_key'] : array()),
        'write_failures=' . (int) (isset($writes['failures']) ? $writes['failures'] : 0) . ' last_action=' . (isset($writes['last_action']) ? $writes['last_action'] : '') . ' last_error=' . (isset($writes['last_error']) ? $writes['last_error'] : ''),
        'retention_days=' . (function_exists('devdacti_retention_days') ? (int) devdacti_retention_days() : 0) . ' last_run=' . (isset($ret['last_run']) ? $ret['last_run'] : '') . ' last_error=' . (isset($ret['last_error']) ? $ret['last_error'] : '') . ' backlog=' . (int) !empty($ret['backlog']),
        'stats=' . wp_json_encode(is_array($stats) ? $stats : null),
        'proxy=' . (function_exists('devdacti_proxy_status') ? wp_json_encode(devdacti_proxy_status()) : 'n/a'),
    );
}, 10, 2);
add_filter('devdcorev1_error_report_redact', function ($text, $plugin) {
    return ($plugin === 'devdome-activity-log' && function_exists('devdacti_redact')) ? devdacti_redact((string) $text) : $text;
}, 10, 2);

if (is_admin()) {
    require_once DEVDACTI_DIR . 'includes/admin.php';
}

// The capability model lives in includes/policy.php: reading the log, exporting it,
// changing its policy and purging it are separate powers with separate filters.

// DevDome Tools hub: register this plugin in the suite dashboard (decoupled — a new plugin is
// ~10 lines, zero hub edits). Tiles + health read ONLY the transient-cached stats (no fresh
// query on render).
add_filter('devdcorev1_suite_register', function ($r) {
    $r['devdome-activity-log'] = array(
        'slug'     => 'devdome-activity-log',
        'name'     => __('Activity Log', 'devdome-activity-log'),
        'desc'     => __('Local audit log of logins, content, users, plugins &amp; key settings.', 'devdome-activity-log'),
        'icon'     => 'dashicons-list-view',
        'version'  => DEVDACTI_VERSION,
        'page'     => 'devdome-activity-log',
        'position' => 70,
        'schema'   => 1,
        'tiles'    => function () {
            // Hub HARD RULE: read ONLY the pre-computed transient — never query on
            // render. Cold cache => degrade; the Dashboard tab render fills it.
            $s = get_transient('devdacti_dash_stats');
            if (!is_array($s) || !isset($s['events_24h'])) {
                return array();
            }
            $href = 'admin.php?page=devdome-activity-log';
            $failed = (int) $s['failed_24h'];
            return array(
                array('label' => __('Events 24h', 'devdome-activity-log'),        'value' => (int) $s['events_24h'],      'fmt' => 'int', 'state' => 'idle', 'href' => $href),
                array('label' => __('Failed logins 24h', 'devdome-activity-log'), 'value' => $failed,                     'fmt' => 'int', 'state' => $failed > 0 ? 'urgent' : 'good', 'href' => $href),
                array('label' => __('Active users 7d', 'devdome-activity-log'),   'value' => (int) $s['active_users_7d'], 'fmt' => 'int', 'state' => 'idle', 'href' => $href),
            );
        },
        'health'   => function () {
            $href = admin_url('admin.php?page=devdome-activity-log');

            // A broken events table or a discarded write must never be reported as a healthy
            // log. Hub HARD RULE still holds: the schema verdict is read from the cached probe
            // and the write count from a bounded option — the events table is never queried here.
            $schema = get_transient('devdacti_schema_status');
            if (!is_array($schema) && function_exists('devdacti_schema_status')) {
                $schema = devdacti_schema_status(); // one cached SHOW COLUMNS probe, never the events rows: an expired verdict is not a healthy one
            }
            $broken = is_array($schema) && empty($schema['ok']);
            $writes = devdacti_write_status();
            if (!empty($writes['error'])) {
                return null; // the ledger could not be read: no verdict, never a healthy one
            }
            if ($broken || $writes['failures'] > 0) {
                return array(
                    'score'       => 0,
                    'status'      => 'urgent',
                    'scope_label' => __('Activity', 'devdome-activity-log'),
                    'summary'     => '',
                    'issues'      => array(array(
                        'problem'        => __('Activity is not being recorded reliably', 'devdome-activity-log'),
                        'why_it_matters' => $broken
                            ? (string) $schema['error']
                            : sprintf(
                                /* translators: %d: number of events that could not be written. */
                                _n(
                                    '%d event could not be written to the activity log and is permanently lost.',
                                    '%d events could not be written to the activity log and are permanently lost.',
                                    (int) $writes['failures'],
                                    'devdome-activity-log'
                                ),
                                (int) $writes['failures']
                            ),
                        'fix'            => __('Open the Activity Log screen and check the database status of this site.', 'devdome-activity-log'),
                        'actions'        => array(array('label' => __('Open Activity Log', 'devdome-activity-log'), 'href' => $href)),
                    )),
                );
            }

            // Hub HARD RULE: transient-only read (see tiles above).
            $s = get_transient('devdacti_dash_stats');
            if (!is_array($s) || !isset($s['failed_24h'])) {
                return null;
            }
            // Evidence, not diagnosis: 0.1.2 dropped suite health to 70 for a single
            // mistyped password and named an attack it had no evidence for. Health now
            // moves only past a stated threshold, and the wording states the count.
            $failed     = (int) $s['failed_24h'];
            $assessment = devdacti_failed_login_assessment($failed);
            $issues     = array();
            if ('good' !== $assessment['status']) {
                $issues[] = array(
                    'problem'        => __('Failed login attempts above this site\'s threshold', 'devdome-activity-log'),
                    'why_it_matters' => sprintf(
                        /* translators: 1: number of failed login attempts, 2: the threshold for this site. */
                        __('%1$d failed login attempts were recorded in the last 24 hours, which is above the threshold of %2$d for this site.', 'devdome-activity-log'),
                        $failed,
                        (int) $assessment['threshold']
                    ),
                    'fix'            => __('Review the failed logins, check which accounts were targeted, and strengthen those passwords.', 'devdome-activity-log'),
                    'actions'        => array(array('label' => __('View activity log', 'devdome-activity-log'), 'href' => $href)),
                );
            }
            return array(
                'score'       => $assessment['score'],
                'status'      => $assessment['status'],
                'scope_label' => __('Activity', 'devdome-activity-log'),
                'summary'     => '',
                'issues'      => $issues,
            );
        },
    );
    return $r;
});

// Recent-activity digest section (read-only, from the transient-cached stats).
add_filter('devdcorev1_suite_report_sections', function ($s) {
    if (!function_exists('devdacti_dashboard_stats')) {
        return $s;
    }
    $sum = devdacti_dashboard_stats();
    if (!empty($sum['error'])) {
        // A failed count is not zero activity (same contract as the Overview and the abilities).
        $s[] = array('title' => __('Activity Log', 'devdome-activity-log'), 'lines' => array(__('Activity could not be counted: the log table could not be read.', 'devdome-activity-log')));
        return $s;
    }
    $s[] = array('title' => __('Activity Log', 'devdome-activity-log'), 'lines' => array(
        sprintf(
            /* translators: %s: number of events, already formatted for the locale. */
            __('%s events in the last 24 hours', 'devdome-activity-log'),
            number_format_i18n((int) $sum['events_24h'])
        ),
        sprintf(
            /* translators: %s: number of failed login attempts, already formatted for the locale. */
            __('%s failed login attempts in the last 24 hours', 'devdome-activity-log'),
            number_format_i18n((int) $sum['failed_24h'])
        ),
        sprintf(
            /* translators: %s: number of active users, already formatted for the locale. */
            __('%s active users in the last 7 days', 'devdome-activity-log'),
            number_format_i18n((int) $sum['active_users_7d'])
        ),
    ));
    return $s;
});

// (The shared-core connection shim that used to live here is gone: shared core 1.4.7 owns
// the whole connection contract under devdcorev1_* — it writes its own devdcorev1_connected_at
// on an explicit Connect click. The shim only ever existed to patch a gap in core 1.3.8, and
// it wrote/deleted devdome_connected_at, which is DevDome Analytics' stamp.)

register_activation_hook(__FILE__, 'devdacti_activate');
register_deactivation_hook(__FILE__, 'devdacti_deactivate');

/* DevDome suite self-hosted updates (admin/cron only - never on the front end). The wp.org
 * build drops the updater file from the zip and defines DEVDCOREV1_WPORG_BUILD (wp.org itself
 * delivers updates there), so the whole block is skipped. */
if ( ! defined( 'DEVDCOREV1_WPORG_BUILD' ) && file_exists( __DIR__ . '/includes/class-devdome-suite-updater.php' ) ) {
	require_once __DIR__ . '/includes/class-devdome-suite-updater.php';
	if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		new DEVDACTI_Suite_Updater( __FILE__, 'devdome-activity-log', 'https://api.devdome.com/plugin-updates/devdome-activity-log.json' );
	}
}
