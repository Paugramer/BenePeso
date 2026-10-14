<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/application_remarks_helper.php';
require_once __DIR__.'/functions.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405);header('Allow: POST');echo json_encode(['error'=>'Use the remarks form.']);exit; }
$payload=json_decode(file_get_contents('php://input'),true);
if(!is_array($payload) || !is_array($payload['ids'] ?? null) || !is_string($payload['remarks'] ?? null) || !is_string($payload['csrf_token'] ?? null)) { http_response_code(400);echo json_encode(['error'=>'Invalid request.']);exit; }
$requested=(string)($payload['role'] ?? '');
$role=auth_first_active_role(in_array($requested,['admin','peso_staff'],true)?[$requested]:['admin','peso_staff']);
if(!$role || !auth_verify_csrf($payload['csrf_token'] ?? null)) { http_response_code(403);echo json_encode(['error'=>'Your session expired. Refresh and try again.']);exit; }
try {
    if(!reporting_details_available($conn))$conn->query(file_get_contents(__DIR__.'/database_update_phase2_reporting.sql'));
    $conn->begin_transaction();$actor=(int)$_SESSION[auth_role_id_key($role)];
    $count=apply_application_remarks($conn,(array)($payload['ids'] ?? []),trim((string)($payload['remarks'] ?? '')),$role,$actor);
    logActivity($conn,$actor,'Beneficiaries','Bulk Remarks Update','Beneficiary Records','Updated remarks for '.$count.' selected applications.');
    $conn->commit();echo json_encode(['ok'=>true,'count'=>$count]);
} catch(Throwable $e) {
    $conn->rollback();http_response_code($e instanceof InvalidArgumentException?422:500);
    echo json_encode(['error'=>$e instanceof InvalidArgumentException?$e->getMessage():'Remarks could not be saved. No records were changed.']);
}
