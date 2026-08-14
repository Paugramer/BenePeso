<?php
session_start();
require "db.php";

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

$up = $conn->prepare("UPDATE $table SET reset_code=?, reset_expire=? WHERE email=?");
$up->bind_param("sss", $code, $expire, $email);
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

  $mail->setFrom($smtp_username, "BENEPESO");
  $mail->addAddress($email);

  $mail->isHTML(true);
  $mail->Subject = "BENEPESO Password Reset Code";
  
  $mail->Body = "
  <div style='font-family: Arial, sans-serif; background-color: #f4f8f5; padding: 40px 20px; color: #163524;'>
      <div style='max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #dbe6df;'>
          
          <div style='background-color: #1f7a54; padding: 30px; text-align: center;'>
              <h1 style='color: #ffffff; margin: 0; font-size: 26px; letter-spacing: 1px; font-weight: 800;'>BENEPESO</h1>
              <p style='color: #e6f4ed; margin: 5px 0 0; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;'>Security Alert</p>
          </div>
          
          <div style='padding: 40px 30px; text-align: center;'>
              <h2 style='margin-top: 0; color: #145339; font-size: 22px; font-weight: 800;'>Password Reset Request</h2>
              <p style='color: #66786f; font-size: 15px; line-height: 1.6; margin-bottom: 30px;'>
                  We received a request to reset the password for your account associated with <b>{$email}</b>. Here is your verification code:
              </p>
              
              <div style='margin: 0 auto; padding: 20px; background-color: #f9fbf9; border: 2px dashed #1f7a54; border-radius: 12px; width: fit-content;'>
                  <span style='letter-spacing: 8px; font-size: 36px; font-weight: 900; color: #1f7a54;'>{$code}</span>
              </div>
              
              <p style='color: #66786f; font-size: 14px; margin-top: 30px; line-height: 1.6;'>
                  This code will expire in <b>10 minutes</b>. For your security, do not share this code with anyone. If you did not request this reset, please ignore this email.
              </p>
          </div>
          
          <div style='background-color: #f9fbf9; padding: 25px; text-align: center; border-top: 1px solid #dbe6df;'>
              <p style='color: #9ab0a3; font-size: 12px; margin: 0; line-height: 1.5;'>
                  © " . date("Y") . " BENEPESO • Public Employment Service Office<br>Municipality of Vinzons, Camarines Norte
              </p>
          </div>
          
      </div>
  </div>";

  $mail->AltBody = "Your BENEPESO verification code is: $code\n\nThis code will expire in 10 minutes. Do not share this code with anyone.";

  $mail->send();

  $_SESSION["fp_step"] = "code";
  $_SESSION["fp_msg"] = "Verification code sent. Please check your email.";
  header("Location: login.php");
  exit();

}catch(Exception $e){
  $_SESSION["fp_step"] = "code";
  $_SESSION["fp_msg"] = "Email sending failed. Error: " . $mail->ErrorInfo;
  header("Location: login.php");
  exit();
}
?>
