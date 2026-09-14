<?php
require_once __DIR__ . '/auth_session.php';
require_once __DIR__ . '/auth_rate_limit.php';
require "db.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: login.php'); exit(); }
auth_require_csrf();

$_SESSION['fp_attempts'] = (int)($_SESSION['fp_attempts'] ?? 0) + 1;
if ($_SESSION['fp_attempts'] > 5) {
  unset($_SESSION['fp_code'], $_SESSION['fp_role']);
  $_SESSION['fp_step'] = 'email';
  $_SESSION['fp_msg'] = 'Too many incorrect attempts. Request a new recovery code.';
  header('Location: login.php');
  exit();
}

$email = $_SESSION["fp_email"] ?? "";
$role  = $_SESSION["fp_role"] ?? "";
$code  = trim($_POST["code"] ?? "");

$_SESSION["fp_step"] = "code";

if ($email === "" || $code === "") {
  $_SESSION["fp_msg"] = "Please enter the 6-digit code.";
  header("Location: login.php");
  exit();
}

if ($role === "") {
  $_SESSION["fp_msg"] = "Incorrect code. Please try again.";
  header("Location: login.php");
  exit();
}

$verify_identity = mb_strtolower($email) . '|' . auth_request_ip();
$verify_retry_after = auth_rate_limit_retry_after('reset-code', $verify_identity, 5, 900, 900);
if ($verify_retry_after > 0) {
  unset($_SESSION['fp_code'], $_SESSION['fp_role']);
  $_SESSION['fp_step'] = 'email';
  $_SESSION['fp_msg'] = 'Too many incorrect attempts. Request a new recovery code later.';
  header('Retry-After: ' . $verify_retry_after);
  header('Location: login.php');
  exit();
}

$table = "users";
if ($role === "peso_staff") $table = "peso_staff";
if ($role === "admin") $table = "admins";

$stmt = $conn->prepare("SELECT reset_code, reset_expire FROM $table WHERE email=?");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows !== 1) {
  $_SESSION["fp_msg"] = "Invalid request.";
  header("Location: login.php");
  exit();
}

$row = $res->fetch_assoc();

if (!$row["reset_code"] || !$row["reset_expire"]) {
  $_SESSION["fp_msg"] = "No active reset request. Please resend the code.";
  header("Location: login.php");
  exit();
}

$storedCode = (string)$row['reset_code'];
$storedCodeInfo = password_get_info($storedCode);
$codeMatches = ($storedCodeInfo['algoName'] ?? 'unknown') !== 'unknown'
  ? password_verify($code, $storedCode)
  : hash_equals($storedCode, $code); // Compatibility for codes issued before hashing was enabled.
if (!$codeMatches) {
  auth_rate_limit_hit('reset-code', $verify_identity, 5, 900, 900);
  $_SESSION["fp_msg"] = "Incorrect code. Please try again.";
  header("Location: login.php");
  exit();
}

if (strtotime($row["reset_expire"]) < time()) {
  $_SESSION["fp_msg"] = "Code expired. Please resend the code.";
  header("Location: login.php");
  exit();
}

// Keep the stored verifier in the session; never retain the submitted code.
auth_rate_limit_clear('reset-code', $verify_identity);
$_SESSION["fp_code"] = $storedCode;
$_SESSION['fp_attempts'] = 0;
$_SESSION["fp_step"] = "reset";
$_SESSION["fp_msg"] = "Code verified. Please create your new password.";
header("Location: login.php");
exit();
?>
