<?php
session_start();
require "db.php";
require_once "report_columns.php";
require_once "xlsx_report_helper.php";

// Clean output buffer to ensure no stray spaces break the Excel file
if (ob_get_length()) {
    ob_clean();
}

// 1. Security Check
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found.");
}

// Allow BOTH Admins and PESO Staff to export the report.
if (
    empty($_SESSION["admin_id"]) &&
    empty($_SESSION["peso_staff_id"]) &&
    empty($_SESSION["staff_id"])
) {
    die("<h3>Unauthorized access. Please log in.</h3>");
}

// 2. Fetch Form Data
$program_id   = (int)($_GET['program_id'] ?? 0); 
$program_name = trim($_GET['program_name'] ?? '');
$barangay     = trim($_GET['report_barangay'] ?? 'All');
$availment    = trim($_GET['report_availment'] ?? 'All');
$business_nature = trim($_GET['business_nature'] ?? 'All');
$format       = strtolower(trim($_GET['export_format'] ?? 'xlsx'));

if (empty($program_name)) {
    die("Invalid Program Name. Please go back and select a valid program.");
}

// 3. Build the Beneficiaries Query
$whereParts = ["p.program_name = ?"];
$params = [$program_name];
$types = "s";

if ($program_id > 0) {
    $whereParts[] = "b.program_id = ?";
    $params[] = $program_id;
    $types .= "i";
}

if ($barangay !== "All") {
    $whereParts[] = "b.barangay = ?";
    $params[] = $barangay;
    $types .= "s";
}

if ($availment !== "All") {
    $whereParts[] = "b.availment_status = ?";
    $params[] = $availment;
    $types .= "s";
}

if (stripos($program_name, 'MSME') !== false && $business_nature !== "All") {
    $whereParts[] = "b.business_nature = ?";
    $params[] = $business_nature;
    $types .= "s";
}

// EXACT ALPHABETICAL SORTING
$orderBy = ($barangay === "All") ? "b.barangay ASC, b.last_name ASC, b.first_name ASC" : "b.last_name ASC, b.first_name ASC";

$sql = "SELECT b.*, p.program_name, p.start_date, p.end_date 
        FROM beneficiaries b 
        JOIN programs p ON b.program_id = p.program_id 
        WHERE " . implode(" AND ", $whereParts) . " 
        ORDER BY $orderBy";

$stmt = $conn->prepare($sql);
$beneficiaries = [];

if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $beneficiaries[] = $row;
    }
    $stmt->close();
}

$dateGenerated = date("F d, Y h:i A");
$safeProgramName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $program_name);
$filename = "Beneficiaries_" . $safeProgramName . "_" . date("Ymd");
$reportTitle = getReportProgramTitle($program_name);
$subtitle = htmlspecialchars(getReportLocationLabel($barangay));

if ($format === 'xlsx') {
    $requestedColumns = isset($_GET['columns']) && is_array($_GET['columns']) ? $_GET['columns'] : [];
    $selectedColumns = getSelectedReportColumns($program_name, $requestedColumns);
    $subtitleParts = [];
    if ($program_id > 0) $subtitleParts[] = 'Selected batch';
    $subtitleParts[] = getReportLocationLabel($barangay);
    if ($availment !== 'All') $subtitleParts[] = 'Availment: ' . $availment;
    if ($business_nature !== 'All') $subtitleParts[] = 'Vendor Type: ' . $business_nature;
    outputNativeXlsxReport($reportTitle, $selectedColumns, $beneficiaries, implode(' | ', $subtitleParts));
}

// =========================================================================
// STANDARD EXCEL DOCUMENT WRAPPER (Forces Native Excel Styling)
// =========================================================================
$excel_header = '
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <link rel="icon" type="image/png" href="img/pesologo.png">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<style>
    table { border-collapse: collapse; table-layout: fixed; }
    td, th { 
        font-family: "Calibri", Arial, sans-serif; 
        font-size: 11.0pt; 
        white-space: nowrap; 
        vertical-align: bottom; 
        padding: 2px 4px;
        color: #000000;
    }
    .grid-header { 
        border: .5pt solid windowtext; 
        font-weight: bold; 
        text-align: center; 
        vertical-align: middle;
        background-color: #FFFFFF;
    }
    .grid-cell { border: .5pt solid windowtext; vertical-align: bottom; }
    .grid-cell-center { border: .5pt solid windowtext; text-align: center; vertical-align: bottom; }
    .no-border { border: none; }
