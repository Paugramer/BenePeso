<?php
// Legacy endpoint retained only as a safe compatibility redirect.
// Administrator recovery is handled by the unified, CSRF-protected flow.
require_once __DIR__ . '/auth_session.php';
header('Location: login.php', true, 303);
exit();

session_start();
require "db.php";

require "PHPMailer/src/PHPMailer.php";
require "PHPMailer/src/SMTP.php";
require "PHPMailer/src/Exception.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$email = trim($_POST["email"] ?? "");

$_SESSION["afp_step"] = "email";
$_SESSION["afp_email"] = $email;

if ($email === "") {
  $_SESSION["afp_msg"] = "Please enter admin email.";
  header("Location: admin_login.php");
  exit();
}

// Corrected: Query the dedicated admins table
$stmt = $conn->prepare("SELECT admin_id FROM admins WHERE email=? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows !== 1) {
  $_SESSION["afp_step"] = "code";
  $_SESSION["afp_msg"] = "If that email exists, a code has been sent.";
  header("Location: admin_login.php");
  exit();
}

$row = $res->fetch_assoc();
$admin_id = (int)$row["admin_id"];

$code = strval(random_int(100000, 999999));
$expire = date("Y-m-d H:i:s", time() + 10*60);

// Corrected: Update the admins table
$up = $conn->prepare("UPDATE admins SET reset_code=?, reset_expire=? WHERE admin_id=?");
$up->bind_param("ssi", $code, $expire, $admin_id);
$up->execute();

$mail = new PHPMailer(true);
try{
  $mail->isSMTP();
  $mail->Host = "smtp.gmail.com";
  $mail->SMTPAuth = true;

  // Configure credentials through environment variables; never commit passwords.
  $smtp_username = getenv("ADMIN_SMTP_USERNAME") ?: "pauloaguilarvidal@gmail.com";
  $mail->Username = $smtp_username;
  $mail->Password = getenv("ADMIN_SMTP_PASSWORD") ?: "";

  $mail->SMTPSecure = "tls";
  $mail->Port = 587;

  $mail->setFrom($smtp_username, "PESO Vinzons");
  $mail->addAddress($email);

  $mail->Subject = "PESO Vinzons Administrator Recovery Code";
  $mail->Body = "A password reset was requested for your PESO Vinzons administrator account.\n\nVerification code: $code\n\nThis code expires in 10 minutes and may be used only once. Do not share it. If you did not request this reset, disregard this message.";

  $mail->send();

  $_SESSION["afp_step"] = "code";
  $_SESSION["afp_msg"] = "Verification code sent. Please check your email.";
  header("Location: admin_login.php");
  exit();

}catch(Exception $e){
  $_SESSION["afp_step"] = "code";
  $_SESSION["afp_msg"] = "Code generated but email sending failed. Please check SMTP settings.";
  header("Location: admin_login.php");
  exit();
}
?>
