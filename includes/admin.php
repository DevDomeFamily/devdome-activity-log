<?php
/**
 * Admin: menu, scoped enqueue, settings/purge/export handlers (PRG, nonce + the capability
 * that actually matches the power being used), and the tabbed screen.
 *
 * The screen is the suite's admin frame (DEVDOME WORDPRESS PLUGIN DESIGN.md): every panel renders
 * once, the tab bar switches them client-side (3), the header carries the gradient mark and the
 * bug-report button (14), lists are the dashboard table with the standard pager (19 / 21.8),
 * settings are label / control rows with one hint and an info icon (21.4), on/off settings are
 * checkboxes saved by the sticky Save footer (16 / 25), destructive actions type a word (23).
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/devdome-tools-menu.php';

/** The tabs this screen has, in order: slug => array(label, dashicon). */
function devdacti_tabs()
{
    return array(
        'overview' => array(__('Overview', 'devdome-activity-log'), 'dashicons-dashboard'),
        'activity' => array(__('Activity', 'devdome-activity-log'), 'dashicons-list-view'),
        'settings' => array(__('Settings', 'devdome-activity-log'), 'dashicons-admin-generic'),
    );
}

/** The tab a request opens on (the panels all render; this one is shown first). Old slugs still land. */
function devdacti_current_tab()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation state.
    $tab = isset($_GET['al_tab']) && is_string($_GET['al_tab']) ? sanitize_key(wp_unslash($_GET['al_tab'])) : 'overview';
    if ('dashboard' === $tab) {
        $tab = 'overview';
    } elseif ('log' === $tab) {
        $tab = 'activity';
    }
    return array_key_exists($tab, devdacti_tabs()) ? $tab : 'overview';
}

function devdacti_menu()
{
    add_submenu_page(
        defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdcorev1-tools',
        __('DevDome Activity Log', 'devdome-activity-log'),
        __('Activity Log', 'devdome-activity-log'),
        devdacti_capability('read'),
        DEVDACTI_PAGE,
        'devdacti_render_page',
        7
    );
}
add_action('admin_menu', 'devdacti_menu');

function devdacti_enqueue_assets($hook)
{
    // The failure notices (devdacti_admin_notices) print a "Report this error" button on EVERY admin screen, so
    // the shared core's button runtime rides along wherever they show (both reads are cached).
    if (function_exists('devdcorev1_error_report_assets') && devdacti_can('read')) {
        $schema_now = devdacti_schema_status();
        $writes_now = devdacti_write_status();
        if (empty($schema_now['ok']) || (int) $writes_now['failures'] > 0) {
            devdcorev1_error_report_assets();
        }
    }
    if (strpos($hook, DEVDACTI_PAGE) === false) {
        return;
    }
    $css = DEVDACTI_DIR . 'assets/devdome-tools-tw.css';
    $ver = file_exists($css) ? filemtime($css) : DEVDACTI_VERSION;
    wp_enqueue_style('devdacti-ui', DEVDACTI_URL . 'assets/devdome-tools-tw.css', array(), $ver);
    wp_enqueue_style('dashicons');
    wp_add_inline_style('devdacti-ui', devdacti_inline_css());
    if (function_exists('devdcorev1_error_report_assets')) {
        devdcorev1_error_report_assets(); // "Report this error" on every error banner (DESIGN.md 26)
    }

    // One script for the whole screen: tabs, dropdowns, flash banners, typed-word confirm, pending counter and the
    // Activity list controller (it runs only when #al-log is on the page).
    $js = DEVDACTI_DIR . 'assets/al-admin.js';
    $jver = file_exists($js) ? filemtime($js) : DEVDACTI_VERSION;
    wp_enqueue_script('devdacti-admin', DEVDACTI_URL . 'assets/al-admin.js', array(), $jver, true);

    wp_localize_script('devdacti-admin', 'DevdActi', array(
        'root'    => esc_url_raw(rest_url('devdome-activity-log/v1/')),
        'nonce'   => wp_create_nonce('wp_rest'),
        'version' => DEVDACTI_VERSION,
        'perPage' => 20,
        'i18n'    => array(
            'loading'      => __('Loading events...', 'devdome-activity-log'),
            'noResults'    => __('No events matched this filter.', 'devdome-activity-log'),
            'showDetails'  => __('Show Details', 'devdome-activity-log'),
            'hideDetails'  => __('Hide Details', 'devdome-activity-log'),
            'error'        => __('The activity log could not be read.', 'devdome-activity-log'),
            'retry'        => __('Try Again', 'devdome-activity-log'),
            'report'       => __('Report this error', 'devdome-activity-log'),
            'usersFailed'  => __('The user list could not be loaded. Filter by the other fields or reload the page.', 'devdome-activity-log'),
            /* translators: %d: a user ID that is not in the loaded user list. */
            'userById'     => __('User #%d', 'devdome-activity-log'),
            'prevPage'     => __('Previous page', 'devdome-activity-log'),
            'nextPage'     => __('Next page', 'devdome-activity-log'),
            /* translators: %d: page number. */
            'pageN'        => __('Page %d', 'devdome-activity-log'),
            'objectType'   => __('Object type', 'devdome-activity-log'),
            'objectId'     => __('Object ID', 'devdome-activity-log'),
            'subject'      => __('Subject', 'devdome-activity-log'),
            'role'         => __('Role', 'devdome-activity-log'),
            'details'      => __('Details', 'devdome-activity-log'),
            'actorType'    => __('Actor', 'devdome-activity-log'),
            'channel'      => __('Request channel', 'devdome-activity-log'),
            'requestId'    => __('Request ID', 'devdome-activity-log'),
            'severity'     => __('Severity', 'devdome-activity-log'),
            'outcome'      => __('Outcome', 'devdome-activity-log'),
            'firstSeen'    => __('First seen', 'devdome-activity-log'),
            'lastSeen'     => __('Last seen', 'devdome-activity-log'),
            'peerIp'       => __('Connection address', 'devdome-activity-log'),
            'group'        => __('Group', 'devdome-activity-log'),
            'none'         => __('none', 'devdome-activity-log'),
            'usersPartial' => __('Showing the 200 most recent users. Use search for anyone else.', 'devdome-activity-log'),
            'perPage'      => __('Show per page:', 'devdome-activity-log'),
            /* translators: %d: number of times a repeated event occurred. */
            'times'        => __('%d times', 'devdome-activity-log'),
            /* translators: %d: number of events shown on this page. */
            'loaded'       => __('%d events shown.', 'devdome-activity-log'),
            /* translators: 1: first row number on the page, 2: last row number, 3: number of stored rows. */
            'ofTotal'      => __('%1$s-%2$s of %3$s', 'devdome-activity-log'),
            /* translators: %s: number of underlying occurrences behind the rows. */
            'occurrences'  => __('%s occurrences', 'devdome-activity-log'),
            // Machine values become words on the client too, where rows are rendered.
            'labels'       => devdacti_value_labels(),
        ),
        // action => group, so the Activity filters stay consistent (a group change clears the action, an action sets its group).
        'actionGroups' => devdacti_action_groups_map(),
    ));
}
add_action('admin_enqueue_scripts', 'devdacti_enqueue_assets');

/**
 * Prominent, non-dismissible failure notice. A silent audit log is the failure mode this
 * plugin must never have, so a broken table or a discarded write is stated on every admin
 * screen, not left to look like a quiet site.
 */
function devdacti_admin_notices()
{
    if (!devdacti_can('read')) {
        return;
    }

    $schema = devdacti_schema_status();
    if (empty($schema['ok'])) {
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s %s</p></div>',
            esc_html__('DevDome Activity Log:', 'devdome-activity-log'),
            esc_html((string) $schema['error']),
            wp_kses(devdacti_report_button((string) $schema['error'], 'notice'), devdacti_kses_allowed())
        );
    }

    $status = devdacti_write_status();
    if ($status['failures'] > 0) {
        $text = sprintf(
            /* translators: 1: number of discarded events, 2: last database error. */
            __('%1$d events could not be recorded and are permanently lost. Last database error: %2$s', 'devdome-activity-log'),
            $status['failures'],
            $status['last_error'] !== '' ? $status['last_error'] : __('unknown', 'devdome-activity-log')
        );
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s %s</p></div>',
            esc_html__('DevDome Activity Log:', 'devdome-activity-log'),
            esc_html($text),
            wp_kses(devdacti_report_button($text, 'notice'), devdacti_kses_allowed())
        );
    }
}
add_action('admin_notices', 'devdacti_admin_notices');

/** action => group for every catalog action (the Activity filter dropdowns keep each other consistent). */
function devdacti_action_groups_map()
{
    $map = array();
    foreach (devdacti_actions_catalog() as $group => $actions) {
        foreach (array_keys($actions) as $action) {
            $map[$action] = $group;
        }
    }
    return $map;
}

/** The DESIGN.md 26 "Report this error" button for one error text ('' when the shared core is older). */
function devdacti_report_button($error, $screen = '')
{
    if (!function_exists('devdcorev1_error_report_button')) {
        return '';
    }
    return devdcorev1_error_report_button('devdome-activity-log', DEVDACTI_VERSION, (string) $error, (string) $screen);
}

/**
 * Allowed tags for the small markup fragments this plugin prints from a variable (the shared core's "Report this
 * error" button, the severity badge, the state badge after an option word): the wp_kses() allow-list at every such echo.
 */
