<?php

/**
 * Normalizes identity values for duplicate comparisons without changing the
 * values stored in the beneficiary record.
 */
function beneficiary_identity_key(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') $value = $ascii;
    }
    $value = strtolower($value);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function beneficiary_contact_key(?string $value): string
{
    return preg_replace('/\D+/', '', (string)$value) ?? '';
}

/**
 * Returns a safe, same-batch duplicate warning. Cross-program participation is
 * intentionally allowed because a person may legitimately join several PESO
 * programs at different times.
 */
function find_beneficiary_duplicate(
    mysqli $conn,
    int $programId,
    int $excludeBeneficiaryId,
    string $fullName,
    ?string $birthdate,
    ?string $contactNumber,
    ?string $idNumber
): ?array {
    if ($programId <= 0) return null;

    $stmt = $conn->prepare(
        'SELECT beneficiary_id, full_name, first_name, middle_name, last_name, ext_name, birthdate, contact_no, id_number
         FROM beneficiaries
         WHERE program_id=? AND beneficiary_id<>?'
    );
    if (!$stmt) return null;
    $stmt->bind_param('ii', $programId, $excludeBeneficiaryId);
    $stmt->execute();
    $records = $stmt->get_result();

    $nameKey = beneficiary_identity_key($fullName);
    $birthdateKey = trim((string)$birthdate);
    $contactKey = beneficiary_contact_key($contactNumber);
    $idKey = beneficiary_identity_key($idNumber);
    $match = null;

    while ($row = $records->fetch_assoc()) {
        $candidateName = trim((string)($row['full_name'] ?? ''));
        if ($candidateName === '') {
            $candidateName = trim(implode(' ', array_filter([
                $row['first_name'] ?? '', $row['middle_name'] ?? '',
                $row['last_name'] ?? '', $row['ext_name'] ?? ''
            ], static fn($part) => trim((string)$part) !== '')));
        }
        $candidateNameKey = beneficiary_identity_key($candidateName);
        $candidateContactKey = beneficiary_contact_key($row['contact_no'] ?? '');
        $candidateIdKey = beneficiary_identity_key($row['id_number'] ?? '');
        $candidateBirthdate = trim((string)($row['birthdate'] ?? ''));

        $reason = '';
        if ($idKey !== '' && $candidateIdKey !== '' && hash_equals($candidateIdKey, $idKey)) {
            $reason = 'the same government ID number';
        } elseif (strlen($contactKey) >= 7 && hash_equals($candidateContactKey, $contactKey)) {
            $reason = 'the same contact number';
        } elseif ($nameKey !== '' && hash_equals($candidateNameKey, $nameKey)) {
            $reason = 'the same normalized full name';
        } elseif ($birthdateKey !== '' && $candidateBirthdate === $birthdateKey && $nameKey !== '' && $candidateNameKey !== '') {
            $distance = levenshtein($nameKey, $candidateNameKey);
            $allowedDistance = max(1, (int)floor(max(strlen($nameKey), strlen($candidateNameKey)) * 0.08));
            if ($distance <= $allowedDistance) $reason = 'a very similar name and the same birthdate';
        }

        if ($reason !== '') {
            $match = [
                'beneficiary_id' => (int)$row['beneficiary_id'],
                'name' => $candidateName,
                'reason' => $reason,
            ];
            break;
        }
    }
    $stmt->close();
    return $match;
}

function beneficiary_duplicate_message(array $match): string
{
    $name = trim((string)($match['name'] ?? 'Existing beneficiary'));
    $reason = trim((string)($match['reason'] ?? 'matching identity information'));
    return "Possible duplicate record: {$name} already exists in this batch with {$reason}. Review the existing profile instead of creating another record.";
}
