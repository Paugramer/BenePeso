<?php
require_once __DIR__ . '/auth.php';
require "db.php";
require_once "beneficiary_choices.php";
require_once "email_helper.php"; // ADDED: Required for sending emails
require_once "report_columns.php";
require_once "beneficiary_import_helper.php";
require_once "tupad_category_helper.php";
require_once "tupad_household_helper.php";
ensure_tupad_category_schema($conn);

if (file_exists("functions.php")) {
    require_once "functions.php";
}

if (!isset($conn) || !($conn instanceof mysqli)) {
  die("Database connection not found. Please check db.php");
}

/* ==========================================
   PROFESSIONAL FIX: Unified Role Check
========================================== */
check_user_role("peso_staff");

$peso_staff_id = (int) $_SESSION["staff_id"];

function h($value){ return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8"); }

function table_exists(mysqli $conn, string $table): bool {
  $table = $conn->real_escape_string($table);
  $res = $conn->query("SHOW TABLES LIKE '$table'");
  return $res && $res->num_rows > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  return $res && $res->num_rows > 0;
}

function update_beneficiary_fields(mysqli $conn, int $beneficiaryId, array $fields): bool {
  if ($beneficiaryId <= 0 || !$fields) return false;
  $fields = array_filter($fields, static fn($value, $column) => column_exists($conn, 'beneficiaries', $column), ARRAY_FILTER_USE_BOTH);
  if (!$fields) return false;
  $assignments = implode(', ', array_map(static fn($column) => "`$column` = ?", array_keys($fields)));
  $values = array_map(static fn($value) => $value === null ? '' : (string)$value, array_values($fields));
  $values[] = $beneficiaryId;
  $stmt = $conn->prepare("UPDATE beneficiaries SET $assignments WHERE beneficiary_id = ?");
  if (!$stmt) return false;
  $types = str_repeat('s', count($fields)) . 'i';
  $stmt->bind_param($types, ...$values);
  $saved = $stmt->execute();
  $stmt->close();
  return $saved;
}

function format_date_value($value): string {
  if (!$value) return "—";
  $ts = strtotime((string)$value);
  return $ts ? date("M d, Y", $ts) : (string)$value;
}

function approval_badge(string $status): string {
  $s = strtolower(trim($status));
  if ($s === "approved") return "pill success";
  if ($s === "rejected") return "pill danger";
  return "pill warning";
}

function availment_badge(string $status): string {
  $s = strtolower(trim($status));
  if ($s === "ongoing") return "pill success";
  if ($s === "completed") return "pill neutral";
  if ($s === "cancelled") return "pill danger";
  if ($s === "requirements received") return "pill warning"; 
  if ($s === "not yet availed") return "pill warning";
  return "pill neutral";
}

function normalize_availment_status($status): string {
  $map = [
    'not yet availed' => 'Not Yet Availed', 'requirements received' => 'Requirements Received',
    'requirements recieved' => 'Requirements Received', 'orientation' => 'Orientation',
    'ongoing' => 'Ongoing', 'salary distribution' => 'Salary Distribution',
    'completed' => 'Completed', 'not qualified' => 'Not Qualified', 'cancelled' => 'Cancelled'
  ];
  return $map[strtolower(trim((string)$status))] ?? 'Not Yet Availed';
}

function ensure_availment_status_schema(mysqli $conn): void {
  $res = $conn->query("SHOW COLUMNS FROM beneficiaries LIKE 'availment_status'");
  if (!$res || !($row = $res->fetch_assoc())) return;
  $type = strtolower((string)($row['Type'] ?? ''));
  if (strpos($type, 'enum(') !== 0) return;
  if (strpos($type, "'orientation'") !== false && strpos($type, "'salary distribution'") !== false && strpos($type, "'not qualified'") !== false) return;
  $null = strtoupper((string)($row['Null'] ?? 'YES')) === 'NO' ? 'NOT NULL' : 'NULL';
  $default = $row['Default'] !== null ? " DEFAULT '" . $conn->real_escape_string((string)$row['Default']) . "'" : '';
  $conn->query("ALTER TABLE beneficiaries MODIFY availment_status ENUM('Not Yet Availed','Requirements Received','Orientation','Ongoing','Salary Distribution','Completed','Not Qualified','Cancelled') $null$default");
}

function ensure_application_source_schema(mysqli $conn): void {
  if (!column_exists($conn, 'beneficiaries', 'application_source')) {
    $conn->query("ALTER TABLE beneficiaries ADD COLUMN application_source VARCHAR(30) NULL AFTER created_by");
  }
}

ensure_availment_status_schema($conn);
ensure_application_source_schema($conn);

function build_query(array $overrides = []): string {
  $query = array_merge($_GET, $overrides);
  foreach ($query as $k => $v) { if ($v === null || $v === "") unset($query[$k]); }
  return http_build_query($query);
}

/* =========================
   STAFF INFO
========================= */
$staff_name = "PESO Staff";
$staff_position = "Staff";
$staff_pic = "default_avatar.png";

if (table_exists($conn, "peso_staff")) {
  $stmt = $conn->prepare("SELECT first_name, last_name, position, profile_picture FROM peso_staff WHERE staff_id = ? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param("i", $peso_staff_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
      $staff_name = trim(($row["first_name"] ?? "") . " " . ($row["last_name"] ?? ""));
      $staff_position = $row["position"] ?? $staff_position;
      $staff_pic = !empty($row["profile_picture"]) ? $row["profile_picture"] : "default_avatar.png";
    }
    $stmt->close();
  }
}

if (empty(trim($staff_name))) { $staff_name = "PESO Staff"; }
$pic_path = "uploads/staff_pics/" . $staff_pic;
if (!file_exists($pic_path) || empty($staff_pic)) { $pic_path = "img/default_avatar.png"; }
$initial = strtoupper(substr(trim($staff_name), 0, 1));

$flash = $_SESSION["flash"] ?? "";
$flash_type = $_SESSION["flash_type"] ?? "success";
$showSuccessModal = $_SESSION["show_success_modal"] ?? false;
$successModalMessage = $_SESSION["success_modal_message"] ?? "";
$importSummary = $_SESSION["import_summary"] ?? null;
$modalIcon = $_SESSION["modal_icon"] ?? "✓";
$showErrorModal = $_SESSION["show_error_modal"] ?? false;
$errorModalMessage = $_SESSION["error_modal_message"] ?? "";

unset($_SESSION["flash"], $_SESSION["flash_type"], $_SESSION["show_success_modal"], $_SESSION["success_modal_message"], $_SESSION["import_summary"], $_SESSION["modal_icon"], $_SESSION["show_error_modal"], $_SESSION["error_modal_message"]);

$barangays = [
  "Aguit-It", "Banocboc", "Cagbalogo", "Calangcawan Norte", "Calangcawan Sur",
  "Guinacutan", "Mangcayo", "Mangcawayan", "Manlucugan", "Matango",
  "Napilihan", "Pinagtigasan", "Barangay I (Pob.)", "Barangay II (Pob.)",
  "Barangay III (Pob.)", "Sabang", "Santo Domingo", "Singi", "Sula"
];

