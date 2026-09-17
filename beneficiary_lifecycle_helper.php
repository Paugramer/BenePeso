<?php

function beneficiary_lifecycle_label(string $status): string
{
    return [
        'Not Yet Availed' => 'Approved - Awaiting Documents',
        'Requirements Received' => 'Documents Submitted',
        'Examination' => 'Examination Scheduled',
        'Exam Passed' => 'Examination Passed',
        'Orientation' => 'Orientation',
        'Ongoing' => 'Ongoing',
        'Salary Distribution' => 'Salary Distribution',
        'Completed' => 'Completed',
    ][$status] ?? $status;
}

function beneficiary_lifecycle_flow(string $programName, bool $returningSpes = false): array
{
    $program = strtoupper(trim($programName));
    if (str_contains($program, 'SPES')) {
        return $returningSpes
            ? ['Not Yet Availed', 'Requirements Received', 'Orientation', 'Ongoing', 'Completed']
            : ['Not Yet Availed', 'Requirements Received', 'Examination', 'Exam Passed', 'Orientation', 'Ongoing', 'Completed'];
    }
    if (str_contains($program, 'TUPAD')) {
        return ['Not Yet Availed', 'Requirements Received', 'Orientation', 'Ongoing', 'Salary Distribution', 'Completed'];
    }
    return ['Not Yet Availed', 'Requirements Received', 'Orientation', 'Ongoing', 'Completed'];
}

/**
 * Enforces one successful milestone at a time. Negative terminal outcomes can
 * be recorded from any active stage, while SPES examination cannot be skipped.
 */
function beneficiary_validate_status_transition(mysqli $conn, int $beneficiaryId, string $targetStatus): array
{
    $stmt = $conn->prepare('SELECT b.approval_status,b.availment_status,p.program_name FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id WHERE b.beneficiary_id=? LIMIT 1');
    if (!$stmt) return ['allowed' => false, 'message' => 'The beneficiary workflow could not be checked.'];
    $stmt->bind_param('i', $beneficiaryId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$record) return ['allowed' => false, 'message' => 'The beneficiary record was not found.'];

    $current = trim((string)($record['availment_status'] ?? 'Not Yet Availed')) ?: 'Not Yet Availed';
    if (strcasecmp($current, 'Requirements Recieved') === 0) $current = 'Requirements Received';
    if ($targetStatus === $current) return ['allowed' => true, 'message' => '', 'current' => $current];
    if (in_array($targetStatus, ['Not Qualified', 'Cancelled'], true)) return ['allowed' => true, 'message' => '', 'current' => $current];
    if (($record['approval_status'] ?? '') !== 'Approved') {
        return ['allowed' => false, 'message' => 'Approve the application before updating its program progress.'];
    }

    $isSpes = stripos((string)$record['program_name'], 'SPES') !== false;
    $returningSpes = $isSpes && function_exists('spes_beneficiary_is_returning')
        && spes_beneficiary_is_returning($conn, $beneficiaryId);
    $flow = beneficiary_lifecycle_flow((string)$record['program_name'], $returningSpes);
    $currentIndex = array_search($current, $flow, true);
    $targetIndex = array_search($targetStatus, $flow, true);

    // Permit legacy SPES records already left at an examination stage to move
    // into the returning-SPES flow without asking them to take another exam.
    if ($returningSpes && in_array($current, ['Examination', 'Exam Passed'], true) && $targetStatus === 'Orientation') {
        return ['allowed' => true, 'message' => '', 'current' => $current];
    }
    if ($targetStatus === 'Exam Failed' && $current === 'Examination' && !$returningSpes) {
        return ['allowed' => true, 'message' => '', 'current' => $current];
    }
    if ($currentIndex !== false && $targetIndex !== false && $targetIndex === $currentIndex + 1) {
        return ['allowed' => true, 'message' => '', 'current' => $current];
    }

    $next = $currentIndex !== false ? ($flow[$currentIndex + 1] ?? null) : null;
    $message = $next
        ? 'Complete "' . beneficiary_lifecycle_label($next) . '" before moving this beneficiary to "' . beneficiary_lifecycle_label($targetStatus) . '".'
        : 'This status change does not follow the required program flow.';
    if ($isSpes && !$returningSpes && in_array($targetStatus, ['Orientation', 'Ongoing', 'Completed'], true) && $current !== 'Exam Passed') {
        $message = 'SPES applicants must complete the examination and receive an "Examination Passed" result before Orientation.';
    }
    return ['allowed' => false, 'message' => $message, 'current' => $current];
}
