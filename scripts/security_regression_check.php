<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/db.php';

function security_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$conn->begin_transaction();

try {
    $column = $conn->query("SHOW COLUMNS FROM activity_logs LIKE 'user_id'");
    security_check($column && $column->num_rows === 1, 'activity_logs.user_id is missing.');

    $index = $conn->query("SHOW INDEX FROM activity_logs WHERE Key_name = 'idx_activity_user_id'");
    security_check($index && $index->num_rows >= 1, 'The activity user ownership index is missing.');

    $userLookupIndex = $conn->query("SHOW INDEX FROM beneficiaries WHERE Key_name = 'idx_beneficiary_user_program_barangay'");
    security_check($userLookupIndex && $userLookupIndex->num_rows === 3, 'The beneficiary user lookup index is missing or incomplete.');

    $emailLookupIndex = $conn->query("SHOW INDEX FROM beneficiaries WHERE Key_name = 'idx_beneficiary_email_program_barangay'");
    security_check($emailLookupIndex && $emailLookupIndex->num_rows === 3, 'The beneficiary email lookup index is missing or incomplete.');

    $userResult = $conn->query(
        "SELECT user_id, email, barangay FROM users
         WHERE COALESCE(status, 'Active') <> 'Banned'
         ORDER BY user_id LIMIT 1"
    );
    $user = $userResult ? $userResult->fetch_assoc() : null;
    security_check(is_array($user), 'No active beneficiary account is available for the regression check.');

    $userId = (int)$user['user_id'];
    $email = (string)$user['email'];
    $barangay = (string)$user['barangay'];
    $actorName = 'BENEPESO SECURITY TEST';
    $description = 'This rolled-back record verifies account-owned audit events.';

    $insert = $conn->prepare(
        "INSERT INTO activity_logs
            (user_id, actor_name, actor_role, module_name, action_type, target_name, description, created_at)
         VALUES (?, ?, 'Registered User', 'Test', 'TEST', 'System', ?, NOW())"
    );
    security_check($insert !== false, 'The owned activity insert could not be prepared.');
    $insert->bind_param('iss', $userId, $actorName, $description);
    security_check($insert->execute(), 'The owned activity insert failed.');
    $logId = (int)$insert->insert_id;
    $insert->close();

    $owned = $conn->prepare(
        "SELECT COUNT(*) AS total FROM activity_logs
         WHERE log_id = ? AND user_id = ? AND actor_role = 'Registered User'"
    );
    security_check($owned !== false, 'The owned activity lookup could not be prepared.');
    $owned->bind_param('ii', $logId, $userId);
    $owned->execute();
    security_check((int)$owned->get_result()->fetch_assoc()['total'] === 1, 'Owned activity lookup failed.');
    $owned->close();

    $identity = $conn->prepare(
        "SELECT account_id FROM (
            SELECT user_id AS account_id FROM users WHERE email = ? AND user_id <> ?
            UNION ALL SELECT staff_id FROM peso_staff WHERE email = ?
            UNION ALL SELECT admin_id FROM admins WHERE email = ?
        ) duplicate_email LIMIT 1"
    );
    security_check($identity !== false, 'The cross-role identity query could not be prepared.');
    $identity->bind_param('siss', $email, $userId, $email, $email);
    security_check($identity->execute(), 'The cross-role identity query failed.');
    $identity->close();

    $programResult = $conn->query(
        "SELECT program_id FROM programs
         WHERE approval_status = 'Approved'
           AND (UPPER(program_name) LIKE '%TUPAD%'
             OR UPPER(program_name) LIKE '%SPES%'
             OR UPPER(program_name) LIKE '%MSME%')
         ORDER BY program_id LIMIT 1"
    );
    $program = $programResult ? $programResult->fetch_assoc() : null;
    if (is_array($program)) {
        $programId = (int)$program['program_id'];
        $verification = $conn->prepare(
            "SELECT b.user_id, b.email
             FROM beneficiaries b
             JOIN programs p ON b.program_id = p.program_id
             WHERE b.barangay = ?
               AND (b.user_id = ? OR (b.user_id IS NULL AND b.email = ?))
               AND p.approval_status = 'Approved'
               AND (UPPER(p.program_name) LIKE '%TUPAD%'
                 OR UPPER(p.program_name) LIKE '%SPES%'
                 OR UPPER(p.program_name) LIKE '%MSME%')
               AND b.program_id = ?"
        );
        security_check($verification !== false, 'The account-owned verification query could not be prepared.');
        $verification->bind_param('sisi', $barangay, $userId, $email, $programId);
        security_check($verification->execute(), 'The account-owned verification query failed.');
        $verificationResult = $verification->get_result();
        while ($row = $verificationResult->fetch_assoc()) {
            $belongsToUser = (int)$row['user_id'] === $userId;
            $matchesLegacyEmail = $row['user_id'] === null
                && strcasecmp((string)$row['email'], $email) === 0;
            security_check($belongsToUser || $matchesLegacyEmail, 'A foreign beneficiary record escaped the ownership filter.');
        }
        $verification->close();
    }

    $conn->rollback();
    echo "BENEPESO security regression checks passed; all test writes were rolled back." . PHP_EOL;
} catch (Throwable $error) {
    $conn->rollback();
    fwrite(STDERR, 'Security regression check failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