</style>
</head>
<body>
<table>';

$excel_footer = '</table></body></html>';


/* =========================================================================
   TUPAD EXCEL TEMPLATE GENERATION (100% Exact Match)
========================================================================= */
if ($format === 'excel_template' && strtoupper($program_name) === 'TUPAD') {
    
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"TUPAD_Official_Report_" . date("Ymd") . ".xls\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo $excel_header;
    
    // Top Headers
    echo '<tr><td colspan="27" class="no-border" style="text-align:left; font-weight:bold; font-size:14.0pt;">Profile of TUPAD Beneficiaries</td></tr>';
    echo '<tr><td colspan="4" class="no-border" style="font-weight:bold;">Nature of Project:</td><td colspan="23" class="no-border"></td></tr>'; 
    echo '<tr><td colspan="4" class="no-border" style="font-weight:bold;">DOLE Regional Office:</td><td colspan="23" class="no-border" style="text-align:left;">5</td></tr>';
    echo '<tr><td colspan="4" class="no-border" style="font-weight:bold;">Province:</td><td colspan="23" class="no-border" style="text-align:left;">Camarines Norte</td></tr>';
    echo '<tr><td colspan="4" class="no-border" style="font-weight:bold;">Municipality:</td><td colspan="23" class="no-border" style="text-align:left;">LGU VINZONS</td></tr>';
    echo '<tr><td colspan="4" class="no-border" style="font-weight:bold;">Location:</td><td colspan="23" class="no-border" style="text-align:left;">'.htmlspecialchars(getReportLocationLabel($barangay)).'</td></tr>';
    echo '<tr><td colspan="27" class="no-border"></td></tr>'; 

    // Formal Column Headers
    echo '<tr>';
    echo '<th rowspan="2" class="grid-header">No.</th>';
    echo '<th colspan="6" class="grid-header">Name of Beneficiary</th>';
    echo '<th rowspan="2" class="grid-header">Birthdate<br>(YYYY/MM/DD)</th>';
    echo '<th colspan="5" class="grid-header">Address</th>';
    echo '<th rowspan="2" class="grid-header">Type of ID</th>';
    echo '<th rowspan="2" class="grid-header">ID Number</th>';
    echo '<th rowspan="2" class="grid-header">Contact No.</th>';
    echo '<th rowspan="2" class="grid-header">Type of Beneficiary</th>';
    echo '<th rowspan="2" class="grid-header">Occupation</th>';
    echo '<th rowspan="2" class="grid-header">Sex</th>';
    echo '<th rowspan="2" class="grid-header">Civil Status</th>';
    echo '<th rowspan="2" class="grid-header">Age</th>';
    echo '<th rowspan="2" class="grid-header">Avg. Monthly Income</th>';
    echo '<th rowspan="2" class="grid-header">Dependent</th>';
    echo '<th rowspan="2" class="grid-header">Relationship to Dependent</th>';
    echo '<th rowspan="2" class="grid-header">Interested in wage employment or self-employment?<br>(Yes/No)<br>If Yes, pls. specify</th>';
    echo '<th rowspan="2" class="grid-header">Skills Training Needed</th>';
    echo '<th rowspan="2" class="grid-header">REMARKS</th>';
    echo '</tr>';

    echo '<tr>';
    echo '<th class="grid-header">First Name</th>';
    echo '<th class="grid-header">Middle Name</th>';
    echo '<th class="grid-header">Last Name</th>';
    echo '<th class="grid-header">Ext. Name</th>';
    echo '<th class="grid-header"></th>'; 
    echo '<th class="grid-header"></th>'; 
    echo '<th class="grid-header">St./ Zone</th>';
    echo '<th class="grid-header">Brgy</th>';
    echo '<th class="grid-header">City/ Mun.</th>';
    echo '<th class="grid-header">Prov.</th>';
    echo '<th class="grid-header">Dist.</th>';
    echo '</tr>';

    if (empty($beneficiaries)) {
        echo '<tr><td colspan="27" class="grid-cell-center" style="padding: 15px;">No records found.</td></tr>';
    } else {
        $counter = 1;
        foreach ($beneficiaries as $b) {
            $bdate = !empty($b['birthdate']) ? date("Y/m/d", strtotime($b['birthdate'])) : '';
            
            // AUTOMATIC AGE CALCULATOR FIX
            $age = (int)($b['age'] ?? 0);
            if ($age === 0 && !empty($b['birthdate'])) {
                try {
                    $dob = new DateTime($b['birthdate']);
                    $now = new DateTime();
                    $age = $now->diff($dob)->y;
                } catch (Exception $e) { $age = 0; }
            }
            $displayAge = $age > 0 ? $age : '';

            echo '<tr>';
            echo '<td class="grid-cell-center">' . $counter++ . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['first_name'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['middle_name'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['last_name'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['ext_name'] ?? '') . '</td>';
            echo '<td class="grid-cell"></td>'; 
            echo '<td class="grid-cell"></td>'; 
            echo '<td class="grid-cell-center" style="mso-number-format:\'yyyy\/mm\/dd\';">' . $bdate . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['street_purok_zone'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['barangay'] ?? '') . '</td>';
            echo '<td class="grid-cell">VINZONS</td>';
            echo '<td class="grid-cell">CN</td>';
            echo '<td class="grid-cell-center">2ND</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['type_of_id'] ?? '') . '</td>';
            echo '<td class="grid-cell-center" style="mso-number-format:\'@\';">' . htmlspecialchars($b['id_number'] ?? '') . '</td>';
            echo '<td class="grid-cell-center" style="mso-number-format:\'@\';">' . htmlspecialchars($b['contact_no'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['type_of_beneficiary'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['occupation'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['sex'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['civil_status'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . $displayAge . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['avg_monthly_income'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['dependent_name'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['dependent_relationship'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['interested_in_employment'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['skills_training_needed'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['approval_note'] ?? '') . '</td>';
            echo '</tr>';
        }
    }
    echo $excel_footer;
    exit();
}

/* =========================================================================
   MSME EXCEL TEMPLATE GENERATION (100% Exact Match)
========================================================================= */
elseif (strtoupper($program_name) === 'MSME PROFILING' || stripos($program_name, 'MSME') !== false) {
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"MSME_Official_Report_" . date("Ymd") . ".xls\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo $excel_header;

    // Main Grouped Grid Headers Updated to 46 Columns
    echo '<tr>';
    echo '<th rowspan="2" class="grid-header" style="width:40px;">No.</th>';
    echo '<th colspan="13" class="grid-header">I. BUSINESS PROFILE</th>';
    echo '<th colspan="9" class="grid-header">II. OWNER/ENTREPRENEUR INFORMATION</th>';
    echo '<th colspan="4" class="grid-header">III. BUSINESS OPERATIONS</th>';
    echo '<th colspan="5" class="grid-header">IV. HUMAN RESOURCES</th>';
    echo '<th colspan="7" class="grid-header">V. FINANCIAL INFORMATION</th>';
    echo '<th colspan="7" class="grid-header">VI. GOVERNMENT ASSISTANCE</th>';
    echo '</tr>';

    // Sub-Headers Exactly Matching the Preview Implementation
    echo '<tr>';
    echo '<th class="grid-header">Business/Trade Name</th><th class="grid-header">Type of Ownership</th><th class="grid-header">Nature of Business</th><th class="grid-header">Primary Products Offered</th><th class="grid-header">Product Price</th><th class="grid-header">Year Business Started</th><th class="grid-header">Business Permit No.</th><th class="grid-header">Valid Until</th><th class="grid-header">DTI Registration No.</th><th class="grid-header">Tax Identification No. (TIN)</th><th class="grid-header">Landline/Mobile Number</th><th class="grid-header">Email</th><th class="grid-header">Website/Social Media</th>';
    echo '<th class="grid-header">Full Name</th><th class="grid-header">Contact Number</th><th class="grid-header">Sex</th><th class="grid-header">Date of Birth</th><th class="grid-header">Age</th><th class="grid-header">Civil Status</th><th class="grid-header">Complete Address</th><th class="grid-header">Educational Attainment</th><th class="grid-header">Work Experience</th>';
    echo '<th class="grid-header">Stall/Booth No.</th><th class="grid-header">Date Started</th><th class="grid-header">Business Assets Owned</th><th class="grid-header">Utility Needs</th>';
    echo '<th class="grid-header">Number of Workers</th><th class="grid-header">Male Employees</th><th class="grid-header">Female Employees</th><th class="grid-header">Employment Type</th><th class="grid-header">Skills Needed</th>';
    echo '<th class="grid-header">Estimated Daily Sales</th><th class="grid-header">Estimated Monthly Sales</th><th class="grid-header">Estimated Capital</th><th class="grid-header">Source of Capital</th><th class="grid-header">Average Monthly Expenses</th><th class="grid-header">Banking Access</th><th class="grid-header">Existing Loans/Credit</th>';
    echo '<th class="grid-header">DTI Assistance</th><th class="grid-header">DOLE Assistance</th><th class="grid-header">LGU Assistance</th><th class="grid-header">TESDA Training</th><th class="grid-header">Financial Assistance</th><th class="grid-header">Livelihood Assistance</th><th class="grid-header">Business Training</th>';
    echo '</tr>';

    if (empty($beneficiaries)) {
        echo '<tr><td colspan="46" class="grid-cell-center" style="padding: 15px;">No records found.</td></tr>';
    } else {
        $counter = 1;
        foreach ($beneficiaries as $b) {
            $dispName = trim(($b["first_name"] ?? "") . " " . ($b["middle_name"] ?? "") . " " . ($b["last_name"] ?? "") . " " . ($b["ext_name"] ?? ""));
            if ($dispName === "") $dispName = $b["full_name"];
            $ownerName = $dispName;
            
            $address = trim(($b['street_purok_zone'] ?? '') . ' ' . ($b['barangay'] ?? '') . ' Vinzons, Camarines Norte');
            $bdate = !empty($b['birthdate']) ? date("Y/m/d", strtotime($b['birthdate'])) : '';
            
            // AUTOMATIC AGE CALCULATOR FIX
            $age = (int)($b['age'] ?? 0);
            if ($age === 0 && !empty($b['birthdate'])) {
                try {
                    $dob = new DateTime($b['birthdate']);
                    $now = new DateTime();
                    $age = $now->diff($dob)->y;
                } catch (Exception $e) { $age = 0; }
            }
            $displayAge = $age > 0 ? $age : '';

            // HR Extraction safely applied
            $hrMale = (int)($b['hr_male'] ?? 0);
            $hrFem = (int)($b['hr_female'] ?? 0);
            $totalHR = !empty($b['hr_total']) ? $b['hr_total'] : ($hrMale + $hrFem);
            
            $empTypes = [];
            if (!empty($b['emp_regular'])) $empTypes[] = "Regular";
            if (!empty($b['emp_seasonal'])) $empTypes[] = "Seasonal";
            if (!empty($b['emp_contractual'])) $empTypes[] = "Contractual";
            if (!empty($b['emp_family'])) $empTypes[] = "Family";
            $empTypeStr = !empty($b['employment_type']) ? $b['employment_type'] : implode(", ", $empTypes);

            // Products Merged correctly
            $prodCombined = $b['primary_products'] ?? '';
            if (!empty($b['product_price']) && $prodCombined !== '') {
                $prodCombined .= ' (' . $b['product_price'] . ')';
            }

            // Assistance checks seamlessly
            $hasDTI = !empty($b['dti_assistance']) ? $b['dti_assistance'] : ((strpos($b['assistance_availed'] ?? '', 'DTI') !== false) ? 'Yes' : '');
            $hasDOLE = !empty($b['dole_assistance']) ? $b['dole_assistance'] : ((strpos($b['assistance_availed'] ?? '', 'DOLE') !== false) ? 'Yes' : '');
            $hasLGU = !empty($b['lgu_assistance']) ? $b['lgu_assistance'] : ((strpos($b['assistance_availed'] ?? '', 'LGU') !== false) ? 'Yes' : '');
            $hasTESDA = !empty($b['tesda_training']) ? $b['tesda_training'] : ((strpos($b['assistance_availed'] ?? '', 'TESDA') !== false) ? 'Yes' : '');
            $hasFin = !empty($b['financial_assistance']) ? $b['financial_assistance'] : ((strpos($b['programs_needed'] ?? '', 'Financing') !== false) ? 'Needed' : '');
            $hasLiv = !empty($b['livelihood_assistance']) ? $b['livelihood_assistance'] : ((strpos($b['assistance_availed'] ?? '', 'Livelihood') !== false) ? 'Yes' : '');
            $hasTrain = !empty($b['business_training']) ? $b['business_training'] : ((strpos($b['past_programs'] ?? '', 'Training') !== false) ? 'Yes' : '');

            echo '<tr>';
            echo '<td class="grid-cell-center">' . $counter++ . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['business_name'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['ownership_type'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['business_nature'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($prodCombined) . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['product_price'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['year_started'] ?? '') . '</td>';
            echo '<td class="grid-cell-center" style="mso-number-format:\'@\';">' . htmlspecialchars($b['business_permit_no'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['permit_validity'] ?? '') . '</td>';
            echo '<td class="grid-cell-center" style="mso-number-format:\'@\';">' . htmlspecialchars($b['dti_no'] ?? '') . '</td>';
            echo '<td class="grid-cell-center" style="mso-number-format:\'@\';">' . htmlspecialchars($b['tin_no'] ?? '') . '</td>';
            echo '<td class="grid-cell-center" style="mso-number-format:\'@\';">' . htmlspecialchars($b['contact_no'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['business_email'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['business_social_media'] ?? '') . '</td>';
            
            echo '<td class="grid-cell" style="font-weight:bold;">' . strtoupper(htmlspecialchars($ownerName)) . '</td>';
            echo '<td class="grid-cell-center" style="mso-number-format:\'@\';">' . htmlspecialchars($b['contact_no'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['sex'] ?? '') . '</td>';
            echo '<td class="grid-cell-center" style="mso-number-format:\'yyyy\/mm\/dd\';">' . $bdate . '</td>';
            echo '<td class="grid-cell-center">' . $displayAge . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['civil_status'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($address) . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['educational_attainment'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['work_experience'] ?? '') . '</td>';
            
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['nm_stall_no'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['nm_date_started'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['business_assets'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['utility_needs'] ?? '') . '</td>';

            echo '<td class="grid-cell-center">' . $totalHR . '</td>';
            echo '<td class="grid-cell-center">' . $hrMale . '</td>';
            echo '<td class="grid-cell-center">' . $hrFem . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($empTypeStr) . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['skills_training_needed'] ?? '') . '</td>';

            echo '<td class="grid-cell-center">' . htmlspecialchars($b['daily_earnings'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['monthly_earnings'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['current_capital'] ?? (!empty($b['initial_capital']) ? $b['initial_capital'] : '')) . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['source_of_capital'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['monthly_expenses'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['banking_access'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['existing_loans'] ?? '') . '</td>';

            echo '<td class="grid-cell-center">' . $hasDTI . '</td>';
            echo '<td class="grid-cell-center">' . $hasDOLE . '</td>';
            echo '<td class="grid-cell-center">' . $hasLGU . '</td>';
            echo '<td class="grid-cell-center">' . $hasTESDA . '</td>';
            echo '<td class="grid-cell-center">' . $hasFin . '</td>';
            echo '<td class="grid-cell-center">' . $hasLiv . '</td>';
            echo '<td class="grid-cell-center">' . $hasTrain . '</td>';
            
            echo '</tr>';
        }
    }
    echo $excel_footer;
    exit();
}


/* =========================================================================
   SPES EXCEL TEMPLATE GENERATION (100% Exact Match)
========================================================================= */
elseif (strtoupper($program_name) === 'SPES' || stripos($program_name, 'SPES') !== false) {
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"SPES_Official_Report_" . date("Ymd") . ".xls\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo $excel_header;

    // 22-Column Header 
    echo '<tr>';
    echo '<th class="grid-header" style="width:40px;">NO</th>';
    echo '<th class="grid-header">LAST NAME</th>';
    echo '<th class="grid-header">FIRST NAME</th>';
    echo '<th class="grid-header">MIDDLE NAME</th>';
    echo '<th class="grid-header">SEX</th>';
    echo '<th class="grid-header">DATE OF BIRTH<br>(yyyy/mm/dd)</th>';
    echo '<th class="grid-header">AGE</th>';
    echo '<th class="grid-header">COMPLETE ADDRESS</th>';
    echo '<th class="grid-header">BARANGAY</th>';
    echo '<th class="grid-header">CONTACT NUMBER</th>';
    echo '<th class="grid-header">SCHOOL</th>';
    echo '<th class="grid-header">COURSE/STRAND</th>';
    echo '<th class="grid-header">LEVEL</th>';
    echo '<th class="grid-header">PARENT/GUARDIAN NAME</th>';
    echo '<th class="grid-header">PARENT OCCUPATION</th>';
    echo '<th class="grid-header">ESTIMATED MONTHLY INCOME</th>';
    echo '<th class="grid-header">DATE OF APPLICATION<br>(yyyy/mm/dd)</th>';
    echo '<th class="grid-header">STATUS (PENDING/QUALIFIED/DISQUALIFIED)</th>';
    echo '<th class="grid-header">WORK ASSIGNMENT</th>';
    echo '<th class="grid-header">START DATE<br>(yyyy/mm/dd)</th>';
    echo '<th class="grid-header">END DATE<br>(yyyy/mm/dd)</th>';
    echo '<th class="grid-header">REMARKS</th>';
    echo '</tr>';

    // Populate Data
    if (empty($beneficiaries)) {
        echo '<tr><td colspan="22" class="grid-cell-center" style="padding: 15px;">No records found.</td></tr>';
    } else {
        $counter = 1;
        foreach ($beneficiaries as $b) {
            
            $bdate = !empty($b['birthdate']) ? date("Y/m/d", strtotime($b['birthdate'])) : '';
            $address = trim(($b['street_purok_zone'] ?? '') . ' ' . ($b['barangay'] ?? '') . ' Vinzons, Camarines Norte');
            
            // AUTOMATIC AGE CALCULATOR FIX
            $age = (int)($b['age'] ?? 0);
            if ($age === 0 && !empty($b['birthdate'])) {
                try {
                    $dob = new DateTime($b['birthdate']);
                    $now = new DateTime();
                    $age = $now->diff($dob)->y;
                } catch (Exception $e) { $age = 0; }
            }
            $displayAge = $age > 0 ? $age : '';
            
            // Logic to fetch school details
            $school = $b['tert_school'] ?: ($b['sec_school'] ?: ($b['elem_school'] ?: ''));
            $course = $b['tert_course'] ?: ($b['sec_degree'] ?: '');
            $level = $b['tert_year_level'] ?: ($b['sec_year_level'] ?: ($b['elem_year_level'] ?: ''));
            
            // Logic for parent details
            $parentName = $b['father_name'] ?: ($b['mother_name'] ?: ($b['gsis_beneficiary_name'] ?: ''));
            $parentOcc = $b['father_occupation'] ?: ($b['mother_occupation'] ?: '');
            
            $dateApp = !empty($b['created_at']) ? date("Y/m/d", strtotime($b['created_at'])) : '';
            
            $rawStatus = strtoupper($b['approval_status'] ?? '');
            $exportStatus = $rawStatus;
            if ($rawStatus === 'APPROVED') $exportStatus = 'QUALIFIED';
            if ($rawStatus === 'REJECTED') $exportStatus = 'DISQUALIFIED';
            
            $startDate = !empty($b['start_date']) ? date("Y/m/d", strtotime($b['start_date'])) : '';
            $endDate = !empty($b['end_date']) ? date("Y/m/d", strtotime($b['end_date'])) : '';

            echo '<tr>';
            echo '<td class="grid-cell-center">' . $counter++ . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['last_name'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['first_name'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['middle_name'] ?? '') . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['sex'] ?? '') . '</td>';
            echo '<td class="grid-cell-center" style="mso-number-format:\'yyyy\/mm\/dd\';">' . $bdate . '</td>';
            echo '<td class="grid-cell-center">' . $displayAge . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($address) . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['barangay'] ?? '') . '</td>';
            echo '<td class="grid-cell-center" style="mso-number-format:\'@\';">' . htmlspecialchars($b['contact_no'] ?? '') . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($school) . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($course) . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($level) . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($parentName) . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($parentOcc) . '</td>';
            echo '<td class="grid-cell-center">' . htmlspecialchars($b['avg_monthly_income'] ?? '') . '</td>';
            echo '<td class="grid-cell-center" style="mso-number-format:\'yyyy\/mm\/dd\';">' . $dateApp . '</td>';
            echo '<td class="grid-cell-center" style="font-weight:bold;">' . htmlspecialchars($exportStatus) . '</td>';
            echo '<td class="grid-cell"></td>'; // WORK ASSIGNMENT
            echo '<td class="grid-cell-center" style="mso-number-format:\'yyyy\/mm\/dd\';">' . $startDate . '</td>';
            echo '<td class="grid-cell-center" style="mso-number-format:\'yyyy\/mm\/dd\';">' . $endDate . '</td>';
            echo '<td class="grid-cell">' . htmlspecialchars($b['approval_note'] ?? '') . '</td>'; // REMARKS
            echo '</tr>';
        }
    }
    echo $excel_footer;
    exit();
}


/* =========================================================================
   STANDARD EXCEL REPORTS (NON-TUPAD, NON-MSME, NON-SPES)
========================================================================= */
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename.xls\"");
header("Pragma: no-cache");
header("Expires: 0");

echo $excel_header;

echo '<tr><td colspan="4" class="no-border" style="text-align:center; font-weight:bold; font-size: 14pt;">LGU Vinzons PESO</td></tr>';
echo '<tr><td colspan="4" class="no-border" style="text-align:center;">Official List of Beneficiaries</td></tr>';
echo '<tr><td colspan="4" class="no-border" style="text-align:center; font-weight:bold; font-size: 12pt;">' . htmlspecialchars($reportTitle) . '</td></tr>';
echo '<tr><td colspan="4" class="no-border" style="text-align:center;">' . $subtitle . '</td></tr>';
echo '<tr><td colspan="4" class="no-border"></td></tr>';

echo '<tr>
        <th class="grid-header" style="width: 50px;">NO.</th>
        <th class="grid-header" style="width: 250px;">NAMES</th>
        <th class="grid-header" style="width: 200px;">ST / ZONE / PUROK</th>
        <th class="grid-header" style="width: 150px;">BRGY</th>
      </tr>';

if (empty($beneficiaries)) {
    echo '<tr><td colspan="4" class="grid-cell-center" style="padding: 20px; font-style: italic; color: #666;">No records found for the selected filters.</td></tr>';
} else {
    $counter = 1;
    foreach ($beneficiaries as $b) {
        $fname = trim(($b["first_name"] ?? "") . " " . ($b["middle_name"] ?? "") . " " . ($b["last_name"] ?? "") . " " . ($b["ext_name"] ?? ""));
        if ($fname === "") {
            $fname = htmlspecialchars($b["full_name"]);
        } else {
            $fname = htmlspecialchars($fname);
        }
        
        $st_zone = htmlspecialchars($b['street_purok_zone'] ?? "—");
        $brgy = htmlspecialchars($b['barangay'] ?? "—");

        echo '<tr>
                <td class="grid-cell-center">' . $counter . '</td>
                <td class="grid-cell" style="font-weight: bold;">' . strtoupper($fname) . '</td>
                <td class="grid-cell">' . strtoupper($st_zone) . '</td>
                <td class="grid-cell">' . strtoupper($brgy) . '</td>
              </tr>';
        $counter++;
    }
}

echo '<tr><td colspan="4" class="no-border"></td></tr>';
echo '<tr><td colspan="4" class="no-border" style="text-align:right; font-size:9pt; color:#666666;">Document Generated on: ' . $dateGenerated . '</td></tr>';

echo $excel_footer;
exit();
?>
