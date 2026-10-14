<?php
/**
 * Users / media / comments tracker: user create/delete/role-change/profile-update
 * (field NAMES only for passwords — never values), media uploads/deletes, and
 * comment status transitions. Profile updates record only real changes (empty
 * diff = no row). All writes go through devdacti_log().
 */

defined('ABSPATH') || exit;

/** New user registered. Never logs any password field. */
function devdacti_track_user_created($user_id)
{
    // wp_insert_user() calls set_role(), so add_user_role (and set_user_role with no previous
    // role) fired on the way here. This row describes the new account; the queued ones for the
    // roles it was created with would just repeat it.
    $user = get_userdata((int) $user_id);
    if (!$user) {
        devdacti_role_created($user_id, array());
        return;
    }
    // set_user_role (queued, no previous role) already covers the add of the role it named; cover only
    // the initial roles it did not, or a later remove + re-add of that role would be swallowed.
    $initial_roles = devdacti_roles_of($user);
    devdacti_role_created($user_id, $initial_roles);
    $already = array();
    foreach (devdacti_role_queue() as $queued) {
        if ('user_role_changed' === $queued['action'] && (int) $queued['object_id'] === (int) $user_id && isset($queued['role'])) {
            $already[(string) $queued['role']] = true;
        }
    }
    foreach ($initial_roles as $initial) {
        if (!isset($already[$initial])) {
            devdacti_role_superseded(devdacti_role_subject_key($user_id) . ':add:' . $initial);
        }
    }

    devdacti_log(array(
        'action'       => 'user_created',
        'object_type'  => 'user',
        'object_id'    => (int) $user_id,
        'object_title' => $user->user_login,
        // The address is masked: the audit log records that an account exists with a given
        // role, it is not a second copy of the site's mailing list.
        'details'      => 'Role: ' . implode(', ', devdacti_roles_of($user)) . '; Email: ' . devdacti_mask_email($user->user_email),
    ));
}

/** User deleted. $user is the WP_User of the deleted account. */
function devdacti_track_user_deleted($id, $reassign, $user)
{
    $login = ($user instanceof WP_User) ? $user->user_login : '';

    devdacti_log(array(
        'action'       => 'user_deleted',
        'object_type'  => 'user',
        'object_id'    => (int) $id,
        'object_title' => $login,
        'details'      => $reassign ? 'Content reassigned to user #' . (int) $reassign : 'Content not reassigned',
    ));
}

/**
 * Buffer for the add/remove-role hooks, flushed when the request ends.
 *
 * WordPress fires `remove_user_role` and `add_user_role` from INSIDE WP_User::set_role(),
 * and only afterwards fires `set_user_role` — and wp_insert_user() calls set_role() too. One
 * admin role change therefore reaches three hooks and produced three rows for a single
 * user-visible action. The add/remove rows are held until the end of the request instead,
 * and dropped when the authoritative set_user_role / user_register row for that account
 * already describes what happened.
 *
 * @param array|null $event queue an event, or null to read the queue
 * @param bool       $reset empty the queue (the flush consumes it)
 * @return array
 */
function devdacti_role_queue($event = null, $reset = false)
{
    static $queue = array();
    if ($reset) {
        $queue = array();
        return array();
    }
    if (null !== $event) {
        // Resolve the actor NOW, not at shutdown: by then the current user may be someone else.
        if (!array_key_exists('user_id', $event)) {
            $actor = devdacti_resolve_actor(array());
            $event['user_id']    = $actor['user_id'];
            $event['user_login'] = $actor['user_login'];
            $event['user_role']  = $actor['user_role'];
            $event['user_roles'] = $actor['user_roles'];
        }
        $event['_blog'] = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        $queue[] = $event;
    }
    return $queue;
}

/**
 * Individual role TRANSITIONS already described by a set_user_role / user_register row, as
 * "<user_id>:add:<role>" / "<user_id>:remove:<role>". Only those exact transitions are dropped
 * from the queue: a further add_role() on the same account in the same request is its own event.
 */
