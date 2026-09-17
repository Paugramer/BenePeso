<?php

require_once __DIR__ . '/google_auth_config.php';

const GOOGLE_PENDING_REGISTRATION_TTL = 1800;

function google_auth_ensure_schema(mysqli $conn): bool
{
    return $conn->query(
        "CREATE TABLE IF NOT EXISTS user_auth_identities (
            identity_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            provider VARCHAR(32) NOT NULL,
            provider_subject VARCHAR(255) NOT NULL,
            provider_email VARCHAR(120) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login_at DATETIME DEFAULT NULL,
            PRIMARY KEY (identity_id),
            UNIQUE KEY uq_provider_subject (provider, provider_subject),
            UNIQUE KEY uq_provider_user (provider, user_id),
            KEY idx_identity_user (user_id),
            CONSTRAINT fk_identity_user FOREIGN KEY (user_id)
                REFERENCES users (user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    ) === true;
}

function google_auth_pending_identity(): ?array
{
    $pending = $_SESSION['google_pending_identity'] ?? null;
    if (!is_array($pending) || empty($pending['subject']) || empty($pending['email'])) {
        return null;
    }

    if ((int)($pending['created_at'] ?? 0) < time() - GOOGLE_PENDING_REGISTRATION_TTL) {
        unset($_SESSION['google_pending_identity']);
        return null;
    }

    return $pending;
}

function google_auth_link_user(mysqli $conn, int $userId, array $identity): bool
{
    if (!google_auth_ensure_schema($conn)) {
        return false;
    }

    $provider = 'google';
    $subject = (string)$identity['subject'];
    $email = mb_strtolower(trim((string)$identity['email']));
    $stmt = $conn->prepare(
        "INSERT INTO user_auth_identities
            (user_id, provider, provider_subject, provider_email, last_login_at)
         VALUES (?, ?, ?, ?, NOW())"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('isss', $userId, $provider, $subject, $email);
    try {
        $ok = $stmt->execute();
    } catch (mysqli_sql_exception $error) {
        $ok = false;
    }
    $stmt->close();
    if ($ok) {
        return true;
    }

    // A duplicate is accepted only when this exact provider identity is already
    // linked to this exact beneficiary. This prevents cross-account relinking.
    $existing = $conn->prepare(
        'SELECT identity_id FROM user_auth_identities
         WHERE provider = ? AND provider_subject = ? AND user_id = ? LIMIT 1'
    );
    if (!$existing) {
        return false;
    }
    $existing->bind_param('ssi', $provider, $subject, $userId);
    $existing->execute();
    $identityId = (int)($existing->get_result()->fetch_assoc()['identity_id'] ?? 0);
    $existing->close();
    if ($identityId < 1) {
        return false;
    }

    $touch = $conn->prepare(
        'UPDATE user_auth_identities SET provider_email = ?, last_login_at = NOW() WHERE identity_id = ?'
    );
    if (!$touch) {
        return false;
    }
    $touch->bind_param('si', $email, $identityId);
    $updated = $touch->execute();
    $touch->close();
    return $updated;
}

function google_auth_activate_beneficiary(mysqli $conn, array $user): void
{
    $userId = (int)$user['user_id'];
    $fullName = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']);

    $_SESSION['fail_count'] = 0;
    $_SESSION['lock_until'] = 0;

    $historyStatus = 'success';
    $history = $conn->prepare('INSERT INTO login_history (user_id, status) VALUES (?, ?)');
    if ($history) {
        $history->bind_param('is', $userId, $historyStatus);
        $history->execute();
        $history->close();
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $fullName;
    $_SESSION['user_pic'] = $user['profile_pic'] ?? 'default_avatar.png';
    auth_activate_role('user');
    auth_regenerate_session();

    $description = $fullName . ' logged in securely using Google.';
    $log = $conn->prepare(
        "INSERT INTO activity_logs
            (actor_name, actor_role, module_name, action_type, target_name, description, created_at)
         VALUES (?, 'Registered User', 'Auth', 'LOGIN', 'System', ?, NOW())"
    );
    if ($log) {
        $log->bind_param('ss', $fullName, $description);
        $log->execute();
        $log->close();
    }
}
