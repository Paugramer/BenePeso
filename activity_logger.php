<?php
function logActivity($conn, $actor_name, $actor_role, $module, $action, $target, $description) {

    $ip = $_SERVER['REMOTE_ADDR'];

    $stmt = $conn->prepare("
        INSERT INTO activity_logs 
        (actor_name, actor_role, module_name, action_type, target_name, description, ip_address) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param("sssssss",
        $actor_name,
        $actor_role,
        $module,
        $action,
        $target,
        $description,
        $ip
    );

    $stmt->execute();
}
?>