function devdacti_kses_allowed()
{
    return array(
        'svg'    => array('width' => true, 'height' => true, 'viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true),
        'path'   => array('d' => true),
        'span'   => array('class' => true, 'style' => true, 'id' => true, 'title' => true),
        'button' => array('type' => true, 'class' => true, 'data-ddc-report' => true),
    );
}

/** The plugin's own mark (lucide "list"): header badge at 20 px, Overview circle at 84 px. */
function devdacti_icon_svg($size = 20)
{
    $size = (int) $size;
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/></svg>';
}

/** wp_kses allow-list for the inline SVG marks. */
function devdacti_kses_svg()
{
    return array(
        'svg'  => array('width' => true, 'height' => true, 'viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true, 'class' => true),
        'path' => array('d' => true),
    );
}

/**
 * The screen's own CSS, attached to the enqueued stylesheet (never a literal style tag in output).
 * Blocks are the suite's shared ones (Admin Cleaner / Bot Protection / Malware Scanner), prefix al-.
 */
function devdacti_inline_css()
{
    return '
        .dd-app .pt-3 { padding-top:.75rem; }
        .dd-app .pb-6 { padding-bottom:1.5rem; }
        .dd-app .py-6 { padding-top:1.5rem; padding-bottom:1.5rem; }
        .dd-app .space-y-8 > * + * { margin-top:2rem; }
        .dd-app h1.text-xl { font-size:1.25rem; line-height:1.75rem; font-weight:700; }
        .dd-app .dd-tabpanel { display:none; }
        .dd-app .dd-tabpanel.is-active { display:block; }
        .dd-app .dd-tab:focus, .dd-app .dd-tab:focus-visible { outline:none; box-shadow:none; }
        .dd-app .dd-tabs { overflow-x:auto; flex-wrap:nowrap; scrollbar-width:thin; }
        .dd-app .dd-tabs .dd-tab { white-space:nowrap; flex:none; }
        .dd-app .dd-tab { color:#374151; }
        .dd-app .dd-tab.is-active { color:#4338ca; }
        .dd-app .dd-btn .dashicons, .dd-app .dd-btn-primary .dashicons { margin-right:5px; }
        .dd-app .dd-btn:disabled, .dd-app .dd-btn-primary:disabled { opacity:.45; cursor:not-allowed; }
        /* Header bug-report button: exact twin of the DevDome dashboard button; the export icon reuses it (section 13). */
        .dd-app .al-bug-btn { width:36px; height:36px; border-radius:50px; border:1px solid #dadce0; background:#fff; display:grid; place-items:center; cursor:pointer; color:#5f6368; transition:all .2s; text-decoration:none; padding:0; }
        .dd-app .al-bug-btn:hover { background:#f8fbff; border-color:#1967d2; color:#1967d2; }
        .dd-app .al-bug-btn svg { width:16px; height:16px; }
        .dd-app .al-bug-btn:focus { outline:none; box-shadow:none; }
        .dd-app .al-act-headbtns { display:none; align-items:center; gap:10px; }
        .dd-app[data-al-tab="activity"] .al-act-headbtns { display:inline-flex; }
        /* Intro texts two thirds wide at most (22.2). */
        .dd-app .al-intro { font-size:13px; color:#6b7280; margin:0 0 14px; max-width:66%; }
        /* Overview hero: status circle (31) left, verdict right, tiles below (Bot Protection .bp-verdict 1:1). */
        .dd-app .al-verdict { display:flex; flex-wrap:wrap; align-items:center; gap:24px; }
        .dd-app .al-verdict-txt { flex:1; min-width:260px; }
        .dd-app .al-verdict-h { display:block; margin:0 0 4px; font-size:1.125rem; line-height:1.75rem; font-weight:600; color:#1f2937; }
        .dd-app .al-verdict-p { display:block; font-size:13px; color:#374151; margin:0; }
        .dd-app .al-status { width:160px; height:160px; border-radius:50%; display:grid; place-items:center; flex:none; border:12px solid #e5e7eb; box-sizing:border-box; color:#fff; }
        .dd-app .al-status svg { width:84px; height:84px; }
        .dd-app .al-status.is-good { border-color:#a7f3d0; background:#10b981; }
        .dd-app .al-status.is-medium { border-color:#fde68a; background:#f59e0b; }
        .dd-app .al-status.is-high { border-color:#fed7aa; background:#f97316; }
        .dd-app .al-status.is-critical { border-color:#fecaca; background:#ef4444; }
        .dd-app .al-status.is-unknown { border-color:#e5e7eb; background:#9ca3af; }
        .dd-app #al-verdict .dd-stats { margin-top:22px; }
        .dd-app .al-stats-4 { grid-template-columns:repeat(4,minmax(0,1fr)); }
        @media (max-width:900px) { .dd-app .al-stats-4 { grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); } }
        /* Data lists = DESIGN.md section 19 (.dd-list): the Analytics dashboard table, 1:1. */
        .dd-app .dd-list-wrap { overflow-x:auto; border-top:1px solid #e5e7eb; position:relative; } /* contains the absolute screen-reader-text spans: they widened the page at 400px (22.5) */
        .dd-app .dd-list { width:100%; min-width:560px; border-collapse:separate; border-spacing:0; } /* narrow screens: the table scrolls inside .dd-list-wrap, the Event column never collapses (19) */
        .dd-app #al-table { min-width:760px; }
        .dd-app .dd-list thead th { width:auto; background:#f9fafb; padding:8px 12px; text-align:left; font-size:12px; line-height:16px; font-weight:500; color:#374151; letter-spacing:.05em; white-space:nowrap; border-bottom:1px solid #e5e7eb; border-right:1px solid #e5e7eb; text-transform:none; }
        .dd-app .dd-list tbody td { padding:10px 12px; font-size:13px; line-height:18px; color:#374151; vertical-align:middle; border-bottom:1px solid #e5e7eb; border-right:1px solid #e5e7eb; }
        .dd-app .dd-list th:last-child, .dd-app .dd-list td:last-child { border-right:0; }
        .dd-app .dd-list tbody tr:last-child td { border-bottom:0; }
        .dd-app .dd-list tbody tr:hover td { background:#f8fbff; }
        .dd-app .dd-list tbody.is-loading { opacity:.55; }
        .dd-app .al-list-card { padding:0; } /* no overflow clip: the per-page menu opens upward past a short card (21.8) */
        .dd-app .al-list-card .dd-list-wrap { border-top:0; border-radius:12px 12px 0 0; }
        .dd-app .al-list-card .dd-list-wrap:last-child { border-radius:12px; }
        .dd-app .al-col-pill { width:1%; white-space:nowrap; padding-right:10px; vertical-align:top; }
        .dd-app .al-col-num { width:1%; white-space:nowrap; font-weight:700; color:#111827; }
        .dd-app .al-col-exp { width:1%; white-space:nowrap; text-align:right; }
        .dd-app .al-title { display:block; font-weight:600; color:#111827; }
        .dd-app .al-meta { display:block; margin-top:3px; font-size:13px; color:#374151; white-space:normal; overflow-wrap:anywhere; }
        .dd-app .al-meta b { color:#111827; }
        .dd-app .al-toggle { background:none; border:0; padding:4px; cursor:pointer; color:#6b7280; min-width:32px; min-height:32px; border-radius:6px; }
        .dd-app .al-toggle:hover { background:#f3f4f6; color:#111827; }
        .dd-app .al-toggle:focus-visible { outline:2px solid #4f46e5; outline-offset:1px; }
        /* Detail panel (21.3): one fact per line, label 110 px uppercase grey, full values. */
        .dd-app .al-detail td { background:#f9fafb; }
        .dd-app .al-facts { margin:0; padding:0; list-style:none; }
        .dd-app .al-facts li { display:flex; gap:12px; padding:3px 0; font-size:12.5px; }
        .dd-app .al-fact-k { flex:0 0 110px; text-transform:uppercase; letter-spacing:.04em; font-size:11px; color:#6b7280; padding-top:1px; }
        .dd-app .al-fact-v { color:#111827; overflow-wrap:anywhere; }
        /* Badges: section-18 colours, 10px bold uppercase pills. */
        .dd-app .al-badge { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 7px; border-radius:999px; white-space:nowrap; }
        .dd-app .al-badge-bad { background:#fef2f2; color:#dc2626; }
        .dd-app .al-badge-warn { background:#fffbeb; color:#b45309; }
        .dd-app .al-badge-info { background:#eef2ff; color:#4338ca; }
        .dd-app .al-badge-muted { background:#f3f4f6; color:#374151; }
        .dd-app .al-grouptag { display:inline-block; font-size:10px; font-weight:600; padding:1px 6px; border-radius:999px; background:#f3f4f6; color:#6b7280; margin-left:6px; vertical-align:middle; }
        /* List toolbar (21.2): filters above the card, search on the right. */
        .dd-app .al-toolbar-row { margin:0 0 10px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .dd-app .al-toolbar-row .dd-dd { width:170px; }
        .dd-app .al-filters .dd-dd { flex:1 1 0; width:auto; min-width:0; } /* the five filters share the whole row, no gap on the right */
        .dd-app .al-search-wrap { position:relative; margin-left:auto; }
        .dd-app .al-search-wrap .dashicons { position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:16px; width:16px; height:16px; color:#6b7280; pointer-events:none; }
        .dd-app .al-search-wrap .al-search { padding-left:32px; }
        /* Retention Status: one info pill per fact (dd-pill, indigo), red for an error. */
        .dd-app .al-pills { display:flex; flex-direction:column; align-items:flex-start; gap:8px; } /* one pill per line (owner 13 Oct) */
        .dd-app .dd-sec-head .dd-tip { margin-left:2px; } /* the section intro lives in the title info icon */
        .dd-app .al-pill-info { border-color:#c7d2fe; background:#eef2ff; color:#4338ca; }
        .dd-app .al-pill-info b { font-weight:700; }
        .dd-app .al-pill-bad { border-color:#fecaca; background:#fef2f2; color:#b91c1c; }
        .dd-app .al-pills .dashicons { font-size:14px; width:14px; height:14px; }
        .dd-app .al-toolbar-row .dd-input { height:34px; font-size:13px; }
        .dd-app .al-search { width:260px; }
        /* From / To = Redirect Manager "Campaign Dates" 1:1: the word, then the date input (34px, #9ca3af, 8px). */
        .dd-app .al-date-lbl { display:inline-flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:#374151; margin:0; }
        .dd-app .al-date-lbl + .al-date-lbl { margin-left:10px; }
        .dd-app .al-date { height:34px; padding:0 10px; border:1px solid #9ca3af; border-radius:8px; min-height:0; font-size:13px; color:#374151; background:#fff; box-shadow:0 1px 2px 0 rgba(0,0,0,.05); }
        .dd-app .al-toolbar-row .dd-btn-primary { white-space:nowrap; flex-shrink:0; padding:8px 16px; }
        /* Pagination bar, Link Monitor / Malware Scanner 1:1 (21.8). */
        .dd-app .al-pagination { display:flex; flex-wrap:nowrap; align-items:center; justify-content:space-between; gap:8px; padding:10px 12px; border-top:1px solid #e5e7eb; }
        .dd-app .al-pagination:empty { display:none; }
        .dd-app .al-pg-count { white-space:nowrap; font-size:12.5px; color:#3c4043; }
        .dd-app .al-pg-right { display:flex; flex-wrap:nowrap; align-items:center; gap:12px; flex:none; }
        .dd-app .al-pg-per { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:500; color:#3c4043; }
        .dd-app .al-pg-btns { display:flex; align-items:center; gap:4px; }
        @media (max-width:600px) { .dd-app .al-pagination, .dd-app .al-pg-right { flex-wrap:wrap; min-width:0; } } /* after the desktop nowrap rules, so it wins */
        .dd-app .page-btn { min-width:30px; height:30px; padding:0 8px; border-radius:6px; border:1px solid #dadce0; background:#fff; color:#3c4043; font-family:inherit; font-size:13px; font-weight:500; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:background .12s, border-color .12s, color .12s; }
        .dd-app .page-btn:hover:not(:disabled) { background:#f8fbff; border-color:#1967d2; color:#1967d2; }
        .dd-app .page-btn.active { background:#1967d2; border-color:#1967d2; color:#fff; }
        .dd-app .page-btn:disabled { color:#bdc1c6; cursor:not-allowed; }
        .dd-app .page-btn:focus { outline:none; box-shadow:none; }
        .dd-app .page-gap { color:#94a3b8; padding:0 4px; font-size:13px; user-select:none; }
        .dd-app .page-jump-wrap { position:relative; display:block; }
        .dd-app .page-jump-menu { position:absolute; bottom:calc(100% + 6px); right:0; width:78px; max-height:336px; overflow-y:auto; overscroll-behavior:contain; background:#fff; border:1px solid #dadce0; border-radius:10px; box-shadow:0 12px 32px rgba(15,23,42,.14), 0 2px 8px rgba(15,23,42,.06); padding:4px; z-index:40; display:flex; flex-direction:column; }
        .dd-app .page-jump-menu.hidden { display:none; }
        .dd-app .pjm-item { border:0; background:none; font-family:inherit; font-size:13px; font-weight:500; padding:8px 10px; border-radius:6px; cursor:pointer; color:#3c4043; text-align:center; flex:none; }
        .dd-app .pjm-item:hover { background:#f8fafc; }
        .dd-app .pjm-item.active { background:#e8f0fe; color:#1967d2; font-weight:700; }
        /* Custom dropdown (.dd-dd), the shared component (Admin Cleaner / Malware Scanner 1:1). */
        .dd-app .dd-dd { position:relative; display:inline-block; }
        .dd-app .dd-dd-trigger { display:flex; height:34px; width:100%; cursor:pointer; user-select:none; align-items:center; justify-content:space-between; gap:.5rem; border-radius:.5rem; border:1px solid #9ca3af; background-color:#fff; padding:.375rem .75rem; font-size:13px; font-weight:600; color:#374151; box-shadow:0 1px 3px 0 rgba(0,0,0,.1),0 1px 2px -1px rgba(0,0,0,.1); transition:all .15s cubic-bezier(.4,0,.2,1); }
        .dd-app .dd-dd.is-open .dd-dd-trigger { border-color:#6366f1; box-shadow:0 0 0 2px rgba(99,102,241,.2),0 1px 3px 0 rgba(0,0,0,.1); }
        .dd-app .dd-dd-label { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .dd-app .dd-dd-chev { height:1rem; width:1rem; flex-shrink:0; color:#6b7280; transition:transform .2s cubic-bezier(.4,0,.2,1); }
        .dd-app .dd-dd.is-open .dd-dd-chev { transform:rotate(180deg); }
        .dd-app .dd-dd-panel { position:absolute; top:100%; left:0; z-index:200; margin-top:.25rem; display:none; max-height:420px; width:max-content; min-width:100%; max-width:min(420px, calc(100vw - 48px)); overflow-y:auto; overflow-x:hidden; border-radius:.5rem; border:1px solid #d1d5db; background-color:#fff; padding:.25rem 0; box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 8px 10px -6px rgba(0,0,0,.1); }
        .dd-app .dd-dd.is-open .dd-dd-panel { display:block; }
        .dd-app .dd-dd-opt { cursor:pointer; white-space:normal; overflow-wrap:anywhere; padding:.5rem 1rem; font-size:13px; color:#374151; transition:color .15s,background-color .15s; }
        .dd-app .dd-dd-opt.is-selected, .dd-app .dd-dd-opt:hover, .dd-app .dd-dd-opt:focus { background-color:#eef2ff; outline:none; }
        .dd-app .dd-dd-opt.is-selected { font-weight:500; color:#4338ca; }
        .dd-app .dd-dd-opt.is-disabled { opacity:.45; cursor:not-allowed; background:none; }
        .dd-app .dd-dd.is-disabled { opacity:.6; pointer-events:none; }
        /* Settings rows (section 4 / 21.6): label cell, control, ONE hint line + the info icon. */
        .dd-app .dd-th { color:#1f2937; }
        .dd-app .dd-td .dd-hint { margin:6px 0 0; }
        .dd-app .dd-td .dd-input, .dd-app .dd-td .dd-textarea { max-width:100%; }
        .dd-app .al-num { width:80px; text-align:center; }
        .dd-app .dd-tip { margin-left:4px; }
        .dd-app .dd-tip .dd-tip-box { display:none; }
        .dd-app .dd-tip:hover .dd-tip-box, .dd-app .dd-tip:focus .dd-tip-box, .dd-app .dd-tip:focus-within .dd-tip-box { display:block; visibility:visible; opacity:1; }
        .dd-app .dd-tip:focus { outline:none; }
        .dd-app .dd-tip:focus-visible .dashicons { color:#4f46e5; outline:2px solid #c7d2fe; outline-offset:1px; border-radius:50%; }
        /* On/off rows (25, Country Blocker devdcoun_setting_row 1:1): the title in the label cell, the checkbox + its word + the hint in the control cell. */
        .dd-app .dd-td .dd-opt { font-size:13px; }
        .dd-app .dd-td .dd-opt .dd-check { margin:0; }
        /* A list of checkable items in one control cell (Analytics "Excluded roles" .da-optlist 1:1). */
        .dd-app .al-optlist { display:block; }
        .dd-app .al-optlist .dd-opt { display:flex; align-items:center; gap:6px; margin:0 0 8px; }
        .dd-app .al-optlist .dd-opt:last-child { margin-bottom:0; }
        .dd-app .al-protected { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 7px; border-radius:999px; background:#eef2ff; color:#4338ca; margin-left:6px; vertical-align:middle; }
        .dd-app .al-status-line { margin:8px 0 0; font-size:13px; color:#374151; }
        .dd-app .al-status-line b { color:#111827; }
        .dd-app details.al-more > summary { cursor:pointer; font-weight:600; color:#1f2937; font-size:13px; width:max-content; }
        .dd-app details.al-more[open] > summary { margin-bottom:10px; }
        /* Typed-word confirmation (DESIGN.md 23, Admin Cleaner 1:1): the button hides, the panel asks for the word. */
        .dd-app .al-typed-confirm { margin-top:8px; border-top:1px solid #f3f4f6; padding-top:14px; }
        .dd-app .al-typed-warn { margin:0 0 8px; font-weight:600; color:#374151; }
        .dd-app .al-typed-ask { margin:0 0 8px; color:#374151; }
        .dd-app .al-typed-input { max-width:240px; margin-bottom:10px; }
        .dd-app .al-typed-btns { display:flex; gap:10px; }
        /* Sticky save footer = the suite footer (16 / 16.1). */
        .dd-app .dd-footer[hidden], .dd-app .dd-saved[hidden], .dd-app .dd-pending[hidden] { display:none !important; }
        .dd-app .dd-pending { font-size:13px; font-weight:600; color:#b45309; }
        /* Banners: one row, icon + text + the report button. */
        .dd-app .dd-banner { display:flex; align-items:center; flex-wrap:wrap; gap:10px; }
        .dd-app .dd-banner ul { flex-basis:100%; }
        /* DevDome account card = DESIGN.md 30 (copied, prefix changed). */
        .dd-app .al-conn { display:flex; align-items:center; gap:16px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; margin-bottom:12px; box-shadow:0 1px 2px rgba(0,0,0,.05); flex-wrap:wrap; }
        .dd-app .al-conn-ci { display:inline-flex; align-items:center; justify-content:center; width:46px; height:46px; border-radius:12px; background:linear-gradient(150deg,#3b82f6,#2563eb 55%,#1d4ed8); box-shadow:0 7px 15px -6px rgba(37,99,235,.6); flex:none; color:#fff; font-weight:800; letter-spacing:-1px; font-size:18px; }
        .dd-app .al-conn-body { flex:1 1 320px; min-width:0; }
        .dd-app .al-conn-body strong { display:block; font-size:14.5px; font-weight:700; color:#0f172a; }
        .dd-app .al-conn-body span { font-size:12.5px; color:#475569; line-height:1.45; }
        .dd-app .al-conn-body a { color:#2563eb; text-decoration:underline; }
        .dd-app .al-conn-ctl { display:flex; flex-direction:column; gap:8px; flex:0 1 272px; }
        .dd-app .al-conn-ctl form { margin:0; }
        .dd-app .al-conn-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; font-size:14px; font-weight:600; border-radius:8px; padding:9px 14px; width:100%; text-decoration:none; cursor:pointer; line-height:1; white-space:nowrap; transition:.12s; color:#fff; background:#2563eb; border:1px solid #2563eb; box-shadow:0 4px 10px -3px rgba(37,99,235,.5); }
        .dd-app .al-conn-btn:hover { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
        .dd-app .al-conn-hint { font-size:11px; color:#8a94a6; text-align:center; margin:0; }
        @media (max-width:600px) { .dd-app .al-conn { padding:12px; gap:10px; } .dd-app .al-conn-body { flex-basis:100%; } .dd-app .al-conn-ctl { flex:1 1 100%; } .dd-app .al-conn-btn { white-space:normal; line-height:1.25; padding:9px 10px; } }
        /* Readability (suite pass): same sizes, darker text. */
        .dd-app .dd-hint, .dd-app .description, .dd-app .dd-stat-lbl, .dd-app .dd-empty, .dd-app .dd-list thead th { color:#374151; }
        .dd-app .screen-reader-text { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(1px,1px,1px,1px); word-wrap:normal; }
        @media (max-width:782px) {
            .dd-app .al-intro { max-width:100%; }
            .dd-app .al-search-wrap { margin-left:0; width:100%; }
            .dd-app .al-search { width:100%; }
            .dd-app .al-toolbar-row .dd-dd, .dd-app .al-filters .dd-dd { flex:1 1 100%; width:100%; }
        }
        @media (max-width:600px) { .dd-app .dd-footer-actions { flex-shrink:1; min-width:0; } .dd-app .dd-table .dd-th, .dd-app .dd-table .dd-td { display:block; width:auto; } .dd-app .dd-table .dd-th { padding-bottom:4px; } .dd-app .al-date-lbl + .al-date-lbl { margin-left:0; } }
        @media (prefers-reduced-motion:reduce) {
            .dd-app * { transition:none !important; animation:none !important; }
        }
    ';
}

/* ----------------------------- save handlers ---------------------------- */

/**
 * Read the posted settings form into a validated policy array. Pure: it writes nothing, so
 * the before/after diff, the weakening check and the audit records all see the same values.
 */
function devdacti_posted_policy(array $post)
{
    // Digits only: "<b>365</b>" or "1<b>2</b>" is NOT a number (a cast would read 0 or 1). The save handler refuses
    // such input before this runs; here anything that is not 1-4 digits in range falls back to the default.
    $raw_days  = isset($post['al_retention_days']) ? trim((string) $post['al_retention_days']) : '';
    $retention = preg_match('/^\d{1,4}$/', $raw_days) ? (int) $raw_days : 90;
    if (!devdacti_valid_retention($retention)) {
        $retention = 90;
    }

    $roles_posted = array();
    if (isset($post['al_excluded_roles']) && is_array($post['al_excluded_roles'])) {
        $roles_posted = array_map('sanitize_key', wp_unslash($post['al_excluded_roles']));
    }
    $roles = array_values(array_intersect($roles_posted, array_keys(wp_roles()->roles)));

    $users_raw = isset($post['al_excluded_users']) ? sanitize_textarea_field(wp_unslash($post['al_excluded_users'])) : '';
    $users = array();
    foreach (preg_split('/\r\n|\r|\n/', $users_raw) as $line) {
        $login = sanitize_user(trim($line));
        if ($login !== '') {
            $users[] = $login;
        }
    }

    $proxies_raw = isset($post['al_trusted_proxies']) ? sanitize_textarea_field(wp_unslash($post['al_trusted_proxies'])) : '';
    $header      = isset($post['al_forwarded_header']) ? sanitize_text_field(wp_unslash($post['al_forwarded_header'])) : '';
    if (!isset(devdacti_forwarded_headers()[$header])) {
        $header = '';
    }

    $values = array(
        'retention_days'   => $retention,
        'anonymize_ip'     => empty($post['al_anonymize_ip']) ? 0 : 1,
        'protect_critical' => empty($post['al_protect_critical']) ? 0 : 1,
        'trusted_proxies'  => $proxies_raw,
        'forwarded_header' => $header,
        'excluded_roles'   => $roles,
        'excluded_users'   => array_values(array_unique($users)),
    );
    foreach (array('auth', 'content', 'plugins', 'users', 'media', 'comments', 'settings') as $group) {
        $values['group_' . $group] = empty($post['al_group_' . $group]) ? 0 : 1;
    }

    return $values;
}

/**
 * Settings tab save (self-POST, nonce + the MANAGE capability, PRG).
 *
 * Three things 0.1.2 did not do: it used the read capability, it performed ten unchecked
 * writes and always said "Settings saved.", and it left no trace whatsoever that the audit
 * policy had changed: a privileged user could switch logging off invisibly.
 */
function devdacti_handle_settings_save()
{
    if (empty($_POST['devdacti_settings_save'])) {
        return;
    }
    if (!devdacti_can('manage')) {
        wp_die(esc_html__('You do not have permission to change the Activity Log policy.', 'devdome-activity-log'), '', array('response' => 403));
    }
    check_admin_referer('devdacti_settings', '_alsn');
    devdacti_db_guard_begin(); // DESIGN.md 24: a failed query inside this action ends it with the database banner, never "Saved."

    // A blank, non-numeric or out-of-range retention is refused outright (it used to become 90 days silently).
    // The RAW string is validated (not a tag-stripped copy: "<b>365</b>" must be refused, never read as 365) and the
    // validated integer is what the policy builder receives, so the check and the stored value can never disagree.
    $raw_retention = isset($_POST['al_retention_days']) ? trim((string) wp_unslash($_POST['al_retention_days'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; validated by the regex on the next line and used only as an integer.
    if (!preg_match('/^\d{1,4}$/', $raw_retention) || !devdacti_valid_retention((int) $raw_retention)) {
        devdacti_finish(array('page' => DEVDACTI_PAGE, 'al_tab' => 'settings', 'al_invalid' => 'retention'));
    }

    $posted = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_admin_referer above.
    $posted['al_retention_days'] = (int) $raw_retention;
    $after = devdacti_posted_policy($posted);

    // Reducing audit coverage is a decision, not a checkbox. Nothing is written until it has been acknowledged
    // explicitly. The same shared writer the update-settings ability uses (one source of truth).
    $r = devdacti_apply_policy($after, !empty($_POST['al_confirm_weaken'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
    if (!empty($r['needs_confirm'])) {
        // The proposed values travel with the warning, so the form comes back filled in and "tick and save again" saves
        // what was typed, not the stored policy. Per user, five minutes.
        set_transient('devdacti_pending_weaken_' . get_current_user_id(), array('weakenings' => $r['weakenings'], 'policy' => $after), 5 * MINUTE_IN_SECONDS);
        devdacti_finish(array('page' => DEVDACTI_PAGE, 'al_tab' => 'settings', 'al_confirm' => '1'));
    }

    delete_transient('devdacti_pending_weaken_' . get_current_user_id());

    $args = array('page' => DEVDACTI_PAGE, 'al_tab' => 'settings');
    if ($r['failed'] > 0) {
        $args['al_failed'] = (int) $r['failed'];
    } elseif (!empty($r['audit_failed'])) {
        $args['al_audit_failed'] = (int) $r['audit_failed']; // stored, but not recorded: said out loud, never "Saved."
    } else {
        $args['al_saved'] = '1';
    }
    devdacti_finish($args);
}
add_action('admin_init', 'devdacti_handle_settings_save');

/**
 * Manual retention purge. Its own capability, its own nonce, an explicit confirmation, and
 * an unsuppressible record of what it deleted.
 */
function devdacti_handle_purge()
{
    if (empty($_POST['devdacti_purge_now'])) {
        return;
    }
    if (!devdacti_can('purge')) {
        wp_die(esc_html__('You do not have permission to delete activity log records.', 'devdome-activity-log'), '', array('response' => 403));
    }
    check_admin_referer('devdacti_purge_now', '_alpn');

    // DESIGN.md 23: the typed word is the only gate, and the server checks it too.
    $word = isset($_POST['al_word']) ? strtoupper(trim(sanitize_text_field(wp_unslash($_POST['al_word'])))) : '';
    if ('DELETE' !== $word) {
        wp_safe_redirect(add_query_arg(
            array('page' => DEVDACTI_PAGE, 'al_tab' => 'settings', 'al_purge_unconfirmed' => '1'),
            admin_url('admin.php')
        ));
        exit;
    }

    devdacti_db_guard_begin();
    $result = devdacti_purge_cron();
    $args   = array('page' => DEVDACTI_PAGE, 'al_tab' => 'settings', 'al_purged' => (int) $result['deleted']);
    if ('' !== (string) $result['error']) {
        unset($args['al_purged']);
        $args['al_purge_error'] = '1'; // the purge's own error is on the Retention card (devdacti_retention_status)
    }
    devdacti_finish($args);
}
add_action('admin_init', 'devdacti_handle_purge');

/**
 * Re-apply the storage schema. Separate capability from purge (a repair adds structure, a
 * purge destroys evidence), nonce-checked, and it refuses without an explicit confirmation
 * because the ALTER can lock a large table.
 */
function devdacti_handle_repair()
{
    if (empty($_POST['devdacti_repair_now'])) {
        return;
    }
    if (!devdacti_can('repair')) {
        wp_die(esc_html__('You do not have permission to repair the activity log storage.', 'devdome-activity-log'), '', array('response' => 403));
    }
    check_admin_referer('devdacti_repair', '_alrn');

    if (empty($_POST['al_confirm_repair'])) {
        wp_safe_redirect(add_query_arg(
            array('page' => DEVDACTI_PAGE, 'al_tab' => 'settings', 'al_repair_unconfirmed' => '1'),
            admin_url('admin.php')
        ));
        exit;
    }

    devdacti_db_guard_begin();
    $result = devdacti_repair_schema();
    devdacti_finish(array(
        'page'        => DEVDACTI_PAGE,
        'al_tab'      => 'settings',
        'al_repaired' => empty($result['after']['ok']) ? '0' : '1',
    ));
}
add_action('admin_init', 'devdacti_handle_repair');

/** The export's filter set, read from the request exactly like GET /events reads its own. */
function devdacti_export_filters(array $get)
{
    return array(
        'user'        => isset($get['user']) ? (int) $get['user'] : 0,
        'group'       => isset($get['group']) ? sanitize_key(wp_unslash($get['group'])) : '',
        'action'      => isset($get['action']) ? sanitize_key(wp_unslash($get['action'])) : '',
        'object_type' => isset($get['object_type']) ? sanitize_key(wp_unslash($get['object_type'])) : '',
        'severity'    => isset($get['severity']) ? sanitize_key(wp_unslash($get['severity'])) : '',
        'from'        => isset($get['from']) ? sanitize_text_field(wp_unslash($get['from'])) : '',
        'to'          => isset($get['to']) ? sanitize_text_field(wp_unslash($get['to'])) : '',
        'search'      => isset($get['search']) ? sanitize_text_field(wp_unslash($get['search'])) : '',
    );
}

/** Short human description of an export's filters, for the audit record. */
function devdacti_filters_summary(array $filters)
{
    $parts = array();
    foreach ($filters as $key => $value) {
        if ('' !== $value && 0 !== $value) {
            $parts[] = $key . '=' . $value;
        }
    }
    return $parts ? implode(', ', $parts) : __('no filters (everything)', 'devdome-activity-log');
}

/** The CSV column order. One list so the header row and the data rows cannot drift apart. */
function devdacti_export_columns()
{
    return array(
        'date_utc', 'first_seen', 'last_seen', 'severity', 'outcome', 'action',
        'actor_login', 'actor_role', 'actor_type', 'channel', 'request_id',
        'client_ip', 'peer_ip', 'subject_type', 'subject_id', 'subject_title',
        'details', 'occurrences', 'site_id',
    );
}

/** One CSV row, every cell neutralised. */
function devdacti_export_row(array $r)
{
    return array_map('devdacti_csv_guard', array(
        (string) $r['created_at'],
        (string) $r['first_seen'],
        (string) $r['last_seen'],
        (string) $r['severity'],
        (string) $r['outcome'],
        (string) $r['action'],
        (string) $r['user_login'],
        (string) $r['user_role'],
        (string) $r['actor_type'],
        (string) $r['channel'],
        (string) $r['request_id'],
        (string) $r['ip'],
        (string) $r['peer_ip'],
        (string) $r['object_type'],
        (string) $r['object_id'],
        (string) $r['object_title'],
        (string) $r['details'],
        (string) $r['counter'],
        (string) $r['site_id'],
    ));
}

/** Rows one export may stream before it is deliberately cut short. */
function devdacti_export_row_cap()
{
    return (int) apply_filters('devdacti_export_row_cap', 200000);
}

/**
 * CSV export (nonce-guarded admin-page GET so the browser downloads natively).
 *
 * Uses its own EXPORT capability, validates the filters before doing any work, and reads
 * with a keyset cursor inside a snapshot: 0.1.2 paged by OFFSET up to 1,000,000 rows while
 * repeating COUNT(*) for every page, so a busy site could see rows duplicated or skipped as
 * new events shifted the pages underneath it.
 */
function devdacti_handle_export()
{
    if (empty($_GET['devdacti_export'])) {
        return;
    }
    if (!devdacti_can('export')) {
        wp_die(esc_html__('You do not have permission to export the activity log.', 'devdome-activity-log'), '', array('response' => 403));
    }
    check_admin_referer('devdacti_export', '_alxn');

    $filters = devdacti_export_filters($_GET); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by check_admin_referer above.

    $invalid = devdacti_validate_query($filters);
    if ($invalid) {
        $messages = array();
        foreach ($invalid as $e) {
            $messages[] = $e['message'];
        }
        wp_die(
            esc_html(implode(' ', $messages)),
            esc_html__('Export failed', 'devdome-activity-log'),
            array('response' => 400, 'back_link' => true)
        );
    }

    // Pre-flight before any header is sent: a broken table must produce a visible error, not
    // a downloaded file with a header row and nothing in it.
    $schema = devdacti_schema_status();
    if (empty($schema['ok'])) {
        wp_die(
            esc_html__('The activity log could not be read, so no export was produced.', 'devdome-activity-log'),
            esc_html__('Export failed', 'devdome-activity-log'),
            array('response' => 500, 'back_link' => true)
        );
    }

    $snapshot = devdacti_events_max_id();
    if ($snapshot < 0) {
        // No snapshot, no export. Streaming without a bound would produce a file whose rows
        // shift under concurrent writes: a partial export that looks complete.
        wp_die(
            esc_html__('The activity log could not be read, so no export was produced. Check the Storage section on the Settings tab and try again.', 'devdome-activity-log'),
            esc_html__('Export failed', 'devdome-activity-log'),
            array('response' => 500, 'back_link' => true)
        );
    }

    // Personal data is about to leave the site. That is itself an audited event, and it is
    // recorded before the download starts, not after (the client can abandon the stream), but
    // only once the snapshot exists: a refused export is not recorded as an export.
    devdacti_log_audit(
        'log_exported',
        __('CSV export', 'devdome-activity-log'),
        sprintf(
            /* translators: %s: the filters applied to the export. */
            __('Activity log exported to CSV with filters: %s', 'devdome-activity-log'),
            devdacti_filters_summary($filters)
        )
    );

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=activity-log-' . gmdate('Ymd') . '.csv');
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('X-Content-Type-Options: nosniff');

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming the CSV straight to php://output.
    $out = fopen('php://output', 'w');
    if (false === $out) {
        // Headers are out; say so in the body rather than send an empty 200.
        echo esc_html__('EXPORT FAILED: the output stream could not be opened.', 'devdome-activity-log');
        exit;
    }

    // UTF-8 BOM so Excel opens non-ASCII usernames correctly; documented, and it never
    // precedes a formula because every cell is neutralised independently.
    echo "\xEF\xBB\xBF";
    if (false === fputcsv($out, devdacti_export_columns())) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream.
        fclose($out);
        echo esc_html__('EXPORT FAILED: the download stream would not accept the header row.', 'devdome-activity-log');
        exit;
    }

    $cursor_created = '';
    $cursor_id      = 0;
    $per            = 1000;
    $written        = 0;
    $incomplete     = '';
    $cap            = devdacti_export_row_cap();

    do {
        $page_filters = array_merge($filters, array(
            'per_page'       => $per,
            'skip_total'     => true,
            'max_id'         => $snapshot,
            'cursor_created' => $cursor_created,
            'cursor_id'      => $cursor_id,
        ));
        $result = devdacti_query_events($page_filters);
        if (!empty($result['error'])) {
            $incomplete = __('EXPORT INCOMPLETE: the activity log could not be read past this point.', 'devdome-activity-log');
            break;
        }

        // An abandoned download must stop querying: without this the loop keeps paging the
        // whole table into a socket nobody is reading.
        if (1 === connection_aborted()) {
            break;
        }

        $count = count($result['rows']);
        foreach ($result['rows'] as $r) {
            if (false === fputcsv($out, devdacti_export_row($r))) {
                $incomplete = __('EXPORT INCOMPLETE: the download stream stopped accepting rows.', 'devdome-activity-log');
                break 2;
            }
            $written++;
            $cursor_created = (string) $r['created_at'];
            $cursor_id      = (int) $r['id'];
            if ($written >= $cap) {
                $incomplete = __('EXPORT TRUNCATED: this export reached its row limit. Narrow the date range and export again.', 'devdome-activity-log');
                break 2;
            }
        }
    } while ($count === $per);

    if ('' !== $incomplete) {
        fputcsv($out, array($incomplete));
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream.
    fclose($out);
    exit;
}
add_action('admin_init', 'devdacti_handle_export');


/* ----------------------------- helpers ---------------------------------- */

/** Info icon with the long explanation in a hover box; the visible hint stays one line (DESIGN.md 21.6). */
function devdacti_tip($text)
{
    static $n = 0;
    $n++;
    $id = 'devdacti-tip-' . $n;
    echo '<span class="dd-tip" tabindex="0" aria-describedby="' . esc_attr($id) . '"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><span class="dd-tip-box" id="' . esc_attr($id) . '" role="tooltip">' . esc_html($text) . '</span></span>';
}

/**
 * The custom dropdown every DevDome plugin uses (Admin Cleaner devdadcl_render_dropdown, 1:1): a hidden input
 * carries the value, the panel lists the options as a listbox, al-admin.js drives it (mouse, Enter, Space, the
 * arrow keys and Escape) and dispatches a native change event on the hidden input. $options = value => label.
 */
function devdacti_render_dropdown($name, array $options, $label, $current = '', $disabled = false, $saved = null)
{
    $current = (string) $current;
    if (!isset($options[$current])) {
        $current = (string) key($options);
    }
    $id = 'devdacti-select-' . sanitize_key($name);
    ?>
    <div class="dd-dd<?php echo $disabled ? ' is-disabled' : ''; ?>" data-name="<?php echo esc_attr($name); ?>" data-label="<?php echo esc_attr($label); ?>" id="<?php echo esc_attr($id); ?>">
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($current); ?>"<?php if (null !== $saved) : ?> data-al-saved="<?php echo esc_attr($saved); ?>"<?php endif; ?>>
        <div class="dd-dd-trigger" tabindex="<?php echo $disabled ? '-1' : '0'; ?>" role="button" aria-haspopup="listbox" aria-expanded="false" aria-controls="<?php echo esc_attr($id); ?>-list" aria-disabled="<?php echo $disabled ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr($label . ': ' . $options[$current]); ?>">
            <span class="dd-dd-label"><?php echo esc_html($options[$current]); ?></span>
            <svg class="dd-dd-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg>
        </div>
        <div class="dd-dd-panel" id="<?php echo esc_attr($id); ?>-list" role="listbox" aria-label="<?php echo esc_attr($label); ?>">
            <?php foreach ($options as $val => $option_label) : ?>
                <div class="dd-dd-opt<?php echo (string) $val === $current ? ' is-selected' : ''; ?>" role="option" tabindex="-1" aria-selected="<?php echo (string) $val === $current ? 'true' : 'false'; ?>" data-value="<?php echo esc_attr($val); ?>"><?php echo esc_html($option_label); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

/**
 * One on/off setting row (DESIGN.md 25, Country Blocker devdcoun_setting_row 1:1): the Title Case title in the label
 * cell on the LEFT; the checkbox, its one-word meaning ($word, "Enable" by default) and ONE hint line with the info
 * icon in the control cell on the RIGHT. Saved by the Save button. The input is named by the title and the word.
 */
function devdacti_setting_row($id, $name, $value, $label, $checked, $hint = '', $tip = '', $locked = false, $extra = '', $saved = null, $word = null)
{
    if (null === $word) {
        $word = __('Enable', 'devdome-activity-log');
    }
    ?>
    <tr>
        <th class="dd-th" scope="row" id="<?php echo esc_attr($id); ?>-label"><?php echo esc_html($label); ?></th>
        <td class="dd-td">
            <label class="dd-opt" for="<?php echo esc_attr($id); ?>"><input type="checkbox" class="dd-check" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" <?php checked($checked); ?> <?php disabled($locked); ?><?php if (null !== $saved) : ?> data-al-saved="<?php echo esc_attr($saved); ?>"<?php endif; ?> aria-labelledby="<?php echo esc_attr($id); ?>-label <?php echo esc_attr($id); ?>-word"<?php if ('' !== $hint) : ?> aria-describedby="<?php echo esc_attr($id); ?>-hint"<?php endif; ?>> <span id="<?php echo esc_attr($id); ?>-word"><?php echo esc_html($word); ?></span></label><?php echo wp_kses($extra, devdacti_kses_allowed()); ?>
            <?php if ($locked) : ?>
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
            <?php endif; ?>
            <?php if ('' !== $hint) : ?>
                <p class="dd-hint" id="<?php echo esc_attr($id); ?>-hint"><?php echo esc_html($hint); ?><?php if ('' !== $tip) { devdacti_tip($tip); } ?></p>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}

/** The severity badge (section 18 colours, one word, one colour everywhere). */
function devdacti_severity_badge($severity)
{
    $map = array('critical' => 'al-badge-bad', 'warning' => 'al-badge-warn', 'notice' => 'al-badge-info', 'info' => 'al-badge-muted');
    $cls = isset($map[$severity]) ? $map[$severity] : 'al-badge-muted';
    return '<span class="al-badge ' . esc_attr($cls) . '">' . esc_html(devdacti_value_label($severity)) . '</span>';
}

/** The account state from the shared core ('ok' => 0 when the core is older). */
function devdacti_account_state()
{
    if (function_exists('devdcorev1_connection_state')) {
        $s = devdcorev1_connection_state();
        return is_array($s) ? $s : array('ok' => 0);
    }
    return array('ok' => 0);
}

/* ----------------------------- data ------------------------------------- */

/** Highest event id right now: the snapshot bound an export reads inside. */
function devdacti_events_max_id()
{
    global $wpdb;
    $table = devdacti_events_table();
    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- primary-key max on the internal events table; must be live.
    $max = $wpdb->get_var("SELECT MAX(id) FROM {$table}");
    // -1 says "no snapshot could be taken". Returning 0 would be indistinguishable from an
    // empty table, and the caller would export without a bound.
    if (null === $max && (string) $wpdb->last_error !== '') {
        return -1;
    }
    return (int) $max;
}

/** The review feed: rows whose SEVERITY says they are worth a human's attention. */
function devdacti_recent_review($limit = 10)
{
    return devdacti_query_events(array(
        'per_page'    => max(1, (int) $limit),
        'severity_in' => devdacti_review_severities(),
    ));
}

/** Most active users over the last 7 days (false on a failed read, never an empty list). */
function devdacti_top_users($limit = 5)
{
    global $wpdb;
    $table = devdacti_events_table();
    $week_ago = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
    devdacti_db_reset_error(); // records a pending failure before clearing (DESIGN.md 24)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal events table aggregate for admin display; values bound via prepare.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT user_login, COUNT(*) AS c FROM {$table} WHERE created_at >= %s AND user_id > 0 GROUP BY user_id, user_login ORDER BY c DESC LIMIT %d",
        $week_ago,
        max(1, (int) $limit)
    ));
    if ((string) $wpdb->last_error !== '' || !is_array($rows)) {
        return false;
    }
    return $rows;
}

/* ----------------------------- page shell ------------------------------- */

function devdacti_render_page()
{
    if (!devdacti_can('read')) {
        wp_die(esc_html__('You do not have permission to view the activity log.', 'devdome-activity-log'), '', array('response' => 403));
    }

    $tab  = devdacti_current_tab();
    $tabs = devdacti_tabs();
    $export_url = wp_nonce_url(
        add_query_arg(array('page' => DEVDACTI_PAGE, 'devdacti_export' => '1'), admin_url('admin.php')),
        'devdacti_export',
        '_alxn'
    );
    $panels = array(
        'overview' => 'devdacti_render_overview',
        'activity' => 'devdacti_render_log',
        'settings' => 'devdacti_render_settings',
    );

    // DESIGN.md 24: the page is built inside a guard window; a failed query while it was built is said at the top
    // of the page (the banner is printed after the page and al-admin.js moves it there), never shown as a calm empty
    // screen. The page is printed directly, never buffered and echoed.
    devdacti_db_guard_begin();
    ?>
        <div class="dd-app min-h-screen bg-gray-50 text-[#3c434a] font-sans text-[13px]" data-al-tab="<?php echo esc_attr($tab); ?>">
            <?php if (function_exists('devdcorev1_analytics_card')) { devdcorev1_analytics_card(); } ?>
            <div class="bg-white border-b border-gray-200 shadow-sm">
                <div class="max-w-5xl px-6 py-4 flex items-center gap-3" style="flex-wrap:wrap;">
                    <div class="p-1.5 rounded text-white inline-flex" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);"><?php echo wp_kses(devdacti_icon_svg(20), devdacti_kses_svg()); ?></div>
                    <h1 class="text-xl font-bold text-gray-800 m-0"><?php esc_html_e('Activity Log', 'devdome-activity-log'); ?></h1>
                    <div style="margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <?php if (devdacti_can('export')) : ?>
                            <span class="al-act-headbtns">
                                <a class="al-bug-btn" id="al-export" href="<?php echo esc_url($export_url); ?>" data-base="<?php echo esc_url($export_url); ?>" title="<?php esc_attr_e('Export the filtered events as CSV', 'devdome-activity-log'); ?>" aria-label="<?php esc_attr_e('Export the filtered events as CSV', 'devdome-activity-log'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg></a>
                            </span>
                        <?php endif; ?>
                        <a class="al-bug-btn" href="<?php echo esc_url('https://devdome.com/report-bug?plugin=devdome-activity-log&v=' . DEVDACTI_VERSION); ?>" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e('Report a bug', 'devdome-activity-log'); ?>" aria-label="<?php esc_attr_e('Report a bug', 'devdome-activity-log'); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8 2 1.88 1.88"/><path d="M14.12 3.88 16 2"/><path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/><path d="M12 20v-9"/><path d="M6.53 9C4.6 8.8 3 7.1 3 5"/><path d="M6 13H2"/><path d="M3 21c0-2.1 1.7-3.9 3.8-4"/><path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/><path d="M22 13h-4"/><path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/></svg>
                        </a>
                    </div>
                </div>
                <div class="dd-tabs max-w-5xl" style="margin:0;border-bottom:0;" role="tablist" aria-label="<?php esc_attr_e('Activity Log sections', 'devdome-activity-log'); ?>">
                    <?php foreach ($tabs as $slug => $meta) : $active = ($tab === $slug); ?>
                        <a class="dd-tab <?php echo esc_attr($active ? 'is-active' : ''); ?>"
                           href="#<?php echo esc_attr($slug); ?>"
                           role="tab"
                           id="al-tab-<?php echo esc_attr($slug); ?>"
                           aria-controls="al-panel-<?php echo esc_attr($slug); ?>"
                           aria-selected="<?php echo esc_attr($active ? 'true' : 'false'); ?>"
                           tabindex="<?php echo esc_attr($active ? '0' : '-1'); ?>"
                           data-dd-tab="<?php echo esc_attr($slug); ?>"><span class="dashicons <?php echo esc_attr($meta[1]); ?>" aria-hidden="true"></span> <?php echo esc_html($meta[0]); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <main>
                <?php
                // All panels render once; switching is instant client-side (no page reload). The ?al_tab= flag still
                // picks the first active panel so the form-save PRG redirects land right. Inactive panels carry hidden
                // as well as the CSS class, so assistive tech agrees with the visual state.
                foreach ($panels as $slug => $renderer) :
                    $active = ($tab === $slug);
                    ?>
                    <div class="dd-tabpanel<?php echo esc_attr($active ? ' is-active' : ''); ?>"
                         id="al-panel-<?php echo esc_attr($slug); ?>"
                         role="tabpanel"
                         aria-labelledby="al-tab-<?php echo esc_attr($slug); ?>"
                         tabindex="0"
                         data-dd-panel="<?php echo esc_attr($slug); ?>"
                         <?php echo esc_attr($active ? '' : 'hidden'); ?>>
                        <?php call_user_func($renderer); ?>
                    </div>
                <?php endforeach; ?>
            </main>
        </div>
        <?php
    $db_failed = devdacti_db_guard_active();
    devdacti_db_guard_end();
    if ($db_failed) :
        $why = devdacti_db_guard_message();
        ?>
        <div class="dd-banner dd-banner-error" id="devdacti-guard-banner" style="margin:16px 24px 0;max-width:61rem;" role="alert"><?php echo esc_html($why); ?> <?php echo wp_kses(devdacti_report_button($why, $tab), devdacti_kses_allowed()); ?></div>
        <?php
    endif;
}

/* ----------------------------- overview tab ----------------------------- */

/**
 * The one verdict the Overview shows (DESIGN.md 31): recording health first, then the failed-login threshold.
 *
 * @return array state (good|medium|high|critical|unknown), head, sub
 */
function devdacti_overview_verdict(array $stats, array $schema, array $writes, array $failed)
{
    if (empty($schema['ok'])) {
        return array('critical', __('Activity Is Not Being Recorded', 'devdome-activity-log'), (string) $schema['error']);
    }
    if (!empty($writes['error'])) {
        return array('unknown', __('Activity Could Not Be Counted', 'devdome-activity-log'), (string) $writes['error']);
    }
    if ((int) $writes['failures'] > 0) {
        return array('critical', __('Some Activity Was Lost', 'devdome-activity-log'), sprintf(
            /* translators: %d: number of events that could not be written. */
            _n('%d event could not be written to the log and is permanently lost.', '%d events could not be written to the log and are permanently lost.', (int) $writes['failures'], 'devdome-activity-log'),
            (int) $writes['failures']
        ) . ' ' . (devdacti_can('integrity')
            ? __('Check the Storage section on the Settings tab.', 'devdome-activity-log')
            : __('Ask an administrator to check the Storage section on the Settings tab.', 'devdome-activity-log')));
    }
    if (isset($stats['error']) && '' !== (string) $stats['error']) {
        return array('unknown', __('Activity Could Not Be Counted', 'devdome-activity-log'), (string) $stats['error']);
    }
    if ('urgent' === $failed['status'] || 'warn' === $failed['status']) {
        return array('urgent' === $failed['status'] ? 'high' : 'medium', __('Failed Logins Above Threshold', 'devdome-activity-log'), sprintf(
            /* translators: 1: failed login attempts in 24 hours, 2: the threshold for this site. */
            __('%1$d failed login attempts in the last 24 hours, above the threshold of %2$d for this site. Review which accounts were targeted and strengthen those passwords.', 'devdome-activity-log'),
            (int) $stats['failed_24h'],
            (int) $failed['threshold']
        ));
    }
    // The coverage claim is derived from the policy in force: groups off and excluded actors are said, not hidden.
    $all = array('auth', 'content', 'plugins', 'users', 'media', 'comments', 'settings');
    $on  = 0;
    foreach ($all as $g) {
        if (devdacti_group_enabled($g)) {
            $on++;
        }
    }
    $roles = count(devdacti_get_array('excluded_roles'));
    $users = count(devdacti_get_array('excluded_users'));
    if ($on === count($all) && 0 === $roles && 0 === $users) {
        return array('good', __('Recording', 'devdome-activity-log'), sprintf(
            /* translators: %d: retention window in days. */
            __('Every event group is being recorded and nothing was lost. Events are kept for %d days.', 'devdome-activity-log'),
            (int) devdacti_retention_days()
        ));
    }
    return array('good', __('Recording With Exclusions', 'devdome-activity-log'), sprintf(
        /* translators: 1: event groups recorded, 2: total event groups, 3: excluded roles, 4: excluded users, 5: retention days. */
        __('%1$d of %2$d event groups are recorded, %3$d roles and %4$d users are excluded, and nothing was lost. Events are kept for %5$d days.', 'devdome-activity-log'),
        $on,
        count($all),
        $roles,
        $users,
        (int) devdacti_retention_days()
    ));
}

function devdacti_render_overview()
{
    $stats  = devdacti_dashboard_stats();
    $review = devdacti_recent_review(10);
    $top    = devdacti_top_users(5);
    $failed = devdacti_failed_login_assessment((int) $stats['failed_24h']);
    $schema = devdacti_schema_status();
    $writes = devdacti_write_status();
    list($state, $head, $sub) = devdacti_overview_verdict($stats, $schema, $writes, $failed);
    $stats_error = isset($stats['error']) ? (string) $stats['error'] : '';
    $num = function ($n) use ($stats_error) {
        return '' !== $stats_error ? '?' : number_format_i18n((int) $n);
    };
    ?>
    <div class="max-w-5xl px-6 py-6 space-y-8">
        <section>
            <div class="dd-card" id="al-verdict" data-state="<?php echo esc_attr($state); ?>">
                <div class="al-verdict">
                    <div class="al-status is-<?php echo esc_attr($state); ?>" role="img" aria-label="<?php echo esc_attr($head); ?>" title="<?php echo esc_attr($head); ?>"><?php echo wp_kses(devdacti_icon_svg(84), devdacti_kses_svg()); ?></div>
                    <div class="al-verdict-txt">
                        <span class="al-verdict-h"><?php echo esc_html($head); ?></span>
                        <span class="al-verdict-p"><?php echo esc_html($sub); ?></span>
                    </div>
                </div>
                <?php if ('' !== $stats_error) : ?>
                    <div class="dd-banner dd-banner-error" style="margin:16px 0 0;" role="alert"><?php echo esc_html($stats_error); ?> <?php echo wp_kses(devdacti_report_button($stats_error, 'overview'), devdacti_kses_allowed()); ?></div>
                <?php endif; ?>
                <div class="dd-stats al-stats-4">
                    <div class="dd-stat"><div class="dd-stat-num" style="color:#4f46e5;"><?php echo esc_html($num($stats['events_24h'])); ?></div><div class="dd-stat-lbl"><?php esc_html_e('Events (24 Hours)', 'devdome-activity-log'); ?></div></div>
                    <div class="dd-stat"><div class="dd-stat-num" style="color:#10b981;"><?php echo esc_html($num($stats['logins_24h'])); ?></div><div class="dd-stat-lbl"><?php esc_html_e('Logins (24 Hours)', 'devdome-activity-log'); ?></div></div>
                    <div class="dd-stat"><div class="dd-stat-num" style="color:#ef4444;"><?php echo esc_html($num($stats['failed_24h'])); ?></div><div class="dd-stat-lbl"><?php esc_html_e('Failed Logins (24 Hours)', 'devdome-activity-log'); ?></div></div>
                    <div class="dd-stat"><div class="dd-stat-num" style="color:#1967d2;"><?php echo esc_html($num($stats['active_users_7d'])); ?></div><div class="dd-stat-lbl"><?php esc_html_e('Active Users (7 Days)', 'devdome-activity-log'); ?></div></div>
                </div>
                <p class="dd-hint" style="margin:10px 0 0;">
                    <?php
                    printf(
                        /* translators: %d: number of failed attempts at which this site starts flagging them. */
                        esc_html__('Failed attempts are counted, not diagnosed: this screen flags them from %d in 24 hours upwards. A handful of failures is usually a mistyped password.', 'devdome-activity-log'),
                        (int) $failed['threshold']
                    );
                    ?>
                </p>
            </div>
        </section>

        <section>
            <div class="dd-sec-head"><span class="dashicons dashicons-warning dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Events Worth Reviewing', 'devdome-activity-log'); ?></h2></div>
            <?php if (!empty($review['error'])) : ?>
                <div class="dd-card"><div class="dd-banner dd-banner-error" style="margin:0;" role="alert"><?php echo esc_html($review['error']); ?> <?php echo wp_kses(devdacti_report_button($review['error'], 'overview'), devdacti_kses_allowed()); ?></div></div>
            <?php elseif (!$review['rows']) : ?>
                <div class="dd-card"><div class="dd-empty"><?php esc_html_e('No warning or critical events recorded yet.', 'devdome-activity-log'); ?></div></div>
            <?php else : ?>
                <div class="dd-card al-list-card">
                    <div class="dd-list-wrap">
                        <table class="dd-table dd-list">
                            <caption class="screen-reader-text"><?php esc_html_e('The latest warning and critical events', 'devdome-activity-log'); ?></caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="al-col-pill"><?php esc_html_e('When', 'devdome-activity-log'); ?></th>
                                    <th scope="col" class="al-col-pill"><?php esc_html_e('Severity', 'devdome-activity-log'); ?></th>
                                    <th scope="col"><?php esc_html_e('Event', 'devdome-activity-log'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($review['rows'] as $c) : ?>
                                    <tr>
                                        <td class="al-col-pill"><?php
                                            printf(
                                                /* translators: %s: human-readable time difference, e.g. "3 hours". */
                                                esc_html__('%s ago', 'devdome-activity-log'),
                                                esc_html(human_time_diff(strtotime($c['created_at'] . ' UTC')))
                                            );
                                        ?></td>
                                        <td class="al-col-pill"><?php echo wp_kses(devdacti_severity_badge((string) $c['severity']), devdacti_kses_allowed()); ?></td>
                                        <td>
                                            <span class="al-title"><?php echo esc_html($c['action_label']); ?><?php if ((int) $c['counter'] > 1) : ?> <span class="al-grouptag"><?php
                                                printf(
                                                    /* translators: %d: number of times this event occurred. */
                                                    esc_html__('%d times', 'devdome-activity-log'),
                                                    (int) $c['counter']
                                                );
                                            ?></span><?php endif; ?></span>
                                            <?php if ('' !== $c['object_title']) : ?>
                                                <span class="al-meta"><b><?php esc_html_e('Subject:', 'devdome-activity-log'); ?></b> <?php echo esc_html($c['object_title']); ?></span>
                                            <?php endif; ?>
                                            <span class="al-meta"><b><?php esc_html_e('Actor:', 'devdome-activity-log'); ?></b> <?php echo esc_html('' !== $c['user_login'] ? $c['user_login'] : devdacti_value_label($c['actor_type'])); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <p class="dd-hint" style="margin:8px 0 0;"><?php esc_html_e('The ten most recent.', 'devdome-activity-log'); ?> <a href="#activity"><?php esc_html_e('The Activity tab lists every event, with filters.', 'devdome-activity-log'); ?></a></p>
            <?php endif; ?>
        </section>

        <section>
            <div class="dd-sec-head"><span class="dashicons dashicons-admin-users dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Most Active Users (7 Days)', 'devdome-activity-log'); ?></h2></div>
            <?php if (false === $top) : ?>
                <?php $top_error = __('The most active users could not be counted: the activity log could not be read.', 'devdome-activity-log'); ?>
                <div class="dd-card"><div class="dd-banner dd-banner-error" style="margin:0;" role="alert"><?php echo esc_html($top_error); ?> <?php echo wp_kses(devdacti_report_button($top_error, 'overview'), devdacti_kses_allowed()); ?></div></div>
            <?php elseif (!$top) : ?>
                <div class="dd-card"><div class="dd-empty"><?php esc_html_e('No user activity in the last 7 days.', 'devdome-activity-log'); ?></div></div>
            <?php else : ?>
                <div class="dd-card al-list-card">
                    <div class="dd-list-wrap">
                        <table class="dd-table dd-list">
                            <caption class="screen-reader-text"><?php esc_html_e('Users with the most recorded events in the last 7 days', 'devdome-activity-log'); ?></caption>
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e('User', 'devdome-activity-log'); ?></th>
                                    <th scope="col" class="al-col-num"><?php esc_html_e('Events', 'devdome-activity-log'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top as $u) : ?>
                                    <tr>
                                        <td><?php echo esc_html((string) $u->user_login); ?></td>
                                        <td class="al-col-num"><?php echo esc_html(number_format_i18n((int) $u->c)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <?php
}

/* ----------------------------- activity tab ----------------------------- */

function devdacti_render_log()
{
    $groups  = devdacti_group_labels();
    $actions = array();
    foreach (devdacti_actions_catalog() as $group_actions) {
        foreach ($group_actions as $key => $label) {
            $actions[$key] = $label;
        }
    }
    asort($actions);
    $types = array('' => __('Any object', 'devdome-activity-log'));
    foreach (devdacti_object_types() as $type) {
        $types[$type] = devdacti_object_type_label($type);
    }
    $sevs = array('' => __('Any severity', 'devdome-activity-log'));
    foreach (devdacti_severities() as $sev) {
        $sevs[$sev] = devdacti_value_label($sev);
    }
    ?>
    <div class="max-w-5xl px-6 pt-3 pb-6" id="al-log">
        <p class="al-intro"><?php esc_html_e('Everyone who can open this screen can read every recorded event, including usernames and IP addresses. Events are deleted automatically once they pass the retention window set on the Settings tab.', 'devdome-activity-log'); ?></p>
        <div class="al-toolbar-row al-filters">
            <?php
            devdacti_render_dropdown('al_user', array('0' => __('All users', 'devdome-activity-log')), __('User', 'devdome-activity-log'), '0');
            devdacti_render_dropdown('al_group', array('' => __('All activity', 'devdome-activity-log')) + $groups, __('Activity Group', 'devdome-activity-log'));
            devdacti_render_dropdown('al_action', array('' => __('Any action', 'devdome-activity-log')) + $actions, __('Action', 'devdome-activity-log'));
            devdacti_render_dropdown('al_object_type', $types, __('Object Type', 'devdome-activity-log'));
            devdacti_render_dropdown('al_severity', $sevs, __('Severity', 'devdome-activity-log'));
            ?>
        </div>
        <div class="al-toolbar-row">
            <?php // From / To = the Redirect Manager "Campaign Dates" field 1:1 (the word, then the date input). ?>
            <label class="al-date-lbl" for="al-from"><?php esc_html_e('From', 'devdome-activity-log'); ?> <input type="date" id="al-from" class="al-date" aria-label="<?php esc_attr_e('From date', 'devdome-activity-log'); ?>"></label>
            <label class="al-date-lbl" for="al-to"><?php esc_html_e('To', 'devdome-activity-log'); ?> <input type="date" id="al-to" class="al-date" aria-label="<?php esc_attr_e('To date', 'devdome-activity-log'); ?>"></label>
            <button type="button" class="dd-btn-primary" id="al-apply"><?php esc_html_e('Apply Dates', 'devdome-activity-log'); ?></button>
            <label class="screen-reader-text" for="al-search"><?php esc_html_e('Search', 'devdome-activity-log'); ?></label>
            <span class="al-search-wrap"><span class="dashicons dashicons-search" aria-hidden="true"></span><input type="search" id="al-search" class="dd-input al-search" placeholder="<?php esc_attr_e('Search events...', 'devdome-activity-log'); ?>" aria-describedby="al-search-hint"></span>
        </div>
        <p class="dd-hint" id="al-search-hint" style="margin:0 0 12px;"><?php esc_html_e('Search matches the subject, the details and the username; at least two characters.', 'devdome-activity-log'); ?><?php devdacti_tip(__('Search matches the subject, the details and the username. Searching the details column reads every matching row, so pair it with a date range on a large log.', 'devdome-activity-log')); ?></p>

        <div id="al-status" role="status" aria-live="polite" class="screen-reader-text"></div>
        <div id="al-error" class="dd-banner dd-banner-error" style="display:none;margin-bottom:12px;" role="alert"></div>
        <div class="dd-card al-list-card">
            <div class="dd-list-wrap">
                <table class="dd-table dd-list" id="al-table">
                    <caption class="screen-reader-text"><?php esc_html_e('Recorded events matching the current filters', 'devdome-activity-log'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col" class="al-col-pill"><?php esc_html_e('Time', 'devdome-activity-log'); ?></th>
                            <th scope="col" class="al-col-pill"><?php esc_html_e('Severity', 'devdome-activity-log'); ?></th>
                            <th scope="col" class="al-col-pill"><?php esc_html_e('Actor', 'devdome-activity-log'); ?></th>
                            <th scope="col" class="al-col-pill"><?php esc_html_e('IP', 'devdome-activity-log'); ?></th>
                            <th scope="col"><?php esc_html_e('Event', 'devdome-activity-log'); ?></th>
                            <th scope="col" class="al-col-exp"><span class="screen-reader-text"><?php esc_html_e('Details', 'devdome-activity-log'); ?></span></th>
                        </tr>
                    </thead>
                    <tbody id="al-rows"></tbody>
                </table>
            </div>
            <div id="al-empty" class="dd-empty" style="display:none;"></div>
            <div class="al-pagination" id="al-pager"></div>
        </div>
    </div>
    <?php
}

/* ----------------------------- settings tab ----------------------------- */

/** The DevDome Account connect card (DESIGN.md 30), shown only while the site is not connected. */
function devdacti_render_connect_card()
{
    $acct = devdacti_account_state();
    if (!empty($acct['ok'])) {
        return;
    }
    $return = add_query_arg(array('page' => DEVDACTI_PAGE, 'al_tab' => 'settings'), admin_url('admin.php')) . '#settings';
    ?>
    <section id="al-devdome-account" style="margin-bottom:32px;">
        <div class="dd-sec-head"><span class="dashicons dashicons-admin-links dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('DevDome Account', 'devdome-activity-log'); ?></h2></div>
        <div class="al-conn">
            <span class="al-conn-ci">DD</span>
            <div class="al-conn-body">
                <strong><?php esc_html_e('Connect this site to your DevDome account', 'devdome-activity-log'); ?></strong>
                <span><?php esc_html_e('Connect sends your domain, site token, active DevDome plugins and software versions to analytics.devdome.com and api.devdome.com. The Activity Log sends nothing else after connecting: every recorded event stays in this site\'s database. See the readme\'s External services section for details.', 'devdome-activity-log'); ?> <a href="https://devdome.com/privacy-policy" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Privacy Policy', 'devdome-activity-log'); ?></a> &middot; <a href="https://devdome.com/terms-of-service" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Terms of Service', 'devdome-activity-log'); ?></a></span>
            </div>
            <div class="al-conn-ctl">
                <form method="post" action="<?php echo esc_url(add_query_arg('return', rawurlencode($return), admin_url('admin-post.php'))); ?>">
                    <input type="hidden" name="action" value="devdcorev1_connect_go">
                    <input type="hidden" name="devdcorev1_inventory" value="1">
                    <?php wp_nonce_field('devdcorev1_connect_go'); ?>
                    <button type="submit" class="al-conn-btn"><?php esc_html_e('Connect your DevDome account', 'devdome-activity-log'); ?></button>
                </form>
                <p class="al-conn-hint"><?php esc_html_e('Opens devdome.com to sign in, then links this site.', 'devdome-activity-log'); ?></p>
            </div>
        </div>
    </section>
    <?php
}

function devdacti_render_settings()
{
    $can_manage = devdacti_can('manage');
    $retention  = devdacti_retention_days();
    $anonymize  = devdacti_get_int('anonymize_ip', 1);
    $protect    = devdacti_get_int('protect_critical', 1);
    $proxies    = (string) devdacti_get_setting('trusted_proxies', '');
    $header     = devdacti_forwarded_header();
    $excluded_roles = devdacti_get_array('excluded_roles');
    $excluded_users = devdacti_get_array('excluded_users');
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG notice flags.
    $saved   = isset($_GET['al_saved']);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG notice flags.
    $failed  = isset($_GET['al_failed']) ? (int) $_GET['al_failed'] : 0;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG notice flags.
    $confirm = isset($_GET['al_confirm']);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG notice flags.
    $purged  = isset($_GET['al_purged']) ? (int) $_GET['al_purged'] : -1;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG notice flags.
    $purge_unconfirmed = isset($_GET['al_purge_unconfirmed']);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG notice flags.
    $purge_error = isset($_GET['al_purge_error']);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG notice flags.
    $repaired = isset($_GET['al_repaired']) ? (int) $_GET['al_repaired'] : -1;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG notice flags.
    $repair_unconfirmed = isset($_GET['al_repair_unconfirmed']);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG notice flags.
    $invalid = isset($_GET['al_invalid']) ? sanitize_key(wp_unslash($_GET['al_invalid'])) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG notice flags.
    $audit_failed = isset($_GET['al_audit_failed']) ? (int) $_GET['al_audit_failed'] : 0;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG notice flags (set by our own devdacti_finish()).
    $db_error = isset($_GET['al_error']) && 'db' === sanitize_key(wp_unslash($_GET['al_error']));
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG notice flags.
    $db_why   = isset($_GET['al_why']) ? sanitize_text_field(wp_unslash($_GET['al_why'])) : '';
    $pending_all = $confirm ? get_transient('devdacti_pending_weaken_' . get_current_user_id()) : false;
    $pending     = (is_array($pending_all) && isset($pending_all['weakenings'])) ? (array) $pending_all['weakenings'] : array();
    $proposed    = (is_array($pending_all) && isset($pending_all['policy']) && is_array($pending_all['policy'])) ? $pending_all['policy'] : array();
    $saved_retention = $retention; // the policy in force: the purge below acts on this, whatever the form proposes
    $saved_values    = array(
        'al_retention_days'   => (string) $retention,
        'al_anonymize_ip'     => (string) $anonymize,
        'al_protect_critical' => (string) $protect,
        'al_trusted_proxies'  => $proxies,
        'al_forwarded_header' => $header,
        'al_excluded_users'   => implode("\n", (array) $excluded_users),
    );
    foreach (array('auth', 'content', 'plugins', 'users', 'media', 'comments', 'settings') as $g) {
        $saved_values['al_group_' . $g] = devdacti_group_enabled($g) ? '1' : '0'; // the EFFECTIVE state (protection wins over a stale flag)
    }
    foreach (array_keys(wp_roles()->roles) as $role_key) {
        $saved_values['al_role_' . $role_key] = in_array($role_key, (array) $excluded_roles, true) ? '1' : '0';
    }
    // data-al-saved: the stored value of a control while the form shows a rejected proposal (al-admin.js uses it as the
    // pending-counter baseline, so "N changes not saved" and the leave-page warning stay honest).
    if ($proposed) {
        // The rejected save: show what was proposed, so the owner only has to tick the acknowledgement and save.
        $retention      = (int) $proposed['retention_days'];
        $anonymize      = (int) $proposed['anonymize_ip'];
        $protect        = (int) $proposed['protect_critical'];
        $proxies        = (string) $proposed['trusted_proxies'];
        $header         = (string) $proposed['forwarded_header'];
        $excluded_roles = (array) $proposed['excluded_roles'];
        $excluded_users = (array) $proposed['excluded_users'];
    }

    $group_fields = array(
        'auth'     => array(__('Authentication', 'devdome-activity-log'), __('Logins, logouts, failed logins and password resets.', 'devdome-activity-log')),
        'content'  => array(__('Content', 'devdome-activity-log'), __('Posts, pages and custom post types: created, updated, trashed, deleted.', 'devdome-activity-log')),
        'plugins'  => array(__('Plugins And Themes', 'devdome-activity-log'), __('Plugin and theme installs, updates, activations, plus core updates.', 'devdome-activity-log')),
        'users'    => array(__('Users', 'devdome-activity-log'), __('User accounts created or deleted, role changes, email changes and profile updates.', 'devdome-activity-log')),
        'media'    => array(__('Media', 'devdome-activity-log'), __('Media uploads and deletions.', 'devdome-activity-log')),
        'comments' => array(__('Comments', 'devdome-activity-log'), __('Comment status changes: approved, spammed, trashed, restored.', 'devdome-activity-log')),
        'settings' => array(__('Settings', 'devdome-activity-log'), __('Changes to a short allowlist of important site options.', 'devdome-activity-log')),
    );
    $protected_groups = devdacti_protected_groups();
    $retention_status = devdacti_retention_status();
    $proxy_status     = devdacti_proxy_status();
    $backlog          = devdacti_retention_backlog();
    $writes           = devdacti_write_status();
    $schema           = devdacti_schema_status();
    $headers          = array('' => __('Do not read any forwarded header', 'devdome-activity-log'));
    foreach (array_keys(devdacti_forwarded_headers()) as $h) {
        $headers[$h] = $h;
    }
    ?>
    <div class="max-w-5xl px-6 pt-3 pb-6">
        <?php // DESIGN.md 22.3: every result banner is a flash (data-al-flash); al-admin.js strips the flags from the URL. ?>
        <?php if ($db_error) : ?>
            <div class="dd-banner dd-banner-error" style="margin-bottom:16px;" data-al-flash role="alert"><?php echo esc_html('' !== $db_why ? $db_why : devdacti_db_guard_message()); ?> <?php echo wp_kses(devdacti_report_button('' !== $db_why ? $db_why : devdacti_db_guard_message(), 'settings'), devdacti_kses_allowed()); ?></div>
        <?php elseif ($failed > 0) : ?>
            <?php
            $failed_text = sprintf(
                /* translators: %d: number of settings that could not be stored. */
                __('%d settings could not be saved. The Activity Log settings table did not accept the write, so the policy on screen is not the policy in force.', 'devdome-activity-log'),
                (int) $failed
            );
            ?>
            <div class="dd-banner dd-banner-error" style="margin-bottom:16px;" data-al-flash role="alert"><?php echo esc_html($failed_text); ?> <?php echo wp_kses(devdacti_report_button($failed_text, 'settings'), devdacti_kses_allowed()); ?></div>
        <?php endif; ?>

        <?php if ($audit_failed > 0) : ?>
            <div class="dd-banner dd-banner-error" style="margin-bottom:16px;" data-al-flash role="alert"><?php
                printf(
                    /* translators: %d: number of policy changes whose audit row could not be written. */
                    esc_html(_n('The settings were stored, but %d change could not be recorded in the activity log. The lost write is counted on the Overview.', 'The settings were stored, but %d changes could not be recorded in the activity log. The lost writes are counted on the Overview.', $audit_failed, 'devdome-activity-log')),
                    (int) $audit_failed
                );
            ?></div>
        <?php endif; ?>
        <?php if ('retention' === $invalid) : ?>
            <div class="dd-banner dd-banner-error" style="margin-bottom:16px;" data-al-flash role="alert"><?php esc_html_e('Nothing was saved: Keep Events For must be a whole number of days between 1 and 3650.', 'devdome-activity-log'); ?></div>
        <?php endif; ?>
        <?php if ($purge_unconfirmed) : ?>
            <div class="dd-banner dd-banner-error" style="margin-bottom:16px;" data-al-flash><?php esc_html_e('Nothing was deleted: type DELETE to confirm the purge.', 'devdome-activity-log'); ?></div>
        <?php endif; ?>
        <?php if ($purge_error) : ?>
            <div class="dd-banner dd-banner-error" style="margin-bottom:16px;" data-al-flash role="alert"><?php esc_html_e('The purge stopped on a database error. The error is shown in the Retention section below.', 'devdome-activity-log'); ?> <?php echo wp_kses(devdacti_report_button((string) $retention_status['last_error'], 'settings'), devdacti_kses_allowed()); ?></div>
        <?php endif; ?>
        <?php if ($repair_unconfirmed) : ?>
            <div class="dd-banner dd-banner-error" style="margin-bottom:16px;" data-al-flash><?php esc_html_e('Nothing was repaired: tick "I have a backup, run the repair" first.', 'devdome-activity-log'); ?></div>
        <?php endif; ?>
        <?php if (1 === $repaired) : ?>
            <div class="dd-banner dd-banner-ok" style="margin-bottom:16px;" data-al-flash><?php esc_html_e('The storage was repaired and the events table is healthy.', 'devdome-activity-log'); ?></div>
        <?php elseif (0 === $repaired) : ?>
            <?php $schema_now = devdacti_schema_status(); ?>
            <div class="dd-banner dd-banner-error" style="margin-bottom:16px;" data-al-flash role="alert"><?php esc_html_e('The repair ran but the events table is still not healthy.', 'devdome-activity-log'); ?> <?php echo esc_html((string) $schema_now['error']); ?> <?php echo wp_kses(devdacti_report_button((string) $schema_now['error'], 'settings'), devdacti_kses_allowed()); ?></div>
        <?php endif; ?>

        <?php if ($confirm) : ?>
            <div class="dd-banner dd-banner-error" style="margin-bottom:16px;"><?php // not a flash: it explains the proposal that is still in the form ?>
                <strong><?php esc_html_e('Nothing was saved. This change reduces what gets recorded:', 'devdome-activity-log'); ?></strong>
                <ul style="margin:6px 0 6px 18px;list-style:disc;">
                    <?php foreach ($pending as $line) : ?>
                        <li><?php echo esc_html($line); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php esc_html_e('Your changes are still in the form. Tick "I understand this reduces audit coverage" in the Audit Coverage section and save again.', 'devdome-activity-log'); ?>
            </div>
        <?php endif; ?>

        <?php if ($purged >= 0) : ?>
            <div class="dd-banner dd-banner-ok" style="margin-bottom:16px;" data-al-flash>
                <?php
                printf(
                    /* translators: %d: number of events deleted by the manual purge. */
                    esc_html__('%d events were deleted by the retention purge.', 'devdome-activity-log'),
                    (int) $purged
                );
                ?>
            </div>
        <?php endif; ?>

        <?php if (!$can_manage) : ?>
            <div class="dd-banner" style="margin-bottom:16px;"><?php esc_html_e('You can read the activity log but not change its policy, so these settings are shown read-only.', 'devdome-activity-log'); ?></div>
        <?php endif; ?>

        <?php if ($can_manage) { devdacti_render_connect_card(); } // the shared core checks its own capability on the post; a reader is not offered the button ?>

        <form method="post" class="space-y-8"<?php if ($proposed) : ?> data-al-proposal="1"<?php endif; ?>>
            <?php wp_nonce_field('devdacti_settings', '_alsn'); ?>
            <input type="hidden" name="devdacti_settings_save" value="1">
            <fieldset <?php disabled(!$can_manage); ?> style="border:0;padding:0;margin:0;" class="space-y-8">

            <section>
                <div class="dd-sec-head"><span class="dashicons dashicons-clock dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Retention', 'devdome-activity-log'); ?></h2></div>
                <div class="dd-card">
                    <table class="dd-table">
                        <tr>
                            <th class="dd-th" scope="row"><label for="al_retention_days"><?php esc_html_e('Keep Events For (Days)', 'devdome-activity-log'); ?></label></th>
                            <td class="dd-td">
                                <input type="text" inputmode="numeric" pattern="[0-9]{1,4}" id="al_retention_days" name="al_retention_days" class="dd-input al-num" required value="<?php echo esc_attr($retention); ?>" aria-describedby="al-retention-hint"<?php if ($proposed && isset($saved_values['al_retention_days'])) : ?> data-al-saved="<?php echo esc_attr($saved_values['al_retention_days']); ?>"<?php endif; ?>>
                                <p class="dd-hint" id="al-retention-hint"><?php esc_html_e('Between 1 and 3650 days. Older events are deleted once a day.', 'devdome-activity-log'); ?><?php devdacti_tip(__('Common choices are 30, 90, 180 and 365 days. Deletion is permanent and is itself recorded in the log.', 'devdome-activity-log')); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th class="dd-th" scope="row"><?php esc_html_e('Retention Status', 'devdome-activity-log'); ?></th>
                            <td class="dd-td">
                                <?php // One info pill per fact (owner 2026-10-12: "make it like a blue info pill"). ?>
                                <div class="al-pills">
                                    <span class="dd-pill al-pill-info"><span class="dashicons dashicons-clock" aria-hidden="true"></span><b><?php esc_html_e('Last purge:', 'devdome-activity-log'); ?></b> <?php echo esc_html('' !== $retention_status['last_run'] ? $retention_status['last_run'] . ' UTC' : __('never', 'devdome-activity-log')); ?></span>
                                    <span class="dd-pill al-pill-info"><span class="dashicons dashicons-trash" aria-hidden="true"></span><b><?php esc_html_e('Deleted:', 'devdome-activity-log'); ?></b> <?php
                                        printf(
                                            /* translators: %d: number of events deleted by the last retention run. */
                                            esc_html__('%d on the last run', 'devdome-activity-log'),
                                            (int) $retention_status['deleted']
                                        );
                                    ?></span>
                                    <span class="dd-pill al-pill-info"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span><b><?php esc_html_e('Next run:', 'devdome-activity-log'); ?></b> <?php echo esc_html($retention_status['next_run'] ? gmdate('Y-m-d H:i', $retention_status['next_run']) . ' UTC' : __('not scheduled', 'devdome-activity-log')); ?></span>
                                    <span class="dd-pill al-pill-info"><span class="dashicons dashicons-backup" aria-hidden="true"></span><b><?php esc_html_e('Older now:', 'devdome-activity-log'); ?></b> <?php echo esc_html($backlog < 0 ? __('unknown (the log could not be read)', 'devdome-activity-log') : sprintf(/* translators: %s: number of events older than the retention window. */ __('%s events past the window', 'devdome-activity-log'), number_format_i18n($backlog))); ?></span>
                                    <?php if ('' !== $retention_status['last_error']) : ?>
                                        <span class="dd-pill al-pill-bad"><span class="dashicons dashicons-warning" aria-hidden="true"></span><b><?php esc_html_e('Last error:', 'devdome-activity-log'); ?></b> <?php echo esc_html($retention_status['last_error']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
            </section>

            <section>
                <div class="dd-sec-head"><span class="dashicons dashicons-privacy dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Privacy', 'devdome-activity-log'); ?></h2></div>
                <div class="dd-card">
                    <table class="dd-table">
                        <?php devdacti_setting_row('al_anonymize_ip', 'al_anonymize_ip', '1', __('Truncate IP Addresses', 'devdome-activity-log'), 1 === (int) $anonymize, __('Stores 1.2.3.0 instead of 1.2.3.4 before the address is written.', 'devdome-activity-log'), __('IPv6 addresses keep their first three groups. Truncation reduces precision; it does not make the record anonymous, because the same row still names the account, the time and the action.', 'devdome-activity-log'), false, '', $proposed ? $saved_values['al_anonymize_ip'] : null); ?>
                        <tr>
                            <th class="dd-th" scope="row"><?php esc_html_e('Stored Data', 'devdome-activity-log'); ?></th>
                            <td class="dd-td">
                                <details class="al-more">
                                    <summary><?php esc_html_e('What this plugin stores, and why', 'devdome-activity-log'); ?></summary>
                                    <div class="dd-list-wrap">
                                        <table class="dd-table dd-list">
                                            <thead><tr><th scope="col"><?php esc_html_e('Field', 'devdome-activity-log'); ?></th><th scope="col"><?php esc_html_e('Purpose', 'devdome-activity-log'); ?></th></tr></thead>
                                            <tbody>
                                                <?php foreach (devdacti_data_inventory() as $row) : ?>
                                                    <tr><td><?php echo esc_html($row[0]); ?></td><td><?php echo esc_html($row[1]); ?></td></tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                                <p class="dd-hint"><?php esc_html_e('Personal-data exports include a user\'s activity records; an erasure pseudonymises them.', 'devdome-activity-log'); ?><?php devdacti_tip(__('A personal-data erasure clears the username, user ID and IP from that account\'s records and keeps the security history in pseudonymised form, because deleting it would remove the site\'s evidence of account and configuration changes.', 'devdome-activity-log')); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
            </section>

            <section>
                <div class="dd-sec-head"><span class="dashicons dashicons-shield dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Trusted Proxies', 'devdome-activity-log'); ?></h2></div>
                <div class="dd-card">
                    <table class="dd-table">
                        <tr>
                            <th class="dd-th" scope="row"><?php esc_html_e('Client IP Header', 'devdome-activity-log'); ?></th>
                            <td class="dd-td">
                                <?php devdacti_render_dropdown('al_forwarded_header', $headers, __('Client IP Header', 'devdome-activity-log'), $header, !$can_manage, $proposed ? $saved_values['al_forwarded_header'] : null); ?>
                                <p class="dd-hint"><?php esc_html_e('The header your proxy writes the visitor address into.', 'devdome-activity-log'); ?><?php devdacti_tip(__('Leave it on "Do not read any forwarded header" unless your site is only reachable through a proxy or CDN. The header is believed only when the connection comes from a trusted proxy range below.', 'devdome-activity-log')); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th class="dd-th" scope="row"><label for="al_trusted_proxies"><?php esc_html_e('Trusted Proxy Ranges', 'devdome-activity-log'); ?></label></th>
                            <td class="dd-td">
                                <textarea id="al_trusted_proxies" name="al_trusted_proxies" class="dd-textarea" rows="3" aria-describedby="al-proxy-hint" placeholder="203.0.113.0/24"<?php if ($proposed && isset($saved_values['al_trusted_proxies'])) : ?> data-al-saved="<?php echo esc_attr($saved_values['al_trusted_proxies']); ?>"<?php endif; ?>><?php echo esc_textarea($proxies); ?></textarea>
                                <p class="dd-hint" id="al-proxy-hint"><?php esc_html_e('One IP address or CIDR range per line.', 'devdome-activity-log'); ?><?php devdacti_tip(__('A forwarded header is believed only when the connection itself comes from one of these ranges; otherwise anyone could choose the IP written into your audit log. Leave empty unless your site is only reachable through a proxy.', 'devdome-activity-log')); ?></p>
                                <p class="al-status-line"><b><?php esc_html_e('Now:', 'devdome-activity-log'); ?></b> <?php echo esc_html($proxy_status['message']); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
            </section>

            <section>
                <div class="dd-sec-head"><span class="dashicons dashicons-lock dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Protected Events', 'devdome-activity-log'); ?></h2></div>
                <div class="dd-card">
                    <table class="dd-table">
                        <?php devdacti_setting_row('al_protect_critical', 'al_protect_critical', '1', __('Always Record Security Events', 'devdome-activity-log'), 1 === (int) $protect, __('Authentication, user and plugin events cannot be switched off or hidden by an exclusion.', 'devdome-activity-log'), __('While this is on, those three groups stay on and excluded users cannot hide them. Changes to the Activity Log itself are always recorded, whatever this is set to.', 'devdome-activity-log'), false, '', $proposed ? $saved_values['al_protect_critical'] : null); ?>
                    </table>
                </div>
            </section>

            <section>
                <div class="dd-sec-head"><span class="dashicons dashicons-list-view dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Event Groups', 'devdome-activity-log'); ?></h2><?php devdacti_tip(__('Tick a group to record it. Untick to stop recording it; what was recorded before stays in the log.', 'devdome-activity-log')); ?></div>
                <div class="dd-card">
                    <table class="dd-table">
                        <?php foreach ($group_fields as $key => $labels) :
                            $locked = in_array($key, $protected_groups, true);
                            devdacti_setting_row(
                                'al_group_' . $key,
                                'al_group_' . $key,
                                '1',
                                $labels[0],
                                $locked || 1 === (int) (isset($proposed['group_' . $key]) ? $proposed['group_' . $key] : devdacti_get_int('group_' . $key, 1)), // a protected group records whatever its stored flag says
                                $labels[1],
                                $locked ? __('A protected group: it stays on while Always Record Security Events is ticked.', 'devdome-activity-log') : '',
                                $locked,
                                $locked ? '<span class="al-protected">' . esc_html__('Protected', 'devdome-activity-log') . '</span>' : '',
                                $proposed ? $saved_values['al_group_' . $key] : null,
                                __('Record', 'devdome-activity-log')
                            );
                        endforeach; ?>
                    </table>
                </div>
            </section>

            <section>
                <div class="dd-sec-head"><span class="dashicons dashicons-hidden dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Exclusions', 'devdome-activity-log'); ?></h2><?php devdacti_tip(__('Activity by an excluded role or username is not recorded, except in the protected groups. A user holding several roles is excluded when any of them is ticked.', 'devdome-activity-log')); ?></div>
                <?php
                $privileged_excluded = array_intersect((array) $excluded_roles, devdacti_privileged_roles());
                if ($privileged_excluded) :
                    ?>
                    <div class="dd-banner dd-banner-error" style="margin-bottom:12px;">
                        <?php
                        printf(
                            /* translators: %s: comma separated list of excluded privileged roles. */
                            esc_html__('An administrative role is excluded from logging: %s. Actions by those accounts are not recorded outside the protected groups.', 'devdome-activity-log'),
                            esc_html(implode(', ', $privileged_excluded))
                        );
                        ?>
                    </div>
                <?php endif; ?>
                <div class="dd-card">
                    <table class="dd-table">
                        <tr>
                            <th class="dd-th" scope="row" id="al-excluded-roles-label"><?php esc_html_e('Excluded Roles', 'devdome-activity-log'); ?></th>
                            <td class="dd-td">
                                <?php // The Analytics "Excluded roles" list 1:1: one checkbox per role, the role name as its word. ?>
                                <div class="dd-opts al-optlist" role="group" aria-labelledby="al-excluded-roles-label" aria-describedby="al-excluded-roles-hint">
                                    <?php foreach (wp_roles()->roles as $role_key => $role) : ?>
                                        <label class="dd-opt" for="al_role_<?php echo esc_attr($role_key); ?>"><input type="checkbox" class="dd-check" id="al_role_<?php echo esc_attr($role_key); ?>" name="al_excluded_roles[]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, (array) $excluded_roles, true)); ?><?php if ($proposed) : ?> data-al-saved="<?php echo esc_attr($saved_values['al_role_' . $role_key]); ?>"<?php endif; ?>> <?php echo esc_html(translate_user_role($role['name'])); ?></label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="dd-hint" id="al-excluded-roles-hint"><?php esc_html_e('Tick a role to stop recording what it does, outside the protected groups.', 'devdome-activity-log'); ?><?php devdacti_tip(__('A user holding several roles is excluded when any of them is ticked. Excluding a role is itself recorded, with the previous and the new list.', 'devdome-activity-log')); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th class="dd-th" scope="row"><label for="al_excluded_users"><?php esc_html_e('Excluded Users', 'devdome-activity-log'); ?></label></th>
                            <td class="dd-td">
                                <textarea id="al_excluded_users" name="al_excluded_users" class="dd-textarea" rows="3" aria-describedby="al-exclusions-hint" placeholder="<?php esc_attr_e('one username per line', 'devdome-activity-log'); ?>"<?php if ($proposed && isset($saved_values['al_excluded_users'])) : ?> data-al-saved="<?php echo esc_attr($saved_values['al_excluded_users']); ?>"<?php endif; ?>><?php echo esc_textarea(implode("\n", (array) $excluded_users)); ?></textarea>
                                <p class="dd-hint" id="al-exclusions-hint"><?php esc_html_e('One username per line.', 'devdome-activity-log'); ?><?php devdacti_tip(__('Nothing these accounts do is recorded outside the protected groups. Excluding an account is itself recorded, with the previous and the new list.', 'devdome-activity-log')); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
            </section>

            <section>
                <div class="dd-sec-head"><span class="dashicons dashicons-visibility dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Audit Coverage', 'devdome-activity-log'); ?></h2></div>
                <div class="dd-card">
                    <table class="dd-table">
                        <?php devdacti_setting_row('al_confirm_weaken', 'al_confirm_weaken', '1', __('Confirmation', 'devdome-activity-log'), false, __('Needed only when a change switches a group off, excludes an actor, shortens retention or unlocks the protected groups.', 'devdome-activity-log'), __('Every policy change is recorded with the previous and the new value, whoever makes it. The tick is asked for once per save and is never stored.', 'devdome-activity-log'), false, '', null, __('I understand this reduces audit coverage', 'devdome-activity-log')); ?>
                    </table>
                </div>
            </section>

            </fieldset>
            <?php if ($can_manage) { devdacti_save_footer($saved && !$db_error); } ?>
        </form>

        <?php if (devdacti_can('purge')) : ?>
            <section style="margin-top:2rem;">
                <div class="dd-sec-head"><span class="dashicons dashicons-trash dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Delete Old Events Now', 'devdome-activity-log'); ?></h2></div>
                <?php // DESIGN.md 23: destructive = type the word (Admin Cleaner form[data-ac-typed] controller, 1:1). al-admin.js drives form[data-al-typed]. ?>
                <form method="post" class="dd-card" data-al-typed="DELETE">
                    <?php wp_nonce_field('devdacti_purge_now', '_alpn'); ?>
                    <input type="hidden" name="devdacti_purge_now" value="1">
                    <p class="al-intro">
                        <?php
                        printf(
                            /* translators: 1: number of events that would be deleted, 2: retention window in days. */
                            esc_html__('This deletes the %1$s events already older than %2$d days. Deletion is permanent, is recorded in the log, and cannot be undone without a database backup.', 'devdome-activity-log'),
                            esc_html($backlog < 0 ? __('unknown number of', 'devdome-activity-log') : number_format_i18n($backlog)),
                            (int) $saved_retention
                        );
                        ?>
                    </p>
                    <button type="button" class="dd-btn dd-btn-danger" data-al-typed-open><?php esc_html_e('Delete Old Events', 'devdome-activity-log'); ?></button>
                    <div class="al-typed-confirm" hidden>
                        <p class="al-typed-warn"><?php esc_html_e('This permanently deletes every event older than the retention window. It cannot be undone.', 'devdome-activity-log'); ?></p>
                        <p class="al-typed-ask"><?php echo wp_kses(__('Type <strong>DELETE</strong> to confirm.', 'devdome-activity-log'), array('strong' => array())); ?></p>
                        <input type="text" class="dd-input al-typed-input" autocomplete="off" placeholder="DELETE" aria-label="<?php esc_attr_e('Type DELETE to confirm', 'devdome-activity-log'); ?>">
                        <div class="al-typed-btns">
                            <button type="submit" class="dd-btn dd-btn-danger" disabled><?php esc_html_e('Delete Old Events', 'devdome-activity-log'); ?></button>
                            <button type="button" class="dd-btn" data-al-typed-cancel><?php esc_html_e('Cancel', 'devdome-activity-log'); ?></button>
                        </div>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php // Storage facts + the repair form: only while something is wrong (a missing column or lost writes). A healthy log shows nothing here; the readme FAQ carries the tamper-proof note. ?>
        <?php if (devdacti_can('integrity') && (empty($schema['ok']) || (int) $writes['failures'] > 0)) : ?>
            <section style="margin-top:2rem;" id="al-storage">
                <div class="dd-sec-head"><span class="dashicons dashicons-database dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Storage', 'devdome-activity-log'); ?></h2></div>
                <div class="dd-card">
                    <ul class="al-facts">
                        <li><span class="al-fact-k"><?php esc_html_e('Storage', 'devdome-activity-log'); ?></span><span class="al-fact-v"><?php echo esc_html(!empty($schema['ok']) ? __('the events table has every column this version needs.', 'devdome-activity-log') : (string) $schema['error']); ?></span></li>
                        <li><span class="al-fact-k"><?php esc_html_e('Discarded', 'devdome-activity-log'); ?></span><span class="al-fact-v"><?php
                            printf(
                                /* translators: %d: number of events that could not be written. */
                                esc_html__('%d writes', 'devdome-activity-log'),
                                (int) $writes['failures']
                            );
                        ?></span></li>
                        <li><span class="al-fact-k"><?php esc_html_e('Schema', 'devdome-activity-log'); ?></span><span class="al-fact-v"><?php
                            printf(
                                /* translators: %d: storage schema version number. */
                                esc_html__('version %d', 'devdome-activity-log'),
                                (int) get_option('devdacti_schema_version', 0)
                            );
                        ?></span></li>
                    </ul>

                    <?php if (empty($schema['ok']) && devdacti_can('repair')) : ?>
                        <form method="post" style="margin-top:14px;border-top:1px solid #f3f4f6;padding-top:14px;">
                            <?php wp_nonce_field('devdacti_repair', '_alrn'); ?>
                            <input type="hidden" name="devdacti_repair_now" value="1">
                            <p class="dd-hint" style="margin:0 0 8px;">
                                <?php
                                $missing_cols = isset($schema['missing']) ? (array) $schema['missing'] : array();
                                $missing_keys = isset($schema['missing_key']) ? (array) $schema['missing_key'] : array();
                                if ($missing_cols || $missing_keys) {
                                    printf(
                                        /* translators: 1: comma separated column names, 2: comma separated index names. */
                                        esc_html__('A repair re-applies the storage schema with dbDelta. It will add the missing columns (%1$s) and indexes (%2$s) and may alter a column definition to match. No stored event is deleted by it; take a database backup first.', 'devdome-activity-log'),
                                        esc_html($missing_cols ? implode(', ', $missing_cols) : __('none', 'devdome-activity-log')),
                                        esc_html($missing_keys ? implode(', ', $missing_keys) : __('none', 'devdome-activity-log'))
                                    );
                                } else {
                                    esc_html_e('A repair re-applies the storage schema with dbDelta and may alter a column definition to match. No stored event is deleted by it; take a database backup first.', 'devdome-activity-log');
                                }
                                ?>
                            </p>
                            <p class="dd-hint" style="margin:0 0 8px;">
                                <?php esc_html_e('Adding a column or an index to a large table can take a while and locks it on some MySQL versions. Take a database backup first, and run it when the site is quiet.', 'devdome-activity-log'); ?>
                            </p>
                            <label class="dd-opt" for="al_confirm_repair"><input type="checkbox" class="dd-check" id="al_confirm_repair" name="al_confirm_repair" value="1"> <?php esc_html_e('I have a backup, run the repair', 'devdome-activity-log'); ?></label>
                            <p style="margin-top:10px;"><button type="submit" class="dd-btn"><?php esc_html_e('Repair Storage', 'devdome-activity-log'); ?></button></p>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Sticky save footer = DESIGN.md 16 (Save button) + 16.1 (pending counter), copied from Admin Cleaner
 * devdadcl_save_footer() 1:1. Rendered INSIDE the settings <form> so the button submits it.
 */
function devdacti_save_footer($saved)
{
    ?>
    <footer class="dd-footer" style="margin:2rem 0 0;">
        <div class="dd-footer-inner">
            <div class="dd-footer-actions">
                <button type="submit" class="dd-btn-primary" style="gap:8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
                    <?php esc_html_e('Save Settings', 'devdome-activity-log'); ?>
                </button>
                <?php if ($saved) : ?><span class="dd-saved" style="color:#059669;font-weight:600;font-size:13px;"><?php esc_html_e('Saved.', 'devdome-activity-log'); ?></span><?php endif; ?>
                <?php /* translators: %d is the number of settings changed but not yet saved. */ ?>
                <span class="dd-pending" hidden style="color:#b45309;font-weight:600;font-size:13px;" data-one="<?php esc_attr_e('1 change not saved', 'devdome-activity-log'); ?>" data-many="<?php esc_attr_e('%d changes not saved', 'devdome-activity-log'); ?>"></span>
            </div>
        </div>
    </footer>
    <?php
}
