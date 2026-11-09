<?php
/**
 * Third-party events (1.0.4): WooCommerce orders and settings, ACF field groups, Yoast SEO settings. Group
 * `integrations` in the catalog. Every hook is harmless when the plugin in question is not installed: an action
 * nobody fires is never run, and the Yoast tracker checks the option name before anything else.
 */

defined('ABSPATH') || exit;

/* --------------------------------- WooCommerce --------------------------------- */

function devdacti_track_woo_order_status($order_id, $from, $to, $order = null)
{
    devdacti_log(array(
        'action'       => 'woo_order_status_changed',
        'object_type'  => 'order',
        'object_id'    => (int) $order_id,
        'object_title' => 'Order #' . (int) $order_id,
        'details'      => 'Status: "' . sanitize_key((string) $from) . '" -> "' . sanitize_key((string) $to) . '"',
    ));
}
add_action('woocommerce_order_status_changed', 'devdacti_track_woo_order_status', 10, 4);

/** Fires once after a WooCommerce settings tab is saved; the tab is the only safe detail (values can hold keys). */
function devdacti_track_woo_settings_saved()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which settings tab was just saved; WooCommerce verified the save itself.
    $tab = isset($_REQUEST['tab']) ? sanitize_key(wp_unslash($_REQUEST['tab'])) : 'general';
    devdacti_log(array(
        'action'       => 'woo_settings_saved',
        'object_type'  => 'option',
        'object_title' => 'WooCommerce settings',
        'details'      => 'Tab: ' . ($tab !== '' ? $tab : 'general'),
    ));
}
add_action('woocommerce_update_options', 'devdacti_track_woo_settings_saved', 10, 0);

/* ------------------------------------- ACF ------------------------------------- */

function devdacti_acf_group_title($field_group)
{
    if (!is_array($field_group)) {
        return 'Field group';
    }
    $title = isset($field_group['title']) ? (string) $field_group['title'] : '';
    return $title !== '' ? $title : (isset($field_group['key']) ? (string) $field_group['key'] : 'Field group');
}

function devdacti_track_acf_group_saved($field_group)
{
    devdacti_log(array(
        'action'       => 'acf_field_group_saved',
        'object_type'  => 'field_group',
        'object_id'    => (is_array($field_group) && isset($field_group['ID'])) ? (int) $field_group['ID'] : 0,
        'object_title' => devdacti_acf_group_title($field_group),
        'details'      => (is_array($field_group) && isset($field_group['key'])) ? 'Key: ' . sanitize_text_field((string) $field_group['key']) : '',
    ));
}
add_action('acf/update_field_group', 'devdacti_track_acf_group_saved', 10, 1);

function devdacti_track_acf_group_deleted($field_group)
{
    devdacti_log(array(
        'action'       => 'acf_field_group_deleted',
        'object_type'  => 'field_group',
        'object_id'    => (is_array($field_group) && isset($field_group['ID'])) ? (int) $field_group['ID'] : 0,
        'object_title' => devdacti_acf_group_title($field_group),
    ));
}
add_action('acf/delete_field_group', 'devdacti_track_acf_group_deleted', 10, 1);
add_action('acf/trash_field_group', 'devdacti_track_acf_group_deleted', 10, 1);

/* ------------------------------------ Yoast ------------------------------------ */

/** Yoast stores its settings in wpseo* options; one row per saved option naming the keys that changed. */
function devdacti_track_yoast_option($option, $old_value, $value)
{
    if (strpos((string) $option, 'wpseo') !== 0) {
        return;
    }
    if (maybe_serialize($old_value) === maybe_serialize($value)) {
        return;
    }
    $keys = devdacti_changed_keys($old_value, $value, 10);
    devdacti_log(array(
        'action'       => 'yoast_settings_saved',
        'object_type'  => 'option',
        'object_title' => 'Yoast SEO: ' . sanitize_key((string) $option),
        'details'      => $keys ? 'Changed: ' . implode(', ', array_map('sanitize_text_field', $keys)) : '',
    ));
}
add_action('updated_option', 'devdacti_track_yoast_option', 10, 3);
