<?php
require_once __DIR__ . '/document_submission_helper.php';

function acceptance_notice_deadline($value): string {
    if (!is_string($value) || !document_submission_deadline_valid($value)) {
        throw new InvalidArgumentException('Set a valid requirements submission deadline, today or later.');
    }
    return $value;
}

function ensure_acceptance_notice_storage(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS application_acceptance_notices (
        beneficiary_id INT NOT NULL PRIMARY KEY,
        submission_deadline DATE NOT NULL,
        requirements_json TEXT NOT NULL,
        accepted_by_admin INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function acceptance_notice_requirements(mysqli $conn, array $record): array {
    return array_values(array_map(static fn($item) => $item['name'], application_requirement_items($conn, $record)));
}

function save_acceptance_notice(mysqli $conn, int $id, string $deadline, array $requirements, int $admin): void {
    $json = json_encode($requirements, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare('INSERT INTO application_acceptance_notices (beneficiary_id,submission_deadline,requirements_json,accepted_by_admin) VALUES (?,?,?,?)');
    $stmt->bind_param('issi', $id, $deadline, $json, $admin);
    $stmt->execute();
    $stmt->close();
}

function acceptance_notice_body(string $name, string $program, string $deadline, array $requirements, bool $returningSpes = false): string {
    $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $deadline);
    if (!$date || $date->format('Y-m-d') !== $deadline) throw new InvalidArgumentException('Invalid submission deadline.');
    $body = '<p>Dear ' . $escape($name) . ',</p><p>Your application for <strong>' . $escape($program) . '</strong> has been accepted.</p>';
    $body .= '<p><strong>Next step: Submit your requirements in person at the PESO Vinzons office, Vinzons Municipal Hall, Camarines Norte, on or before ' . $escape($date->format('F j, Y')) . '.</strong></p>';
    if ($requirements) {
        $body .= '<p>Please bring the following requirements for verification:</p><ul>';
        foreach ($requirements as $item) $body .= '<li>' . $escape($item) . '</li>';
        $body .= '</ul>';
    } else {
        $body .= '<p>Contact the PESO Vinzons office to confirm the required documents before your visit.</p>';
    }
    $body .= '<p>Bring the original documents for verification and the required copies. Acceptance does not mean your documents have already been verified.</p>';
    if ($returningSpes) $body .= '<p>As a returning SPES beneficiary, you remain exempt from the examination. Submit your latest semester grades and current requirements.</p>';
    return $body . '<p><strong>Keep your registered email active and check your inbox and spam folder for PESO updates.</strong> Contact PESO Vinzons if you need help with the submission deadline.</p>';
}
