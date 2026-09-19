<?php
require_once __DIR__ . '/auth.php';
require "db.php";
require_once "program_eligibility_helper.php";
require_once "tupad_category_helper.php";
require_once "beneficiary_choices.php";
require_once "privacy_helper.php";
require_once "email_helper.php";
require_once "tupad_household_helper.php";
require_once "tupad_document_helper.php";
require_once "spes_schema_helper.php";
require_once "spes_lifecycle_helper.php";
ensure_program_eligibility_schema($conn);
ensure_tupad_category_schema($conn);
ensure_tupad_document_schema($conn);
ensureSpesParentStatusCapacity($conn);

check_user_role('user');

function h($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }

function is_supported_benepeso_program_name(string $programName): bool
{
    $normalized = strtoupper(trim($programName));
    return str_contains($normalized, 'TUPAD')
        || str_contains($normalized, 'SPES')
        || str_contains($normalized, 'MSME');
}

$user_id = (int)$_SESSION["user_id"];
$user_display_name = "User";
$first_char = "U";
$is_logged_in = true;
$user_profile_src = '';

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
    $profile_filename = basename((string)($user_data['profile_pic'] ?? ''));
    if ($profile_filename !== '' && is_file(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $profile_filename)) {
        $user_profile_src = 'uploads/' . rawurlencode($profile_filename);
    }
}
$stmt->close();

$spes_lifecycle = spes_user_summary($conn, $user_id, (string)($user_data['email'] ?? ''));
$is_spes_returning = $spes_lifecycle['classification'] !== 'none';
$spes_prefill = $spes_lifecycle['latest'] ?? [];

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_view') {
    auth_require_csrf();
    header('Content-Type: application/json');
    $prog_name = trim((string)($_POST['prog_name'] ?? 'a program'));
    $log_type = $_POST['type'] ?? 'Viewed';
    if ($prog_name === '') $prog_name = 'a program';
    $prog_name = mb_substr($prog_name, 0, 150);

    $logFingerprint = hash('sha256', $log_type . '|' . $prog_name);
    $lastViewLog = $_SESSION['last_program_view_log'] ?? [];
    if (($lastViewLog['fingerprint'] ?? '') === $logFingerprint && (int)($lastViewLog['time'] ?? 0) > time() - 5) {
        echo json_encode(['status' => 'skipped']);
        exit();
    }
    
    $desc = ($log_type === 'status') ? "Opened status details for $prog_name." : "Viewed details for $prog_name.";
    $mod = "Programs";
    
    $log_stmt = $conn->prepare("INSERT INTO activity_logs (actor_name, actor_role, module_name, action_type, target_name, description, created_at) VALUES (?, 'Registered User', ?, 'VIEW', ?, ?, NOW())");
    $log_stmt->bind_param("ssss", $user_display_name, $mod, $prog_name, $desc);
    $log_stmt->execute();
    $_SESSION['last_program_view_log'] = ['fingerprint' => $logFingerprint, 'time' => time()];
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
    if (!is_supported_benepeso_program_name($prog_name_check)) {
        echo json_encode(['eligible' => false, 'message' => 'This listing is not part of the supported BENEPESO catalog.']);
        exit();
    }
    $base_prog_name = explode(' ', trim($prog_name_check))[0];

    // RULE 1: Global Block - User cannot apply if they have ANY active or pending program.
    $activeAppStmt = $conn->prepare("
        SELECT p.program_name, b.availment_status 
        FROM beneficiaries b 
        JOIN programs p ON b.program_id = p.program_id 
        WHERE b.user_id = ? 
        AND (b.approval_status = 'Pending' 
             OR (b.approval_status = 'Approved' AND b.availment_status IN ('Not Yet Availed', 'Requirements Received', 'Orientation', 'Examination', 'Exam Passed', 'Exam Failed', 'Ongoing', 'Salary Distribution')))
        ORDER BY b.created_at DESC LIMIT 1
    ");
    $activeAppStmt->bind_param("i", $user_id);
    $activeAppStmt->execute();
    $activeApp = $activeAppStmt->get_result()->fetch_assoc();
    $activeAppStmt->close();

    if ($activeApp) {
        echo json_encode(['eligible' => false, 'message' => "You currently have an active or pending application for " . $activeApp['program_name'] . ". You cannot apply for another program until it is completed."]); exit();
    }

    // RULE 2: The PESO Vinzons 20-month cooldown applies only to TUPAD.
    if (strcasecmp($base_prog_name, 'TUPAD') === 0) {
        $twentyMonthsAgo = date('Y-m-d', strtotime('-20 months'));
        $searchBase = $base_prog_name . '%';
        $cooldownStmt = $conn->prepare("SELECT b.date_completed, b.date_availed FROM beneficiaries b JOIN programs p ON b.program_id = p.program_id WHERE b.user_id = ? AND p.program_name LIKE ? AND b.approval_status = 'Approved' AND b.availment_status = 'Completed' ORDER BY b.created_at DESC LIMIT 1");
        $cooldownStmt->bind_param("is", $user_id, $searchBase);
        $cooldownStmt->execute();
        $lastAvail = $cooldownStmt->get_result()->fetch_assoc();
        $cooldownStmt->close();
        if ($lastAvail) {
            $compareDate = !empty($lastAvail['date_completed']) ? $lastAvail['date_completed'] : (!empty($lastAvail['date_availed']) ? $lastAvail['date_availed'] : null);
            if ($compareDate && $compareDate > $twentyMonthsAgo) {
                echo json_encode(['eligible' => false, 'message' => "You must wait 1 year and 8 months after completing a TUPAD program before applying for a new batch."]); exit();
            }
        }
    }

    // FAMILY CHECK BYPASSED: 
    // Allowing siblings/family to apply via the automated checker to prevent false disqualifications.
    // Approvals for duplicate households will be handled by the Admin dashboard manually.
    
    echo json_encode(['eligible' => true]); exit();
}

