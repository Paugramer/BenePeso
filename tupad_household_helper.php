<?php

function normalize_person_name(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);
    return trim(preg_replace('/\s+/u', ' ', $name));
}

function check_tupad_household_conflict(mysqli $conn, int $programId, string $applicantName, string $dependentName = '', int $excludeBeneficiaryId = 0): array
{
    $programStmt = $conn->prepare("SELECT program_name, YEAR(start_date) AS program_year FROM programs WHERE program_id=? LIMIT 1");
    $programStmt->bind_param('i', $programId);
    $programStmt->execute();
    $program = $programStmt->get_result()->fetch_assoc();
    $programStmt->close();
    if (!$program || stripos((string)$program['program_name'], 'TUPAD') === false) return ['eligible' => true];

    $year = (int)($program['program_year'] ?: date('Y'));
    $stmt = $conn->prepare("SELECT b.beneficiary_id, b.full_name, b.dependent_name
        FROM beneficiaries b
        JOIN programs p ON p.program_id=b.program_id
        WHERE p.program_name LIKE '%TUPAD%'
          AND YEAR(p.start_date)=?
          AND b.beneficiary_id<>?
          AND b.approval_status<>'Rejected'
          AND COALESCE(b.availment_status, 'Not Yet Availed') NOT IN ('Cancelled','Not Qualified')");
    $stmt->bind_param('ii', $year, $excludeBeneficiaryId);
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $applicant = normalize_person_name($applicantName);
    $dependent = normalize_person_name($dependentName);
    foreach ($records as $record) {
        $existingApplicant = normalize_person_name((string)($record['full_name'] ?? ''));
        $existingDependent = normalize_person_name((string)($record['dependent_name'] ?? ''));
        if ($applicant !== '' && $existingDependent !== '' && $applicant === $existingDependent) {
            return ['eligible' => false, 'message' => "$applicantName is already listed as a dependent in an existing TUPAD household record for $year. A person listed under that household cannot submit a separate TUPAD application within the same program year."];
        }
        if ($dependent !== '' && $existingApplicant !== '' && $dependent === $existingApplicant) {
            return ['eligible' => false, 'message' => "$dependentName already has a TUPAD applicant or beneficiary record for $year and cannot also be listed as a dependent in another TUPAD household application for the same program year."];
        }
        if ($dependent !== '' && $existingDependent !== '' && $dependent === $existingDependent) {
            return ['eligible' => false, 'message' => "$dependentName is already listed in another TUPAD household record for $year. The same dependent cannot be used in more than one TUPAD household application within the same program year."];
        }
    }
    return ['eligible' => true];
}
