<?php
function logActivity($conn, $actor_id, $module, $action, $target, $description) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $staff_id = null;
    $actor_name = 'System';
    $actor_role = 'System';

    if (isset($_SESSION['admin_id'])) {
        $actor_name = 'PESO Vinzons';
        $actor_role = 'Administrator';
    } elseif (isset($_SESSION['staff_id'])) {
        $staff_id = (int)$actor_id;
        $actor_name = 'PESO Staff';
        $actor_role = 'PESO Staff';
        $name_stmt = $conn->prepare("SELECT first_name, last_name FROM peso_staff WHERE staff_id = ? LIMIT 1");
        if ($name_stmt) {
            $name_stmt->bind_param('i', $staff_id);
            $name_stmt->execute();
            if ($staff_row = $name_stmt->get_result()->fetch_assoc()) {
                $resolved_name = trim(($staff_row['first_name'] ?? '') . ' ' . ($staff_row['last_name'] ?? ''));
                if ($resolved_name !== '') $actor_name = $resolved_name;
            }
            $name_stmt->close();
        }
    }

    $sql = "INSERT INTO activity_logs (staff_id, actor_name, actor_role, module_name, action_type, target_name, description, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("isssssss", $staff_id, $actor_name, $actor_role, $module, $action, $target, $description, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

if (($_SESSION['role'] ?? null) === 'peso_staff' && isset($_SESSION['staff_id'], $conn)) {
    $current_id = $_SESSION['staff_id'];
    $check = $conn->prepare("SELECT status FROM peso_staff WHERE staff_id = ?");
    if ($check) {
        $check->bind_param("i", $current_id);
        $check->execute();
        $result = $check->get_result()->fetch_assoc();
        if ($result && isset($result['status']) && $result['status'] === 'Banned') {
            require_once __DIR__ . '/auth_session.php';
            auth_clear_role('peso_staff');
            header("Location: login.php?msg=banned");
            exit();
        }
        $check->close();
    }
}

if (($_SESSION['role'] ?? null) === 'user' && isset($_SESSION['user_id'], $conn)) {
    $current_id = $_SESSION['user_id'];
    $check = $conn->prepare("SELECT status FROM users WHERE user_id = ?");
    if ($check) {
        $check->bind_param("i", $current_id);
        $check->execute();
        $result = $check->get_result()->fetch_assoc();
        if ($result && isset($result['status']) && $result['status'] === 'Banned') {
            require_once __DIR__ . '/auth_session.php';
            auth_clear_role('user');
            header("Location: login.php?msg=banned");
            exit();
        }
        $check->close();
    }
}
?>
