<?php
require_once __DIR__ . '/auth_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/spes_lifecycle_helper.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');

$isAdmin = auth_has_role('admin');
$isStaff = auth_has_role('peso_staff');
if (!$isAdmin && !$isStaff) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Your session has expired. Please sign in again.']);
    exit;
}

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

$approvalClause = $isAdmin ? " AND b.approval_status = 'Approved'" : '';
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

echo json_encode(['ok' => true, 'records' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