function devdacti_role_superseded($key = null, $reset = false)
{
    static $covered = array();
    if ($reset) {
        $covered = array();
        return array();
    }
    if (null !== $key) {
        // A COUNT per transition: each set_user_role covers one remove/add pair, and each queued
        // occurrence consumes one, so set_role('editor'); remove_role('editor'); add_role('editor')
        // keeps the second grant on record.
        $covered[(string) $key] = (isset($covered[(string) $key]) ? $covered[(string) $key] : 0) + 1;
    }
    return $covered;
}

/** "<blog>:<user_id>": one account can change roles on several sites in one request; each site is its own book. */
function devdacti_role_subject_key($user_id, $blog = null)
{
    if (null === $blog) {
        $blog = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    }
    return (int) $blog . ':' . (int) $user_id;
}

/** Accounts created in this request (user_register fired) => the roles they were created with, per site. */
function devdacti_role_created($user_id = null, $roles = null, $reset = false)
{
    static $ids = array();
    if ($reset) {
        $ids = array();
        return array();
    }
    if (null !== $user_id) {
        $ids[devdacti_role_subject_key($user_id)] = is_array($roles) ? array_values(array_map('strval', $roles)) : array();
    }
    return $ids;
}

/** Write the queued rows that nothing else already described. */
function devdacti_flush_role_queue()
{
    $covered = devdacti_role_superseded();
    $created = devdacti_role_created();
    $queued  = devdacti_role_queue();

    // Consume before writing: a second flush in the same request (WP-CLI runs several
    // "requests" in one process) must not write these rows again.
    devdacti_role_queue(null, true);
    devdacti_role_superseded(null, true);
    devdacti_role_created(null, null, true);

    foreach ($queued as $event) {
        $blog_of = isset($event['_blog']) ? (int) $event['_blog'] : 0;
        $uid     = devdacti_role_subject_key((int) $event['object_id'], $blog_of); // the site the event was queued on
        $key     = '';
        if ('user_role_added' === $event['action']) {
            $key = $uid . ':add:' . $event['role'];
        } elseif ('user_role_removed' === $event['action']) {
            $key = $uid . ':remove:' . $event['role'];
        }
        if ('' !== $key && !empty($covered[$key])) {
            $covered[$key]--; // consume ONE occurrence
            continue;
        }
        if ('user_role_changed' === $event['action'] && isset($created[$uid]) && isset($event['role'])) {
            // set_role() inside wp_insert_user(): the user_created row owns the INITIAL role, once. A later
            // set_role() on the new account in the same request (after a remove_role) is its own event.
            $at = array_search((string) $event['role'], $created[$uid], true);
            if (false !== $at) {
                unset($created[$uid][$at]);
                continue;
            }
        }
        unset($event['role']);
        $blog = isset($event['_blog']) ? (int) $event['_blog'] : 0;
        unset($event['_blog']);
        $switched = false;
        if ($blog > 0 && function_exists('is_multisite') && is_multisite() && function_exists('get_current_blog_id') && (int) get_current_blog_id() !== $blog) {
            switch_to_blog($blog); // write it to the site it happened on
            $switched = true;
        }
        devdacti_log($event);
        if ($switched) {
            restore_current_blog();
        }
    }
}
add_action('shutdown', 'devdacti_flush_role_queue', 5);

/**
 * Role changed (set_user_role). With previous roles this is written at once and covers the
 * remove/add pair set_role() fired on the way here. With NO previous role it is queued: during
 * wp_insert_user() the user_created row that follows owns it, while an existing roleless
 * account being granted a role is a real change and is written when the request ends.
 */
function devdacti_track_role_changed($user_id, $role, $old_roles)
{
    $old_roles = array_values(array_filter(array_map('strval', (array) $old_roles), 'strlen'));
    foreach ($old_roles as $old) {
        devdacti_role_superseded(devdacti_role_subject_key($user_id) . ':remove:' . $old);
    }
    devdacti_role_superseded(devdacti_role_subject_key($user_id) . ':add:' . (string) $role);

    $user  = get_userdata((int) $user_id);
    $event = array(
        'action'       => 'user_role_changed',
        'role'         => (string) $role,
        'object_type'  => 'user',
        'object_id'    => (int) $user_id,
        'object_title' => $user ? $user->user_login : '',
        'details'      => 'Role: ' . ($old_roles ? implode(', ', $old_roles) : '(none)') . ' -> ' . $role,
    );
    if (empty($old_roles)) {
        devdacti_role_queue($event);
        return;
    }
    unset($event['role']);
    devdacti_log($event);
}

