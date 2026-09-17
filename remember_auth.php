<?php

const REMEMBER_AUTH_COOKIE = 'benepeso_remember';
const REMEMBER_AUTH_LIFETIME = 2592000; // 30 days.

function remember_auth_ensure_table(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $sql = "CREATE TABLE IF NOT EXISTS auth_remember_tokens (
        token_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        selector CHAR(24) NOT NULL,
        validator_hash CHAR(64) NOT NULL,
        account_role VARCHAR(20) NOT NULL,
        account_id INT UNSIGNED NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME DEFAULT NULL,
        PRIMARY KEY (token_id),
        UNIQUE KEY uq_auth_remember_selector (selector),
        KEY idx_auth_remember_account (account_role, account_id),
        KEY idx_auth_remember_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    try {
        $ready = $conn->query($sql) === true;
    } catch (Throwable $error) {
        $ready = false;
        error_log('Unable to initialize remember-me tokens: ' . $error->getMessage());
    }
    if (!$ready && $conn->error !== '') {
        error_log('Unable to initialize remember-me tokens: ' . $conn->error);
    }

    return $ready;
}

function remember_auth_cookie_options(int $expires): array
{
    $sessionOptions = session_get_cookie_params();
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => $https || !empty($sessionOptions['secure']),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function remember_auth_set_cookie(string $value, int $expires): bool
{
    if (headers_sent()) {
        return false;
    }

    return setcookie(REMEMBER_AUTH_COOKIE, $value, remember_auth_cookie_options($expires));
}

function remember_auth_clear_cookie(): void
{
    unset($_COOKIE[REMEMBER_AUTH_COOKIE]);
    remember_auth_set_cookie('', time() - 3600);
}

function remember_auth_cookie_parts(): ?array
{
    $cookie = $_COOKIE[REMEMBER_AUTH_COOKIE] ?? '';
    if (!is_string($cookie) || !preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/D', $cookie, $matches)) {
        return null;
    }

    return ['selector' => $matches[1], 'validator' => $matches[2]];
}

function remember_auth_revoke_cookie(mysqli $conn, ?string $expectedRole = null): void
{
    $parts = remember_auth_cookie_parts();
    if ($parts === null || !remember_auth_ensure_table($conn)) {
        if ($expectedRole === null) {
            remember_auth_clear_cookie();
        }
        return;
    }

    $sql = 'DELETE FROM auth_remember_tokens WHERE selector = ?';
    if ($expectedRole !== null) {
        $sql .= ' AND account_role = ?';
    }

    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }

        if ($expectedRole === null) {
            $stmt->bind_param('s', $parts['selector']);
        } else {
            $stmt->bind_param('ss', $parts['selector'], $expectedRole);
        }
        $stmt->execute();
        $removed = $stmt->affected_rows > 0;
        $stmt->close();
    } catch (Throwable $error) {
        error_log('Unable to revoke remember-me token: ' . $error->getMessage());
        return;
    }

    if ($expectedRole === null || $removed) {
        remember_auth_clear_cookie();
    }
}

