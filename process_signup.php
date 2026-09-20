<?php
require_once __DIR__ . '/auth_session.php';
require "db.php";
require_once "privacy_helper.php";
require_once "beneficiary_choices.php";
require_once __DIR__ . '/google_auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: signup.php');
    exit();
}
auth_require_csrf();

$google_identity = google_auth_pending_identity();
$google_registration = $google_identity !== null;

$first_name     = trim($_POST["first_name"] ?? "");
$middle_name    = normalize_optional_middle_name($_POST["middle_name"] ?? "");
$last_name      = trim($_POST["last_name"] ?? "");
$ext_name       = normalize_optional_name_part($_POST["ext_name"] ?? "");
$birthdate      = trim($_POST["birthdate"] ?? "");
$sex            = trim($_POST["sex"] ?? "");
$civil_status   = trim($_POST["civil_status"] ?? "");
$contact_no     = trim($_POST["contact_no"] ?? "");
$street_purok   = trim($_POST["street_purok_zone"] ?? "");
$barangay       = canonical_beneficiary_barangay($_POST["barangay"] ?? "");
$district       = trim($_POST["district"] ?? "");
$email          = $google_registration
    ? mb_strtolower(trim((string)$google_identity['email']))
    : trim($_POST["email"] ?? "");
$password       = $_POST["password"] ?? "";
$confirm_pass   = $_POST["confirm_password"] ?? "";

if (!isset($_POST['privacy_acknowledgment']) || $_POST['privacy_acknowledgment'] !== '1') {
    $_SESSION["flash"] = "Please read and acknowledge the Privacy Notice before registering.";
    $_SESSION["form_data"] = $_POST;
    header("Location: signup.php");
    exit();
}

$valid_barangays = beneficiary_barangay_options();
$valid_civil_statuses = ['Single', 'Married', 'Widowed', 'Legally Separated'];

if ($first_name === "" || $last_name === "" || $birthdate === "" || $sex === "" || 
    $civil_status === "" || $contact_no === "" || $street_purok === "" || 
    $barangay === "" || $email === "" || (!$google_registration && $password === "")) {
    $_SESSION["flash"] = "Please complete all required fields.";
    $_SESSION["form_data"] = $_POST; 
    header("Location: signup.php");
    exit();
}

$identity_errors = validate_beneficiary_identity_input([
    'first_name' => $first_name, 'middle_name' => $middle_name, 'last_name' => $last_name,
    'birthdate' => $birthdate, 'sex' => $sex, 'civil_status' => $civil_status,
    'contact_no' => $contact_no, 'email' => $email, 'barangay' => $barangay,
]);
if ($identity_errors) {
    $_SESSION['flash'] = implode(' ', $identity_errors);
    $_SESSION['form_data'] = $_POST;
    header('Location: signup.php');
    exit();
}

if (!in_array($civil_status, $valid_civil_statuses, true)) {
    $_SESSION["flash"] = "Please select a valid civil status.";
    $_SESSION["form_data"] = $_POST;
    header("Location: signup.php");
    exit();
}

$birth_date_object = DateTimeImmutable::createFromFormat('!Y-m-d', $birthdate, new DateTimeZone('Asia/Manila'));
$birthdate_errors = DateTimeImmutable::getLastErrors();
$valid_birthdate = $birth_date_object instanceof DateTimeImmutable
    && ($birthdate_errors === false || ($birthdate_errors['warning_count'] === 0 && $birthdate_errors['error_count'] === 0))
    && $birth_date_object->format('Y-m-d') === $birthdate;
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
$age = $valid_birthdate ? $birth_date_object->diff($today)->y : -1;

if (!$valid_birthdate || $birth_date_object > $today || $age < 18) {
    $_SESSION['flash'] = 'You must be at least 18 years old to create a BENEPESO account. Please check your birthdate.';
    $_SESSION['show_age_notice'] = true;
    $_SESSION['form_data'] = $_POST;
    header('Location: signup.php');
    exit();
}

