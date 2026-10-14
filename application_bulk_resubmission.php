<?php
require_once __DIR__.'/auth.php';require_once __DIR__.'/db.php';require_once __DIR__.'/document_submission_helper.php';require_once __DIR__.'/email_helper.php';require_once __DIR__.'/functions.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
function rs_error(int $code,string $message): void {http_response_code($code);echo json_encode(['error'=>$message]);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')rs_error(405,'Use the resubmission form.');
$payload=json_decode(file_get_contents('php://input'),true);
if(!is_array($payload)||!is_array($payload['ids']??null))rs_error(400,'Invalid request.');
foreach(['reason','instructions','deadline','role','program','csrf_token'] as $field)if(!is_string($payload[$field]??null))rs_error(400,'Invalid request.');
$role=auth_first_active_role(in_array($payload['role'],['admin','peso_staff'],true)?[$payload['role']]:[]);
if(!$role||!auth_verify_csrf($payload['csrf_token']))rs_error(403,'Your session expired. Refresh and try again.');
$actor=(int)$_SESSION[auth_role_id_key($role)];
try{ensure_document_submission_schema($conn);$conn->begin_transaction();$ids=request_document_resubmission($conn,$payload['ids'],$payload['reason'],$payload['instructions'],$payload['deadline'],$role,$actor,$payload['program']);logActivity($conn,$actor,'Beneficiaries','Request Resubmission','Beneficiary Record','Requested document resubmission for '.count($ids).' selected applications.');$conn->commit();}
catch(Throwable $e){$conn->rollback();error_log('Bulk resubmission: '.$e->getMessage());rs_error($e instanceof InvalidArgumentException?422:503,$e instanceof InvalidArgumentException?$e->getMessage():'Resubmission requests could not be saved. Please try again or contact the administrator.');}
@set_time_limit(300);
$sent=0;foreach($ids as $id){$error=null;$message=$payload['reason']."\n".$payload['instructions']."\nResubmission deadline: ".(new DateTimeImmutable($payload['deadline']))->format('F j, Y');try{if(sendBENEPESOStatusEmail($conn,$id,'Requirements Resubmission',$error,$message))$sent++;}catch(Throwable $e){error_log('Resubmission email delivery failed for '.$id);}}
echo json_encode(['ok'=>true,'count'=>count($ids),'emails_sent'=>$sent,'email_failures'=>count($ids)-$sent,'reason'=>$payload['reason'],'deadline'=>$payload['deadline'],'instructions'=>$payload['instructions']]);
