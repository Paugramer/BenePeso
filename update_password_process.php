<?php
require_once __DIR__ . '/auth.php';
require "db.php";
require_once __DIR__ . '/user_security_metadata_helper.php';
require_once __DIR__ . '/auth_rate_limit.php';
require_once __DIR__ . '/activity_log_helper.php';

// 1. Strict Security Check: Ensure user is logged in
check_user_role('user');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    auth_require_csrf();
    $user_id = (int)$_SESSION["user_id"];
    $rate_identity = $user_id . '|' . auth_request_ip();

    try {
        $retry_after = auth_rate_limit_retry_after('profile-password', $rate_identity, 5, 900, 900);
    } catch (Throwable $error) {
        error_log('Password rate-limit check failed: ' . $error->getMessage());
        $retry_after = 0;
    }
    if ($retry_after > 0) {
        $_SESSION['flash'] = 'Security Error: Too many incorrect password attempts. Try again in ' . max(1, (int)ceil($retry_after / 60)) . ' minute(s).';
        header('Location: profile.php#security');
        exit();
    }

    // Fetch inputs
    $current_pass     = $_POST['current_pass'] ?? '';
    $new_pass         = $_POST['new_pass'] ?? '';
    $confirm_new_pass = $_POST['confirm_new_pass'] ?? '';

    // 2. Initial Validation: Check for empty fields
    if (empty($current_pass) || empty($new_pass) || empty($confirm_new_pass)) {
        $_SESSION["flash"] = "Security Error: Password fields cannot be empty.";
        header("Location: profile.php#security");
        exit();
    }

    $current_stmt = $conn->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
    $current_stmt->bind_param('i', $user_id);
    $current_stmt->execute();
    $current_user = $current_stmt->get_result()->fetch_assoc();
    $current_stmt->close();
    if (!$current_user || !password_verify($current_pass, $current_user['password_hash'])) {
        try {
            auth_rate_limit_hit('profile-password', $rate_identity, 5, 900, 900);
        } catch (Throwable $error) {
            error_log('Password rate-limit update failed: ' . $error->getMessage());
        }
        $_SESSION['flash'] = 'Security Error: Your current password is incorrect.';
        header('Location: profile.php#security');
        exit();
    }
    try {
        auth_rate_limit_clear('profile-password', $rate_identity);
    } catch (Throwable $error) {
        error_log('Password rate-limit clear failed: ' . $error->getMessage());
    }

    // 3. Validation: Match Check
    if ($new_pass !== $confirm_new_pass) {
        $_SESSION["flash"] = "Security Error: Your new passwords do not match. Please retype carefully.";
        header("Location: profile.php#security");
        exit();
    }

    // 4. Validation: Strength/Length Check
    if (strlen($new_pass) < 10
        || !preg_match('/[a-z]/', $new_pass)
        || !preg_match('/[A-Z]/', $new_pass)
        || !preg_match('/\d/', $new_pass)
        || !preg_match('/[^A-Za-z0-9]/', $new_pass)) {
        $_SESSION["flash"] = "Security Error: Use at least 10 characters with uppercase, lowercase, a number, and a symbol.";
        header("Location: profile.php#security");
        exit();
    }

    if (password_verify($new_pass, $current_user['password_hash'])) {
        $_SESSION["flash"] = "Security Error: Your new password must be different from your current password.";
        header("Location: profile.php#security");
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
            session_regenerate_id(true);

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
                
                benepeso_log_user_activity(
                    $conn,
                    $user_id,
                    $actor_name,
                    'Profile',
                    'Security',
                    'Account',
                    $log_description
                );
            }

        } else {
            $_SESSION["flash"] = "System Error: Could not update password at this time.";
        }
        $stmt->close();
    } else {
        error_log('BENEPESO password update prepare failed for user ' . $user_id . ': ' . $conn->error);
        $_SESSION["flash"] = "System Error: Could not update password at this time.";
    }

    header("Location: profile.php#security");
    exit();
} else {
    // Prevent direct URL access
    header("Location: profile.php");
    exit();
}
