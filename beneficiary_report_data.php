<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/spes_lifecycle_helper.php';
require_once __DIR__ . '/historical_report_helper.php';
require_once __DIR__ . '/report_columns.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');

$activeRole = auth_first_active_role(['admin', 'peso_staff']);
if ($activeRole === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Your session has expired. Please sign in again.']);
    exit;
}
$isAdmin = $activeRole === 'admin';

$programName = trim((string)($_GET['program_name'] ?? ''));
if ($programName === '' || mb_strlen($programName) > 120) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Choose a valid program before generating a report.']);
    exit;
}

function report_program_column_exists(mysqli $conn, string $column): bool
{
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `programs` LIKE '$safeColumn'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

$programDateColumns = '';
if (report_program_column_exists($conn, 'start_date')) $programDateColumns .= ', p.start_date';
if (report_program_column_exists($conn, 'end_date')) $programDateColumns .= ', p.end_date';

$approvalClause = ' AND ' . historical_record_condition();
$sql = "SELECT b.*, p.program_code, p.program_name$programDateColumns
        FROM beneficiaries b
        JOIN programs p ON b.program_id = p.program_id
        WHERE p.program_name = ?$approvalClause
        ORDER BY b.created_at DESC, b.beneficiary_id DESC";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'The report data could not be prepared.']);
    exit;
}

$stmt->bind_param('s', $programName);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) $rows[] = $row;
$stmt->close();

if (stripos($programName, 'SPES') !== false && $rows) {
    $rows = spes_enrich_rows($conn, $rows);
}

$rows = enrich_reporting_rows($conn, $rows);
$reportColumns=official_report_columns($programName);$previewRows=[];foreach($rows as $row){$values=[];foreach($reportColumns as $column)$values[]=official_report_value($row,$column['key']);$previewRows[]=['batch_id'=>(int)$row['program_id'],'batch'=>$row['program_code'],'name'=>trim(implode(' ',array_filter([$row['first_name'] ?? '',$row['middle_name'] ?? '',$row['last_name'] ?? '']))),'barangay'=>$row['barangay'],'status'=>$row['approval_status']==='Rejected'?'Rejected':$row['availment_status'],'classification'=>$row['spes_lifecycle'] ?? 'none','values'=>$values];}
echo json_encode(['ok' => true, 'records' => $rows,'report_columns'=>$reportColumns,'report_rows'=>$previewRows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
