<?php
session_start();
require "db.php";

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

if ($row["reset_code"] !== $code) {
  $_SESSION["fp_msg"] = "Incorrect code. Please try again.";
  header("Location: login.php");
  exit();
}

if (strtotime($row["reset_expire"]) < time()) {
  $_SESSION["fp_msg"] = "Code expired. Please resend the code.";
  header("Location: login.php");
  exit();
}

$_SESSION["fp_code"] = $code;
$_SESSION["fp_step"] = "reset";
$_SESSION["fp_msg"] = "Code verified. Please create your new password.";
header("Location: login.php");
exit();
?>