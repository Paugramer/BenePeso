<?php

function getReportProgramTitle(string $programName): string
{
    $program = strtoupper(trim($programName));
    if (strpos($program, 'TUPAD') !== false) {
        return 'Tulong Panghanapbuhay sa Ating Disadvantaged/Displaced Workers Beneficiaries';
    }
    if (strpos($program, 'SPES') !== false) {
        return 'Special Program for Employment of Students Beneficiaries';
    }
    if (strpos($program, 'MSME') !== false) {
        return 'Micro, Small and Medium Enterprise Profiling Beneficiaries';
    }
    return trim($programName) . ' Beneficiaries';
}

function getReportLocationLabel(string $barangay): string
{
    return $barangay === 'All' || trim($barangay) === ''
        ? 'Municipality of Vinzons'
        : 'Barangay ' . trim($barangay) . ', Vinzons';
}

function getReportColumnDefinitions(string $programName): array
{
    $program = strtoupper($programName);

    if (strpos($program, 'MSME') !== false) {
        return [
            ['key' => 'business_name', 'label' => 'Business/Trade Name', 'default' => true],
            ['key' => 'ownership_type', 'label' => 'Type of Ownership', 'default' => true],
            ['key' => 'business_nature', 'label' => 'Nature of Business', 'default' => true],
            ['key' => 'primary_products', 'label' => 'Primary Products Offered', 'default' => true],
            ['key' => 'product_price', 'label' => 'Product Price', 'default' => false],
            ['key' => 'year_started', 'label' => 'Year Business Started', 'default' => false],
            ['key' => 'business_permit_no', 'label' => 'Business Permit No.', 'default' => false],
            ['key' => 'permit_validity', 'label' => 'Valid Until', 'default' => false],
            ['key' => 'dti_no', 'label' => 'DTI Registration No.', 'default' => false],
            ['key' => 'tin_no', 'label' => 'Tax Identification No.', 'default' => false],
            ['key' => 'contact_no', 'label' => 'Landline/Mobile Number', 'default' => true],
            ['key' => 'business_email', 'label' => 'Business Contact Details', 'default' => false],
            ['key' => 'owner_name', 'label' => 'Owner Full Name', 'default' => true],
            ['key' => 'sex', 'label' => 'Sex', 'default' => false],
            ['key' => 'birthdate', 'label' => 'Date of Birth', 'default' => false],
            ['key' => 'age', 'label' => 'Age', 'default' => false],
            ['key' => 'civil_status', 'label' => 'Civil Status', 'default' => false],
            ['key' => 'complete_address', 'label' => 'Complete Address', 'default' => true],
            ['key' => 'educational_attainment', 'label' => 'Educational Attainment', 'default' => false],
            ['key' => 'work_experience', 'label' => 'Work Experience', 'default' => false],
            ['key' => 'assets_owned', 'label' => 'Business Assets Owned', 'default' => false],
            ['key' => 'utility_needs', 'label' => 'Utility Needs', 'default' => false],
            ['key' => 'hr_total', 'label' => 'Number of Workers', 'default' => true],
            ['key' => 'hr_male', 'label' => 'Male Employees', 'default' => false],
            ['key' => 'hr_female', 'label' => 'Female Employees', 'default' => false],
            ['key' => 'emp_regular', 'label' => 'Regular Employees', 'default' => false],
            ['key' => 'emp_seasonal', 'label' => 'Seasonal Employees', 'default' => false],
            ['key' => 'emp_contractual', 'label' => 'Contractual Employees', 'default' => false],
            ['key' => 'emp_family', 'label' => 'Family Workers', 'default' => false],
            ['key' => 'hr_skills', 'label' => 'Skills Needed', 'default' => false],
            ['key' => 'daily_earnings', 'label' => 'Regular Daily Earnings', 'default' => true],
            ['key' => 'current_capital', 'label' => 'Current Capital', 'default' => true],
            ['key' => 'source_of_capital', 'label' => 'Source of Capital', 'default' => false],
            ['key' => 'business_size', 'label' => 'Business Size', 'default' => false],
            ['key' => 'initial_capital', 'label' => 'Initial Capital', 'default' => false],
            ['key' => 'mode_of_payment', 'label' => 'Modes of Payment', 'default' => false],
            ['key' => 'distribution_channels', 'label' => 'Distribution Channels', 'default' => false],
            ['key' => 'availed_before', 'label' => 'Previously Availed Assistance', 'default' => false],
            ['key' => 'assistance_availed', 'label' => 'Assistance Availed', 'default' => false],
            ['key' => 'past_programs', 'label' => 'Past Programs', 'default' => false],
            ['key' => 'programs_needed', 'label' => 'Programs Needed', 'default' => false],
            ['key' => 'challenges_encountered', 'label' => 'Challenges Encountered', 'default' => false],
        ];
    }

    if (strpos($program, 'SPES') !== false) {
        return [
            ['key' => 'last_name', 'label' => 'Last Name', 'default' => true],
            ['key' => 'first_name', 'label' => 'First Name', 'default' => true],
            ['key' => 'middle_name', 'label' => 'Middle Name', 'default' => true],
            ['key' => 'sex', 'label' => 'Sex', 'default' => true],
            ['key' => 'birthdate', 'label' => 'Date of Birth', 'default' => true],
            ['key' => 'age', 'label' => 'Age', 'default' => false],
            ['key' => 'complete_address', 'label' => 'Complete Address', 'default' => true],
            ['key' => 'barangay', 'label' => 'Barangay', 'default' => true],
            ['key' => 'contact_no', 'label' => 'Contact Number', 'default' => true],
            ['key' => 'avg_monthly_income', 'label' => 'Estimated Monthly Income', 'default' => false],
            ['key' => 'ext_name', 'label' => 'Extension Name', 'default' => false],
            ['key' => 'civil_status', 'label' => 'Civil Status', 'default' => false],
            ['key' => 'email', 'label' => 'Email Address', 'default' => false],
            ['key' => 'gsis_beneficiary_name', 'label' => 'GSIS Beneficiary / Policy Details', 'default' => false],
            ['key' => 'place_of_birth', 'label' => 'Place of Birth', 'default' => false],
            ['key' => 'citizenship', 'label' => 'Citizenship', 'default' => false],
            ['key' => 'social_media', 'label' => 'Social Media', 'default' => false],
            ['key' => 'spes_type', 'label' => 'Student Status', 'default' => false],
            ['key' => 'parents_status', 'label' => 'Parent Status', 'default' => false],
            ['key' => 'permanent_address', 'label' => 'Permanent Address', 'default' => false],
            ['key' => 'father_name', 'label' => "Father's Name", 'default' => false],
            ['key' => 'father_contact', 'label' => "Father's Contact", 'default' => false],
            ['key' => 'father_occupation', 'label' => "Father's Occupation", 'default' => false],
            ['key' => 'mother_name', 'label' => "Mother's Name", 'default' => false],
            ['key' => 'mother_contact', 'label' => "Mother's Contact", 'default' => false],
            ['key' => 'mother_occupation', 'label' => "Mother's Occupation", 'default' => false],
            ['key' => 'elem_school', 'label' => 'Elementary School', 'default' => false],
            ['key' => 'elem_degree', 'label' => 'Elementary Honors', 'default' => false],
            ['key' => 'elem_year_level', 'label' => 'Elementary Level', 'default' => false],
            ['key' => 'elem_date_attendance', 'label' => 'Elementary Attendance', 'default' => false],
            ['key' => 'sec_school', 'label' => 'Secondary School', 'default' => false],
            ['key' => 'sec_degree', 'label' => 'Secondary Track / Strand', 'default' => false],
            ['key' => 'sec_year_level', 'label' => 'Secondary Level', 'default' => false],
            ['key' => 'sec_date_attendance', 'label' => 'Secondary Attendance', 'default' => false],
            ['key' => 'tert_school', 'label' => 'Tertiary School', 'default' => false],
            ['key' => 'tert_course', 'label' => 'Tertiary Course', 'default' => false],
            ['key' => 'tert_year_level', 'label' => 'Tertiary Level', 'default' => false],
            ['key' => 'tert_date_attendance', 'label' => 'Tertiary Attendance', 'default' => false],
            ['key' => 'tv_school', 'label' => 'Technical / Vocational School', 'default' => false],
            ['key' => 'tv_course', 'label' => 'Technical / Vocational Course', 'default' => false],
            ['key' => 'tv_year_level', 'label' => 'Technical / Vocational Level', 'default' => false],
            ['key' => 'tv_date_attendance', 'label' => 'Technical / Vocational Attendance', 'default' => false],
            ['key' => 'special_skills', 'label' => 'Special Skills', 'default' => false],
            ['key' => 'spes_history', 'label' => 'SPES History', 'default' => false],
        ];
    }

    return [
        ['key' => 'last_name', 'label' => 'Last Name', 'default' => true],
        ['key' => 'first_name', 'label' => 'First Name', 'default' => true],
        ['key' => 'middle_name', 'label' => 'Middle Name', 'default' => true],
        ['key' => 'ext_name', 'label' => 'Extension Name', 'default' => false],
        ['key' => 'sex', 'label' => 'Sex', 'default' => true],
        ['key' => 'birthdate', 'label' => 'Date of Birth', 'default' => true],
        ['key' => 'age', 'label' => 'Age', 'default' => false],
        ['key' => 'civil_status', 'label' => 'Civil Status', 'default' => false],
        ['key' => 'contact_no', 'label' => 'Contact Number', 'default' => true],
        ['key' => 'complete_address', 'label' => 'Complete Address', 'default' => true],
        ['key' => 'barangay', 'label' => 'Barangay', 'default' => true],
        ['key' => 'occupation', 'label' => 'Occupation', 'default' => true],
        ['key' => 'skills_training_needed', 'label' => 'Skills', 'default' => true],
        ['key' => 'avg_monthly_income', 'label' => 'Family Income', 'default' => false],
        ['key' => 'dependent_name', 'label' => 'Dependents', 'default' => false],
        ['key' => 'type_of_id', 'label' => 'ID Type', 'default' => true],
        ['key' => 'id_number', 'label' => 'ID Number', 'default' => true],
        ['key' => 'type_of_beneficiary', 'label' => 'Type of Beneficiary', 'default' => false],
        ['key' => 'dependent_relationship', 'label' => 'Dependent Relationship', 'default' => false],
        ['key' => 'interested_in_employment', 'label' => 'Interested in Employment', 'default' => false],
    ];
}

