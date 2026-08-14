<?php
session_start();
require "db.php";

$email = $_SESSION["afp_email"] ?? "";
$code  = $_SESSION["afp_code"] ?? "";

$p1 = $_POST["new_password"] ?? "";
$p2 = $_POST["confirm_password"] ?? "";

$_SESSION["afp_step"] = "reset";

if ($email==="" || $code==="" || $p1==="" || $p2==="") {
  $_SESSION["afp_msg"] = "Please complete all fields.";
  header("Location: admin_login.php");
  exit();
}

if (strlen($p1) < 8) {
  $_SESSION["afp_msg"] = "Password must be at least 8 characters.";
  header("Location: admin_login.php");
  exit();
}

if ($p1 !== $p2) {
  $_SESSION["afp_msg"] = "Passwords do not match.";
  header("Location: admin_login.php");
  exit();
}

// Corrected: Final verification against the admins table
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

if ($row["reset_code"] !== $code) {
  $_SESSION["afp_msg"] = "Invalid code. Please resend the code.";
  header("Location: admin_login.php");
  exit();
}

if (strtotime($row["reset_expire"]) < time()) {
  $_SESSION["afp_msg"] = "Code expired. Please resend the code.";
  header("Location: admin_login.php");
  exit();
}

$hash = password_hash($p1, PASSWORD_DEFAULT);

// Corrected: Update the admins table and clear the reset tokens
$up = $conn->prepare("UPDATE admins SET password_hash=?, reset_code=NULL, reset_expire=NULL WHERE email=?");
$up->bind_param("ss", $hash, $email);
$up->execute();

unset($_SESSION["afp_step"], $_SESSION["afp_email"], $_SESSION["afp_code"], $_SESSION["afp_msg"]);

$_SESSION["flash"] = "Admin password updated successfully. You may now log in.";
header("Location: admin_login.php");
exit();
?>