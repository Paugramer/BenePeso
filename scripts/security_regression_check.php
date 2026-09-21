<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/db.php';
require dirname(__DIR__) . '/activity_log_helper.php';

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
        "SELECT DISTINCT u.user_id, u.email, u.barangay, b.program_id, b.full_name
           FROM users u
           JOIN beneficiaries b ON b.user_id = u.user_id
           JOIN programs p ON p.program_id = b.program_id
          WHERE COALESCE(u.status, 'Active') <> 'Banned'
            AND p.approval_status = 'Approved'
            AND TRIM(COALESCE(b.full_name, '')) <> ''
            AND (UPPER(p.program_name) LIKE '%TUPAD%'
              OR UPPER(p.program_name) LIKE '%SPES%'
              OR UPPER(p.program_name) LIKE '%MSME%')
          ORDER BY u.user_id LIMIT 1"
    );
    $user = $userResult ? $userResult->fetch_assoc() : null;
    security_check(is_array($user), 'No active beneficiary account is available for the regression check.');

    $userId = (int)$user['user_id'];
    $email = (string)$user['email'];
    $barangay = (string)$user['barangay'];
    $programId = (int)$user['program_id'];
    $beneficiaryName = (string)$user['full_name'];
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

    $programAccess = $conn->prepare(
        "SELECT 1
           FROM beneficiaries own
           JOIN programs p ON p.program_id = own.program_id
          WHERE own.program_id = ?
            AND (own.user_id = ? OR (own.user_id IS NULL AND own.email = ?))
            AND p.approval_status = 'Approved'
          LIMIT 1"
    );
    security_check($programAccess !== false, 'The applied-program access query could not be prepared.');
    $programAccess->bind_param('iis', $programId, $userId, $email);
    security_check($programAccess->execute(), 'The applied-program access query failed.');
    security_check($programAccess->get_result()->num_rows === 1, 'The user cannot access their own applied program.');
    $programAccess->close();

    $verification = $conn->prepare(
        "SELECT b.barangay, b.program_id
           FROM beneficiaries b
           JOIN programs p ON b.program_id = p.program_id
          WHERE b.barangay = ?
            AND p.approval_status = 'Approved'
            AND LOWER(TRIM(b.full_name)) = LOWER(TRIM(?))
            AND b.program_id = ?"
    );
    security_check($verification !== false, 'The scoped verification query could not be prepared.');
    $verification->bind_param('ssi', $barangay, $beneficiaryName, $programId);
    security_check($verification->execute(), 'The scoped verification query failed.');
    $verificationResult = $verification->get_result();
    security_check($verificationResult->num_rows >= 1, 'The scoped verification lookup found no matching record.');
    while ($row = $verificationResult->fetch_assoc()) {
        security_check(strcasecmp((string)$row['barangay'], $barangay) === 0, 'A record outside the user barangay escaped the verification scope.');
        security_check((int)$row['program_id'] === $programId, 'A record outside the selected applied program escaped the verification scope.');
    }
    $verification->close();

    // Deployment compatibility: PHP may be released before the additive SQL
    // migration reaches production. A temporary legacy-shaped table confirms
    // beneficiary login/activity logging still succeeds without user_id.
    $conn->query(
        "CREATE TEMPORARY TABLE activity_logs (
            log_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            actor_name VARCHAR(255) NOT NULL,
            actor_role VARCHAR(100) NOT NULL,
            module_name VARCHAR(100) NOT NULL,
            action_type VARCHAR(100) NOT NULL,
            target_name VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            created_at DATETIME NOT NULL
        )"
    );
    security_check(
        benepeso_log_user_activity($conn, $userId, $actorName, 'Auth', 'LOGIN', 'System', $description),
        'The compatibility logger rejected a legacy activity_logs schema.'
    );
    $legacyCount = $conn->query('SELECT COUNT(*) AS total FROM activity_logs');
    security_check((int)$legacyCount->fetch_assoc()['total'] === 1, 'Legacy activity-log compatibility failed.');
    $conn->query('DROP TEMPORARY TABLE activity_logs');

    $conn->rollback();
    echo "BENEPESO security regression checks passed; all test writes were rolled back." . PHP_EOL;
} catch (Throwable $error) {
    $conn->rollback();
    fwrite(STDERR, 'Security regression check failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
