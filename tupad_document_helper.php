<?php

function ensure_tupad_document_schema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS beneficiary_documents (
        document_id INT AUTO_INCREMENT PRIMARY KEY,
        beneficiary_id INT NOT NULL,
        document_type VARCHAR(60) NOT NULL,
        verification_status ENUM('Pending','Verified','Needs Resubmission') NOT NULL DEFAULT 'Pending',
        reviewer_note VARCHAR(500) NULL,
        reviewed_by_role VARCHAR(20) NULL,
        reviewed_by_id INT NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_beneficiary_document (beneficiary_id, document_type),
        KEY idx_beneficiary_documents (beneficiary_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS beneficiary_tupad_details (
        beneficiary_id INT PRIMARY KEY,
        is_pregnant ENUM('Yes','No','Not Applicable') NOT NULL,
        is_pwd ENUM('Yes','No') NOT NULL,
        has_work_limitation ENUM('Yes','No') NOT NULL,
        capable_of_work ENUM('Yes','No') NOT NULL DEFAULT 'Yes',
        certified_truthful TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $column = $conn->query("SHOW COLUMNS FROM beneficiary_tupad_details LIKE 'capable_of_work'");
    if ($column && $column->num_rows === 0) {
        $conn->query("ALTER TABLE beneficiary_tupad_details ADD COLUMN capable_of_work ENUM('Yes','No') NOT NULL DEFAULT 'Yes' AFTER has_work_limitation");
    }
}

function save_tupad_details(mysqli $conn, int $beneficiaryId, array $source): bool
{
    $stmt = $conn->prepare('INSERT INTO beneficiary_tupad_details (beneficiary_id,is_pregnant,is_pwd,has_work_limitation,capable_of_work,certified_truthful) VALUES (?,?,?,?,?,1)');
    $pregnant = trim((string)$source['tupad_is_pregnant']);
    $pwd = trim((string)$source['tupad_is_pwd']);
    $limitation = trim((string)$source['tupad_has_work_limitation']);
    $capable = trim((string)$source['tupad_capable_of_work']);
    $stmt->bind_param('issss', $beneficiaryId, $pregnant, $pwd, $limitation, $capable);
    $saved = $stmt->execute();
    $stmt->close();
    return $saved;
}

function create_tupad_document_checklist(mysqli $conn, int $beneficiaryId, bool $needsFitnessCertificate): bool
{
    $types = ['government_id'];
    if ($needsFitnessCertificate) $types[] = 'fitness_to_work';
    $stmt = $conn->prepare("INSERT INTO beneficiary_documents (beneficiary_id,document_type) VALUES (?,?) ON DUPLICATE KEY UPDATE verification_status='Pending',reviewer_note=NULL,reviewed_by_role=NULL,reviewed_by_id=NULL,reviewed_at=NULL");
    foreach ($types as $type) {
        $stmt->bind_param('is', $beneficiaryId, $type);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
    }
    $stmt->close();
    return true;
}

function delete_tupad_application_data(mysqli $conn, int $beneficiaryId): void
{
    $delete = $conn->prepare('DELETE FROM beneficiary_documents WHERE beneficiary_id=?');
    $delete->bind_param('i', $beneficiaryId);
    $delete->execute();
    $delete->close();
    $delete = $conn->prepare('DELETE FROM beneficiary_tupad_details WHERE beneficiary_id=?');
    $delete->bind_param('i', $beneficiaryId);
    $delete->execute();
    $delete->close();
}

function tupad_documents_are_verified(mysqli $conn, int $beneficiaryId): bool
{
    $stmt = $conn->prepare("SELECT t.beneficiary_id AS detail_id,t.is_pregnant,t.is_pwd,t.has_work_limitation,
        SUM(d.document_type='government_id' AND d.verification_status='Verified') AS id_ok,
        SUM(d.document_type='fitness_to_work' AND d.verification_status='Verified') AS fitness_ok
        FROM beneficiaries b LEFT JOIN beneficiary_tupad_details t ON t.beneficiary_id=b.beneficiary_id LEFT JOIN beneficiary_documents d ON d.beneficiary_id=b.beneficiary_id
        WHERE b.beneficiary_id=? GROUP BY b.beneficiary_id");
    $stmt->bind_param('i', $beneficiaryId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;
    // Applications created before this verification workflow remain reviewable
    // under PESO's existing manual-document process.
    if (empty($row['detail_id'])) return true;
    if ((int)$row['id_ok'] < 1) return false;
    $needsFitness = ($row['is_pwd'] ?? 'No') === 'Yes' || ($row['has_work_limitation'] ?? 'No') === 'Yes';
    return !$needsFitness || (int)$row['fitness_ok'] >= 1;
}

function is_tupad_beneficiary(mysqli $conn, int $beneficiaryId): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id WHERE b.beneficiary_id=? AND p.program_name LIKE '%TUPAD%' LIMIT 1");
    $stmt->bind_param('i', $beneficiaryId);
    $stmt->execute();
    $isTupad = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $isTupad;
}

function tupad_can_start_work(mysqli $conn, int $beneficiaryId): bool
{
    return !is_tupad_beneficiary($conn, $beneficiaryId) || tupad_documents_are_verified($conn, $beneficiaryId);
}

/**
 * Scheduling TUPAD orientation is the reviewer's confirmation that every
 * applicable physical document has already been inspected successfully.
 */
function tupad_confirm_documents_for_orientation(mysqli $conn, int $beneficiaryId, string $reviewerRole, int $reviewerId): bool
{
    if (!is_tupad_beneficiary($conn, $beneficiaryId)) return true;

    $details = $conn->prepare('SELECT is_pwd,has_work_limitation FROM beneficiary_tupad_details WHERE beneficiary_id=? LIMIT 1');
    if (!$details) return false;
    $details->bind_param('i', $beneficiaryId);
    $details->execute();
    $row = $details->get_result()->fetch_assoc();
    $details->close();
    // Legacy records without a checklist continue through the manual process.
    if (!$row) return true;

    $types = ['government_id'];
    if (($row['is_pwd'] ?? 'No') === 'Yes' || ($row['has_work_limitation'] ?? 'No') === 'Yes') $types[] = 'fitness_to_work';
    $insert = $conn->prepare('INSERT IGNORE INTO beneficiary_documents (beneficiary_id,document_type) VALUES (?,?)');
    if (!$insert) return false;
    foreach ($types as $type) {
        $insert->bind_param('is', $beneficiaryId, $type);
        if (!$insert->execute()) { $insert->close(); return false; }
    }
    $insert->close();

    $note = 'Verified in person before orientation was scheduled.';
    $update = $conn->prepare("UPDATE beneficiary_documents SET verification_status='Verified',reviewer_note=?,reviewed_by_role=?,reviewed_by_id=?,reviewed_at=NOW() WHERE beneficiary_id=? AND document_type IN ('government_id','fitness_to_work')");
    if (!$update) return false;
    $update->bind_param('ssii', $note, $reviewerRole, $reviewerId, $beneficiaryId);
    $saved = $update->execute();
    $update->close();
    return $saved;
}
