<?php

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function auth_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function auth_verify_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function auth_require_csrf(): void
{
    if (!auth_verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid or expired request token. Please return to the previous page and try again.');
    }
}

function auth_csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(auth_csrf_token(), ENT_QUOTES, 'UTF-8')
        . '">';
}

function auth_enable_csrf_form_injection(): void
{
    ob_start(static function (string $html): string {
        $field = auth_csrf_input();
        return (string)preg_replace_callback(
            '/<form\b(?=[^>]*\bmethod\s*=\s*(["\'])post\1)[^>]*>/i',
            static fn(array $match): string => $match[0] . $field,
            $html
        );
    });
}

function auth_regenerate_session(): void
{
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
}

function auth_persist_current_session(int $lifetimeSeconds): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || headers_sent() || $lifetimeSeconds < 1) {
        return;
    }

    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => time() + $lifetimeSeconds,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'] ?? '',
        'secure' => !empty($params['secure']),
        'httponly' => true,
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

function auth_role_id_key(string $role): ?string
{
    return [
        'admin' => 'admin_id',
        'peso_staff' => 'staff_id',
        'user' => 'user_id',
    ][$role] ?? null;
}

function auth_has_role(string $role): bool
{
    $key = auth_role_id_key($role);
    return $key !== null && !empty($_SESSION[$key]);
}

function auth_activate_role(string $role): bool
{
    if (!auth_has_role($role)) {
        return false;
    }

    $_SESSION['role'] = $role;
    $_SESSION['auth_roles'][$role] = true;
    return true;
}

function auth_clear_role(string $role): void
{
    $keys = [
        'admin' => ['admin_id', 'admin_name'],
        'peso_staff' => ['staff_id', 'staff_name', 'staff_pic'],
        'user' => ['user_id', 'user_name', 'user_pic'],
    ][$role] ?? [];

    foreach ($keys as $key) {
        unset($_SESSION[$key]);
    }

    unset($_SESSION['auth_roles'][$role]);
    if (($_SESSION['role'] ?? null) === $role) {
        unset($_SESSION['role']);
    }
}

function auth_remaining_roles(): array
{
    return array_values(array_filter(
        ['admin', 'peso_staff', 'user'],
        static fn(string $role): bool => auth_has_role($role)
    ));
}

start_secure_session();
