<?php
require_once __DIR__.'/tupad_document_helper.php';
function ensure_application_requirements(mysqli $conn): void {
 ensure_tupad_document_schema($conn);
 $conn->query("CREATE TABLE IF NOT EXISTS application_requirement_reviews (beneficiary_id INT NOT NULL, requirement_key CHAR(64) NOT NULL, requirement_name VARCHAR(500) NOT NULL, review_status VARCHAR(30) NOT NULL, reviewer_note VARCHAR(500) NOT NULL DEFAULT '', reviewed_by_role VARCHAR(20) NOT NULL, reviewed_by_id INT NOT NULL, reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(beneficiary_id,requirement_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function application_requirement_items(mysqli $conn,array $record): array {
 $items=[];$tupad=stripos($record['program_name'],'TUPAD')!==false;
 if($tupad){
  $items['government_id']=['name'=>'Government-issued ID (front and back)','source'=>'tupad'];
  $s=$conn->prepare('SELECT is_pwd,has_work_limitation FROM beneficiary_tupad_details WHERE beneficiary_id=?');$s->bind_param('i',$record['beneficiary_id']);$s->execute();$d=$s->get_result()->fetch_assoc();$s->close();
  if(($d['is_pwd']??'No')==='Yes'||($d['has_work_limitation']??'No')==='Yes')$items['fitness_to_work']=['name'=>'Fitness-to-work certificate','source'=>'tupad'];
 }
 foreach(preg_split('/\r?\n/',(string)($record['requirements']??'')) as $line){
  $line=trim(preg_replace('/^\s*[-?]\s*/u','',$line));
  if($line===''||substr($line,-1)===':')continue;
  if($tupad&&preg_match('/\bID\b/i',$line))continue;
  $key=hash('sha256',$line);$items[$key]=['name'=>$line,'source'=>'general'];
 }
 $s=$conn->prepare('SELECT * FROM application_requirement_reviews WHERE beneficiary_id=?');$s->bind_param('i',$record['beneficiary_id']);$s->execute();foreach($s->get_result() as $r)if(isset($items[$r['requirement_key']]))$items[$r['requirement_key']]+= ['status'=>$r['review_status'],'note'=>$r['reviewer_note']];$s->close();
 if($tupad){$s=$conn->prepare('SELECT * FROM beneficiary_documents WHERE beneficiary_id=?');$s->bind_param('i',$record['beneficiary_id']);$s->execute();foreach($s->get_result() as $r)if(isset($items[$r['document_type']]))$items[$r['document_type']]+= ['status'=>$r['verification_status']==='Pending'?'Not Reviewed':$r['verification_status'],'note'=>$r['reviewer_note']??''];$s->close();}
 foreach($items as &$item)$item+=['status'=>'Not Reviewed','note'=>''];unset($item);return $items;
}
function application_requirements_issue_sql(): string {
 return "(EXISTS (SELECT 1 FROM application_requirement_reviews ar WHERE ar.beneficiary_id=b.beneficiary_id AND ar.review_status IN ('Missing','Needs Resubmission') AND LOCATE(ar.requirement_name,p.requirements)>0) OR EXISTS (SELECT 1 FROM beneficiary_documents bd WHERE bd.beneficiary_id=b.beneficiary_id AND bd.verification_status='Needs Resubmission'))";
}
