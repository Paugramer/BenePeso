<?php
require_once __DIR__ . '/auth_session.php';
require "db.php";
require_once __DIR__ . '/remember_auth.php';
require_once __DIR__ . '/user_security_metadata_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: login.php'); exit(); }
auth_require_csrf();

$email = $_SESSION["fp_email"] ?? "";
$role  = $_SESSION["fp_role"] ?? "";
$code  = $_SESSION["fp_code"] ?? "";

$p1 = $_POST["new_password"] ?? "";
$p2 = $_POST["confirm_password"] ?? "";

$_SESSION["fp_step"] = "reset";

if ($email==="" || $role==="" || $code==="" || $p1==="" || $p2==="") {
  $_SESSION["fp_msg"] = "Please complete all fields.";
  header("Location: login.php");
  exit();
}

if (strlen($p1) < 10
    || !preg_match('/[a-z]/', $p1)
    || !preg_match('/[A-Z]/', $p1)
    || !preg_match('/\d/', $p1)
    || !preg_match('/[^A-Za-z0-9]/', $p1)) {
  $_SESSION["fp_msg"] = "Use at least 10 characters with uppercase, lowercase, a number, and a symbol.";
  header("Location: login.php");
  exit();
}

if ($p1 !== $p2) {
  $_SESSION["fp_msg"] = "Passwords do not match.";
  header("Location: login.php");
  exit();
}

$table = "users";
if ($role === "peso_staff") $table = "peso_staff";
if ($role === "admin") $table = "admins";
$id_column = $role === 'admin' ? 'admin_id' : ($role === 'peso_staff' ? 'staff_id' : 'user_id');

$stmt = $conn->prepare("SELECT $id_column AS account_id, password_hash, reset_code, reset_expire FROM $table WHERE email=?");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows !== 1) {
  $_SESSION["fp_msg"] = "Invalid request.";
  header("Location: login.php");
  exit();
}

$row = $res->fetch_assoc();

if (!hash_equals((string)$row['reset_code'], (string)$code)) {
  $_SESSION["fp_msg"] = "Invalid code. Please resend the code.";
  header("Location: login.php");
  exit();
}

if (strtotime($row["reset_expire"]) < time()) {
  $_SESSION["fp_msg"] = "Code expired. Please resend the code.";
  header("Location: login.php");
  exit();
}

if (password_verify($p1, (string)$row['password_hash'])) {
  $_SESSION["fp_msg"] = "Your new password must be different from your current password.";
  header("Location: login.php");
  exit();
}

$hash = password_hash($p1, PASSWORD_DEFAULT);

$up = $conn->prepare("UPDATE $table SET password_hash=?, reset_code=NULL, reset_expire=NULL WHERE email=?");
$up->bind_param("ss", $hash, $email);
$up->execute();
remember_auth_revoke_account($conn, $role, (int)$row['account_id']);
if ($role === 'user') {
  record_user_password_change($conn, (int)$row['account_id']);
}
session_regenerate_id(true);

unset($_SESSION["fp_step"], $_SESSION["fp_email"], $_SESSION["fp_code"], $_SESSION["fp_msg"], $_SESSION["fp_role"]);

$_SESSION["flash"] = "Password updated successfully. You may now log in.";
header("Location: login.php");
exit();
?>
