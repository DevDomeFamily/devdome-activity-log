<?php
/**
 * Trusted-proxy model (audit 4, batch 5).
 *
 * 0.1.2 decided whether to believe a forwarded client header with a boolean constant/filter.
 * On any site whose origin is reachable directly, that let a client choose the IP that would
 * be written into the audit log AND the identity the flood aggregation groups on: forged
 * attribution and a trivial rate-limit bypass in one header.
 *
 * The rule here is the only one that holds: a forwarded header is read ONLY when the direct
 * transport peer is inside a configured trusted-proxy range. No configuration means no trust,
 * and REMOTE_ADDR — the one address the TCP handshake proves — is used.
 */

defined('ABSPATH') || exit;

/** Forwarded headers this plugin knows how to read. Anything else is ignored. */
function devdacti_forwarded_headers()
{
    return array(
        'X-Forwarded-For'   => 'HTTP_X_FORWARDED_FOR',
        'CF-Connecting-IP'  => 'HTTP_CF_CONNECTING_IP',
        'X-Real-IP'         => 'HTTP_X_REAL_IP',
        'True-Client-IP'    => 'HTTP_TRUE_CLIENT_IP',
    );
}

/**
 * Parse the configured trusted-proxy list into validated CIDR strings.
 * Accepts one entry per line or comma separated; a bare address means /32 or /128.
 *
 * @return string[]
 */
function devdacti_trusted_proxies($raw = null)
{
    if (null === $raw) {
        $raw = (string) devdacti_get_setting('trusted_proxies', '');
    }
    $out = array();
    foreach (preg_split('/[\r\n,]+/', (string) $raw) as $entry) {
        $entry = trim($entry);
        if ('' === $entry) {
            continue;
        }
        if (devdacti_valid_cidr($entry)) {
            $out[] = $entry;
        }
    }
    return $out;
}

/** Entries the operator typed that this plugin could not understand (shown as a warning). */
function devdacti_invalid_proxies($raw)
{
    $out = array();
    foreach (preg_split('/[\r\n,]+/', (string) $raw) as $entry) {
        $entry = trim($entry);
        if ('' !== $entry && !devdacti_valid_cidr($entry)) {
            $out[] = $entry;
        }
    }
    return $out;
}

/** A single address or an address/prefix, IPv4 or IPv6. */
function devdacti_valid_cidr($entry)
{
    $entry = (string) $entry;
    $parts = explode('/', $entry, 2);
    $ip    = $parts[0];

    if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (!isset($parts[1])) {
        return true;
    }
    if (!ctype_digit($parts[1])) {
        return false;
    }
    $bits = (int) $parts[1];
    $max  = (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ? 32 : 128;
    return $bits >= 0 && $bits <= $max;
}

/** Binary CIDR containment; false for a family mismatch or unparseable input. */
function devdacti_ip_in_cidr($ip, $cidr)
{
    $parts  = explode('/', (string) $cidr, 2);
    $net    = $parts[0];
    $ip_bin = @inet_pton((string) $ip);
    $net_bin = @inet_pton($net);
    if (false === $ip_bin || false === $net_bin || strlen($ip_bin) !== strlen($net_bin)) {
        return false;
    }

    $bits = isset($parts[1]) ? (int) $parts[1] : strlen($ip_bin) * 8;
    $bits = max(0, min(strlen($ip_bin) * 8, $bits));
    if (0 === $bits) {
        return true;
    }

    $whole = intdiv($bits, 8);
    $rest  = $bits % 8;
    if ($whole > 0 && substr($ip_bin, 0, $whole) !== substr($net_bin, 0, $whole)) {
        return false;
    }
    if (0 === $rest) {
        return true;
    }
    $mask = chr((0xFF << (8 - $rest)) & 0xFF);
    return (substr($ip_bin, $whole, 1) & $mask) === (substr($net_bin, $whole, 1) & $mask);
}

/**
 * True for an address a client can never legitimately reach the internet from: loopback,
 * link-local, RFC1918/unique-local, unspecified, IPv4-mapped.
 *
 * Deliberately narrower than FILTER_FLAG_NO_RES_RANGE, which also rejects the IPv6
 * documentation range 2001:db8::/32 while accepting its IPv4 equivalents. That asymmetry
 * made an ordinary IPv6 client look like a forged hop.
 */
function devdacti_is_internal_ip($ip)
{
    $ip = (string) $ip;

    if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return true; // not an address at all
    }
    // An IPv4-mapped address (::ffff:8.8.8.8) is the IPv4 address: a public client behind a proxy that hands
    // the mapped form must not be refused as "internal". Unmap and judge it by the IPv4 rules.
    if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m) && false !== filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false === filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    foreach (array('::/128', '::1/128', 'fe80::/10', 'fc00::/7', '::ffff:0:0/96') as $range) {
        if (devdacti_ip_in_cidr($ip, $range)) {
            return true;
        }
    }
    return false;
}

