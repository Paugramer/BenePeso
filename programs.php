<?php
session_start();
require "db.php";
require_once "program_eligibility_helper.php";
require_once "tupad_category_helper.php";
require_once "beneficiary_choices.php";
require_once "privacy_helper.php";
require_once "tupad_household_helper.php";
ensure_program_eligibility_schema($conn);
ensure_tupad_category_schema($conn);

if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit(); }

function h($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }

$user_id = (int)$_SESSION["user_id"];
$user_display_name = "User";
$first_char = "U";
$is_logged_in = true;

// FETCH USER DATA FOR AUTOFILL
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id=? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$user_data = [];

if ($res && $res->num_rows === 1) {
    $user_data = $res->fetch_assoc();
    $fn = trim($user_data["first_name"] ?? "");
    $mn = trim($user_data["middle_name"] ?? "");
    $ln = trim($user_data["last_name"] ?? "");
    $ex = trim($user_data["ext_name"] ?? "");
    $full_name = trim($fn . ($mn ? " " . substr($mn, 0, 1) . "." : "") . " " . $ln . ($ex ? " " . $ex : ""));
    if (!empty($full_name)) $user_display_name = $full_name;
    if (!empty($fn)) $first_char = strtoupper(substr($fn, 0, 1));
}
$stmt->close();

$birthdate = $user_data['birthdate'] ?? null;
$userAge = 0;
if (!empty($birthdate)) {
    $dob = new DateTime($birthdate);
    $now = new DateTime();
    $userAge = $now->diff($dob)->y;
}

// CONSTRUCT SMART FULL ADDRESS
$street_purok = trim($user_data['street_purok_zone'] ?? '');
$barangay = trim($user_data['barangay'] ?? '');
$combined_street_brgy = trim("$street_purok $barangay");
$full_address = $combined_street_brgy ? "$combined_street_brgy, Vinzons, Camarines Norte" : "Vinzons, Camarines Norte";

// ==========================================
// AJAX HANDLER FOR CLICK LOGGING
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'log_view') {
    header('Content-Type: application/json');
    $prog_name = $_GET['prog_name'] ?? 'a program';
    $log_type = $_GET['type'] ?? 'Viewed';
    
    $desc = ($log_type === 'status') ? "Opened status details for $prog_name." : "Viewed details for $prog_name.";
    $mod = "Programs";
    
    $log_stmt = $conn->prepare("INSERT INTO activity_logs (actor_name, actor_role, module_name, action_type, target_name, description, created_at) VALUES (?, 'Registered User', ?, 'VIEW', ?, ?, NOW())");
    $log_stmt->bind_param("ssss", $user_display_name, $mod, $prog_name, $desc);
    $log_stmt->execute();
    echo json_encode(['status' => 'success']);
    exit();
}

