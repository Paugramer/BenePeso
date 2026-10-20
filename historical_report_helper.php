<?php
/** One historical scope shared by the report page, API and exports. */
function historical_record_condition(string $alias = 'b'): string {
    // Imported legacy records still awaiting acceptance remain in the review queue.
    return "($alias.approval_status = 'Rejected' OR ($alias.approval_status = 'Approved' AND COALESCE($alias.availment_status, '') IN ('Completed','Not Qualified','Exam Failed','Cancelled')))";
}

function reporting_details_available(mysqli $conn): bool {
    $result = $conn->query("SHOW TABLES LIKE 'beneficiary_reporting_details'");
    return $result && $result->num_rows > 0;
}

function enrich_reporting_rows(mysqli $conn, array $rows): array {
    if (!$rows || !reporting_details_available($conn)) return $rows;
    $ids = array_values(array_filter(array_map(static fn($row) => (int)$row['beneficiary_id'], $rows)));
    if (!$ids) return $rows;
    $result = $conn->query('SELECT * FROM beneficiary_reporting_details WHERE beneficiary_id IN (' . implode(',', $ids) . ')');
    $details = [];
    while ($result && ($record = $result->fetch_assoc())) $details[(int)$record['beneficiary_id']] = $record;
    foreach ($rows as &$row) $row = array_merge($row, $details[(int)$row['beneficiary_id']] ?? []);
    unset($row);
    return $rows;
}

function official_report_columns(string $program): array {
    if (stripos($program, 'SPES') !== false) {
        $map = ['last_name'=>'LAST NAME','first_name'=>'FIRST NAME','middle_name'=>'MIDDLE NAME','sex'=>'SEX',
            'birthdate'=>'DATE OF BIRTH (yyyy/mm/dd)','age'=>'AGE','complete_address'=>'COMPLETE ADDRESS','barangay'=>'BARANGAY',
            'contact_no'=>'CONTACT NUMBER','school'=>'SCHOOL','course'=>'COURSE/STRAND','level'=>'LEVEL',
            'report_parent_name'=>'PARENT/GUARDIAN NAME','report_parent_occupation'=>'PARENT OCCUPATION',
            'avg_monthly_income'=>'ESTIMATED MONTHLY INCOME','created_at'=>'DATE OF APPLICATION (yyyy/mm/dd)',
            'approval_status'=>'STATUS (PENDING/QUALIFIED/DISQUALIFIED)','work_assignment'=>'WORK ASSIGNMENT',
            'employment_start'=>'START DATE (yyyy/mm/dd)','employment_end'=>'END DATE (yyyy/mm/dd)','report_remarks'=>'REMARKS'];
    } elseif (stripos($program, 'TUPAD') !== false) {
        // F/G have no heading in the supplied template; retain their blank columns.
        $map = ['first_name'=>'First Name','middle_name'=>'Middle Name','last_name'=>'Last Name','ext_name'=>'Ext. Name',
            'reserved_f'=>'','reserved_g'=>'','birthdate'=>'Birthdate (YYYY/MM/DD)','street_purok_zone'=>'St./ Zone',
            'barangay'=>'Brgy','municipality'=>'City/ Mun.','report_province'=>'Prov.','district'=>'Dist.',
            'type_of_id'=>'Type of ID','id_number'=>'ID Number','contact_no'=>'Contact No.','type_of_beneficiary'=>'Type of Beneficiary',
            'occupation'=>'Occupation','sex'=>'Sex','civil_status'=>'Civil Status','age'=>'Age','avg_monthly_income'=>'Avg. Monthly Income',
            'dependent_name'=>'Dependent','dependent_relationship'=>'Relationship to Dependent',
            'interested_in_employment'=>'Interested in wage employment or self-employment? (Yes/No). If yes, specify',
            'skills_training_needed'=>'Skills Training Needed','report_remarks'=>'REMARKS','report_remarks_secondary'=>'REMARKS'];
    } elseif (stripos($program, 'MSME') !== false) {
        $map = ['business_name'=>'Business/Trade Name','ownership_type'=>'Type of ownership','business_nature'=>'Nature of Business',
            'primary_products'=>'Primary Products offered','product_price'=>'Product Price','year_started'=>'Year Business Started',
            'business_permit_no'=>'Business Permit No:','permit_validity'=>'Valid Until','dti_no'=>'DTI Registration No.',
            'tin_no'=>'Tax Identification No. (TIN)','contact_no'=>'Landline/Mobile Number','business_email'=>'Email',
            'business_social_media'=>'Website/Social Media','owner_name'=>'Full Name','owner_contact'=>'Contact Number',
            'sex'=>'Sex','birthdate'=>'Date of Birth','age'=>'Age','civil_status'=>'Civil Status','complete_address'=>'Complete Address',
            'educational_attainment'=>'Educational Attainment','work_experience'=>'Current Work/Business Experience',
            'nm_stall_no'=>'Night Market Stall/Booth No.','nm_date_started'=>'Date Started','assets_owned'=>'Business Assets Owned',
            'utility_needs'=>'Utility Needs','report_workers'=>'Number of Workers (M&F)','employment_type'=>'Employment Status',
            'hr_skills'=>'Skills Training Methold','source_of_capital'=>'Source of Capital','business_size'=>'Business Size (RA 9501 - Magna Carta for MSMEs)',
            'initial_capital'=>'Amount of Initial Capital','current_capital'=>'Current Estimated Capital','daily_earnings'=>'How much does your business earn on regular business days?',
            'nm_two_day_earnings'=>'How much did you earn for the two days night market every week?',
            'nm_total_earnings'=>'How much have you earned since you started selling at night market?',
            'mode_of_payment'=>'Mode of Payment Accepted','distribution_channels'=>'Distribution Channels','availed_before'=>'Have you availed any government programs/support?',
            'assistance_availed'=>'If Yes, please specify','past_programs'=>'Programs/Services Availed in the Past',
            'programs_needed'=>'Programs/Services Needed','challenges_encountered'=>'Please sellect what applies'];
    } else return getReportColumnDefinitions($program);
    $columns = [];
    $commonFields=['first_name','last_name','middle_name','ext_name','birthdate','street_purok_zone','barangay','report_province'];
    // SPES/MSME retain their source template's combined name/address columns.
    if (stripos($program,'TUPAD')===false) $commonFields=array_merge($commonFields,['owner_name','complete_address']);
    foreach ($map as $key=>$label) $columns[] = ['key'=>$key,'label'=>$label,'default'=>in_array($key,$commonFields,true)];
    return $columns;
}

