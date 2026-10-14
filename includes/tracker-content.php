<?php
/**
 * Content tracker: post/page/CPT lifecycle. One hook (wp_after_insert_post)
 * covers created / updated / status transitions with real-change detection
 * (title, slug, status called out; content/excerpt as md5 flags — an update
 * with no real change writes NO row). Trash, restore and permanent delete are
 * owned by their dedicated hooks so nothing is double-logged. All writes go
 * through devdacti_log().
 */

defined('ABSPATH') || exit;

/**
 * Internal: true when a post must never be logged — housekeeping post types,
 * transient statuses, or unregistered types.
 */
function devdacti_skip_post($post)
{
    if (!is_object($post) || empty($post->post_type)) {
        return true;
    }

    $skip_types = array('revision', 'attachment', 'nav_menu_item', 'customize_changeset', 'oembed_cache');
    if (in_array($post->post_type, $skip_types, true) || strpos($post->post_type, 'wp_template') === 0) {
        return true;
    }

    if (in_array($post->post_status, array('auto-draft', 'inherit'), true)) {
        return true;
    }

    if (!post_type_exists($post->post_type)) {
        return true;
    }

    return false;
}

/**
 * Internal: human detail strings for ONLY the real changes between two posts.
 * Empty array = nothing actually changed.
 */
function devdacti_post_diff($before, $after)
{
    $diff = array();

    if (!is_object($before) || !is_object($after)) {
        return $diff;
    }

    if ((string) $before->post_title !== (string) $after->post_title) {
        $diff[] = 'Title: "' . $before->post_title . '" -> "' . $after->post_title . '"';
    }

    if ((string) $before->post_name !== (string) $after->post_name) {
        $diff[] = 'Slug: "' . $before->post_name . '" -> "' . $after->post_name . '"';
    }

    if ((string) $before->post_status !== (string) $after->post_status) {
        $diff[] = 'Status: ' . $before->post_status . ' -> ' . $after->post_status;
    }

    if (md5((string) $before->post_content) !== md5((string) $after->post_content)) {
        $diff[] = 'Content changed';
    }

    if (md5((string) $before->post_excerpt) !== md5((string) $after->post_excerpt)) {
        $diff[] = 'Excerpt changed';
    }

    // Visibility and interaction settings: a password set or removed (never the password itself), comments or
    // pings opened or closed, a new parent.
    if ((string) $before->post_password !== (string) $after->post_password) {
        $diff[] = '' === (string) $after->post_password ? 'Password removed' : ('' === (string) $before->post_password ? 'Password set' : 'Password changed');
    }
    if ((string) $before->comment_status !== (string) $after->comment_status) {
        $diff[] = 'Comments: ' . $before->comment_status . ' -> ' . $after->comment_status;
    }
    if ((string) $before->ping_status !== (string) $after->ping_status) {
        $diff[] = 'Pings: ' . $before->ping_status . ' -> ' . $after->ping_status;
    }
    if ((int) $before->post_parent !== (int) $after->post_parent) {
        $diff[] = 'Parent: ' . (int) $before->post_parent . ' -> ' . (int) $after->post_parent;
    }
    if ((int) $before->post_author !== (int) $after->post_author) {
        $diff[] = 'Author: user #' . (int) $before->post_author . ' -> user #' . (int) $after->post_author;
    }

    return $diff;
}

/**
 * The ONE created/updated/status hook. Decides between post_created,
 * post_status_changed and post_updated; writes nothing when nothing really
 * changed. Trash transitions are skipped here — trashed_post/untrashed_post
 * own those rows.
 */
function devdacti_track_post_saved($post_id, $post, $update, $post_before)
{
    if (devdacti_skip_post($post)) {
        return;
    }

    if ('trash' === $post->post_status || ($post_before && 'trash' === $post_before->post_status)) {
        return;
    }

    $created = !$update || ($post_before && in_array($post_before->post_status, array('auto-draft', 'new'), true));

    $details = array();
    if (!in_array($post->post_type, array('post', 'page'), true)) {
        $details[] = 'Type: ' . $post->post_type;
    }

    if ($created) {
        $action    = 'post_created';
        $details[] = 'Status: ' . $post->post_status;
    } else {
        $diff = devdacti_post_diff($post_before, $post);
        if (!$diff) {
            return; // only real changes get a row.
        }
        $status_changed = $post_before && (string) $post_before->post_status !== (string) $post->post_status;
        $action         = $status_changed ? 'post_status_changed' : 'post_updated';
        $details        = array_merge($details, $diff);
    }

    devdacti_log(array(
        'action'       => $action,
        'object_type'  => 'post',
        'object_id'    => (int) $post_id,
        'object_title' => ('' !== $post->post_title) ? $post->post_title : '(no title)',
        'details'      => implode('; ', $details),
    ));
}

/** Post moved to trash. */
function devdacti_track_post_trashed($post_id, $previous_status = '')
{
    $post = get_post($post_id);
    if (!$post || devdacti_skip_post($post)) {
        return;
    }

    $details = array();
    if (!in_array($post->post_type, array('post', 'page'), true)) {
        $details[] = 'Type: ' . $post->post_type;
    }
    if ('' !== (string) $previous_status) {
        $details[] = 'Previous status: ' . $previous_status;
    }

    devdacti_log(array(
        'action'       => 'post_trashed',
        'object_type'  => 'post',
        'object_id'    => (int) $post_id,
        'object_title' => ('' !== $post->post_title) ? $post->post_title : '(no title)',
        'details'      => implode('; ', $details),
    ));
}

/** Post restored from trash. */
function devdacti_track_post_untrashed($post_id, $previous_status = '')
{
    $post = get_post($post_id);
    if (!$post || devdacti_skip_post($post)) {
        return;
    }

    $details = array();
    if (!in_array($post->post_type, array('post', 'page'), true)) {
        $details[] = 'Type: ' . $post->post_type;
    }
    if ('' !== (string) $previous_status) {
        $details[] = 'Previous status: ' . $previous_status;
    }

    devdacti_log(array(
        'action'       => 'post_restored',
        'object_type'  => 'post',
        'object_id'    => (int) $post_id,
        'object_title' => ('' !== $post->post_title) ? $post->post_title : '(no title)',
        'details'      => implode('; ', $details),
    ));
}

/**
 * Permanent delete, recorded on deleted_post: WordPress fires it only after the row and its
 * dependants are really gone, with the pre-deletion post object, so a deletion that failed half-way
 * leaves no "deleted" record for an object that still exists. Attachments are the media tracker's.
 */
function devdacti_track_post_deleted($post_id, $post = null)
{
    if (!($post instanceof WP_Post)) {
        $post = get_post($post_id);
    }
    if (!$post || 'attachment' === (string) $post->post_type || devdacti_skip_post($post)) {
        return;
    }

    $details = array();
    if (!in_array($post->post_type, array('post', 'page'), true)) {
        $details[] = 'Type: ' . $post->post_type;
    }
    $details[] = 'Status: ' . $post->post_status;

    devdacti_log(array(
        'action'       => 'post_deleted',
        'object_type'  => 'post',
        'object_id'    => (int) $post_id,
        'object_title' => ('' !== $post->post_title) ? $post->post_title : '(no title)',
        'details'      => implode('; ', $details),
    ));
}

add_action('wp_after_insert_post', 'devdacti_track_post_saved', 10, 4);
add_action('trashed_post', 'devdacti_track_post_trashed', 10, 2);
add_action('untrashed_post', 'devdacti_track_post_untrashed', 10, 2);
add_action('deleted_post', 'devdacti_track_post_deleted', 10, 2);
