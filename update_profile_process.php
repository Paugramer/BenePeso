<?php
require_once __DIR__ . '/auth.php';
require "db.php";
require_once __DIR__ . '/beneficiary_choices.php';

check_user_role('user');

function return_to_profile(string $message): void
{
    $_SESSION["flash"] = $message;
    header("Location: profile.php");
    exit();
}

function clean_profile_value($value): string
{
    return trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$value));
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: profile.php");
    exit();
}
auth_require_csrf();

$user_id       = (int)$_SESSION["user_id"];
$first_name    = clean_profile_value($_POST['first_name'] ?? '');
$middle_name   = normalize_optional_middle_name(clean_profile_value($_POST['middle_name'] ?? ''));
$last_name     = clean_profile_value($_POST['last_name'] ?? '');
$ext_name      = normalize_optional_name_part(clean_profile_value($_POST['ext_name'] ?? ''));
$birthdate     = clean_profile_value($_POST['birthdate'] ?? '');
$sex           = clean_profile_value($_POST['sex'] ?? '');
$civil_status  = clean_profile_value($_POST['civil_status'] ?? '');
$contact_no    = clean_profile_value($_POST['contact_no'] ?? '');
$street        = clean_profile_value($_POST['street_purok_zone'] ?? '');
$barangay      = canonical_beneficiary_barangay(clean_profile_value($_POST['barangay'] ?? ''));
$email         = strtolower(clean_profile_value($_POST['email'] ?? ''));

$allowed_sexes = ['Male', 'Female'];
$allowed_civil_statuses = ['Single', 'Married', 'Widowed', 'Legally Separated'];
$allowed_barangays = beneficiary_barangay_options();

if ($first_name === '' || $last_name === '' || $birthdate === '' || $contact_no === '' || $barangay === '' || $email === '') {
    return_to_profile('Update Failed: Required fields cannot be left blank.');
}

$identity_errors = validate_beneficiary_identity_input([
    'first_name' => $first_name, 'middle_name' => $middle_name, 'last_name' => $last_name,
    'birthdate' => $birthdate, 'sex' => $sex, 'civil_status' => $civil_status,
    'contact_no' => $contact_no, 'email' => $email, 'barangay' => $barangay,
]);
if ($identity_errors) return_to_profile('Update Failed: ' . implode(' ', $identity_errors));

if (strlen($first_name) > 50 || strlen($middle_name) > 50 || strlen($last_name) > 50 || strlen($ext_name) > 10 || strlen($street) > 100) {
    return_to_profile('Update Failed: One or more profile fields exceed the allowed length.');
}

if (!in_array($sex, $allowed_sexes, true) || !in_array($civil_status, $allowed_civil_statuses, true) || !in_array($barangay, $allowed_barangays, true)) {
    return_to_profile('Update Failed: One or more selected values are invalid.');
}

if (!preg_match('/^09\d{9}$/', $contact_no)) {
    return_to_profile('Update Failed: Enter an 11-digit contact number beginning with 09.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
    return_to_profile('Update Failed: Enter a valid email address.');
}

$birth_date = DateTime::createFromFormat('!Y-m-d', $birthdate);
$today = new DateTime('today');
if (!$birth_date || $birth_date->format('Y-m-d') !== $birthdate || $birth_date > $today) {
    return_to_profile('Update Failed: Enter a valid date of birth that is not in the future.');
}
$age = $birth_date->diff($today)->y;

$current_stmt = $conn->prepare('SELECT email FROM users WHERE user_id = ? LIMIT 1');
if (!$current_stmt) {
    return_to_profile('System Error: Could not verify your account at this time.');
}
$current_stmt->bind_param('i', $user_id);
$current_stmt->execute();
$current_user = $current_stmt->get_result()->fetch_assoc();
$current_stmt->close();

if (!$current_user) {
    return_to_profile('Update Failed: Your account could not be found.');
}
$old_email = (string)$current_user['email'];

$email_stmt = $conn->prepare('SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1');
if (!$email_stmt) {
    return_to_profile('System Error: Could not verify the email address at this time.');
}
$email_stmt->bind_param('si', $email, $user_id);
$email_stmt->execute();
$email_in_use = $email_stmt->get_result()->num_rows > 0;
$email_stmt->close();

if ($email_in_use) {
    return_to_profile('Update Failed: That email address is already registered to another account.');
}

$contact_stmt = $conn->prepare('SELECT user_id FROM users WHERE contact_no = ? AND user_id <> ? LIMIT 1');
if (!$contact_stmt) {
    return_to_profile('System Error: Could not verify the contact number at this time.');
}
$contact_stmt->bind_param('si', $contact_no, $user_id);
$contact_stmt->execute();
$contact_in_use = $contact_stmt->get_result()->num_rows > 0;
$contact_stmt->close();
if ($contact_in_use) {
    return_to_profile('Update Failed: That contact number is already registered to another account.');
}

$conn->begin_transaction();

try {
    $email_db = $email;
    $update_query = "
        UPDATE users
        SET first_name = ?, middle_name = ?, last_name = ?, ext_name = ?,
            birthdate = ?, age = ?, sex = ?, civil_status = ?, contact_no = ?,
            street_purok_zone = ?, barangay = ?, email = ?
        WHERE user_id = ?
    ";
    $stmt = $conn->prepare($update_query);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare profile update.');
    }
    $stmt->bind_param(
        'sssssissssssi',
        $first_name,
        $middle_name,
        $last_name,
        $ext_name,
        $birthdate,
        $age,
        $sex,
        $civil_status,
        $contact_no,
        $street,
        $barangay,
        $email_db,
        $user_id
    );
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to update profile.');
    }
    $stmt->close();

    if (strcasecmp($old_email, $email) !== 0) {
        $beneficiary_stmt = $conn->prepare('UPDATE beneficiaries SET email = ? WHERE user_id = ?');
        if (!$beneficiary_stmt) {
            throw new RuntimeException('Unable to prepare linked record update.');
        }
        $beneficiary_stmt->bind_param('si', $email_db, $user_id);
        if (!$beneficiary_stmt->execute()) {
            throw new RuntimeException('Unable to update linked records.');
        }
        $beneficiary_stmt->close();
    }

    $actor_name = trim($first_name . ' ' . $last_name);
    $actor_role = 'Registered User';
    $log_stmt = $conn->prepare("INSERT INTO activity_logs (action_type, module_name, description, actor_name, actor_role, created_at) VALUES ('Update', 'Profile', 'User successfully updated their personal profile information.', ?, ?, NOW())");
    if (!$log_stmt) {
        throw new RuntimeException('Unable to prepare activity log.');
    }
    $log_stmt->bind_param('ss', $actor_name, $actor_role);
    if (!$log_stmt->execute()) {
        throw new RuntimeException('Unable to record profile activity.');
    }
    $log_stmt->close();

    $conn->commit();
    return_to_profile('Profile details updated successfully!');
} catch (Throwable $error) {
    $conn->rollback();
    error_log('Profile update failed for user ' . $user_id . ': ' . $error->getMessage());
    return_to_profile('System Error: Could not update your profile at this time.');
}
