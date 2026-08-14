<?php

/**
 * Generate the next official BenePeso batch reference for a schedule year.
 * Call this while holding the matching lock returned by acquire_batch_code_lock().
 */
function acquire_batch_code_lock(mysqli $conn, int $year): bool
{
    $lockName = 'benepeso_batch_code_' . $year;
    $stmt = $conn->prepare('SELECT GET_LOCK(?, 5) AS acquired');
    if (!$stmt) return false;
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['acquired'] ?? 0) === 1;
}

function batch_year_from_date(string $startDate): int
{
    $timestamp = strtotime($startDate);
    return $timestamp ? (int)date('Y', $timestamp) : (int)date('Y');
}

function batch_code_segment(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9]+/', '-', $value);
    return trim((string)$value, '-');
}

function valid_batch_date_range(string $startDate, string $endDate): bool
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
    return $start !== false && $end !== false
        && $start->format('Y-m-d') === $startDate
        && $end->format('Y-m-d') === $endDate
        && $end >= $start;
}

function next_batch_code(mysqli $conn, string $startDate, string $programName = '', ?string $tupadCategory = null): string
{
    $year = batch_year_from_date($startDate);
    if (stripos($programName, 'TUPAD') !== false) {
        $category = batch_code_segment($tupadCategory ?: 'Regular TUPAD');
        $category = preg_replace('/^TUPAD-?/', '', $category) ?: 'REGULAR';
        $prefix = 'TUPAD-' . $category . '-' . $year . '-';
        $sequenceWidth = 3;
    } elseif (stripos($programName, 'SPES') !== false) {
        $prefix = 'SPES-' . $year . '-';
        $sequenceWidth = 3;
    } elseif (stripos($programName, 'MSME') !== false) {
        $category = batch_code_segment($programName);
        $category = preg_replace('/^MSME-?/', '', $category) ?: 'PROFILING';
        $prefix = 'MSME-' . $category . '-' . $year . '-';
        $sequenceWidth = 4;
    } else {
        $prefix = 'BP-' . $year . '-';
        $sequenceWidth = 3;
    }
    $like = $prefix . '%';

    $stmt = $conn->prepare(
        "SELECT MAX(CAST(SUBSTRING(program_code, ?) AS UNSIGNED)) AS last_number
         FROM programs
         WHERE program_code LIKE ?"
    );
    $numberStart = strlen($prefix) + 1;
    $stmt->bind_param('is', $numberStart, $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $nextNumber = (int)($row['last_number'] ?? 0) + 1;
    return $prefix . str_pad((string)$nextNumber, $sequenceWidth, '0', STR_PAD_LEFT);
}

function release_batch_code_lock(mysqli $conn, int $year): void
{
    $lockName = 'benepeso_batch_code_' . $year;
    $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
    if (!$stmt) return;
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $stmt->close();
}