function official_report_list($value): array {
    if (is_array($value)) return array_values(array_map('strval', $value));
    $raw = trim((string)$value); if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) return array_values(array_map('strval', $decoded));
    return array_map('trim', preg_split('/[;\r\n]+/', $raw));
}

/** Accept both the paired product form and legacy separate product/price lists. */
function official_report_product_lists(array $row): array {
    $raw=$row['primary_products'] ?? '';
    $decoded=is_array($raw)?$raw:json_decode((string)$raw,true);
    if(is_array($decoded) && is_array($decoded[0] ?? null)) {
        $products=[]; $prices=[];
        foreach($decoded as $pair) {
            if(!is_array($pair)) continue;
            $name=$pair[0] ?? $pair['name'] ?? ''; $price=$pair[1] ?? $pair['price'] ?? '';
            $products[]=is_scalar($name)?(string)$name:'';
            $prices[]=is_scalar($price)?(string)$price:'';
        }
        $storedPrices=official_report_list($row['product_price'] ?? '');
        return [$products,$storedPrices ?: $prices];
    }
    return [official_report_list($raw),official_report_list($row['product_price'] ?? '')];
}

function official_report_value(array $row, string $key): string {
    if ($key === 'approval_status' && in_array($row['availment_status'] ?? '', ['Not Qualified','Exam Failed'], true)) return 'DISQUALIFIED';
    if (in_array($key, ['primary_products','product_price'], true)) { $lists=official_report_product_lists($row); return implode("\n",$lists[$key==='primary_products'?0:1]); }

    if ($key === 'report_workers') return 'Male: '.(int)($row['hr_male'] ?? 0)."\nFemale: ".(int)($row['hr_female'] ?? 0)."\nTotal: ".getReportCellValue($row,'hr_total');
    if ($key === 'employment_type') {
        $entries=[];
        foreach(['emp_regular'=>'Regular','emp_seasonal'=>'Seasonal','emp_contractual'=>'Contractual','emp_family'=>'Family'] as $field=>$label) {
            if (array_key_exists($field,$row) && $row[$field]!==null && $row[$field]!=='') $entries[]=$label.': '.(int)$row[$field];
        }
        return implode("\n",$entries);
    }
    if (in_array($key, ['reserved_f','reserved_g'], true)) return '';
    if ($key === 'report_parent_name') return (string)(($row['father_name'] ?? '') ?: (($row['mother_name'] ?? '') ?: ($row['report_parent_name'] ?? '')));
    if ($key === 'report_parent_occupation') {
        if (!empty($row['father_name'])) return (string)($row['father_occupation'] ?? '');
        if (!empty($row['mother_name'])) return (string)($row['mother_occupation'] ?? '');
        return (string)($row['report_parent_occupation'] ?? '');
    }
    if ($key === 'report_province') return (string)(($row['province'] ?? '') ?: 'Camarines Norte');
    if ($key === 'report_remarks') return (string)(($row['report_remarks'] ?? '') ?: ($row['approval_note'] ?? ''));
    if ($key === 'employment_start' && in_array($row['availment_status'] ?? '', ['Ongoing','Completed'], true) && !empty($row['date_availed'])) $row[$key] = $row['date_availed'];
    if ($key === 'employment_end' && ($row['availment_status'] ?? '') === 'Completed' && !empty($row['date_completed'])) $row[$key] = $row['date_completed'];
    if (in_array($key, ['birthdate','created_at','employment_start','employment_end'], true)) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value === '') return '';
        try { return (new DateTime($value))->format('Y/m/d'); } catch (Exception $e) { return ''; }
    }
    if (in_array($key, ['school','course','level'], true)) {
        foreach (['tert','tv','sec','elem'] as $stage) {
            if (trim((string)($row[$stage.'_school'] ?? '')) === '') continue;
            $field = $key === 'school' ? 'school' : ($key === 'level' ? 'year_level' : ($stage === 'sec' ? 'degree' : 'course'));
            return (string)($row[$stage.'_'.$field] ?? '');
        }
        return '';
    }
    return getReportCellValue($row, $key);
}
