<?php
require_once __DIR__ . '/auth_session.php';
require_once __DIR__ . '/auth_rate_limit.php';
require "db.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: login.php'); exit(); }
auth_require_csrf();

$lastResetRequest = (int)($_SESSION['fp_last_request'] ?? 0);
if ($lastResetRequest > time() - 60) {
  $_SESSION['fp_msg'] = 'Please wait one minute before requesting another code.';
  header('Location: login.php');
  exit();
}
$_SESSION['fp_last_request'] = time();
$_SESSION['fp_attempts'] = 0;

require "PHPMailer/src/PHPMailer.php";
require "PHPMailer/src/SMTP.php";
require "PHPMailer/src/Exception.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$email = trim($_POST["email"] ?? "");

$_SESSION["fp_step"] = "email";
$_SESSION["fp_email"] = $email;

if ($email === "") {
  $_SESSION["fp_msg"] = "Please enter your email.";
  header("Location: login.php");
  exit();
}

$reset_identity = mb_strtolower($email);
$reset_ip = auth_request_ip();
$reset_retry_after = max(
  auth_rate_limit_retry_after('reset-account', $reset_identity, 3, 900, 900),
  auth_rate_limit_retry_after('reset-ip', $reset_ip, 10, 900, 900)
);
if ($reset_retry_after > 0) {
  $_SESSION['fp_msg'] = 'Too many recovery requests. Please wait before trying again.';
  header('Retry-After: ' . $reset_retry_after);
  header('Location: login.php');
  exit();
}
auth_rate_limit_hit('reset-account', $reset_identity, 3, 900, 900);
auth_rate_limit_hit('reset-ip', $reset_ip, 10, 900, 900);

$user_role = "";
$user_found = false;

$stmt_admin = $conn->prepare("SELECT admin_id FROM admins WHERE email=?");
$stmt_admin->bind_param("s", $email);
$stmt_admin->execute();
if ($stmt_admin->get_result()->num_rows === 1) {
    $user_role = "admin";
    $user_found = true;
}
$stmt_admin->close();

if (!$user_found) {
    $stmt_staff = $conn->prepare("SELECT staff_id FROM peso_staff WHERE email=?");
    $stmt_staff->bind_param("s", $email);
    $stmt_staff->execute();
    if ($stmt_staff->get_result()->num_rows === 1) {
        $user_role = "peso_staff";
        $user_found = true;
    }
    $stmt_staff->close();
}

if (!$user_found) {
    $stmt_user = $conn->prepare("SELECT user_id FROM users WHERE email=?");
    $stmt_user->bind_param("s", $email);
    $stmt_user->execute();
    if ($stmt_user->get_result()->num_rows === 1) {
        $user_role = "user";
        $user_found = true;
    }
    $stmt_user->close();
}

if (!$user_found) {
  $_SESSION["fp_step"] = "code"; 
  $_SESSION["fp_msg"] = "If that email exists, a code has been sent.";
  unset($_SESSION["fp_role"]);
  header("Location: login.php");
  exit();
}

$_SESSION["fp_role"] = $user_role;

$code = strval(random_int(100000, 999999));
$expire = date("Y-m-d H:i:s", time() + 10*60);

$table = "users";
if ($user_role === "peso_staff") $table = "peso_staff";
if ($user_role === "admin") $table = "admins";

$stored_code = password_hash($code, PASSWORD_DEFAULT);
$up = $conn->prepare("UPDATE $table SET reset_code=?, reset_expire=? WHERE email=?");
$up->bind_param("sss", $stored_code, $expire, $email);
$up->execute();

$mail = new PHPMailer(true);
try{
  $mail->isSMTP();
  $mail->Host = "smtp.gmail.com";
  $mail->SMTPAuth = true;

  $smtp_username = getenv("BENEPESO_SMTP_USERNAME") ?: "lguvinzonspeso@gmail.com";
  $mail->Username = $smtp_username;
  $mail->Password = getenv("BENEPESO_SMTP_PASSWORD") ?: "";

  $mail->SMTPSecure = "tls";
  $mail->Port = 587;

  $mail->setFrom($smtp_username, "PESO Vinzons");
  $mail->addAddress($email);

  $mail->isHTML(true);
  $mail->Subject = "PESO Vinzons Account Recovery Code";
  
  $mail->Body = "
  <div style='font-family: Arial, sans-serif; background-color: #f4f8f5; padding: 40px 20px; color: #163524;'>
      <div style='max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #dbe6df;'>
          
          <div style='background-color: #1f7a54; padding: 30px; text-align: center;'>
              <h1 style='color: #ffffff; margin: 0; font-size: 26px; letter-spacing: 1px; font-weight: 800;'>PESO Vinzons</h1>
              <p style='color: #e6f4ed; margin: 5px 0 0; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;'>Account Recovery</p>
          </div>
          
          <div style='padding: 40px 30px; text-align: center;'>
              <h2 style='margin-top: 0; color: #145339; font-size: 22px; font-weight: 800;'>Your verification code</h2>
              <p style='color: #66786f; font-size: 15px; line-height: 1.6; margin-bottom: 30px;'>
                  A password reset was requested for the PESO Vinzons account registered to <b>{$email}</b>. Enter this code on the account recovery page:
              </p>
              
              <div style='margin: 0 auto; padding: 20px; background-color: #f9fbf9; border: 2px dashed #1f7a54; border-radius: 12px; width: fit-content;'>
                  <span style='letter-spacing: 8px; font-size: 36px; font-weight: 900; color: #1f7a54;'>{$code}</span>
              </div>
              
              <p style='color: #66786f; font-size: 14px; margin-top: 30px; line-height: 1.6;'>
                  This code expires in <b>10 minutes</b> and may be used only once. Do not share it. If you did not request a password reset, you may disregard this message; your password will remain unchanged.
              </p>
          </div>
          
          <div style='background-color: #f9fbf9; padding: 25px; text-align: center; border-top: 1px solid #dbe6df;'>
              <p style='color: #9ab0a3; font-size: 12px; margin: 0; line-height: 1.5;'>
                  © " . date("Y") . " PESO Vinzons<br>Public Employment Service Office • Municipality of Vinzons, Camarines Norte
              </p>
          </div>
          
      </div>
  </div>";

  $mail->AltBody = "Your PESO Vinzons account recovery code is: $code\n\nThis code expires in 10 minutes and may be used only once. Do not share it. If you did not request a password reset, disregard this message.";

  $mail->send();

  $_SESSION["fp_step"] = "code";
  $_SESSION["fp_msg"] = "Verification code sent. Please check your email.";
  header("Location: login.php");
  exit();

}catch(Exception $e){
  $_SESSION["fp_step"] = "email";
  $_SESSION["fp_msg"] = "The recovery email could not be sent. Please contact PESO support or try again later.";
  error_log('BENEPESO password reset email failed: ' . $mail->ErrorInfo);
  header("Location: login.php");
  exit();
}
?>
