<?php

function ensure_user_security_metadata_schema(mysqli $conn): bool
{
    $sql = "CREATE TABLE IF NOT EXISTS user_security_metadata (
        user_id INT NOT NULL PRIMARY KEY,
        password_changed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return (bool)$conn->query($sql);
}

function fetch_user_security_metadata(mysqli $conn, int $userId): array
{
    $defaults = ['password_changed_at' => null];
    if (!ensure_user_security_metadata_schema($conn)) return $defaults;

    $stmt = $conn->prepare('SELECT password_changed_at FROM user_security_metadata WHERE user_id = ? LIMIT 1');
    if (!$stmt) return $defaults;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? array_merge($defaults, $row) : $defaults;
}

function record_user_password_change(mysqli $conn, int $userId): bool
{
    if (!ensure_user_security_metadata_schema($conn)) return false;
    $stmt = $conn->prepare("INSERT INTO user_security_metadata (user_id, password_changed_at, updated_at) VALUES (?, NOW(), NOW()) ON DUPLICATE KEY UPDATE password_changed_at = NOW(), updated_at = NOW()");
    if (!$stmt) return false;
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}
