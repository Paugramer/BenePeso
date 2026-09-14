<?php

/**
 * Keep stored batch status aligned with its approved schedule.
 * Pending/rejected batches remain Upcoming; approved batches are derived only
 * from their start/end dates so viewing a page never completes a batch merely
 * because its application slots are full.
 */
function sync_program_statuses(mysqli $conn): bool
{
    $columnResult = $conn->query("SHOW COLUMNS FROM programs LIKE 'status'");
    $column = $columnResult ? $columnResult->fetch_assoc() : null;
    $type = strtolower((string)($column['Type'] ?? ''));
    if ($column && str_starts_with($type, 'enum(') && strpos($type, "'upcoming'") === false) {
        $nullable = strtoupper((string)($column['Null'] ?? 'NO')) === 'YES' ? 'NULL' : 'NOT NULL';
        $conn->query("ALTER TABLE programs MODIFY status ENUM('Upcoming','Ongoing','Completed') {$nullable} DEFAULT 'Upcoming'");
    }
    $sql = "UPDATE programs
            SET status = CASE
                WHEN approval_status <> 'Approved' OR start_date IS NULL OR end_date IS NULL THEN 'Upcoming'
                WHEN CURDATE() < start_date THEN 'Upcoming'
                WHEN CURDATE() > end_date THEN 'Completed'
                ELSE 'Ongoing'
            END
            WHERE NOT (status <=> CASE
                WHEN approval_status <> 'Approved' OR start_date IS NULL OR end_date IS NULL THEN 'Upcoming'
                WHEN CURDATE() < start_date THEN 'Upcoming'
                WHEN CURDATE() > end_date THEN 'Completed'
                ELSE 'Ongoing'
            END)";
    return $conn->query($sql) === true;
}
