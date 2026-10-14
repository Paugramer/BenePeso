<?php
require_once __DIR__.'/historical_report_helper.php';
/** Caller supplies the transaction so all selected records update together. */
function apply_application_remarks(mysqli $conn, array $ids, string $remarks, string $role, int $actor): int {
    foreach ($ids as $value) if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value <= 0) throw new InvalidArgumentException('Choose valid applications.');
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0)));
    if (!$ids || count($ids)>500 || mb_strlen($remarks)>3000) throw new InvalidArgumentException('Select 1–500 applications and enter at most 3000 characters.');
    $result=$conn->query("SELECT beneficiary_id FROM beneficiaries b WHERE beneficiary_id IN (".implode(',',$ids).") AND approval_status='Approved' AND NOT ".historical_record_condition()." FOR UPDATE");
    if ($result->num_rows!==count($ids)) throw new InvalidArgumentException('Select accepted, active applications only. No remarks were changed.');
    $stmt=$conn->prepare('INSERT INTO beneficiary_reporting_details (beneficiary_id,report_remarks,updated_by_role,updated_by_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE report_remarks=VALUES(report_remarks),updated_by_role=VALUES(updated_by_role),updated_by_id=VALUES(updated_by_id)');
    foreach($ids as $id) { $stmt->bind_param('issi',$id,$remarks,$role,$actor);$stmt->execute(); }
    $stmt->close();return count($ids);
}