/** True when this address belongs to one of the configured trusted proxies. */
function devdacti_is_trusted_proxy($ip, $proxies = null)
{
    if (null === $proxies) {
        $proxies = devdacti_trusted_proxies();
    }
    foreach ($proxies as $cidr) {
        if (devdacti_ip_in_cidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

/** The transport peer: REMOTE_ADDR, validated. This is the only address TCP proves. */
function devdacti_peer_ip()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    return (false !== filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : '';
}

/** The configured forwarded header name ('' = read no forwarded header at all). */
function devdacti_forwarded_header()
{
    $header = (string) devdacti_get_setting('forwarded_header', '');
    return isset(devdacti_forwarded_headers()[$header]) ? $header : '';
}

/** Raw value of the configured forwarded header. */
function devdacti_forwarded_value($header)
{
    $map = devdacti_forwarded_headers();
    if (!isset($map[$header]) || !isset($_SERVER[$map[$header]])) {
        return '';
    }
    return sanitize_text_field(wp_unslash($_SERVER[$map[$header]]));
}

/**
 * Resolve the client address from a forwarded chain, walking from the trusted edge inward.
 *
 * @param string   $peer    validated transport peer
 * @param string   $chain   raw header value ("client, proxy1, proxy2")
 * @param string[] $proxies trusted CIDRs
 * @return string '' when nothing in the chain may be believed
 */
function devdacti_resolve_forwarded($peer, $chain, array $proxies)
{
    if ('' === $peer || !devdacti_is_trusted_proxy($peer, $proxies)) {
        return ''; // the direct peer is not a proxy we trust: the header is attacker input.
    }

    $hops = array_map('trim', explode(',', (string) $chain));
    for ($i = count($hops) - 1; $i >= 0; $i--) {
        $hop = $hops[$i];
        // A port may be appended on IPv4 hops; bracketed IPv6 may carry one too.
        if (preg_match('/^\[(.+)\](?::\d+)?$/', $hop, $m)) {
            $hop = $m[1];
        } elseif (substr_count($hop, ':') === 1 && false !== strpos($hop, '.')) {
            $hop = substr($hop, 0, strpos($hop, ':'));
        }
        if (false === filter_var($hop, FILTER_VALIDATE_IP)) {
            return ''; // malformed hop: refuse the whole chain rather than guess.
        }
        if (devdacti_is_trusted_proxy($hop, $proxies)) {
            continue; // one of our own edges; keep walking inward.
        }
        // An internal address behind a public edge is a misconfiguration, not a client.
        if (devdacti_is_internal_ip($hop) && !devdacti_is_internal_ip($peer)) {
            return '';
        }
        return $hop;
    }
    return '';
}

/**
 * The exact, validated client address. Falls back to the transport peer whenever the
 * forwarded chain cannot be trusted — this function never returns attacker-chosen input.
 */
function devdacti_client_ip_exact()
{
    $peer = devdacti_peer_ip();
    if ('' === $peer) {
        return '';
    }

    $header = devdacti_forwarded_header();
    if ('' === $header) {
        return $peer;
    }

    $proxies = devdacti_trusted_proxies();
    if (!$proxies) {
        return $peer; // configured header, no configured edge: fail closed.
    }

    $client = devdacti_resolve_forwarded($peer, devdacti_forwarded_value($header), $proxies);
    return ('' !== $client) ? $client : $peer;
}

/**
 * Proxy configuration health for the Settings screen. Never prints a header value — only
 * whether the current request arrived through a trusted edge.
 *
 * @return array state (off|fail_closed|active|invalid), message
 */
function devdacti_proxy_status()
{
    $raw     = (string) devdacti_get_setting('trusted_proxies', '');
    $header  = devdacti_forwarded_header();
    $proxies = devdacti_trusted_proxies($raw);
    $invalid = devdacti_invalid_proxies($raw);

    if ($invalid) {
        return array(
            'state'   => 'invalid',
            'message' => sprintf(
                /* translators: %s: comma separated list of entries that are not valid IPs or CIDRs. */
                __('These trusted-proxy entries are not valid addresses or CIDR ranges and are ignored: %s', 'devdome-activity-log'),
                implode(', ', array_slice($invalid, 0, 5))
            ),
        );
    }
    if ('' === $header) {
        return array(
            'state'   => 'off',
            'message' => __('No forwarded header is trusted. The address recorded is the one your server saw on the connection.', 'devdome-activity-log'),
        );
    }
    if (!$proxies) {
        return array(
            'state'   => 'fail_closed',
            'message' => __('A forwarded header is selected but no trusted proxy ranges are configured, so the header is ignored and the connection address is recorded instead.', 'devdome-activity-log'),
        );
    }

    $peer = devdacti_peer_ip();
    return array(
        'state'   => 'active',
        'message' => devdacti_is_trusted_proxy($peer, $proxies)
            ? sprintf(
                /* translators: %s: forwarded header name, e.g. X-Forwarded-For. */
                __('This request arrived through a trusted proxy, so %s is being used to identify the client.', 'devdome-activity-log'),
                $header
            )
            : sprintf(
                /* translators: %s: forwarded header name, e.g. X-Forwarded-For. */
                __('This request did NOT arrive through a configured trusted proxy, so %s was ignored. If all traffic should pass through your proxy, this address is reaching the origin directly.', 'devdome-activity-log'),
                $header
            ),
    );
}
