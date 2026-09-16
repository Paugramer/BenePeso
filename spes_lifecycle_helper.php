<?php

/**
 * SPES lifecycle rules shared by the beneficiary, admin, staff, and email views.
 * A completed fourth-year-or-higher record is a SPES Graduate; any other person
 * with at least one approved, completed SPES record is a SPES Baby.
 */

function spes_is_graduating_level(?string $level): bool {
    $level = strtolower(trim((string)$level));
    if ($level === '') return false;
    return (bool)preg_match('/(?:^|\b)(4th|fourth|5th|fifth|graduat)/', $level);
}

function spes_person_match_sql(string $candidateAlias, string $baseAlias): string {
    return "(($candidateAlias.beneficiary_id = $baseAlias.beneficiary_id)"
        . " OR ($candidateAlias.user_id IS NOT NULL AND $candidateAlias.user_id = $baseAlias.user_id)"
        . " OR (NULLIF(TRIM($candidateAlias.email), '') IS NOT NULL"
        . " AND LOWER(TRIM($candidateAlias.email)) = LOWER(TRIM($baseAlias.email))))";
}

function spes_completed_exists_sql(string $baseAlias = 'b', bool $graduatingOnly = false, ?string $excludeIdSql = null): string {
    $match = spes_person_match_sql('spes_done', $baseAlias);
    $extra = $graduatingOnly
        ? " AND (LOWER(COALESCE(spes_done.tert_year_level,'')) REGEXP '(^|[[:space:]])(4th|fourth|5th|fifth)|graduat')"
        : '';
    $exclude = $excludeIdSql ? " AND spes_done.beneficiary_id <> $excludeIdSql" : '';
    return "EXISTS (SELECT 1 FROM beneficiaries spes_done"
        . " JOIN programs spes_program ON spes_program.program_id=spes_done.program_id"
        . " WHERE $match AND UPPER(TRIM(spes_program.program_name)) LIKE 'SPES%'"
        . " AND spes_done.approval_status='Approved' AND spes_done.availment_status='Completed'$extra$exclude)";
}

function spes_group_condition_sql(string $group, string $baseAlias = 'b'): string {
    $completed = spes_completed_exists_sql($baseAlias);
    $graduate = spes_completed_exists_sql($baseAlias, true);
    if ($group === 'graduate') return "$graduate";
    if ($group === 'baby') return "($completed AND NOT $graduate)";
    return '1=1';
}

