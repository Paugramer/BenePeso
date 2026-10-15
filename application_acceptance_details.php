<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/application_acceptance_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!auth_first_active_role(['admin'])) { http_response_code(403); echo json_encode(['error'=>'Administrator access required.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }
$id = (int)($_GET['id'] ?? 0);
try {
    $stmt = $conn->prepare('SELECT b.beneficiary_id,b.full_name,b.approval_status,p.program_name,p.requirements FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id WHERE b.beneficiary_id=?');
    $stmt->bind_param('i', $id); $stmt->execute(); $record = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$record || $record['approval_status'] !== 'Pending') { http_response_code(409); echo json_encode(['error'=>'This application is no longer pending. Refresh and try again.']); exit; }
    echo json_encode(['name'=>$record['full_name'], 'program'=>$record['program_name'], 'requirements'=>acceptance_notice_requirements($conn,$record), 'today'=>document_submission_today()]);
} catch (Throwable $error) {
    error_log('Acceptance notice lookup failed: '.$error->getMessage());
    http_response_code(503); echo json_encode(['error'=>'Unable to load requirements. Close this window and try again.']);
}
