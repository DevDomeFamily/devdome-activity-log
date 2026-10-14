<?php
/**
 * System tracker: plugin activate/deactivate/install/update/delete, theme
 * install/update/delete/switch, core updates, and changes to a hard allowlist
 * of core options. Performance-critical: the updated_option handler bails on
 * its FIRST line for anything outside the allowlist. All writes go through
 * devdacti_log().
 */

defined('ABSPATH') || exit;

/**
 * The ONLY core options ever logged. template/stylesheet are deliberately
 * absent (theme_switched is the single authoritative row for a theme change);
 * active_plugins is handled by the plugin hooks, never here.
 */
function devdacti_option_allowlist()
{
    return array(
        'blogname',
        'siteurl',
        'home',
        'admin_email',
        'users_can_register',
        'default_role',
        'permalink_structure',
        // The site clock decides what every stored UTC timestamp MEANS to a reader and which
        // hour an aggregated bucket falls in, so moving it is an audit-relevant act.
        'timezone_string',
        'gmt_offset',
    );
}

/**
 * Internal: human plugin name from its main file when still readable,
 * fallback = the plugin directory (or the file itself for single-file plugins).
 */
function devdacti_plugin_name($plugin_file)
{
    $plugin_file = (string) $plugin_file;
    $path        = WP_PLUGIN_DIR . '/' . $plugin_file;

    if ($plugin_file !== '' && is_readable($path)) {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data($path, false, false);
        if (!empty($data['Name'])) {
            return $data['Name'];
        }
    }

    $dir = dirname($plugin_file);
    return ('.' === $dir || '' === $dir) ? $plugin_file : $dir;
}

/**
 * Version capture. `upgrader_process_complete` runs after the new files are in place, so the
 * old version is only knowable BEFORE the install — 0.1.2 recorded neither number and the
 * log could not answer "what was it before?". Captured per request, never persisted.
 *
 * @param mixed $response  the upgrader's running response (returned untouched)
 * @param array $hook_extra plugin/theme being processed
 */
function devdacti_capture_versions($response, $hook_extra)
{
    if (!is_array($hook_extra)) {
        return $response;
    }

    if (!empty($hook_extra['plugin'])) {
        $file = (string) $hook_extra['plugin'];
        $path = WP_PLUGIN_DIR . '/' . $file;
        if (is_readable($path)) {
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $data = get_plugin_data($path, false, false);
            $GLOBALS['devdacti_old_versions']['plugin:' . $file] = !empty($data['Version']) ? (string) $data['Version'] : '';
        }
    }

    if (!empty($hook_extra['theme'])) {
        $stylesheet = (string) $hook_extra['theme'];
        $theme      = wp_get_theme($stylesheet);
        if ($theme instanceof WP_Theme && $theme->exists()) {
            $GLOBALS['devdacti_old_versions']['theme:' . $stylesheet] = (string) $theme->get('Version');
        }
    }

    return $response;
}

/**
 * The "from version" snapshot. Taken ONCE per request, before any update can start, on the screens and entry
 * points that start one (the update screens, the AJAX updaters, the automatic updater, WP-CLI). WordPress.org
 * reviewers read any `upgrader_*` FILTER as touching the updater, so the previous version is captured here instead
 * of on `upgrader_pre_install`; the single `upgrader_process_complete` ACTION that remains only reads the result.
 */
function devdacti_snapshot_versions()
{
    static $captured = false;
    if ($captured) {
        return;
    }
    $captured = true;

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (function_exists('get_plugins')) {
        foreach (array_keys((array) get_plugins()) as $file) {
            devdacti_capture_versions(null, array('plugin' => (string) $file));
        }
    }
    if (function_exists('wp_get_themes')) {
        foreach (array_keys((array) wp_get_themes()) as $stylesheet) {
            devdacti_capture_versions(null, array('theme' => (string) $stylesheet));
        }
    }
}

/**
 * Did an update really complete? WordPress hands the REQUESTED list to upgrader_process_complete, failures
 * included. Hard evidence first: the installed version moved. Without it (the previous version was not
 * observable, or did not change) the upgrader's own error state decides, and an unknown previous version
 * never establishes success on its own when the upgrader errored.
 */
