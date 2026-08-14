<?php
session_start();
require "db.php";
require_once "privacy_helper.php";

$first_name     = trim($_POST["first_name"] ?? "");
$middle_name    = trim($_POST["middle_name"] ?? "");
$last_name      = trim($_POST["last_name"] ?? "");
$ext_name       = trim($_POST["ext_name"] ?? "");
$birthdate      = trim($_POST["birthdate"] ?? "");
$sex            = trim($_POST["sex"] ?? "");
$civil_status   = trim($_POST["civil_status"] ?? "");
$contact_no     = trim($_POST["contact_no"] ?? "");
$street_purok   = trim($_POST["street_purok_zone"] ?? "");
$barangay       = trim($_POST["barangay"] ?? "");
$district       = trim($_POST["district"] ?? "");
$email          = trim($_POST["email"] ?? "");
$password       = $_POST["password"] ?? "";
$confirm_pass   = $_POST["confirm_password"] ?? "";

if (!isset($_POST['privacy_acknowledgment']) || $_POST['privacy_acknowledgment'] !== '1') {
    $_SESSION["flash"] = "Please read and acknowledge the Privacy Notice before registering.";
    $_SESSION["form_data"] = $_POST;
    header("Location: signup.php");
    exit();
}

$valid_barangays = [
    "Aguit-It", "Banocboc", "Cagbalogo", "Calangcawan Norte", "Calangcawan Sur",
    "Guinacutan", "Mangcayo", "Mangcawayan", "Manlucugan", "Matango",
    "Napilihan", "Pinagtigasan", "Barangay I (Pob.)", "Barangay II (Pob.)",
    "Barangay III (Pob.)", "Sabang", "Santo Domingo", "Singi", "Sula"
];

if ($first_name === "" || $last_name === "" || $birthdate === "" || $sex === "" || 
    $civil_status === "" || $contact_no === "" || $street_purok === "" || 
    $barangay === "" || $email === "" || $password === "") {
    $_SESSION["flash"] = "Please complete all required fields.";
    $_SESSION["form_data"] = $_POST; 
    header("Location: signup.php");
    exit();
}

$profile_pic_name = "default_user.png"; 
if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {
    $target_dir = "uploads/";
    
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $file_ext = strtolower(pathinfo($_FILES["profile_pic"]["name"], PATHINFO_EXTENSION));
    $allowed_exts = ["jpg", "jpeg", "png", "webp"];

    if (in_array($file_ext, $allowed_exts)) {
        $new_filename = uniqid("IMG_", true) . "." . $file_ext;
        $target_file = $target_dir . $new_filename;

        if (move_uploaded_file($_FILES["profile_pic"]["tmp_name"], $target_file)) {
            $profile_pic_name = $new_filename;
        }
    }
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION["flash"] = "Please enter a valid email address.";
    $_SESSION["form_data"] = $_POST; 
    header("Location: signup.php");
    exit();
}

if (!preg_match("/^09\d{9}$/", $contact_no)) {
    $_SESSION["flash"] = "Contact number must be 11 digits and start with 09.";
    $_SESSION["form_data"] = $_POST; 
    header("Location: signup.php");
    exit();
}

if (!in_array($barangay, $valid_barangays, true)) {
    $_SESSION["flash"] = "Please select a valid barangay.";
    $_SESSION["form_data"] = $_POST; 
    header("Location: signup.php");
    exit();
}

if ($password !== $confirm_pass) {
    $_SESSION["flash"] = "Passwords do not match.";
    $_SESSION["form_data"] = $_POST; 
    header("Location: signup.php");
    exit();
}

if (strlen($password) < 8) {
    $_SESSION["flash"] = "Password must be at least 8 characters.";
    $_SESSION["form_data"] = $_POST; 
    header("Location: signup.php");
    exit();
}

$email_found = false;

$q1 = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
if ($q1) {
    $q1->bind_param("s", $email);
    $q1->execute();
    if ($q1->get_result()->num_rows > 0) $email_found = true;
    $q1->close();
}

if (!$email_found) {
    $q2 = $conn->prepare("SELECT staff_id FROM peso_staff WHERE email = ? LIMIT 1");
    if ($q2) {
        $q2->bind_param("s", $email);
        $q2->execute();
        if ($q2->get_result()->num_rows > 0) $email_found = true;
        $q2->close();
    }
}

if (!$email_found) {
    $q3 = $conn->prepare("SELECT admin_id FROM admins WHERE email = ? LIMIT 1");
    if ($q3) {
        $q3->bind_param("s", $email);
        $q3->execute();
        if ($q3->get_result()->num_rows > 0) $email_found = true;
        $q3->close();
    }
}

if ($email_found) {
    $_SESSION["flash"] = "This email is already registered in the system.";
    $_SESSION["form_data"] = $_POST; 
    header("Location: signup.php");
    exit();
}

$check_contact = $conn->prepare("SELECT user_id FROM users WHERE contact_no = ? LIMIT 1");
if ($check_contact) {
    $check_contact->bind_param("s", $contact_no);
    $check_contact->execute();
    if ($check_contact->get_result()->num_rows > 0) {
        $_SESSION["flash"] = "This contact number is already registered.";
        $_SESSION["form_data"] = $_POST; 
        header("Location: signup.php");
        exit();
    }
    $check_contact->close();
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$municipality = "Vinzons";

$stmt = $conn->prepare("
    INSERT INTO users (
        first_name, middle_name, last_name, ext_name, 
        birthdate, sex, civil_status, contact_no, 
        street_purok_zone, barangay, municipality, district, 
        email, profile_pic, password_hash
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    $_SESSION["flash"] = "Database error: " . $conn->error;
    $_SESSION["form_data"] = $_POST; 
    header("Location: signup.php");
    exit();
}

$stmt->bind_param("sssssssssssssss", 
    $first_name, $middle_name, $last_name, $ext_name,
    $birthdate, $sex, $civil_status, $contact_no,
    $street_purok, $barangay, $municipality, $district,
    $email, $profile_pic_name, $hash
);

if ($stmt->execute()) {
    $new_user_id = (int)$stmt->insert_id;
    if (!record_privacy_acknowledgment($conn, $new_user_id, 'account_registration')) {
        $conn->query("DELETE FROM users WHERE user_id = " . $new_user_id);
        $_SESSION["flash"] = "Registration could not be completed. Please try again.";
        $_SESSION["form_data"] = $_POST;
        header("Location: signup.php");
        exit();
    }
    $_SESSION["flash"] = "Account created successfully. You may now log in.";
    header("Location: login.php");
    exit();
}

$_SESSION["flash"] = "System error during registration.";
$_SESSION["form_data"] = $_POST; 
header("Location: signup.php");
exit();
?>