/**
 * A role ADDED to an account. WordPress' add_role() does not fire set_user_role, so 0.1.2
 * recorded nothing at all for the most common way of granting extra capabilities.
 */
function devdacti_track_role_added($user_id, $role)
{
    $user = get_userdata((int) $user_id);

    devdacti_role_queue(array(
        'action'       => 'user_role_added',
        'role'         => (string) $role,
        'object_type'  => 'user',
        'object_id'    => (int) $user_id,
        'object_title' => $user ? $user->user_login : '',
        'details'      => sprintf(
            /* translators: %s: role slug added to the account. */
            __('Role added: %s', 'devdome-activity-log'),
            (string) $role
        ),
    ));
}

/** A role REMOVED from an account. remove_role() fired no event in 0.1.2 either. */
function devdacti_track_role_removed($user_id, $role)
{
    $user = get_userdata((int) $user_id);

    devdacti_role_queue(array(
        'action'       => 'user_role_removed',
        'role'         => (string) $role,
        'object_type'  => 'user',
        'object_id'    => (int) $user_id,
        'object_title' => $user ? $user->user_login : '',
        'details'      => sprintf(
            /* translators: %s: role slug removed from the account. */
            __('Role removed: %s', 'devdome-activity-log'),
            (string) $role
        ),
    ));
}

/**
 * Profile updated — only real changes get a row. Diffs user_email,
 * display_name, user_url, user_nicename (values OK, not secrets); a changed
 * password hash is recorded as the literal 'Password changed' — FIELD NAME
 * ONLY, never any value.
 */
function devdacti_track_profile_updated($user_id, $old_user_data, $userdata = array())
{
    if (!($old_user_data instanceof WP_User)) {
        return;
    }

    $user = get_userdata((int) $user_id);
    if (!$user) {
        return;
    }

    // An email change is its own action: it is the step that redirects account recovery,
    // so it must be findable without reading through profile edits. Both addresses are
    // masked — the log records that the recovery address moved, not what it now is.
    if ((string) $old_user_data->user_email !== (string) $user->user_email) {
        devdacti_log(array(
            'action'       => 'user_email_changed',
            'object_type'  => 'user',
            'object_id'    => (int) $user_id,
            'object_title' => $user->user_login,
            'details'      => 'Email: "' . devdacti_mask_email($old_user_data->user_email) . '" -> "' . devdacti_mask_email($user->user_email) . '"',
        ));
    }

    $changed = array();
    foreach (array('display_name', 'user_url', 'user_nicename') as $field) {
        if ((string) $old_user_data->$field !== (string) $user->$field) {
            $changed[] = $field . ': "' . devdacti_mask_emails_in($old_user_data->$field) . '" -> "' . devdacti_mask_emails_in($user->$field) . '"'; // an address typed into a name or URL field is masked like the email field
        }
    }
    if ((string) $old_user_data->user_pass !== (string) $user->user_pass) {
        $changed[] = 'Password changed';
    }

    if (!$changed) {
        return; // only real changes get a row.
    }

    devdacti_log(array(
        'action'       => 'user_profile_updated',
        'object_type'  => 'user',
        'object_id'    => (int) $user_id,
        'object_title' => $user->user_login,
        'details'      => 'Changed: ' . implode('; ', $changed),
    ));
}

/** Media uploaded. */
function devdacti_track_media_uploaded($post_id)
{
    $post = get_post($post_id);
    if (!$post) {
        return;
    }

    $file  = get_attached_file($post_id);
    $fname = $file ? wp_basename($file) : '';
    $title = ('' !== $post->post_title) ? $post->post_title : $fname;

    devdacti_log(array(
        'action'       => 'media_uploaded',
        'object_type'  => 'attachment',
        'object_id'    => (int) $post_id,
        'object_title' => ('' !== $title) ? $title : '(no title)',
        'details'      => trim($post->post_mime_type . ' ' . $fname),
    ));
}

