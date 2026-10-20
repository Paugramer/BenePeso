<?php
require_once __DIR__ . '/application_requirements_helper.php';
require_once __DIR__ . '/beneficiary_lifecycle_helper.php';

function requirements_report_review_status(array $items, array $submission): string
{
    if (!empty($submission['requested_at'])) return 'Needs Resubmission';
    foreach ($items as $item) {
        if (in_array($item['status'], ['Missing', 'Needs Resubmission', 'Invalid Document'], true)) return 'Needs Resubmission';
    }
    if (application_requirements_are_verified($items)) return 'Verified / Complete';
    foreach ($items as $item) {
        if (!in_array($item['status'], ['Not Reviewed', 'Pending'], true)) return 'Under Review';
    }
    return 'Not Reviewed';
}

function requirements_report_columns(): array
{
    return [
        ['key'=>'full_name', 'label'=>'Beneficiary Name'],
        ['key'=>'barangay', 'label'=>'Barangay'],
        ['key'=>'program_name', 'label'=>'Program'],
        ['key'=>'program_code', 'label'=>'Batch'],
        ['key'=>'submitted_on', 'label'=>'Last Recorded Submission'],
        ['key'=>'review_status', 'label'=>'Requirements Review'],
        ['key'=>'checklist_summary', 'label'=>'Verified Requirements'],
        ['key'=>'progress_label', 'label'=>'Program Status'],
        ['key'=>'followup_deadline', 'label'=>'Resubmission Deadline'],
        ['key'=>'submission_notes', 'label'=>'Follow-up Instructions'],
    ];
}

/** Read-only dataset shared by the list, all-row print view and Excel export. */
function requirements_report_rows(mysqli $conn, array $filters): array
{
    $hasSubmissions = application_requirements_table_available($conn, 'application_document_submissions');
    $join = $hasSubmissions ? 'LEFT JOIN application_document_submissions ds ON ds.beneficiary_id=b.beneficiary_id' : '';
    $fields = $hasSubmissions
        ? 'ds.received_at,ds.requested_at,ds.resubmission_deadline,ds.resubmission_note'
        : 'NULL AS received_at,NULL AS requested_at,NULL AS resubmission_deadline,NULL AS resubmission_note';
    // A later milestone alone is not evidence that a submission date was recorded.
    // Legacy Documents Submitted records remain visible even without the helper row.
    $evidence = "(b.approval_status='Approved' AND b.availment_status='Requirements Received')";
    if ($hasSubmissions) $evidence .= " OR (ds.received_at IS NOT NULL AND ds.received_at > '1000-01-01')";
    $where = ['(' . $evidence . ')']; $params = []; $types = '';
    foreach (['program_name'=>'p.program_name','program_id'=>'b.program_id','barangay'=>'b.barangay'] as $key=>$column) {
        $value = $filters[$key] ?? '';
        if ($value === '' || $value === 'All' || ($key === 'program_id' && (int)$value <= 0)) continue;
        $where[] = $column . '=?'; $params[] = $value; $types .= 's';
    }
    if (($filters['search'] ?? '') !== '') {
        $where[] = '(b.full_name LIKE ? OR b.barangay LIKE ? OR p.program_code LIKE ?)';
        $like = '%' . $filters['search'] . '%'; array_push($params, $like, $like, $like); $types .= 'sss';
    }
    $stmt = $conn->prepare('SELECT b.*,p.program_name,p.program_code,p.requirements,' . $fields . '
        FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id ' . $join . '
        WHERE ' . implode(' AND ', $where) . ' ORDER BY p.program_name,p.program_code,b.barangay,b.full_name,b.beneficiary_id');
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute(); $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $rows = [];
    foreach ($candidates as $row) {
        $items = application_requirement_items($conn, $row);
        $row['review_status'] = requirements_report_review_status($items, $row);
        if (($filters['review_status'] ?? 'All') !== 'All' && $row['review_status'] !== $filters['review_status']) continue;
        $recorded = (string)($row['received_at'] ?? '');
        if ($recorded === '' && $row['availment_status'] === 'Requirements Received') $recorded = (string)($row['date_availed'] ?? '');
        $row['submitted_on'] = preg_match('/^\d{4}-\d{2}-\d{2}/', $recorded) && substr($recorded,0,10) > '1000-01-01'
            ? substr($recorded,0,10) : 'Not recorded';
        $verified = count(array_filter($items, static fn($item)=>in_array($item['status'],['Verified','Not Applicable'],true)));
        $row['checklist_summary'] = $items ? $verified . ' / ' . count($items) : 'No checklist items';
        $row['progress_label'] = beneficiary_lifecycle_label((string)$row['availment_status']);
        $row['followup_deadline'] = !empty($row['requested_at']) ? (string)($row['resubmission_deadline'] ?? '') : '';
        $row['submission_notes'] = !empty($row['requested_at']) ? (string)($row['resubmission_note'] ?? '') : '';
        $rows[] = $row;
    }
    return $rows;
}
