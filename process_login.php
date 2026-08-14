<?php
require_once __DIR__ . '/auth_session.php';
require "db.php";

$now = time();

$lock_until = $_SESSION["lock_until"] ?? 0;
if ($lock_until && $lock_until > $now) {
    $_SESSION["flash"] = "Too many failed attempts. Please try again later.";
    header("Location: login.php");
    exit();
}

$email    = trim($_POST["email"] ?? "");
$password = $_POST["password"] ?? "";

function password_matches($password, $hash): bool {
    if (password_verify($password, $hash)) {
        return true;
    }

    $trimmed = trim($password);
    return $trimmed !== $password && password_verify($trimmed, $hash);
}

if ($email === "" || $password === "") {
    $_SESSION["login_email"] = $email; 
    $_SESSION["flash"] = "Please enter your email and password.";
    header("Location: login.php");
    exit();
}

$user_found = false;

/* ==========================================
   STEP 1: CHECK ADMIN TABLE FIRST
========================================== */
$stmt_admin = $conn->prepare("SELECT admin_id, password_hash FROM admins WHERE email = ? LIMIT 1");
if ($stmt_admin) {
    $stmt_admin->bind_param("s", $email);
    $stmt_admin->execute();
    $res_admin = $stmt_admin->get_result();

    if ($res_admin->num_rows === 1) {
        $row = $res_admin->fetch_assoc();
        
        if (password_verify($password, $row["password_hash"])) {
            $_SESSION["fail_count"] = 0;
            $_SESSION["lock_until"] = 0;
            $_SESSION["admin_id"] = (int)$row["admin_id"];
            $_SESSION["admin_name"] = "Administrator"; 
            auth_activate_role("admin");

            // DIRECT UNIFIED SQL LOGGING FOR ADMIN
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (actor_name, actor_role, module_name, action_type, target_name, description, created_at) VALUES ('System Admin', 'Administrator', 'Auth', 'LOGIN', 'System', 'Admin logged in securely.', NOW())");
            if ($log_stmt) {
                $log_stmt->execute();
                $log_stmt->close();
            }
            
            header("Location: admin_dashboard.php");
            exit();
        } else {
            $user_found = true; 
        }
    }
    $stmt_admin->close();
}

/* ==========================================
   STEP 2: CHECK PESO STAFF TABLE
========================================== */
$stmt_staff = $conn->prepare("SELECT staff_id, first_name, last_name, profile_picture, password_hash, status FROM peso_staff WHERE email = ? LIMIT 1");
if ($stmt_staff) {
    $stmt_staff->bind_param("s", $email);
    $stmt_staff->execute();
    $res_staff = $stmt_staff->get_result();

    if ($res_staff->num_rows === 1) {
        $row = $res_staff->fetch_assoc();

        if (password_matches($password, $row["password_hash"])) {
            if (isset($row["status"]) && strcasecmp($row["status"], "Banned") === 0) {
                header("Location: login.php?msg=banned");
                exit();
            }

            $_SESSION["fail_count"] = 0;
            $_SESSION["lock_until"] = 0;
            $staff_id = (int)$row["staff_id"];
            $staff_full_name = trim($row["first_name"] . " " . $row["last_name"]);

            $_SESSION["staff_id"] = $staff_id;
            $_SESSION["staff_name"] = $staff_full_name;
            $_SESSION["staff_pic"] = $row["profile_picture"];
            auth_activate_role("peso_staff");

            // DIRECT UNIFIED SQL LOGGING FOR STAFF
            $log_desc = $staff_full_name . " logged in securely.";
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (staff_id, actor_name, actor_role, module_name, action_type, target_name, description, created_at) VALUES (?, ?, 'PESO Staff', 'Auth', 'LOGIN', 'System', ?, NOW())");
            if ($log_stmt) {
                $log_stmt->bind_param("iss", $staff_id, $staff_full_name, $log_desc);
                $log_stmt->execute();
                $log_stmt->close();
            }

            header("Location: peso_staff_dashboard.php");
            exit();
        } else {
            $user_found = true;
        }
    }
    $stmt_staff->close();
}

/* ==========================================
   STEP 3: CHECK USERS TABLE (Beneficiaries)
========================================== */
$stmt_user = $conn->prepare("SELECT user_id, first_name, last_name, profile_pic, password_hash, status FROM users WHERE email = ? LIMIT 1");
if ($stmt_user) {
    $stmt_user->bind_param("s", $email);
    $stmt_user->execute();
    $res_user = $stmt_user->get_result();

    if ($res_user->num_rows === 1) {
        $row = $res_user->fetch_assoc();
        $user_id = (int)$row["user_id"];

        if (password_verify($password, $row["password_hash"])) {
            if (isset($row["status"]) && strcasecmp($row["status"], "Banned") === 0) {
                $status_log = "failed";
                $h = $conn->prepare("INSERT INTO login_history (user_id, status) VALUES (?, ?)");
                if ($h) {
                    $h->bind_param("is", $user_id, $status_log);
                    $h->execute();
                }
                header("Location: login.php?msg=banned");
                exit();
            }

            $_SESSION["fail_count"] = 0;
            $_SESSION["lock_until"] = 0;

            $status = "success";
            $h = $conn->prepare("INSERT INTO login_history (user_id, status) VALUES (?, ?)");
            if ($h) {
                $h->bind_param("is", $user_id, $status);
                $h->execute();
            }

            $user_full_name = trim($row["first_name"] . " " . $row["last_name"]);
            $_SESSION["user_id"] = $user_id;
            $_SESSION["user_name"] = $user_full_name;
            $_SESSION["user_pic"] = $row["profile_pic"] ?? 'default_avatar.png';
            auth_activate_role("user");

            // DIRECT UNIFIED SQL LOGGING FOR USERS
            $log_desc = "User successfully logged into the system.";
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (actor_name, actor_role, module_name, action_type, target_name, description, created_at) VALUES (?, 'Registered User', 'Auth', 'LOGIN', 'System', ?, NOW())");
            if ($log_stmt) {
                $log_stmt->bind_param("ss", $user_full_name, $log_desc);
                $log_stmt->execute();
                $log_stmt->close();
            }

            header("Location: home.php");
            exit();
        } else {
            $status = "failed";
            $h = $conn->prepare("INSERT INTO login_history (user_id, status) VALUES (?, ?)");
            if ($h) {
                $h->bind_param("is", $user_id, $status);
                $h->execute();
            }
        }
    }
    $stmt_user->close();
}

/* ==========================================
   FAILED LOGIN HANDLING
========================================== */
$_SESSION["login_email"] = $email; 
$_SESSION["fail_count"] = ($_SESSION["fail_count"] ?? 0) + 1;

if ($_SESSION["fail_count"] >= 3) {
    $_SESSION["lock_until"] = time() + 60;
    $_SESSION["flash"] = "Too many failed attempts. Please wait 1 minute before trying again.";
    header("Location: login.php");
    exit();
}

$_SESSION["flash"] = "Invalid email or password. Attempts: " . $_SESSION["fail_count"] . "/3";
header("Location: login.php");
exit();
?>