function devdacti_update_completed($upgrader, $old_version, $new_version)
{
    $old_version = (string) $old_version;
    $new_version = (string) $new_version;
    // Versions first: in a bulk update WordPress hands EVERY item the last item's error state, so the upgrader's
    // error can only decide when the version evidence is missing.
    if ('' !== $old_version && '' !== $new_version) {
        return $new_version !== $old_version;
    }
    return '' !== $new_version && !devdacti_upgrader_errored($upgrader);
}

/** Did the upgrader report an error (its own result or its skin's)? Used for updates AND installs. */
function devdacti_upgrader_errored($upgrader)
{
    if (!is_object($upgrader)) {
        return false;
    }
    if (isset($upgrader->result) && is_wp_error($upgrader->result)) {
        return true;
    }
    return isset($upgrader->skin) && is_object($upgrader->skin) && isset($upgrader->skin->result) && is_wp_error($upgrader->skin->result);
}

/** The version captured before this update, '' when it was not observable. */
function devdacti_old_version($key)
{
    return isset($GLOBALS['devdacti_old_versions'][$key]) ? (string) $GLOBALS['devdacti_old_versions'][$key] : '';
}

/** "1.2.3 -> 1.3.0", or just the installed version when the old one was not observable. */
function devdacti_version_detail($old, $new)
{
    $old = (string) $old;
    $new = (string) $new;
    if ('' !== $old && '' !== $new) {
        return sprintf(
            /* translators: 1: previous version, 2: newly installed version. */
            __('Version %1$s -> %2$s', 'devdome-activity-log'),
            $old,
            $new
        );
    }
    if ('' !== $new) {
        /* translators: %s: newly installed version. */
        return sprintf(__('Version %s installed', 'devdome-activity-log'), $new);
    }
    return '';
}

/** Plugin activated. */
function devdacti_track_plugin_activated($plugin, $network_wide = false)
{
    devdacti_log(array(
        'action'       => 'plugin_activated',
        'object_type'  => 'plugin',
        'object_title' => devdacti_plugin_name($plugin),
        'details'      => 'Plugin: ' . $plugin . ($network_wide ? ' (network wide)' : ''),
    ));
}

/** Plugin deactivated. */
function devdacti_track_plugin_deactivated($plugin, $network_wide = false)
{
    devdacti_log(array(
        'action'       => 'plugin_deactivated',
        'object_type'  => 'plugin',
        'object_title' => devdacti_plugin_name($plugin),
        'details'      => 'Plugin: ' . $plugin . ($network_wide ? ' (network wide)' : ''),
    ));
}

/** Plugin deleted — only when the delete actually succeeded. */
function devdacti_track_plugin_deleted($plugin_file, $deleted)
{
    if (!$deleted) {
        return;
    }

    devdacti_log(array(
        'action'       => 'plugin_deleted',
        'object_type'  => 'plugin',
        'object_title' => devdacti_plugin_name($plugin_file),
        'details'      => 'Plugin: ' . $plugin_file,
    ));
}

/** Theme deleted — only when the delete actually succeeded. */
function devdacti_track_theme_deleted($stylesheet, $deleted)
{
    if (!$deleted) {
        return;
    }

    devdacti_log(array(
        'action'       => 'theme_deleted',
        'object_type'  => 'theme',
        'object_title' => (string) $stylesheet,
        'details'      => 'Theme: ' . $stylesheet,
    ));
}

/** Theme switched — the single authoritative row for a theme change. */
function devdacti_track_theme_switched($new_name, $new_theme, $old_theme)
{
    $old_name = ($old_theme instanceof WP_Theme) ? (string) $old_theme->get('Name') : '';

    devdacti_log(array(
        'action'       => 'theme_switched',
        'object_type'  => 'theme',
        'object_title' => (string) $new_name,
        'details'      => 'Theme switched: "' . $old_name . '" -> "' . $new_name . '"',
    ));
}

/** WordPress core updated successfully. */
function devdacti_track_core_updated($wp_version)
{
    devdacti_log(array(
        'action'       => 'core_updated',
        'object_type'  => 'core',
        'object_title' => 'WordPress',
        'details'      => 'WordPress core updated to ' . $wp_version,
    ));
}

/**
 * Installs and updates via the upgrader. Core updates are owned by
 * _core_updated_successfully and skipped here (no double row); translation
 * runs are ignored.
 */