/* =========================
   POST ACTIONS (STAFF)
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = $_POST["action"] ?? "";
  $program_name_post = trim($_POST["program_name"] ?? "");

  if ($action === "bulk_status_update") {
      $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['beneficiary_ids'] ?? [])))));
      $bulkStatus = normalize_availment_status($_POST['availment_status'] ?? 'Not Yet Availed');
      $bulkMessage = trim($_POST['status_message'] ?? '');
      $bulkDate = trim($_POST['schedule_date'] ?? '');
      $bulkPlace = trim($_POST['schedule_place'] ?? '');
      $needsBulkDate = in_array($bulkStatus, ['Orientation','Salary Distribution','Completed'], true);
      $needsBulkPlace = in_array($bulkStatus, ['Orientation','Salary Distribution'], true);
      $needsBulkMessage = in_array($bulkStatus, ['Not Qualified', 'Cancelled'], true);
      if (!$selectedIds || ($needsBulkMessage && $bulkMessage === '') || ($needsBulkDate && $bulkDate === '') || ($needsBulkPlace && $bulkPlace === '')) {
          $_SESSION['show_error_modal'] = true;
          $_SESSION['error_modal_message'] = 'Select beneficiaries and complete the reason, date, or venue required for this status.';
      } else {
          $updated = $sent = $failed = 0;
          $dateAvailed = in_array($bulkStatus, ['Orientation','Ongoing','Salary Distribution'], true) && $bulkDate !== '' ? $bulkDate : null;
          $dateCompleted = $bulkStatus === 'Completed' && $bulkDate !== '' ? $bulkDate : null;
          $lastAvailed = $dateAvailed ? $dateAvailed . ' 00:00:00' : null;
          $stmt = $conn->prepare("UPDATE beneficiaries SET availment_status=?, date_availed=COALESCE(?,date_availed), date_completed=COALESCE(?,date_completed), last_availed_at=COALESCE(?,last_availed_at), updated_at=NOW() WHERE beneficiary_id=?");
          foreach ($selectedIds as $selectedId) {
              $stmt->bind_param('ssssi', $bulkStatus, $dateAvailed, $dateCompleted, $lastAvailed, $selectedId);
              if ($stmt->execute() && $stmt->affected_rows >= 0) {
                  $updated++;
                  $emailError = null;
                  if (sendBENEPESOStatusEmail($conn, $selectedId, $bulkStatus, $emailError, $bulkMessage, $bulkDate, $bulkPlace)) $sent++; else $failed++;
              }
          }
          $stmt->close();
          if (function_exists('logActivity')) logActivity($conn, $peso_staff_id, 'Beneficiaries', 'Bulk Status Update', 'Beneficiary Records', "Staff updated $updated beneficiaries to $bulkStatus");
          $_SESSION['show_success_modal'] = true;
          $_SESSION['success_modal_message'] = "$updated beneficiaries updated to $bulkStatus. $sent email(s) sent" . ($failed ? "; $failed email(s) could not be delivered." : ".");
          $_SESSION['modal_icon'] = "✓";
      }
      header("Location: peso_staff_beneficiaries.php" . ($program_name_post ? "?program_name=" . urlencode($program_name_post) : ""));
      exit();
  }

  // --- QUICK STATUS UDPATE BLOCK ---
  if ($action === "quick_update_status") {
      $beneficiary_id = (int)($_POST["beneficiary_id"] ?? 0);
      $availment_status = normalize_availment_status($_POST["availment_status"] ?? "Not Yet Availed");
      $status_message = trim($_POST["status_message"] ?? "");
      $schedule_place = trim($_POST["schedule_place"] ?? "");
      $date_availed = trim($_POST["date_availed"] ?? "");
      $date_completed = trim($_POST["date_completed"] ?? "");

      $date_availed_val = empty($date_availed) ? null : $date_availed;
      $date_completed_val = empty($date_completed) ? null : $date_completed;
      $last_availed_at = (!empty($date_availed)) ? $date_availed . " 00:00:00" : null;

      if ($beneficiary_id > 0) {
          $stmt = $conn->prepare("UPDATE beneficiaries SET availment_status = ?, date_availed = ?, date_completed = ?, last_availed_at = ?, updated_at = NOW() WHERE beneficiary_id = ?");
          $stmt->bind_param("ssssi", $availment_status, $date_availed_val, $date_completed_val, $last_availed_at, $beneficiary_id);
          if ($stmt->execute()) {
              $email_sent = false;
              $email_attempted = false;
              if (function_exists('logActivity')) {
                  logActivity($conn, $peso_staff_id, 'Beneficiaries', 'Update', 'Beneficiary Availment', "Staff updated DOLE availment status to '$availment_status' for ID: $beneficiary_id");
              }

              $email_attempted = true;
              $email_error = null;
              $email_sent = sendBENEPESOStatusEmail($conn, $beneficiary_id, $availment_status, $email_error, $status_message, $date_availed, $schedule_place);

              $_SESSION["show_success_modal"] = true;
              if (!$email_sent) {
                  $_SESSION["success_modal_message"] = "DOLE availment status updated to $availment_status, but the email notification could not be sent: " . ($email_error ?: "Unknown mail error.");
              } else {
                  $_SESSION["success_modal_message"] = "DOLE availment status updated to $availment_status successfully and email sent.";
              }
              $_SESSION["modal_icon"] = "✓";
          }
          $stmt->close();
      }
      header("Location: peso_staff_beneficiaries.php" . ($program_name_post ? "?program_name=" . urlencode($program_name_post) : ""));
      exit();
  }

  // Add or edit for Staff
  if ($action === "admin_add_beneficiary" || $action === "admin_update_beneficiary") {
      $bid = (int)($_POST["beneficiary_id"] ?? 0);
      $program_id = (int)($_POST["program_id"] ?? 0); 
      
      function processArrayField($post_key) {
          return isset($_POST[$post_key]) && is_array($_POST[$post_key]) ? implode(', ', array_map('trim', $_POST[$post_key])) : trim($_POST[$post_key] ?? "");
      }

      $first_name = trim($_POST["first_name"] ?? "");
      $middle_name = trim($_POST["middle_name"] ?? "");
      $last_name = trim($_POST["last_name"] ?? "");
      $ext_name = trim($_POST["ext_name"] ?? ""); 
      $birthdate = trim($_POST["birthdate"] ?? null);
      $age = (int)($_POST["age"] ?? 0);
      $sex = trim($_POST["owner_sex"] ?? $_POST["sex"] ?? ""); 
      $civil_status = trim($_POST["owner_civil_status"] ?? $_POST["civil_status"] ?? "");
      if ($civil_status === 'Widow/er') $civil_status = 'Widowed';
      if ($civil_status === 'Separated') $civil_status = 'Legally Separated';
      $contact_no = trim($_POST["contact_no"] ?? "");
      $email = trim($_POST["email"] ?? "");
      $street = trim($_POST["street_purok_zone"] ?? "");
      $barangay = trim($_POST["barangay"] ?? "");
      $municipality = trim($_POST["municipality"] ?? "Vinzons");
      $district = trim($_POST["district"] ?? "Camarines Norte");
      $status = trim($_POST["status"] ?? "Active");
      $availment_status = normalize_availment_status($_POST["availment_status"] ?? "Not Yet Availed");
      $date_availed = trim($_POST["date_availed"] ?? null);
      $date_completed = trim($_POST["date_completed"] ?? null);
      if (!in_array($availment_status, ['Requirements Received', 'Ongoing', 'Completed'], true)) {
          $date_availed = null;
          $date_completed = null;
      } elseif ($availment_status === 'Requirements Received') {
          $date_completed = null;
      }

      // TUPAD Data
      $type_of_id = trim($_POST["type_of_id"] ?? "");
      if ($type_of_id === 'Others' || $type_of_id === 'Other') $type_of_id = trim($_POST["other_type_of_id"] ?? "Others");
      $id_number = trim($_POST["id_number"] ?? "");
      $type_of_beneficiary = choice_or_other($_POST, 'type_of_beneficiary');
      $occupation = choice_or_other($_POST, 'occupation');
      $avg_monthly_income = trim($_POST["avg_monthly_income"] ?? "");
      $dependent_name = trim($_POST["dependent_name"] ?? "");
      $dependent_relationship = choice_or_other($_POST, 'dependent_relationship');
      $interested_in_employment = trim($_POST["interested_in_employment"] ?? "No");
      $skills_training_needed = choice_or_other($_POST, 'skills_training_needed');

      // SPES Data
      $gsis_beneficiary_name = trim($_POST["gsis_beneficiary"] ?? $_POST["gsis_beneficiary_name"] ?? "");
      $gsis_relationship = trim($_POST["gsis_relationship"] ?? "");
      $place_of_birth = trim($_POST["place_of_birth"] ?? "");
      $citizenship = trim($_POST["citizenship"] ?? "Filipino");
      $social_media = trim($_POST["social_urls"] ?? $_POST["social_media"] ?? "");
      $spes_type = trim($_POST["spes_type"] ?? "");
      $parents_status = processArrayField('spes_parent_status');
      $permanent_address = trim($_POST["permanent_address"] ?? "");
      
      $father_name = trim($_POST["father_name"] ?? "");
      $father_contact = trim($_POST["father_contact"] ?? "");
      $father_occupation = trim($_POST["father_occupation"] ?? "");
      if ($father_occupation === 'Others' || $father_occupation === 'Other') $father_occupation = trim($_POST["other_father_occupation"] ?? "Others");
      
      $mother_name = trim($_POST["mother_name"] ?? "");
      $mother_contact = trim($_POST["mother_contact"] ?? "");
      $mother_occupation = trim($_POST["mother_occupation"] ?? "");
      if ($mother_occupation === 'Others' || $mother_occupation === 'Other') $mother_occupation = trim($_POST["other_mother_occupation"] ?? "Others");
      
      $elem_school = trim($_POST["elem_school"] ?? "");
      $elem_degree = trim($_POST["elem_degree"] ?? "N/A");
      $elem_year_level = trim($_POST["elem_year_level"] ?? "");
      $elem_date_attendance = trim($_POST["elem_date_attendance"] ?? "");
      
      $sec_school = trim($_POST["sec_school"] ?? "");
      $sec_degree = trim($_POST["sec_degree"] ?? "");
      if ($sec_degree === 'Others' || $sec_degree === 'Other') $sec_degree = trim($_POST["other_sec_degree"] ?? "Others");
      $sec_year_level = trim($_POST["sec_year_level"] ?? "");
      $sec_date_attendance = trim($_POST["sec_date_attendance"] ?? "");
      
      $tert_school = trim($_POST["tert_school"] ?? "");
      $tert_course = trim($_POST["tert_course"] ?? "");
      if ($tert_course === 'Others' || $tert_course === 'Other') $tert_course = trim($_POST["other_tert_course"] ?? "Others");
      $tert_year_level = trim($_POST["tert_year_level"] ?? "");
      $tert_date_attendance = trim($_POST["tert_date_attendance"] ?? "");
      
      $tv_school = trim($_POST["tv_school"] ?? "");
      $tv_course = trim($_POST["tv_course"] ?? "");
      if ($tv_course === 'Others' || $tv_course === 'Other') $tv_course = trim($_POST["other_tv_course"] ?? "Others");
      $tv_year_level = trim($_POST["tv_year_level"] ?? "");
      $tv_date_attendance = trim($_POST["tv_date_attendance"] ?? "");
      $special_skills = trim($_POST["special_skills"] ?? "");
      $spes_other_info = trim($_POST["spes_other_info"] ?? "");

      // SPES History
      $spes_hist_year_arr = $_POST["spes_hist_year"] ?? [];
      $spes_hist_id_arr = $_POST["spes_hist_id"] ?? [];
      $spes_hist_est_arr = $_POST["spes_hist_est"] ?? [];
      $spes_history_1_establishment = trim($_POST["spes_history_1_establishment"] ?? $spes_hist_est_arr[0] ?? "");
      $spes_history_2_establishment = trim($_POST["spes_history_2_establishment"] ?? $spes_hist_est_arr[1] ?? "");
      $spes_history_3_establishment = trim($_POST["spes_history_3_establishment"] ?? $spes_hist_est_arr[2] ?? "");
      $spes_history_4_establishment = trim($_POST["spes_history_4_establishment"] ?? $spes_hist_est_arr[3] ?? "");
      $spes_history_1_year = trim($_POST["spes_history_1_year"] ?? $spes_hist_year_arr[0] ?? "");
      $spes_history_1_id = trim($_POST["spes_history_1_id"] ?? $spes_hist_id_arr[0] ?? "");
      $spes_history_2_year = trim($_POST["spes_history_2_year"] ?? $spes_hist_year_arr[1] ?? "");
      $spes_history_2_id = trim($_POST["spes_history_2_id"] ?? $spes_hist_id_arr[1] ?? "");
      $spes_history_3_year = trim($_POST["spes_history_3_year"] ?? $spes_hist_year_arr[2] ?? "");
      $spes_history_3_id = trim($_POST["spes_history_3_id"] ?? $spes_hist_id_arr[2] ?? "");
      $spes_history_4_year = trim($_POST["spes_history_4_year"] ?? $spes_hist_year_arr[3] ?? "");
      $spes_history_4_id = trim($_POST["spes_history_4_id"] ?? $spes_hist_id_arr[3] ?? "");
      $spes_history_json = json_encode([
          ['', $spes_history_1_establishment, $spes_history_1_year, $spes_history_1_id],
          ['', $spes_history_2_establishment, $spes_history_2_year, $spes_history_2_id],
          ['', $spes_history_3_establishment, $spes_history_3_year, $spes_history_3_id],
          ['', $spes_history_4_establishment, $spes_history_4_year, $spes_history_4_id],
      ], JSON_UNESCAPED_UNICODE);
      $spes_form_fields = [
          'spes_history' => $spes_history_json,
          'spes_history_1_establishment' => $spes_history_1_establishment,
          'spes_history_2_establishment' => $spes_history_2_establishment,
          'spes_history_3_establishment' => $spes_history_3_establishment,
          'spes_history_4_establishment' => $spes_history_4_establishment,
          'spes_other_info' => $spes_other_info,
      ];

      // MSME Data
      $business_name = trim($_POST["business_name"] ?? "");
      $ownership_type = choice_or_other($_POST, 'ownership_type');
      
      $business_nature = processArrayField('business_nature_arr');
      if (strpos($business_nature, 'Others') !== false && !empty($_POST['other_business_nature'])) {
          $business_nature = str_replace('Others', trim($_POST['other_business_nature']), $business_nature);
      }

      $prod_names = $_POST['prod_name'] ?? [];
      $prod_prices = $_POST['prod_price'] ?? [];
      $products_arr = [];
      foreach($prod_names as $idx => $pname) {
          if(!empty(trim($pname))) {
              $price = trim($prod_prices[$idx] ?? "");
              $products_arr[] = trim($pname) . ($price ? " (₱$price)" : "");
          }
      }
      $primary_products = implode(', ', $products_arr);
      $clean_product_names = [];
      $clean_product_prices = [];
      foreach ($prod_names as $idx => $product_name) {
          $product_name = trim((string)$product_name);
          if ($product_name === '') continue;
          $clean_product_names[] = $product_name;
          $clean_product_prices[] = trim((string)($prod_prices[$idx] ?? ''));
      }
      $primary_products = implode(', ', $clean_product_names);
      $product_price = implode(', ', $clean_product_prices);

      $year_started = trim($_POST["year_started"] ?? "");
      $business_permit_no = trim($_POST["business_permit_no"] ?? "");
      $permit_validity = trim($_POST["permit_valid_until"] ?? $_POST["permit_validity"] ?? "");
      $dti_no = trim($_POST["dti_no"] ?? "");
      $tin_no = trim($_POST["tin_no"] ?? "");
      $educational_attainment = trim($_POST["educational_attainment"] ?? "");
      if ($educational_attainment === 'Others' || $educational_attainment === 'Other') $educational_attainment = trim($_POST["other_educational_attainment"] ?? "Others");
      $work_experience = trim($_POST["work_experience"] ?? "");
      $business_email = trim($_POST["contact_details"] ?? $_POST["business_email"] ?? "");
      $business_social_media = trim($_POST["business_social_media"] ?? "");
      
      $business_assets = processArrayField('assets_owned');
      $utility_needs = processArrayField('utility_needs');
      $source_of_capital = processArrayField('source_of_capital');
      $mode_of_payment = processArrayField('mode_of_payment');
      $distribution_channels = processArrayField('distribution_channels');
      $assistance_availed = processArrayField('assistance_availed');
      $past_programs = processArrayField('past_programs');
      $programs_needed = processArrayField('programs_needed');
      $challenges_encountered = processArrayField('challenges_encountered');
      $msme_form_fields = [
          'product_price' => $product_price,
          'assets_owned' => $business_assets,
          'utility_needs' => $utility_needs,
          'hr_male' => (int)($_POST['hr_male'] ?? 0),
          'hr_female' => (int)($_POST['hr_female'] ?? 0),
          'hr_total' => (int)($_POST['hr_total'] ?? 0),
          'emp_regular' => (int)($_POST['emp_regular'] ?? 0),
          'emp_seasonal' => (int)($_POST['emp_seasonal'] ?? 0),
          'emp_contractual' => (int)($_POST['emp_contractual'] ?? 0),
          'emp_family' => (int)($_POST['emp_family'] ?? 0),
          'hr_skills' => trim($_POST['hr_skills'] ?? ''),
          'source_of_capital' => $source_of_capital,
          'business_size' => trim($_POST['business_size'] ?? ''),
          'initial_capital' => trim($_POST['initial_capital'] ?? ''),
          'current_capital' => trim($_POST['current_capital'] ?? ''),
          'daily_earnings' => trim($_POST['daily_earnings'] ?? ''),
          'mode_of_payment' => $mode_of_payment,
          'distribution_channels' => $distribution_channels,
          'availed_before' => trim($_POST['availed_before'] ?? ''),
          'assistance_availed' => $assistance_availed,
          'past_programs' => $past_programs,
          'programs_needed' => $programs_needed,
          'challenges_encountered' => $challenges_encountered,
      ];

      $nm_stall_no = trim($_POST["nm_stall_no"] ?? "");
      $nm_date_started = trim($_POST["nm_date_started"] ?? "");

      $approval_status = "Pending";
      $previous_availment_status = null;
      if ($action === "admin_update_beneficiary" && $bid > 0) {
          $statusStmt = $conn->prepare("SELECT approval_status, availment_status FROM beneficiaries WHERE beneficiary_id = ? LIMIT 1");
          if ($statusStmt) {
              $statusStmt->bind_param("i", $bid);
              $statusStmt->execute();
              $statusRow = $statusStmt->get_result()->fetch_assoc();
              if ($statusRow) {
                  $approval_status = $statusRow["approval_status"] ?? $approval_status;
                  $previous_availment_status = $statusRow["availment_status"] ?? null;
              }
               $statusStmt->close();
           }
       }
       $full_name = trim("$first_name $middle_name $last_name $ext_name");
      $last_availed_at = (!empty($date_availed)) ? $date_availed . " 00:00:00" : null;
      $date_availed_val = empty($date_availed) ? null : $date_availed;
      $date_completed_val = empty($date_completed) ? null : $date_completed;
      $birthdate_val = empty($birthdate) ? null : $birthdate;

      $hasSourceCol = column_exists($conn, "beneficiaries", "application_source");
      $hasStall = column_exists($conn, "beneficiaries", "nm_stall_no");
      $stall_col_in = $hasStall ? ", nm_stall_no, nm_date_started, business_assets, utility_needs" : "";
      $stall_col_val = $hasStall ? ", ?, ?, ?, ?" : "";
      $stall_col_upd = $hasStall ? ", nm_stall_no=?, nm_date_started=?, business_assets=?, utility_needs=?" : "";
      $stall_type = $hasStall ? "ssss" : "";

      if ($program_id > 0 && $full_name !== "") {
          $householdCheck = check_tupad_household_conflict($conn, $program_id, $full_name, $dependent_name, $bid);
          if (!$householdCheck['eligible']) {
              $_SESSION["show_error_modal"] = true;
              $_SESSION["error_modal_message"] = $householdCheck['message'];
          } else {
          $checkSql = "SELECT beneficiary_id FROM beneficiaries WHERE program_id = ? AND LOWER(TRIM(full_name)) = LOWER(TRIM(?))";
          if ($bid > 0) $checkSql .= " AND beneficiary_id != $bid";

          $checkStmt = $conn->prepare($checkSql);
          $checkStmt->bind_param("is", $program_id, $full_name);
          $checkStmt->execute();
          $exists = $checkStmt->get_result()->num_rows > 0;
          $checkStmt->close();

          if ($exists) {
              $_SESSION["show_error_modal"] = true;
              $_SESSION["error_modal_message"] = "Beneficiary '$full_name' already exists.";
          } else {
              if ($action === "admin_add_beneficiary") {
              $source_val = "PESO Staff";
              $source_col = $hasSourceCol ? ", application_source" : "";
              $source_param = $hasSourceCol ? ", ?" : "";
              $source_type = $hasSourceCol ? "s" : "";
                  
              // PROFESSIONAL FIX: Removed the extra `?` in the VALUES clause so it perfectly matches the column count
              $sql = "INSERT INTO beneficiaries (
                  program_id, full_name, first_name, middle_name, last_name, ext_name, birthdate, age, sex, civil_status, contact_no, email, street_purok_zone, barangay, municipality, district, type_of_id, id_number, type_of_beneficiary, occupation, avg_monthly_income, dependent_name, dependent_relationship, interested_in_employment, skills_training_needed, status, availment_status, date_availed, date_completed, last_availed_at, approval_status, created_by, created_at, updated_at, gsis_beneficiary_name, gsis_relationship, place_of_birth, citizenship, social_media, spes_type, parents_status, permanent_address, father_name, father_contact, father_occupation, mother_name, mother_contact, mother_occupation, elem_school, elem_degree, elem_year_level, elem_date_attendance, sec_school, sec_degree, sec_year_level, sec_date_attendance, tert_school, tert_course, tert_year_level, tert_date_attendance, tv_school, tv_course, tv_year_level, tv_date_attendance, special_skills, spes_history_1_year, spes_history_1_id, spes_history_2_year, spes_history_2_id, spes_history_3_year, spes_history_3_id, spes_history_4_year, spes_history_4_id, business_name, ownership_type, business_nature, primary_products, year_started, business_permit_no, permit_validity, dti_no, tin_no, educational_attainment, work_experience, business_email, business_social_media
                  $source_col $stall_col_in
              ) VALUES (
                  ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                  $source_param $stall_col_val
              )";
              $stmt = $conn->prepare($sql);
              $bindTypes = "issssssisssssssssssssssssssssssissssssssssssssssssssssssssssssssssssssssssssssssssss" . $source_type . $stall_type;
              
              $bindParams = [
                  $program_id, $full_name, $first_name, $middle_name, $last_name, $ext_name, $birthdate_val, $age, $sex, $civil_status, $contact_no, $email, $street, $barangay, $municipality, $district, $type_of_id, $id_number, $type_of_beneficiary, $occupation, $avg_monthly_income, $dependent_name, $dependent_relationship, $interested_in_employment, $skills_training_needed, $status, $availment_status, $date_availed_val, $date_completed_val, $last_availed_at, $approval_status, $peso_staff_id, $gsis_beneficiary_name, $gsis_relationship, $place_of_birth, $citizenship, $social_media, $spes_type, $parents_status, $permanent_address, $father_name, $father_contact, $father_occupation, $mother_name, $mother_contact, $mother_occupation, $elem_school, $elem_degree, $elem_year_level, $elem_date_attendance, $sec_school, $sec_degree, $sec_year_level, $sec_date_attendance, $tert_school, $tert_course, $tert_year_level, $tert_date_attendance, $tv_school, $tv_course, $tv_year_level, $tv_date_attendance, $special_skills, $spes_history_1_year, $spes_history_1_id, $spes_history_2_year, $spes_history_2_id, $spes_history_3_year, $spes_history_3_id, $spes_history_4_year, $spes_history_4_id, $business_name, $ownership_type, $business_nature, $primary_products, $year_started, $business_permit_no, $permit_validity, $dti_no, $tin_no, $educational_attainment, $work_experience, $business_email, $business_social_media
              ];
              
              if ($hasSourceCol) { $bindParams[] = $source_val; }
              if ($hasStall) {
                  $bindParams[] = $nm_stall_no;
                  $bindParams[] = $nm_date_started;
                  $bindParams[] = $business_assets;
                  $bindParams[] = $utility_needs;
              }
              
              $stmt->bind_param($bindTypes, ...$bindParams);

              if ($stmt->execute()) {
                  $savedBeneficiaryId = (int)$stmt->insert_id;
                  if (stripos($program_name_post, 'SPES') !== false && !update_beneficiary_fields($conn, $savedBeneficiaryId, $spes_form_fields)) {
                      $_SESSION["show_error_modal"] = true;
                      $_SESSION["error_modal_message"] = "The beneficiary was saved, but the additional SPES form details could not be stored.";
                  }
                  if (stripos($program_name_post, 'MSME') !== false && !update_beneficiary_fields($conn, $savedBeneficiaryId, $msme_form_fields)) {
                      $_SESSION["show_error_modal"] = true;
                      $_SESSION["error_modal_message"] = "The main record was saved, but the MSME details could not be stored. Please try saving the record again.";
                      $stmt->close();
                      header("Location: peso_staff_beneficiaries.php?program_name=" . urlencode($program_name_post));
                      exit;
                  }
                  $_SESSION["show_success_modal"] = true;
                  $_SESSION["success_modal_message"] = "Beneficiary submitted to Admin for approval.";
                  $_SESSION["modal_icon"] = "✓";
              } else {
                  $_SESSION["show_error_modal"] = true;
                  $_SESSION["error_modal_message"] = "Database error: " . $stmt->error;
              }
              $stmt->close();
              } else {
                  $sql = "UPDATE beneficiaries SET
                      program_id=?, full_name=?, first_name=?, middle_name=?, last_name=?, ext_name=?, birthdate=?, age=?, sex=?, civil_status=?,
                      contact_no=?, email=?, street_purok_zone=?, barangay=?, municipality=?, district=?,
                      type_of_id=?, id_number=?, type_of_beneficiary=?, occupation=?, avg_monthly_income=?, dependent_name=?, dependent_relationship=?, interested_in_employment=?, skills_training_needed=?,
                      status=?, availment_status=?, date_availed=?, date_completed=?, last_availed_at=?, approval_status=?, updated_at=NOW(),
                      gsis_beneficiary_name=?, gsis_relationship=?, place_of_birth=?, citizenship=?, social_media=?, spes_type=?, parents_status=?, permanent_address=?, father_name=?, father_contact=?, father_occupation=?, mother_name=?, mother_contact=?, mother_occupation=?,
                      elem_school=?, elem_degree=?, elem_year_level=?, elem_date_attendance=?, sec_school=?, sec_degree=?, sec_year_level=?, sec_date_attendance=?, tert_school=?, tert_course=?, tert_year_level=?, tert_date_attendance=?, tv_school=?, tv_course=?, tv_year_level=?, tv_date_attendance=?,
                      special_skills=?, spes_history_1_year=?, spes_history_1_id=?, spes_history_2_year=?, spes_history_2_id=?, spes_history_3_year=?, spes_history_3_id=?, spes_history_4_year=?, spes_history_4_id=?,
                      business_name=?, ownership_type=?, business_nature=?, primary_products=?, year_started=?, business_permit_no=?, permit_validity=?, dti_no=?, tin_no=?, educational_attainment=?, work_experience=?, business_email=?, business_social_media=?
                      $stall_col_upd
                      WHERE beneficiary_id=?";
                  $stmt = $conn->prepare($sql);

                  if ($stmt) {
                      $bindTypes = "issssssi" . str_repeat("s", 75) . $stall_type . "i";
                      $bindParams = [
                          $program_id, $full_name, $first_name, $middle_name, $last_name, $ext_name, $birthdate_val, $age, $sex, $civil_status,
                          $contact_no, $email, $street, $barangay, $municipality, $district,
                          $type_of_id, $id_number, $type_of_beneficiary, $occupation, $avg_monthly_income, $dependent_name, $dependent_relationship, $interested_in_employment, $skills_training_needed,
                          $status, $availment_status, $date_availed_val, $date_completed_val, $last_availed_at, $approval_status,
                          $gsis_beneficiary_name, $gsis_relationship, $place_of_birth, $citizenship, $social_media, $spes_type, $parents_status, $permanent_address, $father_name, $father_contact, $father_occupation, $mother_name, $mother_contact, $mother_occupation,
                          $elem_school, $elem_degree, $elem_year_level, $elem_date_attendance, $sec_school, $sec_degree, $sec_year_level, $sec_date_attendance, $tert_school, $tert_course, $tert_year_level, $tert_date_attendance, $tv_school, $tv_course, $tv_year_level, $tv_date_attendance,
                          $special_skills, $spes_history_1_year, $spes_history_1_id, $spes_history_2_year, $spes_history_2_id, $spes_history_3_year, $spes_history_3_id, $spes_history_4_year, $spes_history_4_id,
                          $business_name, $ownership_type, $business_nature, $primary_products, $year_started, $business_permit_no, $permit_validity, $dti_no, $tin_no, $educational_attainment, $work_experience, $business_email, $business_social_media
                      ];

                      if ($hasStall) {
                          $bindParams[] = $nm_stall_no;
                          $bindParams[] = $nm_date_started;
                          $bindParams[] = $business_assets;
                          $bindParams[] = $utility_needs;
                      }
                      $bindParams[] = $bid;
                      $stmt->bind_param($bindTypes, ...$bindParams);

                      if ($stmt->execute()) {
                          if (stripos($program_name_post, 'SPES') !== false && !update_beneficiary_fields($conn, $bid, $spes_form_fields)) {
                              $_SESSION["show_error_modal"] = true;
                              $_SESSION["error_modal_message"] = "The beneficiary was saved, but the additional SPES form details could not be stored.";
                          }
                          if (stripos($program_name_post, 'MSME') !== false && !update_beneficiary_fields($conn, $bid, $msme_form_fields)) {
                              $_SESSION["show_error_modal"] = true;
                              $_SESSION["error_modal_message"] = "The main record was saved, but the MSME details could not be stored. Please try saving the record again.";
                              $stmt->close();
                              header("Location: peso_staff_beneficiaries.php?program_name=" . urlencode($program_name_post));
                              exit;
                          }
                          if (function_exists('logActivity')) {
                              logActivity($conn, $peso_staff_id, 'Beneficiaries', 'Edit', 'Beneficiary Record', "Staff updated beneficiary ID: $bid");
                          }
                          $_SESSION["show_success_modal"] = true;
                          $_SESSION["success_modal_message"] = "Beneficiary changes saved successfully.";

                          if ($previous_availment_status !== null && $previous_availment_status !== $availment_status) {
                              $email_error = null;
                              if (sendBENEPESOStatusEmail($conn, $bid, $availment_status, $email_error)) {
                                  $_SESSION["success_modal_message"] .= " Email notification sent.";
                              } else {
                                  $_SESSION["success_modal_message"] .= " Status changed, but email could not be sent: " . ($email_error ?: "Unknown mail error.");
                              }
                          }
                      } else {
                          $_SESSION["show_error_modal"] = true;
                          $_SESSION["error_modal_message"] = "Database error: " . $stmt->error;
                      }
                      $stmt->close();
                  } else {
                      $_SESSION["show_error_modal"] = true;
                      $_SESSION["error_modal_message"] = "Unable to prepare the beneficiary update.";
                  }
              }
          }
          }
      }
      header("Location: peso_staff_beneficiaries.php" . ($program_name_post ? "?program_name=" . urlencode($program_name_post) : ""));
      exit();
  }

  // BULK IMPORT FILE
  if ($action === "bulk_upload_beneficiaries") {
      $program_id = (int)($_POST["program_id"] ?? 0);
      $batch_availment = trim($_POST["batch_availment_status"] ?? "Not Yet Availed");
      $batch_message = trim($_POST["batch_status_message"] ?? "");
      $batch_schedule_date = trim($_POST["batch_schedule_date"] ?? "");
      $batch_schedule_place = trim($_POST["batch_schedule_place"] ?? "");
      $file = $_FILES["csv_file"] ?? null;

      if ($program_id > 0 && $file && $file["error"] === UPLOAD_ERR_OK) {
          $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
          $successCount = 0; $updatedCount = 0; $failedCount = 0; $skippedCount = 0;
          $emailSentCount = 0; $emailFailedCount = 0;
          $emailFailureReasons = [];

          if (in_array($ext, ["csv", "xlsx"], true)) {
              $checkStmt = $conn->prepare("SELECT beneficiary_id FROM beneficiaries WHERE program_id = ? AND LOWER(TRIM(full_name)) = LOWER(TRIM(?))");
              
              $header_map = [
                  'firstname' => 'first_name', 'middlename' => 'middle_name', 'lastname' => 'last_name',
                  'extname' => 'ext_name', 'extensionname' => 'ext_name', 'fullname' => 'full_name', 'nameofbeneficiary' => 'full_name', 'ownername' => 'full_name', 'ownerfullname' => 'full_name', 'beneficiaryname' => 'full_name',
                  'stzone' => 'street_purok_zone', 'streetpurok' => 'street_purok_zone', 'street' => 'street_purok_zone', 'address' => 'street_purok_zone', 'completeaddress' => 'street_purok_zone',
                  'brgy' => 'barangay', 'barangay' => 'barangay', 
                  'citymun' => 'municipality', 'citymunicipality' => 'municipality', 'municipality' => 'municipality',
                  'prov' => 'district', 'province' => 'district', 'dist' => 'district', 'district' => 'district', 
                  'contactnumber' => 'contact_no', 'contactno' => 'contact_no', 'mobilenumber' => 'contact_no', 'landlinemobilenumber' => 'contact_no',
                  'emailaddress' => 'email', 'email' => 'email', 'businessemail' => 'business_email',
                  'birthdateyyyymmdd' => 'birthdate', 'dateofbirth' => 'birthdate', 'birthdate' => 'birthdate', 
                  'age' => 'age', 'sex' => 'sex', 'gender' => 'sex', 'civilstatus' => 'civil_status', 
                  'businesstradename' => 'business_name', 'businessname' => 'business_name',
                  'typeofownership' => 'ownership_type', 'natureofbusiness' => 'business_nature',
                  'primaryproductsoffered' => 'primary_products', 'primaryproducts' => 'primary_products',
                  'productprice' => 'product_price',
                  'yearbusinessstarted' => 'year_started', 'businesspermitnumber' => 'business_permit_no', 'businesspermitno' => 'business_permit_no',
                  'permitvaliduntil' => 'permit_validity', 'validuntil' => 'permit_validity', 'departmentoftradeandindustryregistrationnumber' => 'dti_no', 'dtiregistrationno' => 'dti_no', 'dti' => 'dti_no',
                  'taxidentificationnumber' => 'tin_no', 'taxidentificationno' => 'tin_no', 'taxidentificationnotin' => 'tin_no', 'tin' => 'tin_no',
                  'contactdetails' => 'business_email', 'businesscontactdetails' => 'business_email', 'websitesocialmedia' => 'business_social_media',
                  'educationalattainment' => 'educational_attainment', 'workexperience' => 'work_experience', 'currentworkbusinessexperience' => 'work_experience',
                  'businessassetsowned' => 'assets_owned', 'utilityneeds' => 'utility_needs',
                  'typeofidentification' => 'type_of_id', 'typeofid' => 'type_of_id', 'idnumber' => 'id_number',
                  'typeofbeneficiary' => 'type_of_beneficiary', 'occupation' => 'occupation',
                  'avgmonthlyincome' => 'avg_monthly_income', 'averagemonthlyincome' => 'avg_monthly_income', 'monthlyincome' => 'avg_monthly_income', 'estimatedmonthlyincome' => 'avg_monthly_income', 'estimatedmonthlyfamilyincome' => 'avg_monthly_income', 
                  'dependent' => 'dependent_name', 'dependentname' => 'dependent_name', 
                  'relationshiptodependent' => 'dependent_relationship', 'dependentrelationship' => 'dependent_relationship', 'relationship' => 'dependent_relationship',
                  'interestedinwageemployment' => 'interested_in_employment', 'interestedinemployment' => 'interested_in_employment',
                  'skillstrainingneeded' => 'skills_training_needed', 'skills' => 'skills_training_needed', 'skillsneeded' => 'hr_skills',
                  'familyincome' => 'avg_monthly_income', 'dependents' => 'dependent_name', 'idtype' => 'type_of_id',
                  'placeofbirth' => 'place_of_birth', 'citizenship' => 'citizenship', 'socialmedia' => 'social_media',
                  'governmentserviceinsurancesystembeneficiary' => 'gsis_beneficiary_name', 'gsisbeneficiarypolicydetails' => 'gsis_beneficiary_name', 'gsis' => 'gsis_beneficiary_name',
                  'studentstatus' => 'spes_type', 'parentstatus' => 'parents_status',
                  'permanentaddress' => 'permanent_address',
                  'fathersname' => 'father_name', 'fatherscontact' => 'father_contact', 'fatherscontactnumber' => 'father_contact', 'fathersoccupation' => 'father_occupation',
                  'mothersname' => 'mother_name', 'motherscontact' => 'mother_contact', 'motherscontactnumber' => 'mother_contact', 'mothersoccupation' => 'mother_occupation',
                  'elementaryschool' => 'elem_school', 'elementarydegreehonors' => 'elem_degree', 'elementaryhonors' => 'elem_degree', 'elementaryyearlevel' => 'elem_year_level', 'elementarylevel' => 'elem_year_level',
                  'elementarydatesofattendance' => 'elem_date_attendance', 'elementaryattendance' => 'elem_date_attendance', 'secondaryseniorhighschool' => 'sec_school', 'secondaryschool' => 'sec_school',
                  'secondarytrackstrand' => 'sec_degree', 'secondaryyearlevel' => 'sec_year_level', 'secondarylevel' => 'sec_year_level', 'secondarydatesofattendance' => 'sec_date_attendance', 'secondaryattendance' => 'sec_date_attendance',
                  'tertiaryschool' => 'tert_school', 'tertiarycoursedegree' => 'tert_course', 'tertiarycourse' => 'tert_course', 'tertiaryyearlevel' => 'tert_year_level', 'tertiarylevel' => 'tert_year_level',
                  'tertiarydatesofattendance' => 'tert_date_attendance', 'tertiaryattendance' => 'tert_date_attendance', 'technicalvocationalschool' => 'tv_school',
                  'technicalvocationalcourse' => 'tv_course', 'technicalvocationalhourslevel' => 'tv_year_level',
                  'technicalvocationallevel' => 'tv_year_level', 'technicalvocationaldatesofattendance' => 'tv_date_attendance', 'technicalvocationalattendance' => 'tv_date_attendance', 'specialskills' => 'special_skills',
                  'specialprogramforemploymentofstudentshistory1' => 'spes_history_1_year',
                  'specialprogramforemploymentofstudentshistory2' => 'spes_history_2_year',
                  'specialprogramforemploymentofstudentshistory3' => 'spes_history_3_year',
                  'specialprogramforemploymentofstudentshistory4' => 'spes_history_4_year',
                  'speshistory' => 'spes_history',
                  'numberofworkersemployees' => 'hr_total', 'numberofworkers' => 'hr_total',
                  'maleemployees' => 'hr_male',
                  'femaleemployees' => 'hr_female',
                  'regularemployees' => 'emp_regular', 'seasonalemployees' => 'emp_seasonal',
                  'contractualemployees' => 'emp_contractual', 'familyworkers' => 'emp_family',
                  'estimateddailysales' => 'daily_earnings', 'regulardailyearnings' => 'daily_earnings',
                  'estimatedcapital' => 'current_capital', 'currentcapital' => 'current_capital',
                  'sourceofcapital' => 'source_of_capital',
                  'businesssize' => 'business_size', 'initialcapital' => 'initial_capital',
                  'modesofpayment' => 'mode_of_payment', 'distributionchannels' => 'distribution_channels',
                  'previouslyavailedassistance' => 'availed_before', 'assistanceavailed' => 'assistance_availed',
                  'pastprograms' => 'past_programs', 'programsneeded' => 'programs_needed',
                  'challengesencountered' => 'challenges_encountered'
              ];

              try {
                  $importRows = readBeneficiaryImportRows($file["tmp_name"], $ext);
              } catch (Throwable $error) {
                  $_SESSION["show_error_modal"] = true;
                  $_SESSION["error_modal_message"] = "Import failed: " . $error->getMessage();
                  header("Location: peso_staff_beneficiaries.php" . ($program_name_post ? "?program_name=" . urlencode($program_name_post) : ""));
                  exit();
              }

              $headers = [];
              $header_found = false;
              $headerIndex = -1;
              $scan_limit = 25;

              foreach (array_slice($importRows, 0, $scan_limit, true) as $rowIndex => $row) {
                  $rowStr = beneficiaryImportHeaderKey(implode('', $row));
                  if (
                      (strpos($rowStr, 'firstname') !== false && strpos($rowStr, 'lastname') !== false) ||
                      strpos($rowStr, 'fullname') !== false ||
                      strpos($rowStr, 'ownername') !== false ||
                      strpos($rowStr, 'businesstradename') !== false
                  ) {
                      $headers = $row;
                      $header_found = true;
                      $headerIndex = (int)$rowIndex;
                      break;
                  }
              }

              if (!$header_found) {
                  $_SESSION["show_error_modal"] = true;
                  $_SESSION["error_modal_message"] = "Invalid Template. Could not find column headers (First Name, Last Name, Full Name, or Owner Name) in the file.";
                  header("Location: peso_staff_beneficiaries.php" . ($program_name_post ? "?program_name=" . urlencode($program_name_post) : ""));
                  exit();
              }

              $mapped_headers = [];
              foreach ($headers as $index => $h) {
                  $h_clean = beneficiaryImportHeaderKey($h);
                  if (empty($h_clean)) continue;

                  if (isset($header_map[$h_clean])) {
                      $mapped_headers[$index] = $header_map[$h_clean];
                  } else {
                      $mapped_headers[$index] = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($h)));
                  }
              }

              for ($importIndex = $headerIndex + 1, $rowTotal = count($importRows); $importIndex < $rowTotal; $importIndex++) {
                  $data = $importRows[$importIndex];
                  if (empty(trim(implode("", $data)))) continue;

                  $row_data = [];
                  foreach ($mapped_headers as $index => $col_name) {
                      $val = cleanBeneficiaryImportText($data[$index] ?? "");
                      
                      if (in_array($col_name, ['avg_monthly_income', 'initial_capital', 'current_capital', 'daily_earnings'])) {
                          $val = str_replace(',', '', $val);
                      }
                      
                      if (in_array($col_name, ['birthdate', 'permit_validity', 'date_availed', 'date_completed', 'nm_date_started'], true) && $val !== '') {
                          $val = normalizeBeneficiaryImportDate($val);
                      }
                      if ($col_name === 'civil_status' && $val !== '') $val = normalizeBeneficiaryImportCivilStatus($val);
                      
                      if (!empty($val)) {
                          if (isset($row_data[$col_name]) && $row_data[$col_name] !== '') {
                              if ($col_name === 'contact_no' && $row_data[$col_name] !== $val) {
                                  $row_data[$col_name] .= ' / ' . $val;
                              } else if ($col_name === 'business_nature' && $row_data[$col_name] !== $val) {
                                  $row_data[$col_name] .= ', ' . $val;
                              }
                          } else {
                              $row_data[$col_name] = $val;
                          }
                      } elseif (!isset($row_data[$col_name])) {
                          $row_data[$col_name] = "";
                      }
                  }
                  
                  if (empty($row_data['age']) && !empty($row_data['birthdate'])) {
                      $dob = new DateTime($row_data['birthdate']);
                      $now = new DateTime();
                      $row_data['age'] = $now->diff($dob)->y;
                  }

                  if (empty($row_data['barangay']) && !empty($row_data['street_purok_zone'])) {
                      $known_brgys = [
                        "Aguit-It", "Banocboc", "Cagbalogo", "Calangcawan Norte", "Calangcawan Sur",
                        "Guinacutan", "Mangcayo", "Mangcawayan", "Manlucugan", "Matango",
                        "Napilihan", "Pinagtigasan", "Barangay I (Pob.)", "Barangay II (Pob.)",
                        "Barangay III (Pob.)", "Sabang", "Santo Domingo", "Singi", "Sula"
                      ];
                      foreach ($known_brgys as $kb) {
                          if (stripos($row_data['street_purok_zone'], $kb) !== false) {
                              $row_data['barangay'] = $kb;
                              break;
                          }
                      }
                  }

                  $first_name = $row_data['first_name'] ?? "";
                  $middle_name = $row_data['middle_name'] ?? "";
                  $last_name = $row_data['last_name'] ?? "";
                  $full_name = $row_data['full_name'] ?? "";
                  
                  if (!empty($full_name) && empty($first_name) && empty($last_name)) {
                      $nameParts = preg_split('/\s+/', trim($full_name));
                      $first_name = $nameParts[0] ?? '';
                      if (count($nameParts) >= 3) {
                          $last_name = array_pop($nameParts);
                          array_shift($nameParts);
                          $middle_name = implode(' ', $nameParts);
                      } else {
                          $last_name = $nameParts[1] ?? '';
                      }
                  }
                  
                  if (empty($full_name)) {
                      $full_name = trim("$first_name $middle_name $last_name");
                      $full_name = str_replace('  ', ' ', $full_name);
                  }
                  
                  if (empty($full_name)) {
                      $skippedCount++;
                      continue;
                  }
                  $full_name = preg_replace('/\s+/u', ' ', cleanBeneficiaryImportText($full_name));
                  $row_data['full_name'] = $full_name;

                  $checkStmt->bind_param("is", $program_id, $full_name);
                  $checkStmt->execute();
                  $res = $checkStmt->get_result();

                  $savedBeneficiaryId = 0;
                  if ($res->num_rows > 0) {
                      $row = $res->fetch_assoc();
                      $existing_id = $row['beneficiary_id'];
                      if (updateImportedBeneficiary($conn, $existing_id, $row_data, $batch_availment)) {
                          $updatedCount++;
                          $savedBeneficiaryId = (int)$existing_id;
                      } else {
                          $failedCount++;
                      }
                  } else {
                      if (insertImportedBeneficiary($conn, $row_data, $program_id, $batch_availment, 'Pending', $peso_staff_id, 'PESO Staff')) {
                          $successCount++;
                          $savedBeneficiaryId = (int)$conn->insert_id;
                      } else {
                          $failedCount++;
                      }
                  }

                  if ($savedBeneficiaryId > 0) {
                      if (in_array($batch_availment, ['Orientation', 'Salary Distribution'], true) && $batch_schedule_date !== '') {
                          $scheduleStmt = $conn->prepare("UPDATE beneficiaries SET date_availed = ?, last_availed_at = ? WHERE beneficiary_id = ?");
                          if ($scheduleStmt) {
                              $batch_last_availed = $batch_schedule_date . ' 00:00:00';
                              $scheduleStmt->bind_param('ssi', $batch_schedule_date, $batch_last_availed, $savedBeneficiaryId);
                              $scheduleStmt->execute();
                              $scheduleStmt->close();
                          }
                      }
                      $emailError = null;
                      if (sendBENEPESOStatusEmail($conn, $savedBeneficiaryId, $batch_availment, $emailError, $batch_message, $batch_schedule_date, $batch_schedule_place)) {
                          $emailSentCount++;
                      } else {
                          $emailFailedCount++;
                          $emailFailureReason = trim((string)($emailError ?: 'Unknown email delivery error.'));
                          $emailFailureReasons[$emailFailureReason] = ($emailFailureReasons[$emailFailureReason] ?? 0) + 1;
                          error_log("BENEPESO bulk import email not sent for beneficiary_id={$savedBeneficiaryId}: " . $emailFailureReason);
                      }
                  }
              }
              $checkStmt->close();

              $emailSummary = " Status emails: $emailSentCount sent, $emailFailedCount not sent.";

              if ($failedCount > 0) {
                  $_SESSION["show_error_modal"] = true;
                  $_SESSION["error_modal_message"] = "$successCount new and $updatedCount existing records were saved, but $failedCount row(s) failed. $skippedCount row(s) without a beneficiary name were skipped." . $emailSummary;
              } else {
                  $_SESSION["show_success_modal"] = true;
                  $_SESSION["success_modal_message"] = "$successCount new records uploaded (Pending). $updatedCount existing records fully updated to '$batch_availment'." . ($skippedCount ? " $skippedCount unnamed row(s) skipped." : "") . $emailSummary;
                  $_SESSION["import_summary"] = [
                      "added" => $successCount,
                      "updated" => $updatedCount,
                      "skipped" => $skippedCount,
                      "emails_sent" => $emailSentCount,
                      "emails_failed" => $emailFailedCount,
                      "email_failure_reasons" => $emailFailureReasons,
                      "status" => $batch_availment
                  ];
              }
          } else {
              $_SESSION["show_error_modal"] = true;
              $_SESSION["error_modal_message"] = "Invalid file type. Please upload a valid Excel (.xlsx) or CSV (.csv) file.";
          }
      } else {
          $_SESSION["show_error_modal"] = true;
          $_SESSION["error_modal_message"] = $program_id <= 0
              ? "Please select a valid program batch before importing."
              : "The selected file could not be uploaded. Please check its size and try again.";
      }
      header("Location: peso_staff_beneficiaries.php" . ($program_name_post ? "?program_name=" . urlencode($program_name_post) : ""));
      exit();
  }
}

/* =========================
   VIEW MODE & FILTERS
========================= */
$selectedProgramName = isset($_GET["program_name"]) ? trim($_GET["program_name"]) : "";
$reportProgramTitle = getReportProgramTitle($selectedProgramName);
$selectedProgramId = isset($_GET["program_id"]) ? (int)$_GET["program_id"] : 0; 
$selectedTupadCategory = trim($_GET["tupad_category"] ?? "All");
$selectedBarangay = trim($_GET["barangay"] ?? "All");
$search = trim($_GET["search"] ?? "");
$sort = trim($_GET["sort"] ?? "newest");
$approvalFilter = trim($_GET["approval"] ?? "All");
$availmentFilter = trim($_GET["availment"] ?? "All");
$selectedNature = trim($_GET["business_nature"] ?? "All");

