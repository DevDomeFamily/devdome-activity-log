<?php
/**
 * Site structure events (1.0.4): terms (categories, tags, custom taxonomies), navigation menus, widget areas and
 * the Customizer. Groups `taxonomy` and `appearance` in the catalog, both switchable on the Settings tab.
 *
 * The diff helpers are pure (no WordPress calls) so the CLI suite proves them; the hooks below only collect
 * before/after values and hand the words to devdacti_log().
 */

defined('ABSPATH') || exit;

/** Taxonomies with their own events (menus) or no human meaning (link categories of the menu system). */
function devdacti_term_skip_taxonomy($taxonomy)
{
    return in_array((string) $taxonomy, array('nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category'), true);
}

/**
 * Human detail strings for the fields of a term that really changed. Pure.
 *
 * @param array $before name, slug, description, parent (any subset)
 * @param array $after  same keys
 * @return string[]
 */
function devdacti_term_diff(array $before, array $after)
{
    $out = array();
    foreach (array('name' => 'Name', 'slug' => 'Slug', 'description' => 'Description', 'parent' => 'Parent') as $key => $label) {
        $b = isset($before[$key]) ? (string) $before[$key] : '';
        $a = isset($after[$key]) ? (string) $after[$key] : '';
        if ($b === $a) {
            continue;
        }
        if ($key === 'description') {
            $out[] = 'Description changed';
            continue;
        }
        $out[] = $label . ': "' . devdacti_substr($b, 0, 80) . '" -> "' . devdacti_substr($a, 0, 80) . '"';
    }
    return $out;
}

/**
 * Human summary of a sidebars_widgets change: which widget areas gained or lost widgets. Pure.
 *
 * @param mixed $old the option value before (sidebar => array of widget ids)
 * @param mixed $new the option value after
 * @return string[] e.g. array('sidebar-1: 3 -> 4 widgets')
 */
function devdacti_sidebars_diff($old, $new)
{
    $out  = array();
    $old  = is_array($old) ? $old : array();
    $new  = is_array($new) ? $new : array();
    $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
    foreach ($keys as $k) {
        if ($k === 'array_version') {
            continue;
        }
        $b = isset($old[$k]) && is_array($old[$k]) ? array_values($old[$k]) : array();
        $a = isset($new[$k]) && is_array($new[$k]) ? array_values($new[$k]) : array();
        if ($b === $a) {
            continue;
        }
        $out[] = (string) $k . ': ' . count($b) . ' -> ' . count($a) . ' widgets';
    }
    return $out;
}

/**
 * The top-level keys whose value differs between two arrays (added, removed or changed), capped. Pure.
 *
 * @return string[]
 */
function devdacti_changed_keys($old, $new, $cap = 10)
{
    $old  = is_array($old) ? $old : array();
    $new  = is_array($new) ? $new : array();
    $keys = array();
    foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
        $b = array_key_exists($k, $old) ? maybe_serialize($old[$k]) : null;
        $a = array_key_exists($k, $new) ? maybe_serialize($new[$k]) : null;
        if ($b !== $a) {
            $keys[] = (string) $k;
        }
    }
    sort($keys);
    if (count($keys) > $cap) {
        $more = count($keys) - $cap;
        $keys = array_slice($keys, 0, $cap);
        $keys[] = '+' . $more . ' more';
    }
    return $keys;
}

/* ------------------------------------ terms ------------------------------------ */

function devdacti_term_fields($term)
{
    if (!is_object($term)) {
        return array();
    }
    return array(
        'name'        => isset($term->name) ? (string) $term->name : '',
        'slug'        => isset($term->slug) ? (string) $term->slug : '',
        'description' => isset($term->description) ? (string) $term->description : '',
        'parent'      => isset($term->parent) ? (string) (int) $term->parent : '0',
    );
}

function devdacti_track_term_created($term_id, $tt_id, $taxonomy)
{
    if (devdacti_term_skip_taxonomy($taxonomy)) {
        return;
    }
    $term = get_term((int) $term_id, (string) $taxonomy);
    if (!$term || is_wp_error($term)) {
        return;
    }
    devdacti_log(array(
        'action'       => 'term_created',
        'object_type'  => 'term',
        'object_id'    => (int) $term_id,
        'object_title' => (string) $term->name,
        'details'      => 'Taxonomy: ' . $taxonomy . '; Slug: ' . $term->slug,
    ));
}
add_action('created_term', 'devdacti_track_term_created', 10, 3);

/** The term as it was, kept for the edited_term hook of the same request (edit_terms fires right before the update). */
function devdacti_term_before($term_id, $taxonomy)
{
    if (devdacti_term_skip_taxonomy($taxonomy)) {
        return;
    }
    $term = get_term((int) $term_id, (string) $taxonomy);
    if ($term && !is_wp_error($term)) {
        $GLOBALS['devdacti_term_before'][(int) $term_id] = devdacti_term_fields($term);
    }
}
add_action('edit_terms', 'devdacti_term_before', 10, 2);

