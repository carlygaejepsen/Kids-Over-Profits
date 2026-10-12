<?php
/**
 * The mobile app's admin mode: every KOP Tools screen inside the app, signed in to WordPress.
 *
 * The app holds an application password (Reviewer sign-in), which WordPress accepts only on REST
 * requests. A web view needs a cookie session instead, so:
 *
 *   POST kop/v1/app/session   (application password, manage_options) -> a single-use token, 2 minutes,
 *                             only its hash stored, and the /app-login/ address that spends it
 *   GET  /app-login/?t=<token> sets a "remember me" auth cookie for KOP_APP_COOKIE_DAYS and redirects
 *                             to the tool asked for (same site only, else KOP Tools)
 *   GET  kop/v1/app/tools     the KOP Tools registry (wp-plugins/kop-tools), resolved for this site
 *   POST kop/v1/app/signout   ends every WordPress session of this account, on every device
 *
 * Each app session remembers the application password that made it (session info 'kop_app_pw'):
 * revoking that password under Users > Profile ends the session on its next request.
 *
 * The app asks for a token only when it has no session (first open, or WordPress showed its login
 * page), so each device keeps one long session rather than one per tool opened.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_APP_COOKIE_DAYS = 365;
const KOP_APP_TOKEN_TTL   = 120;

/**
 * Some hosts drop the Authorization header before PHP sees it; the app sends the same Basic
 * credentials in X-KOP-Authorization too (as kop_ext_restore_basic_auth() reads for the extension).
 */
