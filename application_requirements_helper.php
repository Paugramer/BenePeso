<?php
require_once __DIR__.'/tupad_document_helper.php';
function application_requirements_table_available(mysqli $conn, string $table): bool {
 if (!in_array($table,['application_requirement_reviews','beneficiary_documents','beneficiary_tupad_details','application_document_submissions'],true)) return false;
 try { return (bool)$conn->query("SELECT 1 FROM `$table` LIMIT 0"); }
 catch (Throwable $e) { return false; }
}
function ensure_application_requirements(mysqli $conn): bool {
 if (application_requirements_table_available($conn,'application_requirement_reviews')) return true;
 try { return (bool)$conn->query(file_get_contents(__DIR__.'/database_update_requirements_review.sql')); }
 catch (Throwable $e) { error_log('Requirements checklist storage unavailable: '.$e->getMessage()); return false; }
}
function application_requirement_items(mysqli $conn,array $record): array {
 $items=[];$tupad=stripos($record['program_name'],'TUPAD')!==false;
 if($tupad){
  $items['government_id']=['name'=>'Government-issued ID (front and back)','source'=>'tupad'];
  if(application_requirements_table_available($conn,'beneficiary_tupad_details')) {
  $s=$conn->prepare('SELECT is_pwd,has_work_limitation FROM beneficiary_tupad_details WHERE beneficiary_id=?');$s->bind_param('i',$record['beneficiary_id']);$s->execute();$d=$s->get_result()->fetch_assoc();$s->close();
  if(($d['is_pwd']??'No')==='Yes'||($d['has_work_limitation']??'No')==='Yes')$items['fitness_to_work']=['name'=>'Fitness-to-work certificate','source'=>'tupad'];
  }
 }
 foreach(preg_split('/\r?\n/',(string)($record['requirements']??'')) as $line){
  $line=trim(preg_replace('/^\s*[-?]\s*/u','',$line));
  if($line===''||substr($line,-1)===':')continue;
  if($tupad&&preg_match('/\bID\b/i',$line))continue;
  $key=hash('sha256',$line);$items[$key]=['name'=>$line,'source'=>'general'];
 }
 if(application_requirements_table_available($conn,'application_requirement_reviews')) {
 $s=$conn->prepare('SELECT * FROM application_requirement_reviews WHERE beneficiary_id=?');$s->bind_param('i',$record['beneficiary_id']);$s->execute();foreach($s->get_result() as $r)if(isset($items[$r['requirement_key']]))$items[$r['requirement_key']]+= ['status'=>$r['review_status'],'note'=>$r['reviewer_note']];$s->close();
 }
 if($tupad && application_requirements_table_available($conn,'beneficiary_documents')){$s=$conn->prepare('SELECT * FROM beneficiary_documents WHERE beneficiary_id=?');$s->bind_param('i',$record['beneficiary_id']);$s->execute();foreach($s->get_result() as $r)if(isset($items[$r['document_type']]))$items[$r['document_type']]+= ['status'=>$r['verification_status']==='Pending'?'Not Reviewed':$r['verification_status'],'note'=>$r['reviewer_note']??''];$s->close();}
 foreach($items as &$item)$item+=['status'=>'Not Reviewed','note'=>''];unset($item);return $items;
}
function application_requirements_review_stage(array $record): bool {
 return ($record['approval_status'] ?? '') === 'Approved' && ($record['availment_status'] ?? '') === 'Requirements Received';
}
function application_requirements_issue_sql(?mysqli $connection = null): string {
 global $conn;
 $connection = $connection ?? $conn;
 $conditions = [];
 if(application_requirements_table_available($connection,'application_requirement_reviews')) $conditions[] = "EXISTS (SELECT 1 FROM application_requirement_reviews ar WHERE ar.beneficiary_id=b.beneficiary_id AND ar.review_status IN ('Missing','Needs Resubmission') AND LOCATE(CONVERT(ar.requirement_name USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(p.requirements USING utf8mb4) COLLATE utf8mb4_unicode_ci)>0)";
 if(application_requirements_table_available($connection,'beneficiary_documents')) $conditions[] = "EXISTS (SELECT 1 FROM beneficiary_documents bd WHERE bd.beneficiary_id=b.beneficiary_id AND bd.verification_status='Needs Resubmission')";
 if(application_requirements_table_available($connection,'application_document_submissions')) $conditions[] = "EXISTS (SELECT 1 FROM application_document_submissions ds WHERE ds.beneficiary_id=b.beneficiary_id AND ds.requested_at IS NOT NULL)";
 if(!$conditions) return '(1=0)';
 return "(b.approval_status='Approved' AND b.availment_status='Requirements Received' AND (".implode(' OR ',$conditions)."))";
}