function devdacti_track_term_updated($term_id, $tt_id, $taxonomy)
{
    if (devdacti_term_skip_taxonomy($taxonomy)) {
        return;
    }
    $term = get_term((int) $term_id, (string) $taxonomy);
    if (!$term || is_wp_error($term)) {
        return;
    }
    $before = isset($GLOBALS['devdacti_term_before'][(int) $term_id]) ? $GLOBALS['devdacti_term_before'][(int) $term_id] : array();
    unset($GLOBALS['devdacti_term_before'][(int) $term_id]);
    $diff = $before ? devdacti_term_diff($before, devdacti_term_fields($term)) : array('Updated');
    if (!$diff) {
        return; // only real changes get a row
    }
    devdacti_log(array(
        'action'       => 'term_updated',
        'object_type'  => 'term',
        'object_id'    => (int) $term_id,
        'object_title' => (string) $term->name,
        'details'      => 'Taxonomy: ' . $taxonomy . '; ' . implode('; ', $diff),
    ));
}
add_action('edited_term', 'devdacti_track_term_updated', 10, 3);

function devdacti_track_term_deleted($term_id, $tt_id, $taxonomy, $deleted_term)
{
    if (devdacti_term_skip_taxonomy($taxonomy)) {
        return;
    }
    devdacti_log(array(
        'action'       => 'term_deleted',
        'object_type'  => 'term',
        'object_id'    => (int) $term_id,
        'object_title' => (is_object($deleted_term) && isset($deleted_term->name)) ? (string) $deleted_term->name : '#' . (int) $term_id,
        'details'      => 'Taxonomy: ' . $taxonomy,
    ));
}
add_action('delete_term', 'devdacti_track_term_deleted', 10, 4);

/* ------------------------------------ menus ------------------------------------ */

function devdacti_menu_name($menu_id)
{
    $menu = wp_get_nav_menu_object((int) $menu_id);
    return ($menu && isset($menu->name)) ? (string) $menu->name : '#' . (int) $menu_id;
}

function devdacti_track_menu_created($menu_id, $menu_data = array())
{
    devdacti_log(array(
        'action'       => 'menu_created',
        'object_type'  => 'menu',
        'object_id'    => (int) $menu_id,
        'object_title' => isset($menu_data['menu-name']) ? (string) $menu_data['menu-name'] : devdacti_menu_name($menu_id),
    ));
}
add_action('wp_create_nav_menu', 'devdacti_track_menu_created', 10, 2);

/** One row per save of a menu (the Menus screen saves items and the menu together, this hook fires once). */
function devdacti_track_menu_updated($menu_id, $menu_data = null)
{
    if (!is_array($menu_data)) {
        return; // the no-data call is the item sync, the data call is the save that matters (one row, not two)
    }
    $items = wp_get_nav_menu_items((int) $menu_id);
    devdacti_log(array(
        'action'       => 'menu_updated',
        'object_type'  => 'menu',
        'object_id'    => (int) $menu_id,
        'object_title' => isset($menu_data['menu-name']) ? (string) $menu_data['menu-name'] : devdacti_menu_name($menu_id),
        'details'      => 'Items: ' . (is_array($items) ? count($items) : 0),
    ));
}
add_action('wp_update_nav_menu', 'devdacti_track_menu_updated', 10, 2);

/** The menu's name is gone by the time wp_delete_nav_menu fires: remember it on the term delete that precedes it. */
function devdacti_menu_before_delete($term_id, $taxonomy)
{
    if ((string) $taxonomy === 'nav_menu') {
        $GLOBALS['devdacti_menu_names'][(int) $term_id] = devdacti_menu_name($term_id);
    }
}
add_action('pre_delete_term', 'devdacti_menu_before_delete', 10, 2);

function devdacti_track_menu_deleted($menu_id)
{
    $name = isset($GLOBALS['devdacti_menu_names'][(int) $menu_id]) ? $GLOBALS['devdacti_menu_names'][(int) $menu_id] : '#' . (int) $menu_id;
    unset($GLOBALS['devdacti_menu_names'][(int) $menu_id]);
    devdacti_log(array(
        'action'       => 'menu_deleted',
        'object_type'  => 'menu',
        'object_id'    => (int) $menu_id,
        'object_title' => $name,
    ));
}
add_action('wp_delete_nav_menu', 'devdacti_track_menu_deleted', 10, 1);

/* ----------------------------------- widgets ----------------------------------- */

function devdacti_track_widgets_updated($old_value, $value)
{
    $diff = devdacti_sidebars_diff($old_value, $value);
    if (!$diff) {
        return;
    }
    devdacti_log(array(
        'action'       => 'widgets_updated',
        'object_type'  => 'widget_area',
        'object_title' => 'Widget areas',
        'details'      => implode('; ', $diff),
    ));
}
add_action('update_option_sidebars_widgets', 'devdacti_track_widgets_updated', 10, 2);

/* ---------------------------------- customizer --------------------------------- */

function devdacti_track_customizer_saved($manager)
{
    $keys = array();
    if (is_object($manager) && method_exists($manager, 'unsanitized_post_values')) {
        $values = $manager->unsanitized_post_values();
        $keys   = is_array($values) ? array_map('strval', array_keys($values)) : array();
    }
    sort($keys);
    if (count($keys) > 10) {
        $more = count($keys) - 10;
        $keys = array_slice($keys, 0, 10);
        $keys[] = '+' . $more . ' more';
    }
    devdacti_log(array(
        'action'       => 'customizer_saved',
        'object_type'  => 'customizer',
        'object_title' => 'Customizer',
        'details'      => $keys ? 'Settings: ' . implode(', ', $keys) : '',
    ));
}
add_action('customize_save_after', 'devdacti_track_customizer_saved', 10, 1);