// SMART ELIGIBILITY CHECKER (AJAX)
if (isset($_GET['action']) && $_GET['action'] === 'check_eligibility') {
    header('Content-Type: application/json');
    $check_program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;

    $configuredEligibility = evaluate_program_eligibility($conn, $user_id, $check_program_id);
    if (!$configuredEligibility['eligible']) {
        echo json_encode($configuredEligibility); exit();
    }

    // Get Base Program Name (e.g. extracts "TUPAD" from "TUPAD 2026 Batch 1")
    $pStmt = $conn->prepare("SELECT program_name FROM programs WHERE program_id = ?");
    $pStmt->bind_param("i", $check_program_id);
    $pStmt->execute();
    $pRes = $pStmt->get_result()->fetch_assoc();
    $pStmt->close();
    
    $prog_name_check = $pRes ? $pRes['program_name'] : '';
    $base_prog_name = explode(' ', trim($prog_name_check))[0];

    // RULE 1: Global Block - User cannot apply if they have ANY active or pending program.
    $activeAppStmt = $conn->prepare("
        SELECT p.program_name, b.availment_status 
        FROM beneficiaries b 
        JOIN programs p ON b.program_id = p.program_id 
        WHERE b.user_id = ? 
        AND (b.approval_status = 'Pending' 
             OR (b.approval_status = 'Approved' AND b.availment_status IN ('Ongoing', 'Not Yet Availed', 'Requirements Received'))) 
        ORDER BY b.created_at DESC LIMIT 1
    ");
    $activeAppStmt->bind_param("i", $user_id);
    $activeAppStmt->execute();
    $activeApp = $activeAppStmt->get_result()->fetch_assoc();
    $activeAppStmt->close();

    if ($activeApp) {
        echo json_encode(['eligible' => false, 'message' => "You currently have an active or pending application for " . $activeApp['program_name'] . ". You cannot apply for another program until it is completed."]); exit();
    }

    // RULE 2: Cooldown Block - 20 months for the same program category across different batches.
    $twentyMonthsAgo = date('Y-m-d', strtotime('-20 months'));
    $searchBase = $base_prog_name . '%';
    $cooldownStmt = $conn->prepare("
        SELECT b.date_completed, b.date_availed 
        FROM beneficiaries b 
        JOIN programs p ON b.program_id = p.program_id 
        WHERE b.user_id = ? 
        AND p.program_name LIKE ? 
        AND b.approval_status = 'Approved' 
        AND b.availment_status = 'Completed' 
        ORDER BY b.created_at DESC LIMIT 1
    ");
    $cooldownStmt->bind_param("is", $user_id, $searchBase);
    $cooldownStmt->execute();
    $lastAvail = $cooldownStmt->get_result()->fetch_assoc();
    $cooldownStmt->close();

    if ($lastAvail) {
        $compareDate = !empty($lastAvail['date_completed']) ? $lastAvail['date_completed'] : (!empty($lastAvail['date_availed']) ? $lastAvail['date_availed'] : null);
        if ($compareDate && $compareDate > $twentyMonthsAgo) {
            echo json_encode(['eligible' => false, 'message' => "You must wait 1 year and 8 months after completing a $base_prog_name program before applying for a new batch."]); exit();
        }
    }

    // FAMILY CHECK BYPASSED: 
    // Allowing siblings/family to apply via the automated checker to prevent false disqualifications.
    // Approvals for duplicate households will be handled by the Admin dashboard manually.
    
    echo json_encode(['eligible' => true]); exit();
}

// HANDLE BULLETPROOF FORM SUBMISSION
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'submit_application') {
    if (!isset($_POST['privacy_acknowledgment']) || $_POST['privacy_acknowledgment'] !== '1') {
        $_SESSION['app_error'] = 'Please read and acknowledge the Privacy Notice before submitting your application.';
        header('Location: programs.php');
        exit();
    }
    $submittedProgramId = (int)($_POST['program_id'] ?? 0);
    $configuredEligibility = evaluate_program_eligibility($conn, $user_id, $submittedProgramId);
    if (!$configuredEligibility['eligible']) {
        $_SESSION['app_error'] = $configuredEligibility['message'];
        header('Location: programs.php'); exit();
    }
    
    $first_name = trim($user_data['first_name'] ?? '');
    $middle_name = trim($user_data['middle_name'] ?? '');
    $last_name = trim($user_data['last_name'] ?? '');
    $ext_name = trim($user_data['ext_name'] ?? '');
    $full_name_insert = trim("$first_name $middle_name $last_name $ext_name");
    $owner_full_name = trim($_POST['owner_full_name'] ?? '');
    if ($owner_full_name !== '') $full_name_insert = $owner_full_name;
    $submitted_birthdate = trim($_POST['owner_birthdate'] ?? ($user_data['birthdate'] ?? ''));
    $submitted_age = isset($_POST['owner_age']) ? (int)$_POST['owner_age'] : $userAge;
    $submitted_contact = trim($_POST['owner_contact_no'] ?? ($user_data['contact_no'] ?? ''));
    $submitted_address = trim($_POST['owner_full_address'] ?? '');

    function processArrayField($post_key) {
        return isset($_POST[$post_key]) && is_array($_POST[$post_key]) ? implode(', ', array_map('trim', $_POST[$post_key])) : trim($_POST[$post_key] ?? "");
    }

    $type_of_id = trim($_POST["type_of_id"] ?? "");
    if ($type_of_id === 'Others') $type_of_id = trim($_POST["other_type_of_id"] ?? "Others");
    
    $occupation = choice_or_other($_POST, 'occupation');
    $type_of_beneficiary = choice_or_other($_POST, 'type_of_beneficiary');
    $dependent_relationship = choice_or_other($_POST, 'dependent_relationship');
    $skills_training_needed = choice_or_other($_POST, 'skills_training_needed');
    $ownership_type = choice_or_other($_POST, 'ownership_type');
    
    $father_occupation = choice_or_other($_POST, 'father_occupation');
    
    $mother_occupation = choice_or_other($_POST, 'mother_occupation');

    $sec_degree = trim($_POST["sec_degree"] ?? "");
    if ($sec_degree === 'Others') $sec_degree = trim($_POST["other_sec_degree"] ?? "Others");

    $tert_course = trim($_POST["tert_course"] ?? "");
    if ($tert_course === 'Others') $tert_course = trim($_POST["other_tert_course"] ?? "Others");

    $tv_course = trim($_POST["tv_course"] ?? "");
    if ($tv_course === 'Others') $tv_course = trim($_POST["other_tv_course"] ?? "Others");

    $educational_attainment = trim($_POST["educational_attainment"] ?? "");
    if ($educational_attainment === 'Others') $educational_attainment = trim($_POST["other_educational_attainment"] ?? "Others");

    $form_sex = trim($_POST['owner_sex'] ?? $user_data['sex'] ?? '');
    $form_civil = trim($_POST['owner_civil_status'] ?? $user_data['civil_status'] ?? '');
    if ($form_civil === 'Widow/er') $form_civil = 'Widowed';
    if ($form_civil === 'Separated') $form_civil = 'Legally Separated';

    $msme_nature = processArrayField('business_nature_arr');
    if (strpos($msme_nature, 'Others') !== false && !empty($_POST['other_business_nature'])) {
        $msme_nature = str_replace('Others', trim($_POST['other_business_nature']), $msme_nature);
    }
    
    $msme_product_names = [];
    $msme_product_prices = [];
    foreach (($_POST['prod_name'] ?? []) as $index => $productName) {
        $productName = trim((string)$productName);
        if ($productName === '') continue;
        $msme_product_names[] = $productName;
        $msme_product_prices[] = trim((string)($_POST['prod_price'][$index] ?? ''));
    }
    $msme_products = implode(', ', $msme_product_names);
    $msme_prices = implode(', ', $msme_product_prices);
    $spes_history = isset($_POST['spes_hist_avail']) ? json_encode(array_map(null, $_POST['spes_hist_avail'], $_POST['spes_hist_est'], $_POST['spes_hist_year'], $_POST['spes_hist_id'])) : "";
    
    $spes_parents_status = processArrayField('spes_parent_status');
    $msme_assets = processArrayField('assets_owned');
    $msme_utilities = processArrayField('utility_needs');
    $msme_capital_src = processArrayField('source_of_capital');
    $msme_payment_mode = processArrayField('mode_of_payment');
    $msme_dist_channels = processArrayField('distribution_channels');
    $msme_assist_availed = processArrayField('assistance_availed');
    $msme_past_programs = processArrayField('past_programs');
    $msme_progs_needed = processArrayField('programs_needed');
    $msme_challenges = processArrayField('challenges_encountered');

    $fieldsToInsert = [
        "user_id" => $user_id,
        "program_id" => $submittedProgramId,
        "full_name" => $full_name_insert,
        "first_name" => $first_name,
        "middle_name" => $middle_name,
        "last_name" => $last_name,
        "ext_name" => $ext_name,
        "birthdate" => $submitted_birthdate,
        "age" => $submitted_age,
        "sex" => $form_sex,
        "civil_status" => $form_civil,
        "contact_no" => $submitted_contact,
        "email" => trim($user_data['email'] ?? ''),
        "street_purok_zone" => trim($user_data['street_purok_zone'] ?? ''),
        "address" => $submitted_address,
        "barangay" => trim($user_data['barangay'] ?? ''),
        "municipality" => trim($user_data['municipality'] ?? 'Vinzons'),
        "district" => trim($user_data['district'] ?? 'Camarines Norte'),
        "status" => "Active",
        "availment_status" => "Not Yet Availed",
        "approval_status" => "Pending",

        // TUPAD Fields
        "type_of_id" => $type_of_id,
        "id_number" => trim($_POST["id_number"] ?? ""),
        "type_of_beneficiary" => $type_of_beneficiary,
        "occupation" => $occupation,
        "avg_monthly_income" => trim($_POST["spes_avg_monthly_income"] ?? $_POST["avg_monthly_income"] ?? ""),
        "dependent_name" => trim($_POST["dependent_name"] ?? ""),
        "dependent_relationship" => $dependent_relationship,
        "interested_in_employment" => trim($_POST["interested_in_employment"] ?? "No"),
        "skills_training_needed" => $skills_training_needed,

        // SPES Fields
        "spes_type" => trim($_POST["spes_type"] ?? ""),
        "gsis_beneficiary_name" => trim($_POST["gsis_beneficiary"] ?? ""),
        "gsis_relationship" => trim($_POST["gsis_relationship"] ?? ""),
        "place_of_birth" => trim($_POST["place_of_birth"] ?? ""),
        "citizenship" => trim($_POST["citizenship"] ?? ""),
        "social_media" => trim($_POST["social_urls"] ?? ""),
        "parents_status" => $spes_parents_status,
        "permanent_address" => trim($_POST["permanent_address"] ?? ""),
        "father_name" => trim($_POST["father_name"] ?? ""),
        "father_contact" => trim($_POST["father_contact"] ?? ""),
        "father_occupation" => $father_occupation,
        "mother_name" => trim($_POST["mother_name"] ?? ""),
        "mother_contact" => trim($_POST["mother_contact"] ?? ""),
        "mother_occupation" => $mother_occupation,
        "elem_school" => trim($_POST["elem_school"] ?? ""),
        "elem_degree" => trim($_POST["elem_degree"] ?? ""),
        "elem_year_level" => trim($_POST["elem_year_level"] ?? ""),
        "elem_date_attendance" => trim($_POST["elem_date_attendance"] ?? ""),
        "sec_school" => trim($_POST["sec_school"] ?? ""),
        "sec_degree" => $sec_degree,
        "sec_year_level" => trim($_POST["sec_year_level"] ?? ""),
        "sec_date_attendance" => trim($_POST["sec_date_attendance"] ?? ""),
        "tert_school" => trim($_POST["tert_school"] ?? ""),
        "tert_course" => $tert_course,
        "tert_year_level" => trim($_POST["tert_year_level"] ?? ""),
        "tert_date_attendance" => trim($_POST["tert_date_attendance"] ?? ""),
        "tv_school" => trim($_POST["tv_school"] ?? ""),
        "tv_course" => $tv_course,
        "tv_year_level" => trim($_POST["tv_year_level"] ?? ""),
        "tv_date_attendance" => trim($_POST["tv_date_attendance"] ?? ""),
        "special_skills" => trim($_POST["special_skills"] ?? ""),
        "spes_history" => $spes_history, 
        "spes_other_info" => trim($_POST["spes_other_info"] ?? ""),

        // MSME Fields
        "business_name" => trim($_POST["business_name"] ?? ""),
        "ownership_type" => $ownership_type,
        "business_nature" => $msme_nature,
        "primary_products" => $msme_products,
        "product_price" => $msme_prices,
        "year_started" => trim($_POST["year_started"] ?? ""),
        "business_permit_no" => trim($_POST["business_permit_no"] ?? ""),
        "permit_validity" => trim($_POST["permit_valid_until"] ?? ""),
        "dti_no" => trim($_POST["dti_no"] ?? ""),
        "tin_no" => trim($_POST["tin_no"] ?? ""),
        "educational_attainment" => $educational_attainment,
        "work_experience" => trim($_POST["work_experience"] ?? ""),
        "business_email" => trim($_POST["contact_details"] ?? ""), 
        "business_social_media" => trim($_POST["business_social_media"] ?? ""),
        "assets_owned" => $msme_assets,
        "utility_needs" => $msme_utilities,
        "hr_male" => (int)($_POST["hr_male"] ?? 0),
        "hr_female" => (int)($_POST["hr_female"] ?? 0),
        "hr_total" => (int)($_POST["hr_total"] ?? 0),
        "emp_regular" => (int)($_POST["emp_regular"] ?? 0),
        "emp_seasonal" => (int)($_POST["emp_seasonal"] ?? 0),
        "emp_contractual" => (int)($_POST["emp_contractual"] ?? 0),
        "emp_family" => (int)($_POST["emp_family"] ?? 0),
        "hr_skills" => trim($_POST["hr_skills"] ?? ""),
        "source_of_capital" => $msme_capital_src,
        "business_size" => trim($_POST["business_size"] ?? ""),
        "initial_capital" => !empty($_POST["initial_capital"]) ? (float)$_POST["initial_capital"] : null,
        "current_capital" => !empty($_POST["current_capital"]) ? (float)$_POST["current_capital"] : null,
        "daily_earnings" => !empty($_POST["daily_earnings"]) ? (float)$_POST["daily_earnings"] : null,
        "mode_of_payment" => $msme_payment_mode,
        "distribution_channels" => $msme_dist_channels,
        "availed_before" => trim($_POST["availed_before"] ?? ""),
        "assistance_availed" => $msme_assist_availed,
        "past_programs" => $msme_past_programs,
        "programs_needed" => $msme_progs_needed,
        "challenges_encountered" => $msme_challenges
    ];

    $tupadHouseholdCheck = check_tupad_household_conflict(
        $conn,
        $submittedProgramId,
        $full_name_insert,
        (string)$fieldsToInsert['dependent_name']
    );
    if (!$tupadHouseholdCheck['eligible']) {
        $_SESSION['app_error'] = $tupadHouseholdCheck['message'];
        $_SESSION['app_error_type'] = 'tupad_household';
        header('Location: programs.php');
        exit();
    }

    $columns = implode(", ", array_keys($fieldsToInsert));
    $placeholders = implode(", ", array_fill(0, count($fieldsToInsert), "?"));
    $values = array_values($fieldsToInsert);

    $types = '';
    foreach($values as $val) {
        if (is_int($val)) $types .= 'i';
        elseif (is_float($val)) $types .= 'd';
        else $types .= 's';
    }

    $sql = "INSERT INTO beneficiaries ($columns, created_at, updated_at) VALUES ($placeholders, NOW(), NOW())";
    $stmt = $conn->prepare($sql);
    
    if($stmt) {
        $stmt->bind_param($types, ...$values);
        if ($stmt->execute()) {
            $new_beneficiary_id = (int)$stmt->insert_id;
            if (!record_privacy_acknowledgment($conn, (int)$user_id, 'program_application', $new_beneficiary_id)) {
                $deleteStmt = $conn->prepare('DELETE FROM beneficiaries WHERE beneficiary_id = ? AND user_id = ?');
                if ($deleteStmt) {
                    $deleteStmt->bind_param('ii', $new_beneficiary_id, $user_id);
                    $deleteStmt->execute();
                    $deleteStmt->close();
                }
                $_SESSION['app_error'] = 'Your application could not be recorded. Please try again.';
                $stmt->close();
                header('Location: programs.php');
                exit();
            }
            $queue_position = null;

            $queueStmt = $conn->prepare("
                SELECT COUNT(*) AS queue_position
                FROM beneficiaries b
                JOIN beneficiaries current_b ON current_b.beneficiary_id = ?
                WHERE b.program_id = ?
                  AND b.approval_status = 'Pending'
                  AND (
                    b.created_at < current_b.created_at
                    OR (b.created_at = current_b.created_at AND b.beneficiary_id <= current_b.beneficiary_id)
                  )
            ");
            if ($queueStmt) {
                $submitted_program_id = (int)$_POST['program_id'];
                $queueStmt->bind_param("ii", $new_beneficiary_id, $submitted_program_id);
                $queueStmt->execute();
                $queueRow = $queueStmt->get_result()->fetch_assoc();
                $queue_position = isset($queueRow['queue_position']) ? (int)$queueRow['queue_position'] : null;
                $queueStmt->close();
            }

            $_SESSION["app_success"] = "Your application has been successfully submitted! It is now pending for approval.";
            if ($queue_position !== null && $queue_position > 0) {
                $_SESSION["app_success"] .= " You are currently number {$queue_position} in this batch's application queue. Qualified applicants are reviewed in submission order until all slots are filled.";
            }
            
            $p_id = (int)$_POST['program_id'];
            $p_name = "Program";
            $pn_stmt = $conn->prepare("SELECT program_name FROM programs WHERE program_id = ?");
            $pn_stmt->bind_param("i", $p_id);
            $pn_stmt->execute();
            $pn_res = $pn_stmt->get_result()->fetch_assoc();
            if($pn_res) $p_name = $pn_res['program_name'];

            $log_desc = "Successfully applied for " . $p_name . ".";
            $l_stmt = $conn->prepare("INSERT INTO activity_logs (actor_name, actor_role, module_name, action_type, target_name, description, created_at) VALUES (?, 'Registered User', 'Programs', 'APPLY', ?, ?, NOW())");
            $l_stmt->bind_param("sss", $user_display_name, $p_name, $log_desc);
            $l_stmt->execute();

        } else {
            $_SESSION["app_error"] = "Error saving application. Please try again.";
        }
        $stmt->close();
    } else {
        $_SESSION["app_error"] = "Database configuration error. Please contact the administrator.";
    }
    header("Location: programs.php");
    exit();
}

$show_success_modal = false;
$success_message = "";
if (isset($_SESSION["app_success"])) {
    $show_success_modal = true;
    $success_message = $_SESSION["app_success"];
    unset($_SESSION["app_success"]);
}

$show_error_modal = false;
$error_message = "";
$error_type = "";
if (isset($_SESSION["app_error"])) {
    $show_error_modal = true;
    $error_message = $_SESSION["app_error"];
    $error_type = (string)($_SESSION["app_error_type"] ?? "");
    unset($_SESSION["app_error"]);
    unset($_SESSION["app_error_type"]);
}

function program_has_ended($end_date): bool {
    if (empty($end_date)) {
        return false;
    }

    try {
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
        $program_end = new DateTimeImmutable((string)$end_date, new DateTimeZone('Asia/Manila'));
        return $program_end < $today;
    } catch (Exception $e) {
        return false;
    }
}

// FETCH PROGRAMS AND SEPARATE ACTIVE FROM COMPLETED
$sql = "SELECT p.*, 
        (p.slots - (SELECT COUNT(*) FROM beneficiaries b2 WHERE b2.program_id = p.program_id AND b2.approval_status = 'Approved')) AS remaining_slots,
        (SELECT COUNT(*) FROM beneficiaries b_served WHERE b_served.program_id = p.program_id AND b_served.approval_status = 'Approved') AS total_served,
        (SELECT approval_status FROM beneficiaries b3 WHERE b3.program_id = p.program_id AND b3.user_id = ? ORDER BY created_at DESC LIMIT 1) AS user_approval_status,
        (SELECT approval_note FROM beneficiaries b4 WHERE b4.program_id = p.program_id AND b4.user_id = ? ORDER BY created_at DESC LIMIT 1) AS user_approval_note,
        (SELECT availment_status FROM beneficiaries b5 WHERE b5.program_id = p.program_id AND b5.user_id = ? ORDER BY created_at DESC LIMIT 1) AS user_availment_status
        FROM programs p 
        WHERE p.approval_status = 'Approved' 
        ORDER BY p.created_at DESC";

$stmt_prog = $conn->prepare($sql);
$stmt_prog->bind_param("iii", $user_id, $user_id, $user_id);
$stmt_prog->execute();
$result = $stmt_prog->get_result();

$active_programs = [];
$completed_programs = [];

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $remaining_slots = max(0, (int)$row['remaining_slots']);
        $is_full = ($remaining_slots <= 0 || strtolower($row['status']) === 'completed');
        $has_ended = program_has_ended($row['end_date'] ?? null);
        $has_invalid_schedule = !empty($row['start_date']) && !empty($row['end_date'])
            && $row['end_date'] < $row['start_date'];
        
        if ($is_full || $has_ended || $has_invalid_schedule) {
            $completed_programs[] = $row;
        } else {
            $active_programs[] = $row;
        }
    }
}
$availableTupadCategories = [];
foreach ($active_programs as $programRow) {
    if (stripos((string)($programRow['program_name'] ?? ''), 'TUPAD') !== false) {
        $category = trim((string)($programRow['tupad_category'] ?? '')) ?: 'Regular TUPAD';
        $availableTupadCategories[$category] = $category;
    }
}
ksort($availableTupadCategories, SORT_NATURAL | SORT_FLAG_CASE);

// Public archive summaries use aggregate counts only; no personal data is exposed.
$program_barangay_counts = [];
$barangay_summary_sql = "SELECT program_id,
        COALESCE(NULLIF(TRIM(barangay), ''), 'Not specified') AS barangay_name,
        COUNT(*) AS beneficiary_count
    FROM beneficiaries
    WHERE approval_status = 'Approved'
    GROUP BY program_id, barangay_name
    ORDER BY program_id, beneficiary_count DESC, barangay_name ASC";
