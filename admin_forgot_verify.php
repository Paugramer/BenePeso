<?php
session_start();
require "db.php";

$email = $_SESSION["afp_email"] ?? "";
$code  = trim($_POST["code"] ?? "");

$_SESSION["afp_step"] = "code";

if ($email === "" || $code === "") {
  $_SESSION["afp_msg"] = "Please enter the 6-digit code.";
  header("Location: admin_login.php");
  exit();
}

// Corrected: Check the admins table
$stmt = $conn->prepare("SELECT reset_code, reset_expire FROM admins WHERE email=? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows !== 1) {
  $_SESSION["afp_msg"] = "Invalid request.";
  header("Location: admin_login.php");
  exit();
}

$row = $res->fetch_assoc();

if (!$row["reset_code"] || !$row["reset_expire"]) {
  $_SESSION["afp_msg"] = "No active reset request. Please resend the code.";
  header("Location: admin_login.php");
  exit();
}

if ($row["reset_code"] !== $code) {
  $_SESSION["afp_msg"] = "Incorrect code. Please try again.";
  header("Location: admin_login.php");
  exit();
}

if (strtotime($row["reset_expire"]) < time()) {
  $_SESSION["afp_msg"] = "Code expired. Please resend the code.";
  header("Location: admin_login.php");
  exit();
}

$_SESSION["afp_code"] = $code;
$_SESSION["afp_step"] = "reset";
$_SESSION["afp_msg"] = "Code verified. Please create your new password.";
header("Location: admin_login.php");
exit();
?>