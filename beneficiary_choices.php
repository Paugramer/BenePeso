<?php

function beneficiary_choice_options(string $group): array
{
    $choices = [
        'occupation' => ['Farmer', 'Fisherfolk', 'Construction Worker', 'Driver', 'Vendor', 'Laborer', 'Domestic Worker', 'Factory Worker', 'Office Worker', 'Service Crew', 'Security Guard', 'Self-employed', 'Student', 'Unemployed', 'Retired'],
        'parent_occupation' => ['Farmer', 'Fisherfolk', 'Construction Worker', 'Driver', 'Vendor', 'Laborer', 'Domestic Worker', 'Factory Worker', 'Office Worker', 'Government Employee', 'Private Employee', 'Self-employed', 'Unemployed', 'Retired', 'Deceased'],
        'skills_training' => ['Agriculture', 'Automotive Servicing', 'Carpentry', 'Computer/Digital Skills', 'Cookery', 'Dressmaking/Sewing', 'Electrical Installation', 'Entrepreneurship', 'Food Processing', 'Housekeeping', 'Massage Therapy', 'Welding'],
        'beneficiary_type' => ['Unemployed', 'Underemployed', 'Displaced Worker', 'Seasonal Worker', 'Informal Sector Worker', 'Self-employed'],
        'dependent_relationship' => ['Spouse', 'Child', 'Parent', 'Sibling', 'Grandparent', 'Grandchild', 'Legal Guardian'],
        'ownership_type' => ['Sole Proprietorship', 'Partnership', 'Corporation', 'Cooperative', 'Association'],
    ];
    return $choices[$group] ?? [];
}

function render_beneficiary_options(string $group): void
{
    foreach (beneficiary_choice_options($group) as $choice) {
        echo '<option value="' . htmlspecialchars($choice, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($choice, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    echo '<option value="Others">Others</option>';
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

