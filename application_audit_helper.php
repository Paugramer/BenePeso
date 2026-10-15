<?php
/** Explicit role attribution for application events, including multi-role sessions. */
function recordApplicationActivity(mysqli $conn, string $role, int $actor, string $action, string $description, string $target = 'Beneficiary Import'): void
{
    if (!in_array($role, ['admin', 'peso_staff'], true) || $actor <= 0) {
        throw new InvalidArgumentException('Invalid application audit actor.');
    }
    $staffId = null;
    $name = trim((string)($_SESSION['admin_name'] ?? '')) ?: 'PESO Vinzons';
    $actorRole = 'Administrator';
    if ($role === 'peso_staff') {
        $staffId = $actor;
        $actorRole = 'PESO Staff';
        $name = 'PESO Staff';
        $lookup = $conn->prepare('SELECT first_name, last_name FROM peso_staff WHERE staff_id = ? LIMIT 1');
        $lookup->bind_param('i', $actor);
        $lookup->execute();
        $record = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if ($record) $name = trim($record['first_name'] . ' ' . $record['last_name']) ?: $name;
    }
    $module = 'Beneficiaries';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $statement = $conn->prepare('INSERT INTO activity_logs (staff_id,actor_name,actor_role,module_name,action_type,target_name,description,ip_address,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
    if (!$statement) throw new RuntimeException('Application audit could not be prepared.');
    $statement->bind_param('isssssss', $staffId, $name, $actorRole, $module, $action, $target, $description, $ip);
    if (!$statement->execute()) throw new RuntimeException('Application audit could not be saved.');
    $statement->close();
}

/** Rejected imports have no data transaction; never expose a logging failure to the user. */
function recordRejectedApplicationImport(mysqli $conn, string $role, int $actor, int $batch, string $reason): void
{
    try {
        recordApplicationActivity($conn, $role, $actor, 'Import Rejected', 'Batch ' . $batch . ': ' . $reason);
    } catch (Throwable $error) {
        error_log('Application import audit could not be saved.');
    }
}
