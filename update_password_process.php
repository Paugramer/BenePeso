<?php
session_start();
require "db.php";

// 1. Strict Security Check: Ensure user is logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $user_id = (int)$_SESSION["user_id"];

    // Fetch inputs
    $new_pass         = $_POST['new_pass'] ?? '';
    $confirm_new_pass = $_POST['confirm_new_pass'] ?? '';

    // 2. Initial Validation: Check for empty fields
    if (empty($new_pass) || empty($confirm_new_pass)) {
        $_SESSION["flash"] = "Security Error: Password fields cannot be empty.";
        header("Location: profile.php");
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

            // 7. PROFESSIONAL FEATURE: Security Event Logging
            // We need to fetch the user's name first to log it accurately
            $name_stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
            $name_stmt->bind_param("i", $user_id);
            $name_stmt->execute();
            $res = $name_stmt->get_result();
            
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $actor_name = trim($row['first_name'] . " " . $row['last_name']);
                
                // Insert into Activity Log (UPDATED: Added created_at NOW())
                $log_stmt = $conn->prepare("INSERT INTO activity_logs (action_type, module_name, description, actor_name, created_at) VALUES ('Security', 'Profile', 'User successfully changed their account password.', ?, NOW())");
                if ($log_stmt) {
                    $log_stmt->bind_param("s", $actor_name);
                    $log_stmt->execute();
                    $log_stmt->close();
                }
            }

        } else {
            $_SESSION["flash"] = "System Error: Could not update password at this time.";
        }
        $stmt->close();
    } else {
        $_SESSION["flash"] = "Database Error: " . $conn->error;
    }

    header("Location: profile.php");
    exit();
} else {
    // Prevent direct URL access
    header("Location: profile.php");
    exit();
}