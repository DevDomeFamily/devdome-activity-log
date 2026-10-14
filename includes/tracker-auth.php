<?php
/**
 * Auth tracker: logins, logouts, failed logins and password resets. Failed logins and
 * password-reset requests are aggregated per source + subject per UTC clock hour into ONE
 * row with a counter, written by a single atomic upsert (devdacti_log_aggregated) so
 * concurrent attempts can neither duplicate the bucket row nor lose an increment.
 * Everything else goes through devdacti_log().
 */

defined('ABSPATH') || exit;

/**
 * Successful login. The current user is not set yet on wp_login, so the actor
 * is supplied explicitly from the just-authenticated WP_User.
 */
function devdacti_track_login($user_login, $user)
{
    if (!($user instanceof WP_User)) {
        return;
    }

    devdacti_log(array(
        'action'       => 'login',
        'object_type'  => 'user',
        'object_id'    => (int) $user->ID,
        'object_title' => $user_login,
        'details'      => 'Logged in',
        'user_id'      => (int) $user->ID,
        'user_login'   => $user->user_login,
        'user_role'    => devdacti_role_of($user),
        'user_roles'   => devdacti_roles_of($user),
    ));
}

/** Logout. wp_logout passes the user id of the session being ended. */
function devdacti_track_logout($user_id)
{
    $user = get_userdata((int) $user_id);

    devdacti_log(array(
        'action'       => 'logout',
        'object_type'  => 'user',
        'object_id'    => (int) $user_id,
        'object_title' => $user ? $user->user_login : '',
        'details'      => 'Logged out',
        'user_id'      => (int) $user_id,
        'user_login'   => $user ? $user->user_login : '',
        'user_role'    => $user ? devdacti_role_of($user) : '',
        'user_roles'   => $user ? devdacti_roles_of($user) : array(),
    ));
}

/** Distinct failed-login bucket rows kept per source per UTC hour before overflow. */
define('DEVDACTI_LOGIN_ROW_CAP', 10);

/**
 * Failed login — aggregated into ONE row per source + username per UTC clock hour by a
 * single atomic INSERT ... ON DUPLICATE KEY UPDATE. Grouping uses a site-secret keyed
 * fingerprint of the EXACT client address, so a whole /24 no longer collapses into one
 * attacker identity while the row still stores only the configured display form.
 */
function devdacti_track_login_failed($username, $error = null)
{
    $subject = devdacti_normalize_login(wp_unslash((string) $username));

    return devdacti_log_aggregated(array(
        'action'           => 'login_failed',
        'object_type'      => 'session',
        'subject'          => $subject,
        'details'          => __('Failed login attempt', 'devdome-activity-log'),
        // ACTOR is anonymous: nobody authenticated. The attempted account is the SUBJECT
        // (object_title). 0.1.2 wrote the attempted name into the actor column, which made
        // an unauthenticated attacker look like an account holder in every user filter —
        // and let the excluded-users list hide attacks by naming the target.
        'user_id'          => 0,
        'user_login'       => '',
        'user_role'        => '',
        'outcome'          => 'failure',
        'ip_exact'         => devdacti_client_ip_exact(),
        'ip_display'       => devdacti_ip(),
        'max_rows'         => DEVDACTI_LOGIN_ROW_CAP,
        'overflow_subject' => 'multiple usernames',
        'overflow_details' => __('Failed login attempts across multiple usernames this hour', 'devdome-activity-log'),
    ));
}

/**
 * Password reset requested (lost-password form or an admin sending a reset
 * link). Resolves the target user by login or email for object_id when it
 * exists; the actor stays whoever triggered it (anonymous on the form).
 * The form is unauthenticated and repeatable, so — like login_failed — repeat
 * requests aggregate into ONE row per IP + username per UTC clock hour.
 */
function devdacti_track_password_reset_requested($user_login)
{
    // Same normalisation as login_failed: an identifier that sanitize_user() empties is
    // still an attempt, and returning early here made a flood of unprintable identifiers
    // the one way to request resets and leave no evidence at all.
    $login = devdacti_normalize_login(wp_unslash((string) $user_login));

    $user = get_user_by('login', $login);
    if (!$user && is_email($login)) {
        $user = get_user_by('email', $login);
    }

    devdacti_log_aggregated(array(
        'action'           => 'password_reset_requested',
        'object_type'      => 'user',
        'object_id'        => $user ? (int) $user->ID : 0,
        'subject'          => devdacti_substr($user ? $user->user_login : $login, 0, 191),
        'details'          => __('Password reset requested', 'devdome-activity-log'),
        // The form is unauthenticated: the actor is whoever is (not) logged in, the account
        // named on the form is the subject.
        'outcome'          => 'success',
        'ip_exact'         => devdacti_client_ip_exact(),
        'ip_display'       => devdacti_ip(),
        'max_rows'         => DEVDACTI_LOGIN_ROW_CAP,
        'overflow_subject' => 'multiple accounts',
        'overflow_details' => __('Password reset requests for multiple accounts this hour', 'devdome-activity-log'),
    ));
}

/**
 * Password reset completed. NEVER touches or logs $new_pass.
 *
 * The SUBJECT is the account whose password changed. The ACTOR is whoever completed the
 * form — normally nobody (the reset link is used while logged out), sometimes an
 * administrator. 0.1.2 recorded the target as the actor, so a reset performed by an
 * attacker holding the link read as "this user reset their own password".
 */
function devdacti_track_password_reset($user, $new_pass)
{
    unset($new_pass); // never logged, never read.

    if (!($user instanceof WP_User)) {
        return;
    }

    devdacti_log(array(
        'action'       => 'password_reset_completed',
        'object_type'  => 'user',
        'object_id'    => (int) $user->ID,
        'object_title' => $user->user_login,
        'details'      => sprintf(
            /* translators: %s: the account whose password was reset. */
            __('Password reset completed for %s', 'devdome-activity-log'),
            $user->user_login
        ),
    ));
}

add_action('wp_login', 'devdacti_track_login', 10, 2);
add_action('wp_logout', 'devdacti_track_logout', 10, 1);
add_action('wp_login_failed', 'devdacti_track_login_failed', 10, 2);
add_action('retrieve_password', 'devdacti_track_password_reset_requested', 10, 1);
add_action('after_password_reset', 'devdacti_track_password_reset', 10, 2);