/**
 * Media deleted. delete_attachment fires BEFORE the files and the row are gone, so the file name is
 * captured there; the row is written on deleted_post, which WordPress fires only after the deletion
 * really completed (a deletion that failed half-way leaves no "deleted" record).
 */
function devdacti_media_pending($post_id = null, $data = null, $take = false)
{
    static $pending = array();
    if ($take) {
        $d = isset($pending[(int) $post_id]) ? $pending[(int) $post_id] : null;
        unset($pending[(int) $post_id]);
        return $d;
    }
    if (null !== $post_id && null !== $data) {
        $pending[(int) $post_id] = $data;
    }
    return null;
}

function devdacti_track_media_deleting($post_id, $post = null)
{
    if (!($post instanceof WP_Post)) {
        $post = get_post($post_id);
    }
    if (!$post) {
        return;
    }
    $file  = get_attached_file($post_id);
    $fname = $file ? wp_basename($file) : '';
    $title = ('' !== $post->post_title) ? $post->post_title : $fname;
    devdacti_media_pending($post_id, array('title' => ('' !== $title) ? $title : '(no title)', 'details' => trim($post->post_mime_type . ' ' . $fname)));
}

function devdacti_track_media_deleted($post_id, $post = null)
{
    $post_id = (int) $post_id;
    $data    = devdacti_media_pending($post_id, null, true);
    if (null === $data) {
        // Not an attachment this request saw being deleted (deleted_post fires for every post type).
        if (!($post instanceof WP_Post) || 'attachment' !== (string) $post->post_type) {
            return;
        }
        $data = array('title' => ('' !== $post->post_title) ? $post->post_title : '(no title)', 'details' => trim((string) $post->post_mime_type));
    }

    devdacti_log(array(
        'action'       => 'media_deleted',
        'object_type'  => 'attachment',
        'object_id'    => $post_id,
        'object_title' => $data['title'],
        'details'      => $data['details'],
    ));
}

/**
 * Comment status transitions. approve/unapprove/spam/trash; trash -> approved
 * counts as restored. Identical transitions and 'delete' are skipped.
 */
function devdacti_track_comment_status($new_status, $old_status, $comment)
{
    if (!is_object($comment) || $new_status === $old_status || 'delete' === $new_status) {
        return;
    }

    $map = array(
        'approved'   => 'comment_approved',
        'unapproved' => 'comment_unapproved',
        'spam'       => 'comment_spammed',
        'trash'      => 'comment_trashed',
    );
    if (!isset($map[$new_status])) {
        return;
    }

    $action = $map[$new_status];
    if ('approved' === $new_status && 'trash' === $old_status) {
        $action = 'comment_restored';
    }

    $post_title = get_the_title((int) $comment->comment_post_ID);
    if ('' === $post_title) {
        $post_title = '(no title)';
    }

    devdacti_log(array(
        'action'       => $action,
        'object_type'  => 'comment',
        'object_id'    => (int) $comment->comment_ID,
        'object_title' => $comment->comment_author . ' on "' . $post_title . '"',
        'details'      => $old_status . ' -> ' . $new_status . ' on "' . $post_title . '"',
    ));
}

add_action('user_register', 'devdacti_track_user_created', 10, 1);
add_action('deleted_user', 'devdacti_track_user_deleted', 10, 3);
add_action('set_user_role', 'devdacti_track_role_changed', 10, 3);
add_action('add_user_role', 'devdacti_track_role_added', 10, 2);
add_action('remove_user_role', 'devdacti_track_role_removed', 10, 2);
add_action('profile_update', 'devdacti_track_profile_updated', 10, 3);
add_action('add_attachment', 'devdacti_track_media_uploaded', 10, 1);
add_action('delete_attachment', 'devdacti_track_media_deleting', 10, 2); // capture
add_action('deleted_post', 'devdacti_track_media_deleted', 10, 2);       // record, after completion
add_action('transition_comment_status', 'devdacti_track_comment_status', 10, 3);
