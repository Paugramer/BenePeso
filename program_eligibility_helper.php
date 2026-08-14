<?php

function eligibility_column_exists(mysqli $conn, string $table, string $column): bool
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function ensure_program_eligibility_schema(mysqli $conn): void
{
    $definitions = [
        'eligible_sex' => "VARCHAR(10) NOT NULL DEFAULT 'Any'",
        'minimum_age' => 'TINYINT UNSIGNED NOT NULL DEFAULT 18',
        'maximum_age' => 'TINYINT UNSIGNED NULL DEFAULT NULL',
        'one_per_household' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ];
    foreach (['program_categories', 'programs'] as $table) {
        foreach ($definitions as $column => $definition) {
            if (!eligibility_column_exists($conn, $table, $column)) {
                $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        }
    }
    // Bring legacy SPES defaults in line with the statutory 15–30 age range,
    // while preserving any age rules that PESO has already configured manually.
    foreach (['program_categories', 'programs'] as $table) {
        $conn->query("UPDATE `$table` SET minimum_age=15, maximum_age=30 WHERE UPPER(TRIM(program_name))='SPES' AND minimum_age=18 AND maximum_age IS NULL");
    }
}

function clean_eligibility_rules(array $source): array
{
    $sex = ucfirst(strtolower(trim((string)($source['eligible_sex'] ?? 'Any'))));
    if (!in_array($sex, ['Any', 'Male', 'Female'], true)) $sex = 'Any';
    $minimumAge = max(0, min(120, (int)($source['minimum_age'] ?? 18)));
    $maximumRaw = trim((string)($source['maximum_age'] ?? ''));
    $maximumAge = $maximumRaw === '' ? null : max($minimumAge, min(120, (int)$maximumRaw));
    return [$sex, $minimumAge, $maximumAge, !empty($source['one_per_household']) ? 1 : 0];
}

function evaluate_program_eligibility(mysqli $conn, int $userId, int $programId): array
{
    $stmt = $conn->prepare('SELECT program_name, eligible_sex, minimum_age, maximum_age, one_per_household FROM programs WHERE program_id = ? AND approval_status = \'Approved\' LIMIT 1');
    $stmt->bind_param('i', $programId);
    $stmt->execute();
    $program = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$program) return ['eligible' => false, 'message' => 'This program is unavailable or is not approved.'];

    $stmt = $conn->prepare('SELECT birthdate, sex, street_purok_zone, barangay FROM users WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || empty($user['birthdate'])) return ['eligible' => false, 'message' => 'Please complete your birthdate in your profile before applying.'];

    try {
        $age = (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($user['birthdate']))->y;
    } catch (Exception $e) {
        return ['eligible' => false, 'message' => 'Your profile birthdate is invalid. Please update your profile.'];
    }
    $minimumAge = (int)($program['minimum_age'] ?? 18);
    $maximumAge = $program['maximum_age'] === null ? null : (int)$program['maximum_age'];
    if ($age < $minimumAge) return ['eligible' => false, 'message' => "This program requires applicants to be at least $minimumAge years old. Your verified profile age is $age."];
    if ($maximumAge !== null && $age > $maximumAge) return ['eligible' => false, 'message' => "This program accepts applicants up to $maximumAge years old. Your verified profile age is $age."];

    $requiredSex = ucfirst(strtolower((string)($program['eligible_sex'] ?? 'Any')));
    $userSex = ucfirst(strtolower(trim((string)($user['sex'] ?? ''))));
    if ($requiredSex !== 'Any' && $userSex !== $requiredSex) {
        return ['eligible' => false, 'message' => "This batch is limited to $requiredSex applicants. Your verified profile records your sex as " . ($userSex ?: 'not specified') . '.'];
    }

    if (!empty($program['one_per_household'])) {
        $street = trim((string)($user['street_purok_zone'] ?? ''));
        $barangay = trim((string)($user['barangay'] ?? ''));
        if ($street === '' || $barangay === '') return ['eligible' => false, 'message' => 'A complete household address is required because this program allows only one active beneficiary per household.'];
        $stmt = $conn->prepare("SELECT b.full_name FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id WHERE (b.user_id IS NULL OR b.user_id<>?) AND LOWER(TRIM(b.street_purok_zone))=LOWER(TRIM(?)) AND LOWER(TRIM(b.barangay))=LOWER(TRIM(?)) AND p.program_name=? AND b.approval_status IN ('Pending','Approved') AND COALESCE(b.availment_status,'Not Yet Availed') NOT IN ('Completed','Cancelled','Not Qualified') ORDER BY b.created_at DESC LIMIT 1");
        $stmt->bind_param('isss', $userId, $street, $barangay, $program['program_name']);
        $stmt->execute();
        $householdMember = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($householdMember) return ['eligible' => false, 'message' => 'Another member of your household already has a pending or active application for this program. Only one active beneficiary per household is allowed. Contact PESO if your address record needs correction.'];
    }

    return ['eligible' => true, 'message' => 'You meet the configured age, sex, and household rules.'];
}