/* =========================
   FETCH BUSINESS NATURES (MSME ONLY)
========================= */
$businessNatures = [];
if (stripos($selectedProgramName, 'MSME') !== false) {
    $bnSql = "SELECT DISTINCT business_nature FROM beneficiaries b JOIN programs p ON b.program_id = p.program_id WHERE p.program_name = ? AND b.business_nature IS NOT NULL AND b.business_nature != '' ORDER BY b.business_nature ASC";
    $bnStmt = $conn->prepare($bnSql);
    if ($bnStmt) {
        $bnStmt->bind_param("s", $selectedProgramName);
        $bnStmt->execute();
        $bnRes = $bnStmt->get_result();
        while($bnRow = $bnRes->fetch_assoc()) {
            $businessNatures[] = $bnRow['business_nature'];
        }
        $bnStmt->close();
    }
}

$programs = [];
$sqlPrograms = "SELECT c.program_name, c.image_path, (SELECT COUNT(b.beneficiary_id) FROM beneficiaries b JOIN programs p ON b.program_id = p.program_id WHERE p.program_name = c.program_name) AS beneficiary_count FROM program_categories c ORDER BY c.program_name ASC";
$resPrograms = $conn->query($sqlPrograms);
if ($resPrograms) { while ($row = $resPrograms->fetch_assoc()) { $programs[] = $row; } }

$globalTotalBeneficiaries = 0; $globalApprovedBeneficiaries = 0; $globalPendingBeneficiaries = 0; $globalOngoingAvailments = 0;
$res = $conn->query("SELECT COUNT(*) AS total FROM beneficiaries"); if ($res && ($row = $res->fetch_assoc())) $globalTotalBeneficiaries = (int)($row["total"] ?? 0);
$res = $conn->query("SELECT COUNT(*) AS total FROM beneficiaries WHERE approval_status = 'Approved'"); if ($res && ($row = $res->fetch_assoc())) $globalApprovedBeneficiaries = (int)($row["total"] ?? 0);
$res = $conn->query("SELECT COUNT(*) AS total FROM beneficiaries WHERE approval_status = 'Pending'"); if ($res && ($row = $res->fetch_assoc())) $globalPendingBeneficiaries = (int)($row["total"] ?? 0);
$res = $query = $conn->query("SELECT COUNT(*) AS total FROM beneficiaries WHERE availment_status = 'Ongoing'"); if ($res && ($row = $res->fetch_assoc())) $globalOngoingAvailments = (int)($row["total"] ?? 0);

$beneficiaries = [];
$batches = [];
$totalRecords = 0;
$totalPages = 1;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 7; 
$offset = ($page - 1) * $limit;

