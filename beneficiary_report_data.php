<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/spes_lifecycle_helper.php';
require_once __DIR__ . '/historical_report_helper.php';
require_once __DIR__ . '/report_columns.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405); header('Allow: GET');
    echo json_encode(['ok'=>false,'message'=>'Report data is read-only.']); exit;
}

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

$approvalClause = ' AND ' . report_record_condition($conn);
$submissionEvidence=report_submission_condition($conn);
$sql = "SELECT b.*, ($submissionEvidence) AS report_has_submission, p.program_code, p.program_name$programDateColumns
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
if (($_GET['template_preview'] ?? '') === '1') {
    require_once __DIR__.'/xlsx_report_helper.php';
    require_once __DIR__.'/official_template_export.php';
    $batch=(int)($_GET['program_id'] ?? 0);$barangay=(string)($_GET['report_barangay'] ?? 'All');
    $status=(string)($_GET['report_outcome'] ?? 'All');$group=(string)($_GET['report_spes_group'] ?? 'all');$nature=(string)($_GET['business_nature'] ?? 'All');
    $rows=array_values(array_filter($rows,static function($row)use($batch,$barangay,$status,$group,$nature){
        $outcome=$row['approval_status']==='Rejected'?'Rejected':$row['availment_status'];
        $natureValues=explode(',',str_replace([';',', ',' ,'],[',',',',','],$row['business_nature']??''));
        $natureValues=array_map(static fn($value)=>mb_strtolower(trim($value)),$natureValues);
        return (!$batch||(int)$row['program_id']===$batch)&&($barangay==='All'||$row['barangay']===$barangay)&&($status==='All'||($status==='Documents Submitted'?!empty($row['report_has_submission']):$outcome===$status))&&($group==='all'||($row['spes_lifecycle']??'none')===$group)&&($nature==='All'||in_array(mb_strtolower($nature),$natureValues,true));
    }));
    usort($rows,static fn($a,$b)=>strcasecmp(($barangay==='All'?($a['barangay']??'').' ':'').($a['last_name']??'').' '.($a['first_name']??''),($barangay==='All'?($b['barangay']??'').' ':'').($b['last_name']??'').' '.($b['first_name']??'')));
    try { echo json_encode(['ok'=>true,'count'=>count($rows),'sheets'=>report_template_sheets($programName,$rows,$barangay)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    catch(Throwable $error){error_log('Report template preview: '.$error->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'message'=>'The report preview could not be prepared. Please try again.']);}
    exit;
}

$reportColumns=official_report_columns($programName);$previewRows=[];foreach($rows as $row){$values=[];foreach($reportColumns as $column)$values[]=official_report_value($row,$column['key']);$previewRows[]=['batch_id'=>(int)$row['program_id'],'batch'=>$row['program_code'],'name'=>trim(implode(' ',array_filter([$row['first_name'] ?? '',$row['middle_name'] ?? '',$row['last_name'] ?? '']))),'barangay'=>$row['barangay'],'status'=>$row['approval_status']==='Rejected'?'Rejected':$row['availment_status'],'classification'=>$row['spes_lifecycle'] ?? 'none','values'=>$values];}
echo json_encode(['ok' => true, 'records' => $rows,'report_columns'=>$reportColumns,'report_rows'=>$previewRows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
