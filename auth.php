<?php
require_once __DIR__ . '/auth_session.php';

function check_user_role(string $required_role): void
{
    if (auth_activate_role($required_role)) {
        if ($required_role === 'peso_staff') {
            global $conn;
            $staffId = (int)($_SESSION['staff_id'] ?? 0);
            if ($staffId <= 0 || !isset($conn) || !($conn instanceof mysqli)) {
                auth_clear_role('peso_staff');
            } else {
                $stmt = $conn->prepare("SELECT status FROM peso_staff WHERE staff_id = ? LIMIT 1");
                $stmt->bind_param('i', $staffId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && strcasecmp((string)($row['status'] ?? 'Active'), 'Banned') !== 0) {
                    return;
                }
                auth_clear_role('peso_staff');
                $_SESSION['flash'] = 'This staff account is no longer active.';
                $_SESSION['flash_type'] = 'error';
            }
        } else {
            return;
        }
    }

    $_SESSION['flash'] = 'Please log in to continue.';
    header('Location: login.php');
    exit();
}