function getSelectedReportColumns(string $programName, array $requested): array
{
    $definitions = getReportColumnDefinitions($programName);
    $allowed = array_column($definitions, null, 'key');
    $requested = array_values(array_filter($requested, static fn($key) => isset($allowed[$key])));

    if (!$requested) {
        $requested = array_column($definitions, 'key');
    }

    $selected = [];
    foreach ($definitions as $column) {
        if (in_array($column['key'], $requested, true)) {
            $selected[] = $column;
        }
    }
    return $selected;
}

function reportDateValue($value): string
{
    if (empty($value)) return '';
    $timestamp = strtotime((string)$value);
    return $timestamp ? date('Y/m/d', $timestamp) : (string)$value;
}

function getReportCellValue(array $row, string $key): string
{
    switch ($key) {
        case 'primary_products':
        case 'product_price':
            $rawProducts = trim((string)($row['primary_products'] ?? ''));
            $storedPrices = trim((string)($row['product_price'] ?? ''));
            $names = [];
            $prices = [];
            $decoded = json_decode($rawProducts, true);
            if (is_array($decoded)) {
                foreach ($decoded as $product) {
                    if (!is_array($product) || trim((string)($product[0] ?? '')) === '') continue;
                    $names[] = trim((string)$product[0]);
                    $prices[] = trim((string)($product[1] ?? ''));
                }
            } else {
                foreach (preg_split('/,\s*(?![^()]*\))/', $rawProducts, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $product) {
                    if (preg_match('/^(.*?)\s*\((?:₱|PHP\s*)?([0-9.,]+)\)$/iu', trim($product), $matches)) {
                        $names[] = trim($matches[1]);
                        $prices[] = trim($matches[2]);
                    } else {
                        $names[] = trim($product);
                    }
                }
            }
            if ($storedPrices !== '') $prices = array_map('trim', explode(',', $storedPrices));
            return $key === 'primary_products' ? implode(', ', $names) : implode(', ', array_filter($prices, static fn($price) => $price !== ''));
        case 'owner_name':
            return trim((string)(($row['full_name'] ?? '') ?: (($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? '') . ' ' . ($row['ext_name'] ?? ''))));
        case 'owner_contact':
            return (string)($row['contact_no'] ?? '');
        case 'complete_address':
            if (!empty($row['address'])) return trim((string)$row['address']);
            return trim(($row['street_purok_zone'] ?? '') . ' ' . ($row['barangay'] ?? '') . ' Vinzons, Camarines Norte');
        case 'birthdate':
        case 'created_at':
        case 'start_date':
        case 'end_date':
        case 'nm_date_started':
        case 'permit_validity':
            return reportDateValue($row[$key] ?? '');
        case 'age':
            $age = (int)($row['age'] ?? 0);
            if ($age === 0 && !empty($row['birthdate'])) {
                try { $age = (new DateTime($row['birthdate']))->diff(new DateTime())->y; } catch (Exception $e) { $age = 0; }
            }
            return $age > 0 ? (string)$age : '';
        case 'school':
            return (string)(($row['tert_school'] ?? '') ?: (($row['sec_school'] ?? '') ?: ($row['elem_school'] ?? '')));
        case 'course':
            return (string)(($row['tert_course'] ?? '') ?: ($row['sec_degree'] ?? ''));
        case 'level':
            return (string)(($row['tert_year_level'] ?? '') ?: (($row['sec_year_level'] ?? '') ?: ($row['elem_year_level'] ?? '')));
        case 'parent_name':
            return (string)(($row['father_name'] ?? '') ?: (($row['mother_name'] ?? '') ?: ($row['gsis_beneficiary_name'] ?? '')));
        case 'parent_occupation':
            return (string)(($row['father_occupation'] ?? '') ?: ($row['mother_occupation'] ?? ''));
        case 'approval_status':
            $status = strtoupper((string)($row['approval_status'] ?? ''));
            if ($status === 'APPROVED') return 'QUALIFIED';
            if ($status === 'REJECTED') return 'DISQUALIFIED';
            return $status;
        case 'remarks':
            return (string)($row['approval_note'] ?? '');
        case 'hr_total':
            return (string)(!empty($row['hr_total']) ? $row['hr_total'] : ((int)($row['hr_male'] ?? 0) + (int)($row['hr_female'] ?? 0)));
        case 'employment_type':
            if (!empty($row['employment_type'])) return (string)$row['employment_type'];
            $types = [];
            foreach (['emp_regular' => 'Regular', 'emp_seasonal' => 'Seasonal', 'emp_contractual' => 'Contractual', 'emp_family' => 'Family'] as $field => $label) {
                if (!empty($row[$field])) $types[] = $label;
            }
            return implode(', ', $types);
        case 'spes_history':
            $history = trim((string)($row['spes_history'] ?? ''));
            $decoded = json_decode($history, true);
            if (is_array($decoded)) {
                $entries = [];
                foreach ($decoded as $entry) {
                    if (!is_array($entry)) continue;
                    $parts = array_values(array_filter(array_map(static fn($value) => trim((string)$value), $entry), static fn($value) => $value !== ''));
                    if ($parts) $entries[] = implode(' / ', $parts);
                }
                if ($entries) return implode('; ', $entries);
            }
            if ($history !== '') return $history;
            $entries = [];
            for ($number = 1; $number <= 4; $number++) {
                $parts = array_filter([
                    trim((string)($row["spes_history_{$number}_year"] ?? '')),
                    trim((string)($row["spes_history_{$number}_id"] ?? '')),
                ], static fn($value) => $value !== '');
                if ($parts) $entries[] = implode(' / ', $parts);
            }
            return implode('; ', $entries);
        case 'current_capital':
            return (string)($row['current_capital'] ?? ($row['initial_capital'] ?? ''));
        default:
            return (string)($row[$key] ?? '');
    }
}