add_filter('determine_current_user', function ($user_id) {
    if (!empty($_SERVER['PHP_AUTH_USER']) || empty($_SERVER['HTTP_X_KOP_AUTHORIZATION'])) {
        return $user_id;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (strpos($uri, '/kop/v1/app/') === false && strpos(rawurldecode($uri), '/kop/v1/app/') === false) {
        return $user_id;
    }
    $header = (string) $_SERVER['HTTP_X_KOP_AUTHORIZATION'];
    if (stripos($header, 'basic ') !== 0) {
        return $user_id;
    }
    $decoded = base64_decode(substr($header, 6), true);
    if ($decoded !== false && strpos($decoded, ':') !== false) {
        list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = explode(':', $decoded, 2);
    }
    return $user_id;
}, 5);

function kop_app_token_key($token) {
    return 'kop_app_login_' . hash('sha256', (string) $token);
}

function kop_app_tools_url() {
    return admin_url('admin.php?page=' . (function_exists('kop_tools_parent_slug') ? kop_tools_parent_slug() : 'kop-tools'));
}

/** Same-site address to land on after signing in; anything else falls back to KOP Tools. */
function kop_app_admin_landing($redirect) {
    $fallback = kop_app_tools_url();
    $redirect = trim((string) $redirect);
    if ($redirect === '') {
        return $fallback;
    }
    $bare = function ($host) {
        return strtolower(preg_replace('/^www\./i', '', (string) $host));
    };
    $host = wp_parse_url($redirect, PHP_URL_HOST);
    if (!$host || $bare($host) !== $bare(wp_parse_url(home_url(), PHP_URL_HOST))) {
        return $fallback;
    }
    return wp_validate_redirect($redirect, $fallback);
}

function kop_app_rest_session(WP_REST_Request $req) {
    $uuid = function_exists('rest_get_authenticated_app_password') ? (string) rest_get_authenticated_app_password() : '';
    if ($uuid === '') {
        return new WP_Error('kop_app_password_only', 'Sign in from the app with an application password.', array('status' => 403));
    }
    $token = bin2hex(random_bytes(32));
    set_transient(kop_app_token_key($token), array(
        'user'     => get_current_user_id(),
        'uuid'     => $uuid,
        'redirect' => kop_app_admin_landing($req->get_param('redirect')),
    ), KOP_APP_TOKEN_TTL);
    return array(
        'url'        => add_query_arg('t', $token, home_url('/app-login/')),
        'expires_in' => KOP_APP_TOKEN_TTL,
        'days'       => KOP_APP_COOKIE_DAYS,
    );
}

/** Every tool on the KOP Tools dashboard that opens as a page, in registry order. */
function kop_app_tools_list() {
    $out = array(array(
        'category' => 'KOP Tools',
        'title'    => 'All tools',
        'desc'     => 'The KOP Tools dashboard, including the batch and repair tools run with a button.',
        'url'      => kop_app_tools_url(),
    ));
    if (function_exists('kop_tools_registry') && function_exists('kop_tools_resolve')) {
        foreach ((array) kop_tools_registry() as $category => $tools) {
            foreach ((array) $tools as $tool) {
                if (($tool['type'] ?? 'page') === 'action') {
                    continue;
                }
                $r = kop_tools_resolve($tool);
                if (empty($r['available']) || $r['url'] === '') {
                    continue;
                }
                $out[] = array(
                    'category' => (string) $category,
                    'title'    => (string) ($tool['title'] ?? ''),
                    'desc'     => (string) ($tool['desc'] ?? ''),
                    'url'      => (string) $r['url'],
                );
            }
        }
    }
    $out[] = array(
        'category' => 'WordPress',
        'title'    => 'WordPress dashboard',
        'desc'     => 'Posts, pages, media, users and settings.',
        'url'      => admin_url(),
    );
    return $out;
}

function kop_app_rest_tools() {
    return array(
        'user'  => wp_get_current_user()->display_name,
        'tools' => kop_app_tools_list(),
    );
}

function kop_app_rest_signout() {
    WP_Session_Tokens::get_instance(get_current_user_id())->destroy_all();
    return array('signed_out' => true);
}

add_action('rest_api_init', function () {
    $admin = function () {
        return current_user_can('manage_options');
    };
    register_rest_route('kop/v1', '/app/session', array(
        'methods'             => 'POST',
        'permission_callback' => $admin,
        'callback'            => 'kop_app_rest_session',
    ));
    register_rest_route('kop/v1', '/app/tools', array(
        'methods'             => 'GET',
        'permission_callback' => $admin,
        'callback'            => 'kop_app_rest_tools',
    ));
    register_rest_route('kop/v1', '/app/signout', array(
        'methods'             => 'POST',
        'permission_callback' => $admin,
        'callback'            => 'kop_app_rest_signout',
    ));
});

/** /app-login/?t=<token>: spends the token, sets the long cookie, sends the web view on. */
function kop_app_login_request() {
    $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $base = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
    if (rtrim($path, '/') !== rtrim($base, '/') . '/app-login') {
        return;
    }
    nocache_headers();
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('Referrer-Policy: no-referrer');

    $token = isset($_GET['t']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['t']) : '';
    $data = $token !== '' ? get_transient(kop_app_token_key($token)) : false;
    if ($token !== '') {
        delete_transient(kop_app_token_key($token));
    }
    $user = is_array($data) ? get_userdata((int) $data['user']) : false;
    if (!$user || !user_can($user, 'manage_options') || empty($data['uuid'])) {
        wp_safe_redirect(wp_login_url(admin_url()));
        exit;
    }

    $days = function () {
        return KOP_APP_COOKIE_DAYS * DAY_IN_SECONDS;
    };
    $tag = function ($info) use ($data) {
        $info['kop_app_pw'] = (string) $data['uuid'];
        return $info;
    };
    add_filter('auth_cookie_expiration', $days, 99);
    add_filter('attach_session_information', $tag);
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true, is_ssl());
    remove_filter('attach_session_information', $tag);
    remove_filter('auth_cookie_expiration', $days, 99);
    do_action('wp_login', $user->user_login, $user);

    wp_safe_redirect(kop_app_admin_landing($data['redirect'] ?? ''));
    exit;
}
add_action('init', 'kop_app_login_request', 1);

/** An app session ends once the application password that made it is revoked. */
function kop_app_check_session_password() {
    if (!is_user_logged_in() || !class_exists('WP_Application_Passwords')) {
        return;
    }
    $token = wp_get_session_token();
    if ($token === '') {
        return;
    }
    $uid = get_current_user_id();
    $manager = WP_Session_Tokens::get_instance($uid);
    $session = $manager->get($token);
    if (empty($session['kop_app_pw'])) {
        return;
    }
    if (WP_Application_Passwords::get_user_application_password($uid, $session['kop_app_pw'])) {
        return;
    }
    $manager->destroy($token);
    wp_clear_auth_cookie();
    wp_set_current_user(0);
}
add_action('init', 'kop_app_check_session_password', 2);
