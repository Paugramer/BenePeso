<?php
// Buffer includes before they can send whitespace ahead of the workbook.
ob_start();
require_once __DIR__ . '/auth.php';
require "db.php";
require_once "report_columns.php";
require_once __DIR__."/report_date_helper.php";
$reportDates=report_date_request($_GET);
require_once "xlsx_report_helper.php";
require_once "official_template_export.php";
require_once "spes_lifecycle_helper.php";

// Clean output buffer to ensure no stray spaces break the Excel file
if (ob_get_length()) {
    ob_clean();
}

// 1. Security Check
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found.");
}

// Allow BOTH Admins and PESO Staff to export the report.
if (auth_first_active_role(['admin', 'peso_staff']) === null) {
    http_response_code(403);
    exit("Unauthorized access. Please log in.");
}

// 2. Fetch Form Data
$program_id   = (int)($_GET['program_id'] ?? 0); 
$program_name = trim($_GET['program_name'] ?? '');
$barangay     = trim($_GET['report_barangay'] ?? 'All');
$availment    = trim($_GET['report_availment'] ?? 'All');
$business_nature = trim($_GET['business_nature'] ?? 'All');
$spes_group   = trim($_GET['report_spes_group'] ?? 'all');
// Old report links also download a genuine workbook instead of HTML named .xls.
$format = strtolower(trim((string)($_GET['export_format'] ?? 'xlsx')));
if (!in_array($format, ['xlsx', 'xls', 'excel', 'excel_template'], true)) {
    http_response_code(400);
    exit('Unsupported report format. Please choose Excel Workbook (.xlsx).');
}
$format = 'xlsx';

if (empty($program_name)) {
    die("Invalid Program Name. Please go back and select a valid program.");
}

// 3. Build the Beneficiaries Query
$whereParts = ["p.program_name = ?", report_record_condition($conn)];
$params = [$program_name];
$types = "s";

if ($program_id > 0) {
    $whereParts[] = "b.program_id = ?";
    $params[] = $program_id;
    $types .= "i";
}

if ($barangay !== "All") {
    $whereParts[] = "b.barangay = ?";
    $params[] = $barangay;
    $types .= "s";
}

if ($availment !== "All") {
    $whereParts[] = "b.availment_status = ?";
    $params[] = $availment;
    $types .= "s";
}

if (stripos($program_name, 'MSME') !== false && $business_nature !== "All") {
    $whereParts[] = "FIND_IN_SET(?, REPLACE(REPLACE(REPLACE(b.business_nature, ';', ','), ', ', ','), ' ,', ',')) > 0";
    $params[] = $business_nature;
    $types .= "s";
}
if (stripos($program_name, 'SPES') !== false && in_array($spes_group, ['baby', 'graduate'], true)) {
    $whereParts[] = spes_group_condition_sql($spes_group, 'b');
} else {
    $spes_group = 'all';
}

$reportOutcome = (string)($_GET['report_outcome'] ?? 'All');
if (in_array($reportOutcome, ['Completed','Rejected','Not Qualified','Exam Failed','Cancelled'], true)) {
    if ($reportOutcome === 'Rejected') $whereParts[] = "b.approval_status = 'Rejected'";
    else { $whereParts[] = "b.approval_status = 'Approved'"; $whereParts[] = 'b.availment_status = ?'; $params[] = $reportOutcome; $types .= 's'; }
}
if ($reportOutcome==='Documents Submitted') $whereParts[]=report_submission_condition($conn);
$reportSearch = mb_substr(trim((string)($_GET['report_search'] ?? '')), 0, 120);
if ($reportSearch !== '') {
    $whereParts[] = '(b.full_name LIKE ? OR b.barangay LIKE ? OR p.program_code LIKE ?)';
    $reportLike = '%' . $reportSearch . '%'; array_push($params, $reportLike, $reportLike, $reportLike); $types .= 'sss';
}

report_date_sql($reportDates,$whereParts,$params,$types);

// EXACT ALPHABETICAL SORTING
$orderBy = ($barangay === "All") ? "b.barangay ASC, b.last_name ASC, b.first_name ASC" : "b.last_name ASC, b.first_name ASC";

$sql = "SELECT b.*, p.program_name, p.start_date, p.end_date 
        FROM beneficiaries b 
        JOIN programs p ON b.program_id = p.program_id 
        WHERE " . implode(" AND ", $whereParts) . " 
        ORDER BY $orderBy";

$stmt = $conn->prepare($sql);
$beneficiaries = [];

if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row = array_merge($row, spes_beneficiary_lifecycle_details($conn, (int)$row['beneficiary_id']));
        $beneficiaries[] = $row;
    }
    $stmt->close();
}

$beneficiaries = enrich_reporting_rows($conn, $beneficiaries);

$dateGenerated = date("F d, Y h:i A");
$safeProgramName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $program_name);
$filename = "Beneficiaries_" . $safeProgramName . "_" . date("Ymd");
$reportTitle = getReportProgramTitle($program_name);
$subtitle = htmlspecialchars(getReportLocationLabel($barangay));

if ($format === 'xlsx') {
    if (stripos($program_name, 'SPES') !== false || stripos($program_name, 'TUPAD') !== false || stripos($program_name, 'MSME') !== false) output_official_template_report($program_name, $beneficiaries, $barangay, $_GET['columns'] ?? null);
    $requestedColumns = isset($_GET['columns']) && is_array($_GET['columns']) ? $_GET['columns'] : [];
    $selectedColumns = getSelectedReportColumns($program_name, $requestedColumns);
    $subtitleParts = [];
    if ($program_id > 0) $subtitleParts[] = 'Selected batch';
    $subtitleParts[] = getReportLocationLabel($barangay);
    if ($availment !== 'All') $subtitleParts[] = 'Status: ' . ($availment === 'Not Yet Availed' ? 'Waiting for Requirements' : $availment);
    if ($business_nature !== 'All') $subtitleParts[] = 'Vendor Type: ' . $business_nature;
    if ($spes_group !== 'all') $subtitleParts[] = $spes_group === 'graduate' ? 'SPES Graduates' : 'SPES Babies';
    outputNativeXlsxReport($reportTitle, $selectedColumns, $beneficiaries, implode(' | ', $subtitleParts));
}
