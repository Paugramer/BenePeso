<?php
require_once __DIR__ . '/auth.php';
auth_enable_csrf_form_injection();
require "db.php";
check_user_role('admin');
// Beneficiary records are view-only; workflow changes belong to Application.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); header('Allow: GET');
    exit('Beneficiary records are read-only. Use Application to update a beneficiary.');
}


require_once "beneficiary_choices.php";
require_once "email_helper.php";
require_once "program_eligibility_helper.php";
require_once "report_columns.php";
require_once "beneficiary_import_helper.php";
require_once "beneficiary_duplicate_helper.php";
require_once "spes_schema_helper.php";
require_once "spes_lifecycle_helper.php";
require_once "tupad_category_helper.php";
require_once "tupad_household_helper.php";
require_once "tupad_document_helper.php";
require_once "beneficiary_lifecycle_helper.php";
ensure_program_eligibility_schema($conn);
ensure_tupad_category_schema($conn);
ensure_tupad_document_schema($conn);
ensureSpesParentStatusCapacity($conn);

// Include functions to enable activity logging
if (file_exists("functions.php")) {
    require_once "functions.php";
}

if (!isset($conn) || !($conn instanceof mysqli)) {
  die("Database connection not found. Please check db.php");
}

$admin_id = (int) $_SESSION["admin_id"];

/* =========================
   HELPERS & NATIVE EXCEL PARSER
========================= */
function h($value){
  return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function beneficiary_program_icon(string $programName): string {
  if (stripos($programName, 'SPES') !== false) return 'ph-student';
  if (stripos($programName, 'MSME') !== false) return 'ph-storefront';
  return 'ph-hammer';
}

function table_exists(mysqli $conn, string $table): bool {
  $table = $conn->real_escape_string($table);
  $sql = "SHOW TABLES LIKE '$table'";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $sql = "SHOW COLUMNS FROM `$table` LIKE '$column'";
  $res = $conn->query($sql);
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
  if ($s === "exam passed") return "pill success";
  if ($s === "completed") return "pill neutral";
  if ($s === "cancelled" || $s === "exam failed") return "pill danger";
  if ($s === "requirements received" || $s === "requirements recieved") return "pill warning";
  if ($s === "not yet availed") return "pill warning";
  return "pill neutral";
}

function normalize_availment_status($status): string {
  $status = trim((string)$status);
  $map = [
    "not yet availed" => "Not Yet Availed",
    "requirements received" => "Requirements Received",
    "requirements recieved" => "Requirements Received",
    "ongoing" => "Ongoing",
    "orientation" => "Orientation",
    "examination" => "Examination",
    "exam passed" => "Exam Passed",
    "exam failed" => "Exam Failed",
    "salary distribution" => "Salary Distribution",
    "not qualified" => "Not Qualified",
    "completed" => "Completed",
    "cancelled" => "Cancelled",
  ];
  $key = strtolower($status);
  return $map[$key] ?? "Not Yet Availed";
}

function ensure_availment_status_schema(mysqli $conn): void {
  $res = $conn->query("SHOW COLUMNS FROM beneficiaries LIKE 'availment_status'");
  if (!$res || !($row = $res->fetch_assoc())) return;

  $type = strtolower((string)($row["Type"] ?? ""));
  if (strpos($type, "enum(") !== 0 || (strpos($type, "'requirements received'") !== false && strpos($type, "'orientation'") !== false && strpos($type, "'examination'") !== false && strpos($type, "'exam passed'") !== false && strpos($type, "'exam failed'") !== false && strpos($type, "'salary distribution'") !== false && strpos($type, "'not qualified'") !== false)) return;

  $null = strtoupper((string)($row["Null"] ?? "YES")) === "NO" ? "NOT NULL" : "NULL";
  $default = $row["Default"] !== null ? " DEFAULT '" . $conn->real_escape_string((string)$row["Default"]) . "'" : "";
  $conn->query("ALTER TABLE beneficiaries MODIFY availment_status ENUM('Not Yet Availed','Requirements Received','Orientation','Examination','Exam Passed','Exam Failed','Ongoing','Salary Distribution','Completed','Not Qualified','Cancelled') $null$default");
}

function availment_display_label(string $status): string {
  $normalized = normalize_availment_status($status);
  if ($normalized === 'Not Yet Availed') return 'Waiting for Requirements';
  if ($normalized === 'Requirements Received') return 'Documents Submitted';
  if ($normalized === 'Exam Failed') return 'Exam Not Passed';
  return $normalized;
}

function beneficiary_matches_bulk_scope(mysqli $conn, int $beneficiaryId, string $programName, int $programId): bool {
  if ($beneficiaryId <= 0 || $programName === '') return false;
  $sql = "SELECT 1 FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id WHERE b.beneficiary_id=? AND p.program_name=?";
  if ($programId > 0) $sql .= " AND b.program_id=?";
  $sql .= " LIMIT 1";
  $stmt = $conn->prepare($sql);
  if ($programId > 0) $stmt->bind_param('isi', $beneficiaryId, $programName, $programId);
  else $stmt->bind_param('is', $beneficiaryId, $programName);
  $stmt->execute();
  $matches = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  return $matches;
}

function ensure_application_source_schema(mysqli $conn): void {
  if (!column_exists($conn, 'beneficiaries', 'application_source')) {
    $conn->query("ALTER TABLE beneficiaries ADD COLUMN application_source VARCHAR(30) NULL AFTER created_by");
  }
}

function build_query(array $overrides = []): string {
  $query = array_merge($_GET, $overrides);
  foreach ($query as $k => $v) {
    if ($v === null || $v === "") {
      unset($query[$k]);
    }
  }
  return http_build_query($query);
}

function beneficiary_return_location(string $script, string $fallbackProgram = ''): string {
  $allowed = ['program_name', 'approval', 'spes_group', 'page', 'search', 'sort', 'program_id', 'tupad_category', 'barangay', 'availment', 'business_nature'];
  $query = [];
  parse_str((string)($_POST['return_query'] ?? ''), $submitted);
  foreach ($allowed as $key) {
    if (isset($submitted[$key]) && is_scalar($submitted[$key]) && trim((string)$submitted[$key]) !== '') $query[$key] = trim((string)$submitted[$key]);
  }
  if (!isset($query['program_name']) && $fallbackProgram !== '') $query['program_name'] = $fallbackProgram;
  return $script . ($query ? '?' . http_build_query($query) : '');
}

// Minimal native Excel (.xlsx) parser to fix garbled text uploads
function read_simple_xlsx(string $filepath): array {
    $rows = [];
    if (!class_exists('ZipArchive')) return [];
    $zip = new ZipArchive();
    if ($zip->open($filepath) === true) {
        $sharedStrings = [];
        if (($ssData = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $xml = simplexml_load_string($ssData);
            if ($xml && isset($xml->si)) {
                foreach ($xml->si as $val) {
                    if (isset($val->t)) {
                        $sharedStrings[] = (string)$val->t;
                    } elseif (isset($val->r)) {
                        $str = '';
                        foreach ($val->r as $r) { $str .= (string)$r->t; }
                        $sharedStrings[] = $str;
                    }
                }
            }
        }
        if (($sheetData = $zip->getFromName('xl/worksheets/sheet1.xml')) !== false) {
            $xml = simplexml_load_string($sheetData);
            if ($xml && isset($xml->sheetData->row)) {
                foreach ($xml->sheetData->row as $row) {
                    $rowData = [];
                    $colIndex = 0;
                    foreach ($row->c as $c) {
                        $r = (string)$c['r'];
                        preg_match('/([A-Z]+)/', $r, $matches);
                        if (isset($matches[1])) {
                            $colLetter = $matches[1];
                            $colNum = 0;
                            $length = strlen($colLetter);
                            for ($i = 0; $i < $length; $i++) {
                                $colNum = $colNum * 26 + (ord($colLetter[$i]) - 64);
                            }
                            $colNum--;
                            while ($colIndex < $colNum) {
                                $rowData[] = '';
                                $colIndex++;
                            }
                        }
                        $val = (string)$c->v;
                        if (isset($c['t']) && (string)$c['t'] == 's') {
                            $val = $sharedStrings[(int)$val] ?? '';
                        }
                        $rowData[] = $val;
                        $colIndex++;
                    }
                    $rows[] = $rowData;
                }
            }
        }
        $zip->close();
    }
    return $rows;
}

ensure_availment_status_schema($conn);
ensure_application_source_schema($conn);

/* =========================
   ADMIN INFO
========================= */
$admin_name = "PESO Vinzons";
$admin_pic = "default_avatar.png";

if (table_exists($conn, "admins")) {
  $stmt = $conn->prepare("SELECT * FROM admins WHERE admin_id = ? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
      $admin_pic = !empty($row["profile_picture"]) ? $row["profile_picture"] : "default_avatar.png";
    }
    $stmt->close();
  }
}

$pic_path = "uploads/admin_pics/" . $admin_pic;
if (!file_exists($pic_path) || empty($admin_pic)) {
    $pic_path = "img/default_user.svg";
}
$initial = strtoupper(substr(trim($admin_name), 0, 1));

/* =========================
   FLASH / SUCCESS / ERROR MODALS
========================= */
$flash = $_SESSION["flash"] ?? "";
$flash_type = $_SESSION["flash_type"] ?? "success";

$showSuccessModal = $_SESSION["show_success_modal"] ?? false;
$successModalMessage = $_SESSION["success_modal_message"] ?? "";
$importSummary = $_SESSION["import_summary"] ?? null;
$modalIcon = $_SESSION["modal_icon"] ?? "✓";

$showErrorModal = $_SESSION["show_error_modal"] ?? false;
$errorModalMessage = $_SESSION["error_modal_message"] ?? "";

unset(
  $_SESSION["flash"],
  $_SESSION["flash_type"],
  $_SESSION["show_success_modal"],
  $_SESSION["success_modal_message"],
  $_SESSION["import_summary"],
  $_SESSION["modal_icon"],
  $_SESSION["show_error_modal"],
  $_SESSION["error_modal_message"]
);

/* =========================
   REAL BARANGAYS
========================= */
$barangays = beneficiary_barangay_options();

/* =========================
   POST ACTIONS (ADMIN POWERS)
========================= */
/* =========================
   VIEW MODE & FILTERS
========================= */
$selectedProgramName = isset($_GET["program_name"]) ? trim($_GET["program_name"]) : "";
$reportProgramTitle = getReportProgramTitle($selectedProgramName);
$selectedProgramId = isset($_GET["program_id"]) ? (int)$_GET["program_id"] : 0;
$selectedTupadCategory = trim($_GET["tupad_category"] ?? "All");
$activeTupadCategories = active_tupad_categories($conn);
if ($selectedTupadCategory !== 'All' && !in_array($selectedTupadCategory, $activeTupadCategories, true)) {
    $selectedTupadCategory = 'All';
}
$selectedBarangay = trim($_GET["barangay"] ?? "All");
$search = trim($_GET["search"] ?? "");
$sort = trim($_GET["sort"] ?? "newest");
$approvalFilter = trim($_GET["approval"] ?? "All");
$availmentFilter = trim($_GET["availment"] ?? "All");
$spesGroup = trim($_GET['spes_group'] ?? 'all');
if (!in_array($spesGroup, ['all', 'baby', 'graduate'], true) || stripos($selectedProgramName, 'SPES') === false) $spesGroup = 'all';
if (stripos($selectedProgramName, 'SPES') === false && in_array($availmentFilter, ['Examination', 'Exam Passed', 'Exam Failed'], true)) $availmentFilter = 'All';
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
        $businessNatureMap = [];
        while($bnRow = $bnRes->fetch_assoc()) {
            foreach (preg_split('/[,;\r\n]+/', (string)$bnRow['business_nature']) as $nature) {
                $nature = trim($nature);
                if ($nature === '') continue;
                $key = strtolower((string)preg_replace('/\s+/', ' ', $nature));
                if (!isset($businessNatureMap[$key])) $businessNatureMap[$key] = $nature;
            }
        }
        $businessNatures = array_values($businessNatureMap);
        natcasesort($businessNatures);
        $businessNatures = array_values($businessNatures);
        $bnStmt->close();
    }
}

