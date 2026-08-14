<?php
require_once __DIR__ . '/auth_session.php';
require "db.php";

$requested_role = $_GET['role'] ?? ($_SESSION['role'] ?? null);
$role = is_string($requested_role) && auth_has_role($requested_role) ? $requested_role : null;

if ($role !== null) {
    
    $actor_name = "Unknown User";
    $actor_role = "Registered User"; 
    $module = "Auth";
    $action = "LOGOUT";
    $target = "System"; // Added target variable
    $desc = "";

    if ($role === "admin") {
        $actor_name = "System Admin";
        $actor_role = "Administrator";
        $desc = "Admin logged out securely.";
        
        // Added target_name to query and bind_param
        $stmt = $conn->prepare("INSERT INTO activity_logs (actor_name, actor_role, module_name, action_type, target_name, description, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("ssssss", $actor_name, $actor_role, $module, $action, $target, $desc);
            $stmt->execute();
            $stmt->close();
        }

    } elseif ($role === "peso_staff") {
        $staff_id = (int)($_SESSION["staff_id"] ?? 0);
        
        $stmt_name = $conn->prepare("SELECT first_name, last_name FROM peso_staff WHERE staff_id = ?");
        if ($stmt_name) {
            $stmt_name->bind_param("i", $staff_id);
            $stmt_name->execute();
            $res = $stmt_name->get_result();
            if ($row = $res->fetch_assoc()) {
                $actor_name = trim($row["first_name"] . " " . $row["last_name"]);
            } else {
                $actor_name = $_SESSION["staff_name"] ?? "PESO Staff";
            }
            $stmt_name->close();
        }
        
        $actor_role = "PESO Staff";
        $desc = $actor_name . " logged out securely.";
        
        // Added target_name to query and bind_param
        $stmt = $conn->prepare("INSERT INTO activity_logs (staff_id, actor_name, actor_role, module_name, action_type, target_name, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("issssss", $staff_id, $actor_name, $actor_role, $module, $action, $target, $desc);
            $stmt->execute();
            $stmt->close();
        }

    } elseif ($role === "user") {
        $actor_name = $_SESSION["user_name"] ?? "User";
        $actor_role = "Registered User"; 
        $desc = $actor_name . " logged out securely.";
        
        // Added target_name to query and bind_param
        $stmt = $conn->prepare("INSERT INTO activity_logs (actor_name, actor_role, module_name, action_type, target_name, description, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("ssssss", $actor_name, $actor_role, $module, $action, $target, $desc);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if ($role !== null) {
    auth_clear_role($role);
}

// Only destroy the shared browser session after its final signed-in role leaves.
if (auth_remaining_roles() === []) {
    $_SESSION = [];
    session_destroy();
}

header("Location: login.php");
exit();
?>