function remember_auth_issue(mysqli $conn, string $role, int $accountId): bool
{
    if (!in_array($role, ['admin', 'peso_staff', 'user'], true) || $accountId < 1) {
        return false;
    }
    if (!remember_auth_ensure_table($conn)) {
        return false;
    }

    try {
        remember_auth_revoke_cookie($conn);
        $conn->query('DELETE FROM auth_remember_tokens WHERE expires_at <= NOW()');

        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $validatorHash = hash('sha256', $validator);
        $stmt = $conn->prepare(
            'INSERT INTO auth_remember_tokens (selector, validator_hash, account_role, account_id, expires_at) '
            . 'VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('sssi', $selector, $validatorHash, $role, $accountId);
        $saved = $stmt->execute();
        $stmt->close();
        if (!$saved) {
            return false;
        }
    } catch (Throwable $error) {
        error_log('Unable to issue remember-me token: ' . $error->getMessage());
        return false;
    }

    $_COOKIE[REMEMBER_AUTH_COOKIE] = $selector . ':' . $validator;
    return remember_auth_set_cookie($_COOKIE[REMEMBER_AUTH_COOKIE], time() + REMEMBER_AUTH_LIFETIME);
}

function remember_auth_load_account(mysqli $conn, string $role, int $accountId): bool
{
    if ($role === 'admin') {
        $stmt = $conn->prepare('SELECT admin_id FROM admins WHERE admin_id = ? LIMIT 1');
    } elseif ($role === 'peso_staff') {
        $stmt = $conn->prepare('SELECT staff_id, first_name, last_name, profile_picture, status FROM peso_staff WHERE staff_id = ? LIMIT 1');
    } elseif ($role === 'user') {
        $stmt = $conn->prepare('SELECT user_id, first_name, last_name, profile_pic, status FROM users WHERE user_id = ? LIMIT 1');
    } else {
        return false;
    }

    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || (isset($row['status']) && strcasecmp((string)$row['status'], 'Banned') === 0)) {
        return false;
    }

    auth_regenerate_session();
    $_SESSION['fail_count'] = 0;
    $_SESSION['lock_until'] = 0;

    if ($role === 'admin') {
        $_SESSION['admin_id'] = (int)$row['admin_id'];
        $_SESSION['admin_name'] = 'Administrator';
    } elseif ($role === 'peso_staff') {
        $_SESSION['staff_id'] = (int)$row['staff_id'];
        $_SESSION['staff_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
        $_SESSION['staff_pic'] = $row['profile_picture'];
    } else {
        $_SESSION['user_id'] = (int)$row['user_id'];
        $_SESSION['user_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
        $_SESSION['user_pic'] = $row['profile_pic'] ?? 'default_avatar.png';
    }

    return auth_activate_role($role);
}

function remember_auth_attempt(mysqli $conn): ?string
{
    if (auth_remaining_roles() !== []) {
        return null;
    }

    $parts = remember_auth_cookie_parts();
    if ($parts === null) {
        if (isset($_COOKIE[REMEMBER_AUTH_COOKIE])) {
            remember_auth_clear_cookie();
        }
        return null;
    }
    if (!remember_auth_ensure_table($conn)) {
        return null;
    }

    try {
        $stmt = $conn->prepare(
            'SELECT token_id, validator_hash, account_role, account_id '
            . 'FROM auth_remember_tokens WHERE selector = ? AND expires_at > NOW() LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $parts['selector']);
        $stmt->execute();
        $token = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $valid = $token && hash_equals((string)$token['validator_hash'], hash('sha256', $parts['validator']));
        if (!$valid || !remember_auth_load_account($conn, (string)($token['account_role'] ?? ''), (int)($token['account_id'] ?? 0))) {
            remember_auth_revoke_cookie($conn);
            return null;
        }

        $newValidator = bin2hex(random_bytes(32));
        $newHash = hash('sha256', $newValidator);
        $tokenId = (int)$token['token_id'];
        $update = $conn->prepare(
            'UPDATE auth_remember_tokens SET validator_hash = ?, expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY), '
            . 'last_used_at = NOW() WHERE token_id = ?'
        );
        if (!$update) {
            remember_auth_revoke_cookie($conn);
            return (string)$token['account_role'];
        }
        $update->bind_param('si', $newHash, $tokenId);
        $rotated = $update->execute();
        $update->close();
    } catch (Throwable $error) {
        error_log('Unable to use remember-me token: ' . $error->getMessage());
        remember_auth_clear_cookie();
        return null;
    }

    if ($rotated) {
        $_COOKIE[REMEMBER_AUTH_COOKIE] = $parts['selector'] . ':' . $newValidator;
        remember_auth_set_cookie($_COOKIE[REMEMBER_AUTH_COOKIE], time() + REMEMBER_AUTH_LIFETIME);
    } else {
        remember_auth_revoke_cookie($conn);
    }

    return (string)$token['account_role'];
}

function remember_auth_revoke_account(mysqli $conn, string $role, int $accountId): void
{
    if (!remember_auth_ensure_table($conn) || $accountId < 1) {
        return;
    }

    try {
        $stmt = $conn->prepare('DELETE FROM auth_remember_tokens WHERE account_role = ? AND account_id = ?');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('si', $role, $accountId);
        $stmt->execute();
        $stmt->close();
        remember_auth_clear_cookie();
    } catch (Throwable $error) {
        error_log('Unable to revoke account remember-me tokens: ' . $error->getMessage());
    }
}

function remember_auth_destination(string $role): string
{
    return [
        'admin' => 'admin_dashboard.php',
        'peso_staff' => 'peso_staff_dashboard.php',
        'user' => 'home.php',
    ][$role] ?? 'login.php';
}
