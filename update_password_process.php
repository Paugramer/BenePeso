<?php
require_once __DIR__ . '/auth.php';
require "db.php";
require_once __DIR__ . '/user_security_metadata_helper.php';

// 1. Strict Security Check: Ensure user is logged in
check_user_role('user');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    auth_require_csrf();
    $user_id = (int)$_SESSION["user_id"];

    // Fetch inputs
    $current_pass     = $_POST['current_pass'] ?? '';
    $new_pass         = $_POST['new_pass'] ?? '';
    $confirm_new_pass = $_POST['confirm_new_pass'] ?? '';

    // 2. Initial Validation: Check for empty fields
    if (empty($current_pass) || empty($new_pass) || empty($confirm_new_pass)) {
        $_SESSION["flash"] = "Security Error: Password fields cannot be empty.";
        header("Location: profile.php");
        exit();
    }

    $current_stmt = $conn->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
    $current_stmt->bind_param('i', $user_id);
    $current_stmt->execute();
    $current_user = $current_stmt->get_result()->fetch_assoc();
    $current_stmt->close();
    if (!$current_user || !password_verify($current_pass, $current_user['password_hash'])) {
        $_SESSION['flash'] = 'Security Error: Your current password is incorrect.';
        header('Location: profile.php');
        exit();
    }

    // 3. Validation: Match Check
    if ($new_pass !== $confirm_new_pass) {
        $_SESSION["flash"] = "Security Error: Your new passwords do not match. Please retype carefully.";
        header("Location: profile.php");
        exit();
    }

    // 4. Validation: Strength/Length Check
    if (strlen($new_pass) < 8) {
        $_SESSION["flash"] = "Security Error: Your new password must be at least 8 characters long for safety.";
        header("Location: profile.php");
        exit();
    }

    // 5. Securely Hash the New Password
    $hashed_password = password_hash($new_pass, PASSWORD_DEFAULT);

    // 6. Update Database
    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
    
    if ($stmt) {
        $stmt->bind_param("si", $hashed_password, $user_id);

        if ($stmt->execute()) {
            $_SESSION["flash"] = "Success: Your account password has been securely updated.";
            record_user_password_change($conn, $user_id);

            // 7. PROFESSIONAL FEATURE: Security Event Logging
            // We need to fetch the user's name first to log it accurately
            $name_stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
            $name_stmt->bind_param("i", $user_id);
            $name_stmt->execute();
            $res = $name_stmt->get_result();
            
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $actor_name = trim($row['first_name'] . " " . $row['last_name']);
                $log_description = $actor_name . ' changed their account password.';
                
                // Keep beneficiary security events visible in the beneficiary activity log.
                $log_stmt = $conn->prepare("INSERT INTO activity_logs (action_type, module_name, description, actor_name, actor_role, created_at) VALUES ('Security', 'Profile', ?, ?, 'Registered User', NOW())");
                if ($log_stmt) {
                    $log_stmt->bind_param("ss", $log_description, $actor_name);
                    $log_stmt->execute();
                    $log_stmt->close();
                }
            }

        } else {
            $_SESSION["flash"] = "System Error: Could not update password at this time.";
        }
        $stmt->close();
    } else {
        error_log('BENEPESO password update prepare failed for user ' . $user_id . ': ' . $conn->error);
        $_SESSION["flash"] = "System Error: Could not update password at this time.";
    }

    header("Location: profile.php");
    exit();
} else {
    // Prevent direct URL access
    header("Location: profile.php");
    exit();
}