// HANDLE BULLETPROOF FORM SUBMISSION
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'submit_application') {
    auth_require_csrf();
    if (!isset($_POST['privacy_acknowledgment']) || $_POST['privacy_acknowledgment'] !== '1') {
        $_SESSION['app_error'] = 'Please read and acknowledge the Privacy Notice before submitting your application.';
        header('Location: programs.php');
        exit();
    }
    $submittedProgramId = (int)($_POST['program_id'] ?? 0);
    $programNameStmt = $conn->prepare("SELECT p.program_name,p.status,p.start_date,p.end_date,p.slots,p.minimum_age,p.maximum_age,
        (SELECT COUNT(*) FROM beneficiaries approved WHERE approved.program_id=p.program_id AND approved.approval_status='Approved') AS approved_count
        FROM programs p WHERE p.program_id=? LIMIT 1");
    $programNameStmt->bind_param('i', $submittedProgramId);
    $programNameStmt->execute();
    $submittedProgram = $programNameStmt->get_result()->fetch_assoc() ?: [];
    $submittedProgramName = (string)($submittedProgram['program_name'] ?? '');
    $programNameStmt->close();
    if (!is_supported_benepeso_program_name($submittedProgramName)) {
        $_SESSION['app_error'] = 'This listing is not part of the supported BENEPESO catalog.';
        header('Location: programs.php');
        exit();
    }
    $isTupadApplication = stripos($submittedProgramName, 'TUPAD') !== false;
    $isSpesApplication = stripos($submittedProgramName, 'SPES') !== false;
    $isMsmeApplication = stripos($submittedProgramName, 'MSME') !== false;
    $configuredEligibility = evaluate_program_eligibility($conn, $user_id, $submittedProgramId);
    if (!$configuredEligibility['eligible']) {
        $_SESSION['app_error'] = $configuredEligibility['message'];
        header('Location: programs.php'); exit();
    }

    // Enforce the same cross-program restriction during the final POST. The
    // eligibility preview is only a convenience and must never be trusted as
    // the authority for accepting an application.
    $activeApplicationStmt = $conn->prepare("SELECT p.program_name FROM beneficiaries b
        JOIN programs p ON p.program_id=b.program_id
        WHERE b.user_id=?
          AND (b.approval_status='Pending'
               OR (b.approval_status='Approved' AND b.availment_status IN
                   ('Not Yet Availed','Requirements Received','Orientation','Examination','Exam Passed','Exam Failed','Ongoing','Salary Distribution')))
        ORDER BY b.created_at DESC LIMIT 1");
    $activeApplicationStmt->bind_param('i', $user_id);
    $activeApplicationStmt->execute();
    $activeApplication = $activeApplicationStmt->get_result()->fetch_assoc();
    $activeApplicationStmt->close();
    if ($activeApplication) {
        $_SESSION['app_error'] = 'You currently have an active or pending application for ' . $activeApplication['program_name'] . '. Complete it before applying for another program.';
        header('Location: programs.php'); exit();
    }

    $today = date('Y-m-d');
    $programUnavailable = !$submittedProgram
        || strtolower((string)($submittedProgram['status'] ?? '')) === 'completed'
        || (!empty($submittedProgram['end_date']) && $submittedProgram['end_date'] < $today)
        || (!empty($submittedProgram['start_date']) && !empty($submittedProgram['end_date']) && $submittedProgram['end_date'] < $submittedProgram['start_date'])
        || ((int)($submittedProgram['slots'] ?? 0) > 0 && (int)($submittedProgram['approved_count'] ?? 0) >= (int)$submittedProgram['slots']);
    if ($programUnavailable) {
        $_SESSION['app_error'] = 'This program batch is no longer accepting applications.';
        header('Location: programs.php'); exit();
    }
    if ($isMsmeApplication) {
        $today = date('Y-m-d');
        $msmeUnavailable = strtolower((string)($submittedProgram['status'] ?? '')) === 'completed'
            || (!empty($submittedProgram['end_date']) && $submittedProgram['end_date'] < $today)
            || (!empty($submittedProgram['start_date']) && !empty($submittedProgram['end_date']) && $submittedProgram['end_date'] < $submittedProgram['start_date'])
            || ((int)($submittedProgram['slots'] ?? 0) > 0 && (int)($submittedProgram['approved_count'] ?? 0) >= (int)$submittedProgram['slots']);
        if ($msmeUnavailable) {
            $_SESSION['app_error'] = 'This MSME profiling batch is no longer accepting applications.';
            header('Location: programs.php'); exit();
        }

        $requiredMsmeFields = [
            'business_name' => 'Business/Trade Name', 'ownership_type' => 'Type of Ownership',
            'owner_full_name' => 'Owner Full Name', 'owner_contact_no' => 'Owner Contact Number',
            'owner_birthdate' => 'Owner Date of Birth', 'owner_full_address' => 'Owner Full Address',
            'educational_attainment' => 'Educational Attainment', 'business_size' => 'Business Size',
            'year_started' => 'Year Started', 'business_email' => 'Business Email'
        ];
        foreach ($requiredMsmeFields as $field => $label) {
            if (trim((string)($_POST[$field] ?? '')) === '') {
                $_SESSION['app_error'] = "Please complete the MSME field: {$label}.";
                header('Location: programs.php'); exit();
            }
        }
        if (empty($_POST['business_nature_arr']) || !is_array($_POST['business_nature_arr'])) {
            $_SESSION['app_error'] = 'Please select at least one nature of business.';
            header('Location: programs.php'); exit();
        }
        if (in_array('Others', $_POST['business_nature_arr'], true) && trim((string)($_POST['other_business_nature'] ?? '')) === '') {
            $_SESSION['app_error'] = 'Please specify the other nature of business.';
            header('Location: programs.php'); exit();
        }
        $hasProduct = false;
        foreach ((array)($_POST['prod_name'] ?? []) as $productName) {
            if (trim((string)$productName) !== '') { $hasProduct = true; break; }
        }
        if (!$hasProduct) {
            $_SESSION['app_error'] = 'Please provide at least one primary product or service.';
            header('Location: programs.php'); exit();
        }
        $businessEmail = trim((string)($_POST['business_email'] ?? $_POST['contact_details'] ?? ''));
        if (!filter_var($businessEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['app_error'] = 'Please enter a valid business email address.';
            header('Location: programs.php'); exit();
        }
        $yearStarted = trim((string)($_POST['year_started'] ?? ''));
        $currentYear = (int)date('Y');
        if (!preg_match('/^\d{4}$/', $yearStarted) || (int)$yearStarted < 1900 || (int)$yearStarted > $currentYear) {
            $_SESSION['app_error'] = 'Please select a valid year when the business started.';
            header('Location: programs.php'); exit();
        }
        $ownerBirthdate = trim((string)($_POST['owner_birthdate'] ?? ''));
        $parsedOwnerBirthdate = DateTimeImmutable::createFromFormat('!Y-m-d', $ownerBirthdate);
        if (!$parsedOwnerBirthdate || $parsedOwnerBirthdate->format('Y-m-d') !== $ownerBirthdate || $parsedOwnerBirthdate > new DateTimeImmutable('today')) {
            $_SESSION['app_error'] = 'Please enter a valid owner date of birth.';
            header('Location: programs.php'); exit();
        }
        $verifiedOwnerAge = (new DateTimeImmutable('today'))->diff($parsedOwnerBirthdate)->y;
        $minimumOwnerAge = (int)($submittedProgram['minimum_age'] ?? 18);
        $maximumOwnerAge = $submittedProgram['maximum_age'] === null ? null : (int)$submittedProgram['maximum_age'];
        if ($verifiedOwnerAge < $minimumOwnerAge || ($maximumOwnerAge !== null && $verifiedOwnerAge > $maximumOwnerAge)) {
            $_SESSION['app_error'] = $maximumOwnerAge === null
                ? "The business owner must be at least {$minimumOwnerAge} years old."
                : "The business owner must be {$minimumOwnerAge}–{$maximumOwnerAge} years old.";
            header('Location: programs.php'); exit();
        }
        $_POST['owner_age'] = (string)$verifiedOwnerAge;
        if (($_POST['msme_certified_truthful'] ?? '') !== '1') {
            $_SESSION['app_error'] = 'Please certify that the MSME information is true and complete.';
            header('Location: programs.php'); exit();
        }
    }
    if ($isSpesApplication) {
        $today = date('Y-m-d');
        $spesUnavailable = strtolower((string)($submittedProgram['status'] ?? '')) === 'completed'
            || (!empty($submittedProgram['end_date']) && $submittedProgram['end_date'] < $today)
            || (!empty($submittedProgram['start_date']) && !empty($submittedProgram['end_date']) && $submittedProgram['end_date'] < $submittedProgram['start_date'])
            || ((int)($submittedProgram['slots'] ?? 0) > 0 && (int)($submittedProgram['approved_count'] ?? 0) >= (int)$submittedProgram['slots']);
        if ($spesUnavailable) {
            $_SESSION['app_error'] = 'This SPES batch is no longer accepting applications.';
            header('Location: programs.php'); exit();
        }

        $activeSpesStmt = $conn->prepare("SELECT 1 FROM beneficiaries WHERE user_id=? AND (approval_status='Pending' OR (approval_status='Approved' AND availment_status IN ('Not Yet Availed','Requirements Received','Orientation','Examination','Exam Passed','Ongoing','Salary Distribution'))) LIMIT 1");
        $activeSpesStmt->bind_param('i', $user_id);
        $activeSpesStmt->execute();
        $hasActiveApplication = $activeSpesStmt->get_result()->num_rows > 0;
        $activeSpesStmt->close();
        if ($hasActiveApplication) {
            $_SESSION['app_error'] = 'You already have a pending or active program application. Complete that application before applying for SPES.';
            header('Location: programs.php'); exit();
        }

        $spesPregnancy = trim((string)($_POST['spes_is_pregnant'] ?? ''));
        if (!in_array($spesPregnancy, ['Yes', 'No', 'Not Applicable'], true)) {
            $_SESSION['app_error'] = 'Please answer the SPES pregnancy declaration.';
            header('Location: programs.php'); exit();
        }
        $spesLocalEligibility = evaluate_spes_local_eligibility($_POST, $is_spes_returning);
        if (!$spesLocalEligibility['eligible']) {
            $_SESSION['app_error'] = $spesLocalEligibility['message'];
            header('Location: programs.php'); exit();
        }
        if (($_POST['spes_certified_truthful'] ?? '') !== '1') {
            $_SESSION['app_error'] = 'Please certify that your SPES information is true and complete.';
            header('Location: programs.php'); exit();
        }
        $submittedGsisBeneficiary = trim((string)($_POST['gsis_beneficiary'] ?? ''));
        $submittedGsisRelationship = trim((string)($_POST['gsis_relationship'] ?? ''));
        if ($submittedGsisBeneficiary !== '' && $submittedGsisRelationship === '') {
            $_SESSION['app_error'] = 'Please select your relationship to the GSIS beneficiary.';
            header('Location: programs.php'); exit();
        }
        if ($submittedGsisBeneficiary !== '' && $submittedGsisRelationship === 'Others' && trim((string)($_POST['other_gsis_relationship'] ?? '')) === '') {
            $_SESSION['app_error'] = 'Please specify your relationship to the GSIS beneficiary.';
            header('Location: programs.php'); exit();
        }
    }
    if ($isTupadApplication) {
        $today = date('Y-m-d');
        $tupadUnavailable = strtolower((string)($submittedProgram['status'] ?? '')) === 'completed'
            || (!empty($submittedProgram['end_date']) && $submittedProgram['end_date'] < $today)
            || (!empty($submittedProgram['start_date']) && !empty($submittedProgram['end_date']) && $submittedProgram['end_date'] < $submittedProgram['start_date'])
            || ((int)($submittedProgram['slots'] ?? 0) > 0 && (int)($submittedProgram['approved_count'] ?? 0) >= (int)$submittedProgram['slots']);
        if ($tupadUnavailable) {
            $_SESSION['app_error'] = 'This TUPAD batch is no longer accepting applications.';
            header('Location: programs.php'); exit();
        }

        $activeTupadStmt = $conn->prepare("SELECT 1 FROM beneficiaries b
            WHERE b.user_id=?
              AND (b.approval_status='Pending' OR (b.approval_status='Approved' AND b.availment_status IN ('Ongoing','Not Yet Availed','Requirements Received')))
            LIMIT 1");
        $activeTupadStmt->bind_param('i', $user_id);
        $activeTupadStmt->execute();
        $hasActiveTupad = $activeTupadStmt->get_result()->num_rows > 0;
        $activeTupadStmt->close();
        if ($hasActiveTupad) {
            $_SESSION['app_error'] = 'You already have a pending or active program application. Complete that application before applying for TUPAD.';
            header('Location: programs.php'); exit();
        }

        $cooldownStmt = $conn->prepare("SELECT COALESCE(b.date_completed,b.date_availed) AS reference_date
            FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id
            WHERE b.user_id=? AND p.program_name LIKE 'TUPAD%' AND b.approval_status='Approved' AND b.availment_status='Completed'
            ORDER BY COALESCE(b.date_completed,b.date_availed,b.created_at) DESC LIMIT 1");
        $cooldownStmt->bind_param('i', $user_id);
        $cooldownStmt->execute();
        $lastTupad = $cooldownStmt->get_result()->fetch_assoc();
        $cooldownStmt->close();
        if (!empty($lastTupad['reference_date']) && date('Y-m-d', strtotime($lastTupad['reference_date'] . ' +20 months')) > $today) {
            $_SESSION['app_error'] = 'You must wait 1 year and 8 months after completing TUPAD before applying for another batch.';
            header('Location: programs.php'); exit();
        }

        $isPregnant = trim((string)($_POST['tupad_is_pregnant'] ?? ''));
        $isPwd = trim((string)($_POST['tupad_is_pwd'] ?? ''));
        $hasLimitation = trim((string)($_POST['tupad_has_work_limitation'] ?? ''));
        $capableOfWork = trim((string)($_POST['tupad_capable_of_work'] ?? ''));
        if (!in_array($isPregnant, ['Yes','No','Not Applicable'], true) || !in_array($isPwd, ['Yes','No'], true) || !in_array($hasLimitation, ['Yes','No'], true) || !in_array($capableOfWork, ['Yes','No'], true)) {
            $_SESSION['app_error'] = 'Please complete the TUPAD fitness-to-work declarations.';
            header('Location: programs.php'); exit();
        }
        if ($isPregnant === 'Yes') {
            $_SESSION['app_error'] = 'Pregnant applicants are not eligible for TUPAD.';
            header('Location: programs.php'); exit();
        }
        if ($capableOfWork !== 'Yes') {
            $_SESSION['app_error'] = 'You selected No for the ability-to-work declaration. Selecting Yes for PWD does not disqualify you; PWD applicants may apply when they are able and willing to perform assigned work with reasonable accommodation if needed.';
            header('Location: programs.php'); exit();
        }
        if (($_POST['tupad_certified_truthful'] ?? '') !== '1') {
            $_SESSION['app_error'] = 'Please certify that your TUPAD information is true and complete.';
            header('Location: programs.php'); exit();
        }
        $needsFitnessCertificate = $isPwd === 'Yes' || $hasLimitation === 'Yes';
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
        if (!isset($_POST[$post_key]) || !is_array($_POST[$post_key])) return trim($_POST[$post_key] ?? "");
        $values = array_values(array_filter(array_map(static fn($value) => trim((string)$value), $_POST[$post_key]), static fn($value) => $value !== ''));
        if (count($values) > 1) $values = array_values(array_filter($values, static fn($value) => !in_array(strtolower($value), ['other', 'others'], true)));
        return implode(', ', $values);
    }

    $type_of_id = trim($_POST["type_of_id"] ?? "");
    if ($type_of_id === 'Others') $type_of_id = trim($_POST["other_type_of_id"] ?? "Others");
    
    $occupation = choice_or_other($_POST, 'occupation');
    $type_of_beneficiary = choice_or_other($_POST, 'type_of_beneficiary');
    $dependent_relationship = choice_or_other($_POST, 'dependent_relationship');
    $gsis_relationship = trim((string)($_POST['gsis_relationship'] ?? ''));
    if ($gsis_relationship === 'Others') $gsis_relationship = trim((string)($_POST['other_gsis_relationship'] ?? 'Others'));
    $allowedGsisRelationships = ['', 'Father', 'Mother', 'Guardian', 'Spouse'];
    if (!in_array($gsis_relationship, $allowedGsisRelationships, true) && trim((string)($_POST['gsis_relationship'] ?? '')) !== 'Others') $gsis_relationship = '';
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
    if (!in_array($form_civil, ['Single', 'Married', 'Widowed', 'Legally Separated'], true)) $form_civil = '';

    $msme_nature = processArrayField('business_nature_arr');
    if (in_array('Others', (array)($_POST['business_nature_arr'] ?? []), true) && trim((string)($_POST['other_business_nature'] ?? '')) !== '') {
        $standardNature = trim(str_replace('Others', '', $msme_nature), " ,");
        $msme_nature = implode(', ', array_filter([$standardNature, trim((string)$_POST['other_business_nature'])]));
    }
    
    $msme_product_names = [];
    $msme_product_prices = [];
    foreach (array_slice((array)($_POST['prod_name'] ?? []), 0, 10) as $index => $productName) {
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
        "spes_is_pregnant" => trim($_POST["spes_is_pregnant"] ?? ""),
        "gsis_beneficiary_name" => trim($_POST["gsis_beneficiary"] ?? ""),
        "gsis_relationship" => $gsis_relationship,
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
        "business_email" => trim($_POST["business_email"] ?? $_POST["contact_details"] ?? ""),
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

    if (!$conn->begin_transaction()) {
        $_SESSION['app_error'] = 'Your application could not be started safely. Please try again.';
        header('Location: programs.php');
        exit();
    }

    // Serialize submissions for this user and batch, then repeat the mutable
    // checks inside the transaction to prevent double submissions and slot
    // overbooking caused by simultaneous requests.
    $userLockStmt = $conn->prepare('SELECT user_id FROM users WHERE user_id=? FOR UPDATE');
    $userLockStmt->bind_param('i', $user_id);
    $userLockStmt->execute();
    $lockedUser = $userLockStmt->get_result()->fetch_assoc();
    $userLockStmt->close();
    if (!$lockedUser) {
        $conn->rollback();
        $_SESSION['app_error'] = 'Your account could not be verified. Please sign in again.';
        header('Location: programs.php'); exit();
    }

    $programLockStmt = $conn->prepare("SELECT p.slots,p.status,p.start_date,p.end_date,
        (SELECT COUNT(*) FROM beneficiaries approved WHERE approved.program_id=p.program_id AND approved.approval_status='Approved') AS approved_count
        FROM programs p WHERE p.program_id=? AND p.approval_status='Approved' LIMIT 1 FOR UPDATE");
    $programLockStmt->bind_param('i', $submittedProgramId);
    $programLockStmt->execute();
    $lockedProgram = $programLockStmt->get_result()->fetch_assoc();
    $programLockStmt->close();

    $activeApplicationStmt = $conn->prepare("SELECT 1 FROM beneficiaries b
        WHERE b.user_id=?
          AND (b.approval_status='Pending'
               OR (b.approval_status='Approved' AND b.availment_status IN
                   ('Not Yet Availed','Requirements Received','Orientation','Examination','Exam Passed','Exam Failed','Ongoing','Salary Distribution')))
        LIMIT 1 FOR UPDATE");
    $activeApplicationStmt->bind_param('i', $user_id);
    $activeApplicationStmt->execute();
    $hasConcurrentApplication = $activeApplicationStmt->get_result()->num_rows > 0;
    $activeApplicationStmt->close();

    $lockedProgramUnavailable = !$lockedProgram
        || strtolower((string)($lockedProgram['status'] ?? '')) === 'completed'
        || (!empty($lockedProgram['end_date']) && $lockedProgram['end_date'] < $today)
        || (!empty($lockedProgram['start_date']) && !empty($lockedProgram['end_date']) && $lockedProgram['end_date'] < $lockedProgram['start_date'])
        || ((int)($lockedProgram['slots'] ?? 0) > 0 && (int)($lockedProgram['approved_count'] ?? 0) >= (int)$lockedProgram['slots']);
    if ($hasConcurrentApplication || $lockedProgramUnavailable) {
        $conn->rollback();
        $_SESSION['app_error'] = $hasConcurrentApplication
            ? 'Another active or pending application is already recorded for your account.'
            : 'This program batch is no longer accepting applications.';
        header('Location: programs.php'); exit();
    }

    $sql = "INSERT INTO beneficiaries ($columns, created_at, updated_at) VALUES ($placeholders, NOW(), NOW())";
    $stmt = $conn->prepare($sql);
    
    if($stmt) {
        $stmt->bind_param($types, ...$values);
        if ($stmt->execute()) {
            $new_beneficiary_id = (int)$stmt->insert_id;
            if ($isTupadApplication) {
                $documentError = null;
                $documentsSaved = save_tupad_details($conn, $new_beneficiary_id, $_POST);
                if (!$documentsSaved) $documentError = 'The TUPAD declaration could not be stored.';
                if ($documentsSaved) $documentsSaved = create_tupad_document_checklist($conn, $new_beneficiary_id, $needsFitnessCertificate);
                if (!$documentsSaved && $documentError === null) $documentError = 'The physical-document checklist could not be created.';
                if (!$documentsSaved) {
                    $conn->rollback();
                    $_SESSION['app_error'] = $documentError ?: 'Your TUPAD documents could not be stored.';
                    $stmt->close();
                    header('Location: programs.php'); exit();
                }
            }
            if (!record_privacy_acknowledgment($conn, (int)$user_id, 'program_application', $new_beneficiary_id)) {
                $conn->rollback();
                $_SESSION['app_error'] = 'Your application could not be recorded. Please try again.';
                $stmt->close();
                header('Location: programs.php');
                exit();
            }
            if (!$conn->commit()) {
                $conn->rollback();
                $_SESSION['app_error'] = 'Your application could not be finalized. Please try again.';
                $stmt->close();
                header('Location: programs.php');
                exit();
            }
            $_SESSION["app_success"] = $isSpesApplication && $is_spes_returning
                ? "Your updated SPES form was submitted for review. As a returning SPES Baby, you do not need to take the SPES examination again. Please prepare your latest semester grades and the other documents required by PESO Vinzons."
                : "Your application was submitted and is pending PESO review. Please wait for an official update before visiting the office or submitting documents.";
            if ($isTupadApplication && $needsFitnessCertificate) {
                $_SESSION["app_success"] .= " If approved, bring a fitness-to-work certificate.";
            }
            
            $p_id = (int)$_POST['program_id'];
            $p_name = "Program";
            $pn_stmt = $conn->prepare("SELECT program_name FROM programs WHERE program_id = ?");
            $pn_stmt->bind_param("i", $p_id);
            $pn_stmt->execute();
            $pn_res = $pn_stmt->get_result()->fetch_assoc();
            if($pn_res) $p_name = $pn_res['program_name'];

            if ($isSpesApplication) {
                $receiptEmail = trim((string)($user_data['email'] ?? ''));
                if ($receiptEmail !== '' && strpos(strtolower($receiptEmail), 'no email') === false) {
            $safeApplicantName = htmlspecialchars(benepeso_recipient_name((string)$first_name, (string)$user_display_name), ENT_QUOTES, 'UTF-8');
                    $safeProgramName = htmlspecialchars($p_name, ENT_QUOTES, 'UTF-8');
                    if ($is_spes_returning) {
                        $receiptBody = "<p>Dear <strong>{$safeApplicantName}</strong>,</p><p>PESO Vinzons received your updated SPES form for <strong>{$safeProgramName}</strong>.</p><p>You are recognized as a <strong>SPES Baby</strong>, so you do not need to take the SPES examination again. Please prepare and submit your latest semester grades and all other current documentary requirements when instructed by PESO Vinzons.</p><p>Keep your profile and SPES form information updated while your record is under review.</p>";
                        sendBENEPESOEmail($receiptEmail, "SPES Baby Form Update Received: {$p_name}", 'Your updated SPES form is under review', $receiptBody);
                    } else {
                        $receiptBody = "<p>Dear <strong>{$safeApplicantName}</strong>,</p><p>PESO Vinzons has received your application for <strong>{$safeProgramName}</strong>. Its current status is <strong>Pending Review</strong>.</p><p>No office visit is required at this stage. If you qualify for the next step, PESO Vinzons will send the examination schedule and documentary requirements by email or account notification.</p>";
                        sendBENEPESOEmail($receiptEmail, "SPES Application Received: {$p_name}", 'Your SPES application is pending review', $receiptBody);
                    }
                }
            } elseif ($isMsmeApplication) {
                $receiptEmail = trim((string)($user_data['email'] ?? ''));
                if ($receiptEmail === '') $receiptEmail = trim((string)($_POST['business_email'] ?? ''));
                if (filter_var($receiptEmail, FILTER_VALIDATE_EMAIL)) {
            $safeApplicantName = htmlspecialchars(benepeso_recipient_name((string)$first_name, (string)$user_display_name), ENT_QUOTES, 'UTF-8');
                    $safeProgramName = htmlspecialchars($p_name, ENT_QUOTES, 'UTF-8');
                    $receiptBody = "<p>Dear <strong>{$safeApplicantName}</strong>,</p><p>PESO Vinzons has received your application for <strong>{$safeProgramName}</strong>. Its current status is <strong>Pending Review</strong>.</p><p>The office will verify the submitted business information. Please wait for an official email or account update before taking further action.</p>";
                    sendBENEPESOEmail($receiptEmail, "MSME Application Received: {$p_name}", 'Your MSME application is pending review', $receiptBody);
                }
            } elseif ($isTupadApplication) {
                $receiptEmail = trim((string)($user_data['email'] ?? ''));
                if (filter_var($receiptEmail, FILTER_VALIDATE_EMAIL)) {
            $safeApplicantName = htmlspecialchars(benepeso_recipient_name((string)$first_name, (string)$user_display_name), ENT_QUOTES, 'UTF-8');
                    $safeProgramName = htmlspecialchars($p_name, ENT_QUOTES, 'UTF-8');
                    $receiptBody = "<p>Dear <strong>{$safeApplicantName}</strong>,</p><p>PESO Vinzons has received your application for <strong>{$safeProgramName}</strong>. Its current status is <strong>Pending Review</strong>.</p><p>Do not submit physical documents while the application is pending. If approved, PESO Vinzons will advise you when to visit the office and which original documents and photocopies to bring for verification.</p>";
                    sendBENEPESOEmail($receiptEmail, "TUPAD Application Received: {$p_name}", 'Your TUPAD application is pending review', $receiptBody);
                }
            }

            $log_desc = "Successfully applied for " . $p_name . ".";
            $l_stmt = $conn->prepare("INSERT INTO activity_logs (actor_name, actor_role, module_name, action_type, target_name, description, created_at) VALUES (?, 'Registered User', 'Programs', 'APPLY', ?, ?, NOW())");
            $l_stmt->bind_param("sss", $user_display_name, $p_name, $log_desc);
            $l_stmt->execute();

        } else {
            $conn->rollback();
            $_SESSION["app_error"] = "Error saving application. Please try again.";
        }
        $stmt->close();
    } else {
        $conn->rollback();
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
          AND (UPPER(p.program_name) LIKE '%TUPAD%' OR UPPER(p.program_name) LIKE '%SPES%' OR UPPER(p.program_name) LIKE '%MSME%')
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
        $user_approval = strtolower(trim((string)($row['user_approval_status'] ?? '')));
        $user_availment = strtolower(trim((string)($row['user_availment_status'] ?? '')));
        $is_user_current = in_array($user_approval, ['pending', 'approved'], true)
            && !in_array($user_availment, ['completed', 'not qualified', 'cancelled', 'exam failed'], true);
        
        $is_program_concluded = $is_full || $has_ended || $has_invalid_schedule;

        // Keep the user's exact pending/active batch available for status tracking,
        // even if its public schedule has concluded. All other concluded batches
        // belong exclusively in the public archive summary.
        if ($is_user_current) {
            $active_programs[] = $row;
        } elseif ($is_program_concluded) {
            $completed_programs[] = $row;
        } else {
            $active_programs[] = $row;
        }
    }
}
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
    
    <link rel="stylesheet" href="home.css?v=17">
    <link rel="stylesheet" href="programs.css?v=38">
<link rel="stylesheet" href="frontend_polish.css?v=17">
<link rel="stylesheet" href="beneficiary_responsive.css?v=10">
    <link rel="stylesheet" href="beneficiary_content_enhancements.css?v=1">
    <link rel="stylesheet" href="beneficiary_content_polish.css?v=9">
    <link rel="stylesheet" href="authenticated_experience.css?v=6">
<script src="frontend_polish.js?v=19" defer></script>
    <script src="beneficiary_content_polish.js?v=1" defer></script>
</head>
<body class="beneficiary-programs-page">

<header class="topbar">
  <div class="topbar-inner">
    <a class="brand-area" href="home.php">
      <img class="brand-logo" src="img/pesologo.png" alt="PESO Logo" onerror="this.style.display='none'">
      <div class="brand-name">
        <div class="brand-title">BENEPESO</div>
        <div class="brand-subtitle">PESO Vinzons</div>
      </div>
    </a>

    <button class="menu-button" id="menuButton" type="button" aria-label="Toggle menu" aria-controls="menuArea" aria-expanded="false">
      <span></span><span></span><span></span>
    </button>

    <nav class="menu-area" id="menuArea" aria-label="Resident navigation">
      <a class="menu-item" href="home.php">Home</a>
      <a class="menu-item active" href="programs.php" aria-current="page">Programs</a>
      <a class="menu-item" href="about.php">About</a>

      <?php if($is_logged_in): ?>
      <div class="account-area" id="accountWrap">
        <button class="account-button" id="accountButton" type="button" aria-label="Open account menu" aria-controls="accountDropdown" aria-expanded="false">
          <span class="account-icon">
            <?php echo htmlspecialchars($first_char); ?>
            <?php if ($user_profile_src !== ''): ?><img src="<?php echo h($user_profile_src); ?>" alt="" onerror="this.remove()"><?php endif; ?>
          </span>
          <span class="account-text"><?php echo htmlspecialchars($user_display_name); ?></span>
          <span class="account-arrow">▾</span>
        </button>

        <div class="account-dropdown" id="accountDropdown">
                    <a class="account-dropdown-link" href="profile.php"><span class="account-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg></span><span>My Profile</span></a>
                    <a class="account-dropdown-link" href="verification.php"><span class="account-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 4.5 6v5.5c0 4.6 3.1 7.9 7.5 9.5 4.4-1.6 7.5-4.9 7.5-9.5V6L12 3Z"></path><path d="m9 12 2 2 4-4"></path></svg></span><span>Verification</span></a>
          <div class="dropdown-line"></div>
          <form class="logout-form" action="logout.php" method="POST">
            <?= auth_csrf_input() ?><input type="hidden" name="role" value="user">
            <button class="logout-link" type="submit">
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M15 4h3a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3h-3" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <span>Log out</span>
            </button>
          </form>
        </div>
      </div>
      <?php else: ?>
        <a class="btn-login" href="login.php" style="margin-left: 10px;">Login</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="page-wrap">
    <section class="welcome-area programs-hero">
        <div class="welcome-inner content-wrap">
            <div class="welcome-left">
                <div class="welcome-badge"><span class="badge-dot"></span>OPPORTUNITIES AWAIT</div>
                <h1 class="welcome-title">Community <span class="welcome-highlight">Programs</span></h1>
                <p class="welcome-text">Discover verified PESO opportunities, review batch-specific requirements, and continue through one secure application service.</p>
                <div class="programs-hero-note">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4.5 6v5.5c0 4.6 3.1 7.9 7.5 9.5 4.4-1.6 7.5-4.9 7.5-9.5V6L12 3Z"></path><path d="m9 12 2 2 4-4"></path></svg>
                    <span><strong>Official PESO Vinzons listings</strong><small>Every schedule, requirement, and application status is connected to its exact batch.</small></span>
                </div>
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
                <div class="program-discovery-controls">
                    <div class="search-container">
                        <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <label class="sr-only" for="searchInput">Search programs by name</label>
                        <input type="search" id="searchInput" placeholder="Search TUPAD, SPES, or MSME" autocomplete="off" oninput="filterPrograms()">
                    </div>
                    <div class="schedule-filter-wrap">
                        <input type="hidden" id="scheduleFilter" value="all">
                        <button type="button" class="program-schedule-toggle" id="scheduleFilterToggle" aria-haspopup="listbox" aria-expanded="false" aria-controls="programScheduleMenu">
                            <svg class="program-schedule-leading" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg>
                            <span id="scheduleFilterLabel">All schedules</span>
                            <svg class="program-schedule-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"></path></svg>
                        </button>
                        <div class="program-schedule-menu" id="programScheduleMenu" role="listbox" aria-label="Filter programs by schedule" hidden>
                            <button type="button" role="option" data-value="all" aria-selected="true"><span class="program-filter-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg></span><span><strong>All schedules</strong><small>Show every active listing</small></span><span class="program-filter-check" aria-hidden="true"></span></button>
                            <button type="button" role="option" data-value="open" aria-selected="false"><span class="program-filter-icon"><svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-5"/></svg></span><span><strong>Open now</strong><small>Currently accepting applications</small></span><span class="program-filter-check" aria-hidden="true"></span></button>
                            <button type="button" role="option" data-value="upcoming" aria-selected="false"><span class="program-filter-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><span><strong>Coming soon</strong><small>Programs opening next</small></span><span class="program-filter-check" aria-hidden="true"></span></button>
                            <button type="button" role="option" data-value="ending" aria-selected="false"><span class="program-filter-icon program-filter-icon--gold"><svg viewBox="0 0 24 24"><path d="M12 3 3 20h18L12 3Z"/><path d="M12 9v5M12 17h.01"/></svg></span><span><strong>Ending soon</strong><small>Deadline within 14 days</small></span><span class="program-filter-check" aria-hidden="true"></span></button>
                            <button type="button" role="option" data-value="current" aria-selected="false"><span class="program-filter-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/><path d="m16 13 2 2 3-4"/></svg></span><span><strong>My current program</strong><small>Show your active or pending record</small></span><span class="program-filter-check" aria-hidden="true"></span></button>
                        </div>
                    </div>
                </div>
                <div class="program-filter-guidance">
                    <span class="program-results-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"></path></svg></span>
                    <strong id="programFilterStatus" aria-live="polite"></strong>
                </div>
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
                            $programSearchText = strtolower((string)($row['program_name'] ?? '') . ' ' . (string)($row['description'] ?? ''));
                            $serviceType = 'employment';
                            if (str_contains($programSearchText, 'spes') || str_contains($programSearchText, 'student')) $serviceType = 'student';
                            elseif (str_contains($programSearchText, 'tupad') || str_contains($programSearchText, 'livelihood') || str_contains($programSearchText, 'emergency')) $serviceType = 'livelihood';
                            elseif (str_contains($programSearchText, 'training') || str_contains($programSearchText, 'tesda') || str_contains($programSearchText, 'skill')) $serviceType = 'skills';
                            $serviceLabel = $serviceType === 'student' ? 'Employment for Students' : ucfirst($serviceType);
                            $programFamily = str_contains($programSearchText, 'tupad') ? 'tupad'
                                : (str_contains($programSearchText, 'spes') ? 'spes'
                                : (str_contains($programSearchText, 'msme') ? 'msme' : strtolower(trim((string)($row['program_name'] ?? 'program')))));
                            $daysUntilDeadline = !empty($row['end_date']) ? (int)floor((strtotime($row['end_date']) - strtotime(date('Y-m-d'))) / 86400) : 9999;
                            $isClosingSoon = $daysUntilDeadline >= 0 && $daysUntilDeadline <= 14;
                            $daysUntilStart = !empty($row['start_date']) ? (int)floor((strtotime($row['start_date']) - strtotime(date('Y-m-d'))) / 86400) : 0;
                            $isComingSoon = strtolower(trim((string)($row['status'] ?? ''))) === 'upcoming' || $daysUntilStart > 0;
                            $scheduleState = $isComingSoon ? 'upcoming' : ($isClosingSoon ? 'ending' : 'open');
                            $programUpdatedAt = $row['updated_at'] ?: $row['created_at'];
                            $missingProgramDetails = [];
                            foreach (['description' => 'description', 'eligibility' => 'eligibility rules', 'requirements' => 'document requirements', 'venue' => 'venue', 'start_date' => 'program start date', 'end_date' => 'application deadline'] as $field => $label) {
                                if (trim((string)($row[$field] ?? '')) === '' || in_array($row[$field] ?? '', ['0000-00-00', 'TBA'], true)) $missingProgramDetails[] = $label;
                            }

                            $action_type = $user_status ? 'status' : 'apply';
                            $badge_class = $user_status ? 'slots-badge current-program' : (($remaining_slots <= 5) ? 'slots-badge warning' : 'slots-badge');
                            $badge_text = $user_status ? 'Your Current Program' : $remaining_slots . ' Slots';
                    ?>
                        <article class="program-card" 
                                 style="animation-delay: <?= $delay ?>s;"
                                 data-action="<?= $action_type ?>"
                                 data-prog-id="<?= $row['program_id'] ?>"
                                 data-title="<?= $safe_title ?>"
                                 data-family="<?= h($programFamily) ?>"
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
                                  data-service-type="<?= h($serviceType) ?>"
                                  data-schedule="<?= h($scheduleState) ?>"
                                 data-schedules="<?= h($scheduleState . ($user_status ? ' current' : '')) ?>"
                                 data-updated="<?= h(date('M d, Y', strtotime($programUpdatedAt))) ?>"
                                 data-document-schedule="Issued by PESO after application review"
                                 data-incomplete="<?= h(implode(', ', $missingProgramDetails)) ?>"
                                 data-spes-returning="<?= ($is_spes_returning && str_contains(strtoupper((string)$row['program_name']), 'SPES')) ? '1' : '0' ?>"
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
                                    <div class="program-card-schedule">
                                        <span><small>Program starts</small><strong><?= $safe_start ?></strong></span>
                                        <span><small>Application deadline</small><strong><?= $safe_end ?></strong></span>
                                    </div>
                                    
                                    <?php if ($user_status): ?>
                                        <button type="button" class="btn-check-status">View Your Status</button>
                                    <?php else: ?>
                                        <button type="button" class="program-btn">View Details</button>
                                    <?php endif; ?>
                                </div>
                                <div class="program-record-meta"><span><?= h($serviceLabel) ?></span><time datetime="<?= h(date('Y-m-d', strtotime($programUpdatedAt))) ?>">Updated <?= h(date('M d, Y', strtotime($programUpdatedAt))) ?> by PESO</time></div>
                                <?php if ($missingProgramDetails): ?><div class="program-information-warning">Some official details are not yet available: <?= h(implode(', ', $missingProgramDetails)) ?>.</div><?php endif; ?>
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

    <section class="program-guidance-section bp-content-module" aria-labelledby="applicationJourneyTitle">
        <div class="content-wrap">
            <div class="program-route-shell">
                <div class="content-enhancement-heading">
                    <span class="content-enhancement-eyebrow">Before you submit</span>
                    <h2 id="applicationJourneyTitle">Your Application Journey</h2>
                    <p>Automated eligibility is preliminary. PESO issues the final decision after validating your information and requirements.</p>
                </div>
                <ol class="application-journey" aria-label="Program application stages">
                    <li><span>1</span><strong>Profile review</strong><small>Confirm registered details.</small></li>
                    <li><span>2</span><strong>Eligibility</strong><small>Check the exact batch rules.</small></li>
                    <li><span>3</span><strong>Application</strong><small>Submit complete information.</small></li>
                    <li><span>4</span><strong>PESO validation</strong><small>Staff review your record.</small></li>
                    <li><span>5</span><strong>Program activity</strong><small>Follow official instructions.</small></li>
                </ol>
            </div>

            <div class="applicant-guide-grid">
                <article>
                    <span class="guide-card-code">READY</span>
                    <h3>Prepare before applying</h3>
                    <ul>
                        <li>Use the exact information shown on your valid documents.</li>
                        <li>Review requirements, venue, dates, and available slots.</li>
                        <li>Keep your email and mobile number active for updates.</li>
                    </ul>
                </article>
                <article>
                    <span class="guide-card-code">CHECK</span>
                    <h3>Important reminders</h3>
                    <ul>
                        <li>A preliminary match does not guarantee final approval.</li>
                        <li>Incomplete or inconsistent records may require validation.</li>
                        <li>Use My Profile to follow your recorded next action.</li>
                    </ul>
                </article>
            </div>

            <div class="program-faq-stack">
                <details class="program-faq"><summary>Can I apply to more than one active program?</summary><p>BENEPESO may prevent another application while you have a pending or active program record. Complete the current program or contact PESO if the record needs correction.</p></details>
                <details class="program-faq"><summary>What if my eligibility result uses incorrect information?</summary><p>Update editable information in My Profile. For identity or household corrections requiring validation, coordinate with PESO before applying again.</p></details>
                <details class="program-faq"><summary>Where can I see requirements and schedules?</summary><p>Open a program card for batch dates, venue, eligibility rules, and documentary requirements. After applying, check My Profile for the latest instruction.</p></details>
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
            <?php if (count($completed_programs) > 6): ?>
            <nav class="archive-pagination" id="archivePagination" aria-label="Completed program pages">
                <button type="button" id="archivePrevious">Previous</button>
                <span id="archivePageStatus" aria-live="polite"></span>
                <button type="button" id="archiveNext">Next</button>
            </nav>
            <?php endif; ?>
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
      <a href="about.php">About</a>
      <a href="verification.php">Verification</a>
      <a href="profile.php">Profile</a>
      <a href="privacy_notice.php">Privacy Notice</a>
    </div>
    <div class="footer-col">
      <div class="footer-head">Office</div>
      <div class="footer-text">Municipality of Vinzons, Camarines Norte</div>
      <div class="footer-text">Public Employment Service Office (PESO)</div>
      <a class="footer-contact-link" href="#peso-contact" data-contact-kind="email"><i class="fa-solid fa-envelope" aria-hidden="true"></i><span>lguvinzonspeso@gmail.com</span></a>
      <a class="footer-contact-link" href="#peso-contact" data-contact-kind="phone"><i class="fa-solid fa-phone" aria-hidden="true"></i><span>+63 947 997 1186</span></a>
      <a class="footer-contact-link" href="https://www.facebook.com/peso.vinzons" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-facebook" aria-hidden="true"></i><span>PESO Vinzons on Facebook</span></a>
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
        <h2 style="color:#1a6d41; margin-bottom:10px; font-weight:800;">Application Submitted</h2>
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
        <h2 id="alertTitle" style="color:#a32222; margin-bottom:10px;">Notice</h2>
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
        <div class="archive-summary-assurance" aria-label="Summary information">
            <span><i aria-hidden="true"></i>Verified PESO record</span>
            <span>Aggregate figures only</span>
        </div>

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
                <span>Approved records · relative scale</span>
            </div>
            <div id="archBarangayList" class="archive-barangay-list"></div>
        </section>

        <p class="archive-summary-note">This batch concluded in <strong id="archEnd"></strong>. Figures reflect approved beneficiaries recorded by PESO.</p>
        <button class="btn-secondary archive-summary-close" onclick="closeModal('archiveModal')">Close Summary</button>
    </div>
</div>

<!-- MULTIPLE ACTIVE BATCH CHOOSER -->
<div class="modal" id="batchChooserModal" role="dialog" aria-modal="true" aria-labelledby="batchChooserTitle" aria-hidden="true">
    <div class="modal-content batch-chooser-dialog">
        <button type="button" class="modal-close" onclick="closeModal('batchChooserModal')" aria-label="Close batch choices">&times;</button>
        <span class="batch-chooser-kicker">Choose your schedule</span>
        <h2 id="batchChooserTitle">Available Batches</h2>
        <p class="batch-chooser-intro">This program has more than one active batch. Review the dates and remaining slots before continuing.</p>
        <div class="batch-choice-list" id="batchChoiceList"></div>
    </div>
</div>

<!-- PROGRAM DETAILS MODAL (First step before applying) -->
<div class="modal" id="programDetailsModal" aria-hidden="true">
    <div class="modal-content details-box program-details-dialog" role="dialog" aria-modal="true" aria-labelledby="detTitle" tabindex="-1">
        <div class="program-details-split">
            <aside class="program-details-identity">
                <div class="program-details-image-wrap">
                    <img id="detImage" src="img/pesologo.png" alt="" onerror="this.onerror=null;this.src='img/pesologo.png';">
                </div>
                <div class="details-header">
                    <span class="program-details-kicker">Official PESO program</span>
                    <div class="details-heading-row">
                        <h2 id="detTitle"></h2>
                    </div>
                    <div class="batch-code" id="detBatch"></div>
                    <span class="slots-badge" id="detBadge"></span>
                </div>
                <ol class="program-details-path" aria-label="Application steps">
                    <li><b>01</b><span><strong>Review</strong><small>Confirm the batch details</small></span></li>
                    <li><b>02</b><span><strong>Check</strong><small>Verify preliminary eligibility</small></span></li>
                    <li><b>03</b><span><strong>Apply</strong><small>Submit your official form</small></span></li>
                </ol>
                <div class="program-details-trust"><span aria-hidden="true"></span><div><strong>Verified listing</strong><small>Maintained by PESO Vinzons</small></div></div>
            </aside>

            <section class="program-details-content">
                <button type="button" class="modal-close" onclick="closeModal('programDetailsModal')" aria-label="Close program details">&times;</button>
                <div class="program-details-scroll">
                    <div class="program-details-review-heading"><span><i aria-hidden="true"></i>Program details</span><strong>Review before you apply</strong></div>
                    <section class="program-details-section" aria-labelledby="programDescriptionTitle">
                        <h3 id="programDescriptionTitle">Description</h3>
                        <p id="detDesc"></p>
                    </section>

                    <div class="program-schedule-panel">
                        <div class="program-schedule-grid">
                            <div class="program-schedule-item"><div class="program-schedule-label">Program activity starts</div><div id="detStart" class="program-schedule-value"></div></div>
                            <div class="program-schedule-item"><div class="program-schedule-label">Application deadline</div><div id="detEnd" class="program-schedule-value"></div></div>
                            <div class="program-schedule-item"><div class="program-schedule-label">Venue</div><div id="detVenue" class="program-schedule-value"></div></div>
                            <div class="program-schedule-item"><div class="program-schedule-label">Document submission</div><div id="detDocumentSchedule" class="program-schedule-value"></div></div>
                        </div>
                    </div>
                    <div class="program-detail-record-meta"><span id="detUpdated"></span><strong>Official program record maintained by PESO Vinzons</strong></div>
                    <div id="detIncomplete" class="program-information-warning program-detail-warning" hidden></div>

                    <section class="program-details-section">
                        <h3>Eligibility Rules</h3>
                        <ul id="detEligibility" class="program-detail-list"></ul>
                    </section>
                    <section class="program-details-section">
                        <h3>Documentary Requirements</h3>
                        <ul id="detReqs" class="program-detail-list"></ul>
                    </section>
                    <div class="preliminary-eligibility-note">
                        <strong>Preliminary eligibility only</strong>
                        <span>Meeting the displayed rules allows you to proceed with an application but does not guarantee final approval. PESO will validate the submitted information and requirements.</span>
                    </div>
                </div>

                <div class="details-footer" id="detFooter">
                    <div class="program-details-next"><strong>Ready to continue?</strong><span>Your registered profile will be checked first.</span></div>
                    <div id="detAction"></div>
                </div>
            </section>
        </div>
    </div>
</div>

<div class="modal" id="spesBabyModal" role="dialog" aria-modal="true" aria-labelledby="spesBabyTitle" aria-hidden="true">
    <div class="modal-content spes-baby-dialog">
        <button type="button" class="modal-close" onclick="closeModal('spesBabyModal')" aria-label="Close">&times;</button>
        <div class="spes-baby-emblem" aria-hidden="true"><span>SPES</span><strong>BABY</strong></div>
        <span class="spes-baby-kicker">Returning beneficiary</span>
        <h2 id="spesBabyTitle">Welcome back, SPES Baby!</h2>
        <p>You have already completed SPES at least once. You do not need to take the qualifying examination again.</p>
        <div class="spes-baby-checklist">
            <strong>Your next step</strong>
            <span>Review and update your SPES form</span>
            <span>Prepare your latest semester grades</span>
            <span>Submit the current documents required by PESO Vinzons</span>
        </div>
        <p class="spes-baby-note">Your updated record will still be reviewed for the current batch. Wait for PESO's document-submission instructions before visiting the office.</p>
        <button type="button" class="btn-primary" id="continueSpesBaby" style="width:100%;">Continue to SPES details</button>
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

<div class="modal" id="applicationModal" aria-hidden="true">
    <div class="modal-content" style="max-width: 850px;" role="dialog" aria-modal="true" aria-labelledby="applicationFormTitle" tabindex="-1">
        <button class="modal-close" onclick="closeModal('applicationModal')" aria-label="Close application form">✕</button>
        <div class="application-modal-heading">
            <span class="application-modal-eyebrow"><i aria-hidden="true"></i>Secure resident application</span>
            <h2 id="applicationFormTitle">Application Form</h2>
            <p id="applicationFormSubtitle">Applying for: <strong id="formProgramName"></strong></p>
        </div>

        <div class="wizard-nav" id="wizardNav">
            <!-- Populated via JS -->
        </div>
        <div class="application-progress-summary">
            <strong id="applicationStepStatus">Step 1</strong>
            <span>Complete the required fields marked with an asterisk.</span>
        </div>

        <form method="POST" action="programs.php" id="multiStepForm" autocomplete="off">
            <?= auth_csrf_input() ?>
            <input type="hidden" name="action" value="submit_application">
            <input type="hidden" name="program_id" id="hiddenProgramId">

            <!-- STEP 1: SHARED PROFILE -->
            <div class="form-step active" id="step-1">
                <div class="form-grid">
                    <div class="span-2 section-title">Basic Information</div>
                    <div class="form-group"><label>First Name</label><input type="text" value="<?php echo h($user_data['first_name']??''); ?>" readonly required></div>
                    <div class="form-group"><label>Last Name</label><input type="text" value="<?php echo h($user_data['last_name']??''); ?>" readonly required></div>
                    <div class="form-group"><label>Full Address</label><input type="text" value="<?php echo h($full_address); ?>" readonly required></div>
                    <div class="form-group"><label>Contact No.</label><input type="text" value="<?php echo h($user_data['contact_no']??''); ?>" readonly required></div>
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
                        <div class="form-group"><label>Avg Monthly Income</label><input type="text" name="avg_monthly_income" placeholder="e.g. 5000" inputmode="numeric" autocomplete="off" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></div>
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
                        <div class="span-2 section-title">Fitness Declaration</div>
                        <div class="form-group"><label>Are you currently pregnant?</label><select name="tupad_is_pregnant" id="tupad_is_pregnant" onchange="toggleTupadFitnessCertificate()"><option value="">--Select--</option><option value="Yes">Yes</option><option value="No">No</option><option value="Not Applicable">Not applicable</option></select></div>
                        <div class="form-group"><label>Are you a Person with Disability (PWD)?</label><select name="tupad_is_pwd" id="tupad_is_pwd" onchange="toggleTupadFitnessCertificate()"><option value="">--Select--</option><option value="Yes">Yes</option><option value="No">No</option></select></div>
                        <div class="form-group tupad-fitness-field"><label>Do you have a condition or work limitation requiring accommodation?</label><select name="tupad_has_work_limitation" id="tupad_has_work_limitation" onchange="toggleTupadFitnessCertificate()"><option value="">--Select--</option><option value="Yes">Yes</option><option value="No">No</option></select></div>
                        <div class="form-group tupad-fitness-field"><label>Are you able and willing to perform assigned work, with reasonable accommodation if needed?</label><select name="tupad_capable_of_work" id="tupad_capable_of_work" onchange="toggleTupadFitnessCertificate()"><option value="">--Select--</option><option value="Yes">Yes</option><option value="No">No</option></select></div>
                        <div class="form-group span-2"><small id="tupad_fitness_notice">Pregnant applicants are not eligible for TUPAD. PWD applicants, including applicants with speech impairment, may apply as long as they are capable of working.</small></div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(3)">Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(3)">Next Step</button>
                    </div>
                </div>
                <div class="form-step" id="tupad-step-4">
                    <div class="form-grid">
                        <div class="span-2 section-title">Applicant Confirmation</div>
                        <div class="form-group span-2"><small>Review and confirm your declaration before submitting. Your application will remain pending until PESO completes its review.</small></div>
                    </div>
                    <label class="privacy-acknowledgment"><input type="checkbox" name="tupad_certified_truthful" value="1"><span>I certify that my TUPAD information is true and complete and consent to PESO/DOLE validation for this application.</span></label>
                    <label class="privacy-acknowledgment">
                        <input type="checkbox" name="privacy_acknowledgment" value="1">
                        <span>I have read and understood the <button type="button" class="privacy-notice-link" onclick="openApplicationPrivacyNotice()">Privacy Notice</button> and understand how my information will be processed for this program application.</span>
                    </label>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(4)">Back</button>
                        <button type="submit" class="btn-primary" id="spesSubmitButton">Submit Application</button>
                    </div>
                </div>
            </div>

            <!-- ===================== SPES WIZARD ===================== -->
            <div id="spesWrapper" style="display:none;">
                <div class="form-step" id="spes-step-2">
                    <div class="form-grid">
                        <div class="span-2 section-title">Additional Details</div>
                        <div class="form-group" style="order:1;"><label>GSIS Beneficiary Name / Policy No. (If applicable)</label><input type="text" name="gsis_beneficiary" class="not-required" placeholder="Leave blank if not applicable" oninput="toggleSpesGsisRelationship(this)"></div>
                        <div class="form-group" id="spes_citizenship_wrap" style="order:2;"><label>Citizenship</label><input type="text" name="citizenship" value="Filipino"></div>
                        <div class="form-group" id="spes_gsis_relationship_wrap" hidden style="display:none;order:2;"><label>Relationship to GSIS Beneficiary</label><select name="gsis_relationship" class="not-required" onchange="toggleSpesGsisOther(this)"><option value="">--Select relationship--</option><option value="Father">Father</option><option value="Mother">Mother</option><option value="Guardian">Guardian</option><option value="Spouse">Spouse</option><option value="Others">Others</option></select><input type="text" name="other_gsis_relationship" id="other_gsis_relationship" class="not-required" style="display:none;margin-top:5px" placeholder="Specify relationship"></div>
                        <?php $spesBirthMunicipality = trim((string)($user_data['municipality'] ?? '')) ?: 'Vinzons'; $spesBirthProvince = trim((string)($user_data['district'] ?? '')) ?: 'Camarines Norte'; ?>
                        <div class="form-group" style="order:3;"><label>Place of Birth</label><input type="text" name="place_of_birth" id="spes_place_of_birth"><label style="display:flex;align-items:center;gap:8px;margin-top:8px;font-size:12px;font-weight:500;"><input type="checkbox" class="not-required" onchange="toggleSpesBirthplace(this)" data-birthplace="<?php echo h($spesBirthMunicipality . ', ' . $spesBirthProvince); ?>" style="width:auto;"> Same as my registered municipality and province</label></div>
                        <div class="form-group" style="order:4;"><label>Social Media URLs (Optional)</label><input type="text" name="social_urls" class="not-required" placeholder="Facebook, LinkedIn..."></div>
                        <div class="form-group" style="order:6;"><label>Email</label><input type="email" value="<?php echo h($user_data['email']??''); ?>" readonly></div>
                        <div class="form-group" style="order:6;"><label>Date of Birth</label><input type="text" value="<?php echo h($user_data['birthdate']??''); ?>" readonly></div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(2)">Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(2)">Next Step</button>
                    </div>
                </div>

                <div class="form-step" id="spes-step-3">
                    <div class="form-grid">
                        <div class="span-2 section-title">Applicant Status</div>
                        <div class="form-group"><label>Civil Status</label><select name="owner_civil_status" required><option value="Single" <?php if(($user_data['civil_status']??'')==='Single') echo 'selected'; ?>>Single</option><option value="Married" <?php if(($user_data['civil_status']??'')==='Married') echo 'selected'; ?>>Married</option><option value="Widowed" <?php if(($user_data['civil_status']??'')==='Widowed') echo 'selected'; ?>>Widowed</option><option value="Legally Separated" <?php if(($user_data['civil_status']??'')==='Legally Separated') echo 'selected'; ?>>Separated</option></select></div>
                        <div class="form-group"><label>Sex</label><input type="text" value="<?php echo h($user_data['sex']??''); ?>" readonly></div>
                        <div class="form-group span-2"><label>Are you currently pregnant?</label><select name="spes_is_pregnant" id="spes_is_pregnant" onchange="validateSpesPregnancy(this)"><option value="">--Select--</option><option value="Yes">Yes</option><option value="No">No</option><option value="Not Applicable">Not applicable</option></select><small id="spesPregnancyNotice" style="display:none;color:#a32222;font-weight:600;margin-top:7px;">Pregnant applicants are not eligible for SPES.</small></div>
                        <div class="form-group span-2"><label>Student Status</label>
                            <select name="spes_type" id="spes_type" onchange="validateSpesYearLevel(document.querySelector('[name=&quot;tert_year_level&quot;]'))">
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
                        <div class="form-group"><label>Elementary School Name</label><input type="text" name="elem_school"></div>
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
                        <div class="form-group"><label>Inclusive Dates of Attendance</label><input type="text" name="elem_date_attendance" placeholder="e.g. 2010-2016" oninput="this.value = this.value.replace(/[^0-9\s-]/g, '')"></div>
                        <div class="span-2 divider-line"></div>
                        
                        <!-- Secondary (JHS & SHS combined) -->
                        <div class="form-group"><label>Secondary / Senior High School Name</label><input type="text" name="sec_school"></div>
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
                        <div class="form-group"><label>Inclusive Dates of Attendance</label><input type="text" name="sec_date_attendance" placeholder="e.g. 2016-2022" oninput="this.value = this.value.replace(/[^0-9\s-]/g, '')"></div>
                        <div class="span-2 divider-line"></div>
                        
                        <!-- Tertiary -->
                        <div class="form-group"><label>Tertiary School Name (Put N/A if none)</label><input type="text" name="tert_school" class="not-required" placeholder="Put N/A if none"></div>
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
                            <label>Current College Year Level</label>
                            <select name="tert_year_level" class="not-required" data-eligibility-check onchange="validateSpesYearLevel(this)">
                                <option value="N/A">N/A</option>
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                                <option value="5th Year">5th Year</option>
                                <option value="Graduated">Graduated</option>
                            </select>
                            <small id="spesFourthYearNotice" style="display:none;color:#a32222;font-weight:600;margin-top:7px;">Fourth-year college students are not eligible for this PESO Vinzons SPES batch.</small>
                        </div>
                        <div class="form-group"><label>Inclusive Dates of Attendance</label><input type="text" name="tert_date_attendance" class="not-required" placeholder="e.g. 2022-2026" oninput="this.value = this.value.replace(/[^0-9\s-]/g, '')"></div>
                        <div class="span-2 divider-line"></div>
                        
                        <!-- Tech-Voc -->
                        <div class="form-group"><label>Tech-Voc School Name (Put N/A if none)</label><input type="text" name="tv_school" class="not-required" placeholder="Put N/A if none"></div>
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
                        <div class="form-group"><label>Date of Attendance</label><input type="text" name="tv_date_attendance" class="not-required" placeholder="e.g. 2023 or N/A"></div>
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
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(6)">Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(6)">Next Step</button>
                    </div>
                </div>

                <div class="form-step" id="spes-step-7">
                    <div class="form-grid">
                        <div class="span-2 section-title">Applicant Confirmation</div>
                        <div class="form-group span-2"><small>Review and confirm your SPES information before submitting. Your application will remain pending until PESO completes its review and provides the next instructions.</small></div>
                    </div>
                    <label class="privacy-acknowledgment"><input type="checkbox" name="spes_certified_truthful" value="1"><span>I certify that my SPES information is true and complete and consent to PESO/DOLE validation for this application.</span></label>
                    <label class="privacy-acknowledgment">
                        <input type="checkbox" name="privacy_acknowledgment" value="1">
                        <span>I have read and understood the <button type="button" class="privacy-notice-link" onclick="openApplicationPrivacyNotice()">Privacy Notice</button> and understand how my information will be processed for this program application.</span>
                    </label>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(7)">Back</button>
                        <button type="submit" class="btn-primary">Submit Application</button>
                    </div>
                </div>
            </div>

            <!-- ===================== MSME WIZARD ===================== -->
            <div id="msmeWrapper" style="display:none;">
                <div class="form-step" id="msme-step-2">
                    <div class="form-grid">
                        <div class="span-2 section-title">Business Profile</div>
                        <div class="form-group"><label>Business/Trade Name</label><input type="text" name="business_name"></div>
                        <div class="form-group"><label>Type of Ownership</label><select name="ownership_type"><option value="">--Select--</option><?php render_beneficiary_options('ownership_type'); ?></select></div>
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

                        <div class="form-group"><label>Year Started</label><select name="year_started"><option value="">--Select year--</option><?php for ($year = (int)date('Y'); $year >= 1900; $year--): ?><option value="<?php echo $year; ?>"><?php echo $year; ?></option><?php endfor; ?></select></div>
                        <div class="form-group"><label>Business Permit No.</label><input type="text" name="business_permit_no"></div>
                        <div class="form-group"><label>Permit Valid Until</label><input type="date" name="permit_valid_until"></div>
                        <div class="form-group"><label>DTI Reg No.</label><input type="text" name="dti_no"></div>
                        <div class="form-group"><label>TIN</label><input type="text" name="tin_no" oninput="this.value = this.value.replace(/[^0-9-]/g, '')"></div>
                        <div class="form-group"><label>Business Email</label><input type="email" name="business_email" placeholder="business@example.com"></div>
                        <div class="form-group span-2"><label>Website / Social Media (Optional)</label><input type="text" name="business_social_media" class="not-required" data-text-input="true" autocomplete="url" placeholder="Website or Facebook page"></div>
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
                        <div class="form-group"><label>Full Name</label><input type="text" name="owner_full_name" value="<?php echo h($full_name); ?>"></div>
                        <div class="form-group"><label>Sex</label>
                            <select name="owner_sex">
                                <option value="Male" <?php if(($user_data['sex']??'')=='Male') echo 'selected'; ?>>Male</option>
                                <option value="Female" <?php if(($user_data['sex']??'')=='Female') echo 'selected'; ?>>Female</option>
                            </select>
                        </div>
                        <div class="form-group"><label>Contact No.</label><input type="text" name="owner_contact_no" value="<?php echo h($user_data['contact_no']??''); ?>" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></div>
                        <div class="form-group"><label>Date of Birth</label><input type="date" name="owner_birthdate" id="msme_owner_birthdate" max="<?php echo date('Y-m-d'); ?>" value="<?php echo h($user_data['birthdate']??''); ?>" onchange="syncMsmeOwnerAge()"></div>
                        <div class="form-group"><label>Age</label><input type="text" name="owner_age" id="msme_owner_age" value="<?php echo $userAge; ?>" readonly></div>
                        <div class="form-group"><label>Civil Status</label>
                            <select name="owner_civil_status">
                                <option value="Single" <?php if(($user_data['civil_status']??'')=='Single') echo 'selected'; ?>>Single</option>
                                <option value="Married" <?php if(($user_data['civil_status']??'')=='Married') echo 'selected'; ?>>Married</option>
                                <option value="Widowed" <?php if(in_array(($user_data['civil_status']??''), ['Widowed', 'Widow/er'], true)) echo 'selected'; ?>>Widowed</option>
                                <option value="Legally Separated" <?php if(($user_data['civil_status']??'')==='Legally Separated') echo 'selected'; ?>>Separated</option>
                            </select>
                        </div>
                        <div class="form-group">
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
                        <div class="form-group"><label>Full Address</label><input type="text" name="owner_full_address" value="<?php echo h($full_address); ?>"></div>
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
                            <input type="text" name="assets_owned[]" id="asset_other" data-text-input="true" style="display:none; margin-top:10px;" placeholder="Specify other assets" class="not-required">
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
                            <input type="text" name="utility_needs[]" id="util_other" data-text-input="true" style="display:none; margin-top:10px;" placeholder="Specify other utilities" class="not-required">
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
                            <input type="text" name="source_of_capital[]" id="cap_other" data-text-input="true" style="display:none; margin-top:10px;" placeholder="Specify other source" class="not-required">
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
                                <label><input type="checkbox" name="programs_needed[]" value="Business Registration Assistance"> Business Registration Assistance</label>
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
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(8)">Back</button>
                        <button type="button" class="btn-primary" onclick="nextStep(8)">Next Step</button>
                    </div>
                </div>

                <div class="form-step msme-confirmation-step" id="msme-step-9">
                    <div class="form-grid">
                        <div class="span-2 section-title">Applicant Confirmation</div>
                        <div class="form-group span-2"><small>Review your MSME information before submitting. Your application will remain pending until PESO Vinzons completes its assessment.</small></div>
                    </div>
                    <label class="privacy-acknowledgment"><input type="checkbox" name="msme_certified_truthful" value="1"><span>I certify that the MSME and business information I provided is true and complete and consent to PESO validation.</span></label>
                    <label class="privacy-acknowledgment">
                        <input type="checkbox" name="privacy_acknowledgment" value="1">
                        <span>I have read and understood the <button type="button" class="privacy-notice-link" onclick="openApplicationPrivacyNotice()">Privacy Notice</button> and understand how my information will be processed for this program application.</span>
                    </label>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="prevStep(9)">Back</button>
                        <button type="submit" class="btn-primary">Submit Application</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="applicationPrivacyModal" role="dialog" aria-modal="true" aria-labelledby="applicationPrivacyTitle" aria-hidden="true">
    <div class="modal-content application-privacy-dialog">
        <button type="button" class="modal-close" onclick="closeApplicationPrivacyNotice()" aria-label="Close privacy notice">✕</button>
        <h2 id="applicationPrivacyTitle">Privacy Notice</h2>
        <p>Review how PESO Vinzons processes and protects your application information.</p>
        <iframe src="privacy_notice.php?embedded=1" title="PESO Vinzons Privacy Notice"></iframe>
        <div class="application-privacy-actions"><button type="button" class="btn-primary" onclick="closeApplicationPrivacyNotice()">Return to Application</button></div>
    </div>
</div>

<script>
    const accountButton = document.getElementById('accountButton');
    const accountDropdown = document.getElementById('accountDropdown');
    if(accountButton) {
        accountButton.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = accountDropdown.classList.toggle('show');
            accountButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }
    window.addEventListener('click', () => {
        if(accountDropdown && accountDropdown.classList.contains('show')) {
            accountDropdown.classList.remove('show');
            accountButton?.setAttribute('aria-expanded', 'false');
        }
    });
    const menuButton = document.getElementById('menuButton');
    const menuArea = document.getElementById('menuArea');
    if(menuButton && menuArea) {
        menuButton.addEventListener('click', () => {
            const isOpen = menuArea.classList.toggle('open');
            menuButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    const scheduleFilterWrap = document.querySelector('.schedule-filter-wrap');
    const scheduleFilterToggle = document.getElementById('scheduleFilterToggle');
    const scheduleFilterMenu = document.getElementById('programScheduleMenu');
    const scheduleFilterInput = document.getElementById('scheduleFilter');
    const scheduleFilterLabel = document.getElementById('scheduleFilterLabel');

    function closeScheduleFilter(restoreFocus = false) {
        if (!scheduleFilterMenu || !scheduleFilterToggle) return;
        scheduleFilterMenu.hidden = true;
        scheduleFilterWrap?.classList.remove('is-open');
        scheduleFilterToggle.setAttribute('aria-expanded', 'false');
        if (restoreFocus) scheduleFilterToggle.focus();
    }

    scheduleFilterToggle?.addEventListener('click', event => {
        event.stopPropagation();
        const willOpen = scheduleFilterMenu.hidden;
        scheduleFilterMenu.hidden = !willOpen;
        scheduleFilterWrap?.classList.toggle('is-open', willOpen);
        scheduleFilterToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen) scheduleFilterMenu.querySelector('[aria-selected="true"]')?.focus();
    });

    scheduleFilterMenu?.querySelectorAll('[role="option"]').forEach(option => {
        option.addEventListener('click', () => {
            scheduleFilterInput.value = option.dataset.value || 'all';
            scheduleFilterLabel.textContent = option.querySelector('strong')?.textContent || 'All schedules';
            scheduleFilterMenu.querySelectorAll('[role="option"]').forEach(item => item.setAttribute('aria-selected', item === option ? 'true' : 'false'));
            closeScheduleFilter(true);
            filterPrograms();
        });
        option.addEventListener('keydown', event => {
            const options = Array.from(scheduleFilterMenu.querySelectorAll('[role="option"]'));
            const index = options.indexOf(option);
            if (event.key === 'ArrowDown') { event.preventDefault(); (options[index + 1] || options[0]).focus(); }
            if (event.key === 'ArrowUp') { event.preventDefault(); (options[index - 1] || options[options.length - 1]).focus(); }
            if (event.key === 'Escape') { event.preventDefault(); closeScheduleFilter(true); }
        });
    });

    document.addEventListener('click', event => {
        if (scheduleFilterWrap && !scheduleFilterWrap.contains(event.target)) closeScheduleFilter();
    });

    function updateProgramGridBalance() {
        const grid = document.getElementById('programGrid');
        if (!grid) return;
        const cards = Array.from(grid.querySelectorAll('.program-card:not(.program-batch-duplicate)'));
        cards.forEach(card => card.classList.remove('bp-grid-last-desktop', 'bp-grid-last-pair'));
        if (grid.classList.contains('list-view')) return;
        const visibleCards = cards.filter(card => !card.hidden && card.style.display !== 'none');
        const last = visibleCards[visibleCards.length - 1];
        if (!last) return;
        if (visibleCards.length % 3 === 1) last.classList.add('bp-grid-last-desktop');
        if (visibleCards.length % 2 === 1) last.classList.add('bp-grid-last-pair');
    }

    function filterPrograms() {
        let input = document.getElementById('searchInput');
        let filter = input.value.toLowerCase();
        let scheduleFilter = document.getElementById('scheduleFilter')?.value || 'all';
        let cards = document.querySelectorAll('.program-card:not(.program-batch-duplicate)');
        let grid = document.getElementById('programGrid');
        let hasMatch = false;
        let visibleCount = 0;
        
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
            const schedules = (cards[i].getAttribute('data-schedules') || cards[i].getAttribute('data-schedule') || 'open').split(/\s+/);
            const matchesSchedule = scheduleFilter === 'all'
                || (scheduleFilter === 'open' && (schedules.includes('open') || schedules.includes('ending')))
                || schedules.includes(scheduleFilter);
            if (matchesSearch && matchesSchedule) {
                cards[i].style.display = "flex";
                hasMatch = true;
                visibleCount++;
            } else {
                cards[i].style.display = "none";
            }
        }

        const noMatchMsg = document.getElementById('noSearchMatch');
        if (noMatchMsg) {
            noMatchMsg.style.display = hasMatch ? "none" : "block";
        }
        const filterStatus = document.getElementById('programFilterStatus');
        if (filterStatus) {
            filterStatus.textContent = hasMatch
                ? `${visibleCount} official ${visibleCount === 1 ? 'program' : 'programs'} available`
                : 'No official programs match these filters';
        }
        updateProgramGridBalance();
    }

    let activeProgramId = 0, activeProgramName = "", currentFormType = "tupad", totalSteps = 3;
    const isReturningSpesBeneficiary = <?= $is_spes_returning ? 'true' : 'false' ?>;
    const spesPreviousDetails = <?= json_encode($spes_prefill, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let pendingSpesCard = null;
    let spesWelcomeAcknowledged = false;

    document.getElementById('continueSpesBaby')?.addEventListener('click', function () {
        const card = pendingSpesCard;
        spesWelcomeAcknowledged = true;
        closeModal('spesBabyModal');
        if (card) openProgramDetails(card);
    });

    function prefillReturningSpesForm() {
        if (!isReturningSpesBeneficiary || !spesPreviousDetails) return;
        const form = document.getElementById('multiStepForm');
        const fields = {
            gsis_beneficiary: 'gsis_beneficiary_name', gsis_relationship: 'gsis_relationship',
            citizenship: 'citizenship', place_of_birth: 'place_of_birth', social_urls: 'social_media',
            spes_type: 'spes_type', spes_is_pregnant: 'spes_is_pregnant', permanent_address: 'permanent_address',
            father_name: 'father_name', father_contact: 'father_contact', father_occupation: 'father_occupation',
            mother_name: 'mother_name', mother_contact: 'mother_contact', mother_occupation: 'mother_occupation',
            elem_school: 'elem_school', elem_degree: 'elem_degree', elem_year_level: 'elem_year_level', elem_date_attendance: 'elem_date_attendance',
            sec_school: 'sec_school', sec_degree: 'sec_degree', sec_year_level: 'sec_year_level', sec_date_attendance: 'sec_date_attendance',
            tert_school: 'tert_school', tert_course: 'tert_course', tert_year_level: 'tert_year_level', tert_date_attendance: 'tert_date_attendance',
            tv_school: 'tv_school', tv_course: 'tv_course', tv_year_level: 'tv_year_level', tv_date_attendance: 'tv_date_attendance',
            special_skills: 'special_skills'
        };
        Object.entries(fields).forEach(([name, column]) => {
            const input = form?.querySelector(`[name="${name}"]`);
            const value = String(spesPreviousDetails[column] ?? '').trim();
            if (!input || value === '') return;
            if (input.tagName === 'SELECT' && !Array.from(input.options).some(option => option.value === value)) return;
            input.value = value;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        const parentStatuses = String(spesPreviousDetails.parents_status || '').split(',').map(value => value.trim().toLowerCase());
        form?.querySelectorAll('[name="spes_parent_status[]"]').forEach(input => {
            input.checked = parentStatuses.includes(String(input.value).trim().toLowerCase());
        });
    }
    
    let lastModalTrigger = null;
    document.addEventListener('click', event => {
        if (event.target.closest('[onclick*="Modal"], [data-modal-target], .program-card')) {
            lastModalTrigger = event.target.closest('button, a, .program-card');
        }
    }, true);

    document.querySelectorAll('.modal').forEach(modal => {
        modal.setAttribute('aria-hidden', modal.classList.contains('show') ? 'false' : 'true');
        new MutationObserver(() => {
            modal.setAttribute('aria-hidden', modal.classList.contains('show') ? 'false' : 'true');
        }).observe(modal, { attributes: true, attributeFilter: ['class'] });
    });

    function closeModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        if (lastModalTrigger && document.contains(lastModalTrigger)) lastModalTrigger.focus();
    }

    function initializeProgramCollections() {
        const cards = Array.from(document.querySelectorAll('#programGrid .program-card'));
        const groups = new Map();

        cards.forEach(card => {
            const title = (card.dataset.title || '').trim().toLowerCase();
            const key = card.dataset.family || (title.includes('tupad') ? 'tupad'
                : title.includes('spes') ? 'spes'
                : title.includes('msme') ? 'msme'
                : title);
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(card);
        });

        groups.forEach(groupCards => {
            if (groupCards.length < 2) return;
            const representative = groupCards[0];
            groupCards.slice(1).forEach(card => {
                card.classList.add('program-batch-duplicate');
                card.hidden = true;
            });
            representative.removeAttribute('onclick');
            representative.classList.add('program-multi-batch');
            representative.setAttribute('role', 'button');
            representative.setAttribute('tabindex', '0');
            representative.setAttribute('aria-label', `${representative.dataset.title}: choose from ${groupCards.length} batch options`);
            representative.addEventListener('click', () => openBatchChooser(groupCards));
            representative.addEventListener('keydown', event => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openBatchChooser(groupCards);
                }
            });
            representative.dataset.category = groupCards.map(card => card.dataset.category || '').filter(Boolean).join(' ');
            representative.dataset.schedules = [...new Set(groupCards.flatMap(card => (card.dataset.schedules || card.dataset.schedule || 'open').split(/\s+/)))].join(' ');

            const batchLabel = representative.querySelector('.batch-code');
            if (batchLabel) batchLabel.textContent = `${groupCards.length} BATCHES`;
            representative.querySelector('.program-category-badge')?.remove();
            const slotBadge = representative.querySelector('.floating-badge');
            if (slotBadge) {
                slotBadge.className = 'slots-badge floating-badge grouped-batch-badge';
                slotBadge.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="12" height="12" rx="2"></rect><path d="M8 9h12v10a2 2 0 0 1-2 2H8V9Z"></path></svg><span>Choose batch</span>';
            }
            const schedulePanel = representative.querySelector('.program-card-schedule');
            if (schedulePanel) {
                schedulePanel.innerHTML = `<span><small>Batch options</small><strong>${groupCards.length} schedules</strong></span><span><small>Dates & status</small><strong>Review each batch</strong></span>`;
            }
            const actionButton = representative.querySelector('.program-btn, .btn-check-status');
            const currentCard = groupCards.find(card => card.dataset.action === 'status');
            if (currentCard) {
                representative.classList.add('has-current-batch');
                const statusNote = document.createElement('div');
                statusNote.className = 'program-current-batch-note';
                statusNote.innerHTML = '<span aria-hidden="true"></span>Your current application is included';
                representative.querySelector('.card-footer-info')?.before(statusNote);
            }
            if (actionButton) {
                actionButton.className = 'program-btn';
                actionButton.textContent = 'Choose Batch';
                actionButton.addEventListener('click', event => {
                    event.stopPropagation();
                    openBatchChooser(groupCards);
                });
            }
        });

        updateProgramGridBalance();
        filterPrograms();

        initializeArchivePagination();

        const requestedId = new URLSearchParams(window.location.search).get('program_id');
        if (requestedId) {
            const requestedCard = cards.find(card => card.dataset.progId === requestedId);
            if (requestedCard) window.setTimeout(() => openProgramDetails(requestedCard), 180);
        }
    }

    function openBatchChooser(cards) {
        const modal = document.getElementById('batchChooserModal');
        const list = document.getElementById('batchChoiceList');
        const title = document.getElementById('batchChooserTitle');
        if (!modal || !list || !cards.length) return;

        title.textContent = `${cards[0].dataset.title} Batches`;
        const currentBatch = cards.find(card => card.dataset.action === 'status');
        const intro = modal.querySelector('.batch-chooser-intro');
        if (intro) {
            intro.textContent = currentBatch
                ? 'Your current batch is highlighted first. You can view its status or review another available schedule.'
                : 'This program has more than one active batch. Review the dates and remaining slots before continuing.';
        }
        list.replaceChildren();
        const orderedCards = [...cards].sort((first, second) => Number(second.dataset.action === 'status') - Number(first.dataset.action === 'status'));
        orderedCards.forEach((card, index) => {
            const choice = document.createElement('button');
            choice.type = 'button';
            const hasStatus = card.dataset.action === 'status';
            choice.className = `batch-choice-card${hasStatus ? ' has-user-status' : ''}`;
            choice.setAttribute('aria-label', `${card.dataset.batch || 'Batch'}: ${hasStatus ? 'view your status' : `${card.dataset.slots || '0'} slots available`}`);
            choice.innerHTML = `<span class="batch-choice-number">${index + 1}</span><span class="batch-choice-copy"><strong>${escapeHtml(card.dataset.batch || 'Batch')}</strong><small>${escapeHtml(card.dataset.start || 'TBA')} – ${escapeHtml(card.dataset.end || 'TBA')}</small></span><span class="batch-choice-slots">${hasStatus ? 'View your status' : `${escapeHtml(card.dataset.slots || '0')} slots`}</span><span class="batch-choice-arrow" aria-hidden="true">→</span>`;
            choice.addEventListener('click', () => {
                closeModal('batchChooserModal');
                openProgramDetails(card);
            });
            list.appendChild(choice);
        });
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        window.setTimeout(() => list.querySelector('button')?.focus(), 80);
    }

    function initializeArchivePagination() {
        const items = Array.from(document.querySelectorAll('.completed-list .completed-item'));
        const previous = document.getElementById('archivePrevious');
        const next = document.getElementById('archiveNext');
        const status = document.getElementById('archivePageStatus');
        if (!previous || !next || !status || items.length <= 6) return;

        const perPage = 6;
        const pages = Math.ceil(items.length / perPage);
        let page = 1;
        const render = () => {
            items.forEach((item, index) => { item.hidden = index < (page - 1) * perPage || index >= page * perPage; });
            previous.disabled = page === 1;
            next.disabled = page === pages;
            status.textContent = `Page ${page} of ${pages}`;
        };
        previous.addEventListener('click', () => { if (page > 1) { page--; render(); } });
        next.addEventListener('click', () => { if (page < pages) { page++; render(); } });
        render();
    }

    function showProfessionalNotice(message, title = 'Information Required') {
        const modal = document.getElementById('alertModal');
        const titleElement = document.getElementById('alertTitle');
        const messageElement = document.getElementById('alertMessage');
        if (!modal || !messageElement) return;
        if (titleElement) titleElement.textContent = title;
        messageElement.textContent = message;
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        modal.querySelector('.btn-primary')?.focus();
    }

    function openApplicationPrivacyNotice() {
        const modal = document.getElementById('applicationPrivacyModal');
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        modal.querySelector('.modal-close')?.focus();
    }

    function closeApplicationPrivacyNotice() {
        const modal = document.getElementById('applicationPrivacyModal');
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.querySelector('.form-step.active .privacy-notice-link')?.focus();
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
        if(table.rows.length >= 10) { showProfessionalNotice('You may add up to 10 products or services only.', 'Product Limit Reached'); return; }
        let newRow = table.insertRow();
        newRow.innerHTML = `<td><input type="text" name="prod_name[]" placeholder="Item Name" required></td>
                            <td><input type="text" name="prod_price[]" placeholder="0.00" oninput="this.value = this.value.replace(/[^0-9.]/g, '')" required></td>
                            <td class="action-cell"><button type="button" class="btn-remove-row" onclick="removeRow(this)">✕</button></td>`;
    }

    function addSpesRow() {
        let table = document.getElementById('spesTable').getElementsByTagName('tbody')[0];
        if(table.rows.length >= 4) { showProfessionalNotice('You may add up to four SPES history records only.', 'History Limit Reached'); return; }
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
    function renderProgramDetailList(target, value, fallback) {
        const items = String(value || '')
            .replace(/\r/g, '')
            .trim()
            .split(/\n+|[•●▪]\s*|;\s*|(?:^|\s)[\-–—]\s+/g)
            .map(item => item.trim().replace(/^[\s\-–—•●▪,.:]+|[,;]+$/g, '').trim())
            .filter(item => item && !/^for\s+students?\s*:?$/i.test(item));

        target.replaceChildren();
        (items.length ? items : [fallback]).forEach(item => {
            const listItem = document.createElement('li');
            listItem.textContent = item;
            target.appendChild(listItem);
        });
    }

    function openProgramDetails(element) {
        const action = element.getAttribute('data-action');
        if (action === 'none') return;
        if (action === 'status') {
            viewStatus(
                (element.getAttribute('data-status') || '').trim().toLowerCase(),
                (element.getAttribute('data-availment') || '').trim().toLowerCase(),
                element.getAttribute('data-reason'),
                element.getAttribute('data-reqs'),
                element.getAttribute('data-venue'),
                element.getAttribute('data-title')
            );
            return;
        }
        if (element.getAttribute('data-spes-returning') === '1' && !spesWelcomeAcknowledged) {
            pendingSpesCard = element;
            const modal = document.getElementById('spesBabyModal');
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            return;
        }
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
        const documentSchedule = element.getAttribute('data-document-schedule') || 'Not yet announced';
        const updated = element.getAttribute('data-updated') || 'Date unavailable';
        const incomplete = element.getAttribute('data-incomplete') || '';
        const programImage = element.querySelector('.card-img-wrap img');
        
        const status = (element.getAttribute('data-status') || '').trim().toLowerCase();
        const availment = (element.getAttribute('data-availment') || '').trim().toLowerCase();
        const reason = element.getAttribute('data-reason');

        // Populate Details Modal
        document.getElementById('detTitle').innerText = title;
        const detailsImage = document.getElementById('detImage');
        detailsImage.src = programImage?.currentSrc || programImage?.src || 'img/pesologo.png';
        detailsImage.alt = title + ' program image';
        document.getElementById('detBatch').innerText = "BATCH: " + batch;
        document.getElementById('detDesc').innerText = desc;
        document.getElementById('detStart').innerText = startDate;
        document.getElementById('detEnd').innerText = endDate;
        document.getElementById('detVenue').innerText = venue;
        document.getElementById('detDocumentSchedule').innerText = documentSchedule;
        document.getElementById('detUpdated').innerText = 'Updated ' + updated;
        const incompleteTarget = document.getElementById('detIncomplete');
        incompleteTarget.hidden = incomplete === '';
        incompleteTarget.textContent = incomplete === '' ? '' : 'Some official information is not yet available: ' + incomplete + '. Check again later or contact PESO before visiting the office.';
        const requirementsTarget = document.getElementById('detReqs');
        renderProgramDetailList(requirementsTarget, reqs, 'No requirements specified.');
        const eligibilityTarget = document.getElementById('detEligibility');
        if (eligibilityTarget) renderProgramDetailList(eligibilityTarget, eligibility, 'No eligibility rules specified.');
        
        let badge = document.getElementById('detBadge');
        badge.innerText = slots + " Slots Available";
        badge.className = (slots <= 5) ? 'slots-badge warning' : 'slots-badge';

        let footer = document.getElementById('detAction');
        footer.replaceChildren();
        
        let btn = document.createElement('button');
        btn.className = "btn-primary";
        btn.style.width = "100%";

        if (action === 'status') {
            btn.innerText = "View Your Status";
            btn.onclick = function() {
                closeModal('programDetailsModal');
                viewStatus(status, availment, reason, reqs, venue, title);
            };
        } else {
            btn.innerText = element.getAttribute('data-spes-returning') === '1' ? "Review & Update SPES Form" : "Check Eligibility & Apply";
            btn.onclick = function() {
                // Set global active variables before checking eligibility
                activeProgramId = element.getAttribute('data-prog-id');
                activeProgramName = title;
                checkEligibility(activeProgramId, title, desc, startDate, endDate);
            };
        }
        
        footer.appendChild(btn);
        const detailsModal = document.getElementById('programDetailsModal');
        detailsModal.classList.add('show');
        detailsModal.setAttribute('aria-hidden', 'false');
        window.setTimeout(() => detailsModal.querySelector('.modal-close')?.focus(), 80);
        fetch('programs.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: new URLSearchParams({
                action: 'log_view',
                type: 'details',
                prog_name: title,
                csrf_token: <?= json_encode(auth_csrf_token()) ?>
            })
        }).catch(() => {});
    }

    function viewStatus(status, availment, reason, reqs, venue, title) {
        const modal = document.getElementById('statusModal');
        const modalTitle = document.getElementById('statusModalTitle');
        const modalBody = document.getElementById('statusModalBody');
        const modalIcon = document.getElementById('statusIcon');

        modalIcon.className = "modal-icon";
        modalIcon.style.background = "";
        modalIcon.style.color = "";
        const safeTitle = escapeHtml(title || 'this program');
        const programKey = String(title || '').toUpperCase();
        const safeReason = escapeHtml(reason || 'No reason was provided.');
        const safeAvailment = escapeHtml((availment || 'approved').toUpperCase());

        if (status === 'approved') {
            if (availment === 'exam passed') {
                modalIcon.className = "modal-icon icon-success";
                modalIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`;
                modalTitle.innerText = "Examination Passed";
                modalTitle.style.color = "var(--green)";
                modalBody.innerHTML = `<div style="text-align:center;"><h3 style="color:var(--green-dark);font-size:17px;margin-bottom:10px;font-weight:800;">You passed the SPES examination</h3><p style="font-size:14px;color:#444;line-height:1.6;">Please wait for your official assignment, start schedule, and further instructions from PESO Vinzons.</p></div>`;
            } else if (availment === 'exam failed') {
                modalIcon.className = "modal-icon icon-danger";
                modalIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="8" y1="12" x2="16" y2="12"></line></svg>`;
                modalTitle.innerText = "Examination Result";
                modalTitle.style.color = "#c0392b";
                modalBody.innerHTML = `<div style="text-align:center;"><h3 style="color:#c0392b;font-size:17px;margin-bottom:10px;font-weight:800;">Exam Not Passed</h3><p style="font-size:14px;color:#444;line-height:1.6;">You will not proceed to placement for the current SPES application. You may contact PESO Vinzons for clarification or information about future application opportunities.</p></div>`;
            } else if (availment === 'ongoing') {
                modalIcon.className = "modal-icon icon-success";
                modalIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`;
                modalTitle.innerText = "Congratulations!";
                modalTitle.style.color = "var(--green)";
                modalBody.innerHTML = `
                    <div style="text-align:center; padding: 10px 0;">
                        <h3 style="color:var(--green-dark); font-size:18px; margin-bottom:10px; font-weight: 800;">You are now a ${safeTitle} Beneficiary!</h3>
                        <p style="font-size: 14.5px; color: #444; line-height: 1.6;">Your application has been completely finalized by DOLE. Your work and program status is now officially <strong style="color:var(--green);">Ongoing</strong>.</p>
                    </div>
                `;
            } else if (availment === 'requirements received') {
                modalIcon.className = "modal-icon";
                modalIcon.style.background = "#e0f2fe";
                modalIcon.style.color = "#0284c7";
                modalIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>`;
                modalTitle.innerText = "Documents Submitted";
                modalTitle.style.color = "#0284c7";
                modalBody.innerHTML = `
                    <div style="text-align:center; padding: 10px 0;">
                        <h3 style="color:#0284c7; font-size:17px; margin-bottom:10px; font-weight: 800;">Documents Under Verification</h3>
                        <p style="font-size: 14.5px; color: #444; line-height: 1.6;">Your physical documents for <b>${safeTitle}</b> have been submitted to PESO and are now under verification.</p>
                    </div>
                `;
            } else if (availment === 'not yet availed') {
                modalIcon.className = "modal-icon icon-success";
                modalIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`;
                modalTitle.innerText = "LGU Approved!";
                modalTitle.style.color = "var(--green)";
                if (programKey.includes('TUPAD')) {
                    modalBody.innerHTML = `<div style="text-align:center;"><h3 style="color:#27ae60; font-size:16px; margin-bottom:10px; font-weight:800;">Next Step: Submit Documents</h3><p style="font-size:14px;color:#444;">Your application for <b>${safeTitle}</b> has been <strong>approved</strong>. Please bring the physical requirements identified by PESO for verification.</p></div>`;
                } else if (programKey.includes('SPES')) {
                    modalBody.innerHTML = `<div style="text-align:center;"><h3 style="color:#27ae60; font-size:16px; margin-bottom:10px; font-weight:800;">Awaiting Examination Schedule</h3><p style="font-size:14px;color:#444;">Your application for <b>${safeTitle}</b> has been <strong>approved</strong>. PESO Vinzons will send the examination schedule and any required instructions.</p></div>`;
                } else if (programKey.includes('MSME')) {
                    modalBody.innerHTML = `<div style="text-align:center;"><h3 style="color:#27ae60; font-size:16px; margin-bottom:10px; font-weight:800;">Profiling Record Approved</h3><p style="font-size:14px;color:#444;">Your profiling record for <b>${safeTitle}</b> has been approved. PESO Vinzons will contact you if another verification step or office action is needed.</p></div>`;
                } else {
                    modalBody.innerHTML = `<div style="text-align:center;"><h3 style="color:#27ae60; font-size:16px; margin-bottom:10px; font-weight:800;">Approved – Awaiting Next Step</h3><p style="font-size:14px;color:#444;">Your application for <b>${safeTitle}</b> has been approved. Please wait for the official schedule or instructions from PESO Vinzons.</p></div>`;
                }
            } else {
                modalIcon.className = "modal-icon icon-success";
                modalIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`;
                modalTitle.innerText = "Application Approved";
                modalTitle.style.color = "var(--green)";
                modalBody.innerHTML = `
                    <div style="text-align:center;">
                        <h3 style="color:#27ae60; font-size:16px; margin-bottom:10px; font-weight:800;">LGU Approved</h3>
                        <p style="font-size: 14px; color: #444;">Your application for <b>${safeTitle}</b> has been approved. Tracking status: <strong>${safeAvailment}</strong>.</p>
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
                    <p style="text-align:center;">Unfortunately, your application for <b>${safeTitle}</b> was not approved.</p>
                <div class="reason-box" style="background: rgba(231, 76, 60, 0.05); border: 1px solid rgba(231, 76, 60, 0.2); padding: 15px; border-radius: 12px; margin-top: 15px;">
                    <h4 style="color: #c0392b; margin-bottom: 5px;">Reason given by Admin:</h4>
                    <p style="margin:0; white-space:pre-line;">${safeReason}</p>
                </div>
            `;
        } 
        else {
            modalIcon.className = "modal-icon icon-warning";
            modalIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>`;
            modalTitle.innerText = "Application Pending";
            modalTitle.style.color = "#f39c12";
            modalBody.innerHTML = `
                <p style="text-align:center;">Your application for <b>${safeTitle}</b> is currently under review by the PESO admin team. Please check back later.</p>
            `;
        }

        modal.classList.add('show');
    }

    function checkEligibility(programId, title, desc, startDate, endDate) {
        document.getElementById('eligibilityProgName').innerText = title;
        document.getElementById('eligibilityProgDesc').innerText = desc;
        document.getElementById('eligibilityProgDates').textContent = "Program Duration: " + startDate + " to " + endDate;

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
            }).catch(error => {
                console.error('Fetch Error:', error);
                closeModal('programDetailsModal');
                showProfessionalNotice('Eligibility could not be checked right now. Please check your connection and try again.', 'Unable to Check Eligibility');
            });
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

        const archiveModal = document.getElementById('archiveModal');
        archiveModal.classList.add('show');
        archiveModal.setAttribute('aria-hidden', 'false');
        window.setTimeout(() => archiveModal.querySelector('.modal-close')?.focus(), 80);
    }

    function toggleTupadFitnessCertificate() {
        const pregnant = document.getElementById('tupad_is_pregnant')?.value === 'Yes';
        const pwd = document.getElementById('tupad_is_pwd')?.value === 'Yes';
        const limitation = document.getElementById('tupad_has_work_limitation')?.value === 'Yes';
        const capableSelect = document.getElementById('tupad_capable_of_work');
        const capable = capableSelect?.value || '';
        const notice = document.getElementById('tupad_fitness_notice');
        if (!notice) return;
        document.getElementById('tupad_is_pregnant')?.setCustomValidity(pregnant ? 'Pregnant applicants are not eligible for TUPAD.' : '');
        capableSelect?.setCustomValidity(capable === 'No' ? 'To qualify for TUPAD, select Yes only if you are able and willing to perform assigned work. Selecting Yes for PWD is allowed.' : '');
        notice.textContent = pregnant
            ? 'Pregnant applicants are not eligible for TUPAD.'
            : capable === 'No'
                ? 'PWD status is allowed, but every applicant must be able and willing to perform assigned work with reasonable accommodation if needed.'
            : (pwd || limitation)
                ? 'PWD applicants may apply when capable of working. If approved, bring a fitness-to-work certificate so PESO can arrange safe work or reasonable accommodation.'
                : 'PWD applicants, including applicants with speech impairment, may apply as long as they are capable of working.';
    }

    function validateSpesPregnancy(select) {
        if (!select) return;
        const blocked = select.value === 'Yes';
        select.setCustomValidity(blocked ? 'Pregnant applicants are not eligible for SPES.' : '');
        const notice = document.getElementById('spesPregnancyNotice');
        if (notice) notice.style.display = blocked ? 'block' : 'none';
        if (blocked) select.reportValidity();
    }

    function validateSpesYearLevel(select) {
        if (!select) return;
        const isStudent = (document.getElementById('spes_type')?.value || '').trim().toLowerCase() === 'student';
        const blocked = !isReturningSpesBeneficiary && isStudent && select.value.trim().toLowerCase() === '4th year';
        const notice = document.getElementById('spesFourthYearNotice');
        select.setCustomValidity(blocked ? 'Fourth-year college students are not eligible for this PESO Vinzons SPES batch.' : '');
        if (notice && isReturningSpesBeneficiary && select.value.trim().toLowerCase() === '4th year') {
            notice.style.display = 'block';
            notice.style.color = '#17623f';
            notice.textContent = 'After completing this SPES cycle, your profile will be recognized as SPES Graduate.';
        } else if (notice) {
            notice.style.display = blocked ? 'block' : 'none';
            notice.style.color = '#a32222';
            notice.textContent = 'Fourth-year college students are not eligible for this PESO Vinzons SPES batch.';
        }
        if (blocked) select.reportValidity();
    }

    function toggleSpesBirthplace(checkbox) {
        const input = document.getElementById('spes_place_of_birth');
        if (!input) return;
        const registeredPlace = checkbox.dataset.birthplace || 'Vinzons, Camarines Norte';
        if (checkbox.checked) {
            input.value = registeredPlace;
            input.readOnly = true;
        } else {
            input.readOnly = false;
            if (input.value === registeredPlace) input.value = '';
            input.focus();
        }
    }

    function toggleSpesGsisRelationship(input) {
        const wrapper = document.getElementById('spes_gsis_relationship_wrap');
        const relationship = wrapper?.querySelector('select[name="gsis_relationship"]');
        const other = document.getElementById('other_gsis_relationship');
        const citizenship = document.getElementById('spes_citizenship_wrap');
        if (!wrapper || !relationship) return;
        const hasGsisBeneficiary = input.value.trim() !== '';
        wrapper.hidden = !hasGsisBeneficiary;
        wrapper.style.display = hasGsisBeneficiary ? '' : 'none';
        if (citizenship) citizenship.style.order = hasGsisBeneficiary ? '5' : '2';
        relationship.required = hasGsisBeneficiary;
        if (!hasGsisBeneficiary) {
            relationship.value = '';
            relationship.removeAttribute('required');
            if (other) {
                other.value = '';
                other.style.display = 'none';
                other.removeAttribute('required');
            }
        }
    }

    function toggleSpesGsisOther(select) {
        toggleOther(select, 'other_gsis_relationship');
        const other = document.getElementById('other_gsis_relationship');
        if (!other) return;
        if (select.value === 'Others') other.setAttribute('required', 'required');
        else other.removeAttribute('required');
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
            buildWizardNav(["Basic Info", "Business Profile", "Owner Info", "Operations", "Human Resources", "Financials", "Gov Assistance", "Challenges", "Confirmation"]);
        } else if (currentFormType === 'spes') {
            buildWizardNav(["Basic Info", "Other Details", "Status", "Family", "Education", "History", "Confirmation"]);
        } else {
            buildWizardNav(["Basic Info", "Specifics", "Dependents & Fitness", "Confirmation"]);
        }

        document.getElementById(currentFormType + 'Wrapper').style.display = 'block';
        const returningSpes = currentFormType === 'spes' && isReturningSpesBeneficiary;
        document.getElementById('applicationFormTitle').textContent = returningSpes ? 'SPES Information Update' : 'Application Form';
        document.getElementById('applicationFormSubtitle').firstChild.textContent = returningSpes ? 'Updating your record for: ' : 'Applying for: ';
        const spesSubmitButton = document.getElementById('spesSubmitButton');
        if (spesSubmitButton) spesSubmitButton.textContent = returningSpes ? 'Submit Updated SPES Form' : 'Submit Application';
        if (returningSpes) prefillReturningSpesForm();
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
        const truthfulCertification = document.querySelector(`#${currentFormType}Wrapper input[name="tupad_certified_truthful"]`);
        if (truthfulCertification) truthfulCertification.setAttribute('required', 'required');
        const spesTruthfulCertification = document.querySelector(`#${currentFormType}Wrapper input[name="spes_certified_truthful"]`);
        if (spesTruthfulCertification) spesTruthfulCertification.setAttribute('required', 'required');
        const msmeTruthfulCertification = document.querySelector(`#${currentFormType}Wrapper input[name="msme_certified_truthful"]`);
        if (msmeTruthfulCertification) msmeTruthfulCertification.setAttribute('required', 'required');

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

        const applicationStepStatus = document.getElementById('applicationStepStatus');
        const currentLabel = document.querySelector(`#ind-step-${step} .wizard-label`)?.textContent || 'Application details';
        if (applicationStepStatus) applicationStepStatus.textContent = `Step ${step} of ${totalSteps} · ${currentLabel}`;
        const applicationForm = document.getElementById('multiStepForm');
        if (applicationForm) applicationForm.scrollTop = 0;

        // Keep the current wizard step visible on narrow screens in both directions.
        const wizardNav = document.getElementById('wizardNav');
        const currentIndicator = document.getElementById(`ind-step-${step}`);
        if (wizardNav && currentIndicator) {
            window.requestAnimationFrame(() => {
                const centeredPosition = currentIndicator.offsetLeft
                    - ((wizardNav.clientWidth - currentIndicator.offsetWidth) / 2);
                const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                wizardNav.scrollTo({
                    left: Math.max(0, centeredPosition),
                    behavior: reducedMotion ? 'auto' : 'smooth'
                });
            });
        }
    }

    function nextStep(currentStep) {
        let currentContainer = currentStep === 1 ? document.getElementById('step-1') : document.getElementById(`${currentFormType}-step-${currentStep}`);
        if (currentFormType === 'msme' && currentStep === 2) {
            const selectedNature = currentContainer.querySelector('input[name="business_nature_arr[]"]:checked');
            if (!selectedNature) { showProfessionalNotice('Please select at least one nature of business before continuing.'); return; }
            const product = [...currentContainer.querySelectorAll('input[name="prod_name[]"]')].find(input => input.value.trim() !== '');
            if (!product) { showProfessionalNotice('Please provide at least one primary product or service before continuing.'); return; }
        }
        let inputs = currentContainer.querySelectorAll('[required], [data-eligibility-check]');
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

    function syncMsmeOwnerAge() {
        const birthdate = document.getElementById('msme_owner_birthdate');
        const ageField = document.getElementById('msme_owner_age');
        if (!birthdate || !ageField || !birthdate.value) { if (ageField) ageField.value = ''; return; }
        const dob = new Date(birthdate.value + 'T00:00:00');
        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const monthDifference = today.getMonth() - dob.getMonth();
        if (monthDifference < 0 || (monthDifference === 0 && today.getDate() < dob.getDate())) age--;
        ageField.value = Number.isFinite(age) && age >= 0 ? age : '';
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[character]);
    }

    document.getElementById('multiStepForm')?.addEventListener('submit', function () {
        const submitButton = this.querySelector('button[type="submit"]');
        if (!submitButton) return;
        submitButton.disabled = true;
        submitButton.textContent = 'Submitting Application...';
    });

    initializeProgramCollections();
</script>
</body>
</html>
