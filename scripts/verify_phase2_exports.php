<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../report_columns.php';
require_once __DIR__.'/../xlsx_report_helper.php';
require_once __DIR__.'/../official_template_export.php';
require_once __DIR__.'/../beneficiary_import_helper.php';

function phase2_check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function phase2_sheet(PharData $zip, int $sheet): DOMXPath {
    $doc=new DOMDocument();phase2_check($doc->loadXML($zip['xl/worksheets/sheet'.$sheet.'.xml']->getContent(),LIBXML_NONET),'Invalid worksheet XML');
    $xpath=new DOMXPath($doc);$xpath->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');return $xpath;
}
function phase2_cell(DOMXPath $xpath,string $ref): string { return $xpath->evaluate('string(//m:c[@r="'.$ref.'"]/m:is/m:t)'); }

$sample=['beneficiary_id'=>1,'first_name'=>'TEST ONLY','last_name'=>'SYNTHETIC','full_name'=>'TEST ONLY SYNTHETIC',
    'approval_status'=>'Approved','availment_status'=>'Completed','birthdate'=>'2001-02-03','barangay'=>'TEST BARANGAY',
    'contact_no'=>'09123456789','id_number'=>'001234','created_at'=>'2026-01-02','tert_school'=>'Test School','tert_course'=>'Test Course',
    'tert_year_level'=>'3','father_name'=>'Test Father','father_occupation'=>'Test Occupation','work_assignment'=>'Synthetic assignment',
    'employment_start'=>'2026-05-01','employment_end'=>'2026-05-20','business_name'=>'SYNTHETIC BUSINESS',
    'primary_products'=>json_encode(array_map(static fn($i)=>'Product '.$i,range(1,12))),
    'product_price'=>json_encode(array_fill(0,12,'50')),'hr_male'=>2,'hr_female'=>1,'hr_total'=>3,
    'emp_regular'=>1,'emp_seasonal'=>1,'emp_contractual'=>0,'emp_family'=>1,'nm_two_day_earnings'=>'1500','nm_total_earnings'=>'12000'];
$second=$sample;$second['beneficiary_id']=2;$second['business_name']='SECOND SYNTHETIC BUSINESS';$second['approval_status']='Rejected';
foreach(['SPES','TUPAD','MSME'] as $program) {
    $path=build_official_template_report($program,[$sample,$second]);
    try {
        $zip=new PharData($path,0,null,Phar::ZIP);$sheet=phase2_sheet($zip,1);
        phase2_check(!isset($zip['xl/sharedStrings.xml']),'Cached sample strings leaked into export');
        if ($program==='SPES') {
            phase2_check(phase2_cell($sheet,'J4')==='09123456789','Phone lost its leading zero');
            phase2_check(phase2_cell($sheet,'R4')==='QUALIFIED' && phase2_cell($sheet,'R5')==='DISQUALIFIED','SPES decision mapping is incorrect');
            phase2_check(phase2_cell($sheet,'T4')==='2026/05/01','Actual employment date was not used');
        } elseif ($program==='TUPAD') {
            phase2_check(phase2_cell($sheet,'O11')==='001234','ID lost leading zeroes');
            phase2_check(phase2_cell($sheet,'H11')==='2001/02/03','TUPAD date format differs from template');
            phase2_check(phase2_cell($sheet,'F11')==='' && phase2_cell($sheet,'G11')==='','Reserved TUPAD columns were populated');
        } else {
            phase2_check(phase2_cell($sheet,'F5')==='Product 1' && phase2_cell($sheet,'F16')==='Product 12','Product blocks truncated');
            phase2_check(phase2_cell($sheet,'G5')==='50' && phase2_cell($sheet,'G6')==='50','Repeated product prices lost alignment');
            phase2_check(phase2_cell($sheet,'C17')==='SECOND SYNTHETIC BUSINESS','Next business overlaps the product block');
            $other=phase2_sheet($zip,2);
            phase2_check(phase2_cell($other,'K7')==='1500' && phase2_cell($other,'L7')==='12000','Night market earnings map incorrectly');
            phase2_check(phase2_cell($other,'A19')==='2','MSME sheets have different business numbering');
            foreach([$sheet,$other] as $xp) {
                $merges=$xp->query('/m:worksheet/m:mergeCells')->item(0);
                phase2_check((int)$merges->getAttribute('count')===$xp->query('m:mergeCell',$merges)->length,'Invalid merged-cell count');
            }
        }
        echo "$program template export checks passed\n";
    } finally { unset($zip);if(is_file($path))unlink($path); }
    $parsed=readBeneficiaryXlsxRows(__DIR__.'/../assets/templates/'.$program.'_Import_Blank.xlsx');
    phase2_check(count($parsed)===1,'Blank import template contains sample records');
}
phase2_check(official_report_value(['report_parent_name'=>'Explicit Guardian','father_occupation'=>'Unrelated occupation'],'report_parent_occupation')==='','Guardian inherited an unrelated occupation');
phase2_check(official_report_value(['approval_status'=>'Approved','availment_status'=>'Not Qualified'],'approval_status')==='DISQUALIFIED','Explicit disqualification reported as qualified');
phase2_check(official_report_value(['approval_status'=>'Approved','availment_status'=>'Cancelled'],'approval_status')==='QUALIFIED','Cancellation changed the qualification decision');
phase2_check(official_report_value(['father_name'=>'Application Parent','report_parent_name'=>'Old Override'],'report_parent_name')==='Application Parent','Application parent data was not reused');
phase2_check(official_report_value(['availment_status'=>'Orientation','date_availed'=>'2026-03-01'],'employment_start')==='', 'Orientation date was exported as employment start');
phase2_check(official_report_value(['availment_status'=>'Completed','date_availed'=>'2026-04-01','date_completed'=>'2026-04-20'],'employment_start')==='2026/04/01','Status start date not reused');
phase2_check(official_report_value(['availment_status'=>'Completed','date_completed'=>'2026-04-20'],'employment_end')==='2026/04/20','Status completion date not reused');
echo "Blank import templates and guardian mapping checks passed\n";
