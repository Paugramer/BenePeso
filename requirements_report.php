<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
$requestedRole = $_GET['role'] ?? '';
$role = is_string($requestedRole) && in_array($requestedRole,['admin','peso_staff'],true)
    ? $requestedRole : auth_first_active_role(['admin','peso_staff']);
if (!$role) { http_response_code(403); exit('Authorized PESO reviewers only.'); }
check_user_role($role);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); exit('This report is read-only.'); }
require_once __DIR__ . '/requirements_report_helper.php';
require_once __DIR__ . '/module_shell.php';
require_once __DIR__ . '/report_tabs.php';
require_once __DIR__ . '/report_columns.php';
require_once __DIR__ . '/spes_lifecycle_helper.php';
require_once __DIR__ . '/historical_report_helper.php';
header('Cache-Control: private, no-store');
function rr_input(string $key, string $default=''): string {
    return is_string($_GET[$key] ?? null) ? mb_substr(trim($_GET[$key]),0,120) : $default;
}
$statuses = ['All','Not Reviewed','Under Review','Needs Resubmission','Verified / Complete'];
$filters = ['program_name'=>rr_input('program_name'),'program_id'=>max(0,(int)rr_input('program_id','0')),
    'barangay'=>rr_input('barangay','All'),'search'=>rr_input('search'),'review_status'=>rr_input('review_status','All')];
if (!in_array($filters['review_status'],$statuses,true)) $filters['review_status']='All';
$programs=$conn->query('SELECT DISTINCT program_name FROM programs ORDER BY program_name')->fetch_all(MYSQLI_ASSOC);
$batches=$conn->query('SELECT program_id,program_name,program_code FROM programs ORDER BY program_name,program_code')->fetch_all(MYSQLI_ASSOC);
$batches=array_values(array_filter($batches,static fn($batch)=>$filters['program_name']===''||$batch['program_name']===$filters['program_name']));
if ($filters['program_id'] && !in_array($filters['program_id'],array_map('intval',array_column($batches,'program_id')),true)) $filters['program_id']=0;
$barangays=$conn->query("SELECT DISTINCT barangay FROM beneficiaries WHERE TRIM(COALESCE(barangay,''))<>'' ORDER BY barangay")->fetch_all(MYSQLI_ASSOC);
$rows=requirements_report_rows($conn,$filters); $columns=requirements_report_columns(); $total=count($rows);
$query=$filters+['role'=>$role];
$summary=($filters['program_name'] ?: 'All Programs') . ' | ' . ($filters['barangay']==='All'?'All Barangays':$filters['barangay']);
if($filters['program_id'])foreach($batches as $batch)if((int)$batch['program_id']===$filters['program_id'])$summary.=' | Batch: '.$batch['program_code'];
if($filters['review_status']!=='All')$summary.=' | '.$filters['review_status'];
if($filters['search']!=='')$summary.=' | Search: '.$filters['search'];
$summary.=' | '.$total.' record'.($total===1?'':'s');
$format=rr_input('format');
if($format==='xlsx') {
    require_once __DIR__.'/report_columns.php';require_once __DIR__.'/xlsx_report_helper.php';
    require_once __DIR__.'/official_template_export.php';
    $exportRows=$rows;
    if(stripos($filters['program_name'],'SPES')!==false && $exportRows) $exportRows=spes_enrich_rows($conn,$exportRows);
    $exportRows=enrich_reporting_rows($conn,$exportRows);
    output_official_template_report($filters['program_name'],$exportRows,$filters['barangay'],$_GET['columns'] ?? null);
}
if($format==='preview') {
    require_once __DIR__.'/xlsx_report_helper.php';
    require_once __DIR__.'/official_template_export.php';
    $previewRows=$rows;
    if(stripos($filters['program_name'],'SPES')!==false && $previewRows) $previewRows=spes_enrich_rows($conn,$previewRows);
    $previewRows=enrich_reporting_rows($conn,$previewRows);
    header('Content-Type: application/json; charset=utf-8');
    try { echo json_encode(['ok'=>true,'count'=>$total,'sheets'=>report_template_sheets($filters['program_name'],$previewRows,$filters['barangay'])],JSON_INVALID_UTF8_SUBSTITUTE); }
    catch(Throwable $error) { error_log('Submission report preview: '.$error->getMessage()); http_response_code(500); echo json_encode(['ok'=>false,'message'=>'The report preview could not be prepared. Please try again.']); }
    exit;
}
if($format==='print') {
    require_once __DIR__.'/xlsx_report_helper.php'; require_once __DIR__.'/official_template_export.php';
    $printRows=$rows;
    if(stripos($filters['program_name'],'SPES')!==false && $printRows) $printRows=spes_enrich_rows($conn,$printRows);
    $printRows=enrich_reporting_rows($conn,$printRows);
    $printSheets=report_template_sheets($filters['program_name'],$printRows,$filters['barangay']); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title><?=module_escape(getReportProgramTitle($filters['program_name']))?></title><link rel="stylesheet" href="requirements_report.css"></head><body class="requirements-print">
<?php foreach($printSheets as $sheet):?><section style="break-after:page"><header><img id="requirementsLetterhead" src="assets/peso_official_report_header.png" alt="PESO Vinzons official letterhead"><h1><?=module_escape(getReportProgramTitle($filters['program_name']))?></h1><p><?=module_escape($summary)?></p></header><div class="requirements-table"><?=$sheet['html']?></div></section><?php endforeach?>
<script src="requirements_report.js"></script></body></html>
<?php exit; }
$page=max(1,(int)rr_input('page','1'));$pages=max(1,(int)ceil($total/25));$page=min($page,$pages);$offset=($page-1)*25;
require __DIR__.'/requirements_report_view.php';