$barangay_summary_result = $conn->query($barangay_summary_sql);
if ($barangay_summary_result) {
    while ($summary_row = $barangay_summary_result->fetch_assoc()) {
        $summary_program_id = (int)$summary_row['program_id'];
        $program_barangay_counts[$summary_program_id][] = [
            'name' => (string)$summary_row['barangay_name'],
            'count' => (int)$summary_row['beneficiary_count'],
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/pesologo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BENEPESO | Available Programs</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="home.css?v=10">
    <link rel="stylesheet" href="programs.css?v=21">
    <link rel="stylesheet" href="frontend_polish.css?v=1">
    <script src="frontend_polish.js?v=1" defer></script>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <a class="brand-area" href="home.php">
      <img class="brand-logo" src="img/pesologo.png" alt="PESO Logo" onerror="this.style.display='none'">
      <div class="brand-name">
        <div class="brand-title">BENEPESO</div>
        <div class="brand-subtitle">PESO Vinzons</div>
      </div>
    </a>

    <button class="menu-button" id="menuButton" type="button" aria-label="Toggle menu">
      <span></span><span></span><span></span>
    </button>

    <nav class="menu-area" id="menuArea">
      <a class="menu-item" href="home.php">Home</a>
      <a class="menu-item active" href="programs.php">Programs</a>
      <a class="menu-item" href="about.php">About</a>

      <?php if($is_logged_in): ?>
      <div class="account-area" id="accountWrap">
        <button class="account-button" id="accountButton" type="button">
          <span class="account-icon"><?php echo htmlspecialchars($first_char); ?></span>
          <span class="account-text"><?php echo htmlspecialchars($user_display_name); ?></span>
          <span class="account-arrow">▾</span>
        </button>

        <div class="account-dropdown" id="accountDropdown">
          <a href="profile.php">My Profile</a>
          <a href="verification.php">Verification</a>
          <div class="dropdown-line"></div>
          <a class="logout-link" href="logout.php?role=user">Logout</a>
        </div>
      </div>
      <?php else: ?>
        <a class="btn-login" href="login.php" style="margin-left: 10px;">Login</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="page-wrap">
    <section class="welcome-area">
        <div class="welcome-inner content-wrap">
            <div class="welcome-left">
                <div class="welcome-badge"><span class="badge-dot"></span>OPPORTUNITIES AWAIT</div>
                <h1 class="welcome-title">Community <span class="welcome-highlight">Programs</span></h1>
                <p class="welcome-text">Browse and apply for available programs designed to support your growth, skills, and employment journey in Vinzons.</p>
            </div>
        </div>
    </section>

    <!-- ACTIVE PROGRAMS SECTION -->
    <section class="program-area">
        <div class="content-wrap">
            <div class="area-head">
                <div class="head-text">
                    <h2 class="area-title">Active Opportunities</h2>
                    <p class="area-sub">Click on a card to see program details and submit your application.</p>
                </div>
                <div class="search-container">
                    <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="text" id="searchInput" placeholder="Search for active programs (e.g., TUPAD, SPES)..." onkeyup="filterPrograms()">
                </div>
                <?php if ($availableTupadCategories): ?>
                <select id="tupadCategoryFilter" onchange="filterPrograms()" aria-label="Filter programs by TUPAD category" style="min-height:48px;padding:0 14px;border:1px solid #d7e6de;border-radius:12px;background:#fff;color:#173728;font:600 13px Poppins,sans-serif;">
                    <option value="all">All TUPAD Categories</option>
                    <?php foreach ($availableTupadCategories as $category): ?><option value="<?= h(strtolower($category)) ?>"><?= h($category) ?></option><?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>

            <div class="program-grid-container">
                <div class="program-grid" id="programGrid">
                    <?php 
                    if (count($active_programs) > 0): 
                        foreach($active_programs as $index => $row): 
                            $delay = $index * 0.1;
                            $remaining_slots = max(0, (int)$row['remaining_slots']);
                            
                            $user_status = $row['user_approval_status']; 
                            $user_availment = $row['user_availment_status'];

                            $safe_title = h($row['program_name'] ?? $row['title'] ?? '');
                            $safe_desc = h(trim(preg_replace('/\s+/', ' ', strip_tags($row['description'] ?? ''))));
                            $safe_start = !empty($row['start_date']) ? date("M d, Y", strtotime($row['start_date'])) : 'TBA';
                            $safe_end = !empty($row['end_date']) ? date("M d, Y", strtotime($row['end_date'])) : 'TBA';
                            $safe_batch = h($row['program_code'] ?? 'N/A');
                            $tupadCategory = stripos((string)($row['program_name'] ?? ''), 'TUPAD') !== false ? (trim((string)($row['tupad_category'] ?? '')) ?: 'Regular TUPAD') : '';
                            
                            $safe_reason = h($row['user_approval_note'] ?? 'No specific reason provided.');
                            $safe_reqs = h($row['requirements'] ?? 'Please visit the main office for document requirements.');
                            $eligibilityParts = [];
                            $eligibleSex = $row['eligible_sex'] ?? 'Any';
                            $eligibilityParts[] = $eligibleSex === 'Any' ? 'Any sex' : $eligibleSex . ' only';
                            $minimumAge = (int)($row['minimum_age'] ?? 18);
                            $maximumAge = $row['maximum_age'] === null ? null : (int)$row['maximum_age'];
                            $eligibilityParts[] = $maximumAge === null ? "Age {$minimumAge} and above" : "Ages {$minimumAge}–{$maximumAge}";
                            if (!empty($row['one_per_household'])) $eligibilityParts[] = 'One active beneficiary per household';
                            $safe_eligibility = h(implode(' • ', $eligibilityParts));
                            $safe_venue = h($row['venue'] ?? 'PESO Main Office');

                            $action_type = $user_status ? 'status' : 'apply';
                            $badge_class = ($remaining_slots <= 5) ? 'slots-badge warning' : 'slots-badge';
                            $badge_text = $remaining_slots . ' Slots';
                    ?>
                        <article class="program-card" 
                                 style="animation-delay: <?= $delay ?>s;"
                                 data-action="<?= $action_type ?>"
                                 data-prog-id="<?= $row['program_id'] ?>"
                                 data-title="<?= $safe_title ?>"
                                 data-category="<?= h(strtolower($tupadCategory)) ?>"
                                 data-batch="<?= $safe_batch ?>"
                                 data-desc="<?= $safe_desc ?>"
                                 data-start="<?= $safe_start ?>"
                                 data-end="<?= $safe_end ?>"
                                 data-slots="<?= $remaining_slots ?>"
                                 data-status="<?= htmlspecialchars(strtolower($user_status ?? '')) ?>"
                                 data-availment="<?= htmlspecialchars(strtolower($user_availment ?? '')) ?>"
                                 data-reason="<?= $safe_reason ?>"
                                 data-reqs="<?= $safe_reqs ?>"
                                 data-eligibility="<?= $safe_eligibility ?>"
                                 data-venue="<?= $safe_venue ?>"
                                 onclick="openProgramDetails(this)">
                                 
                            <div class="card-img-wrap">
                                <span class="<?= $badge_class ?> floating-badge">
                                    <span class="pulse-dot"></span> <?= $badge_text ?>
                                </span>
                                <img src="<?= h($row['image_path'] ?: 'img/pesologo.png') ?>" onerror="this.onerror=null;this.src='img/pesologo.png'" alt="<?= $safe_title ?>" loading="lazy" decoding="async">
                            </div>
                            
                            <div class="card-body">
                                <h3 class="card-title"><?= $safe_title ?></h3>
                                <div class="batch-code">BATCH: <?= $safe_batch ?></div>
                                <?php if ($tupadCategory !== ''): ?><div class="program-category-badge"><?= h($tupadCategory) ?></div><?php endif; ?>
                                <p class="card-desc"><?= mb_strimwidth($safe_desc, 0, 110, "...") ?></p>
                                
                                <div class="card-footer-info">
                                    <span class="date">Start: <?= $safe_start ?></span>
                                    
                                    <?php if ($user_status): ?>
                                        <button type="button" class="btn-check-status">View Status</button>
                                    <?php else: ?>
                                        <button type="button" class="program-btn">View Details</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php 
                        endforeach; 
                    ?>
                    <?php else: ?>
                        <div style="grid-column: 1/-1; text-align:center; padding:40px; background:#fff; border-radius:20px; box-shadow: var(--shadow-soft);">
                            <p style="font-weight:600; color:var(--text-muted);">No active programs available at the moment. Please check back later!</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div id="noSearchMatch" style="display:none; grid-column: 1/-1; text-align:center; padding:50px; background:#fff; border-radius:20px; box-shadow: var(--shadow-soft); margin-top:20px;">
                    <div style="color:#a0b0a6; display:flex; justify-content:center; margin-bottom:15px;">
                        <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </div>
                    <h3 style="color:var(--green-dark); font-weight:800; margin-bottom:5px;">No active programs match your search</h3>
                    <p style="color:var(--text-muted); font-size:14.5px;">Try using different keywords like "TUPAD" or "SPES".</p>
                </div>
            </div>
        </div>
    </section>

    <!-- COMPLETED PROGRAMS SECTION (ARCHIVE) -->
    <section class="completed-area">
        <div class="content-wrap">
            <div class="area-head" style="margin-bottom: 25px; border-top: 1px solid var(--border-light); padding-top: 40px;">
                <h2 class="area-title" style="font-size: 20px;">Past / Completed Programs</h2>
                <p class="area-sub">Archives of successfully concluded batches and programs.</p>
            </div>
            
            <div class="completed-list">
                <?php if (count($completed_programs) > 0): 
                    foreach($completed_programs as $row): 
                        $safe_title = h($row['program_name'] ?? $row['title'] ?? '');
                        $safe_batch = h($row['program_code'] ?? 'N/A');
                        $safe_end = !empty($row['end_date']) ? date("F Y", strtotime($row['end_date'])) : 'TBA';
                        $served_count = (int)($row['total_served'] ?? 0);
                        $barangay_breakdown = $program_barangay_counts[(int)$row['program_id']] ?? [];
                        $barangay_breakdown_json = json_encode($barangay_breakdown, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                ?>
                    <div class="completed-item" 
                         data-title="<?= $safe_title ?>" 
                         data-batch="<?= $safe_batch ?>" 
                         data-served="<?= $served_count ?>" 
                         data-end="<?= $safe_end ?>"
                         data-barangays="<?= h($barangay_breakdown_json ?: '[]') ?>"
                         onclick="openArchiveDetails(this)">
                        <div class="comp-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                        </div>
                        <div class="comp-info">
                            <h4 class="comp-title"><?= $safe_title ?></h4>
                            <span class="comp-meta">Batch: <?= $safe_batch ?> &bull; Concluded: <?= $safe_end ?></span>
                        </div>
                        <div class="comp-status">View Summary</div>
                    </div>
                <?php 
                    endforeach;
                else: 
                ?>
                    <div style="text-align:center; padding:20px; background:#fff; border-radius:12px; color:var(--text-muted); font-size: 13.5px; border: 1px solid var(--border-light);">
                        No completed programs yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<footer class="site-footer">
  <div class="content-wrap footer-grid">
    <div class="footer-brand">
      <img class="footer-logo" src="img/pesologo.png" alt="PESO Logo" onerror="this.style.display='none'">
      <div>
        <div class="footer-title">BENEPESO</div>
        <div class="footer-sub">PESO Vinzons • Beneficiary Profiling & Verification</div>
      </div>
    </div>
    <div class="footer-col">
      <div class="footer-head">Links</div>
      <a href="home.php">Home</a>
      <a href="programs.php">Programs</a>
      <a href="verification.php">Verification</a>
      <a href="profile.php">Profile</a>
      <a href="privacy_notice.php">Privacy Notice</a>
    </div>
    <div class="footer-col">
      <div class="footer-head">Office</div>
      <div class="footer-text">Municipality of Vinzons, Camarines Norte</div>
      <div class="footer-text">Public Employment Service Office (PESO)</div>
    </div>
  </div>
  <div class="content-wrap footer-bottom">
    <div>© <?php echo date("Y"); ?> BENEPESO • PESO Vinzons</div>
    <div class="footer-mini">Republic of the Philippines • Province of Camarines Norte</div>
  </div>
</footer>

<!-- SYSTEM MODALS -->

<?php if($show_success_modal): ?>
<div class="modal show" id="submissionSuccessModal">
    <div class="modal-content alert-box">
        <div style="margin-bottom: 15px; color: #2e7d32; display: flex; justify-content: center;">
            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
        </div>
        <h2 style="color:#1a6d41; margin-bottom:10px; font-weight:800;">Success!</h2>
        <p style="font-size:14px; color:#555; margin-bottom:25px; font-weight:500;"><?php echo $success_message; ?></p>
        <button class="btn-primary" style="width:100%; box-shadow:none;" onclick="closeModal('submissionSuccessModal')">Continue</button>
    </div>
</div>
<?php endif; ?>

<?php if($show_error_modal): ?>
<div class="modal show" id="submissionErrorModal">
    <div class="modal-content alert-box<?= $error_type === 'tupad_household' ? ' eligibility-notice' : '' ?>">
        <?php if ($error_type === 'tupad_household'): ?>
        <div class="notice-agency">PESO VINZONS &bull; TUPAD PROGRAM</div>
        <div class="notice-emblem" aria-hidden="true">
            <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4.5 6v5.2c0 4.7 3.2 8.1 7.5 9.8 4.3-1.7 7.5-5.1 7.5-9.8V6L12 3Z"/><path d="M8.7 12.1 11 14.4l4.6-4.8"/></svg>
        </div>
        <h2 class="notice-title">Household Eligibility Notice</h2>
        <p class="notice-lead">This application requires verification under the TUPAD one-beneficiary-per-household policy.</p>
        <div class="notice-message"><?= h($error_message) ?></div>
        <p class="notice-guidance">If the household information is incorrect or needs updating, please coordinate with the Public Employment Service Office (PESO) Vinzons before submitting another application.</p>
        <button class="btn-primary notice-button" onclick="closeModal('submissionErrorModal')">I Understand</button>
        <?php else: ?>
        <div style="margin-bottom: 15px; color: #d32f2f; display: flex; justify-content: center;">
            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
        </div>
        <h2 style="color:#a32222; margin-bottom:10px; font-weight:800;">Submission Failed</h2>
        <p style="font-size:14px; color:#555; margin-bottom:25px; font-weight:500;"><?= h($error_message) ?></p>
        <button class="btn-primary" style="background:#eee; color:#333; width:100%; box-shadow:none;" onclick="closeModal('submissionErrorModal')">Close</button>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="modal" id="alertModal">
    <div class="modal-content alert-box">
        <button class="modal-close" onclick="closeModal('alertModal')">✕</button>
        <h2 style="color:#a32222; margin-bottom:10px;">Notice</h2>
        <p id="alertMessage" style="font-size:14px; color:#555; margin-bottom:20px;"></p>
        <button class="btn-primary" style="background:#eee; color:#333; width:auto; box-shadow:none;" onclick="closeModal('alertModal')">Okay</button>
    </div>
</div>

<!-- ARCHIVE SUMMARY MODAL -->
<div class="modal" id="archiveModal">
    <div class="modal-content alert-box archive-summary-dialog">
        <button class="modal-close" onclick="closeModal('archiveModal')">✕</button>
        <div class="archive-summary-icon">
            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
        </div>
        <h2 id="archTitle" class="archive-summary-title"></h2>
        <p id="archBatch" class="archive-summary-batch"></p>

        <div class="archive-summary-metrics">
            <div class="archive-summary-metric">
                <strong id="archServed">0</strong>
                <span>Beneficiaries served</span>
            </div>
            <div class="archive-summary-metric">
                <strong id="archBarangayCount">0</strong>
                <span>Barangays reached</span>
            </div>
        </div>

        <section class="archive-breakdown" aria-labelledby="archiveBreakdownTitle">
            <div class="archive-breakdown-heading">
                <h3 id="archiveBreakdownTitle">Beneficiaries by barangay</h3>
                <span>Approved participants</span>
            </div>
            <div id="archBarangayList" class="archive-barangay-list"></div>
        </section>

        <p class="archive-summary-note">This batch concluded in <strong id="archEnd"></strong>. Figures reflect approved beneficiaries recorded by PESO.</p>
        <button class="btn-secondary archive-summary-close" onclick="closeModal('archiveModal')">Close Summary</button>
    </div>
</div>

<!-- PROGRAM DETAILS MODAL (First step before applying) -->
<div class="modal" id="programDetailsModal">
    <div class="modal-content details-box">
        <button class="modal-close" onclick="closeModal('programDetailsModal')">✕</button>
        <div class="details-header">
            <span class="slots-badge" id="detBadge" style="margin-bottom:10px;"></span>
            <h2 id="detTitle" style="color:var(--green-dark); font-weight:900; line-height:1.2; margin-bottom:5px;"></h2>
            <div class="batch-code" id="detBatch" style="margin-bottom:0;"></div>
        </div>
        
        <div class="details-body" style="margin-top:20px;">
            <p id="detDesc" style="font-size:14px; color:var(--text-muted); line-height:1.6; margin-bottom:20px; text-align:justify;"></p>
            
            <div style="background:var(--bg-main); border:1px solid var(--border-light); border-radius:12px; padding:15px; margin-bottom:20px;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                    <div>
                        <div style="font-size:11px; font-weight:800; color:#a0b0a6; text-transform:uppercase;">Start Date</div>
                        <div id="detStart" style="font-size:13.5px; font-weight:600; color:var(--text-main);"></div>
                    </div>
                    <div>
                        <div style="font-size:11px; font-weight:800; color:#a0b0a6; text-transform:uppercase;">End Date</div>
                        <div id="detEnd" style="font-size:13.5px; font-weight:600; color:var(--text-main);"></div>
                    </div>
                    <div style="grid-column: span 2;">
                        <div style="font-size:11px; font-weight:800; color:#a0b0a6; text-transform:uppercase;">Venue</div>
                        <div id="detVenue" style="font-size:13.5px; font-weight:600; color:var(--text-main);"></div>
                    </div>
                </div>
            </div>

            <div style="margin-bottom:18px;">
                <div style="font-size:14px; font-weight:800; color:var(--green-dark); margin-bottom:8px;">Eligibility Rules</div>
                <div id="detEligibility" style="font-size:13px; color:var(--text-muted); line-height:1.6; background:#f4f8f5; border:1px solid var(--border-light); padding:12px; border-radius:8px;"></div>
            </div>
            
            <div style="margin-bottom:25px;">
                <div style="font-size:14px; font-weight:800; color:var(--green-dark); margin-bottom:8px;">Documentary Requirements</div>
                <div id="detReqs" style="font-size:13px; color:var(--text-muted); line-height:1.6; background:#fff; border:1px solid var(--border-light); padding:12px; border-radius:8px;"></div>
            </div>
        </div>
        
        <div class="details-footer" id="detFooter">
            <!-- Dynamic Buttons inserted via JS -->
        </div>
    </div>
</div>

<div class="modal" id="successEligibleModal">
    <div class="modal-content alert-box" style="max-width: 520px; text-align: left; padding: 40px 35px;">
        <button class="modal-close" onclick="closeModal('successEligibleModal')">✕</button>
        <div class="modal-icon icon-success" style="margin-bottom: 15px; color: #2e7d32; display: flex; justify-content: center;">
            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
        </div>
        <h2 style="color:#1a6d41; margin-bottom:5px; font-weight:800; text-align:center;">Eligibility Confirmed</h2>
        <p style="font-size:14px; color:var(--text-muted); margin-bottom:25px; text-align:center;">Your profile meets the preliminary criteria for this program. You may now proceed with your application.</p>
        
        <!-- RESTORED FOR JS INJECTION -->
        <div style="background:var(--bg-main); border: 1px solid var(--border-light); border-radius:16px; padding:20px; margin-bottom:25px;">
            <h3 id="eligibilityProgName" style="color:var(--green-dark); font-size:17px; margin-bottom:8px; font-weight:800;">Program Title</h3>
            <p id="eligibilityProgDesc" style="font-size:13.5px; color:var(--text-muted); margin-bottom:15px; line-height:1.6; text-align: justify;"></p>
            <div style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:var(--green-dark); background:#fff; padding:10px 15px; border-radius:10px; border:1px solid var(--border-light);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                <span id="eligibilityProgDates">Date</span>
            </div>
        </div>

        <button class="btn-primary" style="width:100%; box-shadow:none;" onclick="proceedToForm()">Proceed to Application Form</button>
    </div>
</div>

<div class="modal" id="statusModal">
    <div class="modal-content alert-box" style="padding-top: 40px; max-width: 500px;">
        <button class="modal-close" onclick="closeModal('statusModal')">✕</button>
        <div class="modal-icon" id="statusIcon"></div>
        <h2 id="statusModalTitle" style="margin-bottom:10px; font-weight:800;">Status</h2>
        <div id="statusModalBody" style="font-size:14px; color:#555; margin-bottom:25px; font-weight:500; text-align: left;"></div>
        <button class="btn-primary" style="width:100%; box-shadow:none;" onclick="closeModal('statusModal')">Close Status</button>
    </div>
</div>

<div class="modal" id="applicationModal">
    <div class="modal-content" style="max-width: 850px;">
        <button class="modal-close" onclick="closeModal('applicationModal')">✕</button>
        <h2 style="color:#1f4d38; margin-bottom:5px;">Application Form</h2>
        <p style="font-size:13px; color:#666; margin-bottom:20px;">Applying for: <strong id="formProgramName"></strong></p>

        <div class="wizard-nav" id="wizardNav">
            <!-- Populated via JS -->
        </div>

        <form method="POST" action="programs.php" id="multiStepForm">
            <input type="hidden" name="action" value="submit_application">
            <input type="hidden" name="program_id" id="hiddenProgramId">

            <!-- STEP 1: SHARED PROFILE -->
            <div class="form-step active" id="step-1">
                <div class="form-grid">
                    <div class="span-2 section-title">Basic Information</div>
                    <div class="form-group"><label>First Name</label><input type="text" value="<?php echo h($user_data['first_name']??''); ?>" readonly required></div>
                    <div class="form-group"><label>Last Name</label><input type="text" value="<?php echo h($user_data['last_name']??''); ?>" readonly required></div>
                    <div class="form-group span-2"><label>Full Address</label><input type="text" value="<?php echo h($full_address); ?>" readonly required></div>
                    <div class="form-group span-2"><label>Contact No.</label><input type="text" value="<?php echo h($user_data['contact_no']??''); ?>" readonly required></div>
                </div>
                <div class="form-actions single-btn">
                    <button type="button" class="btn-primary" onclick="nextStep(1)">Next Step</button>
                </div>
            </div>

            <!-- ===================== TUPAD WIZARD ===================== -->
            <div id="tupadWrapper" style="display:none;">
                <div class="form-step" id="tupad-step-2">
                    <div class="form-grid">
                        <div class="span-2 section-title">Basic Profiling</div>
                        <div class="form-group">
                            <label>Type of ID</label>
                            <select name="type_of_id" onchange="toggleOther(this, 'tupad_id_other')">
                                <option value="">--Select--</option><option value="PhilID">PhilID</option><option value="Voter's ID">Voter's ID</option><option value="Others">Others</option>
                            </select>
                            <input type="text" name="other_type_of_id" id="tupad_id_other" style="display:none; margin-top:5px;" placeholder="Specify ID" class="not-required" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s.-]/g, '')">
                        </div>
                        <div class="form-group"><label>ID Number</label><input type="text" name="id_number" oninput="this.value = this.value.replace(/[^a-zA-Z0-9-]/g, '')"></div>
                        <div class="form-group"><label>Beneficiary Type</label><select name="type_of_beneficiary" onchange="toggleOther(this, 'other_type_of_beneficiary')"><option value="">--Select--</option><?php render_beneficiary_options('beneficiary_type'); ?></select><input type="text" name="other_type_of_beneficiary" id="other_type_of_beneficiary" class="not-required" style="display:none;margin-top:5px" placeholder="Specify beneficiary type"></div>
                        <div class="form-group">
                            <label>Occupation</label>
                            <select name="occupation" onchange="toggleOther(this, 'tupad_occ_other')">
                                <option value="">--Select--</option><?php render_beneficiary_options('occupation'); ?>
                            </select>
                            <input type="text" name="other_occupation" id="tupad_occ_other" style="display:none; margin-top:5px;" placeholder="Specify Occupation" class="not-required" oninput="this.value = this.value.replace(/[^a-zA-ZñÑ\s.-]/g, '')">
                        </div>
                        <div class="form-group"><label>Avg Monthly Income</label><input type="text" name="avg_monthly_income" placeholder="e.g. 5000" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></div>
                        <div class="form-group"><label>Interested in Wage Employment?</label><select name="interested_in_employment"><option value="No">No</option><option value="Yes">Yes</option></select></div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(2)">Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(2)">Next Step</button>
                    </div>
                </div>
                <div class="form-step" id="tupad-step-3">
                    <div class="form-grid">
                        <div class="span-2 section-title">Dependents & Skills</div>
                        <div class="form-group"><label>Dependent Name</label><input type="text" name="dependent_name" class="not-required" placeholder="If applicable" oninput="this.value = this.value.replace(/[^a-zA-ZñÑ\s.-]/g, '')"></div>
                        <div class="form-group"><label>Relationship to Dependent</label>
                            <select name="dependent_relationship" class="not-required" onchange="toggleOther(this, 'other_dependent_relationship')"><option value="">--Select--</option><?php render_beneficiary_options('dependent_relationship'); ?></select><input type="text" name="other_dependent_relationship" id="other_dependent_relationship" class="not-required" style="display:none;margin-top:5px" placeholder="Specify relationship">
                        </div>
                        <div class="form-group span-2"><label>Skills Training Needed</label><select name="skills_training_needed" class="not-required" onchange="toggleOther(this, 'other_skills_training_needed')"><option value="">None / Not specified</option><?php render_beneficiary_options('skills_training'); ?></select><input type="text" name="other_skills_training_needed" id="other_skills_training_needed" class="not-required" style="display:none;margin-top:5px" placeholder="Specify training needed"></div>
                    </div>
                    <label class="privacy-acknowledgment">
                        <input type="checkbox" name="privacy_acknowledgment" value="1">
                        <span>I have read and understood the <a href="privacy_notice.php" target="_blank" rel="noopener">Privacy Notice</a> and understand how my information will be processed for this program application.</span>
                    </label>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(3)">Back</button>
                        <button type="submit" class="btn-primary">Submit Application</button>
                    </div>
                </div>
            </div>

            <!-- ===================== SPES WIZARD ===================== -->
            <div id="spesWrapper" style="display:none;">
                <div class="form-step" id="spes-step-2">
                    <div class="form-grid">
                        <div class="span-2 section-title">Additional Details</div>
                        <div class="form-group"><label>GSIS Beneficiary / Policy No. (If applicable)</label><input type="text" name="gsis_beneficiary" class="not-required" placeholder="Optional"></div>
                        <div class="form-group"><label>Relationship to GSIS Beneficiary</label><input type="text" name="gsis_relationship" class="not-required" placeholder="Optional"></div>
                        <div class="form-group"><label>Place of Birth</label><input type="text" name="place_of_birth"></div>
                        <div class="form-group"><label>Citizenship</label><input type="text" name="citizenship" value="Filipino"></div>
                        <div class="form-group span-2"><label>Social Media URLs (Optional)</label><input type="text" name="social_urls" class="not-required" placeholder="Facebook, LinkedIn..."></div>
                        <div class="form-group"><label>Email</label><input type="email" value="<?php echo h($user_data['email']??''); ?>" readonly></div>
                        <div class="form-group"><label>Date of Birth</label><input type="text" value="<?php echo h($user_data['birthdate']??''); ?>" readonly></div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(2)">Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(2)">Next Step</button>
                    </div>
                </div>

                <div class="form-step" id="spes-step-3">
                    <div class="form-grid">
                        <div class="span-2 section-title">Applicant Status</div>
                        <div class="form-group"><label>Civil Status</label><input type="text" value="<?php echo h($user_data['civil_status']??''); ?>" readonly></div>
                        <div class="form-group"><label>Sex</label><input type="text" value="<?php echo h($user_data['sex']??''); ?>" readonly></div>
                        <div class="form-group span-2"><label>Student Status</label>
                            <select name="spes_type">
                                <option value="">--Select--</option>
                                <option value="Student">Student</option>
                                <option value="ALS student">ALS student</option>
                                <option value="Out-of-school OSY">Out-of-school OSY</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(3)">Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(3)">Next Step</button>
                    </div>
                </div>

                <div class="form-step" id="spes-step-4">
                    <div class="form-grid">
                        <div class="span-2 section-title">Family Background</div>
                        <div class="form-group span-2">
                            <label>Parent Status (Check all that apply)</label>
                            <div class="checkbox-grid">
                                <label><input type="checkbox" name="spes_parent_status[]" value="Living together"> Living together</label>
                                <label><input type="checkbox" name="spes_parent_status[]" value="Single Parent"> Single Parent</label>
                                <label><input type="checkbox" name="spes_parent_status[]" value="Separated"> Separated</label>
                                <label><input type="checkbox" name="spes_parent_status[]" value="Person With Disability"> Person With Disability</label>
                                <label><input type="checkbox" name="spes_parent_status[]" value="Senior Citizen"> Senior Citizen</label>
                                <label><input type="checkbox" name="spes_parent_status[]" value="Sugar Plantation Worker"> Sugar Plantation Worker</label>
                                <label><input type="checkbox" name="spes_parent_status[]" value="Indigenous Peoples"> Indigenous Peoples</label>
                                <label><input type="checkbox" name="spes_parent_status[]" value="Displaced Worker - Local"> Displaced Worker - Local</label>
                                <label><input type="checkbox" name="spes_parent_status[]" value="Displaced Worker - OFW"> Displaced Worker - OFW</label>
                            </div>
                        </div>
                        
                        <div class="form-group span-2">
                            <label>Current Address</label>
                            <input type="text" id="current_addr" value="<?php echo h($full_address); ?>" readonly>
                        </div>
                        <div class="form-group span-2">
                            <label>Permanent Address</label>
                            <label style="font-size: 11.5px; display:flex; align-items:center; gap:6px; text-transform:none; margin-bottom:5px; font-weight:600; cursor:pointer;">
                                <input type="checkbox" id="sameAddressCheck" onclick="copyAddress()" style="width:14px; height:14px; accent-color:var(--green);"> Same as Current Address
                            </label>
                            <input type="text" name="permanent_address" id="perm_address" placeholder="Enter permanent address">
                        </div>
                        
                        <div class="form-group span-2 section-title" style="margin-top:10px; font-size:14px;">Father's Details</div>
                        <div class="form-group span-2"><label>Father's Name</label><input type="text" name="father_name" placeholder="Full Name" oninput="this.value = this.value.replace(/[^a-zA-ZñÑ\s.-]/g, '')"></div>
                        <div class="form-group"><label>Father's Contact No.</label><input type="text" name="father_contact" placeholder="09xxxxxxxxx" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></div>
                        <div class="form-group"><label>Father's Occupation</label><select name="father_occupation" onchange="toggleOther(this, 'other_father_occupation')"><option value="">--Select--</option><?php render_beneficiary_options('parent_occupation'); ?></select><input type="text" name="other_father_occupation" id="other_father_occupation" class="not-required" style="display:none;margin-top:5px" placeholder="Specify occupation"></div>
                        
                        <div class="form-group span-2 section-title" style="margin-top:10px; font-size:14px;">Mother's Details</div>
                        <div class="form-group span-2"><label>Mother's Maiden Name</label><input type="text" name="mother_name" placeholder="Full Name" oninput="this.value = this.value.replace(/[^a-zA-ZñÑ\s.-]/g, '')"></div>
                        <div class="form-group"><label>Mother's Contact No.</label><input type="text" name="mother_contact" placeholder="09xxxxxxxxx" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></div>
                        <div class="form-group"><label>Mother's Occupation</label><select name="mother_occupation" onchange="toggleOther(this, 'other_mother_occupation')"><option value="">--Select--</option><?php render_beneficiary_options('parent_occupation'); ?></select><input type="text" name="other_mother_occupation" id="other_mother_occupation" class="not-required" style="display:none;margin-top:5px" placeholder="Specify occupation"></div>
                        <div class="form-group span-2"><label>Estimated Monthly Family Income</label><input type="text" name="spes_avg_monthly_income" inputmode="numeric" placeholder="e.g. 10000" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(4)">Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(4)">Next Step</button>
                    </div>
                </div>

                <div class="form-step" id="spes-step-5">
                    <div class="form-grid">
                        <div class="span-2 section-title">Educational History</div>
                        
                        <!-- Elementary -->
                        <div class="form-group span-2"><label>Elementary School Name</label><input type="text" name="elem_school"></div>
                        <div class="form-group"><label>Degree/Honors</label><input type="text" name="elem_degree" class="not-required" placeholder="Put N/A if none"></div>
                        <div class="form-group">
                            <label>Highest Year Level</label>
                            <select name="elem_year_level">
                                <option value="">--Select--</option>
                                <option value="Grade 1">Grade 1</option>
                                <option value="Grade 2">Grade 2</option>
                                <option value="Grade 3">Grade 3</option>
                                <option value="Grade 4">Grade 4</option>
                                <option value="Grade 5">Grade 5</option>
                                <option value="Grade 6">Grade 6</option>
                                <option value="Graduated">Graduated</option>
                            </select>
                        </div>
                        <div class="form-group span-2"><label>Inclusive Dates of Attendance</label><input type="text" name="elem_date_attendance" placeholder="e.g. 2010-2016" oninput="this.value = this.value.replace(/[^0-9\s-]/g, '')"></div>
                        <div class="span-2 divider-line"></div>
                        
                        <!-- Secondary (JHS & SHS combined) -->
                        <div class="form-group span-2"><label>Secondary / Senior High School Name</label><input type="text" name="sec_school"></div>
                        <div class="form-group">
                            <label>Track / Strand</label>
                            <select name="sec_degree" class="not-required" onchange="toggleOther(this, 'sec_degree_other')">
                                <option value="N/A">N/A (JHS)</option>
                                <option value="STEM">STEM</option>
                                <option value="ABM">ABM</option>
                                <option value="HUMSS">HUMSS</option>
                                <option value="GAS">GAS</option>
                                <option value="TVL">TVL</option>
                                <option value="Others">Others</option>
                            </select>
                            <input type="text" name="other_sec_degree" id="sec_degree_other" style="display:none; margin-top:5px;" class="not-required" placeholder="Specify Strand">
                        </div>
                        <div class="form-group">
                            <label>Highest Year Level</label>
                            <select name="sec_year_level">
                                <option value="">--Select--</option>
                                <option value="Grade 7">Grade 7</option>
                                <option value="Grade 8">Grade 8</option>
                                <option value="Grade 9">Grade 9</option>
                                <option value="Grade 10">Grade 10</option>
                                <option value="Grade 11">Grade 11</option>
                                <option value="Grade 12">Grade 12</option>
                                <option value="Graduated">Graduated</option>
                            </select>
                        </div>
                        <div class="form-group span-2"><label>Inclusive Dates of Attendance</label><input type="text" name="sec_date_attendance" placeholder="e.g. 2016-2022" oninput="this.value = this.value.replace(/[^0-9\s-]/g, '')"></div>
                        <div class="span-2 divider-line"></div>
                        
                        <!-- Tertiary -->
                        <div class="form-group span-2"><label>Tertiary School Name (Put N/A if none)</label><input type="text" name="tert_school" class="not-required" placeholder="Put N/A if none"></div>
                        <div class="form-group">
                            <label>Course / Degree</label>
                            <select name="tert_course" class="not-required" onchange="toggleOther(this, 'tert_course_other')">
                                <option value="N/A">N/A</option>
                                <option value="BS Information Technology">BS Information Technology</option>
                                <option value="BS Business Administration">BS Business Admin</option>
                                <option value="BS Education">BS Education</option>
                                <option value="BS Criminology">BS Criminology</option>
                                <option value="BS Engineering">BS Engineering</option>
                                <option value="Others">Others</option>
                            </select>
                            <input type="text" name="other_tert_course" id="tert_course_other" style="display:none; margin-top:5px;" class="not-required" placeholder="Specify Course">
                        </div>
                        <div class="form-group">
                            <label>Highest Year Level</label>
                            <select name="tert_year_level" class="not-required">
                                <option value="N/A">N/A</option>
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                                <option value="5th Year">5th Year</option>
                                <option value="Graduated">Graduated</option>
                            </select>
                        </div>
                        <div class="form-group span-2"><label>Inclusive Dates of Attendance</label><input type="text" name="tert_date_attendance" class="not-required" placeholder="e.g. 2022-2026" oninput="this.value = this.value.replace(/[^0-9\s-]/g, '')"></div>
                        <div class="span-2 divider-line"></div>
                        
                        <!-- Tech-Voc -->
                        <div class="form-group span-2"><label>Tech-Voc School Name (Put N/A if none)</label><input type="text" name="tv_school" class="not-required" placeholder="Put N/A if none"></div>
                        <div class="form-group">
                            <label>Tech-Voc Course</label>
                            <select name="tv_course" class="not-required" onchange="toggleOther(this, 'tv_course_other')">
                                <option value="N/A">N/A</option>
                                <option value="Computer Systems Servicing NC II">Computer Systems Servicing NC II</option>
                                <option value="Automotive Servicing NC II">Automotive Servicing NC II</option>
                                <option value="Electrical Installation NC II">Electrical Installation NC II</option>
                                <option value="Others">Others</option>
                            </select>
                            <input type="text" name="other_tv_course" id="tv_course_other" style="display:none; margin-top:5px;" class="not-required" placeholder="Specify Course">
                        </div>
                        <div class="form-group"><label>Hours/Level Completed</label><input type="text" name="tv_year_level" class="not-required" placeholder="e.g. N/A or 300 Hrs"></div>
                        <div class="form-group span-2"><label>Date of Attendance</label><input type="text" name="tv_date_attendance" class="not-required" placeholder="e.g. 2023 or N/A"></div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(5)">Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(5)">Next Step</button>
                    </div>
                </div>

                <div class="form-step" id="spes-step-6">
                    <div class="form-grid">
                        <div class="span-2 section-title">Skills & History</div>
                        <div class="form-group span-2"><label>Special Skills (Optional)</label><textarea name="special_skills" class="not-required" rows="3"></textarea></div>
                        
                        <div class="span-2 section-title" style="margin-top:10px;">History of SPES Availment</div>
                        <div class="span-2 dynamic-table-wrap">
                            <table class="dynamic-table" id="spesTable">
                                <thead>
                                    <tr>
                                        <th>Availment No.</th>
                                        <th>Establishment</th>
                                        <th>Year</th>
                                        <th>SPES ID NO.</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><input type="text" name="spes_hist_avail[]" class="not-required" placeholder="e.g. 1st"></td>
                                        <td><input type="text" name="spes_hist_est[]" class="not-required" placeholder="Office/LGU"></td>
                                        <td><input type="text" name="spes_hist_year[]" class="not-required" placeholder="YYYY" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></td>
                                        <td><input type="text" name="spes_hist_id[]" class="not-required" placeholder="ID Number"></td>
                                        <td class="action-cell"><button type="button" class="btn-remove-row" onclick="removeRow(this)">✕</button></td>
                                    </tr>
                                </tbody>
                            </table>
                            <button type="button" class="btn-add-row" onclick="addSpesRow()">+ Add History</button>
                        </div>
                    </div>
                    <label class="privacy-acknowledgment">
                        <input type="checkbox" name="privacy_acknowledgment" value="1">
                        <span>I have read and understood the <a href="privacy_notice.php" target="_blank" rel="noopener">Privacy Notice</a> and understand how my information will be processed for this program application.</span>
                    </label>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(6)">Back</button>
                        <button type="submit" class="btn-primary">Submit Application</button>
                    </div>
                </div>
            </div>

            <!-- ===================== MSME WIZARD ===================== -->
            <div id="msmeWrapper" style="display:none;">
                <div class="form-step" id="msme-step-2">
                    <div class="form-grid">
                        <div class="span-2 section-title">Business Profile</div>
                        <div class="form-group span-2"><label>Business/Trade Name</label><input type="text" name="business_name"></div>
                        <div class="form-group span-2"><label>Type of Ownership</label><select name="ownership_type" onchange="toggleOther(this, 'other_ownership_type')"><option value="">--Select--</option><?php render_beneficiary_options('ownership_type'); ?></select><input type="text" name="other_ownership_type" id="other_ownership_type" class="not-required" style="display:none;margin-top:5px" placeholder="Specify ownership type"></div>
                        <div class="form-group span-2">
                            <label>Nature of Business (Check all that apply)</label>
                            <div class="checkbox-grid">
                                <label><input type="checkbox" name="business_nature_arr[]" value="Food & Beverage"> Food & Beverage</label>
                                <label><input type="checkbox" name="business_nature_arr[]" value="Retail/Trading"> Retail/Trading</label>
                                <label><input type="checkbox" name="business_nature_arr[]" value="Services"> Services</label>
                                <label><input type="checkbox" name="business_nature_arr[]" value="Handicrafts"> Handicrafts</label>
                                <label><input type="checkbox" name="business_nature_arr[]" value="Agri-Products"> Agri-Products</label>
                                <label><input type="checkbox" name="business_nature_arr[]" value="Others" onchange="document.getElementById('msme_nat_other').style.display=this.checked?'block':'none'"> Others</label>
                            </div>
                            <input type="text" name="other_business_nature" id="msme_nat_other" style="display:none; margin-top:10px;" class="not-required" placeholder="Specify other nature of business">
                        </div>
                        
                        <div class="span-2 section-title">Primary Products / Prices</div>
                        <div class="span-2 dynamic-table-wrap">
                            <table class="dynamic-table" id="productsTable">
                                <thead>
                                    <tr>
                                        <th>Product Name</th>
                                        <th>Price (₱)</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><input type="text" name="prod_name[]" placeholder="Item Name"></td>
                                        <td><input type="text" name="prod_price[]" placeholder="0.00" oninput="this.value = this.value.replace(/[^0-9.]/g, '')"></td>
                                        <td class="action-cell"><button type="button" class="btn-remove-row" onclick="removeRow(this)">✕</button></td>
                                    </tr>
                                </tbody>
                            </table>
                            <button type="button" class="btn-add-row" onclick="addProductRow()">+ Add Product</button>
                        </div>

                        <div class="form-group"><label>Year Started</label><input type="text" name="year_started" placeholder="YYYY" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></div>
                        <div class="form-group"><label>Business Permit No.</label><input type="text" name="business_permit_no"></div>
                        <div class="form-group"><label>Permit Valid Until</label><input type="date" name="permit_valid_until"></div>
                        <div class="form-group"><label>DTI Reg No.</label><input type="text" name="dti_no"></div>
                        <div class="form-group"><label>TIN</label><input type="text" name="tin_no" oninput="this.value = this.value.replace(/[^0-9-]/g, '')"></div>
                        <div class="form-group"><label>Contact Details (Landline, Email)</label><input type="text" name="contact_details"></div>
                        <div class="form-group span-2"><label>Website / Social Media (Optional)</label><input type="text" name="business_social_media" class="not-required" placeholder="Website or Facebook page"></div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(2)">Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(2)">Next Step</button>
                    </div>
                </div>

                <div class="form-step" id="msme-step-3">
                    <div class="form-grid">
                        <div class="span-2 section-title">Owner Info</div>
                        <div class="form-group span-2" style="font-size: 13px; color: var(--text-muted); margin-bottom: -5px;"><i>Note: These fields are pre-filled with your profile data, but you may edit them if applying on behalf of the business owner.</i></div>
                        <div class="form-group span-2"><label>Full Name</label><input type="text" name="owner_full_name" value="<?php echo h($full_name); ?>"></div>
                        <div class="form-group"><label>Sex</label>
                            <select name="owner_sex">
                                <option value="Male" <?php if(($user_data['sex']??'')=='Male') echo 'selected'; ?>>Male</option>
                                <option value="Female" <?php if(($user_data['sex']??'')=='Female') echo 'selected'; ?>>Female</option>
                            </select>
                        </div>
                        <div class="form-group"><label>Contact No.</label><input type="text" name="owner_contact_no" value="<?php echo h($user_data['contact_no']??''); ?>" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></div>
                        <div class="form-group"><label>Date of Birth</label><input type="date" name="owner_birthdate" value="<?php echo h($user_data['birthdate']??''); ?>"></div>
                        <div class="form-group"><label>Age</label><input type="text" name="owner_age" value="<?php echo $userAge; ?>" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></div>
                        <div class="form-group"><label>Civil Status</label>
                            <select name="owner_civil_status">
                                <option value="Single" <?php if(($user_data['civil_status']??'')=='Single') echo 'selected'; ?>>Single</option>
                                <option value="Married" <?php if(($user_data['civil_status']??'')=='Married') echo 'selected'; ?>>Married</option>
                                <option value="Widowed" <?php if(in_array(($user_data['civil_status']??''), ['Widowed', 'Widow/er'], true)) echo 'selected'; ?>>Widowed</option>
                                <option value="Legally Separated" <?php if(in_array(($user_data['civil_status']??''), ['Legally Separated', 'Separated'], true)) echo 'selected'; ?>>Legally Separated</option>
                            </select>
                        </div>
                        <div class="form-group span-2"><label>Full Address</label><input type="text" name="owner_full_address" value="<?php echo h($full_address); ?>"></div>
                        <div class="form-group span-2">
                            <label>Educational Attainment</label>
                            <select name="educational_attainment" onchange="toggleOther(this, 'msme_edu_other')">
                                <option value="">--Select--</option>
                                <option value="Elementary">Elementary</option>
                                <option value="High School">High School</option>
                                <option value="College Undergraduate">College Undergraduate</option>
                                <option value="College Graduate">College Graduate</option>
                                <option value="Vocational/Technical">Vocational/Technical</option>
                                <option value="Others">Others</option>
                            </select>
                            <input type="text" name="other_educational_attainment" id="msme_edu_other" style="display:none; margin-top:5px;" class="not-required" placeholder="Specify Educational Attainment">
                        </div>
                        <div class="form-group span-2"><label>Work Experience</label><textarea name="work_experience" rows="3"></textarea></div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(3)">Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(3)">Next Step</button>
                    </div>
                </div>

                <div class="form-step" id="msme-step-4">
                    <div class="form-grid">
                        <div class="span-2 section-title">Operations</div>
                        <div class="form-group span-2">
                            <label>Assets Owned (Check all that apply)</label>
                            <div class="checkbox-grid">
                                <label><input type="checkbox" name="assets_owned[]" value="Cart/Stall"> Cart/Stall</label>
                                <label><input type="checkbox" name="assets_owned[]" value="Cooking Equipment"> Cooking Equipment</label>
                                <label><input type="checkbox" name="assets_owned[]" value="Refrigerator"> Refrigerator</label>
                                <label><input type="checkbox" name="assets_owned[]" value="Vehicles"> Vehicles</label>
                                <label><input type="checkbox" name="assets_owned[]" value="Others" onchange="document.getElementById('asset_other').style.display=this.checked?'block':'none'"> Others</label>
                            </div>
                            <input type="text" name="assets_owned[]" id="asset_other" style="display:none; margin-top:10px;" placeholder="Specify other assets" class="not-required">
                        </div>
                        <div class="form-group span-2">
                            <label>Utility Needs (Check all that apply)</label>
                            <div class="checkbox-grid">
                                <label><input type="checkbox" name="utility_needs[]" value="Electricity"> Electricity</label>
                                <label><input type="checkbox" name="utility_needs[]" value="Water"> Water</label>
                                <label><input type="checkbox" name="utility_needs[]" value="Storage"> Storage</label>
                                <label><input type="checkbox" name="utility_needs[]" value="Internet/Data"> Internet/Data</label>
                                <label><input type="checkbox" name="utility_needs[]" value="Others" onchange="document.getElementById('util_other').style.display=this.checked?'block':'none'"> Others</label>
                            </div>
                            <input type="text" name="utility_needs[]" id="util_other" style="display:none; margin-top:10px;" placeholder="Specify other utilities" class="not-required">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(4)">Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(4)">Next Step</button>
                    </div>
                </div>

                <div class="form-step" id="msme-step-5">
                    <div class="form-grid">
                        <div class="span-2 section-title">Human Resources</div>
                        <div class="form-group"><label>Number of Male Workers</label><input type="text" name="hr_male" id="hr_male" value="0" oninput="this.value = this.value.replace(/[^0-9]/g, ''); calcHr()"></div>
                        <div class="form-group"><label>Number of Female Workers</label><input type="text" name="hr_female" id="hr_female" value="0" oninput="this.value = this.value.replace(/[^0-9]/g, ''); calcHr()"></div>
                        <div class="form-group span-2"><label>Total Workers</label><input type="text" name="hr_total" id="hr_total" readonly value="0"></div>
                        
                        <div class="span-2 section-title">Employment Status</div>
                        <div class="form-group"><label>Regular</label><input type="text" name="emp_regular" value="0" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></div>
                        <div class="form-group"><label>Seasonal</label><input type="text" name="emp_seasonal" value="0" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></div>
                        <div class="form-group"><label>Contractual</label><input type="text" name="emp_contractual" value="0" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></div>
                        <div class="form-group"><label>Family</label><input type="text" name="emp_family" value="0" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></div>
                        
                        <div class="form-group span-2"><label>Skills Training Needed (Optional)</label><textarea name="hr_skills" rows="3" class="not-required"></textarea></div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(5)">Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(5)">Next Step</button>
                    </div>
                </div>

                <div class="form-step" id="msme-step-6">
                    <div class="form-grid">
                        <div class="span-2 section-title">Financials</div>
                        <div class="form-group span-2">
                            <label>Source of Capital (Check all that apply)</label>
                            <div class="checkbox-grid">
                                <label><input type="checkbox" name="source_of_capital[]" value="Own Savings"> Own Savings</label>
                                <label><input type="checkbox" name="source_of_capital[]" value="Loan Bank"> Loan Bank</label>
                                <label><input type="checkbox" name="source_of_capital[]" value="Loan Coop/MFI"> Loan Coop/MFI</label>
                                <label><input type="checkbox" name="source_of_capital[]" value="Borrowed from Family/Friends"> Borrowed Family/Friends</label>
                                <label><input type="checkbox" name="source_of_capital[]" value="Government Assistance"> Govt Assistance</label>
                                <label><input type="checkbox" name="source_of_capital[]" value="Others" onchange="document.getElementById('cap_other').style.display=this.checked?'block':'none'"> Others</label>
                            </div>
                            <input type="text" name="source_of_capital[]" id="cap_other" style="display:none; margin-top:10px;" placeholder="Specify other source" class="not-required">
                        </div>
                        <div class="form-group span-2"><label>Business Size</label>
                            <select name="business_size">
                                <option value="">--Select--</option>
                                <option value="Micro">Micro ≤ ₱3M</option>
                                <option value="Small">Small ₱3,000,001–₱15,000,000</option>
                                <option value="Medium">Medium ₱15,000,001–₱100,000,000</option>
                            </select>
                        </div>
                        <div class="form-group"><label>Initial Capital (₱)</label><input type="text" name="initial_capital" placeholder="0.00" oninput="this.value = this.value.replace(/[^0-9.]/g, '')"></div>
                        <div class="form-group"><label>Current Capital (₱)</label><input type="text" name="current_capital" placeholder="0.00" oninput="this.value = this.value.replace(/[^0-9.]/g, '')"></div>
                        <div class="form-group span-2"><label>Regular Daily Earnings (₱)</label><input type="text" name="daily_earnings" placeholder="0.00" oninput="this.value = this.value.replace(/[^0-9.]/g, '')"></div>
                        
                        <div class="form-group span-2">
                            <label>Mode of Payment Accepted</label>
                            <div class="checkbox-grid">
                                <label><input type="checkbox" name="mode_of_payment[]" value="Cash"> Cash</label>
                                <label><input type="checkbox" name="mode_of_payment[]" value="E-Wallet GCash/PayMaya"> E-Wallet</label>
                                <label><input type="checkbox" name="mode_of_payment[]" value="Bank Transfer"> Bank Transfer</label>
                                <label><input type="checkbox" name="mode_of_payment[]" value="Others" onchange="document.getElementById('mop_other').style.display=this.checked?'block':'none'"> Others</label>
                            </div>
                            <input type="text" name="mode_of_payment[]" id="mop_other" style="display:none; margin-top:10px;" placeholder="Specify other mode" class="not-required">
                        </div>
                        <div class="form-group span-2">
                            <label>Distribution Channels</label>
                            <div class="checkbox-grid">
                                <label><input type="checkbox" name="distribution_channels[]" value="Direct Selling"> Direct Selling</label>
                                <label><input type="checkbox" name="distribution_channels[]" value="Retailer"> Retailer</label>
                                <label><input type="checkbox" name="distribution_channels[]" value="Wholesaler"> Wholesaler</label>
                                <label><input type="checkbox" name="distribution_channels[]" value="Online Platform"> Online Platform</label>
                                <label><input type="checkbox" name="distribution_channels[]" value="Others" onchange="document.getElementById('dist_other').style.display=this.checked?'block':'none'"> Others</label>
                            </div>
                            <input type="text" name="distribution_channels[]" id="dist_other" style="display:none; margin-top:10px;" placeholder="Specify other channel" class="not-required">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(6)">Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(6)">Next Step</button>
                    </div>
                </div>

                <div class="form-step" id="msme-step-7">
                    <div class="form-grid">
                        <div class="span-2 section-title">Government Assistance</div>
                        <div class="form-group span-2"><label>Availed before?</label>
                            <select name="availed_before" onchange="document.getElementById('gov_assisted_wrap').style.display=(this.value==='Yes')?'grid':'none'">
                                <option value="">--Select--</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                            </select>
                        </div>
                        
                        <div id="gov_assisted_wrap" class="span-2 form-grid" style="display:none; gap:18px;">
                            <div class="form-group span-2">
                                <label>Assistance Availed</label>
                                <div class="checkbox-grid">
                                    <label><input type="checkbox" name="assistance_availed[]" value="DTI Training/Livelihood Kits"> DTI Training/Livelihood Kits</label>
                                    <label><input type="checkbox" name="assistance_availed[]" value="DOLE Livelihood Program"> DOLE Livelihood Program</label>
                                    <label><input type="checkbox" name="assistance_availed[]" value="TESDA Skills Training"> TESDA Skills Training</label>
                                    <label><input type="checkbox" name="assistance_availed[]" value="DA/DSWD Support"> DA/DSWD Support</label>
                                    <label><input type="checkbox" name="assistance_availed[]" value="LGU Assistance"> LGU Assistance</label>
                                    <label><input type="checkbox" name="assistance_availed[]" value="Others" onchange="document.getElementById('assist_other').style.display=this.checked?'block':'none'"> Others</label>
                                </div>
                                <input type="text" name="assistance_availed[]" id="assist_other" style="display:none; margin-top:10px;" placeholder="Specify other assistance" class="not-required">
                            </div>
                            <div class="form-group span-2">
                                <label>Past Programs</label>
                                <div class="checkbox-grid">
                                    <label><input type="checkbox" name="past_programs[]" value="Skills Training"> Skills Training</label>
                                    <label><input type="checkbox" name="past_programs[]" value="Trade Fair/Exhibit"> Trade Fair/Exhibit</label>
                                    <label><input type="checkbox" name="past_programs[]" value="Product Packaging & Labeling"> Product Packaging & Labeling</label>
                                    <label><input type="checkbox" name="past_programs[]" value="Business Advisory Services"> Business Advisory Services</label>
                                    <label><input type="checkbox" name="past_programs[]" value="Shared Service Facilities"> Shared Service Facilities</label>
                                    <label><input type="checkbox" name="past_programs[]" value="Others" onchange="document.getElementById('past_other').style.display=this.checked?'block':'none'"> Others</label>
                                </div>
                                <input type="text" name="past_programs[]" id="past_other" style="display:none; margin-top:10px;" placeholder="Specify other past program" class="not-required">
                            </div>
                        </div>

                        <div class="form-group span-2">
                            <label>Programs Needed (Check all that apply)</label>
                            <div class="checkbox-grid">
                                <label><input type="checkbox" name="programs_needed[]" value="Financing Assistance"> Financing Assistance</label>
                                <label><input type="checkbox" name="programs_needed[]" value="Skills Training"> Skills Training</label>
                                <label><input type="checkbox" name="programs_needed[]" value="Marketing Support"> Marketing Support</label>
                                <label><input type="checkbox" name="programs_needed[]" value="Product Development & Innovation"> Product Development</label>
                                <label><input type="checkbox" name="programs_needed[]" value="Business Registration Assistance"> Business Registration Assist</label>
                                <label><input type="checkbox" name="programs_needed[]" value="Export Assistance"> Export Assistance</label>
                                <label><input type="checkbox" name="programs_needed[]" value="Others" onchange="document.getElementById('prog_n_other').style.display=this.checked?'block':'none'"> Others</label>
                            </div>
                            <input type="text" name="programs_needed[]" id="prog_n_other" style="display:none; margin-top:10px;" placeholder="Specify other programs" class="not-required">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(7)">Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(7)">Next Step</button>
                    </div>
                </div>

                <div class="form-step" id="msme-step-8">
                    <div class="form-grid">
                        <div class="span-2 section-title">Challenges</div>
                        <div class="form-group span-2">
                            <label>Challenges Encountered (Check all that apply)</label>
                            <div class="checkbox-grid">
                                <label><input type="checkbox" name="challenges_encountered[]" value="Lack of access to capital/credit"> Lack of access to capital/credit</label>
                                <label><input type="checkbox" name="challenges_encountered[]" value="Limited marketing and promotion"> Limited marketing and promotion</label>
                                <label><input type="checkbox" name="challenges_encountered[]" value="Lack of technical skills and training"> Lack of technical skills</label>
                                <label><input type="checkbox" name="challenges_encountered[]" value="High cost of raw materials"> High cost of raw materials</label>
                                <label><input type="checkbox" name="challenges_encountered[]" value="Limited technology and equipment"> Limited technology/equipment</label>
                                <label><input type="checkbox" name="challenges_encountered[]" value="Inadequate infrastructure"> Inadequate infrastructure</label>
                                <label><input type="checkbox" name="challenges_encountered[]" value="Lack of business permits/documentation"> Lack of permits/documentation</label>
                                <label><input type="checkbox" name="challenges_encountered[]" value="Competition from larger businesses"> Competition from larger businesses</label>
                                <label><input type="checkbox" name="challenges_encountered[]" value="Others" onchange="document.getElementById('chal_other').style.display=this.checked?'block':'none'"> Others</label>
                            </div>
                            <input type="text" name="challenges_encountered[]" id="chal_other" style="display:none; margin-top:10px;" placeholder="Specify other challenges" class="not-required">
                        </div>
                    </div>
                    <label class="privacy-acknowledgment">
                        <input type="checkbox" name="privacy_acknowledgment" value="1">
                        <span>I have read and understood the <a href="privacy_notice.php" target="_blank" rel="noopener">Privacy Notice</a> and understand how my information will be processed for this program application.</span>
                    </label>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(8)">Back</button>
                        <button type="submit" class="btn-primary">Submit Application</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    const accountButton = document.getElementById('accountButton');
    const accountDropdown = document.getElementById('accountDropdown');
    if(accountButton) { accountButton.addEventListener('click', (e) => { e.stopPropagation(); accountDropdown.classList.toggle('show'); }); }
    window.addEventListener('click', () => { if(accountDropdown && accountDropdown.classList.contains('show')) accountDropdown.classList.remove('show'); });
    const menuButton = document.getElementById('menuButton');
    const menuArea = document.getElementById('menuArea');
    if(menuButton) { menuButton.addEventListener('click', () => { menuArea.classList.toggle('show'); }); }

    function filterPrograms() {
        let input = document.getElementById('searchInput');
        let filter = input.value.toLowerCase();
        let categoryFilter = document.getElementById('tupadCategoryFilter')?.value || 'all';
        let cards = document.getElementsByClassName('program-card');
        let grid = document.getElementById('programGrid');
        let hasMatch = false;
        
        input.classList.add('searching');
        setTimeout(() => input.classList.remove('searching'), 300);

        if (filter.length > 0) {
            grid.classList.add('list-view');
        } else {
            grid.classList.remove('list-view');
        }

        for (let i = 0; i < cards.length; i++) {
            let title = cards[i].getAttribute('data-title');
            let category = cards[i].getAttribute('data-category') || '';
            let matchesSearch = title && (title.toLowerCase().indexOf(filter) > -1 || category.indexOf(filter) > -1);
            let matchesCategory = categoryFilter === 'all' || category === categoryFilter;
            if (matchesSearch && matchesCategory) {
                cards[i].style.display = "flex";
                hasMatch = true;
            } else {
                cards[i].style.display = "none";
            }
        }

        const noMatchMsg = document.getElementById('noSearchMatch');
        if (noMatchMsg) {
            noMatchMsg.style.display = hasMatch ? "none" : "block";
        }
    }

    let activeProgramId = 0, activeProgramName = "", currentFormType = "tupad", totalSteps = 3;
    
    function closeModal(id) { 
        document.getElementById(id).classList.remove('show'); 
    }
    
    function toggleOther(selectObj, otherId) {
        const otherInput = document.getElementById(otherId);
        if (selectObj.value === 'Others') { 
            otherInput.style.display = 'block'; 
            if(!selectObj.classList.contains('not-required')) {
                otherInput.setAttribute('required', 'required'); 
            }
        } 
        else { 
            otherInput.style.display = 'none'; 
            otherInput.removeAttribute('required'); 
        }
    }

    function copyAddress() {
        let current = document.getElementById('current_addr').value;
        let perm = document.getElementById('perm_address');
        let check = document.getElementById('sameAddressCheck');
        if(check.checked) {
            perm.value = current;
            perm.setAttribute('readonly', 'readonly');
            perm.style.background = '#f0f4f2';
        } else {
            perm.value = '';
            perm.removeAttribute('readonly');
            perm.style.background = '#f9fbf9';
        }
    }

    function calcHr() {
        let m = parseInt(document.getElementById('hr_male').value) || 0;
        let f = parseInt(document.getElementById('hr_female').value) || 0;
        document.getElementById('hr_total').value = m + f;
    }

    function addProductRow() {
        let table = document.getElementById('productsTable').getElementsByTagName('tbody')[0];
        if(table.rows.length >= 10) return alert("Maximum 10 products allowed.");
        let newRow = table.insertRow();
        newRow.innerHTML = `<td><input type="text" name="prod_name[]" placeholder="Item Name" required></td>
                            <td><input type="text" name="prod_price[]" placeholder="0.00" oninput="this.value = this.value.replace(/[^0-9.]/g, '')" required></td>
                            <td class="action-cell"><button type="button" class="btn-remove-row" onclick="removeRow(this)">✕</button></td>`;
    }

    function addSpesRow() {
        let table = document.getElementById('spesTable').getElementsByTagName('tbody')[0];
        if(table.rows.length >= 4) return alert("Maximum 4 histories allowed.");
        let newRow = table.insertRow();
        newRow.innerHTML = `<td><input type="text" name="spes_hist_avail[]" class="not-required" placeholder="e.g. 1st"></td>
                            <td><input type="text" name="spes_hist_est[]" class="not-required" placeholder="Office/LGU"></td>
                            <td><input type="text" name="spes_hist_year[]" class="not-required" placeholder="YYYY" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></td>
                            <td><input type="text" name="spes_hist_id[]" class="not-required" placeholder="ID Number"></td>
                            <td class="action-cell"><button type="button" class="btn-remove-row" onclick="removeRow(this)">✕</button></td>`;
    }

    function removeRow(btn) {
        let row = btn.parentNode.parentNode;
        row.parentNode.removeChild(row);
    }

    // FLOW 1: Active Programs Details -> Eligibility -> Application
    function openProgramDetails(element) {
        let action = element.getAttribute('data-action');
        if (action === 'none') return; 
        
        activeProgramId = element.getAttribute('data-prog-id');
        activeProgramName = element.getAttribute('data-title');
        
        const title = element.getAttribute('data-title');
        const batch = element.getAttribute('data-batch');
        const desc = element.getAttribute('data-desc');
        const startDate = element.getAttribute('data-start');
        const endDate = element.getAttribute('data-end');
        const slots = element.getAttribute('data-slots');
        const venue = element.getAttribute('data-venue');
        const reqs = element.getAttribute('data-reqs');
        const eligibility = element.getAttribute('data-eligibility') || '';
        
        const status = (element.getAttribute('data-status') || '').trim().toLowerCase();
        const availment = (element.getAttribute('data-availment') || '').trim().toLowerCase();
        const reason = element.getAttribute('data-reason');

        // Populate Details Modal
        document.getElementById('detTitle').innerText = title;
        document.getElementById('detBatch').innerText = "BATCH: " + batch;
        document.getElementById('detDesc').innerText = desc;
        document.getElementById('detStart').innerText = startDate;
        document.getElementById('detEnd').innerText = endDate;
        document.getElementById('detVenue').innerText = venue;
        document.getElementById('detReqs').innerHTML = reqs.replace(/\n/g, '<br>');
        const eligibilityTarget = document.getElementById('detEligibility');
        if (eligibilityTarget) eligibilityTarget.innerText = eligibility;
        
        let badge = document.getElementById('detBadge');
        badge.innerText = slots + " Slots Available";
        badge.className = (slots <= 5) ? 'slots-badge warning' : 'slots-badge';

        let footer = document.getElementById('detFooter');
        footer.innerHTML = ''; 
        
        let btn = document.createElement('button');
        btn.className = "btn-primary";
        btn.style.width = "100%";

        if (action === 'status') {
            btn.innerText = "Check Application Status";
            btn.onclick = function() {
                closeModal('programDetailsModal');
                viewStatus(status, availment, reason, reqs, venue, title);
            };
        } else {
            btn.innerText = "Check Eligibility & Apply";
            btn.onclick = function() {
                // Set global active variables before checking eligibility
                activeProgramId = element.getAttribute('data-prog-id');
                activeProgramName = title;
                checkEligibility(activeProgramId, title, desc, startDate, endDate);
            };
        }
        
        footer.appendChild(btn);
        document.getElementById('programDetailsModal').classList.add('show');
        fetch(`programs.php?action=log_view&type=details&prog_name=${encodeURIComponent(title)}`);
    }

    function viewStatus(status, availment, reason, reqs, venue, title) {
        const modal = document.getElementById('statusModal');
        const modalTitle = document.getElementById('statusModalTitle');
        const modalBody = document.getElementById('statusModalBody');
        const modalIcon = document.getElementById('statusIcon');

        modalIcon.className = "modal-icon";
        modalIcon.style.background = "";
        modalIcon.style.color = "";

        if (status === 'approved') {
            if (availment === 'ongoing') {
                modalIcon.className = "modal-icon icon-success";
                modalIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`;
                modalTitle.innerText = "Congratulations!";
                modalTitle.style.color = "var(--green)";
                modalBody.innerHTML = `
                    <div style="text-align:center; padding: 10px 0;">
                        <h3 style="color:var(--green-dark); font-size:18px; margin-bottom:10px; font-weight: 800;">You are now a ${title} Beneficiary!</h3>
                        <p style="font-size: 14.5px; color: #444; line-height: 1.6;">Your application has been completely finalized by DOLE. Your work and program status is now officially <strong style="color:var(--green);">Ongoing</strong>.</p>
                    </div>
                `;
            } else if (availment === 'requirements received') {
                modalIcon.className = "modal-icon";
                modalIcon.style.background = "#e0f2fe";
                modalIcon.style.color = "#0284c7";
                modalIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>`;
                modalTitle.innerText = "Documents Received";
                modalTitle.style.color = "#0284c7";
                modalBody.innerHTML = `
                    <div style="text-align:center; padding: 10px 0;">
                        <h3 style="color:#0284c7; font-size:17px; margin-bottom:10px; font-weight: 800;">Requirements in Process</h3>
                        <p style="font-size: 14.5px; color: #444; line-height: 1.6;">We have successfully received your physical documents for <b>${title}</b>. They are currently being verified and forwarded.</p>
                    </div>
                `;
            } else if (availment === 'not yet availed') {
                modalIcon.className = "modal-icon icon-success";
                modalIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`;
                modalTitle.innerText = "LGU Approved!";
                modalTitle.style.color = "var(--green)";
                modalBody.innerHTML = `
                    <div style="text-align:center;">
                        <h3 style="color:#27ae60; font-size:16px; margin-bottom:10px; font-weight:800;">Action Required: Submit Documents</h3>
                        <p style="font-size: 14px; color: #444;">Your application for <b>${title}</b> has been <strong>Approved</strong> by the LGU! To finalize your slot, please bring your physical requirements.</p>
                    </div>
                `;
            } else {
                modalIcon.className = "modal-icon icon-success";
                modalIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`;
                modalTitle.innerText = "Application Approved";
                modalTitle.style.color = "var(--green)";
                modalBody.innerHTML = `
                    <div style="text-align:center;">
                        <h3 style="color:#27ae60; font-size:16px; margin-bottom:10px; font-weight:800;">LGU Approved</h3>
                        <p style="font-size: 14px; color: #444;">Your application for <b>${title}</b> has been approved. Tracking status: <strong>${availment.toUpperCase()}</strong>.</p>
                    </div>
                `;
            }
        } 
        else if (status === 'rejected') {
            modalIcon.className = "modal-icon icon-danger";
            modalIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>`;
            modalTitle.innerText = "Application Rejected";
            modalTitle.style.color = "#e74c3c";
            modalBody.innerHTML = `
                <p style="text-align:center;">Unfortunately, your application for <b>${title}</b> was not approved.</p>
                <div class="reason-box" style="background: rgba(231, 76, 60, 0.05); border: 1px solid rgba(231, 76, 60, 0.2); padding: 15px; border-radius: 12px; margin-top: 15px;">
                    <h4 style="color: #c0392b; margin-bottom: 5px;">Reason given by Admin:</h4>
                    <p style="margin:0;">${reason}</p>
                </div>
            `;
        } 
        else {
            modalIcon.className = "modal-icon icon-warning";
            modalIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>`;
            modalTitle.innerText = "Application Pending";
            modalTitle.style.color = "#f39c12";
            modalBody.innerHTML = `
                <p style="text-align:center;">Your application for <b>${title}</b> is currently under review by the PESO admin team. Please check back later.</p>
            `;
        }

        modal.classList.add('show');
    }

    function checkEligibility(programId, title, desc, startDate, endDate) {
        document.getElementById('eligibilityProgName').innerText = title;
        document.getElementById('eligibilityProgDesc').innerText = desc;
        document.getElementById('eligibilityProgDates').innerHTML = "<strong>Program Duration:</strong> " + startDate + " to " + endDate;

        fetch(`programs.php?action=check_eligibility&program_id=` + programId)
            .then(response => response.json())
            .then(data => {
                closeModal('programDetailsModal');
                if (data.eligible === false) {
                    document.getElementById('alertMessage').textContent = data.message;
                    document.getElementById('alertModal').classList.add('show');
                } else {
                    document.getElementById('successEligibleModal').classList.add('show');
                }
            }).catch(error => console.error('Fetch Error:', error));
    }

    // FLOW 2: Archive Program Details
    function openArchiveDetails(element) {
        let barangays = [];
        try {
            barangays = JSON.parse(element.getAttribute('data-barangays') || '[]');
        } catch (error) {
            barangays = [];
        }

        document.getElementById('archTitle').innerText = element.getAttribute('data-title');
        document.getElementById('archBatch').innerText = "BATCH: " + element.getAttribute('data-batch');
        document.getElementById('archServed').innerText = element.getAttribute('data-served');
        document.getElementById('archBarangayCount').innerText = barangays.length;
        document.getElementById('archEnd').innerText = element.getAttribute('data-end');

        const barangayList = document.getElementById('archBarangayList');
        barangayList.replaceChildren();

        if (barangays.length === 0) {
            const emptyState = document.createElement('p');
            emptyState.className = 'archive-breakdown-empty';
            emptyState.textContent = 'No barangay distribution has been recorded for this batch.';
            barangayList.appendChild(emptyState);
        } else {
            const highestCount = Math.max(...barangays.map(item => Number(item.count) || 0), 1);
            barangays.forEach(item => {
                const count = Number(item.count) || 0;
                const row = document.createElement('div');
                row.className = 'archive-barangay-row';

                const label = document.createElement('div');
                label.className = 'archive-barangay-label';

                const name = document.createElement('span');
                name.textContent = item.name || 'Not specified';

                const total = document.createElement('strong');
                total.textContent = count;

                const track = document.createElement('div');
                track.className = 'archive-barangay-track';

                const bar = document.createElement('span');
                bar.style.width = `${Math.max(6, (count / highestCount) * 100)}%`;

                label.append(name, total);
                track.appendChild(bar);
                row.append(label, track);
                barangayList.appendChild(row);
            });
        }

        document.getElementById('archiveModal').classList.add('show');
    }

    function buildWizardNav(stepsArray) {
        let navHtml = '';
        totalSteps = stepsArray.length;
        stepsArray.forEach((stepName, idx) => {
            navHtml += `<div class="wizard-step-indicator" id="ind-step-${idx+1}"><span class="wizard-number">${idx+1}</span><span class="wizard-label">${stepName}</span></div>`;
        });
        document.getElementById('wizardNav').innerHTML = navHtml;
    }

    function proceedToForm() {
        closeModal('successEligibleModal');
        document.getElementById('formProgramName').textContent = activeProgramName;
        document.getElementById('hiddenProgramId').value = activeProgramId;

        document.getElementById('tupadWrapper').style.display = 'none';
        document.getElementById('spesWrapper').style.display = 'none';
        document.getElementById('msmeWrapper').style.display = 'none';

        let uName = activeProgramName.toUpperCase();
        if (uName.includes('SPES')) currentFormType = 'spes';
        else if (uName.includes('MSME')) currentFormType = 'msme';
        else currentFormType = 'tupad';

        if(currentFormType === 'msme') {
            buildWizardNav(["Basic Info", "Business Profile", "Owner Info", "Operations", "Human Resources", "Financials", "Gov Assistance", "Challenges"]);
        } else if (currentFormType === 'spes') {
            buildWizardNav(["Basic Info", "Other Details", "Status", "Family", "Education", "History"]);
        } else {
            buildWizardNav(["Basic Info", "Specifics", "Dependents"]);
        }

        document.getElementById(currentFormType + 'Wrapper').style.display = 'block';
        showStep(1);

        // Reset Required attributes
        document.querySelectorAll('#applicationModal [required]').forEach(el => el.removeAttribute('required'));
        document.querySelectorAll('#step-1 input').forEach(el => el.setAttribute('required', 'required'));
        
        // Strictly apply to only fields WITHOUT the .not-required class
        document.querySelectorAll(`#${currentFormType}Wrapper input[name]:not([type="checkbox"]):not([type="file"]):not(.not-required), #${currentFormType}Wrapper select:not(.not-required), #${currentFormType}Wrapper textarea:not(.not-required)`).forEach(el => {
            if(!el.id.includes('other') && el.style.display !== 'none' && !el.name.includes('[]') && !el.name.includes('hr_')) {
                el.setAttribute('required', 'required');
            }
        });
        const privacyAcknowledgment = document.querySelector(`#${currentFormType}Wrapper input[name="privacy_acknowledgment"]`);
        if (privacyAcknowledgment) privacyAcknowledgment.setAttribute('required', 'required');

        document.getElementById('applicationModal').classList.add('show');
    }

    function showStep(step) {
        document.querySelectorAll('.form-step').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.wizard-step-indicator').forEach(el => el.classList.remove('active'));
        
        let targetStep = step === 1 ? document.getElementById('step-1') : document.getElementById(`${currentFormType}-step-${step}`);
        if(targetStep) targetStep.classList.add('active');
        
        for(let i=1; i<=step; i++) {
            let ind = document.getElementById(`ind-step-${i}`);
            if(ind) ind.classList.add('active');
        }
    }

    function nextStep(currentStep) {
        let currentContainer = currentStep === 1 ? document.getElementById('step-1') : document.getElementById(`${currentFormType}-step-${currentStep}`);
        let inputs = currentContainer.querySelectorAll('[required]');
        let isValid = true;
        inputs.forEach(input => { 
            if (!input.checkValidity()) { 
                input.reportValidity(); 
                isValid = false; 
            } 
        });
        if (isValid) showStep(currentStep + 1);
    }
    function prevStep(currentStep) { showStep(currentStep - 1); }
</script>
</body>
</html>