if ($selectedProgramName !== "") {

  $stmt = $conn->prepare("SELECT program_id, program_code FROM programs WHERE program_name = ? ORDER BY created_at DESC");
  if ($stmt) {
      $stmt->bind_param("s", $selectedProgramName);
      $stmt->execute();
      $res = $stmt->get_result();
      while($r = $res->fetch_assoc()) { $batches[] = $r; }
      $stmt->close();
  }

  $whereParts = ["p.program_name = ?"];
  $params = [$selectedProgramName];
  $types = "s";

  if ($selectedProgramId > 0) { $whereParts[] = "b.program_id = ?"; $params[] = $selectedProgramId; $types .= "i"; }
  if (stripos($selectedProgramName, 'TUPAD') !== false && $selectedTupadCategory !== 'All') { $whereParts[] = "COALESCE(NULLIF(TRIM(p.tupad_category), ''), 'Regular TUPAD') = ?"; $params[] = $selectedTupadCategory; $types .= "s"; }
  if ($selectedBarangay !== "All") { $whereParts[] = "b.barangay = ?"; $params[] = $selectedBarangay; $types .= "s"; }
  if ($search !== "") {
    $whereParts[] = "(b.full_name LIKE ? OR b.email LIKE ? OR b.first_name LIKE ? OR b.last_name LIKE ?)";
    $searchLike = "%" . $search . "%";
    array_push($params, $searchLike, $searchLike, $searchLike, $searchLike);
    $types .= "ssss";
  }
  if ($approvalFilter !== "All") { $whereParts[] = "b.approval_status = ?"; $params[] = $approvalFilter; $types .= "s"; }
  if ($availmentFilter !== "All") { $whereParts[] = "b.availment_status = ?"; $params[] = $availmentFilter; $types .= "s"; }
  
  if (stripos($selectedProgramName, 'MSME') !== false && $selectedNature !== "All") {
      $whereParts[] = "b.business_nature = ?";
      $params[] = $selectedNature;
      $types .= "s";
  }

  $countSql = "SELECT COUNT(b.beneficiary_id) as total FROM beneficiaries b JOIN programs p ON b.program_id = p.program_id WHERE " . implode(" AND ", $whereParts);
  $countStmt = $conn->prepare($countSql);
  if ($countStmt) {
    if (!empty($types)) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $countRes = $countStmt->get_result()->fetch_assoc();
    $totalRecords = (int)($countRes['total'] ?? 0);
    $countStmt->close();
  }
  $totalPages = max(1, ceil($totalRecords / $limit));
  $orderBy = "b.created_at DESC, b.beneficiary_id DESC";
  
  $sqlList = "SELECT b.*, p.program_code,
      CASE WHEN b.user_id IS NOT NULL THEN 'Online Applicant'
           WHEN b.application_source = 'Admin' THEN 'Administrator'
           WHEN b.application_source = 'PESO Staff' THEN COALESCE(NULLIF(TRIM(CONCAT(s.first_name, ' ', s.last_name)), ''), 'PESO Staff')
           WHEN b.application_source IS NULL AND b.created_by IS NOT NULL AND s.staff_id IS NOT NULL
             THEN COALESCE(NULLIF(TRIM(CONCAT(s.first_name, ' ', s.last_name)), ''), 'PESO Staff')
           ELSE 'Legacy Record' END AS added_by_name
      FROM beneficiaries b JOIN programs p ON b.program_id = p.program_id
      LEFT JOIN peso_staff s ON b.created_by = s.staff_id
      WHERE " . implode(" AND ", $whereParts) . " ORDER BY $orderBy LIMIT ? OFFSET ?";
  $paramsPaginated = $params;
  $typesPaginated = $types;
  array_push($paramsPaginated, $limit, $offset);
  $typesPaginated .= "ii";

  $stmt = $conn->prepare($sqlList);
  if ($stmt) {
    $stmt->bind_param($typesPaginated, ...$paramsPaginated);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $beneficiaries[] = $row; }
    $stmt->close();
  }

  $allBeneficiariesForReport = [];
  
  $hasStartDate = column_exists($conn, "programs", "start_date");
  $hasEndDate = column_exists($conn, "programs", "end_date");
  $progDateCols = "";
  if ($hasStartDate) $progDateCols .= ", p.start_date";
  if ($hasEndDate) $progDateCols .= ", p.end_date";
  
  $sqlAll = "SELECT b.*, p.program_code, p.program_name $progDateCols FROM beneficiaries b JOIN programs p ON b.program_id = p.program_id WHERE p.program_name = ?";
  $stmtAll = $conn->prepare($sqlAll);
  if ($stmtAll) {
      $stmtAll->bind_param("s", $selectedProgramName);
      $stmtAll->execute();
      $resAll = $stmtAll->get_result();
      while ($row = $resAll->fetch_assoc()) { $allBeneficiariesForReport[] = $row; }
      $stmtAll->close();
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="img/pesologo.png">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BENEPESO | Staff Beneficiaries</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <link rel="stylesheet" href="peso_staff_beneficiaries.css?v=20260820-dependent-modal-polish">
  <link rel="stylesheet" href="shared_sidebar.css">
  <style>
      /* Temporary Inline Styles to enforce the A4 Preview Look */
      .spreadsheet-table { border-collapse: collapse; width: max-content; min-width: 100%; table-layout: auto; }
      .spreadsheet-table th, .spreadsheet-table td { border: 1px solid #ccc; padding: 6px 3px; font-size: 9px; white-space: normal; overflow-wrap: anywhere; }
      .spreadsheet-table thead th { background: #e6f4ed; color: #0d2618; position: sticky; top: 0; z-index: 10; font-weight: 700;}
  </style>
  <link rel="stylesheet" href="frontend_polish.css?v=2">
  <script src="frontend_polish.js?v=1" defer></script>
</head>
<body>
  <div class="page-wrap">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <aside class="side-area" id="sideArea">
    <div class="side-top">
      <div class="side-brand">
        <img src="img/pesologo.png" alt="PESO Logo" class="side-logo">
        <div>
          <div class="side-title">BENEPESO</div>
          <div class="side-sub">PESO Staff Panel</div>
        </div>
      </div>
      <button class="side-close" id="sideClose" type="button" aria-label="Close menu">
        <i class="ph-bold ph-x"></i>
      </button>
    </div>

    <div class="side-user">
      <div class="user-pic-wrap">
        <img src="<?php echo h($pic_path); ?>" alt="Staff" class="user-img-side" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($staff_name); ?>&background=1f7a54&color=fff';">
      </div>
      <div>
        <div class="user-name"><?php echo h($staff_name); ?></div>
        <div class="user-role"><?php echo h($staff_position); ?></div>
      </div>
    </div>

    <nav class="nav-area">
      <a href="peso_staff_dashboard.php" class="nav-item"><i class="ph-fill ph-squares-four"></i> Dashboard</a>
      <a href="peso_staff_program.php" class="nav-item"><i class="ph-fill ph-briefcase"></i> Program</a>
      <a href="peso_staff_beneficiaries.php" class="nav-item active"><i class="ph-fill ph-users"></i> Beneficiaries</a>
      <a href="peso_staff_activity_log.php" class="nav-item"><i class="ph-fill ph-clock-counter-clockwise"></i> Activity Log</a>
      <a href="logout.php?role=peso_staff" class="nav-item logout-item"><i class="ph-bold ph-sign-out"></i> Logout</a>
    </nav>
  </aside>

  <main class="main-area">
    <header class="top-area animate-fade-in">
      <div class="top-left">
        <button class="menu-toggle" id="menuToggle" type="button"><span></span><span></span><span></span></button>
        <?php if ($selectedProgramName !== ""): ?>
            <nav class="breadcrumb">
                <a href="peso_staff_beneficiaries.php"><i class="ph-fill ph-users" style="font-size: 1rem; margin-right: 6px;"></i> Beneficiaries</a>
                <span class="separator">/</span><span class="current"><?php echo h($selectedProgramName); ?></span>
            </nav>
            <div class="top-big"><?php echo h($selectedProgramName); ?> Records</div>
            <div class="top-sub">Review and add beneficiaries under <?php echo h($selectedProgramName); ?>.</div>
        <?php else: ?>
            <div class="eyebrow">Beneficiary Management</div>
            <div class="top-big">Staff Beneficiaries</div>
            <div class="top-sub">Select a program to view and add beneficiaries.</div>
        <?php endif; ?>
      </div>

      <div class="top-actions">
        <?php if ($selectedProgramName !== ""): ?>
          <button class="btn-main" type="button" id="openAddBeneficiaryModal"><i class="ph-bold ph-plus" style="font-size: 1.1rem; margin-right: 6px;"></i> Add Manual</button>
          <button class="btn-light" type="button" id="openBulkUploadModal"><i class="ph-bold ph-upload-simple" style="font-size: 1.1rem; margin-right: 6px;"></i> Import File</button>
        <?php endif; ?>
        <div class="top-chip">
            <img src="<?php echo h($pic_path ?? ''); ?>" alt="" class="chip-img" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($staff_name); ?>&background=1f7a54&color=fff';">
            <?php echo h($staff_name); ?>
        </div>
      </div>
    </header>

    <?php if ($selectedProgramName === ""): ?>
      <section class="stats-grid animate-fade-in" style="animation-delay: 0.1s;">
        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-label">TOTAL BENEFICIARIES</div>
                <div class="stat-icon-sm" style="color: var(--green); background: var(--green-light);"><i class="ph-fill ph-users-three"></i></div>
            </div>
            <div class="stat-value"><?php echo (int)$globalTotalBeneficiaries; ?></div>
            <div class="stat-trend trend-up"><i class="ph-bold ph-trend-up"></i> System Tracked</div>
            <div class="stat-note">Total recorded beneficiaries across the system.</div>
        </div>
        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-label">APPROVED</div>
                <div class="stat-icon-sm" style="color:#1a6d41; background:#e6f6ec;"><i class="ph-fill ph-check-circle"></i></div>
            </div>
            <div class="stat-value"><?php echo (int)$globalApprovedBeneficiaries; ?></div>
            <div class="stat-trend trend-up"><i class="ph-bold ph-check"></i> Verified</div>
            <div class="stat-note">Fully approved beneficiary applications.</div>
        </div>
        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-label">NEED APPROVAL</div>
                <div class="stat-icon-sm" style="color:#b06900; background:#fff2e0;"><i class="ph-fill ph-clock-countdown"></i></div>
            </div>
            <div class="stat-value"><?php echo (int)$globalPendingBeneficiaries; ?></div>
            <div class="stat-trend trend-warning"><i class="ph-bold ph-warning-circle"></i> Action Required</div>
            <div class="stat-note">Applications awaiting administrative review.</div>
        </div>
        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-label">ONGOING AVAILED</div>
                <div class="stat-icon-sm" style="color:#2a5a8a; background:#e8eff4;"><i class="ph-fill ph-play-circle"></i></div>
            </div>
            <div class="stat-value"><?php echo (int)$globalOngoingAvailments; ?></div>
            <div class="stat-trend trend-neutral"><i class="ph-bold ph-arrows-clockwise"></i> Operational</div>
            <div class="stat-note">Beneficiaries currently active in programs.</div>
        </div>
      </section>

      <section class="panel-card animate-fade-in" style="animation-delay: 0.2s; margin-top: 8px;">
        <div class="panel-head-flex"><div><div class="panel-title">Programs Overview</div><div class="panel-sub">Select a program below to review applications and beneficiaries.</div></div></div>
        <div class="program-grid">
          <?php foreach ($programs as $program): ?>
            <article class="program-card-shell">
              <div class="program-image-wrap"><img src="<?php echo !empty($program["image_path"]) ? h($program["image_path"]) : "img/pesobgs.jpg"; ?>" class="program-image"></div>
              <div class="program-body">
                <h3 class="program-title"><?php echo h($program["program_name"]); ?></h3>
                
                <div class="styled-record-counter">
                    <i class="ph-fill ph-users"></i>
                    <span><strong><?php echo h($program["beneficiary_count"]); ?></strong> Total Records</span>
                </div>
                
                <div class="card-actions">
                    <a href="peso_staff_beneficiaries.php?program_name=<?php echo urlencode($program["program_name"]); ?>" class="btn-directory">
                        <i class="ph-fill ph-folder-open"></i> Directory
                    </a>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

    <?php else: ?>
      <section class="panel-card animate-fade-in" style="flex: 1; animation-delay: 0.1s; margin-top: 8px; display: flex; flex-direction: column;">
        <div class="records-toolbar">
            <div class="custom-tabs">
                <a href="peso_staff_beneficiaries.php?<?php echo h(build_query(['approval' => 'All', 'page' => 1])); ?>" class="tab-item <?php echo $approvalFilter === 'All' ? 'active' : ''; ?>">All Records</a>
                <a href="peso_staff_beneficiaries.php?<?php echo h(build_query(['approval' => 'Pending', 'page' => 1])); ?>" class="tab-item <?php echo $approvalFilter === 'Pending' ? 'active' : ''; ?>">Needs Approval</a>
                <a href="peso_staff_beneficiaries.php?<?php echo h(build_query(['approval' => 'Approved', 'page' => 1])); ?>" class="tab-item <?php echo $approvalFilter === 'Approved' ? 'active' : ''; ?>">Approved</a>
                <a href="peso_staff_beneficiaries.php?<?php echo h(build_query(['approval' => 'Rejected', 'page' => 1])); ?>" class="tab-item <?php echo $approvalFilter === 'Rejected' ? 'active' : ''; ?>">Rejected</a>
            </div>
            
            <button class="btn-main" type="button" id="openReportModal" style="margin-bottom: 12px;">
                <i class="ph-bold ph-file-text" style="font-size: 1.1rem; margin-right: 6px;"></i> Generate Report
            </button>
        </div>

        <div class="bulk-selection-bar" id="bulkSelectionBar" hidden>
          <div><strong id="bulkSelectedCount">0</strong> selected <span>Choose a bulk action for these beneficiaries.</span></div>
          <div class="bulk-selection-actions">
            <button type="button" class="btn-light" id="clearBulkSelection">Clear</button>
            <button type="button" class="btn-main" id="openBulkStatusModal"><i class="ph-bold ph-arrows-clockwise"></i> Update Availment & Email</button>
          </div>
        </div>

        <form method="GET" class="filter-container" id="filterForm">
            <input type="hidden" name="program_name" value="<?php echo h($selectedProgramName); ?>">
            <input type="hidden" name="approval" value="<?php echo h($approvalFilter); ?>">
            <input type="hidden" name="page" value="1">
            
            <div class="filter-search">
              <i class="ph-bold ph-magnifying-glass search-icon"></i>
              <input type="text" name="search" value="<?php echo h($search); ?>" placeholder="Search by name, email..." class="search-input" id="liveSearchInput">
            </div>
            
            <select name="program_id" class="filter-select auto-submit">
                <option value="0">All Batches</option>
                <?php foreach($batches as $b): ?><option value="<?php echo $b['program_id']; ?>" <?php echo $selectedProgramId == $b['program_id'] ? 'selected' : ''; ?>><?php echo h($b['program_code']); ?></option><?php endforeach; ?>
            </select>
            <?php if (stripos($selectedProgramName, 'TUPAD') !== false): ?>
            <select name="tupad_category" class="filter-select auto-submit" aria-label="Filter beneficiaries by TUPAD category">
              <option value="All">All TUPAD Categories</option>
              <?php foreach (available_tupad_categories($conn) as $category): ?><option value="<?php echo h($category); ?>" <?php echo $selectedTupadCategory === $category ? 'selected' : ''; ?>><?php echo h($category); ?></option><?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="barangay" class="filter-select auto-submit">
              <option value="All">All Barangays</option>
              <?php foreach ($barangays as $barangay): ?><option value="<?php echo h($barangay); ?>" <?php echo $selectedBarangay === $barangay ? 'selected' : ''; ?>><?php echo h($barangay); ?></option><?php endforeach; ?>
            </select>
            <select name="availment" class="filter-select auto-submit">
              <option value="All" <?php echo $availmentFilter === 'All' ? 'selected' : ''; ?>>All Availment</option>
              <option value="Not Yet Availed" <?php echo $availmentFilter === 'Not Yet Availed' ? 'selected' : ''; ?>>Not Yet Availed</option>
              <option value="Requirements Received" <?php echo $availmentFilter === 'Requirements Received' ? 'selected' : ''; ?>>Requirements Received</option>
              <option value="Orientation" <?php echo $availmentFilter === 'Orientation' ? 'selected' : ''; ?>>Orientation</option>
              <option value="Ongoing" <?php echo $availmentFilter === 'Ongoing' ? 'selected' : ''; ?>>Ongoing</option>
              <option value="Salary Distribution" <?php echo $availmentFilter === 'Salary Distribution' ? 'selected' : ''; ?>>Salary Distribution</option>
              <option value="Completed" <?php echo $availmentFilter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
              <option value="Not Qualified" <?php echo $availmentFilter === 'Not Qualified' ? 'selected' : ''; ?>>Not Qualified</option>
            </select>
            
            <?php if (stripos($selectedProgramName, 'MSME') !== false): ?>
            <select name="business_nature" class="filter-select auto-submit">
              <option value="All">All Vendor Types</option>
              <?php foreach ($businessNatures as $nature): ?>
                  <option value="<?php echo h($nature); ?>" <?php echo $selectedNature === $nature ? 'selected' : ''; ?>><?php echo h($nature); ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </form>

        <?php if (!$beneficiaries): ?>
          <div class="empty-state">
            <i class="ph-fill ph-users empty-icon"></i>
            <div class="empty-title">No beneficiaries found</div>
            <div class="empty-text">There are no records matching your current filters.</div>
          </div>
        <?php else: ?>
          <div class="table-wrap">
              <table class="data-table">
                  <thead>
                      <tr>
                           <th style="width: 62px; text-align: center;"><label class="bulk-check-label"><input type="checkbox" id="selectAllBeneficiaries" aria-label="Select all visible beneficiaries"><span>No.</span></label></th>
                          <th>Profile</th>
                          <th>Barangay</th>
                          <th>Batch Code</th>
                          <th style="text-align: center;">Availment</th>
                          <th style="text-align: center;">Actions</th>
                      </tr>
                  </thead>
                  <tbody>
                      <?php 
                      $counter = $offset + 1;
                      foreach ($beneficiaries as $beneficiary): 
                          $bId = (int)$beneficiary["beneficiary_id"];
                          $bProgId = (int)$beneficiary["program_id"];
                          $beneficiaryApproval = $beneficiary["approval_status"] ?? "Pending";
                          $availmentStatus = $beneficiary["availment_status"] ?? "Not Yet Availed";
                          $addedBy = trim($beneficiary["added_by_name"] ?? "Online Applicant"); 
                          
                          $dispName = trim($beneficiary["full_name"] ?? "");
                          if ($dispName === "" || strpos(strtolower($dispName), 'null null') !== false) {
                               $dispName = trim(($beneficiary["first_name"] ?? "") . " " . ($beneficiary["last_name"] ?? ""));
                          }
                          $initial = strtoupper(substr($dispName, 0, 1));
                          
                          $bData = $beneficiary;
                          $bData['program'] = $selectedProgramName;
                          $bData['added_by'] = $addedBy;
                          $bData['name'] = $dispName;
                          $bData['initial'] = $initial;
                          // Standardize names for JS
                          $bData['availment'] = $availmentStatus;
                          $bData['approval'] = $beneficiaryApproval;
                          $bData['id'] = $bId;

                          $profileData = json_encode($bData, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
                      ?>
                      <tr class="table-row-animate clickable-row" data-profile='<?php echo $profileData; ?>' onclick="window.openProfileModal(this)">
                          <td style="text-align: center; font-weight: 700; color: var(--muted); font-size: 13px;" onclick="event.stopPropagation();"><label class="bulk-row-check"><input type="checkbox" class="beneficiary-select" value="<?php echo $bId; ?>" aria-label="Select <?php echo h($dispName); ?>"><span><?php echo $counter++; ?></span></label></td>
                          <td>
                              <div style="display: flex; align-items: center; gap: 12px;">
                                  <div class="avatar-circle" style="width: 40px; height: 40px; font-size: 15px;"><?php echo h($initial); ?></div>
                                  <div>
                                      <div style="font-weight: 800; color: var(--green-dark); font-size: 13.5px; text-transform: uppercase;"><?php echo h($dispName); ?></div>
                                      <div style="font-size: 11.5px; color: var(--muted); margin-top: 2px;">Added <?php echo format_date_value($beneficiary["created_at"]); ?></div>
                                  </div>
                              </div>
                          </td>
                          <td style="font-weight: 600; text-transform: uppercase; color: var(--text); font-size: 13px;">
                              <i class="ph-fill ph-map-pin" style="color: var(--muted); margin-right:4px;"></i><?php echo h($beneficiary["barangay"] ?? "—"); ?>
                          </td>
                          <td><div style="font-weight: 700; color: var(--muted); font-size: 12.5px;"><?php echo h($beneficiary["program_code"] ?? "—"); ?></div></td>
                          <td style="text-align: center;">
                              <span class="<?php echo h(availment_badge($availmentStatus)); ?>">
                                  <span class="pill-dot" style="background:currentColor;"></span> <?php echo h($availmentStatus); ?>
                              </span>
                          </td>
                          <td style="text-align: center;" onclick="event.stopPropagation();">
                              <div class="action-menu-wrap" style="justify-content: center;">
                                  <?php if ($beneficiaryApproval === 'Approved'): ?>
                                      <button type="button" class="beneficiary-availment-button" onclick="openQuickStatusModal(<?php echo $bId; ?>, '<?php echo addslashes($availmentStatus); ?>', '<?php echo addslashes($beneficiary['date_availed'] ?? ''); ?>', '<?php echo addslashes($beneficiary['date_completed'] ?? ''); ?>')" title="Update availment">
                                          <i class="ph-bold ph-timer" aria-hidden="true"></i>
                                          <span>Availment</span>
                                      </button>
                                  <?php endif; ?>
                              </div>
                          </td>
                      </tr>
                      <?php endforeach; ?>
                  </tbody>
              </table>
          </div>
          <?php if($totalPages > 1): ?>
          <div class="pagination-wrapper">
              <span class="page-info">Showing <?php echo $totalRecords > 0 ? $offset + 1 : 0; ?> to <?php echo min($offset + $limit, $totalRecords); ?> of <?php echo $totalRecords; ?> entries</span>
              <div class="pagination-controls">
                  <a href="?<?php echo h(build_query(['page' => max(1, $page - 1)])); ?>" class="page-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>"><i class="ph-bold ph-caret-left"></i> Prev</a>
                  <a href="?<?php echo h(build_query(['page' => min($totalPages, $page + 1)])); ?>" class="page-btn <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">Next <i class="ph-bold ph-caret-right"></i></a>
              </div>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>
</div>

<?php if ($selectedProgramName !== ""): ?>

<div class="modal" id="bulkStatusActionModal" aria-hidden="true">
  <div class="modal-backdrop" data-close-bulk-status></div>
  <div class="modal-dialog modal-dialog-sm bulk-status-dialog">
    <div class="modal-head-alt">
      <div><div class="modal-title">Bulk Availment Update</div><div class="modal-sub"><span class="bulk-modal-count">0</span> beneficiaries will be updated and emailed.</div></div>
      <button type="button" class="modal-close-icon" data-close-bulk-status><i class="ph-bold ph-x"></i></button>
    </div>
    <form method="POST" class="modal-form" id="bulkStatusForm">
      <input type="hidden" name="action" value="bulk_status_update">
      <input type="hidden" name="program_name" value="<?php echo h($selectedProgramName); ?>">
      <div id="bulkSelectedInputs"></div>
      <div class="form-grid">
        <div class="form-group span-2"><label>New Availment Status *</label>
          <select name="availment_status" id="bulkAvailmentStatus" required>
            <option value="Requirements Received">Requirements Received</option><option value="Orientation">Orientation</option><option value="Ongoing">Ongoing</option><option value="Salary Distribution">Salary Distribution</option><option value="Completed">Completed</option><option value="Not Qualified">Not Qualified</option><option value="Cancelled">Cancelled</option>
          </select>
        </div>
        <div class="form-group span-2 bulk-message-field" hidden><label>Reason for this status *</label><textarea name="status_message" rows="4" placeholder="Explain why the beneficiaries are not qualified or why their availment was cancelled."></textarea></div>
        <div class="form-group bulk-schedule-field" hidden><label>Date</label><input type="date" name="schedule_date"></div>
        <div class="form-group bulk-schedule-field" hidden><label>Place</label><input type="text" name="schedule_place" placeholder="PESO Office or venue"></div>
      </div>
      <div class="modal-actions-flex"><button type="button" class="btn-light" data-close-bulk-status>Cancel</button><button type="submit" class="btn-main"><i class="ph-bold ph-paper-plane-tilt"></i> Update & Send Emails</button></div>
    </form>
  </div>
</div>
<div class="modal" id="adminAddBeneficiaryModal" aria-hidden="true">
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-dialog modal-dialog-large">
    <div class="modal-head-alt">
      <div>
        <div class="modal-title" id="modalTitle">Add Beneficiary Record</div>
        <div class="modal-sub" id="modalSub">Create a new profile under <?php echo h($selectedProgramName); ?>.</div>
      </div>
      <button type="button" class="modal-close-icon" data-close-modal><i class="ph-bold ph-x"></i></button>
    </div>

    <div class="wizard-nav" id="wizardNav"></div>

    <form method="POST" class="modal-form" id="multiStepForm" novalidate>
      <input type="hidden" name="action" id="modalAction" value="admin_add_beneficiary">
      <input type="hidden" name="beneficiary_id" id="edit_id" value="">
      <input type="hidden" name="program_name" value="<?php echo h($selectedProgramName); ?>">

      <div class="form-step active" id="step-1">
          <div class="form-grid-2">
              <div class="form-group span-2">
                  <label>Select Batch *</label>
                  <select name="program_id" id="modal_program_id" required>
                      <option value="">-- Select Batch --</option>
                      <?php foreach($batches as $b): ?>
                          <option value="<?php echo $b['program_id']; ?>"><?php echo h($b['program_code']); ?></option>
                      <?php endforeach; ?>
                  </select>
              </div>

              <div class="span-2 section-title"><i class="ph-fill ph-user-circle"></i> Basic Information</div>
              <div class="form-group"><label>First Name</label><input type="text" name="first_name" id="first_name" required></div>
              <div class="form-group"><label>Last Name</label><input type="text" name="last_name" id="last_name" required></div>
              <div class="form-group"><label>Middle Name</label><input type="text" name="middle_name" id="middle_name" required></div>
              <div class="form-group"><label>Extension Name (Optional)</label><input type="text" name="ext_name" id="ext_name" class="not-required" placeholder="Jr, Sr, etc."></div>
              <div class="form-group span-2"><label>Full Address (Street/Purok, Barangay)</label><input type="text" name="street_purok_zone" id="street_purok_zone" placeholder="Street/Purok" required></div>
              <div class="form-group">
                  <label>Barangay</label>
                  <select name="barangay" id="barangay" required>
                      <option value="">-- Select Barangay --</option>
                      <?php foreach ($barangays as $barangay): ?>
                          <option value="<?php echo h($barangay); ?>"><?php echo h($barangay); ?></option>
                      <?php endforeach; ?>
                  </select>
              </div>
              <div class="form-group"><label>Contact No.</label><input type="text" name="contact_no" id="contact_no" required></div>
          </div>
      </div>

      <?php if (stripos($selectedProgramName, 'TUPAD') !== false): ?>
          <div class="form-step" id="tupad-step-2">
              <div class="form-grid-2">
                  <div class="span-2 section-title"><i class="ph-fill ph-identification-badge"></i> Basic Profiling</div>
                  <div class="form-group">
                      <label>Type of ID</label>
                      <select name="type_of_id" id="type_of_id" onchange="toggleOther(this, 'tupad_id_other')" required>
                          <option value="">--Select--</option><option value="PhilID">PhilID</option><option value="Voter's ID">Voter's ID</option><option value="Others">Others</option>
                      </select>
                      <input type="text" name="other_type_of_id" id="tupad_id_other" style="display:none; margin-top:5px;" placeholder="Specify ID" class="not-required" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s.-]/g, '')">
                  </div>
                  <div class="form-group"><label>ID Number</label><input type="text" name="id_number" id="id_number" oninput="this.value = this.value.replace(/[^a-zA-Z0-9-]/g, '')" required></div>
                  <div class="form-group"><label>Beneficiary Type</label><select name="type_of_beneficiary" id="type_of_beneficiary" onchange="toggleOther(this, 'other_type_of_beneficiary')" required><option value="">--Select--</option><?php render_beneficiary_options('beneficiary_type'); ?></select><input type="text" name="other_type_of_beneficiary" id="other_type_of_beneficiary" class="not-required" style="display:none;margin-top:5px" placeholder="Specify beneficiary type"></div>
                  <div class="form-group">
                      <label>Occupation</label>
                      <select name="occupation" id="occupation" onchange="toggleOther(this, 'other_occupation')" required>
                          <option value="">--Select--</option><?php render_beneficiary_options('occupation'); ?>
                      </select>
                      <input type="text" name="other_occupation" id="other_occupation" style="display:none; margin-top:5px;" placeholder="Specify Occupation" class="not-required" oninput="this.value = this.value.replace(/[^a-zA-ZñÑ\s.-]/g, '')">
                  </div>
                  <div class="form-group"><label>Avg Monthly Income</label><input type="text" name="avg_monthly_income" id="avg_monthly_income" placeholder="e.g. 5000" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required></div>
                  <div class="form-group"><label>Interested in Wage Employment?</label><select name="interested_in_employment" id="interested_in_employment" required><option value="No">No</option><option value="Yes">Yes</option></select></div>
                  <div class="form-group"><label>Sex</label><select name="sex" id="sex" required><option value="">-- Select --</option><option value="Male">Male</option><option value="Female">Female</option></select></div>
                  <div class="form-group"><label>Civil Status</label><select name="civil_status" id="civil_status" required><option value="">-- Select --</option><option value="Single">Single</option><option value="Married">Married</option><option value="Widowed">Widowed</option><option value="Legally Separated">Legally Separated</option></select></div>
                  <div class="form-group"><label>Date of Birth</label><input type="date" name="birthdate" id="birthdateInput" required></div>
                  <div class="form-group"><label>Age</label><input type="number" name="age" id="ageOutput" readonly style="background:#f4f8f5;" required></div>
              </div>
          </div>
          <div class="form-step" id="tupad-step-3">
              <div class="form-grid-2">
                  <div class="span-2 section-title"><i class="ph-fill ph-users-three"></i> Dependents, Skills & Status</div>
                  <div class="form-group"><label>Dependent Name</label><input type="text" name="dependent_name" id="dependent_name" class="not-required" placeholder="If applicable" oninput="this.value = this.value.replace(/[^a-zA-ZñÑ\s.-]/g, '')"></div>
                  <div class="form-group"><label>Relationship to Dependent</label>
                      <select name="dependent_relationship" id="dependent_relationship" class="not-required" onchange="toggleOther(this, 'other_dependent_relationship')">
                          <option value="">--Select--</option>
                          <option value="Spouse">Spouse</option>
                          <option value="Child">Child</option>
                          <option value="Parent">Parent</option>
                          <option value="Sibling">Sibling</option>
                          <option value="Others">Others</option>
                      </select><input type="text" name="other_dependent_relationship" id="other_dependent_relationship" class="not-required" style="display:none;margin-top:5px" placeholder="Specify relationship">
                  </div>
                  <div class="form-group span-2"><label>Skills Training Needed</label><select name="skills_training_needed" id="skills_training_needed" class="not-required" onchange="toggleOther(this, 'other_skills_training_needed')"><option value="">None / Not specified</option><?php render_beneficiary_options('skills_training'); ?></select><input type="text" name="other_skills_training_needed" id="other_skills_training_needed" class="not-required" style="display:none;margin-top:5px" placeholder="Specify training needed"></div>
                  
                  <div class="span-2 divider-line"></div>
                  <div class="form-group">
                      <label>Availment Status *</label>
                      <select name="availment_status" id="availment_status_input" required>
                          <option value="Not Yet Availed">Not Yet Availed</option>
                          <option value="Requirements Received">Requirements Received</option>
                          <option value="Orientation">Orientation</option>
                          <option value="Ongoing">Ongoing</option>
                          <option value="Salary Distribution">Salary Distribution</option>
                          <option value="Completed">Completed</option>
                          <option value="Not Qualified">Not Qualified</option>
                      </select>
                  </div>
                  <div class="form-group">
                      <label>LGU Approval Status</label>
                      <select name="approval_status" id="approval_status_input" disabled style="background:#f4f8f5; cursor:not-allowed; color:var(--muted);">
                          <option value="Pending">Pending</option>
                          <option value="Approved">Approved</option>
                          <option value="Rejected">Rejected</option>
                      </select>
                      <small style="color:var(--muted); font-size:11px;">* Admin will update automatically.</small>
                  </div>
                  <div id="date_fields_wrapper" style="display: none; grid-column: 1 / -1; width: 100%;">
                      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                          <div class="form-group" style="margin:0;"><label>Date Availed</label><input type="date" name="date_availed" id="date_availed"></div>
                          <div class="form-group" style="margin:0;"><label>Date Completed</label><input type="date" name="date_completed" id="date_completed"></div>
                      </div>
                  </div>
              </div>
          </div>

      <?php elseif (stripos($selectedProgramName, 'SPES') !== false): ?>
          <div class="form-step" id="spes-step-2">
              <div class="form-grid-2">
                  <div class="span-2 section-title"><i class="ph-fill ph-file-text"></i> Additional Details</div>
                  <div class="form-group"><label>GSIS Beneficiary / Policy No. (If applicable)</label><input type="text" name="gsis_beneficiary" id="gsis_beneficiary_name" class="not-required" placeholder="Optional"></div>
                  <div class="form-group"><label>Relationship to GSIS Beneficiary</label><input type="text" name="gsis_relationship" id="gsis_relationship" class="not-required" placeholder="Optional"></div>
                  <div class="form-group"><label>Place of Birth</label><input type="text" name="place_of_birth" id="place_of_birth" required></div>
                  <div class="form-group"><label>Citizenship</label><input type="text" name="citizenship" id="citizenship" value="Filipino" required></div>
                  <div class="form-group span-2"><label>Social Media URLs (Optional)</label><input type="text" name="social_urls" id="social_urls" class="not-required" placeholder="Facebook, LinkedIn..."></div>
                  <div class="form-group"><label>Email</label><input type="email" name="email" id="email" required></div>
                  <div class="form-group"><label>Date of Birth</label><input type="date" name="birthdate" id="birthdateInput" required></div>
                  <div class="form-group span-2"><label>Age</label><input type="number" name="age" id="ageOutput" readonly style="background:#f4f8f5;" required></div>
              </div>
          </div>
          <div class="form-step" id="spes-step-3">
              <div class="form-grid-2">
                  <div class="span-2 section-title"><i class="ph-fill ph-check-square-offset"></i> Applicant Status</div>
                  <div class="form-group"><label>Civil Status</label><select name="civil_status" id="civil_status" required><option value="">-- Select --</option><option value="Single">Single</option><option value="Married">Married</option><option value="Widowed">Widowed</option><option value="Legally Separated">Legally Separated</option></select></div>
                  <div class="form-group"><label>Sex</label><select name="sex" id="sex" required><option value="">-- Select --</option><option value="Male">Male</option><option value="Female">Female</option></select></div>
                  <div class="form-group span-2"><label>Student Status</label>
                      <select name="spes_type" id="spes_type" required>
                          <option value="">--Select--</option>
                          <option value="Student">Student</option>
                          <option value="ALS student">ALS student</option>
                          <option value="Out-of-school OSY">Out-of-school OSY</option>
                      </select>
                  </div>
              </div>
          </div>
          <div class="form-step" id="spes-step-4">
              <div class="form-grid-2">
                  <div class="span-2 section-title"><i class="ph-fill ph-house-line"></i> Family Background</div>
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
                      <label>Permanent Address</label>
                      <input type="text" name="permanent_address" id="permanent_address" placeholder="Enter permanent address" required>
                  </div>
                  
                  <div class="form-group span-2 section-title" style="margin-top:10px; font-size:14px;"><i class="ph-fill ph-user"></i> Father's Details</div>
                  <div class="form-group span-2"><label>Father's Name</label><input type="text" name="father_name" id="father_name" placeholder="Full Name" oninput="this.value = this.value.replace(/[^a-zA-ZñÑ\s.-]/g, '')" required></div>
                  <div class="form-group"><label>Father's Contact No.</label><input type="text" name="father_contact" id="father_contact" placeholder="09xxxxxxxxx" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required></div>
                  <div class="form-group"><label>Father's Occupation</label><select name="father_occupation" id="father_occupation" onchange="toggleOther(this, 'other_father_occupation')" required><option value="">--Select--</option><?php render_beneficiary_options('parent_occupation'); ?></select><input type="text" name="other_father_occupation" id="other_father_occupation" class="not-required" style="display:none;margin-top:5px" placeholder="Specify occupation"></div>
                  
                  <div class="form-group span-2 section-title" style="margin-top:10px; font-size:14px;"><i class="ph-fill ph-user"></i> Mother's Details</div>
                  <div class="form-group span-2"><label>Mother's Maiden Name</label><input type="text" name="mother_name" id="mother_name" placeholder="Full Name" oninput="this.value = this.value.replace(/[^a-zA-ZñÑ\s.-]/g, '')" required></div>
                  <div class="form-group"><label>Mother's Contact No.</label><input type="text" name="mother_contact" id="mother_contact" placeholder="09xxxxxxxxx" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required></div>
                  <div class="form-group"><label>Mother's Occupation</label><select name="mother_occupation" id="mother_occupation" onchange="toggleOther(this, 'other_mother_occupation')" required><option value="">--Select--</option><?php render_beneficiary_options('parent_occupation'); ?></select><input type="text" name="other_mother_occupation" id="other_mother_occupation" class="not-required" style="display:none;margin-top:5px" placeholder="Specify occupation"></div>
                  <div class="form-group span-2"><label>Estimated Monthly Family Income</label><input type="text" name="avg_monthly_income" id="avg_monthly_income" inputmode="numeric" placeholder="e.g. 10000" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required></div>
              </div>
          </div>
          <div class="form-step" id="spes-step-5">
              <div class="form-grid-2">
                  <div class="span-2 section-title"><i class="ph-fill ph-graduation-cap"></i> Educational History</div>
                  
                  <div class="form-group span-2" style="background:#fdfdfd; padding:16px; border-radius:12px; border:1px solid var(--line);">
                      <strong style="font-size:12px; color:var(--green-dark);">Elementary</strong>
                      <div style="display:flex; gap:10px; margin-top:8px;">
                          <input type="text" name="elem_school" id="elem_school" placeholder="Name of School" style="flex:2;">
                          <input type="text" name="elem_degree" id="elem_degree" value="N/A" readonly style="flex:1; background:#eee; color:#666;" title="Degree not applicable for Elementary">
                          <input type="text" name="elem_year_level" id="elem_year_level" placeholder="Year/Level" style="flex:1;">
                          <input type="number" name="elem_date_attendance" id="elem_date_attendance" placeholder="Year (e.g. 2015)" min="1950" max="2050" style="flex:1;">
                      </div>
                  </div>
                  
                  <div class="form-group span-2" style="background:#fdfdfd; padding:16px; border-radius:12px; border:1px solid var(--line);">
                      <strong style="font-size:12px; color:var(--green-dark);">Secondary</strong>
                      <div style="display:flex; gap:10px; margin-top:8px;">
                          <input type="text" name="sec_school" id="sec_school" placeholder="Name of School" style="flex:2;">
                          <select name="sec_degree" id="sec_degree" style="flex:1;">
                              <option value="">--Strand--</option>
                              <option value="STEM">STEM</option>
                              <option value="ABM">ABM</option>
                              <option value="HUMSS">HUMSS</option>
                              <option value="GAS">GAS</option>
                              <option value="TVL">TVL</option>
                              <option value="General">General</option>
                          </select>
                          <input type="text" name="sec_year_level" id="sec_year_level" placeholder="Year/Level" style="flex:1;">
                          <input type="number" name="sec_date_attendance" id="sec_date_attendance" placeholder="Year (e.g. 2019)" min="1950" max="2050" style="flex:1;">
                      </div>
                  </div>

                  <div class="form-group span-2" style="background:#fdfdfd; padding:16px; border-radius:12px; border:1px solid var(--line);">
                      <strong style="font-size:12px; color:var(--green-dark);">Tertiary</strong>
                      <div style="display:flex; gap:10px; margin-top:8px;">
                          <input type="text" name="tert_school" id="tert_school" placeholder="Name of School" style="flex:2;">
                          <div style="flex:1; display:flex; flex-direction:column; gap:4px;">
                              <select name="tert_course" id="tert_course" onchange="toggleOther(this, 'other_tert_course')">
                                  <option value="">--Course--</option>
                                  <option value="BS Information Technology">BS Information Technology</option>
                                  <option value="BS Business Administration">BS Business Admin</option>
                                  <option value="BS Education">BS Education</option>
                                  <option value="BS Criminology">BS Criminology</option>
                                  <option value="BS Engineering">BS Engineering</option>
                                  <option value="Others">Others</option>
                              </select>
                              <input type="text" name="other_tert_course" id="tert_course_other" style="display:none; margin-top:5px;" class="not-required" placeholder="Specify Course">
                          </div>
                          <input type="text" name="tert_year_level" id="tert_year_level" placeholder="Year/Level" style="flex:1;">
                          <input type="number" name="tert_date_attendance" id="tert_date_attendance" placeholder="Year (e.g. 2023)" min="1950" max="2050" style="flex:1;">
                      </div>
                  </div>

                  <div class="form-group span-2" style="background:#fdfdfd; padding:16px; border-radius:12px; border:1px solid var(--line);">
                      <strong style="font-size:12px; color:var(--green-dark);">Tech-Voc</strong>
                      <div style="display:flex; gap:10px; margin-top:8px;">
                          <input type="text" name="tv_school" id="tv_school" placeholder="Name of School" style="flex:2;">
                          <div style="flex:1; display:flex; flex-direction:column; gap:4px;">
                              <select name="tv_course" id="tv_course" onchange="toggleOther(this, 'tv_course_other')">
                                  <option value="">--Course--</option>
                                  <option value="Computer Systems Servicing NC II">Computer Systems Servicing NC II</option>
                                  <option value="Automotive Servicing NC II">Automotive Servicing NC II</option>
                                  <option value="Electrical Installation NC II">Electrical Installation NC II</option>
                                  <option value="Others">Others</option>
                              </select>
                              <input type="text" name="other_tv_course" id="tv_course_other" style="display:none; margin-top:5px;" class="not-required" placeholder="Specify Course">
                          </div>
                          <input type="text" name="tv_year_level" id="tv_year_level" placeholder="Hours/Level" style="flex:1;">
                          <input type="number" name="tv_date_attendance" id="tv_date_attendance" placeholder="Year" min="1950" max="2050" style="flex:1;">
                      </div>
                  </div>

              </div>
          </div>
          <div class="form-step" id="spes-step-6">
              <div class="form-grid-2">

                  <div class="form-group span-2"><label>Special Skills</label><input type="text" name="special_skills" id="special_skills_spes"></div>

                  <h4 class="form-section-title span-2" style="margin-top:10px;">History of SPES Availment</h4>
                  <div class="form-group span-2" style="display:grid; grid-template-columns:1.4fr .7fr 1fr; gap:10px;">
                      <div><label>1st Establishment</label><input type="text" name="spes_history_1_establishment" id="spes_history_1_establishment"></div>
                      <div><label>1st Availment (Year)</label><input type="number" name="spes_history_1_year" id="spes_history_1_year" placeholder="YYYY"></div>
                      <div><label>SPES ID No.</label><input type="text" name="spes_history_1_id" id="spes_history_1_id"></div>
                      <div><label>2nd Establishment</label><input type="text" name="spes_history_2_establishment" id="spes_history_2_establishment"></div>
                      <div><label>2nd Availment (Year)</label><input type="number" name="spes_history_2_year" id="spes_history_2_year" placeholder="YYYY"></div>
                      <div><label>SPES ID No.</label><input type="text" name="spes_history_2_id" id="spes_history_2_id"></div>
                      <div><label>3rd Establishment</label><input type="text" name="spes_history_3_establishment" id="spes_history_3_establishment"></div>
                      <div><label>3rd Availment (Year)</label><input type="number" name="spes_history_3_year" id="spes_history_3_year" placeholder="YYYY"></div>
                      <div><label>SPES ID No.</label><input type="text" name="spes_history_3_id" id="spes_history_3_id"></div>
                      <div><label>4th Establishment</label><input type="text" name="spes_history_4_establishment" id="spes_history_4_establishment"></div>
                      <div><label>4th Availment (Year)</label><input type="number" name="spes_history_4_year" id="spes_history_4_year" placeholder="YYYY"></div>
                      <div><label>SPES ID No.</label><input type="text" name="spes_history_4_id" id="spes_history_4_id"></div>
                  </div>
                  <div class="form-group span-2"><label>Other Related Information / Requests / Interventions from DOLE</label><textarea name="spes_other_info" id="spes_other_info" rows="3" class="not-required" placeholder="Leave blank if none"></textarea></div>

                  <h4 class="form-section-title span-2" style="margin-top:10px;">Current Record Availment Data</h4>
                  <div class="form-group span-2"><label>Availment Status *</label><select name="availment_status" id="availment_status_input" required><option value="Not Yet Availed">Not Yet Availed</option><option value="Requirements Received">Requirements Received</option><option value="Orientation">Orientation</option><option value="Ongoing">Ongoing</option><option value="Salary Distribution">Salary Distribution</option><option value="Completed">Completed</option><option value="Not Qualified">Not Qualified</option></select></div>
                  <div id="date_fields_wrapper" style="display: none; grid-column: 1 / -1; width: 100%;">
                      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                          <div class="form-group" style="margin:0;"><label>Date Received / Started</label><input type="date" name="date_availed" id="date_availed"></div>
                          <div class="form-group" style="margin:0;"><label>Date Completed</label><input type="date" name="date_completed" id="date_completed"></div>
                      </div>
                  </div>
              </div>
          </div>

      <?php elseif (stripos($selectedProgramName, 'MSME') !== false): ?>
          <div class="form-step" id="msme-step-2">
              <div class="form-grid-2">
                  <div class="span-2 section-title"><i class="ph-fill ph-storefront"></i> Business Profile</div>
                  <div class="form-group span-2"><label>Business/Trade Name</label><input type="text" name="business_name" id="business_name" required></div>
                  <div class="form-group span-2"><label>Type of Ownership</label><select name="ownership_type" id="ownership_type" onchange="toggleOther(this, 'other_ownership_type')" required><option value="">--Select--</option><?php render_beneficiary_options('ownership_type'); ?></select><input type="text" name="other_ownership_type" id="other_ownership_type" class="not-required" style="display:none;margin-top:5px" placeholder="Specify ownership type"></div>
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
                  
                  <div class="span-2 section-title"><i class="ph-fill ph-tag"></i> Primary Products / Prices</div>
                  <div class="span-2 dynamic-table-wrap">
                      <table class="dynamic-table" id="productsTable" style="width: 100%;">
                          <thead>
                              <tr>
                                  <th>Product Name</th>
                                  <th>Price (₱)</th>
                                  <th></th>
                              </tr>
                          </thead>
                          <tbody>
                              <tr>
                                  <td style="padding: 0;" colspan="3">
                                      <div class="product-row-flex">
                                          <input type="text" name="prod_name[]" placeholder="Item Name" required>
                                          <input type="text" name="prod_price[]" placeholder="0.00" oninput="this.value = this.value.replace(/[^0-9.]/g, '')" required>
                                          <button type="button" class="btn-remove-row" onclick="removeRow(this)">✕</button>
                                      </div>
                                  </td>
                              </tr>
                          </tbody>
                      </table>
                      <button type="button" class="btn-add-row" onclick="addProductRow()">+ Add Product</button>
                  </div>

                  <div class="form-group"><label>Year Started</label><input type="text" name="year_started" id="year_started" placeholder="YYYY" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required></div>
                  <div class="form-group"><label>Business Permit No.</label><input type="text" name="business_permit_no" id="business_permit_no" required></div>
                  <div class="form-group"><label>Permit Valid Until</label><input type="date" name="permit_valid_until" id="permit_validity" required></div>
                  <div class="form-group"><label>DTI Reg No.</label><input type="text" name="dti_no" id="dti_no" required></div>
                  <div class="form-group"><label>TIN</label><input type="text" name="tin_no" id="tin_no" oninput="this.value = this.value.replace(/[^0-9-]/g, '')" required></div>
                  <div class="form-group"><label>Contact Details (Landline, Email)</label><input type="text" name="contact_details" id="business_email" required></div>
                  <div class="form-group span-2"><label>Website / Social Media (Optional)</label><input type="text" name="business_social_media" id="business_social_media" class="not-required" placeholder="Website or Facebook page"></div>
              </div>
          </div>
          <div class="form-step" id="msme-step-3">
              <div class="form-grid-2">
                  <div class="span-2 section-title"><i class="ph-fill ph-user-circle"></i> Owner Info</div>
                  <div class="form-group"><label>Sex</label>
                      <select name="owner_sex" id="sex" required>
                          <option value="">--Select--</option>
                          <option value="Male">Male</option>
                          <option value="Female">Female</option>
                      </select>
                  </div>
                  <div class="form-group"><label>Date of Birth</label><input type="date" name="birthdate" id="birthdateInput" required></div>
                  <div class="form-group"><label>Age</label><input type="text" name="age" id="ageOutput" oninput="this.value = this.value.replace(/[^0-9]/g, '')" readonly style="background:#f4f8f5;" required></div>
                  <div class="form-group"><label>Civil Status</label>
                      <select name="owner_civil_status" id="civil_status" required>
                          <option value="">--Select--</option>
                          <option value="Single">Single</option>
                          <option value="Married">Married</option>
                          <option value="Widowed">Widowed</option>
                          <option value="Legally Separated">Legally Separated</option>
                      </select>
                  </div>
                  <div class="form-group span-2">
                      <label>Educational Attainment</label>
                      <select name="educational_attainment" id="educational_attainment" onchange="toggleOther(this, 'msme_edu_other')" required>
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
                  <div class="form-group span-2"><label>Work Experience</label><textarea name="work_experience" id="work_experience" rows="3" required></textarea></div>
              </div>
          </div>
          <div class="form-step" id="msme-step-4">
              <div class="form-grid-2">
                  <div class="span-2 section-title"><i class="ph-fill ph-chart-line-up"></i> Operations</div>
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
                  <div class="form-group"><label>Night Market Stall No. (Optional)</label><input type="text" name="nm_stall_no" id="nm_stall_no" class="not-required"></div>
                  <div class="form-group"><label>Night Market Date Started (Optional)</label><input type="date" name="nm_date_started" id="nm_date_started" class="not-required"></div>
              </div>
          </div>
          <div class="form-step" id="msme-step-5">
              <div class="form-grid-2">
                  <div class="span-2 section-title"><i class="ph-fill ph-users-three"></i> Human Resources</div>
                  <div class="form-group"><label>Number of Male Workers</label><input type="text" name="hr_male" id="hr_male" value="0" oninput="this.value = this.value.replace(/[^0-9]/g, ''); calcHr()" required></div>
                  <div class="form-group"><label>Number of Female Workers</label><input type="text" name="hr_female" id="hr_female" value="0" oninput="this.value = this.value.replace(/[^0-9]/g, ''); calcHr()" required></div>
                  <div class="form-group span-2"><label>Total Workers</label><input type="text" name="hr_total" id="hr_total" readonly value="0"></div>
                  
                  <div class="span-2 section-title" style="margin-top:10px;"><i class="ph-fill ph-briefcase"></i> Employment Status</div>
                  <div class="form-group"><label>Regular</label><input type="text" name="emp_regular" value="0" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required></div>
                  <div class="form-group"><label>Seasonal</label><input type="text" name="emp_seasonal" value="0" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required></div>
                  <div class="form-group"><label>Contractual</label><input type="text" name="emp_contractual" value="0" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required></div>
                  <div class="form-group"><label>Family</label><input type="text" name="emp_family" value="0" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required></div>
                  
                  <div class="form-group span-2"><label>Skills Training Needed (Optional)</label><textarea name="hr_skills" rows="3" class="not-required"></textarea></div>
              </div>
          </div>
          <div class="form-step" id="msme-step-6">
              <div class="form-grid-2">
                  <div class="span-2 section-title"><i class="ph-fill ph-bank"></i> Financials</div>
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
                      <select name="business_size" required>
                          <option value="">--Select--</option>
                          <option value="Micro">Micro ≤ ₱3M</option>
                          <option value="Small">Small ₱3,000,001–₱15,000,000</option>
                          <option value="Medium">Medium ₱15,000,001–₱100,000,000</option>
                      </select>
                  </div>
                  <div class="form-group"><label>Initial Capital (₱)</label><input type="text" name="initial_capital" placeholder="0.00" oninput="this.value = this.value.replace(/[^0-9.]/g, '')" required></div>
                  <div class="form-group"><label>Current Capital (₱)</label><input type="text" name="current_capital" placeholder="0.00" oninput="this.value = this.value.replace(/[^0-9.]/g, '')" required></div>
                  <div class="form-group span-2"><label>Regular Daily Earnings (₱)</label><input type="text" name="daily_earnings" placeholder="0.00" oninput="this.value = this.value.replace(/[^0-9.]/g, '')" required></div>
                  
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
          </div>
          <div class="form-step" id="msme-step-7">
              <div class="form-grid-2">
                  <div class="span-2 section-title"><i class="ph-fill ph-handshake"></i> Government Assistance</div>
                  <div class="form-group span-2"><label>Availed before?</label>
                      <select name="availed_before" onchange="document.getElementById('gov_assisted_wrap').style.display=(this.value==='Yes')?'grid':'none'" required>
                          <option value="">--Select--</option><option value="Yes">Yes</option><option value="No">No</option>
                      </select>
                  </div>
                  
                  <div id="gov_assisted_wrap" class="span-2 form-grid-2" style="display:none; gap:20px;">
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
          </div>
          <div class="form-step" id="msme-step-8">
              <div class="form-grid-2">
                  <div class="span-2 section-title"><i class="ph-fill ph-warning-circle"></i> Challenges & Status</div>
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
                  
                  <div class="span-2 divider-line"></div>
                  <div class="form-group">
                      <label>Availment Status *</label>
                      <select name="availment_status" id="availment_status_input" required>
                          <option value="Not Yet Availed">Not Yet Availed</option>
          <option value="Requirements Received">Requirements Received</option>
          <option value="Orientation">Orientation</option>
          <option value="Ongoing">Ongoing</option>
          <option value="Salary Distribution">Salary Distribution</option>
          <option value="Completed">Completed</option>
          <option value="Not Qualified">Not Qualified</option>
                      </select>
                  </div>
                  <div class="form-group">
                      <label>LGU Approval Status</label>
                      <select name="approval_status" id="approval_status_input" disabled style="background:#f4f8f5; cursor:not-allowed; color:var(--muted);">
                          <option value="Pending">Pending</option>
                          <option value="Approved">Approved</option>
                          <option value="Rejected">Rejected</option>
                      </select>
                      <small style="color:var(--muted); font-size:11px;">* Staff cannot modify approval status.</small>
                  </div>
                  <div id="date_fields_wrapper" style="display: none; grid-column: 1 / -1; width: 100%;">
                      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                          <div class="form-group" style="margin:0;"><label>Date Availed</label><input type="date" name="date_availed" id="date_availed"></div>
                          <div class="form-group" style="margin:0;"><label>Date Completed</label><input type="date" name="date_completed" id="date_completed"></div>
                      </div>
                  </div>
              </div>
          </div>
      <?php endif; ?>

      <div class="modal-footer-sticky">
        <button type="button" class="btn-light" data-close-modal id="btnCancelWizard">
            <i class="ph-bold ph-x" style="margin-right:6px; color: var(--muted);"></i> Cancel
        </button>
        <div style="display: flex; gap: 12px; align-items: center;">
            
            <span id="form-error-msg" style="background: #fef2f2; color: #dc2626; padding: 8px 16px; border-radius: 8px; border: 1px solid #fecaca; font-size: 12px; font-weight: 700; display: none; align-items: center; gap: 6px;">
                <i class="ph-fill ph-warning-circle" style="font-size: 16px;"></i> Missing required fields
            </span>

            <button type="button" class="btn-light" id="btnPrevStep" style="display:none;">
                <i class="ph-bold ph-caret-left" style="margin-right:6px;"></i> Back
            </button>
            <button type="button" class="btn-main" id="btnNextStep">
                Next Step <i class="ph-bold ph-caret-right" style="margin-left:6px;"></i>
            </button>
            <button type="submit" class="btn-main" id="btnSubmitForm" style="display:none; background: #1a6d41;">
                <i class="ph-bold ph-check-circle" style="margin-right:6px;"></i> Save Beneficiary
            </button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal" id="quickStatusModal" aria-hidden="true">
  <div class="modal-backdrop" data-close-quick></div>
  <div class="modal-dialog modal-dialog-sm" style="animation: modalSlideUp 0.3s ease-out; overflow: hidden !important;">
    <div class="modal-head-sm">
      <div>
        <div class="modal-title" style="font-size: 22px;">Update Availment</div>
        <div class="modal-sub">Update DOLE Availment tracking.</div>
      </div>
      <button type="button" class="modal-close-icon" style="position: absolute; right: 24px; top: 24px;" data-close-quick><i class="ph-bold ph-x"></i></button>
    </div>

    <div class="modal-body-sm">
        <form method="POST" class="modal-form" style="padding: 0; background: transparent; overflow: visible;" novalidate onsubmit="return validateSimpleForm(this);">
          <input type="hidden" name="action" value="quick_update_status">
          <input type="hidden" name="beneficiary_id" id="quick_beneficiary_id">
          <input type="hidden" name="program_name" value="<?php echo h($selectedProgramName); ?>">

          <div class="form-group">
            <label>DOLE Availment Status *</label>
            <select name="availment_status" id="quick_availment_status" required onchange="toggleQuickDateFields()">
              <option value="Not Yet Availed">Not Yet Availed</option>
              <option value="Requirements Received">Requirements Received</option>
              <option value="Orientation">Orientation</option>
              <option value="Ongoing">Ongoing</option>
              <option value="Salary Distribution">Salary Distribution</option>
              <option value="Completed">Completed</option>
              <option value="Not Qualified">Not Qualified</option>
            </select>
          </div>

          <div class="form-group" id="quick_place_container" style="display: none;">
            <label>Place *</label>
            <input type="text" name="schedule_place" id="quick_schedule_place" placeholder="Enter venue or distribution place">
          </div>

      <div class="form-group" id="quick_message_container" style="display:none;">
        <label>Reason for this status *</label>
        <textarea name="status_message" id="quick_status_message" rows="3" placeholder="Explain why the applicant is not qualified or why the availment was cancelled."></textarea>
          </div>

          <div id="quick_date_fields_wrapper" style="display: none;">
            <div class="form-row-2">
                <div class="form-group" id="quick_date_1_container" style="display: none; margin: 0;">
                    <label id="quick_date_1_label" style="font-size: 12px;">Date Started</label>
                    <input type="date" name="date_availed" id="quick_date_availed">
                </div>
                <div class="form-group" id="quick_date_2_container" style="display: none; margin: 0;">
                    <label id="quick_date_2_label" style="font-size: 12px;">Date Completed</label>
                    <input type="date" name="date_completed" id="quick_date_completed">
                </div>
            </div>
          </div>

          <div class="modal-actions-sm">
            <button type="button" class="btn-light" data-close-quick>Cancel</button>
            <button type="submit" class="btn-main"><i class="ph-bold ph-check" style="margin-right:6px;"></i> Save Status</button>
          </div>
        </form>
    </div>
  </div>
</div>

<div class="modal" id="bulkUploadModal" aria-hidden="true">
  <div class="modal-backdrop" data-close-bulk></div>
  <div class="modal-dialog" style="max-width: 500px; animation: modalSlideUp 0.3s ease-out; overflow: hidden !important;">
    <div class="modal-head-alt" style="padding-bottom: 16px;">
      <div>
        <div class="modal-title">Import File</div>
        <div class="modal-sub">Upload your Excel or CSV file.</div>
      </div>
      <button type="button" class="modal-close-icon" data-close-bulk><i class="ph-bold ph-x"></i></button>
    </div>

    <form method="POST" enctype="multipart/form-data" class="modal-form" novalidate onsubmit="return validateSimpleForm(this);">
      <input type="hidden" name="action" value="bulk_upload_beneficiaries">
      <input type="hidden" name="program_name" value="<?php echo h($selectedProgramName); ?>">

      <div class="form-group" style="margin-bottom: 20px;">
        <label>Select Batch *</label>
        <select name="program_id" required>
            <option value="">-- Select Batch --</option>
            <?php foreach($batches as $b): ?>
                <option value="<?php echo $b['program_id']; ?>"><?php echo h($b['program_code']); ?></option>
            <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group" style="margin-bottom: 20px;">
        <label>Batch Availment Status *</label>
        <select name="batch_availment_status" required onchange="toggleBatchScheduleFields(this)">
          <option value="Not Yet Availed">New Beneficiaries (Not Yet Availed)</option>
          <option value="Requirements Received">Requirements Received</option>
          <option value="Orientation">Orientation</option>
          <option value="Ongoing">Ongoing Beneficiaries</option>
          <option value="Salary Distribution">Salary Distribution</option>
          <option value="Completed">Old/Completed Beneficiaries</option>
          <option value="Not Qualified">Not Qualified</option>
        </select>
      </div>

      <div id="batch_schedule_fields" class="form-row-2" style="display:none; margin-bottom:20px;">
        <div class="form-group" style="margin:0;"><label id="batch_schedule_date_label">Schedule Date *</label><input type="date" name="batch_schedule_date" id="batch_schedule_date"></div>
        <div class="form-group" style="margin:0;"><label>Place *</label><input type="text" name="batch_schedule_place" id="batch_schedule_place" placeholder="Enter venue or distribution place"></div>
      </div>

      <div class="form-group" id="batch_message_container" style="display:none; margin-bottom:20px;">
        <label>Reason for this status *</label>
        <textarea name="batch_status_message" id="batch_status_message" rows="3" placeholder="Explain why the applicants are not qualified."></textarea>
      </div>

      <div class="form-group">
        <label>Upload File *</label>
        <div class="file-upload-box-dashed">
            <input type="file" name="csv_file" accept=".csv,.xlsx" required style="width:100%; padding:15px;">
            <div style="font-size: 32px; color: var(--green); margin-bottom: 8px;"><i class="ph-fill ph-file-text"></i></div>
            <div class="file-help-text">Select an Excel (.xlsx) or CSV (.csv) file</div>
        </div>
      </div>

      <div class="modal-actions-flex" style="justify-content: center; margin-top: 32px; border-radius: 0 0 var(--radius-xl) var(--radius-xl);">
        <button type="button" class="btn-light" data-close-bulk style="width:48%;">Cancel</button>
        <button type="submit" class="btn-main" style="width:48%;"><i class="ph-bold ph-upload-simple" style="margin-right:6px;"></i> Upload Data</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleBatchScheduleFields(select) {
  const needsSchedule = select.value === 'Orientation' || select.value === 'Salary Distribution';
  const needsMessage = select.value === 'Not Qualified' || select.value === 'Cancelled';
  const fields = document.getElementById('batch_schedule_fields');
  const dateInput = document.getElementById('batch_schedule_date');
  const placeInput = document.getElementById('batch_schedule_place');
  const messageContainer = document.getElementById('batch_message_container');
  const messageInput = document.getElementById('batch_status_message');
  fields.style.display = needsSchedule ? 'grid' : 'none';
  dateInput.required = needsSchedule;
  placeInput.required = needsSchedule;
  messageContainer.style.display = needsMessage ? 'block' : 'none';
  messageInput.required = needsMessage;
  if (!needsMessage) messageInput.value = '';
  document.getElementById('batch_schedule_date_label').textContent = select.value === 'Orientation' ? 'Orientation Date *' : 'Distribution Date *';
}
</script>

<div class="modal" id="generateReportModal" aria-hidden="true">
  <div class="modal-backdrop" data-close-report></div>
  <div class="modal-dialog split-layout" style="position: relative; overflow: hidden !important;">
    
    <button type="button" data-close-report style="position: absolute; top: 16px; right: 16px; width: 32px; height: 32px; border-radius: 50%; background: #fee2e2; border: none; color: #dc2626; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 100; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"><i class="ph-bold ph-x" style="font-size: 16px;"></i></button>

    <div class="report-controls"> 
        <div style="margin-bottom: 24px; flex-shrink: 0;">
            <div class="modal-title">Generate Report</div>
            <div class="modal-sub">Filter, print, or export the beneficiary list for <?php echo h($selectedProgramName); ?>.</div>
        </div>
        
        <form method="GET" action="export_report.php" target="_blank" class="report-form">
            <input type="hidden" name="program_name" value="<?php echo h($selectedProgramName); ?>">
            
            <div style="flex: 1; overflow-y: auto; padding-right: 8px; display: flex; flex-direction: column; gap: 16px; margin-bottom: 16px;">
                <div class="form-group" style="margin:0;">
                    <label>Select Batch Filter</label>
                    <select name="program_id" id="report_batch_select">
                        <option value="0">All Batches</option>
                        <?php foreach($batches as $b): ?><option value="<?php echo $b['program_id']; ?>"><?php echo h($b['program_code']); ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin:0;">
                    <label>Select Barangay</label>
                    <select name="report_barangay" id="report_brgy_select">
                        <option value="All">Municipality of Vinzons</option>
                        <?php foreach ($barangays as $barangay): ?><option value="<?php echo h($barangay); ?>"><?php echo h($barangay); ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin:0;">
                    <label>Availment Filter</label>
                    <select name="report_availment" id="report_avail_select">
                        <option value="All">All Records</option>
                        <option value="Not Yet Availed">New (Not Availed)</option>
                        <option value="Requirements Received">Requirements Received</option>
                        <option value="Orientation">Orientation</option>
                        <option value="Ongoing">Ongoing</option>
                        <option value="Salary Distribution">Salary Distribution</option>
                        <option value="Completed">Old / Completed</option>
                        <option value="Not Qualified">Not Qualified</option>
                    </select>
                </div>
                
                <?php if (stripos($selectedProgramName, 'MSME') !== false): ?>
                <div class="form-group" style="margin:0;">
                    <label>Type of Vendor Filter</label>
                    <select name="business_nature" id="report_nature_select">
                        <option value="All">All Vendor Types</option>
                        <?php foreach ($businessNatures as $nature): ?>
                            <option value="<?php echo h($nature); ?>"><?php echo h($nature); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <details class="report-column-picker">
                    <summary><span>Report Columns</span><span class="report-column-count"></span></summary>
                    <div class="report-column-tools">
                        <button type="button" data-report-columns="essential">Common Fields</button>
                        <button type="button" data-report-columns="all">Select All</button>
                    </div>
                    <div class="report-column-list">
                        <?php foreach (getReportColumnDefinitions($selectedProgramName) as $columnIndex => $column): ?>
                            <label>
                                <input type="checkbox" name="columns[]" value="<?php echo h($column['key']); ?>" data-column-index="<?php echo $columnIndex + 1; ?>" data-default="<?php echo $column['default'] ? '1' : '0'; ?>" checked>
                                <span><?php echo h($column['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </details>

                <div class="form-group" style="margin:0;">
                    <label>Export Format</label>
                    <select name="export_format">
                        <option value="xlsx">Editable Excel Workbook (.xlsx)</option>
                    </select>
                </div>
            </div>

            <div class="report-actions">
                <button type="button" class="report-action-button report-action-print" id="report_print_button"><i class="ph-bold ph-printer"></i> Print</button>
                <button type="submit" class="report-action-button report-action-export"><i class="ph-bold ph-download-simple"></i> Export Data</button>
            </div>
        </form>
    </div>

    <div class="report-preview">
        <div class="spreadsheet-container">
            <div class="preview-header">
                <img class="official-report-header" src="assets/peso_official_report_header.png?v=20260820-compact" alt="PESO Vinzons official letterhead">
                <h2><?php echo h($reportProgramTitle); ?></h2>
                <p id="preview_subtitle_top">Municipality of Vinzons</p>
            </div>
            <div class="scrollable-table-wrap">
                <table class="spreadsheet-table" id="preview_main_table">
                    <thead id="preview_table_head"></thead>
                    <tbody id="preview_table_body"></tbody>
                </table>
            </div>
            <div id="preview_pagination" class="modern-pagination-controls"></div>
        </div>
    </div>
  </div>
</div>

<div class="modal" id="profileModal" aria-hidden="true">
  <div class="modal-backdrop" data-close-profile></div>
  <div class="modal-dialog id-card-dialog" style="max-width: 900px; padding: 0; display: flex; overflow: hidden; background: #fff; border-radius: 16px;">
    
    <div class="id-card-left" style="width: 320px; background: linear-gradient(135deg, #e8f5e9 0%, #ffffff 100%); position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 20px; border-right: 1px solid #eaecf0;">
       <div class="id-card-pattern" style="position: absolute; top:0; left:0; right:0; bottom:0; opacity: 0.1; background-image: radial-gradient(#1f7a54 1px, transparent 1px); background-size: 10px 10px; z-index: 1;"></div>
       <div style="z-index: 2; display: flex; flex-direction: column; align-items: center;">
           <div class="id-avatar-large" id="pm_avatar" style="width: 100px; height: 100px; background: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 40px; font-weight: 800; color: #0d2618; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 24px;">?</div>
           
           <div class="profile-badges-row" style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
               <div class="id-badge badge-user-bg" id="pm_program_header" style="background: #1f7a54; color: #fff; padding: 6px 16px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;">PROGRAM NAME</div>
               <div id="pm_availment_badge"></div>
           </div>
       </div>
    </div>

    <div class="id-card-right" style="flex: 1; padding: 32px; background: #fff; position: relative;">
       <button type="button" data-close-profile style="position: absolute; top: 16px; right: 16px; background: #f9fafb; border: 1px solid #eaecf0; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #667085;"><i class="ph-bold ph-x"></i></button>

       <div class="id-card-right-inner">
           <div class="id-header" style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px; border-bottom: 2px solid #1f7a54; padding-bottom: 12px; width: fit-content; min-width: 250px;">
              <div style="width: 36px; height: 36px; background: #1f7a54; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff;"><i class="ph-fill ph-user" style="font-size: 18px;"></i></div>
              <h3 class="id-name" id="pm_name" style="margin: 0; font-size: 20px; font-weight: 700; color: #0d2618;">Name</h3>
           </div>
           
           <div class="id-details-grid resume-details" id="pm_resume_content">
              
              <div class="info-card">
                 <div class="info-icon"><i class="ph-fill ph-phone-call"></i></div>
                 <div class="info-text">
                    <label>CONTACT NO.</label>
                    <div class="detail-value" id="pm_contact"></div>
                 </div>
              </div>

              <div class="info-card">
                 <div class="info-icon"><i class="ph-fill ph-map-pin"></i></div>
                 <div class="info-text">
                    <label>BARANGAY</label>
                    <div class="detail-value" id="pm_barangay"></div>
                 </div>
              </div>

              <div class="info-card">
                 <div class="info-icon"><i class="ph-fill ph-user"></i></div>
                 <div class="info-text">
                    <label>SEX & AGE</label>
                    <div class="detail-value" id="pm_sex_age"></div>
                 </div>
              </div>
              
              <div class="info-card" id="pm_business_container" style="grid-column: span 2; display:none;">
                 <div class="info-icon"><i class="ph-fill ph-briefcase"></i></div>
                 <div class="info-text">
                    <label>BUSINESS NAME</label>
                    <div class="detail-value text-success" id="pm_business_name"></div>
                 </div>
              </div>

              <div class="info-card" id="pm_nature_container">
                 <div class="info-icon"><i class="ph-fill ph-tag"></i></div>
                 <div class="info-text">
                    <label id="pm_dynamic_label">NATURE OF BUSINESS</label>
                    <div class="detail-value" id="pm_type_beneficiary"></div>
                 </div>
              </div>

              <div class="info-card" id="pm_date_availed_container">
                 <div class="info-icon"><i class="ph-fill ph-calendar-check"></i></div>
                 <div class="info-text">
                    <label>DATE AVAILED</label>
                    <div class="detail-value text-success" id="pm_date_availed_val"></div>
                 </div>
              </div>

              <div class="info-card" id="pm_stall_container" style="display:none;">
                 <div class="info-icon"><i class="ph-fill ph-storefront"></i></div>
                 <div class="info-text">
                    <label>NIGHT MARKET STALL NO.</label>
                    <div class="detail-value" id="pm_stall"></div>
                 </div>
              </div>

              <div class="info-card" id="pm_date_container" style="display:none;">
                 <div class="info-icon"><i class="ph-fill ph-calendar-plus"></i></div>
                 <div class="info-text">
                    <label id="pm_dynamic_date_label">NIGHT MARKET DATE STARTED</label>
                    <div class="detail-value" id="pm_nm_start"></div>
                 </div>
              </div>

              <div class="info-card" id="pm_utility_container" style="grid-column: 1 / -1; display:none;">
                 <div class="info-icon"><i class="ph-fill ph-lightning"></i></div>
                 <div class="info-text">
                    <label>UTILITY NEEDS</label>
                    <div class="detail-value" id="pm_utility"></div>
                 </div>
              </div>

              <div class="info-card" id="pm_assets_container" style="grid-column: 1 / -1; display:none;">
                 <div class="info-icon"><i class="ph-fill ph-bank"></i></div>
                 <div class="info-text">
                    <label>BUSINESS ASSETS OWNED</label>
                    <div class="detail-value" id="pm_assets"></div>
                 </div>
              </div>

           </div>
       </div>
       
       <div class="id-actions" style="display: flex; justify-content: space-between; align-items: center; margin-top: 24px; padding-top: 16px; border-top: 1px solid #eaecf0;">
          <div class="added-by" style="font-size: 11px; color: #667085;">ADDED BY: <span id="pm_added_by" style="color: #1f7a54; font-weight:800; text-transform:uppercase;"></span></div>
          <div id="pm_footer_actions" style="display:flex; gap:10px; align-items:center;"></div>
       </div>
    </div>
  </div>
</div>

<div class="modal success-modal <?php echo $showSuccessModal ? 'show' : ''; ?>" id="successModal" aria-hidden="<?php echo $showSuccessModal ? 'false' : 'true'; ?>">
  <div class="modal-backdrop" data-close-success></div>
  <div class="success-dialog<?php echo is_array($importSummary) ? ' import-summary-dialog' : ''; ?>">
    <div class="warning-icon" style="background:#e6f4ed; color:#1f7a54;"><i class="ph-bold ph-check"></i></div>
    <?php if (is_array($importSummary)): ?>
      <div class="success-title">Import completed</div>
      <p class="import-summary-subtitle">The beneficiary file was processed successfully. Here is a summary of the records.</p>
      <div class="import-summary-grid" aria-label="Import results">
        <div class="import-summary-stat import-summary-stat-primary">
          <strong><?php echo number_format((int)$importSummary['added']); ?></strong>
          <span>New records added</span>
        </div>
        <div class="import-summary-stat">
          <strong><?php echo number_format((int)$importSummary['updated']); ?></strong>
          <span>Existing records updated</span>
        </div>
        <div class="import-summary-stat">
          <strong><?php echo number_format((int)$importSummary['skipped']); ?></strong>
          <span>Unnamed rows skipped</span>
        </div>
        <div class="import-summary-stat">
          <strong><?php echo number_format((int)$importSummary['emails_sent']); ?></strong>
          <span>Status emails sent</span>
        </div>
      </div>
      <div class="import-summary-note">
        <span><i class="ph ph-info"></i> New records are pending approval. Saved records use the availment status <strong><?php echo h($importSummary['status']); ?></strong>.</span>
        <?php if ((int)$importSummary['emails_failed'] > 0): ?>
          <span class="import-summary-warning"><?php echo number_format((int)$importSummary['emails_failed']); ?> email notification(s) could not be sent.</span>
          <div class="import-summary-reasons">
            <strong>Reason:</strong>
            <?php foreach (($importSummary['email_failure_reasons'] ?? []) as $reason => $count): ?>
              <span><?php echo number_format((int)$count); ?> record(s): <?php echo h($reason); ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <button type="button" class="btn-main import-summary-close" data-close-success>Close Summary</button>
    <?php else: ?>
      <div class="success-title">Processed</div>
      <div class="modal-text">
        <?php echo h($successModalMessage ?: "Operation completed successfully."); ?>
      </div>
      <button type="button" class="btn-main" style="margin-top: 24px; width: 100%;" data-close-success>Okay</button>
    <?php endif; ?>
  </div>
</div>

<div class="modal error-modal <?php echo $showErrorModal ? 'show' : ''; ?>" id="errorModal" aria-hidden="<?php echo $showErrorModal ? 'false' : 'true'; ?>">
  <div class="modal-backdrop" data-close-error></div>
  <div class="success-dialog" style="border-top: 4px solid #dc2626;">
    <div class="warning-icon"><i class="ph-bold ph-x"></i></div>
    <div class="success-title" style="color:#dc2626;">Action Failed</div>
    <div class="modal-text" style="color: var(--muted);">
      <?php echo h($errorModalMessage ?: "An error occurred."); ?>
    </div>
    <button type="button" class="btn-danger" style="margin-top: 24px; width: 100%; padding: 12px; border-radius: 12px; font-weight: 700; cursor: pointer;" data-close-error>Okay</button>
  </div>
</div>

<?php endif; ?>

<script>
    const allBeneficiariesData = <?php echo json_encode($allBeneficiariesForReport ?? []); ?>;
    const currentProgramName = "<?php echo h($selectedProgramName); ?>";
    const currentReportTitle = <?php echo json_encode($reportProgramTitle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    
    let previewData = [];
    let currentPreviewPage = 1;
    const previewItemsPerPage = 20;
</script>

<script>
  function toggleOther(selectObj, otherId) {
      const otherInput = document.getElementById(otherId);
      if (!otherInput) return;
      if (selectObj.value === 'Others' || selectObj.value === 'Other') {
          otherInput.style.display = 'block';
          otherInput.setAttribute('required', 'required');
          otherInput.focus();
      } else {
          otherInput.style.display = 'none';
          otherInput.removeAttribute('required');
      }
  }

  function setSelectOrOther(selectId, value) {
      const sel = document.getElementById(selectId);
      const otherInput = document.getElementById('other_' + selectId);
      if (!sel) return;
      
      let optionExists = false;
      for (let i = 0; i < sel.options.length; i++) {
          if (sel.options[i].value === value) {
              optionExists = true;
              break;
          }
      }
      
      if (optionExists || !value) {
          sel.value = value;
          if(otherInput) {
              otherInput.style.display = 'none';
              otherInput.value = '';
              otherInput.removeAttribute('required');
          }
      } else {
          sel.value = 'Others';
          if (!sel.value) sel.value = 'Other'; 
          if (otherInput) {
              otherInput.style.display = 'block';
              otherInput.value = value;
              otherInput.setAttribute('required', 'required');
          }
      }
  }

  function calcHr() {
      let m = parseInt(document.getElementById('hr_male')?.value) || 0;
      let f = parseInt(document.getElementById('hr_female')?.value) || 0;
      let totalEl = document.getElementById('hr_total');
      if (totalEl) totalEl.value = m + f;
  }

  function addProductRow() {
      let table = document.getElementById('productsTable')?.getElementsByTagName('tbody')[0];
      if(!table) return;
      if(table.rows.length >= 10) return alert("Maximum 10 products allowed.");
      let newRow = table.insertRow();
      newRow.innerHTML = `<td style="padding: 0;" colspan="3">
                              <div class="product-row-flex">
                                  <input type="text" name="prod_name[]" placeholder="Item Name" required>
                                  <input type="text" name="prod_price[]" placeholder="0.00" oninput="this.value = this.value.replace(/[^0-9.]/g, '')" required>
                                  <button type="button" class="btn-remove-row" onclick="removeRow(this)">✕</button>
                              </div>
                          </td>`;
  }

  function populateProductRows(primaryProducts, productPrices) {
      const tbody = document.getElementById('productsTable')?.tBodies[0];
      if (!tbody) return;
      let names = [];
      let prices = [];
      const rawProducts = String(primaryProducts || '').trim();
      try {
          const decoded = JSON.parse(rawProducts);
          if (Array.isArray(decoded)) {
              decoded.forEach(product => {
                  if (!Array.isArray(product) || !String(product[0] || '').trim()) return;
                  names.push(String(product[0]).trim());
                  prices.push(String(product[1] || '').trim());
              });
          }
      } catch (error) {
          rawProducts.split(/,\s*(?![^()]*\))/).filter(Boolean).forEach(product => {
              const match = product.trim().match(/^(.*?)\s*\((?:₱|PHP\s*)?([0-9.,]+)\)$/i);
              names.push(match ? match[1].trim() : product.trim());
              if (match) prices.push(match[2].trim());
          });
      }
      if (String(productPrices || '').trim()) prices = String(productPrices).split(',').map(price => price.trim());
      if (!names.length) names = [''];
      tbody.innerHTML = '';
      names.slice(0, 10).forEach((name, index) => {
          const row = tbody.insertRow();
          row.innerHTML = `<td style="padding: 0;" colspan="3"><div class="product-row-flex">
                              <input type="text" name="prod_name[]" placeholder="Item Name" required>
                              <input type="text" name="prod_price[]" placeholder="0.00" inputmode="decimal" oninput="this.value = this.value.replace(/[^0-9.]/g, '')" required>
                              <button type="button" class="btn-remove-row" onclick="removeRow(this)">×</button>
                           </div></td>`;
          row.querySelector('[name="prod_name[]"]').value = name;
          row.querySelector('[name="prod_price[]"]').value = prices[index] || '';
      });
  }

  function populateNamedField(name, value) {
      const field = document.querySelector(`#multiStepForm [name="${name}"]`);
      if (field) field.value = value ?? '';
  }

  function populateCheckboxGroup(name, value) {
      const selected = String(value || '').split(',').map(item => item.trim().toLowerCase()).filter(Boolean);
      const checkboxes = Array.from(document.querySelectorAll(`#multiStepForm [name="${name}[]"]`)).filter(field => field.type === 'checkbox');
      const standardValues = checkboxes.filter(field => field.value.toLowerCase() !== 'others').map(field => field.value.trim().toLowerCase());
      const customValues = selected.filter(item => item !== 'others' && !standardValues.includes(item));
      checkboxes.forEach(checkbox => {
          checkbox.checked = selected.includes(String(checkbox.value).trim().toLowerCase());
          if (checkbox.value.toLowerCase() === 'others' && customValues.length) {
              checkbox.checked = true;
              const targetId = (checkbox.getAttribute('onchange') || '').match(/getElementById\('([^']+)'\)/)?.[1];
              const target = targetId ? document.getElementById(targetId) : null;
              if (target) { target.value = customValues.join(', '); target.style.display = 'block'; }
          }
      });
  }

  function addSpesRow() {
      let table = document.getElementById('spesTable')?.getElementsByTagName('tbody')[0];
      if(!table) return;
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
      if (row.tagName === "TD") row = row.parentNode;
      row.parentNode.removeChild(row);
  }

  function setupWizardNav() {
      const nav = document.getElementById('wizardNav');
      if (!nav) return;
      if (currentProgramName.toUpperCase().includes('MSME')) {
          nav.innerHTML = `
              <div class="wizard-step active" id="indicator-step-1"><span class="step-num">1</span> <span>Batch & Profile</span></div>
              <div class="wizard-step" id="indicator-step-2"><span class="step-num">2</span> <span>Business Profile</span></div>
              <div class="wizard-step" id="indicator-step-3"><span class="step-num">3</span> <span>Owner Info</span></div>
              <div class="wizard-step" id="indicator-step-4"><span class="step-num">4</span> <span>Operations</span></div>
              <div class="wizard-step" id="indicator-step-5"><span class="step-num">5</span> <span>Human Resources</span></div>
              <div class="wizard-step" id="indicator-step-6"><span class="step-num">6</span> <span>Financials</span></div>
              <div class="wizard-step" id="indicator-step-7"><span class="step-num">7</span> <span>Assistance</span></div>
              <div class="wizard-step" id="indicator-step-8"><span class="step-num">8</span> <span>Challenges</span></div>
          `;
      } else if (currentProgramName.toUpperCase().includes('SPES')) {
          nav.innerHTML = `
              <div class="wizard-step active" id="indicator-step-1"><span class="step-num">1</span> <span>Batch & Info</span></div>
              <div class="wizard-step" id="indicator-step-2"><span class="step-num">2</span> <span>Addt'l Details</span></div>
              <div class="wizard-step" id="indicator-step-3"><span class="step-num">3</span> <span>Status</span></div>
              <div class="wizard-step" id="indicator-step-4"><span class="step-num">4</span> <span>Family</span></div>
              <div class="wizard-step" id="indicator-step-5"><span class="step-num">5</span> <span>Education</span></div>
              <div class="wizard-step" id="indicator-step-6"><span class="step-num">6</span> <span>Skills & Hist</span></div>
          `;
      } else {
          nav.innerHTML = `
              <div class="wizard-step active" id="indicator-step-1"><span class="step-num">1</span> <span>Batch & Info</span></div>
              <div class="wizard-step" id="indicator-step-2"><span class="step-num">2</span> <span>TUPAD Specifics</span></div>
              <div class="wizard-step" id="indicator-step-3"><span class="step-num">3</span> <span>Status & Skills</span></div>
          `;
      }
  }

  window.openProfileModal = function(rowElement) {
      try {
          const data = JSON.parse(rowElement.getAttribute('data-profile'));
          
          const safeSetText = (id, text) => {
              const el = document.getElementById(id);
              if (el) el.textContent = text || '—';
          };

          safeSetText('pm_avatar', data.initial);
          safeSetText('pm_name', data.name);
          safeSetText('pm_program_header', data.program); 
          safeSetText('pm_added_by', data.added_by); 

          safeSetText('pm_barangay', data.barangay);
          safeSetText('pm_contact', data.contact_no);
          safeSetText('pm_sex_age', `${data.sex || 'N/A'}, ${data.age || 'N/A'}`);
          safeSetText('pm_civil', data.civil_status || 'N/A');
          
          let dateAvailedStr = 'Not yet availed';
          if(data.date_availed && data.date_availed.trim() !== '') {
              let d = new Date(data.date_availed);
              if(!isNaN(d)) {
                  dateAvailedStr = d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
              }
          }
          safeSetText('pm_date_availed_val', dateAvailedStr);
          
          let typeText = "N/A";
          let labelText = "TYPE / CATEGORY";
          
          const busContainer = document.getElementById('pm_business_container');
          const stallContainer = document.getElementById('pm_stall_container');
          const dateContainer = document.getElementById('pm_date_container');
          const utilityContainer = document.getElementById('pm_utility_container');
          const assetsContainer = document.getElementById('pm_assets_container');
          
          if (data.program.toUpperCase().includes('TUPAD')) {
              if(data.type_of_beneficiary) typeText = data.type_of_beneficiary;
              labelText = "APPLICANT TYPE";
              
              if(busContainer) busContainer.style.display = 'none';
              if(stallContainer) stallContainer.style.display = 'none';
              if(dateContainer) dateContainer.style.display = 'none';
              if(utilityContainer) utilityContainer.style.display = 'none';
              if(assetsContainer) assetsContainer.style.display = 'none';
          }
          else if (data.program.toUpperCase().includes('SPES')) {
              if(data.spes_type) typeText = data.spes_type;
              labelText = "SPES CATEGORY";
              
              if(busContainer) busContainer.style.display = 'none';
              if(stallContainer) stallContainer.style.display = 'none';
              if(dateContainer) dateContainer.style.display = 'none';
              if(utilityContainer) utilityContainer.style.display = 'none';
              if(assetsContainer) assetsContainer.style.display = 'none';
          }
          else if (data.program.toUpperCase().includes('MSME')) {
              if(data.business_nature) typeText = data.business_nature;
              labelText = "NATURE OF BUSINESS";
              
              if(busContainer) { busContainer.style.display = 'block'; safeSetText('pm_business_name', data.business_name || 'N/A'); }
              if(stallContainer) { stallContainer.style.display = 'block'; safeSetText('pm_stall', data.nm_stall_no || '—'); }
              if(dateContainer) { dateContainer.style.display = 'block'; safeSetText('pm_dynamic_date_label', 'NIGHT MARKET DATE STARTED'); safeSetText('pm_nm_start', data.nm_date_started || '—'); }
              if(utilityContainer) { utilityContainer.style.display = 'block'; safeSetText('pm_utility', data.utility_needs || '—'); }
              if(assetsContainer) { assetsContainer.style.display = 'block'; safeSetText('pm_assets', data.business_assets || '—'); }
          }
          
          safeSetText('pm_dynamic_label', labelText);
          safeSetText('pm_type_beneficiary', typeText);

          renderBeneficiaryResume(data, labelText, typeText);
          
          function getAvail(s) {
              s = (s || '').toLowerCase().trim();
              if (s === "ongoing") return "pill success";
              if (s === "completed") return "pill neutral";
              if (s === "cancelled") return "pill danger";
              if (s === "requirements received") return "pill warning"; 
              if (s === "not yet availed") return "pill warning";
              return "pill neutral";
          }

          const badgeContainer = document.getElementById('pm_availment_badge');
          if (badgeContainer) {
              badgeContainer.innerHTML = `<span class="${getAvail(data.availment)}"><span class="pill-dot" style="background:currentColor;"></span> ${data.availment}</span>`;
          }

          window.currentProfileData = data;

          const footerActions = document.getElementById('pm_footer_actions');
          if (footerActions) {
              if (data.approval === 'Pending') {
                  footerActions.innerHTML = `
                      <button type="button" class="btn-light" onclick="document.getElementById('profileModal').classList.remove('show')" style="padding: 10px 20px;">Close View</button>
                  `;
              } else if (data.approval === 'Approved') {
                  footerActions.innerHTML = `
                      <button type="button" class="btn-edit btn-quick-status" onclick="openQuickStatusModal(${data.id}, '${data.availment}', '${data.date_availed || ''}', '${data.date_completed || ''}')" style="margin-right:10px;"><i class="ph-bold ph-timer" style="margin-right:6px;"></i> Update Availment</button>
                      <button type="button" class="btn-main" onclick="openEditModal(window.currentProfileData)" style="margin-right:10px;"><i class="ph-bold ph-pencil-simple" style="margin-right:6px;"></i> Full Edit</button>
                      <button type="button" class="btn-light" onclick="document.getElementById('profileModal').classList.remove('show')">Close View</button>
                  `;
              } else {
                  footerActions.innerHTML = `
                      <button type="button" class="btn-light" onclick="document.getElementById('profileModal').classList.remove('show')">Close View</button>
                  `;
              }
              if (String(data.program || currentProgramName || '').trim().toUpperCase() === 'SPES') {
                  footerActions.insertAdjacentHTML('afterbegin', `<button type="button" class="btn-light" onclick="openSpesForm(${encodeURIComponent(data.id)})" style="display:inline-flex;align-items:center;gap:8px;"><i class="ph-bold ph-file-text"></i> SPES Form</button>`);
              }
              if (String(data.program || currentProgramName || '').trim().toUpperCase().includes('MSME')) {
                  footerActions.insertAdjacentHTML('afterbegin', `<button type="button" class="btn-light" onclick="openMsmeForm(${encodeURIComponent(data.id)})" style="display:inline-flex;align-items:center;gap:8px;"><i class="ph-bold ph-file-text"></i> MSME Form</button>`);
              }
          }
          
          const pModal = document.getElementById('profileModal');
          if(pModal) pModal.classList.add('show');

      } catch (e) {
          console.error("Error opening profile modal: ", e);
      }
  };

  window.openDependentProfileByName = async function(name) {
      document.getElementById('profileModal')?.classList.remove('show');
      try {
          const response = await fetch(`beneficiary_profile_lookup.php?name=${encodeURIComponent(name)}`, { credentials: 'same-origin' });
          const result = await response.json();
          if (!result.found || !result.profile) {
              showDependentLookupNotice(name, false);
              return;
          }
          const holder = document.createElement('div');
          holder.setAttribute('data-profile', JSON.stringify(result.profile));
          window.openProfileModal(holder);
      } catch (error) {
          showDependentLookupNotice(name, true);
      }
  };

  function showDependentLookupNotice(name, isError) {
      document.querySelector('.dependent-notice-overlay')?.remove();
      const overlay = document.createElement('div');
      overlay.className = 'dependent-notice-overlay';
      const dialog = document.createElement('div');
      dialog.className = 'dependent-notice-dialog';
      dialog.setAttribute('role', 'dialog');
      dialog.setAttribute('aria-modal', 'true');
      const icon = document.createElement('div');
      icon.className = 'dependent-notice-icon';
      icon.innerHTML = `<i class="ph-bold ${isError ? 'ph-warning-circle' : 'ph-user'}"></i>`;
      const dismiss = document.createElement('button');
      dismiss.type = 'button';
      dismiss.className = 'dependent-notice-x';
      dismiss.setAttribute('aria-label', 'Close notice');
      dismiss.innerHTML = '<i class="ph-bold ph-x"></i>';
      const eyebrow = document.createElement('div');
      eyebrow.className = 'dependent-notice-eyebrow';
      eyebrow.textContent = 'TUPAD dependent check';
      const title = document.createElement('h3');
      title.textContent = isError ? 'Profile lookup unavailable' : 'No matching profile found';
      const message = document.createElement('p');
      message.textContent = isError
          ? 'The dependent profile could not be checked right now. Please try again.'
          : `${name} is recorded as a dependent but does not yet have a matching TUPAD beneficiary profile.`;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn-main dependent-notice-close';
      button.textContent = 'Close';
      const closeNotice = () => { document.removeEventListener('keydown', handleNoticeKey); overlay.remove(); };
      const handleNoticeKey = event => { if (event.key === 'Escape') closeNotice(); };
      button.onclick = closeNotice;
      dismiss.onclick = closeNotice;
      overlay.onclick = event => { if (event.target === overlay) closeNotice(); };
      document.addEventListener('keydown', handleNoticeKey);
      dialog.append(dismiss, icon, eyebrow, title, message, button);
      overlay.appendChild(dialog);
      document.body.appendChild(overlay);
      button.focus();
  }

  function toggleDateFields() {
      const availmentSelects = document.querySelectorAll('select[name="availment_status"]');
      availmentSelects.forEach((availmentSelect) => {
          const scope = availmentSelect.closest('.form-step') || availmentSelect.closest('form');
          const wrapper = scope ? scope.querySelector('[id="date_fields_wrapper"]') : null;
          if (availmentSelect && wrapper) {
              const val = availmentSelect.value;
              const group1 = wrapper.children[0].children[0];
              const group2 = wrapper.children[0].children[1];
              const label1 = group1.querySelector('label');
              const label2 = group2.querySelector('label');
              const input1 = group1.querySelector('input');
              const input2 = group2.querySelector('input');

              wrapper.style.display = 'none';
              group1.style.display = 'none';
              group2.style.display = 'none';
              input1.removeAttribute('required');
              input2.removeAttribute('required');
              input1.disabled = true;
              input2.disabled = true;
              wrapper.children[0].style.gridTemplateColumns = '1fr 1fr';

              if (val === 'Requirements Received') {
                  wrapper.style.display = 'block';
                  wrapper.children[0].style.gridTemplateColumns = '1fr'; 
                  group1.style.display = 'block';
                  input1.disabled = false;
                  label1.textContent = 'Date Received *';
                  input1.setAttribute('required', 'required');
              } else if (val === 'Ongoing') {
                  wrapper.style.display = 'block';
                  wrapper.children[0].style.gridTemplateColumns = '1fr 1fr';
                  group1.style.display = 'block';
                  group2.style.display = 'block';
                  input1.disabled = false;
                  input2.disabled = false;
                  label1.textContent = 'Date Started *';
                  label2.textContent = 'Date to be Completed';
                  input1.setAttribute('required', 'required');
              } else if (val === 'Completed') {
                  wrapper.style.display = 'block';
                  wrapper.children[0].style.gridTemplateColumns = '1fr 1fr';
                  group1.style.display = 'block';
                  group2.style.display = 'block';
                  input1.disabled = false;
                  input2.disabled = false;
                  label1.textContent = 'Date Started *';
                  label2.textContent = 'Date Completed *';
                  input1.setAttribute('required', 'required');
                  input2.setAttribute('required', 'required');
              } else {
                  input1.value = '';
                  input2.value = '';
              }
          }
      });
  }

  function renderBeneficiaryResume(data, categoryLabel, categoryValue) {
      const root = document.getElementById('pm_resume_content');
      if (!root) return;
      root.replaceChildren();

      const emptyValues = new Set(['', 'null', 'undefined', 'n/a', 'none']);
      const hasValue = value => {
          if (value === null || value === undefined) return false;
          const normalized = String(value).trim().toLowerCase();
          return !emptyValues.has(normalized);
      };
      const formatDate = value => {
          if (!hasValue(value)) return '';
          const parsed = new Date(String(value).replace(' ', 'T'));
          return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
      };
      const address = [data.street_purok_zone, data.barangay, data.municipality, data.district].filter(hasValue).join(', ');
      const programUpper = String(data.program || '').toUpperCase();
      const lastAvailmentRaw = data.last_availed_at || data.date_availed;
      const lastAvailmentYearMatch = hasValue(lastAvailmentRaw) ? String(lastAvailmentRaw).match(/\b(19|20)\d{2}\b/) : null;
      const lastAvailmentYear = lastAvailmentYearMatch ? lastAvailmentYearMatch[0] : '';
      const availmentSummary = lastAvailmentYear ? `${data.program} — ${lastAvailmentYear}` : '';
      const sections = [
          ['Personal Information', 'ph-user-circle', [
              ['Full Address', address], ['Contact Number', data.contact_no], ['Email Address', data.email || data.business_email],
              ['Birthdate', formatDate(data.birthdate)], ['Age', data.age], ['Sex', data.sex], ['Civil Status', data.civil_status],
              ['Citizenship', data.citizenship], ['Place of Birth', data.place_of_birth], ['ID Type', data.type_of_id], ['ID Number', data.id_number]
          ]],
          ['Program & Availment', 'ph-calendar-check', [
              ['Program', data.program], ['Approval Status', data.approval], ['Availment Status', data.availment],
              ['Previous / Last Availment', availmentSummary], ['Last Availed Year', lastAvailmentYear],
              ['Date Started / Availed', formatDate(data.date_availed)], ['Date Completed', formatDate(data.date_completed)], [categoryLabel, categoryValue]
          ]],
          ['Employment & Livelihood', 'ph-briefcase-metal', [
              ['Occupation', data.occupation], ['Monthly Income', data.avg_monthly_income],
              ['Interested in Employment', data.interested_in_employment],
              ['Skills / Training Needed', data.skills_training_needed || data.special_skills],
              ['Work Experience', data.work_experience]
          ]],
          ['Family & Dependents', 'ph-users-three', [
              ['Dependent', data.dependent_name], ['Relationship', data.dependent_relationship], ['Parent Status', data.parents_status],
              ["Father's Name", data.father_name], ["Father's Contact", data.father_contact], ["Father's Occupation", data.father_occupation],
              ["Mother's Name", data.mother_name], ["Mother's Contact", data.mother_contact], ["Mother's Occupation", data.mother_occupation]
          ]],
          ['Education & Experience', 'ph-graduation-cap', [
              ['Educational Attainment', data.educational_attainment],
              ['Elementary', [data.elem_school, data.elem_year_level, data.elem_date_attendance].filter(hasValue).join(' · ')],
              ['Secondary', [data.sec_school, data.sec_degree, data.sec_year_level, data.sec_date_attendance].filter(hasValue).join(' · ')],
              ['Tertiary', [data.tert_school, data.tert_course, data.tert_year_level, data.tert_date_attendance].filter(hasValue).join(' · ')],
              ['Technical-Vocational', [data.tv_school, data.tv_course, data.tv_year_level, data.tv_date_attendance].filter(hasValue).join(' · ')]
          ]],
          ['Past SPES Availments', 'ph-clock-counter-clockwise', [
              ['1st Availment', [data.spes_history_1_year, data.spes_history_1_id && `ID: ${data.spes_history_1_id}`].filter(hasValue).join(' · ')],
              ['2nd Availment', [data.spes_history_2_year, data.spes_history_2_id && `ID: ${data.spes_history_2_id}`].filter(hasValue).join(' · ')],
              ['3rd Availment', [data.spes_history_3_year, data.spes_history_3_id && `ID: ${data.spes_history_3_id}`].filter(hasValue).join(' · ')],
              ['4th Availment', [data.spes_history_4_year, data.spes_history_4_id && `ID: ${data.spes_history_4_id}`].filter(hasValue).join(' · ')]
          ]],
          ['SPES Qualification Review', 'ph-shield-check', [
              ['Age Requirement', data.age ? ((Number(data.age) >= 15 && Number(data.age) <= 30) ? `Meets requirement (${data.age} years old)` : `Does not meet requirement (${data.age} years old; required 15–30)`) : 'For verification'],
              ['Reported Family Income', data.avg_monthly_income],
              ['Income Qualification', 'Verify combined annual income against the latest Region V poverty threshold for a family of six.'],
              ['Supporting Evidence', 'Validate the latest ITR, BIR tax-exemption certification, Certificate of Indigence, or Certificate of Low Income.']
          ]],
          ['Business Information', 'ph-briefcase', [
              ['Business Name', data.business_name], ['Nature of Business', data.business_nature], ['Ownership Type', data.ownership_type],
              ['Primary Products', data.primary_products], ['Year Started', data.year_started], ['Business Permit', data.business_permit_no],
              ['Permit Validity', formatDate(data.permit_validity)], ['DTI Number', data.dti_no], ['TIN', data.tin_no],
              ['Business Assets', data.business_assets || data.assets_owned], ['Utility Needs', data.utility_needs],
              ['Workers', data.hr_total], ['Business Size', data.business_size], ['Current Capital', data.current_capital],
              ['Daily Earnings', data.daily_earnings], ['Distribution Channels', data.distribution_channels]
          ]]
      ];

      sections.forEach(([title, icon, fields]) => {
          if (title === 'Business Information' && !programUpper.includes('MSME')) return;
          if (title === 'Past SPES Availments' && !programUpper.includes('SPES')) return;
          if (title === 'SPES Qualification Review' && !programUpper.includes('SPES')) return;
          const visible = fields.filter(([, value]) => hasValue(value));
          if (!visible.length) return;
          const section = document.createElement('section');
          section.className = 'resume-section';
          const heading = document.createElement('h4');
          heading.innerHTML = `<i class="ph-fill ${icon}"></i><span></span>`;
          heading.querySelector('span').textContent = title;
          section.appendChild(heading);
          const grid = document.createElement('div');
          grid.className = 'resume-field-grid';
          visible.forEach(([label, value]) => {
              const item = document.createElement('div');
              item.className = 'resume-field';
              const key = document.createElement('span');
              key.textContent = label;
              const content = document.createElement('strong');
              if (label === 'Dependent' && programUpper.includes('TUPAD')) {
                  content.className = 'dependent-profile-link';
                  content.setAttribute('role', 'button');
                  content.setAttribute('tabindex', '0');
                  content.textContent = String(value);
                  content.onclick = () => window.openDependentProfileByName(String(value));
                  content.onkeydown = event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); content.click(); } };
              } else {
                  content.textContent = String(value);
              }
              item.append(key, content);
              grid.appendChild(item);
          });
          section.appendChild(grid);
          root.appendChild(section);
      });
  }

  document.querySelectorAll('select[name="availment_status"]').forEach(select => {
      select.addEventListener('change', toggleDateFields);
  });

  function validateSimpleForm(formElement) {
      const inputs = formElement.querySelectorAll('[required]');
      for (let input of inputs) {
          if (!input.value.trim()) {
              input.style.borderColor = '#dc2626';
              input.style.boxShadow = '0 0 0 4px rgba(220, 38, 38, 0.1)';
              input.focus();
              return false; 
          } else {
              input.style.borderColor = ''; 
              input.style.boxShadow = '';
          }
      }
      return true;
  }

  let currentStep = 1;
  let totalSteps = 3;

  function validateCurrentStep() {
      const stepDiv = document.getElementById('step-' + currentStep) || document.getElementById((currentProgramName.toUpperCase().includes('MSME') ? 'msme-step-' : (currentProgramName.toUpperCase().includes('SPES') ? 'spes-step-' : 'tupad-step-')) + currentStep);
      if(!stepDiv) return true;
      const inputs = stepDiv.querySelectorAll('[required]');
      let isValid = true;
      inputs.forEach(input => {
          if (!input.value.trim()) {
              input.style.borderColor = '#dc2626';
              input.style.boxShadow = '0 0 0 4px rgba(220, 38, 38, 0.1)';
              isValid = false;
          } else {
              input.style.borderColor = '';
              input.style.boxShadow = '';
          }
      });
      const errorMsg = document.getElementById('form-error-msg');
      if (!isValid) {
          if(errorMsg) errorMsg.style.display = 'flex';
      } else {
          if(errorMsg) errorMsg.style.display = 'none';
      }
      return isValid;
  }

  function updateWizard() {
      document.querySelectorAll('.form-step').forEach(step => step.classList.remove('active'));
      document.querySelectorAll('.wizard-step').forEach(ind => ind.classList.remove('active'));

      let stepPrefix = 'step-';
      if (currentStep > 1) {
          if(currentProgramName.toUpperCase().includes('MSME')) stepPrefix = 'msme-step-';
          else if(currentProgramName.toUpperCase().includes('SPES')) stepPrefix = 'spes-step-';
          else stepPrefix = 'tupad-step-';
      }

      const targetStep = document.getElementById(stepPrefix + currentStep) || document.getElementById('step-' + currentStep);
      if(targetStep) targetStep.classList.add('active');
      
      for(let i=1; i<=currentStep; i++) {
          const ind = document.getElementById('indicator-step-' + i);
          if(ind) ind.classList.add('active');
      }

      const btnPrev = document.getElementById('btnPrevStep');
      const btnNext = document.getElementById('btnNextStep');
      const btnSub = document.getElementById('btnSubmitForm');

      if(btnPrev) btnPrev.style.display = currentStep > 1 ? 'inline-flex' : 'none';
      
      if (currentStep === totalSteps) {
          if(btnNext) btnNext.style.display = 'none';
          if(btnSub) btnSub.style.display = 'inline-flex';
      } else {
          if(btnNext) btnNext.style.display = 'inline-flex';
          if(btnSub) btnSub.style.display = 'none';
      }
  }

  document.getElementById('btnNextStep')?.addEventListener('click', function() {
      if (validateCurrentStep()) { if (currentStep < totalSteps) { currentStep++; updateWizard(); } }
  });

  document.getElementById('btnPrevStep')?.addEventListener('click', function() {
      if (currentStep > 1) { currentStep--; updateWizard(); }
  });

  function openQuickStatusModal(id, currentStatus, dateAvailed, dateCompleted) {
      document.getElementById('profileModal')?.classList.remove('show');
      document.getElementById('adminAddBeneficiaryModal')?.classList.remove('show');
      document.getElementById('quick_beneficiary_id').value = id;
      document.getElementById('quick_availment_status').value = currentStatus || 'Not Yet Availed';
      document.getElementById('quick_date_availed').value = dateAvailed || '';
      document.getElementById('quick_date_completed').value = dateCompleted || '';
      document.getElementById('quick_schedule_place').value = '';
      document.getElementById('quick_status_message').value = '';
      toggleQuickDateFields(); // Run instantly to load matching dynamic layout
      const quickModal = document.getElementById('quickStatusModal');
      quickModal.classList.add('show');
      quickModal.setAttribute('aria-hidden', 'false');
  }

  function toggleQuickDateFields() {
      const status = document.getElementById('quick_availment_status').value;
      const wrapper = document.getElementById('quick_date_fields_wrapper');
      const group1 = document.getElementById('quick_date_1_container');
      const group2 = document.getElementById('quick_date_2_container');
      const label1 = document.getElementById('quick_date_1_label');
      const label2 = document.getElementById('quick_date_2_label');
      const input1 = document.getElementById('quick_date_availed');
      const input2 = document.getElementById('quick_date_completed');
      const dateRow = wrapper.querySelector('.form-row-2');
      const placeGroup = document.getElementById('quick_place_container');
      const placeInput = document.getElementById('quick_schedule_place');
      const messageGroup = document.getElementById('quick_message_container');
      const messageInput = document.getElementById('quick_status_message');

      // Reset
      wrapper.style.display = 'none';
      group1.style.display = 'none';
      group2.style.display = 'none';
      input1.removeAttribute('required');
      input2.removeAttribute('required');
      placeGroup.style.display = 'none';
      placeInput.removeAttribute('required');
      const needsMessage = status === 'Not Qualified' || status === 'Cancelled';
      messageGroup.style.display = needsMessage ? 'block' : 'none';
      messageInput.required = needsMessage;
      if (!needsMessage) messageInput.value = '';
      dateRow.style.gridTemplateColumns = 'minmax(0, 1fr) minmax(0, 1fr)';

      if (status === 'Orientation' || status === 'Salary Distribution') {
          wrapper.style.display = 'block';
          dateRow.style.gridTemplateColumns = 'minmax(0, 1fr)';
          group1.style.display = 'block';
          label1.textContent = status === 'Orientation' ? 'Orientation Date *' : 'Distribution Date *';
          input1.setAttribute('required', 'required');
          placeGroup.style.display = 'block';
          placeInput.setAttribute('required', 'required');
      } else if (status === 'Requirements Received') {
          wrapper.style.display = 'block';
          dateRow.style.gridTemplateColumns = 'minmax(0, 1fr)';
          group1.style.display = 'block';
          label1.textContent = 'Date Received *';
          input1.setAttribute('required', 'required');
      } else if (status === 'Ongoing') {
          wrapper.style.display = 'block';
          group1.style.display = 'block';
          group2.style.display = 'block';
          label1.textContent = 'Date Started *';
          label2.textContent = 'Date to be Completed';
          input1.setAttribute('required', 'required');
      } else if (status === 'Completed') {
          wrapper.style.display = 'block';
          group1.style.display = 'block';
          group2.style.display = 'block';
          label1.textContent = 'Date Started *';
          label2.textContent = 'Date Completed *';
          input1.setAttribute('required', 'required');
          input2.setAttribute('required', 'required');
      } else {
          input1.value = '';
          input2.value = '';
      }
  }

  document.querySelectorAll('[data-close-quick]').forEach(btn => {
      btn.addEventListener('click', () => {
          const quickModal = document.getElementById('quickStatusModal');
          quickModal.classList.remove('show');
          quickModal.setAttribute('aria-hidden', 'true');
      });
  });

  // ADDED: The Full Edit function was missing from the staff file entirely
  window.openEditModal = function(data) {
      if (typeof data === 'string') {
          try { data = JSON.parse(data); } catch(e) { console.error('Invalid edit payload', e); return; }
      }
      if (!data || typeof data !== 'object') {
          console.error('Missing beneficiary data for Full Edit');
          return;
      }

      const profileModal = document.getElementById('profileModal');
      const quickModal = document.getElementById('quickStatusModal');
      const editModal = document.getElementById('adminAddBeneficiaryModal');
      profileModal?.classList.remove('show');
      quickModal?.classList.remove('show');
      if (!editModal) return;
      editModal.classList.add('show');
      editModal.setAttribute('aria-hidden', 'false');

      if (currentProgramName.toUpperCase().includes('MSME')) {
          totalSteps = 8;
      } else if (currentProgramName.toUpperCase().includes('SPES')) {
          totalSteps = 6;
      } else {
          totalSteps = 3;
      }
      currentStep = 1;
      setupWizardNav();
      updateWizard();

      if (document.getElementById('modalTitle')) document.getElementById('modalTitle').textContent = "Edit Beneficiary Record";
      if (document.getElementById('modalSub')) document.getElementById('modalSub').textContent = "Review and update the beneficiary details step by step.";
      if (document.getElementById('btnSubmitForm')) {
          document.getElementById('btnSubmitForm').innerHTML = '<i class="ph-bold ph-check-circle" style="margin-right:6px;"></i> Save Changes';
      }
      if (document.getElementById('modalAction')) document.getElementById('modalAction').value = "admin_update_beneficiary";
      if (document.getElementById('edit_id')) document.getElementById('edit_id').value = data.id || '';
      
      if(document.getElementById('modal_program_id')) document.getElementById('modal_program_id').value = data.program_id;

      if(document.getElementById('first_name')) document.getElementById('first_name').value = data.first_name || '';
      if(document.getElementById('middle_name')) document.getElementById('middle_name').value = data.middle_name || '';
      if(document.getElementById('last_name')) document.getElementById('last_name').value = data.last_name || '';
      if(document.getElementById('ext_name')) document.getElementById('ext_name').value = data.ext_name || '';
      if(document.getElementById('birthdateInput')) document.getElementById('birthdateInput').value = data.birthdate || '';
      if(document.getElementById('ageOutput')) document.getElementById('ageOutput').value = data.age || '';
      if(document.getElementById('sex')) document.getElementById('sex').value = data.sex || '';
      if(document.getElementById('civil_status')) document.getElementById('civil_status').value = data.civil_status || '';
      if(document.getElementById('contact_no')) document.getElementById('contact_no').value = data.contact_no || '';
      if(document.getElementById('email')) document.getElementById('email').value = data.email || '';
      if(document.getElementById('street_purok_zone')) document.getElementById('street_purok_zone').value = data.street_purok_zone || '';
      if(document.getElementById('barangay')) document.getElementById('barangay').value = data.barangay || '';
      const editAvailmentStatus = ((data.availment_status || 'Not Yet Availed') + '').toLowerCase() === 'requirements recieved'
          ? 'Requirements Received'
          : (data.availment_status || 'Not Yet Availed');
      if(document.getElementById('availment_status_input')) document.getElementById('availment_status_input').value = editAvailmentStatus;
      if(document.getElementById('date_availed')) document.getElementById('date_availed').value = data.date_availed || '';
      if(document.getElementById('date_completed')) document.getElementById('date_completed').value = data.date_completed || '';

      setSelectOrOther('type_of_id', data.type_of_id);
      if(document.getElementById('id_number')) document.getElementById('id_number').value = data.id_number || '';
      setSelectOrOther('type_of_beneficiary', data.type_of_beneficiary);
      setSelectOrOther('occupation', data.occupation);
      if(document.getElementById('avg_monthly_income')) document.getElementById('avg_monthly_income').value = data.avg_monthly_income || '';
      if(document.getElementById('dependent_name')) document.getElementById('dependent_name').value = data.dependent_name || '';
      setSelectOrOther('dependent_relationship', data.dependent_relationship);
      if(document.getElementById('interested_in_employment')) document.getElementById('interested_in_employment').value = data.interested_in_employment || 'No';
      setSelectOrOther('skills_training_needed', data.skills_training_needed);

      if(document.getElementById('gsis_beneficiary_name')) document.getElementById('gsis_beneficiary_name').value = data.gsis_beneficiary_name || '';
      if(document.getElementById('gsis_relationship')) document.getElementById('gsis_relationship').value = data.gsis_relationship || '';
      if(document.getElementById('place_of_birth')) document.getElementById('place_of_birth').value = data.place_of_birth || '';
      if(document.getElementById('citizenship')) document.getElementById('citizenship').value = data.citizenship || 'Filipino';
      if(document.getElementById('social_urls')) document.getElementById('social_urls').value = data.social_media || '';
      if(document.getElementById('spes_type')) document.getElementById('spes_type').value = data.spes_type || '';
      populateCheckboxGroup('spes_parent_status', data.parents_status);
      if(document.getElementById('permanent_address')) document.getElementById('permanent_address').value = data.permanent_address || '';
      if(document.getElementById('father_name')) document.getElementById('father_name').value = data.father_name || '';
      if(document.getElementById('father_contact')) document.getElementById('father_contact').value = data.father_contact || '';
      setSelectOrOther('father_occupation', data.father_occupation);
      if(document.getElementById('mother_name')) document.getElementById('mother_name').value = data.mother_name || '';
      if(document.getElementById('mother_contact')) document.getElementById('mother_contact').value = data.mother_contact || '';
      setSelectOrOther('mother_occupation', data.mother_occupation);
      
      if(document.getElementById('elem_school')) document.getElementById('elem_school').value = data.elem_school || '';
      if(document.getElementById('elem_year_level')) document.getElementById('elem_year_level').value = data.elem_year_level || '';
      if(document.getElementById('elem_date_attendance')) document.getElementById('elem_date_attendance').value = data.elem_date_attendance || '';
      if(document.getElementById('sec_school')) document.getElementById('sec_school').value = data.sec_school || '';
      if(document.getElementById('sec_degree')) document.getElementById('sec_degree').value = data.sec_degree || '';
      if(document.getElementById('sec_year_level')) document.getElementById('sec_year_level').value = data.sec_year_level || '';
      if(document.getElementById('sec_date_attendance')) document.getElementById('sec_date_attendance').value = data.sec_date_attendance || '';
      if(document.getElementById('tert_school')) document.getElementById('tert_school').value = data.tert_school || '';
      setSelectOrOther('tert_course', data.tert_course);
      if(document.getElementById('tert_year_level')) document.getElementById('tert_year_level').value = data.tert_year_level || '';
      if(document.getElementById('tert_date_attendance')) document.getElementById('tert_date_attendance').value = data.tert_date_attendance || '';
      if(document.getElementById('tv_school')) document.getElementById('tv_school').value = data.tv_school || '';
      setSelectOrOther('tv_course', data.tv_course);
      if(document.getElementById('tv_year_level')) document.getElementById('tv_year_level').value = data.tv_year_level || '';
      if(document.getElementById('tv_date_attendance')) document.getElementById('tv_date_attendance').value = data.tv_date_attendance || '';
      
      if(document.getElementById('special_skills_spes')) document.getElementById('special_skills_spes').value = data.special_skills || '';
      
      let legacySpesHistory = [];
      try { legacySpesHistory = Array.isArray(data.spes_history) ? data.spes_history : JSON.parse(data.spes_history || '[]'); } catch (e) { legacySpesHistory = []; }
      const spesHistoryValue = (number, column, legacyIndex) => data[`spes_history_${number}_${column}`] || legacySpesHistory[number - 1]?.[legacyIndex] || '';
      if(document.getElementById('spes_history_1_year')) document.getElementById('spes_history_1_year').value = spesHistoryValue(1, 'year', 2);
      if(document.getElementById('spes_history_1_establishment')) document.getElementById('spes_history_1_establishment').value = spesHistoryValue(1, 'establishment', 1);
      if(document.getElementById('spes_history_1_id')) document.getElementById('spes_history_1_id').value = spesHistoryValue(1, 'id', 3);
      for (let number = 2; number <= 4; number++) {
          const establishment = document.getElementById(`spes_history_${number}_establishment`);
          const year = document.getElementById(`spes_history_${number}_year`);
          const id = document.getElementById(`spes_history_${number}_id`);
          if (establishment) establishment.value = spesHistoryValue(number, 'establishment', 1);
          if (year) year.value = spesHistoryValue(number, 'year', 2);
          if (id) id.value = spesHistoryValue(number, 'id', 3);
      }
      if(document.getElementById('spes_other_info')) document.getElementById('spes_other_info').value = data.spes_other_info || '';

      if(document.getElementById('business_name')) document.getElementById('business_name').value = data.business_name || '';
      populateProductRows(data.primary_products, data.product_price);
      setSelectOrOther('ownership_type', data.ownership_type);
      if(document.getElementById('year_started')) document.getElementById('year_started').value = data.year_started || '';
      if(document.getElementById('business_permit_no')) document.getElementById('business_permit_no').value = data.business_permit_no || '';
      if(document.getElementById('permit_validity')) document.getElementById('permit_validity').value = data.permit_validity || '';
      if(document.getElementById('dti_no')) document.getElementById('dti_no').value = data.dti_no || '';
      if(document.getElementById('tin_no')) document.getElementById('tin_no').value = data.tin_no || '';
      if(document.getElementById('business_email')) document.getElementById('business_email').value = data.business_email || '';
      if(document.getElementById('business_social_media')) document.getElementById('business_social_media').value = data.business_social_media || '';
      setSelectOrOther('educational_attainment', data.educational_attainment);
      if(document.getElementById('work_experience')) document.getElementById('work_experience').value = data.work_experience || '';
      if(document.getElementById('nm_stall_no')) document.getElementById('nm_stall_no').value = data.nm_stall_no || '';
      if(document.getElementById('nm_date_started')) document.getElementById('nm_date_started').value = data.nm_date_started || '';
      ['hr_male','hr_female','hr_total','emp_regular','emp_seasonal','emp_contractual','emp_family','hr_skills','business_size','initial_capital','current_capital','daily_earnings','availed_before'].forEach(name => populateNamedField(name, data[name]));
      populateCheckboxGroup('business_nature_arr', data.business_nature);
      populateCheckboxGroup('assets_owned', data.assets_owned);
      populateCheckboxGroup('utility_needs', data.utility_needs);
      populateCheckboxGroup('source_of_capital', data.source_of_capital);
      populateCheckboxGroup('mode_of_payment', data.mode_of_payment);
      populateCheckboxGroup('distribution_channels', data.distribution_channels);
      populateCheckboxGroup('assistance_availed', data.assistance_availed);
      populateCheckboxGroup('past_programs', data.past_programs);
      populateCheckboxGroup('programs_needed', data.programs_needed);
      populateCheckboxGroup('challenges_encountered', data.challenges_encountered);

      if (currentProgramName.toUpperCase().includes('MSME')) {
          totalSteps = 8;
      } else if (currentProgramName.toUpperCase().includes('SPES')) {
          totalSteps = 6;
      } else {
          totalSteps = 3;
      }

      currentStep = 1;
      setupWizardNav();
      updateWizard();
      toggleDateFields(); 
      editModal.classList.add('show');
  };

  document.querySelectorAll('.auto-submit').forEach(el => { el.addEventListener('change', () => document.getElementById('filterForm').submit()); });

  document.addEventListener('DOMContentLoaded', () => {
      const addModal = document.getElementById('adminAddBeneficiaryModal');
      const bulkModal = document.getElementById('bulkUploadModal');
      const reportModal = document.getElementById('generateReportModal');
      const succModal = document.getElementById('successModal');
      const errModal = document.getElementById('errorModal');
      const profileModal = document.getElementById('profileModal');

      document.querySelectorAll('#openAddBeneficiaryModal, #openAddBeneficiaryModal2').forEach(btn => {
          if(btn) btn.addEventListener('click', () => {
              document.getElementById('modalTitle').textContent = "Add Beneficiary Record";
              document.getElementById('modalSub').textContent = "Create a new profile under <?php echo h($selectedProgramName); ?>.";
              document.getElementById('btnSubmitForm').innerHTML = '<i class="ph-bold ph-check-circle" style="margin-right:6px;"></i> Save Beneficiary';
              document.getElementById('modalAction').value = "admin_add_beneficiary";
              document.getElementById('edit_id').value = "";
              document.querySelector('#multiStepForm').reset();
              populateProductRows('', '');
              document.querySelectorAll('[id^="other_"]').forEach(el => { el.style.display = 'none'; el.removeAttribute('required'); });
              document.querySelectorAll('#multiStepForm input[type="checkbox"]').forEach(cb => cb.checked = false);

              if (currentProgramName.toUpperCase().includes('MSME')) {
                  totalSteps = 8;
              } else if (currentProgramName.toUpperCase().includes('SPES')) {
                  totalSteps = 6;
              } else {
                  totalSteps = 3;
              }

              currentStep = 1;
              setupWizardNav();
              updateWizard();
              toggleDateFields(); 
              addModal.classList.add('show');
          });
      });
      document.querySelectorAll('[data-close-modal]').forEach(btn => btn.addEventListener('click', () => addModal.classList.remove('show')));
      
      document.querySelectorAll('#openBulkUploadModal, #openBulkUploadModal2').forEach(btn => { if(btn) btn.addEventListener('click', () => bulkModal.classList.add('show')); });
      document.querySelectorAll('[data-close-bulk]').forEach(btn => btn.addEventListener('click', () => bulkModal.classList.remove('show')));
      
      const fileInput = document.getElementById('bulkFileInput');
      const fileNameDisplay = document.getElementById('bulkFileName');
      if(fileInput && fileNameDisplay) {
          fileInput.addEventListener('change', function() {
              if(this.files && this.files.length > 0) {
                  fileNameDisplay.textContent = this.files[0].name;
                  fileNameDisplay.style.color = "var(--green)";
              } else {
                  fileNameDisplay.textContent = "Click to select your Excel (.xlsx) or CSV file";
                  fileNameDisplay.style.color = "var(--green-dark)";
              }
          });
      }

      const openReportBtn = document.getElementById('openReportModal');
      if(openReportBtn) {
          openReportBtn.addEventListener('click', () => {
              reportModal.classList.add('show');
              updateReportPreview(); 
          });
      }
      document.querySelectorAll('[data-close-report]').forEach(btn => btn.addEventListener('click', () => reportModal.classList.remove('show')));

      const reportBrgySelect = document.getElementById('report_brgy_select');
      const reportAvailSelect = document.getElementById('report_avail_select');
      const reportBatchSelect = document.getElementById('report_batch_select');
      const reportNatureSelect = document.getElementById('report_nature_select');
      const reportPrintButton = document.getElementById('report_print_button');
      const reportColumnInputs = Array.from(document.querySelectorAll('.report-column-list input[type="checkbox"]'));
      const reportColumnCount = document.querySelector('.report-column-count');
      const reportColumnDefinitions = <?php echo json_encode(getReportColumnDefinitions($selectedProgramName), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

      function reportProductParts(row) {
          let names = [];
          let prices = [];
          const raw = String(row.primary_products || '').trim();
          try {
              const decoded = JSON.parse(raw);
              if (Array.isArray(decoded)) decoded.forEach(product => {
                  if (!Array.isArray(product) || !String(product[0] || '').trim()) return;
                  names.push(String(product[0]).trim());
                  prices.push(String(product[1] || '').trim());
              });
          } catch (error) {
              raw.split(/,\s*(?![^()]*\))/).filter(Boolean).forEach(product => {
                  const match = product.trim().match(/^(.*?)\s*\((?:₱|PHP\s*)?([0-9.,]+)\)$/i);
                  names.push(match ? match[1].trim() : product.trim());
                  if (match) prices.push(match[2].trim());
              });
          }
          if (String(row.product_price || '').trim()) prices = String(row.product_price).split(',').map(price => price.trim());
          return { names: names.join(', '), prices: prices.filter(Boolean).join(', ') };
      }

      function reportCellValue(row, key) {
          const products = (key === 'primary_products' || key === 'product_price') ? reportProductParts(row) : null;
          if (key === 'primary_products') return products.names;
          if (key === 'product_price') return products.prices;
          if (key === 'owner_name') return row.full_name || [row.first_name, row.middle_name, row.last_name, row.ext_name].filter(Boolean).join(' ');
          if (key === 'owner_contact') return row.contact_no || '';
          if (key === 'complete_address') return row.address || [row.street_purok_zone, row.barangay, 'Vinzons, Camarines Norte'].filter(Boolean).join(' ');
          if (key === 'school') return row.tert_school || row.sec_school || row.elem_school || '';
          if (key === 'course') return row.tert_course || row.sec_degree || '';
          if (key === 'level') return row.tert_year_level || row.sec_year_level || row.elem_year_level || '';
          if (key === 'parent_name') return row.father_name || row.mother_name || row.gsis_beneficiary_name || '';
          if (key === 'parent_occupation') return row.father_occupation || row.mother_occupation || '';
          if (key === 'hr_total') return row.hr_total || ((parseInt(row.hr_male) || 0) + (parseInt(row.hr_female) || 0)) || '';
          if (key === 'employment_type') return row.employment_type || [row.emp_regular ? 'Regular' : '', row.emp_seasonal ? 'Seasonal' : '', row.emp_contractual ? 'Contractual' : '', row.emp_family ? 'Family' : ''].filter(Boolean).join(', ');
          if (key === 'current_capital') return row.current_capital || row.initial_capital || '';
          if (key === 'remarks') return row.approval_note || row.remarks || '';
          if (key === 'approval_status') return String(row.approval_status || '').toUpperCase() === 'APPROVED' ? 'QUALIFIED' : (String(row.approval_status || '').toUpperCase() === 'REJECTED' ? 'DISQUALIFIED' : row.approval_status || '');
          if (key === 'spes_history' && !row.spes_history) return [1,2,3,4].map(number => [row[`spes_history_${number}_year`], row[`spes_history_${number}_id`]].filter(Boolean).join(' / ')).filter(Boolean).join('; ');
          if (['dti_assistance','dole_assistance','lgu_assistance','tesda_training'].includes(key) && !row[key]) return String(row.assistance_availed || '').toUpperCase().includes(key.split('_')[0].toUpperCase()) ? 'Yes' : '';
          if (key === 'financial_assistance' && !row[key]) return String(row.programs_needed || '').toLowerCase().includes('financ') ? 'Needed' : '';
          if (key === 'livelihood_assistance' && !row[key]) return String(row.assistance_availed || '').toLowerCase().includes('livelihood') ? 'Yes' : '';
          if (key === 'business_training' && !row[key]) return String(row.past_programs || '').toLowerCase().includes('training') ? 'Yes' : '';
          return row[key] ?? '';
      }

      function escapeReportValue(value) {
          const div = document.createElement('div');
          div.textContent = String(value ?? '');
          return div.innerHTML;
      }
      
      if(reportBrgySelect && reportAvailSelect) {
          reportBrgySelect.addEventListener('change', updateReportPreview);
          reportAvailSelect.addEventListener('change', updateReportPreview);
          if (reportBatchSelect) reportBatchSelect.addEventListener('change', updateReportPreview);
          if (reportNatureSelect) reportNatureSelect.addEventListener('change', updateReportPreview);
      }
      if (reportPrintButton) reportPrintButton.addEventListener('click', printFilteredReport);
      reportColumnInputs.forEach(input => input.addEventListener('change', () => {
          if (!reportColumnInputs.some(column => column.checked)) input.checked = true;
          updateReportPreview();
      }));
      document.querySelectorAll('[data-report-columns]').forEach(button => button.addEventListener('click', () => {
          const selectAll = button.dataset.reportColumns === 'all';
          reportColumnInputs.forEach(input => { input.checked = selectAll || input.dataset.default === '1'; });
          updateReportPreview();
      }));

      function applyReportColumnVisibility() {
          const table = document.getElementById('preview_main_table');
          const thead = document.getElementById('preview_table_head');
          if (!table || !thead || reportColumnInputs.length === 0) return;

          if (thead.rows.length > 1) {
              const labels = Array.from(thead.rows[thead.rows.length - 1].cells).map(cell => cell.textContent);
              thead.innerHTML = `<tr><th>No.</th>${labels.map(label => `<th>${label}</th>`).join('')}</tr>`;
          }

          const visibleIndexes = new Set([0]);
          reportColumnInputs.forEach(input => { if (input.checked) visibleIndexes.add(Number(input.dataset.columnIndex)); });
          const cellFontSize = visibleIndexes.size <= 14 ? '10px' : (visibleIndexes.size <= 22 ? '9px' : '8px');
          const headerLabels = Array.from(thead.rows[0]?.cells || []).map(cell => cell.textContent.trim());
          table.classList.remove('report-detail-view');
          table.style.minWidth = visibleIndexes.size <= 8 ? '100%' : `${Math.max(1100, visibleIndexes.size * 125)}px`;
          table.querySelectorAll('tr').forEach(row => {
              if (row.cells.length === 1 && row.cells[0].hasAttribute('colspan')) {
                  row.cells[0].colSpan = visibleIndexes.size;
                  return;
              }
              Array.from(row.cells).forEach((cell, index) => {
                  cell.style.display = visibleIndexes.has(index) ? '' : 'none';
                  cell.style.fontSize = cellFontSize;
                  cell.classList.toggle('report-column-visible', visibleIndexes.has(index));
                  if (row.parentElement?.tagName === 'TBODY') cell.dataset.label = headerLabels[index] || '';
              });
          });
          if (reportColumnCount) reportColumnCount.textContent = `${visibleIndexes.size - 1} selected`;
      }

      function updateReportPreview() {
          const selectedBrgy = reportBrgySelect.value;
          const selectedAvail = reportAvailSelect.value;
          const selectedBatchId = reportBatchSelect ? reportBatchSelect.value : '0';
          const selectedNature = reportNatureSelect ? reportNatureSelect.value : 'All';
          
          const subtitleParts = [];
          if (selectedBatchId !== '0' && reportBatchSelect) subtitleParts.push(`Batch: ${reportBatchSelect.options[reportBatchSelect.selectedIndex].text}`);
          subtitleParts.push(selectedBrgy === 'All' || selectedBrgy === '' ? 'Municipality of Vinzons' : `Barangay ${selectedBrgy}, Vinzons`);
          if (selectedAvail !== 'All') subtitleParts.push(`Availment: ${selectedAvail}`);
          if (selectedNature !== 'All') subtitleParts.push(`Vendor Type: ${selectedNature}`);
          const subtitleText = subtitleParts.join(' | ');
          const subTitle = document.getElementById('preview_subtitle_top');
          if (subTitle) subTitle.textContent = subtitleText;
          
          const thead = document.getElementById('preview_table_head');
          
          if (thead) {
              if (reportColumnDefinitions.length) {
                  thead.innerHTML = `<tr><th>No.</th>${reportColumnDefinitions.map(column => `<th>${escapeReportValue(column.label)}</th>`).join('')}</tr>`;
              } else if (currentProgramName.toUpperCase().includes('MSME')) {
                  thead.innerHTML = `
                  <tr>
                      <th rowspan="2">No.</th>
                      <th colspan="13">I. BUSINESS PROFILE</th>
                      <th colspan="9">II. OWNER/ENTREPRENEUR INFORMATION</th>
                      <th colspan="4">III. BUSINESS OPERATIONS</th>
                      <th colspan="5">IV. HUMAN RESOURCES</th>
                      <th colspan="7">V. FINANCIAL INFORMATION</th>
                      <th colspan="7">VI. GOVERNMENT ASSISTANCE</th>
                  </tr>
                  <tr>
                      <th>Business/Trade Name</th><th>Type of Ownership</th><th>Nature of Business</th><th>Primary Products Offered</th><th>Product Price</th><th>Year Business Started</th><th>Business Permit No.</th><th>Valid Until</th><th>DTI Registration No.</th><th>Tax Identification No. (TIN)</th><th>Landline/Mobile Number</th><th>Email</th><th>Website/Social Media</th>
                      <th>Full Name</th><th>Contact Number</th><th>Sex</th><th>Date of Birth</th><th>Age</th><th>Civil Status</th><th>Complete Address</th><th>Educational Attainment</th><th>Work Experience</th>
                      <th>Stall/Booth No.</th><th>Date Started</th><th>Business Assets Owned</th><th>Utility Needs</th>
                      <th>Number of Workers</th><th>Male Employees</th><th>Female Employees</th><th>Employment Type</th><th>Skills Needed</th>
                      <th>Estimated Daily Sales</th><th>Estimated Monthly Sales</th><th>Estimated Capital</th><th>Source of Capital</th><th>Average Monthly Expenses</th><th>Banking Access</th><th>Existing Loans/Credit</th>
                      <th>DTI Assistance</th><th>DOLE Assistance</th><th>LGU Assistance</th><th>TESDA Training</th><th>Financial Assistance</th><th>Livelihood Assistance</th><th>Business Training</th>
                  </tr>`;
              } else if (currentProgramName.toUpperCase().includes('SPES')) {
                  thead.innerHTML = `
                  <tr>
                      <th>No.</th>
                      <th>Last Name</th>
                      <th>First Name</th>
                      <th>Middle Name</th>
                      <th>Sex</th>
                      <th>Date of Birth</th>
                      <th>Age</th>
                      <th>Complete Address</th>
                      <th>Barangay</th>
                      <th>Contact Number</th>
                      <th>School</th>
                      <th>Course/Strand</th>
                      <th>Level</th>
                      <th>Parent/Guardian Name</th>
                      <th>Parent Occupation</th>
                      <th>Est. Monthly Income</th>
                      <th>Date of Application</th>
                      <th>Status</th>
                      <th>Work Assignment</th>
                      <th>Start Date</th>
                      <th>End Date</th>
                      <th>Remarks</th>
                  </tr>`;
              } else if (currentProgramName.toUpperCase().includes('TUPAD')) {
                  thead.innerHTML = `
                  <tr>
                      <th>No.</th>
                      <th>Last Name</th>
                      <th>First Name</th>
                      <th>Middle Name</th>
                      <th>Extension Name</th>
                      <th>Sex</th>
                      <th>Date of Birth</th>
                      <th>Age</th>
                      <th>Civil Status</th>
                      <th>Contact Number</th>
                      <th>Complete Address</th>
                      <th>Barangay</th>
                      <th>Educational Attainment</th>
                      <th>Occupation</th>
                      <th>Skills</th>
                      <th>Family Income</th>
                      <th>Dependents</th>
                      <th>ID Type</th>
                      <th>ID Number</th>
                      <th>Nature of Project</th>
                      <th>Work Assignment</th>
                      <th>Start Date</th>
                      <th>End Date</th>
                      <th>Days of Work</th>
                      <th>Salary/Wage</th>
                      <th>Status</th>
                      <th>Remarks</th>
                  </tr>`;
              } else {
                  thead.innerHTML = `<tr><th>No.</th><th>Names</th><th>Barangay</th></tr>`;
              }
              
              let filtered = allBeneficiariesData.filter(b => {
                  let matchBrgy = selectedBrgy === 'All' || b.barangay === selectedBrgy;
                  let matchAvail = selectedAvail === 'All' || b.availment_status === selectedAvail;
                  let matchBatch = selectedBatchId === '0' || b.program_id == selectedBatchId;
                  let matchNature = selectedNature === 'All' || b.business_nature === selectedNature;
                  return matchBrgy && matchAvail && matchBatch && matchNature;
              });

              filtered.sort((a, b) => {
                  if (selectedBrgy === 'All') {
                      let brgyCmp = (a.barangay || '').localeCompare(b.barangay || '');
                      if (brgyCmp !== 0) return brgyCmp;
                  }
                  let nameA = ((a.last_name||'') + ' ' + (a.first_name||'')).trim().toLowerCase();
                  let nameB = ((b.last_name||'') + ' ' + (b.first_name||'')).trim().toLowerCase();
                  return nameA.localeCompare(nameB);
              });
              
              previewData = filtered;
              currentPreviewPage = 1;
              renderPreviewPage();
          }
      }
      
      window.changePreviewPage = function(pageNumber) {
          const totalPages = Math.ceil(previewData.length / previewItemsPerPage);
          if (pageNumber >= 1 && pageNumber <= totalPages) {
              currentPreviewPage = pageNumber;
              renderPreviewPage();
          }
      };
      
      function renderPreviewPage() {
          const tbody = document.getElementById('preview_table_body');
          if (!tbody) return;
          tbody.innerHTML = '';
          
          const pag = document.getElementById('preview_pagination');

          if(previewData.length === 0) {
              let colspan = reportColumnDefinitions.length + 1;
              if (!reportColumnDefinitions.length && currentProgramName.toUpperCase().includes('MSME')) colspan = 47;
              if (!reportColumnDefinitions.length && currentProgramName.toUpperCase().includes('SPES')) colspan = 22;
              tbody.innerHTML = `<tr><td colspan="${colspan}" style="text-align: center; padding: 24px; color: #667085; font-style: italic;">No records match these filters</td></tr>`;
              
              pag.innerHTML = '';
              pag.style.display = 'none';
              applyReportColumnVisibility();
              return;
          }
          
          let startIndex = (currentPreviewPage - 1) * previewItemsPerPage;
          let endIndex = startIndex + previewItemsPerPage;
          let pageData = previewData.slice(startIndex, endIndex);
          
          pageData.forEach((b, i) => {
              let globalIndex = startIndex + i + 1;
              if (reportColumnDefinitions.length) {
                  tbody.innerHTML += `<tr><td style="text-align:center;">${globalIndex}</td>${reportColumnDefinitions.map(column => `<td>${escapeReportValue(reportCellValue(b, column.key))}</td>`).join('')}</tr>`;
              } else if (currentProgramName.toUpperCase().includes('MSME')) {
                  let ownerName = `${b.first_name || ''} ${b.middle_name || ''} ${b.last_name || ''}`.trim();
                  let address = `${b.street_purok_zone || ''} ${b.barangay || ''} Vinzons, CN`.trim();
                  
                  let hrMale = parseInt(b.hr_male) || 0;
                  let hrFem = parseInt(b.hr_female) || 0;
                  let totalHR = b.hr_total ? b.hr_total : (hrMale + hrFem);
                  let empType = b.employment_type ? b.employment_type : [b.emp_regular ? 'Regular' : '', b.emp_seasonal ? 'Seasonal' : '', b.emp_contractual ? 'Contractual' : '', b.emp_family ? 'Family' : ''].filter(Boolean).join(', ');
                  
                  let prodCombined = b.primary_products || '';
                  if (b.product_price && prodCombined !== '') prodCombined += ' (' + b.product_price + ')';

                  let hasDTI = b.dti_assistance ? b.dti_assistance : ((b.assistance_availed || '').includes('DTI') ? 'Yes' : '');
                  let hasDOLE = b.dole_assistance ? b.dole_assistance : ((b.assistance_availed || '').includes('DOLE') ? 'Yes' : '');
                  let hasLGU = b.lgu_assistance ? b.lgu_assistance : ((b.assistance_availed || '').includes('LGU') ? 'Yes' : '');
                  let hasTESDA = b.tesda_training ? b.tesda_training : ((b.assistance_availed || '').includes('TESDA') ? 'Yes' : '');
                  let hasFin = b.financial_assistance ? b.financial_assistance : ((b.programs_needed || '').includes('Financing') ? 'Needed' : '');
                  let hasLiv = b.livelihood_assistance ? b.livelihood_assistance : ((b.assistance_availed || '').includes('Livelihood') ? 'Yes' : '');
                  let hasTrain = b.business_training ? b.business_training : ((b.past_programs || '').includes('Training') ? 'Yes' : '');
                  
                  tbody.innerHTML += `
                  <tr>
                      <td style="text-align: center;">${globalIndex}</td>
                      <td>${b.business_name || ''}</td>
                      <td>${b.ownership_type || ''}</td>
                      <td>${b.business_nature || ''}</td>
                      <td>${prodCombined}</td>
                      <td>${b.product_price || ''}</td>
                      <td>${b.year_started || ''}</td>
                      <td>${b.business_permit_no || ''}</td>
                      <td>${b.permit_validity || ''}</td>
                      <td>${b.dti_no || ''}</td>
                      <td>${b.tin_no || ''}</td>
                      <td>${b.contact_no || ''}</td>
                      <td>${b.business_email || ''}</td>
                      <td>${b.business_social_media || ''}</td>
                      <td style="font-weight:600; color:#101828;">${ownerName}</td>
                      <td>${b.contact_no || ''}</td>
                      <td>${b.sex || ''}</td>
                      <td>${b.birthdate || ''}</td>
                      <td>${b.age || ''}</td>
                      <td>${b.civil_status || ''}</td>
                      <td>${address}</td>
                      <td>${b.educational_attainment || ''}</td>
                      <td>${b.work_experience || ''}</td>
                      <td>${b.nm_stall_no || ''}</td>
                      <td>${b.nm_date_started || ''}</td>
                      <td>${b.business_assets || ''}</td>
                      <td>${b.utility_needs || ''}</td>
                      <td>${totalHR}</td>
                      <td>${b.hr_male || ''}</td>
                      <td>${b.hr_female || ''}</td>
                      <td>${empType}</td>
                      <td>${b.skills_training_needed || ''}</td>
                      <td>${b.daily_earnings || ''}</td>
                      <td>${b.monthly_earnings || ''}</td>
                      <td>${b.current_capital || b.initial_capital || ''}</td>
                      <td>${b.source_of_capital || ''}</td>
                      <td>${b.monthly_expenses || ''}</td>
                      <td>${b.banking_access || ''}</td>
                      <td>${b.existing_loans || ''}</td>
                      <td>${hasDTI}</td>
                      <td>${hasDOLE}</td>
                      <td>${hasLGU}</td>
                      <td>${hasTESDA}</td>
                      <td>${hasFin}</td>
                      <td>${hasLiv}</td>
                      <td>${hasTrain}</td>
                  </tr>`;
              } else if (currentProgramName.toUpperCase().includes('SPES')) {
                  let dob = b.birthdate ? b.birthdate.replace(/-/g, '/') : '';
                  let address = `${b.street_purok_zone || ''} ${b.barangay || ''} Vinzons, CN`.trim();
                  let school = b.tert_school || b.sec_school || b.elem_school || '';
                  let course = b.tert_course || b.sec_degree || '';
                  let level = b.tert_year_level || b.sec_year_level || b.elem_year_level || '';
                  let parentName = b.father_name || b.mother_name || '';
                  let parentOcc = b.father_occupation || b.mother_occupation || '';
                  let dateApp = b.created_at ? new Date(b.created_at).toLocaleDateString() : '';

                  tbody.innerHTML += `
                  <tr>
                      <td style="text-align: center;">${globalIndex}</td>
                      <td style="font-weight:600; color:#101828;">${b.last_name || ''}</td>
                      <td style="font-weight:600; color:#101828;">${b.first_name || ''}</td>
                      <td>${b.middle_name || ''}</td>
                      <td>${b.sex || ''}</td>
                      <td>${dob}</td>
                      <td>${b.age || ''}</td>
                      <td>${address}</td>
                      <td>${b.barangay || ''}</td>
                      <td>${b.contact_no || ''}</td>
                      <td>${school}</td>
                      <td>${course}</td>
                      <td>${level}</td>
                      <td>${parentName}</td>
                      <td>${parentOcc}</td>
                      <td>${b.avg_monthly_income || ''}</td>
                      <td>${dateApp}</td>
                      <td style="font-weight:bold; color:#1f7a54;">${b.approval_status || ''}</td>
                      <td></td>
                      <td>${b.date_availed || ''}</td>
                      <td>${b.date_completed || ''}</td>
                      <td></td>
                  </tr>`;
              } else if (currentProgramName.toUpperCase().includes('TUPAD')) {
                  let dob = b.birthdate ? b.birthdate.replace(/-/g, '/') : '';
                  let address = `${b.street_purok_zone || ''} ${b.barangay || ''} Vinzons, CN`.trim();
                  
                  tbody.innerHTML += `
                  <tr>
                      <td style="text-align: center;">${globalIndex}</td>
                      <td style="font-weight:600; color:#101828;">${b.last_name || ''}</td>
                      <td style="font-weight:600; color:#101828;">${b.first_name || ''}</td>
                      <td>${b.middle_name || ''}</td>
                      <td>${b.ext_name || ''}</td>
                      <td>${b.sex || ''}</td>
                      <td>${dob}</td>
                      <td>${b.age || ''}</td>
                      <td>${b.civil_status || ''}</td>
                      <td>${b.contact_no || ''}</td>
                      <td>${address}</td>
                      <td>${b.barangay || ''}</td>
                      <td>${b.educational_attainment || ''}</td>
                      <td>${b.occupation || ''}</td>
                      <td>${b.skills_training_needed || ''}</td>
                      <td>${b.avg_monthly_income || ''}</td>
                      <td>${b.dependent_name || ''}</td>
                      <td>${b.type_of_id || ''}</td>
                      <td>${b.id_number || ''}</td>
                      <td></td>
                      <td></td>
                      <td>${b.date_availed || ''}</td>
                      <td>${b.date_completed || ''}</td>
                      <td></td>
                      <td></td>
                      <td>${b.availment_status || ''}</td>
                      <td></td>
                  </tr>`;
              } else {
                  let dispName = b.full_name || '';
                  if (!dispName || dispName.toLowerCase().includes("null null")) dispName = `${b.first_name || ''} ${b.middle_name || ''} ${b.last_name || ''} ${b.ext_name || ''}`.trim().replace(/\s+/g, ' ');
                  tbody.innerHTML += `<tr><td style="text-align: center;">${globalIndex}</td><td style="font-weight: bold; text-transform: uppercase; color:#101828;">${dispName}</td><td style="text-transform: uppercase;">${b.barangay || '—'}</td></tr>`;
              }
          });
          
          const totalPages = Math.ceil(previewData.length / previewItemsPerPage);
          
          pag.style.display = 'flex';
          pag.style.justifyContent = 'space-between';
          pag.style.alignItems = 'center';
          pag.style.width = '100%';
          pag.style.padding = '16px';
          pag.style.borderTop = '1px solid #eaecf0';

          if(previewData.length === 0) {
              pag.innerHTML = '';
              pag.style.display = 'none';
          } else {
              pag.innerHTML = `
                  <div style="font-size: 13px; color: #667085;">
                      Showing <strong>${startIndex + 1}</strong> to <strong>${Math.min(endIndex, previewData.length)}</strong> of <strong>${previewData.length}</strong> entries
                  </div>
                  <div style="display: flex; align-items: center; gap: 8px;">
                      <button type="button" style="padding: 6px 12px; border: 1px solid #d0d5dd; background: #fff; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; color: #344054; display: flex; align-items: center; gap: 4px;" onclick="changePreviewPage(${currentPreviewPage - 1})" ${currentPreviewPage === 1 ? 'disabled' : ''}>
                          <i class="ph-bold ph-caret-left"></i> Prev
                      </button>
                      <span style="font-size: 13px; font-weight: 600; color: #344054; margin: 0 4px;">Page ${currentPreviewPage} of ${totalPages}</span>
                      <button type="button" style="padding: 6px 12px; border: 1px solid #d0d5dd; background: #fff; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; color: #344054; display: flex; align-items: center; gap: 4px;" onclick="changePreviewPage(${currentPreviewPage + 1})" ${currentPreviewPage === totalPages ? 'disabled' : ''}>
                          Next <i class="ph-bold ph-caret-right"></i>
                      </button>
                  </div>
              `;
          }
          applyReportColumnVisibility();
      }

      function printFilteredReport() {
          updateReportPreview();
          if (previewData.length === 0) {
              alert('No records match the selected report filters.');
              return;
          }

          const tbody = document.getElementById('preview_table_body');
          const thead = document.getElementById('preview_table_head');
          const originalPage = currentPreviewPage;
          const totalPages = Math.ceil(previewData.length / previewItemsPerPage);
          const printRows = [];

          for (let page = 1; page <= totalPages; page++) {
              currentPreviewPage = page;
              renderPreviewPage();
              tbody.querySelectorAll('tr').forEach(row => printRows.push(row.cloneNode(true)));
          }
          currentPreviewPage = originalPage;
          renderPreviewPage();

          const printWindow = window.open('', '_blank', 'width=1400,height=900');
          if (!printWindow) {
              alert('Please allow pop-ups to print this report.');
              return;
          }

          const printColumnCount = Array.from(thead.rows[0]?.cells || []).filter(cell => cell.style.display !== 'none').length;
          const printFontSize = printColumnCount > 30 ? '4.5pt' : (printColumnCount > 20 ? '5.5pt' : '7pt');
          const officialHeaderUrl = new URL('assets/peso_official_report_header.png?v=20260820-compact', window.location.href).href;
          printWindow.document.write(`<!DOCTYPE html><html><head><title>Beneficiary Report</title><style>
              @page { size: A3 landscape; margin: 8mm; }
              @page { size: A3 landscape; margin: 8mm; }
              * { box-sizing: border-box; }
              body { margin: 0; color: #142b20; font-family: Arial, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
              .official-header-wrap { width: 100%; margin: 0 0 4px; text-align: center; }
              .official-header { display: block; width: 100%; height: auto; margin: 0; }
              .print-brand { margin-bottom: 6px; text-align: center; color: #142b20; }
              h1 { margin: 0 0 2px; font-size: 15pt; line-height: 1.15; }
              p { margin: 0; font-size: 9pt; color: #466557; }
              table { width: 100%; table-layout: auto; border-collapse: collapse; }
              thead { display: table-header-group; }
              th, td { border: 1px solid #9eb8aa; padding: 3px 2px; font-size: ${printFontSize}; line-height: 1.2; overflow-wrap: anywhere; vertical-align: middle; text-align: center; }
              th { background: #21845b; color: #fff; font-weight: 700; text-align: center; }
              tbody tr:nth-child(even) td { background: #f1f7f4; }
          </style></head><body><div class="official-header-wrap"><img id="print_official_header" class="official-header" src="${officialHeaderUrl}" alt="PESO Vinzons official letterhead"></div><div class="print-brand"><h1 id="print_report_title"></h1><p id="print_report_subtitle"></p></div><table><thead id="print_report_head"></thead><tbody id="print_report_body"></tbody></table></body></html>`);
          printWindow.document.close();
          printWindow.document.getElementById('print_report_title').textContent = currentReportTitle;
          printWindow.document.getElementById('print_report_subtitle').textContent = document.getElementById('preview_subtitle_top')?.textContent || '';
          printWindow.document.getElementById('print_report_head').replaceWith(printWindow.document.importNode(thead, true));
          const printBody = printWindow.document.getElementById('print_report_body');
          printRows.forEach(row => printBody.appendChild(printWindow.document.importNode(row, true)));
          const printHeader = printWindow.document.getElementById('print_official_header');
          const startPrint = () => window.setTimeout(() => { printWindow.focus(); printWindow.print(); }, 150);
          if (printHeader.complete) startPrint(); else { printHeader.onload = startPrint; printHeader.onerror = startPrint; }
      }

      document.querySelectorAll('[data-close-profile]').forEach(btn => btn.addEventListener('click', () => { if(profileModal) profileModal.classList.remove('show'); }));
      document.querySelectorAll('[data-close-success]').forEach(btn => btn.addEventListener('click', () => { if(succModal) succModal.classList.remove('show'); }));
      document.querySelectorAll('[data-close-error]').forEach(btn => btn.addEventListener('click', () => { if(errModal) errModal.classList.remove('show'); }));

      const bDateInput = document.getElementById('birthdateInput');
      const ageOutput = document.getElementById('ageOutput');
      if(bDateInput && ageOutput) {
          bDateInput.addEventListener('change', function() {
              if(!this.value) return;
              const dob = new Date(this.value);
              const today = new Date();
              let age = today.getFullYear() - dob.getFullYear();
              const m = today.getMonth() - dob.getMonth();
              if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
              ageOutput.value = age > 0 ? age : 0;
          });
      }
      
      const menuToggle = document.getElementById('menuToggle');
      const sideClose = document.getElementById('sideClose');
      const sideArea = document.getElementById('sideArea');
      const sidebarOverlay = document.getElementById('sidebarOverlay');

      function openSidebar() { sideArea.classList.add('open'); sidebarOverlay.classList.add('show'); }
      function closeSidebar() { sideArea.classList.remove('open'); sidebarOverlay.classList.remove('show'); }

      if (menuToggle) menuToggle.addEventListener('click', openSidebar);
      if (sideClose) sideClose.addEventListener('click', closeSidebar);
      if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);
      
      const searchInput = document.getElementById('liveSearchInput');
      if(searchInput) {
          let searchTimeout;
          const valLen = searchInput.value.length;
          if (valLen > 0) {
              searchInput.focus();
              searchInput.setSelectionRange(valLen, valLen);
          }
          searchInput.addEventListener('input', function() {
              clearTimeout(searchTimeout);
              searchTimeout = setTimeout(() => { document.getElementById('filterForm').submit(); }, 600); 
          });
      }
  });