function devdacti_track_upgrader($upgrader, $extra)
{
    if (!is_array($extra) || empty($extra['type']) || empty($extra['action'])) {
        return;
    }

    $type   = $extra['type'];
    $action = $extra['action'];

    if ('plugin' === $type) {
        if ('update' === $action) {
            $plugins = (!empty($extra['plugins']) && is_array($extra['plugins'])) ? $extra['plugins'] : array();
            if (!$plugins && !empty($extra['plugin'])) {
                $plugins = array($extra['plugin']);
            }
            foreach ($plugins as $file) {
                $new_version = '';
                $path = WP_PLUGIN_DIR . '/' . $file;
                if (is_readable($path)) {
                    if (!function_exists('get_plugin_data')) {
                        require_once ABSPATH . 'wp-admin/includes/plugin.php';
                    }
                    $data = get_plugin_data($path, false, false);
                    $new_version = !empty($data['Version']) ? (string) $data['Version'] : '';
                }
                $old_version = devdacti_old_version('plugin:' . $file);
                $versions    = devdacti_version_detail($old_version, $new_version);
                // WordPress hands the REQUESTED list to this hook, failures included. The update is
                // taken as completed only when the installed version really moved (or when the
                // previous version could not be observed and the upgrader reports no error).
                $done = devdacti_update_completed($upgrader, $old_version, $new_version);
                devdacti_log(array(
                    'action'       => 'plugin_updated',
                    'object_type'  => 'plugin',
                    'object_title' => devdacti_plugin_name($file),
                    'outcome'      => $done ? 'success' : 'failure',
                    'details'      => 'Plugin: ' . $file . ('' !== $versions ? '; ' . $versions : '') . ($done ? '' : '; update did not complete (installed version unchanged or the upgrader reported an error)'),
                ));
            }
        } elseif ('install' === $action) {
            // Failure first: upgrader_process_complete fires for failed installs too, and then plugin_info() reads
            // a WP_Error result as an array (fatal), so the info calls happen only for a completed install.
            $install_failed = devdacti_upgrader_errored($upgrader);
            $file  = '';
            $title = '';
            if (!$install_failed) {
                if (is_object($upgrader) && method_exists($upgrader, 'plugin_info')) {
                    $file = (string) $upgrader->plugin_info();
                }
                $title = ('' !== $file) ? devdacti_plugin_name($file) : '';
                if ('' === $title && is_object($upgrader) && is_array($upgrader->result) && !empty($upgrader->result['destination_name'])) {
                    $title = (string) $upgrader->result['destination_name'];
                }
            }
            if ('' === $title) {
                if (!$install_failed) {
                    return;
                }
                $title = __('(unknown plugin: the install failed before it was named)', 'devdome-activity-log');
            }
            devdacti_log(array(
                'action'       => 'plugin_installed',
                'object_type'  => 'plugin',
                'object_title' => $title,
                'outcome'      => $install_failed ? 'failure' : 'success',
                'details'      => (('' !== $file) ? 'Plugin: ' . $file : '') . ($install_failed ? '; the install did not complete (the upgrader reported an error)' : ''),
            ));
        }
    } elseif ('theme' === $type) {
        if ('update' === $action) {
            $themes = (!empty($extra['themes']) && is_array($extra['themes'])) ? $extra['themes'] : array();
            if (!$themes && !empty($extra['theme'])) {
                $themes = array($extra['theme']);
            }
            foreach ($themes as $stylesheet) {
                $theme = wp_get_theme($stylesheet);
                $name  = ($theme instanceof WP_Theme && $theme->exists()) ? (string) $theme->get('Name') : (string) $stylesheet;
                $new_version = '';
                if ($theme instanceof WP_Theme && $theme->exists()) {
                    // From disk: the WP_Theme header may still be the one cached when the snapshot ran before the update.
                    $style = $theme->get_stylesheet_directory() . '/style.css';
                    if (function_exists('get_file_data') && is_readable($style)) {
                        $headers     = get_file_data($style, array('Version' => 'Version'));
                        $new_version = !empty($headers['Version']) ? (string) $headers['Version'] : '';
                    }
                    if ('' === $new_version) {
                        $new_version = (string) $theme->get('Version');
                    }
                }
                $old_version = devdacti_old_version('theme:' . $stylesheet);
                $versions    = devdacti_version_detail($old_version, $new_version);
                $done        = devdacti_update_completed($upgrader, $old_version, $new_version);
                devdacti_log(array(
                    'action'       => 'theme_updated',
                    'object_type'  => 'theme',
                    'object_title' => $name,
                    'outcome'      => $done ? 'success' : 'failure',
                    'details'      => 'Theme: ' . $stylesheet . ('' !== $versions ? '; ' . $versions : '') . ($done ? '' : '; update did not complete (installed version unchanged or the upgrader reported an error)'),
                ));
            }
        } elseif ('install' === $action) {
            // Failure first (see the plugin branch): theme_info() reads a WP_Error result as an array.
            $install_failed = devdacti_upgrader_errored($upgrader);
            $name       = '';
            $stylesheet = '';
            if (!$install_failed) {
                if (is_object($upgrader) && method_exists($upgrader, 'theme_info') && is_array($upgrader->result)) {
                    $theme = $upgrader->theme_info();
                    if ($theme instanceof WP_Theme) {
                        $name       = (string) $theme->get('Name');
                        $stylesheet = (string) $theme->get_stylesheet();
                    }
                }
                if ('' === $name && is_object($upgrader) && is_array($upgrader->result) && !empty($upgrader->result['destination_name'])) {
                    $name       = (string) $upgrader->result['destination_name'];
                    $stylesheet = $name;
                }
            }
            if ('' === $name) {
                if (!$install_failed) {
                    return;
                }
                $name = __('(unknown theme: the install failed before it was named)', 'devdome-activity-log');
            }
            devdacti_log(array(
                'action'       => 'theme_installed',
                'object_type'  => 'theme',
                'object_title' => $name,
                'outcome'      => $install_failed ? 'failure' : 'success',
                'details'      => (('' !== $stylesheet) ? 'Theme: ' . $stylesheet : '') . ($install_failed ? '; the install did not complete (the upgrader reported an error)' : ''),
            ));
        }
    }
    // core + update → handled by _core_updated_successfully; skipped here.
}

