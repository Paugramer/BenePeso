<?php
function logActivity($conn, $staff_id, $module, $action, $target, $description) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $sql = "INSERT INTO activity_logs (staff_id, module_name, action_type, target_name, description, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("isssss", $staff_id, $module, $action, $target, $description, $ip);
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
