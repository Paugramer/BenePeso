<?php

/**
 * Detect whether the additive account-ownership migration is available.
 * Production deployments may briefly run the new PHP code before the SQL
 * migration is applied, so activity logging must remain non-blocking.
 */
function benepeso_activity_log_supports_user_id(mysqli $conn): bool
{
    static $supportByConnection = [];
    $connectionId = spl_object_id($conn);
    if (array_key_exists($connectionId, $supportByConnection)) {
        return $supportByConnection[$connectionId];
    }

    try {
        $result = $conn->query("SHOW COLUMNS FROM activity_logs LIKE 'user_id'");
        $supported = $result instanceof mysqli_result && $result->num_rows === 1;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } catch (Throwable $error) {
        error_log('BENEPESO activity-log schema check failed: ' . $error->getMessage());
        $supported = false;
    }

    $supportByConnection[$connectionId] = $supported;
    return $supported;
}

/**
 * Record a beneficiary event without allowing an audit-write problem to break
 * login, logout, profile, or program workflows. New schemas receive user_id;
 * older schemas use the legacy shape until the migration is applied.
 */
function benepeso_log_user_activity(
    mysqli $conn,
    int $userId,
    string $actorName,
    string $module,
    string $action,
    string $target,
    string $description
): bool {
    try {
        if (benepeso_activity_log_supports_user_id($conn)) {
            $stmt = $conn->prepare(
                "INSERT INTO activity_logs
                    (user_id, actor_name, actor_role, module_name, action_type, target_name, description, created_at)
                 VALUES (?, ?, 'Registered User', ?, ?, ?, ?, NOW())"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('isssss', $userId, $actorName, $module, $action, $target, $description);
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO activity_logs
                    (actor_name, actor_role, module_name, action_type, target_name, description, created_at)
                 VALUES (?, 'Registered User', ?, ?, ?, ?, NOW())"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sssss', $actorName, $module, $action, $target, $description);
        }

        $saved = $stmt->execute();
        $stmt->close();
        return $saved;
    } catch (Throwable $error) {
        error_log('BENEPESO beneficiary activity log failed: ' . $error->getMessage());
        return false;
    }
}
