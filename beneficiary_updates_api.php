<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!auth_activate_role('user') || !auth_role_account_is_active('user')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'items' => []]);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$email = '';
$userStmt = $conn->prepare('SELECT email FROM users WHERE user_id = ? LIMIT 1');
if ($userStmt) {
    $userStmt->bind_param('i', $userId);
    $userStmt->execute();
    $email = trim((string)($userStmt->get_result()->fetch_assoc()['email'] ?? ''));
    $userStmt->close();
}

$sql = "SELECT b.beneficiary_id, b.approval_status, b.availment_status,
               b.approval_note, b.date_availed, b.date_completed,
               b.created_at, b.updated_at, p.program_name, p.program_code
        FROM beneficiaries b
        JOIN programs p ON p.program_id = b.program_id
        WHERE b.user_id = ? OR (b.user_id IS NULL AND b.email = ?)
        ORDER BY COALESCE(b.updated_at, b.created_at) DESC, b.beneficiary_id DESC
        LIMIT 12";
$stmt = $conn->prepare($sql);
$items = [];

if ($stmt) {
    $stmt->bind_param('is', $userId, $email);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $approval = trim((string)($row['approval_status'] ?? 'Pending')) ?: 'Pending';
        $availment = trim((string)($row['availment_status'] ?? 'Not Yet Availed')) ?: 'Not Yet Availed';
        $program = trim((string)($row['program_name'] ?? 'PESO Program')) ?: 'PESO Program';
        $tone = 'info';
        $headline = 'Application received';
        $message = 'Your application is in the PESO review queue.';
        $nextAction = 'Keep your registered email active while the office reviews your information.';

        if (strcasecmp($approval, 'Rejected') === 0) {
            $tone = 'danger';
            $headline = 'Application not approved';
            $message = trim((string)($row['approval_note'] ?? '')) ?: 'The office completed its eligibility review.';
            $nextAction = 'Open your program details or contact PESO Vinzons if you need clarification.';
        } elseif (strcasecmp($approval, 'Approved') === 0) {
            $tone = 'success';
            $headline = 'Application approved';
            $message = 'Your eligibility review has been completed successfully.';
            $nextAction = 'Review the program requirements and wait for the next official instruction.';

            $statusMessages = [
                'Requirements Received' => ['Documents recorded', 'Your submitted requirements are now recorded.', 'Wait for the office to confirm the next program activity.'],
                'Orientation' => ['Verification passed - orientation scheduled', 'Your submitted requirements passed PESO document verification.', 'Check the schedule below and prepare to attend the orientation.'],
                'Examination' => ['Examination scheduled', 'Your SPES application is ready for examination.', 'Check the schedule below and attend the face-to-face examination.'],
                'Exam Passed' => ['Examination passed', 'You passed the recorded SPES examination.', 'Wait for the official orientation schedule and instructions from PESO Vinzons.'],
                'Exam Failed' => ['Examination result recorded', 'The examination requirement was not passed for this batch.', 'Contact PESO Vinzons if you need clarification about the result.'],
                'Ongoing' => ['Program participation ongoing', 'Your participation is currently active.', 'Continue following the official program schedule and instructions.'],
                'Salary Distribution' => ['Distribution scheduled', 'Your record has moved to salary distribution.', 'Check the recorded date and follow the office distribution instructions.'],
                'Completed' => ['Program completed', 'Your participation has been marked completed.', 'No further action is required unless your record needs correction.'],
                'Not Qualified' => ['Program status updated', 'Your record is marked not qualified for this program stage.', 'Contact PESO Vinzons if you need clarification.'],
                'Cancelled' => ['Application cancelled', 'This application is no longer active.', 'You may review other open programs when available.'],
            ];

            if (isset($statusMessages[$availment])) {
                [$headline, $message, $nextAction] = $statusMessages[$availment];
            }
            if (in_array($availment, ['Exam Failed', 'Not Qualified', 'Cancelled'], true)) {
                $tone = 'danger';
            }
        }

        $eventDate = (string)($row['updated_at'] ?: $row['created_at']);
        $scheduleDate = '';
        if ($availment === 'Completed') {
            $scheduleDate = (string)($row['date_completed'] ?? '');
        } elseif (in_array($availment, ['Orientation', 'Examination', 'Salary Distribution', 'Ongoing'], true)) {
            $scheduleDate = (string)($row['date_availed'] ?? '');
        }

        $signature = implode(':', [
            (int)$row['beneficiary_id'],
            strtolower($approval),
            strtolower($availment),
            $eventDate,
        ]);

        $items[] = [
            'id' => hash('sha256', $signature),
            'program' => $program,
            'batch' => (string)($row['program_code'] ?? ''),
            'headline' => $headline,
            'message' => $message,
            'next_action' => $nextAction,
            'approval' => $approval,
            'availment' => $availment,
            'tone' => $tone,
            'schedule_date' => $scheduleDate,
            'updated_at' => $eventDate,
            'href' => 'profile.php#my-programs',
        ];
    }
    $stmt->close();
}

$applicationSummary = $items[0] ?? null;

// Open batches are shared service announcements. Staff proposals appear only
// after admin approval, so beneficiaries never receive a notice for drafts.
$programSql = "SELECT program_id, program_name, program_code, start_date, end_date,
                      status, created_at, updated_at
                 FROM programs
                WHERE approval_status = 'Approved'
                  AND end_date >= CURDATE()
                  AND status IN ('Upcoming', 'Ongoing', 'Active')
                ORDER BY COALESCE(updated_at, created_at) DESC, program_id DESC
                LIMIT 5";
$programResult = $conn->query($programSql);
if ($programResult) {
    while ($programRow = $programResult->fetch_assoc()) {
        $programName = trim((string)($programRow['program_name'] ?? 'PESO Program')) ?: 'PESO Program';
        $programCode = trim((string)($programRow['program_code'] ?? ''));
        $eventDate = (string)($programRow['updated_at'] ?: $programRow['created_at']);
        $startDate = (string)($programRow['start_date'] ?? '');
        $endDate = (string)($programRow['end_date'] ?? '');
        $schedule = ($startDate && $endDate)
            ? date('M j, Y', strtotime($startDate)) . ' to ' . date('M j, Y', strtotime($endDate))
            : 'Schedule available in program details';

        $items[] = [
            'id' => hash('sha256', implode(':', ['program', (int)$programRow['program_id'], strtolower((string)$programRow['status']), $eventDate])),
            'kind' => 'program',
            'program' => $programName,
            'batch' => $programCode,
            'headline' => 'New approved batch available',
            'message' => $schedule,
            'next_action' => 'Review the eligibility rules, available slots, and batch schedule before applying.',
            'approval' => '',
            'availment' => 'Open program',
            'tone' => 'opportunity',
            'schedule_date' => $startDate,
            'updated_at' => $eventDate,
            'href' => 'programs.php?program_id=' . (int)$programRow['program_id'],
        ];
    }
}

usort($items, static function (array $left, array $right): int {
    return strtotime((string)($right['updated_at'] ?? '')) <=> strtotime((string)($left['updated_at'] ?? ''));
});
$items = array_slice($items, 0, 12);

echo json_encode([
    'ok' => true,
    'items' => $items,
    'summary' => $applicationSummary,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