</script>


<script>
(() => {
  const boxes = [...document.querySelectorAll('.beneficiary-select')];
  const selectAll = document.getElementById('selectAllBeneficiaries');
  const bar = document.getElementById('bulkSelectionBar');
  const count = document.getElementById('bulkSelectedCount');
  const modal = document.getElementById('bulkStatusActionModal');
  const inputs = document.getElementById('bulkSelectedInputs');
  const status = document.getElementById('bulkAvailmentStatus');
  const selected = () => boxes.filter(box => box.checked);
  const sync = () => {
    const chosen = selected();
    count.textContent = chosen.length;
    document.querySelectorAll('.bulk-modal-count').forEach(el => el.textContent = chosen.length);
    bar.hidden = chosen.length === 0;
    if (selectAll) {
      selectAll.checked = boxes.length > 0 && chosen.length === boxes.length;
      selectAll.indeterminate = chosen.length > 0 && chosen.length < boxes.length;
    }
    boxes.forEach(box => box.closest('tr')?.classList.toggle('is-selected', box.checked));
  };
  selectAll?.addEventListener('change', () => { boxes.forEach(box => box.checked = selectAll.checked); sync(); });
  boxes.forEach(box => box.addEventListener('change', sync));
  document.getElementById('clearBulkSelection')?.addEventListener('click', () => { boxes.forEach(box => box.checked = false); sync(); });
  document.getElementById('openBulkStatusModal')?.addEventListener('click', () => {
    const chosen = selected();
    if (!chosen.length) return;
    inputs.innerHTML = chosen.map(box => '<input type="hidden" name="beneficiary_ids[]" value="' + box.value + '">').join('');
    modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden';
  });
  const close = () => { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; };
  document.querySelectorAll('[data-close-bulk-status]').forEach(el => el.addEventListener('click', close));
  const toggleSchedule = () => {
    const needsDate = ['Orientation','Salary Distribution','Completed'].includes(status.value);
    const needsPlace = ['Orientation','Salary Distribution'].includes(status.value);
    document.querySelectorAll('.bulk-schedule-field').forEach((field, index) => {
      field.hidden = index === 0 ? !needsDate : !needsPlace;
      const control = field.querySelector('input'); if (control) control.required = index === 0 ? needsDate : needsPlace;
    });
    const messageField = document.querySelector('.bulk-message-field');
    const messageInput = messageField?.querySelector('textarea');
    const needsMessage = ['Not Qualified','Cancelled'].includes(status.value);
    if (messageField) messageField.hidden = !needsMessage;
    if (messageInput) { messageInput.required = needsMessage; if (!needsMessage) messageInput.value = ''; }
  };
  status?.addEventListener('change', toggleSchedule); toggleSchedule(); sync();
  document.getElementById('bulkStatusForm')?.addEventListener('submit', event => {
    if (!confirm('Update ' + selected().length + ' selected beneficiaries and send their email notifications?')) event.preventDefault();
  });

  if (window.matchMedia('(pointer: coarse)').matches) {
    let holdTimer = null, startX = 0, startY = 0;
    document.querySelectorAll('.clickable-row').forEach(row => {
      const box = row.querySelector('.beneficiary-select');
      if (!box) return;
      row.addEventListener('touchstart', event => {
        if (event.target.closest('button,a,input,label,select,textarea')) return;
        const touch = event.touches[0]; startX = touch.clientX; startY = touch.clientY;
        holdTimer = window.setTimeout(() => {
          box.checked = !box.checked;
          box.dispatchEvent(new Event('change', { bubbles: true }));
          row.dataset.longPressSelected = '1';
          navigator.vibrate?.(35);
        }, 550);
      }, { passive: true });
      row.addEventListener('touchmove', event => {
        const touch = event.touches[0];
        if (Math.abs(touch.clientX - startX) > 10 || Math.abs(touch.clientY - startY) > 10) {
          clearTimeout(holdTimer); holdTimer = null;
        }
      }, { passive: true });
      row.addEventListener('touchend', () => { clearTimeout(holdTimer); holdTimer = null; }, { passive: true });
      row.addEventListener('touchcancel', () => { clearTimeout(holdTimer); holdTimer = null; }, { passive: true });
    });
    document.addEventListener('click', event => {
      const row = event.target.closest('.clickable-row');
      if (!row || event.target.closest('button,a,input,label,select,textarea')) return;
      const box = row.querySelector('.beneficiary-select');
      if (row.dataset.longPressSelected === '1') {
        delete row.dataset.longPressSelected;
        event.preventDefault(); event.stopImmediatePropagation(); return;
      }
      if (selected().length > 0 && box) {
        box.checked = !box.checked;
        box.dispatchEvent(new Event('change', { bubbles: true }));
        event.preventDefault(); event.stopImmediatePropagation();
      }
    }, true);
  }
})();
</script>
<script src="spes_form_modal.js?v=20260813-profile-transition-fix"></script>
<script src="msme_form_modal.js?v=20260820y"></script>
</body>
</html>
