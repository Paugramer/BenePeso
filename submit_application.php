<?php
session_start();
require "db.php";

// 1. Check if the user is logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

// 2. Prevent Direct Access (Only allow POST requests)
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: programs.php");
    exit();
}

// 3. Collect and Sanitize Data
$user_id = (int)$_SESSION["user_id"];
$program_id = (int)($_POST['program_id'] ?? 0);

// ID Type Logic (Handle 'Other')
$id_type = trim($_POST['id_type'] ?? '');
if ($id_type === 'Other') {
    $id_type = trim($_POST['id_type_other'] ?? 'Unspecified ID');
}

$id_number = trim($_POST['id_number'] ?? '');

// Occupation Logic (Handle 'Other')
$occupation = trim($_POST['occupation'] ?? '');
if ($occupation === 'Other') {
    $occupation = trim($_POST['occupation_other'] ?? 'Unspecified Occupation');
}

$monthly_income = (float)($_POST['monthly_income'] ?? 0.00);

// Skills Training Logic (Handle 'Other')
$skills_training = trim($_POST['skills_training_needed'] ?? '');
if ($skills_training === 'Other') {
    $skills_training = trim($_POST['skills_training_needed_other'] ?? 'Unspecified Skill');
}

$dependent_name = trim($_POST['dependent_name'] ?? '');

// Dependent Relationship Logic (Handle 'Other')
$dependent_relationship = trim($_POST['dependent_relationship'] ?? '');
if ($dependent_relationship === 'Other') {
    $dependent_relationship = trim($_POST['dependent_relationship_other'] ?? 'Unspecified Relationship');
}

$interested_in_wage = trim($_POST['interested_in_wage_employment'] ?? 'No');

// Basic Validation
if ($program_id === 0 || empty($id_type) || empty($id_number) || empty($occupation)) {
    // Missing required fields
    header("Location: programs.php?status=error&msg=missing_fields");
    exit();
}

// 4. Backend Duplicate Check
// Ensures they cannot submit twice via network manipulation
$check_stmt = $conn->prepare("SELECT application_id FROM applications WHERE user_id = ? AND program_id = ?");
$check_stmt->bind_param("ii", $user_id, $program_id);
$check_stmt->execute();
$check_res = $check_stmt->get_result();

if ($check_res->num_rows > 0) {
    header("Location: programs.php?status=error&msg=already_applied");
    exit();
}
$check_stmt->close();

// 5. Execute Secure Database Insertion
$insert_query = "INSERT INTO applications 
    (user_id, program_id, id_type, id_number, occupation, monthly_income, skills_training_needed, interested_in_wage_employment, dependent_name, dependent_relationship, application_status, date_applied) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())";

$stmt = $conn->prepare($insert_query);
$stmt->bind_param(
    "iisssdssss", 
    $user_id, 
    $program_id, 
    $id_type, 
    $id_number, 
    $occupation, 
    $monthly_income, 
    $skills_training, 
    $interested_in_wage, 
    $dependent_name, 
    $dependent_relationship
);

if ($stmt->execute()) {
    // Success: Redirect back with a success flag
    header("Location: programs.php?status=success");
} else {
    // System Error
    header("Location: programs.php?status=error&msg=system_error");
}

$stmt->close();
$conn->close();
exit();
?>