<?php
require_once __DIR__ . '/auth_session.php';

function auth_role_account_is_active(string $role): bool
{
    global $conn;

    if (!auth_has_role($role) || !isset($conn) || !($conn instanceof mysqli)) {
        return false;
    }

    $id = (int)($_SESSION[auth_role_id_key($role)] ?? 0);
    if ($id <= 0) {
        return false;
    }

    if ($role === 'admin') {
        $stmt = $conn->prepare('SELECT admin_id FROM admins WHERE admin_id = ? LIMIT 1');
    } elseif ($role === 'peso_staff') {
        $stmt = $conn->prepare("SELECT staff_id FROM peso_staff WHERE staff_id = ? AND COALESCE(status, 'Active') <> 'Banned' LIMIT 1");
    } elseif ($role === 'user') {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND COALESCE(status, 'Active') <> 'Banned' LIMIT 1");
    } else {
        return false;
    }

    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $active = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $active;
}

function check_user_role(string $required_role): void
{
    if (auth_activate_role($required_role) && auth_role_account_is_active($required_role)) {
        return;
    }

    if (auth_has_role($required_role)) {
        auth_clear_role($required_role);
        $_SESSION['flash'] = 'This account is no longer active.';
        $_SESSION['flash_type'] = 'error';
    } else {
        $_SESSION['flash'] = 'Please log in to continue.';
    }

    header('Location: login.php');
    exit();
}
