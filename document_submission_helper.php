<?php
function document_submission_today(): string {return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');}
function document_submission_deadline_valid(string $deadline): bool {
 $date=DateTimeImmutable::createFromFormat('!Y-m-d',$deadline,new DateTimeZone('Asia/Manila'));
 return $date && $date->format('Y-m-d')===$deadline && $deadline>=document_submission_today();
}
function ensure_document_submission_schema(mysqli $conn): void {
 $conn->query(file_get_contents(__DIR__.'/database_update_document_submissions.sql'));
}
function save_document_submission(mysqli $conn,int $id,bool $resubmission,string $deadline,string $note): void {
 $now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
 $received=$now;
 if($resubmission){$lookup=$conn->prepare('SELECT date_availed FROM beneficiaries WHERE beneficiary_id=?');$lookup->bind_param('i',$id);$lookup->execute();$existing=$lookup->get_result()->fetch_assoc()['date_availed'] ?? ''; $lookup->close();if($existing)$received=$existing.' 00:00:00';}
 $due=$resubmission?$deadline:null;$requested=$resubmission?$now:null;
 $sql=$resubmission
 ? 'INSERT INTO application_document_submissions (beneficiary_id,received_at,resubmission_deadline,resubmission_note,requested_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE resubmission_deadline=VALUES(resubmission_deadline),resubmission_note=VALUES(resubmission_note),requested_at=VALUES(requested_at)'
 : "INSERT INTO application_document_submissions (beneficiary_id,received_at) VALUES (?,?) ON DUPLICATE KEY UPDATE received_at=VALUES(received_at),resubmission_deadline=NULL,resubmission_note='',requested_at=NULL";
 $s=$conn->prepare($sql);if($resubmission)$s->bind_param('issss',$id,$received,$due,$note,$requested);else $s->bind_param('is',$id,$now);$s->execute();$s->close();
}

function document_submission_is_pending(mysqli $conn,int $id): bool {
 try {$s=$conn->prepare('SELECT requested_at FROM application_document_submissions WHERE beneficiary_id=?');$s->bind_param('i',$id);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();return !empty($row['requested_at']);}catch(Throwable $e){return false;}
}
