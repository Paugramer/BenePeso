<?php
session_start();
require "db.php";

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

if (strlen($p1) < 8) {
  $_SESSION["fp_msg"] = "Password must be at least 8 characters.";
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

if ($row["reset_code"] !== $code) {
  $_SESSION["fp_msg"] = "Invalid code. Please resend the code.";
  header("Location: login.php");
  exit();
}

if (strtotime($row["reset_expire"]) < time()) {
  $_SESSION["fp_msg"] = "Code expired. Please resend the code.";
  header("Location: login.php");
  exit();
}

$hash = password_hash($p1, PASSWORD_DEFAULT);

$up = $conn->prepare("UPDATE $table SET password_hash=?, reset_code=NULL, reset_expire=NULL WHERE email=?");
$up->bind_param("ss", $hash, $email);
$up->execute();

unset($_SESSION["fp_step"], $_SESSION["fp_email"], $_SESSION["fp_code"], $_SESSION["fp_msg"], $_SESSION["fp_role"]);

$_SESSION["flash"] = "Password updated successfully. You may now log in.";
header("Location: login.php");
exit();
?>