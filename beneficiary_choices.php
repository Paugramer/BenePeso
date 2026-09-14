<?php

/**
 * Canonical values used by every beneficiary-facing form. Keeping these in
 * one place prevents the registration, profile, admin, and staff forms from
 * accepting different spellings for the same barangay.
 */
function beneficiary_barangay_options(): array
{
    return [
        'Aguit-It', 'Banocboc', 'Cagbalogo', 'Calangcawan Norte', 'Calangcawan Sur',
        'Guinacutan', 'Mangcayo', 'Mangcawayan', 'Manlucugan', 'Matango',
        'Napilihan', 'Pinagtigasan', 'Barangay I (Pob.)', 'Barangay II (Pob.)',
        'Barangay III (Pob.)', 'Sabang', 'Santo Domingo', 'Singi', 'Sula',
    ];
}

function canonical_beneficiary_barangay($value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    foreach (beneficiary_barangay_options() as $barangay) {
        if (strcasecmp($value, $barangay) === 0) return $barangay;
    }
    return $value;
}

function normalize_optional_name_part($value): string
{
    $value = trim((string)$value);
    return in_array(strtolower($value), ['n/a', 'na', 'not applicable', 'none'], true) ? '' : $value;
}

function normalize_optional_middle_name($value): string
{
    return normalize_optional_name_part($value);
}

function is_strict_iso_date($value): bool
{
    $value = trim((string)$value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date instanceof DateTimeImmutable
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

/**
 * Server-side checks for manually entered beneficiary identity data. The
 * function reports errors only; it never guesses or mutates personal data.
 */
function validate_beneficiary_identity_input(array $data): array
{
    $errors = [];
    $firstName = trim((string)($data['first_name'] ?? ''));
    $middleName = normalize_optional_middle_name($data['middle_name'] ?? '');
    $lastName = trim((string)($data['last_name'] ?? ''));
    $birthdate = trim((string)($data['birthdate'] ?? ''));
    $sex = trim((string)($data['sex'] ?? ''));
    $civilStatus = trim((string)($data['civil_status'] ?? ''));
    $contact = trim((string)($data['contact_no'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $barangay = canonical_beneficiary_barangay($data['barangay'] ?? '');

    if ($firstName === '' || $lastName === '') $errors[] = 'First name and last name are required.';
    foreach (['First name' => $firstName, 'Middle name' => $middleName, 'Last name' => $lastName] as $label => $name) {
        if ($name !== '' && !preg_match("/^[\\p{L}\\p{M} .'\\x{2019}\\p{Pd}-]+$/u", $name)) {
            $errors[] = "$label contains an invalid character.";
        }
    }
    if (!is_strict_iso_date($birthdate)) {
        $errors[] = 'Enter a valid date of birth.';
    } else {
        $dob = new DateTimeImmutable($birthdate);
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
        if ($dob > $today) $errors[] = 'Date of birth cannot be in the future.';
        if ($dob->diff($today)->y > 120) $errors[] = 'Verify the date of birth; the calculated age exceeds 120.';
    }
    if (!in_array($sex, ['Male', 'Female'], true)) $errors[] = 'Select a valid sex.';
    if (!in_array($civilStatus, ['Single', 'Married', 'Widowed', 'Legally Separated'], true)) $errors[] = 'Select a valid civil status.';
    if (!preg_match('/^09\d{9}$/', $contact)) $errors[] = 'Contact number must contain 11 digits and start with 09.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address or leave it blank.';
    if (!in_array($barangay, beneficiary_barangay_options(), true)) $errors[] = 'Select a valid barangay.';

    return array_values(array_unique($errors));
}

function beneficiary_choice_options(string $group): array
{
    $choices = [
        'occupation' => ['Farmer', 'Fisherfolk', 'Construction Worker', 'Driver', 'Vendor', 'Laborer', 'Domestic Worker', 'Factory Worker', 'Office Worker', 'Service Crew', 'Security Guard', 'Self-employed', 'Student', 'Unemployed', 'Retired'],
        'parent_occupation' => ['Farmer', 'Fisherfolk', 'Construction Worker', 'Driver', 'Vendor', 'Laborer', 'Domestic Worker', 'Factory Worker', 'Office Worker', 'Government Employee', 'Private Employee', 'Self-employed', 'Unemployed', 'Retired', 'Deceased'],
        'skills_training' => ['Agriculture', 'Automotive Servicing', 'Carpentry', 'Computer/Digital Skills', 'Cookery', 'Dressmaking/Sewing', 'Electrical Installation', 'Entrepreneurship', 'Food Processing', 'Housekeeping', 'Massage Therapy', 'Welding'],
        'beneficiary_type' => ['Unemployed', 'Underemployed', 'Displaced Worker', 'Seasonal Worker', 'Informal Sector Worker', 'Self-employed'],
        'dependent_relationship' => ['Spouse', 'Child', 'Parent', 'Sibling', 'Grandparent', 'Grandchild', 'Legal Guardian'],
        'ownership_type' => ['Sole Proprietorship', 'Partnership', 'Corporation', 'Cooperative'],
    ];
    return $choices[$group] ?? [];
}

function render_beneficiary_options(string $group): void
{
    foreach (beneficiary_choice_options($group) as $choice) {
        echo '<option value="' . htmlspecialchars($choice, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($choice, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    if ($group !== 'ownership_type') {
        echo '<option value="Others">Others</option>';
    }
}

function choice_or_other(array $source, string $field): string
{
    $value = trim((string)($source[$field] ?? ''));
    if ($value === 'Others' || $value === 'Other') {
        $custom = trim((string)($source['other_' . $field] ?? ''));
        return $custom !== '' ? $custom : 'Others';
    }
    return $value;
}
