<?php
require_once __DIR__.'/application_requirements_helper.php';
function document_submission_today(): string {return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');}
function document_submission_deadline_valid(string $deadline): bool {
 $date=DateTimeImmutable::createFromFormat('!Y-m-d',$deadline,new DateTimeZone('Asia/Manila'));
 return $date && $date->format('Y-m-d')===$deadline && $deadline>=document_submission_today();
}
function ensure_document_submission_schema(mysqli $conn): void {
 $conn->query(file_get_contents(__DIR__.'/database_update_document_submissions.sql'));
 $column=$conn->query("SHOW COLUMNS FROM application_document_submissions LIKE 'resubmission_reason'");
 if(!$column->num_rows)$conn->query("ALTER TABLE application_document_submissions ADD COLUMN resubmission_reason VARCHAR(60) NOT NULL DEFAULT ''");
}
function save_document_submission(mysqli $conn,int $id,bool $resubmission,string $deadline,string $note,string $reason=''): void {
 $now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
 $received=$now;
 if($resubmission){$lookup=$conn->prepare('SELECT date_availed FROM beneficiaries WHERE beneficiary_id=?');$lookup->bind_param('i',$id);$lookup->execute();$existing=$lookup->get_result()->fetch_assoc()['date_availed'] ?? ''; $lookup->close();if($existing)$received=$existing.' 00:00:00';}
 $due=$resubmission?$deadline:null;$requested=$resubmission?$now:null;
 $sql=$resubmission
 ? 'INSERT INTO application_document_submissions (beneficiary_id,received_at,resubmission_deadline,resubmission_note,requested_at,resubmission_reason) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE resubmission_deadline=VALUES(resubmission_deadline),resubmission_note=VALUES(resubmission_note),requested_at=VALUES(requested_at),resubmission_reason=VALUES(resubmission_reason)'
 : "INSERT INTO application_document_submissions (beneficiary_id,received_at) VALUES (?,?) ON DUPLICATE KEY UPDATE received_at=VALUES(received_at),resubmission_deadline=NULL,resubmission_note='',requested_at=NULL,resubmission_reason=''";
 $s=$conn->prepare($sql);if($resubmission)$s->bind_param('isssss',$id,$received,$due,$note,$requested,$reason);else $s->bind_param('is',$id,$now);$s->execute();$s->close();
 if(!$resubmission){
  if(application_requirements_table_available($conn,'application_requirement_reviews')){$s=$conn->prepare("UPDATE application_requirement_reviews SET review_status='Received' WHERE beneficiary_id=? AND review_status IN ('Missing','Needs Resubmission','Invalid Document')");$s->bind_param('i',$id);$s->execute();$s->close();}
  if(application_requirements_table_available($conn,'beneficiary_documents')){$s=$conn->prepare("UPDATE beneficiary_documents SET verification_status='Pending',reviewer_note=NULL,reviewed_by_role=NULL,reviewed_by_id=NULL,reviewed_at=NULL WHERE beneficiary_id=? AND verification_status='Needs Resubmission'");$s->bind_param('i',$id);$s->execute();$s->close();}
 }
}

function document_submission_is_pending(mysqli $conn,int $id): bool {
 try {$s=$conn->prepare('SELECT requested_at FROM application_document_submissions WHERE beneficiary_id=?');$s->bind_param('i',$id);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();return !empty($row['requested_at']);}catch(Throwable $e){return false;}
}

function document_resubmission_reasons(): array {return ['Missing Requirement','Invalid Document','Other'];}
function document_submission_details(mysqli $conn,int $id): array {
 try {$s=$conn->prepare('SELECT * FROM application_document_submissions WHERE beneficiary_id=?');$s->bind_param('i',$id);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();return $row ?: [];}catch(Throwable $e){return [];}
}
function request_document_resubmission(mysqli $conn,array $ids,string $reason,string $instructions,string $deadline,string $role,int $actor,string $program): array {
 if(!in_array($reason,document_resubmission_reasons(),true)||trim($instructions)===''||mb_strlen($instructions)>2500||!document_submission_deadline_valid($deadline))throw new InvalidArgumentException('Choose a reason, specify the documents to resubmit, and set a valid deadline.');
 if(!$ids||count($ids)>500)throw new InvalidArgumentException('Select between 1 and 500 applications.');
 foreach($ids as $id)if(!is_int($id)||$id<1)throw new InvalidArgumentException('Invalid application selection.');
 $ids=array_values(array_unique($ids));sort($ids);$sql='SELECT b.beneficiary_id,b.approval_status,b.availment_status,p.program_name FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id WHERE b.beneficiary_id IN ('.implode(',',$ids).') ORDER BY b.beneficiary_id FOR UPDATE';$rows=$conn->query($sql)->fetch_all(MYSQLI_ASSOC);
 if(count($rows)!==count($ids))throw new InvalidArgumentException('Some selected applications no longer exist.');
 foreach($rows as $r)if($r['approval_status']!=='Approved'||$r['availment_status']!=='Requirements Received'||$r['program_name']!==$program)throw new InvalidArgumentException('Select accepted applications with Documents Submitted in this program.');
 foreach($ids as $id)save_document_submission($conn,$id,true,$deadline,trim($instructions),$reason);
 return $ids;
}

function document_submission_reason_available(mysqli $conn): bool {
 try{return $conn->query("SHOW COLUMNS FROM application_document_submissions LIKE 'resubmission_reason'")->num_rows>0;}catch(Throwable $e){return false;}
}