$programs = [];
$sqlPrograms = "SELECT c.program_name, c.image_path, COALESCE(t.beneficiary_count, 0) AS beneficiary_count
  FROM program_categories c
  LEFT JOIN (
    SELECT p.program_name, COUNT(b.beneficiary_id) AS beneficiary_count
    FROM programs p
    LEFT JOIN beneficiaries b ON b.program_id = p.program_id
    GROUP BY p.program_name
  ) t ON t.program_name = c.program_name
  ORDER BY c.program_name ASC";
$resPrograms = $conn->query($sqlPrograms);
if ($resPrograms) { while ($row = $resPrograms->fetch_assoc()) { $programs[] = $row; } }

$globalTotalBeneficiaries = 0; $globalApprovedBeneficiaries = 0; $globalPendingBeneficiaries = 0; $globalOngoingAvailments = 0;
$res = $conn->query("SELECT COUNT(*) AS total,
  SUM(approval_status = 'Approved') AS approved_total,
  SUM(approval_status = 'Pending') AS pending_total,
  SUM(availment_status = 'Ongoing') AS ongoing_total
  FROM beneficiaries");
if ($res && ($row = $res->fetch_assoc())) {
  $globalTotalBeneficiaries = (int)($row['total'] ?? 0);
  $globalApprovedBeneficiaries = (int)($row['approved_total'] ?? 0);
  $globalPendingBeneficiaries = (int)($row['pending_total'] ?? 0);
  $globalOngoingAvailments = (int)($row['ongoing_total'] ?? 0);
}

$beneficiaries = [];
$batches = [];
$totalRecords = 0;
$totalPages = 1;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 5;
$offset = ($page - 1) * $limit;

if ($selectedProgramName !== "") {
  $isSpesProgram = stripos($selectedProgramName, 'SPES') !== false;

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
  if ($spesGroup !== 'all') $whereParts[] = spes_group_condition_sql($spesGroup, 'b');

  if (stripos($selectedProgramName, 'MSME') !== false && $selectedNature !== "All") {
      $whereParts[] = "FIND_IN_SET(?, REPLACE(REPLACE(REPLACE(b.business_nature, ';', ','), ', ', ','), ' ,', ',')) > 0";
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
  // The approval queue is FIFO; all other tabs show the latest record first.
  $orderBy = $approvalFilter === 'Pending'
      ? "b.created_at ASC, b.beneficiary_id ASC"
      : "b.created_at DESC, b.beneficiary_id DESC";

  $sqlList = "SELECT b.*, p.program_code, u.profile_pic AS user_profile_pic,
      CASE WHEN b.user_id IS NOT NULL THEN 'Online Applicant'
           WHEN b.application_source = 'Admin' THEN 'Administrator'
           WHEN b.application_source = 'PESO Staff' THEN COALESCE(NULLIF(TRIM(CONCAT(s.first_name, ' ', s.last_name)), ''), 'PESO Staff')
           WHEN b.application_source IS NULL AND b.created_by IS NOT NULL AND s.staff_id IS NOT NULL
             THEN COALESCE(NULLIF(TRIM(CONCAT(s.first_name, ' ', s.last_name)), ''), 'PESO Staff')
           ELSE 'Legacy Record' END AS added_by_name
      FROM beneficiaries b JOIN programs p ON b.program_id = p.program_id
      LEFT JOIN peso_staff s ON b.created_by = s.staff_id
      LEFT JOIN users u ON b.user_id = u.user_id
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
    while ($row = $res->fetch_assoc()) {
        if ($isSpesProgram) {
            $row = array_merge($row, spes_beneficiary_lifecycle_details($conn, (int)$row['beneficiary_id']));
        }
        $beneficiaries[] = $row;
    }
    $stmt->close();
  }

  // Report-only records are fetched on demand so the directory can render quickly.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="img/pesologo.png">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BENEPESO | Admin Beneficiaries</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/@phosphor-icons/web" defer></script>
  <link rel="stylesheet" href="admin_beneficiaries.css?v=20260924-mobile-performance">
  <link rel="stylesheet" href="shared_sidebar.css">
  <style>
      /* Temporary Inline Styles to enforce the A4 Preview Look */
      .spreadsheet-table { border-collapse: collapse; width: max-content; min-width: 100%; table-layout: auto; }
      .spreadsheet-table th, .spreadsheet-table td { border: 1px solid #ccc; padding: 6px 3px; font-size: 9px; white-space: normal; overflow-wrap: anywhere; }
      .spreadsheet-table thead th { background: #e6f4ed; color: #0d2618; position: sticky; top: 0; z-index: 10; font-weight: 700;}
  </style>
<link rel="stylesheet" href="frontend_polish.css?v=20260929">
<link rel="stylesheet" href="admin_responsive.css?v=23">
<link rel="stylesheet" href="system_search_polish.css?v=1">
<link rel="stylesheet" href="beneficiary_workspace_polish.css?v=3">
  <link rel="stylesheet" href="system_mobile.css?v=24">
<link rel="stylesheet" href="system_readability.css?v=1">
<script src="frontend_polish.js?v=20261014-restored" defer></script>
<script src="beneficiary_workspace_polish.js?v=2" defer></script>
</head>
<body class="admin-mobile-page admin-beneficiaries-page">
  <div class="page-wrap">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <aside class="side-area" id="sideArea">
    <div class="side-top">
      <div class="side-brand">
        <img src="img/pesologo.png" alt="PESO Logo" class="side-logo">
        <div>
          <div class="side-title">BENEPESO</div>
          <div class="side-sub">Admin Panel</div>
        </div>
      </div>
      <button class="side-close" id="sideClose" type="button" aria-label="Close menu">
        <span class="side-close-glyph" aria-hidden="true">&#215;</span>
      </button>
    </div>

    <div class="side-user">
      <div class="user-pic-wrap">
        <img src="<?php echo h($pic_path); ?>" alt="Admin" class="user-img-side" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($admin_name); ?>&background=1f7a54&color=fff';">
      </div>
      <div>
        <div class="user-name"><?php echo h($admin_name); ?></div>
        <div class="user-role">Administrator</div>
      </div>
    </div>

    <nav class="nav-area">
      <a href="admin_dashboard.php" class="nav-item"><i class="ph-fill ph-squares-four"></i> Dashboard</a>
      <a href="admin_program.php" class="nav-item"><i class="ph-fill ph-briefcase"></i> Programs</a>
      <a href="admin_beneficiaries.php" class="nav-item active"><i class="ph-fill ph-users"></i> Beneficiaries</a>
      <a href="admin_accounts.php" class="nav-item"><i class="ph-fill ph-user-circle-gear"></i> Manage Accounts</a>
      <a href="admin_activity_log.php" class="nav-item"><i class="ph-fill ph-clock-counter-clockwise"></i> System Logs</a>
      <form method="POST" action="logout.php" class="sidebar-logout-form"><?php echo auth_csrf_input(); ?><input type="hidden" name="role" value="admin"><button type="submit" class="nav-item logout-item"><i class="ph-bold ph-sign-out"></i> Logout</button></form>
    </nav>
  </aside>

  <main class="main-area">
    <header class="top-area animate-fade-in">
      <div class="top-left">
        <button class="menu-toggle" id="menuToggle" type="button" aria-label="Open menu" aria-expanded="false"><span></span><span></span><span></span></button>
        <?php if ($selectedProgramName !== ""): ?>
            <nav class="breadcrumb">
                <a href="admin_beneficiaries.php"><i class="ph-fill ph-users" style="font-size: 1rem; margin-right: 6px;"></i> Beneficiaries</a>
                <span class="separator">/</span><span class="current"><?php echo h($selectedProgramName); ?></span>
            </nav>
            <div class="top-big"><?php echo h($selectedProgramName); ?> Records</div>
            <div class="top-sub">Review and add beneficiaries under <?php echo h($selectedProgramName); ?>.</div>
        <?php else: ?>
            <div class="eyebrow">Beneficiary Management</div>
            <div class="top-big">Admin Beneficiaries</div>
            <div class="top-sub">Select a program to view and manage beneficiaries.</div>
        <?php endif; ?>
      </div>

      <div class="top-actions">
        <?php if ($selectedProgramName !== ""): ?>
        <?php endif; ?>
        <div class="top-chip">
            <img src="<?php echo h($pic_path ?? ''); ?>" alt="" class="chip-img" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($admin_name); ?>&background=1f7a54&color=fff';">
            <?php echo h($admin_name); ?>
        </div>
      </div>
    </header>

    <?php if ($selectedProgramName === ""): ?>
      <section class="stats-grid animate-fade-in" style="animation-delay: 0.1s;">
        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-label">BENEFICIARIES</div>
                <div class="stat-icon-sm" style="color: var(--green); background: var(--green-light);"><i class="ph-fill ph-users-three"></i></div>
            </div>
            <div class="stat-value"><?php echo (int)$globalTotalBeneficiaries; ?></div>
            <div class="stat-trend trend-up"><i class="ph-bold ph-users-three"></i> All records</div>
            <div class="stat-note">Beneficiary records in BENEPESO.</div>
        </div>
        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-label">APPROVED APPLICATIONS</div>
                <div class="stat-icon-sm" style="color:#1a6d41; background:#e6f6ec;"><i class="ph-fill ph-check-circle"></i></div>
            </div>
            <div class="stat-value"><?php echo (int)$globalApprovedBeneficiaries; ?></div>
            <div class="stat-trend trend-up"><i class="ph-bold ph-check"></i> Accepted</div>
            <div class="stat-note">Applications accepted by PESO.</div>
        </div>
        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-label">PENDING REVIEW</div>
                <div class="stat-icon-sm" style="color:#b06900; background:#fff2e0;"><i class="ph-fill ph-clock-countdown"></i></div>
            </div>
            <div class="stat-value"><?php echo (int)$globalPendingBeneficiaries; ?></div>
            <div class="stat-trend trend-warning"><i class="ph-bold ph-clock"></i> For review</div>
            <div class="stat-note">Applications waiting for admin review.</div>
        </div>
        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-label">ACTIVE BENEFICIARIES</div>
                <div class="stat-icon-sm" style="color:#2a5a8a; background:#e8eff4;"><i class="ph-fill ph-play-circle"></i></div>
            </div>
            <div class="stat-value"><?php echo (int)$globalOngoingAvailments; ?></div>
            <div class="stat-trend trend-neutral"><i class="ph-bold ph-play"></i> In progress</div>
            <div class="stat-note">Beneficiaries currently receiving program support.</div>
        </div>
      </section>

      <section class="panel-card animate-fade-in" style="animation-delay: 0.2s; margin-top: 8px;">
        <div class="panel-head-flex"><div><div class="panel-title">Programs Overview</div><div class="panel-sub">Select a program below to review applications and beneficiaries.</div></div></div>
        <div class="program-grid">
          <?php foreach ($programs as $program): ?>
            <article class="program-card-shell">
              <div class="program-image-wrap"><img src="<?php echo !empty($program["image_path"]) ? h($program["image_path"]) : "img/pesobgs.jpg"; ?>" class="program-image" alt="<?php echo h($program["program_name"]); ?>"><span class="beneficiary-program-icon" aria-hidden="true"><i class="ph <?php echo beneficiary_program_icon($program["program_name"]); ?>"></i></span></div>
              <div class="program-body">
                <h3 class="program-title"><?php echo h($program["program_name"]); ?></h3>

                <div class="styled-record-counter">
                    <i class="ph-fill ph-users"></i>
                    <span><strong><?php echo h($program["beneficiary_count"]); ?></strong> Total Records</span>
                </div>

                <div class="card-actions">
                    <a href="admin_beneficiaries.php?program_name=<?php echo urlencode($program["program_name"]); ?>" class="btn-directory">
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
                <a href="admin_beneficiaries.php?<?php echo h(build_query(['approval' => 'All', 'availment' => 'All', 'spes_group' => 'all', 'page' => 1])); ?>" class="tab-item <?php echo $approvalFilter === 'All' && $spesGroup === 'all' ? 'active' : ''; ?>">All Records</a>
                <a href="admin_beneficiaries.php?<?php echo h(build_query(['approval' => 'Pending', 'availment' => 'All', 'spes_group' => 'all', 'page' => 1])); ?>" class="tab-item <?php echo $approvalFilter === 'Pending' && $spesGroup === 'all' ? 'active' : ''; ?>">Needs Approval</a>
                <a href="admin_beneficiaries.php?<?php echo h(build_query(['approval' => 'Approved', 'availment' => 'All', 'spes_group' => 'all', 'page' => 1])); ?>" class="tab-item <?php echo $approvalFilter === 'Approved' && $spesGroup === 'all' ? 'active' : ''; ?>">Accepted</a>
                <a href="admin_beneficiaries.php?<?php echo h(build_query(['approval' => 'Rejected', 'availment' => 'All', 'spes_group' => 'all', 'page' => 1])); ?>" class="tab-item <?php echo $approvalFilter === 'Rejected' && $spesGroup === 'all' ? 'active' : ''; ?>">Rejected</a>
                <?php if (stripos($selectedProgramName, 'SPES') !== false): ?>
                <a href="admin_beneficiaries.php?<?php echo h(build_query(['approval' => 'All', 'availment' => 'All', 'spes_group' => 'baby', 'page' => 1])); ?>" class="tab-item <?php echo $spesGroup === 'baby' ? 'active' : ''; ?>">SPES Baby</a>
                <a href="admin_beneficiaries.php?<?php echo h(build_query(['approval' => 'All', 'availment' => 'All', 'spes_group' => 'graduate', 'page' => 1])); ?>" class="tab-item <?php echo $spesGroup === 'graduate' ? 'active' : ''; ?>">Graduated</a>
                <?php endif; ?>
            </div>

            <button class="btn-main" type="button" id="openReportModal" style="margin-bottom: 12px;">
                <i class="ph-bold ph-file-text" style="font-size: 1.1rem; margin-right: 6px;"></i> Generate Report
            </button>
        </div>

        <form method="GET" class="filter-container records-filter-container" id="filterForm">
            <input type="hidden" name="program_name" value="<?php echo h($selectedProgramName); ?>">
            <input type="hidden" name="approval" value="<?php echo h($approvalFilter); ?>">
            <input type="hidden" name="spes_group" value="<?php echo h($spesGroup); ?>">
            <input type="hidden" name="page" value="1">

            <div class="filter-search system-search">
              <i class="ph-bold ph-magnifying-glass search-icon"></i>
              <input type="search" name="search" value="<?php echo h($search); ?>" placeholder="Search by name or email..." class="search-input" id="liveSearchInput" autocomplete="off" autocapitalize="none" spellcheck="false" aria-label="Search beneficiaries">
            </div>
            <button type="button" class="beneficiary-filter-toggle" aria-expanded="false" aria-controls="beneficiarySecondaryFilters">
              <i class="ph ph-faders-horizontal"></i>
              <span>More filters</span>
              <span class="beneficiary-filter-count" hidden>0</span>
              <i class="ph ph-caret-down beneficiary-filter-caret"></i>
            </button>
            <div class="beneficiary-secondary-filters" id="beneficiarySecondaryFilters">
            <select name="program_id" class="filter-select auto-submit">
                <option value="0">All Batches</option>
                <?php foreach($batches as $b): ?><option value="<?php echo $b['program_id']; ?>" <?php echo $selectedProgramId == $b['program_id'] ? 'selected' : ''; ?>><?php echo h($b['program_code']); ?></option><?php endforeach; ?>
            </select>
            <?php if (stripos($selectedProgramName, 'TUPAD') !== false): ?>
            <select name="tupad_category" class="filter-select auto-submit" aria-label="Filter beneficiaries by TUPAD category">
              <option value="All">All TUPAD Categories</option>
              <?php foreach ($activeTupadCategories as $category): ?><option value="<?php echo h($category); ?>" <?php echo $selectedTupadCategory === $category ? 'selected' : ''; ?>><?php echo h($category); ?></option><?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="barangay" class="filter-select auto-submit">
              <option value="All">All Barangays</option>
              <?php foreach ($barangays as $barangay): ?><option value="<?php echo h($barangay); ?>" <?php echo $selectedBarangay === $barangay ? 'selected' : ''; ?>><?php echo h($barangay); ?></option><?php endforeach; ?>
            </select>
            <select name="availment" class="filter-select auto-submit">
              <option value="All" <?php echo $availmentFilter === 'All' ? 'selected' : ''; ?>>All Statuses</option>
              <option value="Not Yet Availed" <?php echo $availmentFilter === 'Not Yet Availed' ? 'selected' : ''; ?>>Waiting for Requirements</option>
              <option value="Requirements Received" <?php echo $availmentFilter === 'Requirements Received' ? 'selected' : ''; ?>>Requirements Received</option>
              <option value="Orientation" <?php echo $availmentFilter === 'Orientation' ? 'selected' : ''; ?>>Orientation</option>
              <option value="Examination" <?php echo $availmentFilter === 'Examination' ? 'selected' : ''; ?>>Examination (Face-to-Face)</option>
              <option value="Exam Passed" <?php echo $availmentFilter === 'Exam Passed' ? 'selected' : ''; ?>>Exam Passed</option>
              <option value="Exam Failed" <?php echo $availmentFilter === 'Exam Failed' ? 'selected' : ''; ?>>Exam Not Passed</option>
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
            </div>
        </form>

        <?php if ($beneficiaries): ?>

        <?php endif; ?>

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
                           <th style="width: 62px; text-align: center;">No.</th>
                          <th>Profile</th>
                          <th>Barangay</th>
                          <th>Batch Code</th>
                          <th style="text-align: center;">Status</th>
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
                          $availmentDisplay = $beneficiaryApproval === 'Pending'
                              ? 'Pending Review'
                              : ($beneficiaryApproval === 'Rejected' ? 'Application Not Accepted' : availment_display_label($availmentStatus));
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
                           $profileFilename = basename((string)($beneficiary['user_profile_pic'] ?? ''));
                           $profileImage = $profileFilename !== '' && $profileFilename !== 'default_user.png' && is_file('uploads/' . $profileFilename) ? 'uploads/' . $profileFilename : '';
                           $bData['profile_image'] = $profileImage;

                          $profileData = json_encode($bData, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_INVALID_UTF8_SUBSTITUTE);
                      ?>
                      <tr class="table-row-animate clickable-row" data-profile='<?php echo h($profileData); ?>' onclick="window.openProfileModal(this)">
                          <td style="text-align: center; font-weight: 700; color: var(--muted); font-size: 13px;" onclick="event.stopPropagation();"><?php echo $counter++; ?></td>
                          <td>
                              <div style="display: flex; align-items: center; gap: 12px;">
                                  <div class="avatar-circle beneficiary-table-avatar" style="width: 40px; height: 40px; font-size: 15px;">
                                      <span><?php echo h($initial); ?></span>
                                      <?php if ($profileImage !== ''): ?>
                                          <img src="<?php echo h($profileImage); ?>" alt="<?php echo h($dispName); ?> profile photo" loading="lazy" onerror="this.remove()">
                                      <?php endif; ?>
                                  </div>
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
                                  <span class="pill-dot" style="background:currentColor;"></span> <?php echo h($availmentDisplay); ?>
                              </span>
                          </td>
                          <td style="text-align:center" onclick="event.stopPropagation();"><div class="action-menu-wrap" style="justify-content:center"><button class="btn-edit" type="button" onclick="window.openProfileModal(this.closest('tr'))"><i class="ph ph-eye"></i> View Record</button></div></td>
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

<div class="modal" id="generateReportModal" aria-hidden="true">
  <div class="modal-backdrop" data-close-report></div>
  <div class="modal-dialog split-layout" style="position: relative; overflow: hidden !important;">

    <button type="button" class="modal-close-icon report-close-button" data-close-report aria-label="Close generate report"><i class="ph-bold ph-x"></i></button>

    <div class="report-controls" id="reportFiltersPanel">
        <div class="report-controls-heading">
            <div class="report-title-row"><span class="report-title-icon" aria-hidden="true"><i class="ph-bold ph-file-text"></i></span><div><div class="modal-title">Generate Report</div><div class="report-program-label"><?php echo h($selectedProgramName); ?> beneficiary records</div></div></div>
            <div class="modal-sub">Choose filters and columns, then print the preview or export an editable workbook.</div>
        </div>

        <form method="GET" action="export_report.php" target="_blank" class="report-form"><input type="hidden" name="report_scope" value="original_beneficiaries">
            <input type="hidden" name="program_name" value="<?php echo h($selectedProgramName); ?>">

            <div class="report-filter-list" style="flex: 1; overflow-y: auto; padding-right: 8px; display: flex; flex-direction: column; gap: 16px; margin-bottom: 16px;">
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
                    <label>Status Filter</label>
                    <select name="report_availment" id="report_avail_select">
                        <option value="All">All Records</option>
                        <option value="Not Yet Availed">New (Not Availed)</option>
                        <option value="Requirements Received">Requirements Received</option>
                        <option value="Orientation">Orientation</option>
                        <option value="Examination">Examination (Face-to-Face)</option>
                        <option value="Exam Passed">Exam Passed</option>
                        <option value="Exam Failed">Exam Not Passed</option>
                        <option value="Ongoing">Ongoing</option>
                        <option value="Salary Distribution">Salary Distribution</option>
                        <option value="Completed">Old / Completed</option>
                        <option value="Not Qualified">Not Qualified</option>
                    </select>
                </div>
                <?php if (stripos($selectedProgramName, 'SPES') !== false): ?>
                <div class="form-group" style="margin:0;"><label>SPES Classification Filter</label><select name="report_spes_group" id="report_spes_group_select"><option value="all">All SPES Records</option><option value="baby">SPES Baby</option><option value="graduate">SPES Graduate</option></select></div>
                <?php endif; ?>

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
                    <input type="hidden" name="export_format" value="xlsx">
                    <div class="report-format-card"><span class="report-format-icon" aria-hidden="true"><i class="ph-bold ph-file-xls"></i></span><span><strong>Excel Workbook</strong><small>Editable .xlsx data file</small></span><i class="ph-bold ph-check-circle report-format-check" aria-hidden="true"></i></div>
                </div>
            </div>

            <div class="report-actions">
                <button type="button" class="report-action-button report-action-print" id="report_print_button"><i class="ph-bold ph-printer"></i> Print</button>
                <button type="submit" class="report-action-button report-action-export"><i class="ph-bold ph-download-simple"></i> Export Data</button>
            </div>
        </form>
    </div>

    <div class="report-preview">
        <button type="button" class="report-filter-toggle" aria-controls="reportFiltersPanel" aria-expanded="false"><i class="ph-bold ph-funnel"></i><span>Filters</span></button>
        <div class="spreadsheet-container">
            <div class="preview-header">
                <h2><?php echo h($reportProgramTitle); ?></h2>
                <p id="preview_subtitle_top">Municipality of Vinzons</p>
            </div>
            <div class="report-view-toolbar" aria-label="Report preview controls">
                <span class="report-view-label"><i class="ph-bold ph-magnifying-glass"></i><span>Preview size</span></span>
                <div class="report-zoom-controls">
                    <button type="button" data-report-zoom="out" aria-label="Zoom report out" title="Zoom out"><i class="ph-bold ph-minus"></i></button>
                    <output class="report-zoom-value" aria-live="polite">100%</output>
                    <button type="button" data-report-zoom="in" aria-label="Zoom report in" title="Zoom in"><i class="ph-bold ph-plus"></i></button>
                    <button type="button" class="report-fit-button" data-report-zoom="fit"><i class="ph-bold ph-arrows-in-line-horizontal"></i><span>Fit width</span></button>
                </div>
            </div>
            <div class="report-column-pagination" aria-label="Report column navigation">
                <button type="button" data-report-column-page="previous" aria-label="Previous report columns" title="Previous columns"><i class="ph-bold ph-caret-left"></i> Previous columns</button>
                <span class="report-column-page-status">Columns 1–8</span>
                <button type="button" data-report-column-page="next" aria-label="Next report columns" title="Next columns">Next columns <i class="ph-bold ph-caret-right"></i></button>
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
    <button type="button" class="profile-close-button" data-close-profile aria-label="Close beneficiary profile" style="position: absolute; top: 16px; right: 16px; background: #f9fafb; border: 1px solid #eaecf0; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #667085;"><i class="ph-bold ph-x"></i></button>

    <div class="id-card-left" style="width: 320px; background: linear-gradient(135deg, #e8f5e9 0%, #ffffff 100%); position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 20px; border-right: 1px solid #eaecf0;">
       <div class="id-card-pattern" style="position: absolute; top:0; left:0; right:0; bottom:0; opacity: 0.1; background-image: radial-gradient(#1f7a54 1px, transparent 1px); background-size: 10px 10px; z-index: 1;"></div>
       <div class="profile-identity-row" style="z-index: 2; display: flex; flex-direction: column; align-items: center;">
           <div class="id-avatar-large" id="pm_avatar" style="width: 100px; height: 100px; background: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 40px; font-weight: 800; color: #0d2618; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 24px;">?</div>

           <div class="profile-badges-row" style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
               <div class="id-badge badge-user-bg" id="pm_program_header" style="background: #1f7a54; color: #fff; padding: 6px 16px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;">PROGRAM NAME</div>
               <div id="pm_availment_badge"></div>
           </div>
       </div>
    </div>

    <div class="id-card-right" style="flex: 1; padding: 32px; background: #fff; position: relative;">
       <div class="id-card-right-inner">
           <div class="id-header" style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px; border-bottom: 2px solid #1f7a54; padding-bottom: 12px; width: fit-content; min-width: 250px;">
              <div style="width: 36px; height: 36px; background: #1f7a54; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff;"><i class="ph-fill ph-user" style="font-size: 18px;"></i></div>
              <div class="profile-name-stack"><span id="pm_spes_lifecycle" class="spes-lifecycle-ribbon" hidden></span><h3 class="id-name" id="pm_name" style="margin: 0; font-size: 20px; font-weight: 700; color: #0d2618;">Name</h3></div>
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

       <div class="id-actions" style="display: flex; justify-content: space-between; align-items: center; margin-top: 24px; padding-top: 16px; border-top: 1px solid #eaecf0; padding-left: 32px; padding-right: 32px;">
          <div class="added-by" style="font-size: 11px; color: #667085;">ADDED BY: <span id="pm_added_by" style="color: #1f7a54; font-weight:800; text-transform:uppercase;"></span></div>
          <div id="pm_footer_actions" style="display:flex; gap:10px; align-items: center;"></div>
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
          <span>Status emails scheduled</span>
        </div>
      </div>
      <div class="import-summary-note">
        <span><i class="ph ph-info"></i> New records were accepted. Saved records use the status <strong><?php echo h($importSummary['status']); ?></strong>.</span>
        <?php if ((int)$importSummary['emails_failed'] > 0): ?>
          <span class="import-summary-warning"><?php echo number_format((int)$importSummary['emails_failed']); ?> email notification(s) could not be scheduled.</span>
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

<div class="modal" id="beneficiaryNoticeModal" aria-hidden="true">
  <div class="modal-backdrop" data-close-beneficiary-notice></div>
  <div class="success-dialog" role="dialog" aria-modal="true" aria-labelledby="beneficiaryNoticeTitle">
    <div class="warning-icon" style="background:#eef7f2;color:#1f7a54;"><i class="ph-bold ph-info"></i></div>
    <div class="success-title" id="beneficiaryNoticeTitle">Notice</div>
    <div class="modal-text" id="beneficiaryNoticeMessage"></div>
    <div class="modal-actions-flex" style="margin-top:24px;">
      <button type="button" class="btn-light" id="beneficiaryNoticeCancel" data-close-beneficiary-notice>Close</button>
      <button type="button" class="btn-main" id="beneficiaryNoticeConfirm" hidden>Continue</button>
    </div>
  </div>
</div>

<?php endif; ?>

<script>
    let allBeneficiariesData = [];
    const currentProgramName = <?php echo json_encode($selectedProgramName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const currentReportTitle = <?php echo json_encode($reportProgramTitle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    let reportDataPromise = null;

    function loadReportData() {
      if (reportDataPromise) return reportDataPromise;
      const url = `beneficiary_report_data.php?report_scope=original_beneficiaries&program_name=${encodeURIComponent(currentProgramName)}`;
      reportDataPromise = fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(response => response.json().then(payload => ({ response, payload })))
        .then(({ response, payload }) => {
          if (!response.ok || !payload.ok || !Array.isArray(payload.records)) {
            throw new Error(payload.message || 'Report records could not be loaded.');
          }
          allBeneficiariesData = payload.records;
          return allBeneficiariesData;
        })
        .catch(error => {
          reportDataPromise = null;
          throw error;
        });
      return reportDataPromise;
    }

    let previewData = [];
    let currentPreviewPage = 1;
    const previewItemsPerPage = 10;

    function showBeneficiaryNotice(title, message, onConfirm = null) {
      const modal = document.getElementById('beneficiaryNoticeModal');
      document.getElementById('beneficiaryNoticeTitle').textContent = title;
      document.getElementById('beneficiaryNoticeMessage').textContent = message;
      const confirmButton = document.getElementById('beneficiaryNoticeConfirm');
      const cancelButton = document.getElementById('beneficiaryNoticeCancel');
      confirmButton.hidden = typeof onConfirm !== 'function';
      cancelButton.textContent = onConfirm ? 'Cancel' : 'Okay';
      confirmButton.onclick = onConfirm ? () => { closeBeneficiaryNotice(); onConfirm(); } : null;
      modal.classList.add('show');
      modal.setAttribute('aria-hidden', 'false');
      (onConfirm ? confirmButton : cancelButton).focus();
    }
    function closeBeneficiaryNotice() {
      const modal = document.getElementById('beneficiaryNoticeModal');
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden', 'true');
    }
    document.querySelectorAll('[data-close-beneficiary-notice]').forEach(button => button.addEventListener('click', closeBeneficiaryNotice));
</script>

<script>
  function sanitizeNumericValue(value, mode = 'integer') {
      if (mode === 'decimal') {
          const cleaned = value.replace(/[^0-9.]/g, '');
          const parts = cleaned.split('.');
          return parts.length > 1 ? parts[0] + '.' + parts.slice(1).join('') : cleaned;
      }
      return value.replace(/[^0-9]/g, '');
  }

  function getNumericMode(input) {
      if (input.dataset.textInput === 'true') return null;
      const inputType = (input.type || '').toLowerCase();
      if (['hidden', 'checkbox', 'radio', 'file', 'date', 'email'].includes(inputType)) return null;

      const label = input.closest('.form-group')?.querySelector('label')?.textContent || '';
      const key = `${input.name || ''} ${input.id || ''} ${input.placeholder || ''} ${label}`.toLowerCase();
      if (/(capital|earning|income|price|amount|sales|revenue|expense|cost|peso|salary|wage)/.test(key)) {
          return 'decimal';
      }
      if (/(contact|phone|mobile|tel|age|year|attendance|worker|employee|count|regular|seasonal|contractual|family|male|female|total|stall no|stall number)/.test(key)) {
          return 'integer';
      }
      return null;
  }

  function applyNumericInputGuards(root = document) {
      const guardedInputs = root.querySelectorAll('input');
      guardedInputs.forEach(input => {
          const mode = getNumericMode(input);
          if (!mode || input.dataset.numericGuard === 'true') return;
          input.dataset.numericGuard = 'true';
          input.setAttribute('inputmode', mode === 'decimal' ? 'decimal' : 'numeric');
          input.addEventListener('beforeinput', (event) => {
              if (!event.data || !event.inputType.startsWith('insert')) return;
              const start = input.selectionStart ?? input.value.length;
              const end = input.selectionEnd ?? input.value.length;
              const proposedValue = input.value.slice(0, start) + event.data + input.value.slice(end);
              if (sanitizeNumericValue(proposedValue, mode) !== proposedValue) event.preventDefault();
          });
          input.addEventListener('input', () => {
              const nextValue = sanitizeNumericValue(input.value, mode);
              if (input.value !== nextValue) input.value = nextValue;
              if (input.id === 'hr_male' || input.id === 'hr_female') calcHr();
          });
      });
  }

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
      if(table.rows.length >= 10) { showBeneficiaryNotice('Product Limit Reached', 'You may add up to 10 products or services only.'); return; }
      let newRow = table.insertRow();
      newRow.innerHTML = `<td><input type="text" name="prod_name[]" placeholder="Item Name" required></td>
                          <td><input type="text" name="prod_price[]" placeholder="0.00" oninput="this.value = this.value.replace(/[^0-9.]/g, '')" required></td>
                          <td class="action-cell"><button type="button" class="btn-remove-row" onclick="removeRow(this)" aria-label="Remove row" title="Remove row"><i class="ph-bold ph-trash"></i></button></td>`;
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
          row.innerHTML = `<td><input type="text" name="prod_name[]" placeholder="Item Name" required></td>
                           <td><input type="text" name="prod_price[]" placeholder="0.00" inputmode="decimal" oninput="this.value = this.value.replace(/[^0-9.]/g, '')" required></td>
                           <td class="action-cell"><button type="button" class="btn-remove-row" onclick="removeRow(this)" aria-label="Remove row" title="Remove row"><i class="ph-bold ph-trash"></i></button></td>`;
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
      if(table.rows.length >= 4) { showBeneficiaryNotice('History Limit Reached', 'You may add up to four SPES history records only.'); return; }
      let newRow = table.insertRow();
      newRow.innerHTML = `<td><input type="text" name="spes_hist_avail[]" class="not-required" placeholder="e.g. 1st"></td>
                          <td><input type="text" name="spes_hist_est[]" class="not-required" placeholder="Office/LGU"></td>
                          <td><input type="text" name="spes_hist_year[]" class="not-required" placeholder="YYYY" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></td>
                          <td><input type="text" name="spes_hist_id[]" class="not-required" placeholder="ID Number"></td>
                          <td class="action-cell"><button type="button" class="btn-remove-row" onclick="removeRow(this)" aria-label="Remove row" title="Remove row"><i class="ph-bold ph-trash"></i></button></td>`;
  }

  function removeRow(btn) {
      let row = btn.parentNode.parentNode;
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

          const profileAvatar = document.getElementById('pm_avatar');
          if (profileAvatar) {
              profileAvatar.textContent = data.initial || '?';
              if (data.profile_image) {
                  const image = document.createElement('img');
                  image.src = data.profile_image;
                  image.alt = `${data.name || 'Beneficiary'} profile photo`;
                  image.onload = () => { profileAvatar.textContent = ''; profileAvatar.appendChild(image); };
                  image.onerror = () => { profileAvatar.textContent = data.initial || '?'; };
              }
          }
          safeSetText('pm_name', data.name);
          safeSetText('pm_program_header', data.program);
          const lifecycleRibbon = document.getElementById('pm_spes_lifecycle');
          if (lifecycleRibbon) {
              lifecycleRibbon.hidden = !data.spes_lifecycle_label;
              lifecycleRibbon.textContent = data.spes_lifecycle_label || '';
              lifecycleRibbon.className = `spes-lifecycle-ribbon ${data.spes_lifecycle === 'graduate' ? 'is-graduate' : ''}`;
          }
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
              if(stallContainer) stallContainer.style.display = 'none';
              if(dateContainer) dateContainer.style.display = 'none';
              if(utilityContainer) { utilityContainer.style.display = 'block'; safeSetText('pm_utility', data.utility_needs || '—'); }
              if(assetsContainer) { assetsContainer.style.display = 'block'; safeSetText('pm_assets', data.assets_owned || '—'); }
          }

          safeSetText('pm_dynamic_label', labelText);
          safeSetText('pm_type_beneficiary', typeText);

          renderBeneficiaryResume(data, labelText, typeText);

          function getAvail(s) {
              s = (s || '').toLowerCase().trim();
              if (s === "ongoing") return "pill success";
              if (s === "completed") return "pill neutral";
              if (s === "requirements received") return "pill warning";
              if (s === "not yet availed") return "pill warning";
              return "pill neutral";
          }

          const badgeContainer = document.getElementById('pm_availment_badge');
          if (badgeContainer) {
              let displayedAvailment = data.availment;
              if (data.approval === 'Pending') displayedAvailment = 'Pending Review';
              else if (data.approval === 'Rejected') displayedAvailment = 'Application Not Accepted';
              else if (data.availment === 'Not Yet Availed') displayedAvailment = 'Waiting for Requirements';
              else if (data.availment === 'Requirements Received') displayedAvailment = 'Documents Submitted';
              badgeContainer.innerHTML = `<span class="${getAvail(data.availment)}"><span class="pill-dot" style="background:currentColor;"></span> ${displayedAvailment}</span>`;
          }

          window.currentProfileData = data;

          const footerActions = document.getElementById('pm_footer_actions');
          if(footerActions){footerActions.replaceChildren();const close=document.createElement('button');close.type='button';close.className='btn-light';close.textContent='Close View';close.addEventListener('click',()=>document.getElementById('profileModal').classList.remove('show'));footerActions.append(close);}

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
          const wrapper = scope ? scope.querySelector('.date-fields-wrapper') : null;
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
          ['Program & Status', 'ph-calendar-check', [
              ['Program', data.program], ['Approval Status', data.approval === 'Approved' ? 'Accepted' : data.approval], ['Status', data.availment === 'Not Yet Availed' ? 'Waiting for Requirements' : data.availment],
              ['Previous / Last Participation', availmentSummary], ['Last Availed Year', lastAvailmentYear],
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
          ['Past SPES Participation', 'ph-clock-counter-clockwise', [
              ['SPES Classification', data.spes_lifecycle_label],
              ['All Recorded Participation', data.spes_all_availments],
              ['1st Participation', [data.spes_history_1_year, data.spes_history_1_id && `ID: ${data.spes_history_1_id}`].filter(hasValue).join(' · ')],
              ['2nd Participation', [data.spes_history_2_year, data.spes_history_2_id && `ID: ${data.spes_history_2_id}`].filter(hasValue).join(' · ')],
              ['3rd Participation', [data.spes_history_3_year, data.spes_history_3_id && `ID: ${data.spes_history_3_id}`].filter(hasValue).join(' · ')],
              ['4th Participation', [data.spes_history_4_year, data.spes_history_4_id && `ID: ${data.spes_history_4_id}`].filter(hasValue).join(' · ')]
          ]],
          ['SPES Qualification Review', 'ph-shield-check', [
              ['Age Requirement', data.age ? ((Number(data.age) >= 18 && Number(data.age) <= 30) ? `Meets requirement (${data.age} years old)` : `Does not meet requirement (${data.age} years old; required 18–30)`) : 'For verification'],
              ['Reported Family Income', data.avg_monthly_income],
              ['Region V Income Reference', '2023 PSA threshold for a family of six: ₱16,787.50 monthly / ₱201,450.00 annually (calculated from ₱33,575 annual per capita).']
          ]],
          ['Regional Income Reference', 'ph-chart-line-up', [
              ['Reported Family Income', data.avg_monthly_income],
              ['Region V Reference', '2023 PSA threshold for a family of six: ₱16,787.50 monthly / ₱201,450.00 annually.'],
              ['Use', 'Reference only; apply it when the current program guidelines require an income qualification.']
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
          if (title === 'Past SPES Participation' && !programUpper.includes('SPES')) return;
          if (title === 'SPES Qualification Review' && !programUpper.includes('SPES')) return;
          if (title === 'Regional Income Reference' && !programUpper.includes('TUPAD')) return;
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

      const wizardNav = document.getElementById('wizardNav');
      const currentIndicator = document.getElementById('indicator-step-' + currentStep);
      const currentLabel = currentIndicator?.querySelector('span:not(.step-num)')?.textContent.trim() || '';
      if (wizardNav) {
          wizardNav.dataset.progress = `Step ${currentStep} of ${totalSteps}${currentLabel ? ' \u00b7 ' + currentLabel : ''}`;
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

  let quickStatusReturnFocus = null;

  function openQuickStatusModal(id, currentStatus, dateAvailed, dateCompleted) {
      quickStatusReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      document.getElementById('profileModal')?.classList.remove('show');
      document.getElementById('adminAddBeneficiaryModal')?.classList.remove('show');
      document.getElementById('quick_beneficiary_id').value = id;
      document.getElementById('quick_availment_status').value = currentStatus || 'Not Yet Availed';
      document.getElementById('quick_date_availed').value = dateAvailed || '';
      document.getElementById('quick_date_completed').value = dateCompleted || '';
      document.getElementById('quick_schedule_place').value = '';
      document.getElementById('quick_status_message').value = '';
      document.getElementById('quick_needs_resubmission').checked = false;
      toggleQuickDateFields();
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
      const resubmissionGroup = document.getElementById('quick_resubmission_container');
      const resubmissionInput = document.getElementById('quick_needs_resubmission');

      wrapper.style.display = 'none';
      group1.style.display = 'none';
      group2.style.display = 'none';
      input1.removeAttribute('required');
      input2.removeAttribute('required');
      placeGroup.style.display = 'none';
      placeInput.removeAttribute('required');
      const isRequirements = status === 'Requirements Received';
      resubmissionGroup.style.display = isRequirements ? 'block' : 'none';
      if (!isRequirements) resubmissionInput.checked = false;
      const needsMessage = status === 'Not Qualified' || status === 'Cancelled' || (isRequirements && resubmissionInput.checked);
      messageGroup.style.display = needsMessage ? 'block' : 'none';
      messageInput.required = needsMessage;
      messageGroup.querySelector('label').textContent = isRequirements && resubmissionInput.checked ? 'Reason and resubmission instructions *' : 'Reason for this status *';
      messageInput.placeholder = isRequirements && resubmissionInput.checked ? 'Explain what is incorrect or missing and what documents must be resubmitted.' : 'Explain why the applicant is not qualified or why the availment was cancelled.';
      if (!needsMessage) messageInput.value = '';
      dateRow.style.gridTemplateColumns = 'minmax(0, 1fr) minmax(0, 1fr)';

      if (status === 'Orientation' || status === 'Examination' || status === 'Salary Distribution') {
          wrapper.style.display = 'block';
          dateRow.style.gridTemplateColumns = 'minmax(0, 1fr)';
          group1.style.display = 'block';
          label1.textContent = status === 'Orientation' ? 'Orientation Date *' : (status === 'Examination' ? 'Examination Date *' : 'Distribution Date *');
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
          const activeElement = document.activeElement;
          if (activeElement instanceof HTMLElement && quickModal.contains(activeElement)) activeElement.blur();
          quickModal.classList.remove('show');
          quickModal.setAttribute('aria-hidden', 'true');
          window.requestAnimationFrame(() => {
              if (quickStatusReturnFocus?.isConnected && quickStatusReturnFocus.offsetParent !== null) {
                  quickStatusReturnFocus.focus();
              } else {
                  document.getElementById('menuToggle')?.focus();
              }
          });
      });
  });

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

      if (document.getElementById('modalTitle')) document.getElementById('modalTitle').textContent = "Edit Beneficiary";
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

  function openRejectModal(id) {
      document.getElementById('profileModal').classList.remove('show');
      document.getElementById('reject_beneficiary_id').value = id;
      document.getElementById('rejectModal').classList.add('show');
  }

  document.querySelectorAll('.auto-submit').forEach(el => { el.addEventListener('change', () => document.getElementById('filterForm').submit()); });

  document.addEventListener('DOMContentLoaded', () => {
      const addModal = document.getElementById('adminAddBeneficiaryModal');
      const bulkModal = document.getElementById('bulkUploadModal');
      const reportModal = document.getElementById('generateReportModal');
      const succModal = document.getElementById('successModal');
      const errModal = document.getElementById('errorModal');
      const profileModal = document.getElementById('profileModal');
      let lastModalTrigger = null;

      const setModalVisibility = (modal, isOpen, returnFocusTo = null) => {
          if (!modal) return;
          if (isOpen) {
              lastModalTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
              modal.setAttribute('aria-hidden', 'false');
              modal.classList.add('show');
              return;
          }

          const focusedElement = document.activeElement;
          if (focusedElement instanceof HTMLElement && modal.contains(focusedElement)) focusedElement.blur();
          modal.classList.remove('show', 'filters-open');
          modal.setAttribute('aria-hidden', 'true');
          const focusTarget = returnFocusTo || lastModalTrigger;
          if (focusTarget instanceof HTMLElement && document.contains(focusTarget)) {
              requestAnimationFrame(() => focusTarget.focus({ preventScroll: true }));
          }
      };

      applyNumericInputGuards(document);
      const numericGuardObserver = new MutationObserver((mutations) => {
          mutations.forEach(mutation => {
              mutation.addedNodes.forEach(node => {
                  if (node.nodeType === Node.ELEMENT_NODE) applyNumericInputGuards(node);
              });
          });
      });
      numericGuardObserver.observe(document.body, { childList: true, subtree: true });

      document.querySelectorAll('#openAddBeneficiaryModal, #openAddBeneficiaryModal2').forEach(btn => {
          if(btn) btn.addEventListener('click', () => {
              document.getElementById('modalTitle').textContent = "Add Beneficiary";
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
              setModalVisibility(addModal, true);
          });
      });
      document.querySelectorAll('[data-close-modal]').forEach(btn => btn.addEventListener('click', () => setModalVisibility(addModal, false), true));

      document.querySelectorAll('#openBulkUploadModal, #openBulkUploadModal2').forEach(btn => { if(btn) btn.addEventListener('click', () => setModalVisibility(bulkModal, true)); });
      document.querySelectorAll('[data-close-bulk]').forEach(btn => btn.addEventListener('click', () => setModalVisibility(bulkModal, false), true));

      // Keep legacy database values stable while presenting clear workflow labels.
      document.querySelectorAll('option[value="Not Yet Availed"]').forEach(option => {
          option.textContent = option.textContent.includes('New Beneficiaries')
              ? 'New Beneficiaries (Initial Stage)'
              : 'Waiting for Requirements';
      });
      document.querySelectorAll('option[value="Requirements Received"]').forEach(option => {
          option.textContent = 'Documents Submitted';
      });

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
      const reportFilterToggle = document.querySelector('#generateReportModal .report-filter-toggle');
      if(openReportBtn) {
          openReportBtn.addEventListener('click', async () => {
              setModalVisibility(reportModal, true);
              reportModal.classList.remove('filters-open');
              reportFilterToggle?.setAttribute('aria-expanded', 'false');
              openReportBtn.disabled = true;
              openReportBtn.setAttribute('aria-busy', 'true');
              try {
                  await loadReportData();
                  updateReportPreview();
              } catch (error) {
                  setModalVisibility(reportModal, false, openReportBtn);
                  showBeneficiaryNotice('Report Unavailable', error.message || 'Report records could not be loaded. Please try again.');
              } finally {
                  openReportBtn.disabled = false;
                  openReportBtn.removeAttribute('aria-busy');
              }
          });
      }
      reportFilterToggle?.addEventListener('click', () => {
          const open = reportModal.classList.toggle('filters-open');
          reportFilterToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      document.querySelectorAll('[data-close-report]').forEach(btn => btn.addEventListener('click', () => {
          setModalVisibility(reportModal, false, openReportBtn);
          reportFilterToggle?.setAttribute('aria-expanded', 'false');
      }, true));

      const reportBrgySelect = document.getElementById('report_brgy_select');
      const reportAvailSelect = document.getElementById('report_avail_select');
      const reportBatchSelect = document.getElementById('report_batch_select');
      const reportSpesGroupSelect = document.getElementById('report_spes_group_select');
      const reportNatureSelect = document.getElementById('report_nature_select');
      const reportPrintButton = document.getElementById('report_print_button');
      const reportPreviewTable = document.getElementById('preview_main_table');
      const reportTableViewport = document.querySelector('#generateReportModal .scrollable-table-wrap');
      const reportZoomValue = document.querySelector('#generateReportModal .report-zoom-value');
      const reportZoomButtons = Array.from(document.querySelectorAll('#generateReportModal [data-report-zoom]'));
      const reportColumnInputs = Array.from(document.querySelectorAll('.report-column-list input[type="checkbox"]'));
      const reportColumnCount = document.querySelector('.report-column-count');
      const reportColumnDefinitions = <?php echo json_encode(getReportColumnDefinitions($selectedProgramName), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      const getReportColumnsPerPage = () => window.innerWidth <= 520 ? 2 : (window.innerWidth <= 900 ? 4 : 8);
      let currentReportColumnPage = 1;
      let showAllReportColumns = false;
      let reportZoom = 1;

      function setReportZoom(nextZoom) {
          reportZoom = Math.min(1.5, Math.max(0.65, Math.round(nextZoom * 10) / 10));
          if (reportPreviewTable) reportPreviewTable.style.zoom = String(reportZoom);
          if (reportZoomValue) reportZoomValue.value = `${Math.round(reportZoom * 100)}%`;
          reportZoomButtons.forEach(button => {
              button.disabled = (button.dataset.reportZoom === 'out' && reportZoom <= 0.65)
                  || (button.dataset.reportZoom === 'in' && reportZoom >= 1.5);
              button.classList.toggle('is-active', button.dataset.reportZoom === 'fit' && reportZoom === 1);
          });
          if (reportZoom === 1 && reportTableViewport) reportTableViewport.scrollLeft = 0;
      }

      reportZoomButtons.forEach(button => button.addEventListener('click', () => {
          const action = button.dataset.reportZoom;
          setReportZoom(action === 'in' ? reportZoom + 0.1 : (action === 'out' ? reportZoom - 0.1 : 1));
      }));
      setReportZoom(1);

      const reportMultiValueKeys = ['primary_products','product_price','business_nature','assets_owned','utility_needs','source_of_capital','mode_of_payment','distribution_channels','assistance_availed','past_programs','programs_needed','challenges_encountered','special_skills','parents_status','ownership_type','hr_skills','skills_training_needed','type_of_beneficiary','employment_type','spes_history'];

      function uniqueReportListItems(value) {
          let source = value;
          if (!Array.isArray(source)) {
              const raw = String(source || '').trim();
              try {
                  const decoded = JSON.parse(raw);
                  if (Array.isArray(decoded)) source = decoded;
              } catch (error) {}
          }
          const flattened = (Array.isArray(source) ? source.flat(Infinity) : String(source || '').split(/[,;\r\n]+/));
          const seen = new Set();
          return flattened.map(item => String(item ?? '').trim()).filter(item => {
              const key = item.replace(/\s+/g, ' ').toLowerCase();
              if (!key || seen.has(key)) return false;
              seen.add(key);
              return true;
          });
      }

      function formatReportList(items) {
          const values = Array.isArray(items) ? items.filter(Boolean) : uniqueReportListItems(items);
          return values.length <= 1 ? (values[0] || '') : values.map(item => `• ${item}`).join('\n');
      }

      function formatStructuredReportValue(value) {
          const raw = String(value ?? '').trim();
          if (!raw) return '';
          try {
              const decoded = JSON.parse(raw);
              if (Array.isArray(decoded)) return formatReportList(uniqueReportListItems(decoded));
          } catch (error) {}
          if (/[;\r\n]/.test(raw)) {
              const items = raw.split(/[;\r\n]+/).map(item => item.trim()).filter(Boolean);
              return formatReportList([...new Map(items.map(item => [item.replace(/\s+/g, ' ').toLowerCase(), item])).values()]);
          }
          return raw;
      }

      function formatSpesHistory(row) {
          let history = row.spes_history;
          for (let attempt = 0; attempt < 2 && typeof history === 'string'; attempt++) {
              try { history = JSON.parse(history); } catch (error) { break; }
          }
          let entries = [];
          if (Array.isArray(history)) {
              entries = history.map(entry => {
                  if (!entry || typeof entry !== 'object') return '';
                  const establishment = String(entry.establishment ?? entry[1] ?? '').trim();
                  const year = String(entry.year ?? entry[2] ?? '').trim();
                  const id = String(entry.id ?? entry[3] ?? '').trim();
                  return [establishment, year ? `Year: ${year}` : '', id ? `SPES ID: ${id}` : ''].filter(Boolean).join(' | ');
              }).filter(Boolean);
          }
          if (!entries.length) {
              entries = [1,2,3,4].map(number => {
                  const establishment = String(row[`spes_history_${number}_establishment`] || '').trim();
                  const year = String(row[`spes_history_${number}_year`] || '').trim();
                  const id = String(row[`spes_history_${number}_id`] || '').trim();
                  return [establishment, year ? `Year: ${year}` : '', id ? `SPES ID: ${id}` : ''].filter(Boolean).join(' | ');
              }).filter(Boolean);
          }
          return entries.length ? entries.map(entry => `• ${entry}`).join('\n') : (typeof history === 'string' ? history : '');
      }

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
          const groups = new Map();
          names.forEach((name, index) => {
              const cleanName = String(name || '').trim();
              if (!cleanName) return;
              const groupKey = cleanName.replace(/\s+/g, ' ').toLowerCase();
              if (!groups.has(groupKey)) groups.set(groupKey, { name: cleanName, prices: [] });
              const price = String(prices[index] || '').trim();
              const group = groups.get(groupKey);
              if (price && !group.prices.includes(price)) group.prices.push(price);
          });
          const grouped = Array.from(groups.values());
          return {
              names: formatReportList(grouped.map(group => group.name)),
              prices: formatReportList(grouped.map(group => group.prices.length ? group.prices.join(' / ') : '—'))
          };
      }

      function reportCellValue(row, key) {
          const products = (key === 'primary_products' || key === 'product_price') ? reportProductParts(row) : null;
          if (key === 'primary_products') return products.names;
          if (key === 'product_price') return products.prices;
          if (key === 'spes_history') return formatSpesHistory(row);
          if (key === 'employment_type') return formatReportList(uniqueReportListItems(row.employment_type || [row.emp_regular ? 'Regular' : '', row.emp_seasonal ? 'Seasonal' : '', row.emp_contractual ? 'Contractual' : '', row.emp_family ? 'Family' : ''].filter(Boolean)));
          if (reportMultiValueKeys.includes(key)) return formatReportList(uniqueReportListItems(row[key]));
          if (key === 'owner_name') return row.full_name || [row.first_name, row.middle_name, row.last_name, row.ext_name].filter(Boolean).join(' ');
          if (key === 'owner_contact') return row.contact_no || '';
          if (key === 'complete_address') return row.address || [row.street_purok_zone, row.barangay, 'Vinzons, Camarines Norte'].filter(Boolean).join(' ');
          if (key === 'school') return row.tert_school || row.sec_school || row.elem_school || '';
          if (key === 'course') return row.tert_course || row.sec_degree || '';
          if (key === 'level') return row.tert_year_level || row.sec_year_level || row.elem_year_level || '';
          if (key === 'parent_name') return row.father_name || row.mother_name || row.gsis_beneficiary_name || '';
          if (key === 'parent_occupation') return row.father_occupation || row.mother_occupation || '';
          if (key === 'hr_total') return row.hr_total || ((parseInt(row.hr_male) || 0) + (parseInt(row.hr_female) || 0)) || '';
          if (key === 'current_capital') return row.current_capital || row.initial_capital || '';
          if (key === 'remarks') return row.approval_note || row.remarks || '';
          if (key === 'approval_status') return String(row.approval_status || '').toUpperCase() === 'APPROVED' ? 'QUALIFIED' : (String(row.approval_status || '').toUpperCase() === 'REJECTED' ? 'DISQUALIFIED' : row.approval_status || '');
          if (['dti_assistance','dole_assistance','lgu_assistance','tesda_training'].includes(key) && !row[key]) return String(row.assistance_availed || '').toUpperCase().includes(key.split('_')[0].toUpperCase()) ? 'Yes' : '';
          if (key === 'financial_assistance' && !row[key]) return String(row.programs_needed || '').toLowerCase().includes('financ') ? 'Needed' : '';
          if (key === 'livelihood_assistance' && !row[key]) return String(row.assistance_availed || '').toLowerCase().includes('livelihood') ? 'Yes' : '';
          if (key === 'business_training' && !row[key]) return String(row.past_programs || '').toLowerCase().includes('training') ? 'Yes' : '';
          return formatStructuredReportValue(row[key] ?? '');
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
          if (reportSpesGroupSelect) reportSpesGroupSelect.addEventListener('change', updateReportPreview);
          if (reportNatureSelect) reportNatureSelect.addEventListener('change', updateReportPreview);
      }
      if (reportPrintButton) reportPrintButton.addEventListener('click', printFilteredReport);
      reportColumnInputs.forEach(input => input.addEventListener('change', () => {
          if (!reportColumnInputs.some(column => column.checked)) input.checked = true;
          currentReportColumnPage = 1;
          updateReportPreview();
      }));
      document.querySelectorAll('[data-report-columns]').forEach(button => button.addEventListener('click', () => {
          const selectAll = button.dataset.reportColumns === 'all';
          reportColumnInputs.forEach(input => { input.checked = selectAll || input.dataset.default === '1'; });
          currentReportColumnPage = 1;
          updateReportPreview();
      }));
      document.querySelectorAll('[data-report-column-page]').forEach(button => button.addEventListener('click', () => {
          const selectedCount = reportColumnInputs.filter(input => input.checked).length;
          const reportColumnsPerPage = getReportColumnsPerPage();
          const totalPages = Math.max(1, Math.ceil(selectedCount / reportColumnsPerPage));
          const direction = button.dataset.reportColumnPage === 'next' ? 1 : -1;
          currentReportColumnPage = Math.min(totalPages, Math.max(1, currentReportColumnPage + direction));
          applyReportColumnVisibility();
      }));
      window.addEventListener('resize', () => {
          if (reportModal?.classList.contains('show')) applyReportColumnVisibility();
      });

      function applyReportColumnVisibility() {
          const table = document.getElementById('preview_main_table');
          const thead = document.getElementById('preview_table_head');
          if (!table || !thead || reportColumnInputs.length === 0) return;

          if (thead.rows.length > 1) {
              const labels = Array.from(thead.rows[thead.rows.length - 1].cells).map(cell => cell.textContent);
              thead.innerHTML = `<tr><th>No.</th>${labels.map(label => `<th>${label}</th>`).join('')}</tr>`;
          }

          const selectedIndexes = reportColumnInputs.filter(input => input.checked).map(input => Number(input.dataset.columnIndex));
          const reportColumnsPerPage = getReportColumnsPerPage();
          const totalColumnPages = Math.max(1, Math.ceil(selectedIndexes.length / reportColumnsPerPage));
          currentReportColumnPage = Math.min(totalColumnPages, Math.max(1, currentReportColumnPage));
          const columnStart = (currentReportColumnPage - 1) * reportColumnsPerPage;
          const pageIndexes = showAllReportColumns ? selectedIndexes : selectedIndexes.slice(columnStart, columnStart + reportColumnsPerPage);
          const visibleIndexes = new Set([0, ...pageIndexes]);
          const cellFontSize = showAllReportColumns ? (visibleIndexes.size > 22 ? '5.5px' : '7px') : '9px';
          const headerLabels = Array.from(thead.rows[0]?.cells || []).map(cell => cell.textContent.trim());
          table.classList.remove('report-detail-view');
          table.style.width = '100%';
          table.style.minWidth = '0';
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
          if (reportColumnCount) reportColumnCount.textContent = `${selectedIndexes.length} selected`;
          const pageStatus = document.querySelector('.report-column-page-status');
          if (pageStatus) {
              const firstVisible = selectedIndexes.length ? columnStart + 1 : 0;
              const lastVisible = Math.min(columnStart + reportColumnsPerPage, selectedIndexes.length);
              pageStatus.textContent = showAllReportColumns ? `All ${selectedIndexes.length} columns` : `Columns ${firstVisible}–${lastVisible} of ${selectedIndexes.length}`;
          }
          document.querySelectorAll('[data-report-column-page]').forEach(button => {
              button.disabled = showAllReportColumns || (button.dataset.reportColumnPage === 'previous' ? currentReportColumnPage <= 1 : currentReportColumnPage >= totalColumnPages);
          });
      }

      function updateReportPreview() {
          const selectedBrgy = reportBrgySelect.value;
          const selectedAvail = reportAvailSelect.value;
          const selectedBatchId = reportBatchSelect ? reportBatchSelect.value : '0';
          const selectedSpesGroup = reportSpesGroupSelect ? reportSpesGroupSelect.value : 'all';
          const selectedNature = reportNatureSelect ? reportNatureSelect.value : 'All';

          const subtitleParts = [];
          if (selectedBatchId !== '0' && reportBatchSelect) subtitleParts.push(`Batch: ${reportBatchSelect.options[reportBatchSelect.selectedIndex].text}`);
          subtitleParts.push(selectedBrgy === 'All' || selectedBrgy === '' ? 'Municipality of Vinzons' : `Barangay ${selectedBrgy}, Vinzons`);
          if (selectedAvail !== 'All') subtitleParts.push(`Status: ${selectedAvail === 'Not Yet Availed' ? 'Waiting for Requirements' : (selectedAvail === 'Requirements Received' ? 'Documents Submitted' : selectedAvail)}`);
          if (selectedNature !== 'All') subtitleParts.push(`Vendor Type: ${selectedNature}`);
          if (selectedSpesGroup !== 'all') subtitleParts.push(selectedSpesGroup === 'graduate' ? 'SPES Graduates' : 'SPES Babies');
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
                      <th colspan="2">III. BUSINESS OPERATIONS</th>
                      <th colspan="5">IV. HUMAN RESOURCES</th>
                      <th colspan="7">V. FINANCIAL INFORMATION</th>
                      <th colspan="7">VI. GOVERNMENT ASSISTANCE</th>
                  </tr>
                  <tr>
                      <th>Business/Trade Name</th><th>Type of Ownership</th><th>Nature of Business</th><th>Primary Products Offered</th><th>Product Price</th><th>Year Business Started</th><th>Business Permit No.</th><th>Valid Until</th><th>DTI Registration No.</th><th>Tax Identification No. (TIN)</th><th>Landline/Mobile Number</th><th>Email</th><th>Website/Social Media</th>
                      <th>Full Name</th><th>Contact Number</th><th>Sex</th><th>Date of Birth</th><th>Age</th><th>Civil Status</th><th>Complete Address</th><th>Educational Attainment</th><th>Work Experience</th>
                      <th>Business Assets Owned</th><th>Utility Needs</th>
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
                  let matchNature = selectedNature === 'All' || uniqueReportListItems(b.business_nature)
                      .some(nature => nature.toLowerCase() === selectedNature.toLowerCase());
                  let matchSpesGroup = selectedSpesGroup === 'all' || b.spes_lifecycle === selectedSpesGroup;
                  return matchBrgy && matchAvail && matchBatch && matchNature && matchSpesGroup;
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
                  tbody.innerHTML += `<tr><td style="text-align:center;">${globalIndex}</td>${reportColumnDefinitions.map(column => {
                      const value = escapeReportValue(reportCellValue(b, column.key));
                      const title = value.replace(/\n/g, ' | ').replace(/"/g, '&quot;');
                      const cellClass = reportMultiValueKeys.includes(column.key) || value.includes('\n') ? 'report-multivalue' : '';
                      return `<td class="${cellClass}" title="${title}">${value}</td>`;
                  }).join('')}</tr>`;
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
                      <td>${b.assets_owned || ''}</td>
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
                      <td style="font-weight:bold; color:#1f7a54;">${b.approval_status === 'Approved' ? 'Accepted' : (b.approval_status || '')}</td>
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
                      <td>${b.availment_status === 'Not Yet Availed' ? 'Waiting for Requirements' : (b.availment_status || '')}</td>
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
                  <div class="report-page-summary" style="font-size: 13px; color: #667085;">
                      Showing <strong>${startIndex + 1}</strong> to <strong>${Math.min(endIndex, previewData.length)}</strong> of <strong>${previewData.length}</strong> entries
                  </div>
                  <div class="report-page-actions" style="display: flex; align-items: center; gap: 8px;">
                      <button type="button" class="report-page-button" style="padding: 6px 12px; border: 1px solid #d0d5dd; background: #fff; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; color: #344054; display: flex; align-items: center; gap: 4px;" onclick="changePreviewPage(${currentPreviewPage - 1})" ${currentPreviewPage === 1 ? 'disabled' : ''}>
                          <i class="ph-bold ph-caret-left"></i> Prev
                      </button>
                      <span class="report-page-indicator" style="font-size: 13px; font-weight: 600; color: #344054; margin: 0 4px;">Page ${currentPreviewPage} of ${totalPages}</span>
                      <button type="button" class="report-page-button" style="padding: 6px 12px; border: 1px solid #d0d5dd; background: #fff; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; color: #344054; display: flex; align-items: center; gap: 4px;" onclick="changePreviewPage(${currentPreviewPage + 1})" ${currentPreviewPage === totalPages ? 'disabled' : ''}>
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
              showBeneficiaryNotice('No Records Found', 'No records match the selected report filters.');
              return;
          }

          const tbody = document.getElementById('preview_table_body');
          const thead = document.getElementById('preview_table_head');
          const originalPage = currentPreviewPage;
          const totalPages = Math.ceil(previewData.length / previewItemsPerPage);
          const printRows = [];

          showAllReportColumns = true;
          for (let page = 1; page <= totalPages; page++) {
              currentPreviewPage = page;
              renderPreviewPage();
              tbody.querySelectorAll('tr').forEach(row => printRows.push(row.cloneNode(true)));
          }
          const printHead = thead.cloneNode(true);
          showAllReportColumns = false;
          currentPreviewPage = originalPage;
          renderPreviewPage();

          const printWindow = window.open('', '_blank', 'width=1400,height=900');
          if (!printWindow) {
              showBeneficiaryNotice('Print Window Blocked', 'Please allow pop-ups for BENEPESO, then try printing the report again.');
              return;
          }

          const printColumnCount = Array.from(printHead.rows[0]?.cells || []).filter(cell => cell.style.display !== 'none').length;
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
          </style></head><body><div class="official-header-wrap"></div><div class="print-brand"><h1 id="print_report_title"></h1><p id="print_report_subtitle"></p></div><table><thead id="print_report_head"></thead><tbody id="print_report_body"></tbody></table></body></html>`);
          printWindow.document.close();
          printWindow.document.getElementById('print_report_title').textContent = currentReportTitle;
          printWindow.document.getElementById('print_report_subtitle').textContent = document.getElementById('preview_subtitle_top')?.textContent || '';
          printWindow.document.getElementById('print_report_head').replaceWith(printWindow.document.importNode(printHead, true));
          const printBody = printWindow.document.getElementById('print_report_body');
          printRows.forEach(row => printBody.appendChild(printWindow.document.importNode(row, true)));
          const printHeader = printWindow.document.getElementById('print_official_header');
          const startPrint = () => window.setTimeout(() => { printWindow.focus(); printWindow.print(); }, 150);
          if (!printHeader || printHeader.complete) startPrint(); else { printHeader.onload = startPrint; printHeader.onerror = startPrint; }
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

      function openSidebar() {
          sideArea.classList.add('open');
          sidebarOverlay.classList.add('show');
          document.body.classList.add('sidebar-open');
          if (menuToggle) {
              menuToggle.setAttribute('aria-expanded', 'true');
          }
      }
      function closeSidebar() {
          sideArea.classList.remove('open');
          sidebarOverlay.classList.remove('show');
          document.body.classList.remove('sidebar-open');
          if (menuToggle) {
              menuToggle.setAttribute('aria-expanded', 'false');
          }
      }

      if (menuToggle) menuToggle.addEventListener('click', openSidebar);
      if (sideClose) sideClose.addEventListener('click', closeSidebar);
      if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);
      window.addEventListener('resize', () => { if (window.innerWidth > 992 && sideArea.classList.contains('open')) closeSidebar(); });

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
  const mobileSelectAll = document.getElementById('mobileSelectAllBeneficiaries');
  const bar = document.getElementById('bulkSelectionBar');
  const count = document.getElementById('bulkSelectedCount');
  const modal = document.getElementById('bulkStatusActionModal');
  const inputs = document.getElementById('bulkSelectedInputs');
  const status = document.getElementById('bulkAvailmentStatus');
  if (!bar || !count || !modal || !inputs || !status) return;
  const selected = () => boxes.filter(box => box.checked);
  const sync = () => {
    const chosen = selected();
    count.textContent = chosen.length;
    document.querySelectorAll('.bulk-modal-count').forEach(el => el.textContent = chosen.length);
    bar.hidden = chosen.length === 0;
    document.body.classList.toggle('has-bulk-selection', chosen.length > 0);
    if (selectAll) {
      selectAll.checked = boxes.length > 0 && chosen.length === boxes.length;
      selectAll.indeterminate = chosen.length > 0 && chosen.length < boxes.length;
    }
    if (mobileSelectAll) {
      mobileSelectAll.checked = boxes.length > 0 && chosen.length === boxes.length;
      mobileSelectAll.indeterminate = chosen.length > 0 && chosen.length < boxes.length;
    }
    boxes.forEach(box => box.closest('tr')?.classList.toggle('is-selected', box.checked));
  };
  selectAll?.addEventListener('change', () => { boxes.forEach(box => box.checked = selectAll.checked); sync(); });
  mobileSelectAll?.addEventListener('change', () => { boxes.forEach(box => box.checked = mobileSelectAll.checked); sync(); });
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
    const needsDate = ['Orientation','Examination','Salary Distribution','Completed'].includes(status.value);
    const needsPlace = ['Orientation','Examination','Salary Distribution'].includes(status.value);
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
  let bulkStatusConfirmed = false;
  document.getElementById('bulkStatusForm')?.addEventListener('submit', event => {
    if (bulkStatusConfirmed) return;
    event.preventDefault();
    const form = event.currentTarget;
    showBeneficiaryNotice('Confirm Status Update', 'Update ' + selected().length + ' selected beneficiaries and send their phone-first notifications?', () => {
      bulkStatusConfirmed = true;
      form.requestSubmit();
    });
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
<script src="spes_form_modal.js?v=20260927b"></script>
<script src="msme_form_modal.js?v=20260927b"></script>
<script>
(() => {
  const program = <?php echo json_encode(strtoupper($selectedProgramName)); ?>;
  const isSpes = program.includes('SPES');
  const isTupad = program.includes('TUPAD');
  if (!isSpes) {
    document.querySelectorAll('select option[value="Examination"], select option[value="Exam Passed"], select option[value="Exam Failed"]').forEach(option => option.remove());
  }
  const allowed = isSpes
    ? ['Not Yet Availed','Requirements Received','Examination','Exam Passed','Exam Failed','Orientation','Ongoing','Completed','Not Qualified']
    : (isTupad
      ? ['Not Yet Availed','Requirements Received','Orientation','Ongoing','Salary Distribution','Completed','Not Qualified']
      : ['Not Yet Availed','Requirements Received','Orientation','Ongoing','Completed','Not Qualified']);
  document.querySelectorAll('#quick_availment_status option').forEach(option => {
    if (!allowed.includes(option.value)) option.remove();
  });
  const quickStatus = document.getElementById('quick_availment_status');
  if (quickStatus) allowed.forEach(value => {
    const option = Array.from(quickStatus.options).find(item => item.value === value);
    if (option) quickStatus.appendChild(option);
  });
})();
</script>
<script>
</script>
</body>
</html>
