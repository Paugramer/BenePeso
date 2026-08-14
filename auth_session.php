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

