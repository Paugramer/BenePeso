<?php
require_once __DIR__.'/auth.php';require_once __DIR__.'/db.php';require_once __DIR__.'/historical_report_helper.php';require_once __DIR__.'/functions.php';require_once __DIR__.'/application_requirements_helper.php';
header('Content-Type: application/json; charset=utf-8');
function rq_fail(int $code,string $message): void {http_response_code($code);echo json_encode(['error'=>$message]);exit;}
$role=auth_first_active_role(['admin','peso_staff']);if(!$role)rq_fail(403,'Authorized PESO reviewers only.');
$id=(int)($_GET['id']??0);$storageReady=ensure_application_requirements($conn);
if(!$storageReady)rq_fail(503,'Requirements checklist is temporarily unavailable. Please contact the system administrator.');
$s=$conn->prepare('SELECT b.beneficiary_id,b.approval_status,b.availment_status,p.program_name,p.requirements FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id WHERE b.beneficiary_id=?');$s->bind_param('i',$id);$s->execute();$record=$s->get_result()->fetch_assoc();$s->close();if(!$record)rq_fail(404,'Application not found.');
$active=application_requirements_review_stage($record);
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!$active)rq_fail(409,'Review requirements only after Documents Submitted. Orientation and later stages have already passed this step.');
 $payload=json_decode(file_get_contents('php://input'),true);if(!is_array($payload)||!is_string($payload['csrf_token']??null)||!auth_verify_csrf($payload['csrf_token']??null))rq_fail(403,'Invalid request token.');
 $entries=$payload['items']??null;if(!is_array($entries)||count($entries)>100)rq_fail(422,'Invalid checklist.');
 $items=application_requirement_items($conn,$record);
 foreach($entries as $key=>$e){if(!isset($items[$key])||!is_array($e)||!is_string($e['status']??null)||!is_string($e['note']??null)||!in_array($e['status'],['Not Reviewed','Missing','Received','Verified','Needs Resubmission','Not Applicable'],true)||mb_strlen($e['note'])>500)rq_fail(422,'Invalid checklist entry.');if(in_array($e['status'],['Missing','Needs Resubmission','Not Applicable'],true)&&trim($e['note'])==='')rq_fail(422,'Explain what is missing, needs resubmission, or is not applicable.');if($items[$key]['source']==='tupad'&&$e['status']==='Not Applicable')rq_fail(422,'Required TUPAD documents cannot be marked Not Applicable.');}
 $conn->begin_transaction();try{
 $s=$conn->prepare("SELECT beneficiary_id FROM beneficiaries b WHERE beneficiary_id=? AND approval_status='Approved' AND availment_status='Requirements Received' AND NOT ".historical_record_condition()." FOR UPDATE");$s->bind_param('i',$id);$s->execute();if(!$s->get_result()->num_rows)throw new RuntimeException('Closed application');$s->close();$actor=(int)$_SESSION[auth_role_id_key($role)];
 foreach($entries as $key=>$e){$status=$e['status'];$note=trim($e['note']);$name=$items[$key]['name'];
 if($items[$key]['source']==='tupad'){$status=in_array($status,['Missing','Needs Resubmission'],true)?'Needs Resubmission':($status==='Verified'?'Verified':'Pending');$s=$conn->prepare('INSERT INTO beneficiary_documents (beneficiary_id,document_type,verification_status,reviewer_note,reviewed_by_role,reviewed_by_id,reviewed_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE verification_status=VALUES(verification_status),reviewer_note=VALUES(reviewer_note),reviewed_by_role=VALUES(reviewed_by_role),reviewed_by_id=VALUES(reviewed_by_id),reviewed_at=NOW()');$s->bind_param('issssi',$id,$key,$status,$note,$role,$actor);}
 else{$s=$conn->prepare('INSERT INTO application_requirement_reviews (beneficiary_id,requirement_key,requirement_name,review_status,reviewer_note,reviewed_by_role,reviewed_by_id) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE review_status=VALUES(review_status),reviewer_note=VALUES(reviewer_note),reviewed_by_role=VALUES(reviewed_by_role),reviewed_by_id=VALUES(reviewed_by_id),reviewed_at=NOW()');$s->bind_param('isssssi',$id,$key,$name,$status,$note,$role,$actor);}$s->execute();$s->close();
 }if(count($entries))logActivity($conn,$actor,'Beneficiaries','Requirements Review','Beneficiary Record','Reviewed requirements for beneficiary ID: '.$id);$conn->commit();}catch(Throwable $e){$conn->rollback();rq_fail(409,'Checklist could not be saved. Refresh and try again.');}
}elseif($_SERVER['REQUEST_METHOD']!=='GET')rq_fail(405,'Method not allowed.');
echo json_encode(['items'=>application_requirement_items($conn,$record),'editable'=>$active,'csrf_token'=>auth_csrf_token()]);