function spes_user_summary(mysqli $conn, int $userId, string $email = ''): array {
    $email = trim($email);
    $sql = "SELECT b.* FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id"
        . " WHERE UPPER(TRIM(p.program_name)) LIKE 'SPES%'"
        . " AND b.approval_status='Approved' AND b.availment_status='Completed'"
        . " AND ((? > 0 AND b.user_id=?) OR (? <> '' AND LOWER(TRIM(b.email))=LOWER(?)))"
        . " ORDER BY COALESCE(b.date_completed,b.updated_at,b.created_at) DESC, b.beneficiary_id DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return ['classification' => 'none', 'completed_count' => 0, 'latest' => null];
    $stmt->bind_param('iiss', $userId, $userId, $email, $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $latest = null;
    $count = 0;
    $graduate = false;
    while ($row = $result->fetch_assoc()) {
        if ($latest === null) $latest = $row;
        $count++;
        if (spes_is_graduating_level($row['tert_year_level'] ?? '')) $graduate = true;
    }
    $stmt->close();
    return [
        'classification' => $graduate ? 'graduate' : ($count > 0 ? 'baby' : 'none'),
        'completed_count' => $count,
        'latest' => $latest,
    ];
}

function spes_beneficiary_is_returning(mysqli $conn, int $beneficiaryId): bool {
    if ($beneficiaryId <= 0) return false;
    $exists = spes_completed_exists_sql('b', false, 'b.beneficiary_id');
    $stmt = $conn->prepare("SELECT $exists AS is_returning FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id WHERE b.beneficiary_id=? AND UPPER(TRIM(p.program_name)) LIKE 'SPES%' LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $beneficiaryId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return !empty($row['is_returning']);
}

function spes_beneficiary_classification(mysqli $conn, int $beneficiaryId): string {
    $stmt = $conn->prepare("SELECT b.user_id,b.email,b.tert_year_level,b.approval_status,b.availment_status,p.program_name FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id WHERE b.beneficiary_id=? LIMIT 1");
    if (!$stmt) return 'none';
    $stmt->bind_param('i', $beneficiaryId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return 'none';
    if (stripos((string)$row['program_name'], 'SPES') !== false && ($row['approval_status'] ?? '') === 'Approved' && ($row['availment_status'] ?? '') === 'Completed') {
        return spes_is_graduating_level($row['tert_year_level'] ?? '') ? 'graduate' : 'baby';
    }
    return spes_user_summary($conn, (int)($row['user_id'] ?? 0), (string)($row['email'] ?? ''))['classification'];
}

function spes_ordinal(int $number): string {
    $mod100 = $number % 100;
    if ($mod100 >= 11 && $mod100 <= 13) return $number . 'th';
    return $number . ([1 => 'st', 2 => 'nd', 3 => 'rd'][$number % 10] ?? 'th');
}

function spes_beneficiary_lifecycle_details(mysqli $conn, int $beneficiaryId): array {
    $empty = ['spes_lifecycle' => 'none', 'spes_lifecycle_label' => '', 'spes_completed_count' => 0, 'spes_graduation_year' => '', 'spes_all_availments' => ''];
    $base = $conn->prepare('SELECT user_id,email FROM beneficiaries WHERE beneficiary_id=? LIMIT 1');
    if (!$base) return $empty;
    $base->bind_param('i', $beneficiaryId);
    $base->execute();
    $identity = $base->get_result()->fetch_assoc();
    $base->close();
    if (!$identity) return $empty;

    $userId = (int)($identity['user_id'] ?? 0);
    $email = trim((string)($identity['email'] ?? ''));
    $sql = "SELECT b.beneficiary_id,b.tert_year_level,b.approval_status,b.availment_status,b.date_completed,b.date_availed,b.created_at,p.program_code"
        . " FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id"
        . " WHERE UPPER(TRIM(p.program_name)) LIKE 'SPES%'"
        . " AND (b.beneficiary_id=? OR (? > 0 AND b.user_id=?) OR (? <> '' AND LOWER(TRIM(b.email))=LOWER(?)))"
        . " ORDER BY COALESCE(b.date_completed,b.date_availed,b.created_at),b.beneficiary_id";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $empty;
    $stmt->bind_param('iiiss', $beneficiaryId, $userId, $userId, $email, $email);
    $stmt->execute();
    $rows = $stmt->get_result();
    $completed = 0;
    $graduateYear = '';
    $availments = [];
    while ($row = $rows->fetch_assoc()) {
        $isCompleted = ($row['approval_status'] ?? '') === 'Approved' && ($row['availment_status'] ?? '') === 'Completed';
        if ($isCompleted) {
            $completed++;
            if (spes_is_graduating_level($row['tert_year_level'] ?? '')) {
                $date = $row['date_completed'] ?: ($row['date_availed'] ?: $row['created_at']);
                if ($date) $graduateYear = date('Y', strtotime($date));
            }
        }
        $date = $row['date_completed'] ?: ($row['date_availed'] ?: $row['created_at']);
        $year = $date ? date('Y', strtotime($date)) : 'Year unavailable';
        $batch = trim((string)($row['program_code'] ?? '')) ?: 'Uncoded batch';
        $status = trim((string)($row['availment_status'] ?? '')) ?: 'Status unavailable';
        $availments[] = $year . ' | ' . $batch . ' | ' . $status;
    }
    $stmt->close();
    $classification = $graduateYear !== '' ? 'graduate' : ($completed > 0 ? 'baby' : 'none');
    $label = $classification === 'graduate'
        ? 'SPES Graduate' . ($graduateYear !== '' ? ' - Class of ' . $graduateYear : '')
        : ($classification === 'baby' ? 'SPES Baby - ' . spes_ordinal($completed) . ' Year' : '');
    return [
        'spes_lifecycle' => $classification,
        'spes_lifecycle_label' => $label,
        'spes_completed_count' => $completed,
        'spes_graduation_year' => $graduateYear,
        'spes_all_availments' => implode("\n", array_map(static fn($item) => '* ' . $item, $availments)),
    ];
}

function spes_enrich_rows(mysqli $conn, array $rows): array {
    foreach ($rows as &$row) {
        $row = array_merge($row, spes_beneficiary_lifecycle_details($conn, (int)($row['beneficiary_id'] ?? 0)));
    }
    unset($row);
    return $rows;
}