/**
 * Core option changed. FIRST line bails for anything outside the allowlist —
 * updated_option fires constantly and this must stay free for every other option.
 */
function devdacti_track_option_updated($option, $old_value, $value)
{
    if (!in_array($option, devdacti_option_allowlist(), true)) {
        return;
    }

    if (maybe_serialize($old_value) === maybe_serialize($value)) {
        return; // no real change.
    }

    // Redact the COMPLETE original value first: truncating a quoted credential open would let the cut-off
    // tail past the matcher at write time.
    $old = devdacti_substr(sanitize_text_field(devdacti_redact(is_scalar($old_value) ? (string) $old_value : (string) maybe_serialize($old_value))), 0, 200);
    $new = devdacti_substr(sanitize_text_field(devdacti_redact(is_scalar($value) ? (string) $value : (string) maybe_serialize($value))), 0, 200);

    devdacti_log(array(
        'action'       => 'option_updated',
        'object_type'  => 'option',
        'object_title' => $option,
        'details'      => $option . ': "' . $old . '" -> "' . $new . '"',
    ));
}

add_action('activated_plugin', 'devdacti_track_plugin_activated', 10, 2);
add_action('deactivated_plugin', 'devdacti_track_plugin_deactivated', 10, 2);
add_action('deleted_plugin', 'devdacti_track_plugin_deleted', 10, 2);
add_action('deleted_theme', 'devdacti_track_theme_deleted', 10, 2);
add_action('switch_theme', 'devdacti_track_theme_switched', 10, 3);
add_action('_core_updated_successfully', 'devdacti_track_core_updated', 10, 1);
// The previous versions are snapshotted where an update can START (never an upgrader_* filter, see
// devdacti_snapshot_versions); the completion ACTION below only records what happened.
foreach (array('load-update.php', 'load-update-core.php', 'load-plugins.php', 'load-themes.php', 'wp_ajax_update-plugin', 'wp_ajax_update-theme', 'wp_maybe_auto_update') as $devdacti_snapshot_hook) {
    add_action($devdacti_snapshot_hook, 'devdacti_snapshot_versions', 0, 0);
}
unset($devdacti_snapshot_hook);
if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    WP_CLI::add_hook('after_wp_load', 'devdacti_snapshot_versions');
}
add_action('upgrader_process_complete', 'devdacti_track_upgrader', 10, 2);
add_action('updated_option', 'devdacti_track_option_updated', 10, 3);