$profile_pic_name = "";
$profile_upload = null;
if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {
    $file_ext = strtolower(pathinfo($_FILES["profile_pic"]["name"], PATHINFO_EXTENSION));
    $allowed_exts = ["jpg", "jpeg", "png", "webp"];
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
    $detected_mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['profile_pic']['tmp_name']);

    if ($_FILES['profile_pic']['size'] <= 5 * 1024 * 1024
        && in_array($file_ext, $allowed_exts, true)
        && in_array($detected_mime, $allowed_mimes, true)) {
        $profile_upload = [
            'tmp_name' => $_FILES['profile_pic']['tmp_name'],
            'extension' => $file_ext,
        ];
    } else {
        $_SESSION['flash'] = 'Profile picture must be a valid JPG, PNG, or WebP image up to 5 MB.';
        $_SESSION['form_data'] = $_POST;
        header('Location: signup.php');
        exit();
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

if (!$google_registration && $password !== $confirm_pass) {
    $_SESSION["flash"] = "Passwords do not match.";
    $_SESSION["form_data"] = $_POST; 
    header("Location: signup.php");
    exit();
}

if (!$google_registration && (
    strlen($password) < 10
    || !preg_match('/[a-z]/', $password)
    || !preg_match('/[A-Z]/', $password)
    || !preg_match('/\d/', $password)
    || !preg_match('/[^A-Za-z0-9]/', $password)
)) {
    $_SESSION["flash"] = "Use at least 10 characters with uppercase, lowercase, a number, and a symbol.";
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

// Prevent a second beneficiary account even when different contact details are used.
$check_identity = $conn->prepare(
    "SELECT user_id FROM users
     WHERE LOWER(TRIM(first_name)) = LOWER(TRIM(?))
       AND LOWER(TRIM(last_name)) = LOWER(TRIM(?))
       AND birthdate = ? LIMIT 1"
);
if ($check_identity) {
    $check_identity->bind_param("sss", $first_name, $last_name, $birthdate);
    $check_identity->execute();
    if ($check_identity->get_result()->num_rows > 0) {
        $_SESSION["flash"] = "An account with the same name and birthdate already exists. Please sign in, recover the existing account, or contact PESO Vinzons for assistance.";
        $_SESSION["form_data"] = $_POST;
        $check_identity->close();
        header("Location: signup.php");
        exit();
    }
    $check_identity->close();
}

// Store the image only after all account fields and uniqueness checks have passed.
$uploaded_profile_path = null;
if ($profile_upload !== null) {
    $target_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($target_dir) && !mkdir($target_dir, 0755, true) && !is_dir($target_dir)) {
        $_SESSION['flash'] = 'Profile picture storage is unavailable. Please try again.';
        $_SESSION['form_data'] = $_POST;
        header('Location: signup.php');
        exit();
    }

    $profile_pic_name = 'IMG_' . bin2hex(random_bytes(12)) . '.' . $profile_upload['extension'];
    $uploaded_profile_path = $target_dir . DIRECTORY_SEPARATOR . $profile_pic_name;
    if (!move_uploaded_file($profile_upload['tmp_name'], $uploaded_profile_path)) {
        $_SESSION['flash'] = 'Profile picture could not be saved. Please try again.';
        $_SESSION['form_data'] = $_POST;
        header('Location: signup.php');
        exit();
    }
}

$hash = password_hash($google_registration ? bin2hex(random_bytes(32)) : $password, PASSWORD_DEFAULT);
$municipality = "Vinzons";
$email_db = $email;

$stmt = $conn->prepare("
    INSERT INTO users (
        first_name, middle_name, last_name, ext_name, 
        birthdate, age, sex, civil_status, contact_no, 
        street_purok_zone, barangay, municipality, district, 
        email, profile_pic, password_hash
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    if ($uploaded_profile_path !== null && is_file($uploaded_profile_path)) unlink($uploaded_profile_path);
    error_log('BENEPESO registration prepare failed: ' . $conn->error);
    $_SESSION["flash"] = "Registration is temporarily unavailable. Please try again.";
    $_SESSION["form_data"] = $_POST; 
    header("Location: signup.php");
    exit();
}

$stmt->bind_param("ssssssssssssssss", 
    $first_name, $middle_name, $last_name, $ext_name,
    $birthdate, $age, $sex, $civil_status, $contact_no,
    $street_purok, $barangay, $municipality, $district,
    $email_db, $profile_pic_name, $hash
);

if ($stmt->execute()) {
    $new_user_id = (int)$stmt->insert_id;
    if (!record_privacy_acknowledgment($conn, $new_user_id, 'account_registration')) {
        $conn->query("DELETE FROM users WHERE user_id = " . $new_user_id);
        if ($uploaded_profile_path !== null && is_file($uploaded_profile_path)) unlink($uploaded_profile_path);
        $_SESSION["flash"] = "Registration could not be completed. Please try again.";
        $_SESSION["form_data"] = $_POST;
        header("Location: signup.php");
        exit();
    }

    if ($google_registration && !google_auth_link_user($conn, $new_user_id, $google_identity)) {
        $conn->query("DELETE FROM users WHERE user_id = " . $new_user_id);
        if ($uploaded_profile_path !== null && is_file($uploaded_profile_path)) unlink($uploaded_profile_path);
        $_SESSION["flash"] = "Your profile was valid, but Google sign-in could not be linked safely. Please try again.";
        $_SESSION["form_data"] = $_POST;
        header("Location: signup.php?google=complete");
        exit();
    }

    if ($google_registration) {
        unset($_SESSION['google_pending_identity']);
        google_auth_activate_beneficiary($conn, [
            'user_id' => $new_user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'profile_pic' => $profile_pic_name,
        ]);
        $_SESSION['google_signup_success'] = true;
        header("Location: signup.php?google=success");
        exit();
    }

    $_SESSION["flash"] = "Account created successfully. You may now log in.";
    header("Location: login.php");
    exit();
}

if ($uploaded_profile_path !== null && is_file($uploaded_profile_path)) unlink($uploaded_profile_path);
$_SESSION["flash"] = "System error during registration.";
$_SESSION["form_data"] = $_POST; 
header("Location: signup.php");
exit();
?>
