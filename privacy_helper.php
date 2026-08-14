<?php

const BENEPESO_PRIVACY_NOTICE_VERSION = '2026-08-13';

function record_privacy_acknowledgment(mysqli $conn, int $userId, string $context, ?int $beneficiaryId = null): bool
{
    $ipAddress = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $version = BENEPESO_PRIVACY_NOTICE_VERSION;
    $stmt = $conn->prepare('INSERT INTO privacy_acknowledgments (user_id, beneficiary_id, acknowledgment_context, notice_version, ip_address, user_agent, acknowledged_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    if (!$stmt) return false;
    $stmt->bind_param('iissss', $userId, $beneficiaryId, $context, $version, $ipAddress, $userAgent);
    $saved = $stmt->execute();
    $stmt->close();
    return $saved;
}

