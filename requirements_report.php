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
    outputNativeXlsxReport('Documents Submitted',$columns,$rows,$summary);
}
function rr_table(array $rows,array $columns,int $offset=0): void { ?>
<div class="module-table-wrap requirements-table"><table class="module-table"><thead><tr><th>No.</th><?php foreach($columns as $column):?><th><?=module_escape($column['label'])?></th><?php endforeach?></tr></thead><tbody>
<?php foreach($rows as $i=>$row):?><tr data-record-id="<?=(int)$row['beneficiary_id']?>"><td><?=$offset+$i+1?></td><?php foreach($columns as $column):?><td><?=nl2br(module_escape($row[$column['key']] ?? ''))?></td><?php endforeach?></tr><?php endforeach?>
<?php if(!$rows):?><tr><td colspan="<?=count($columns)+1?>">No recorded submissions match these filters.</td></tr><?php endif?></tbody></table></div>
<?php }
if($format==='print') { ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Documents Submitted</title><link rel="stylesheet" href="requirements_report.css"></head><body class="requirements-print">
<header><img id="requirementsLetterhead" src="assets/peso_official_report_header.png" alt="PESO Vinzons official letterhead"><h1>Documents Submitted</h1><p><?=module_escape($summary)?></p><p>Submission and review records only. This list does not certify DOLE approval.</p></header>
<?php rr_table($rows,$columns);?><script src="requirements_report.js"></script></body></html>
<?php exit; }
$page=max(1,(int)rr_input('page','1'));$pages=max(1,(int)ceil($total/25));$page=min($page,$pages);$offset=($page-1)*25;
$prefix=$role==='admin'?'admin':'peso_staff';
module_shell_start($role,'report',($filters['program_name'] ? $filters['program_name'].' Reports' : 'Report'),'View submitted documents and their current review status.','report-workspace requirements-report-page'); ?>
<link rel="stylesheet" href="requirements_report.css?v=2">
<section class="module-panel report-record-panel panel-card">
<div class="report-workspace-toolbar panel-head-flex">
<?php render_report_tabs($role,$filters['program_name'],$filters['program_id'],$filters['search'],'documents'); ?>
<a class="module-btn secondary" href="<?=$prefix?>_beneficiaries.php"><i class="ph ph-squares-four"></i> All Programs</a>
</div>
<p class="requirements-note">Face-to-face submissions and PESO review status. This list does not certify DOLE approval.</p>
<form method="get" class="requirements-filters filter-container records-filter-container" id="requirementsFilters"><input type="hidden" name="role" value="<?=module_escape($role)?>">
<label>Program<select name="program_name" id="requirementsProgram"><option value="">All Programs</option><?php foreach($programs as $program):?><option value="<?=module_escape($program['program_name'])?>" <?=$filters['program_name']===$program['program_name']?'selected':''?>><?=module_escape($program['program_name'])?></option><?php endforeach?></select></label>
<label>Batch<select name="program_id" id="requirementsBatch"><option value="0">All Batches</option><?php foreach($batches as $batch):?><option value="<?=(int)$batch['program_id']?>" <?=$filters['program_id']===(int)$batch['program_id']?'selected':''?>><?=module_escape($batch['program_code'])?></option><?php endforeach?></select></label>
<label>Barangay<select name="barangay"><option value="All">All Barangays</option><?php foreach($barangays as $item):?><option value="<?=module_escape($item['barangay'])?>" <?=$filters['barangay']===$item['barangay']?'selected':''?>><?=module_escape($item['barangay'])?></option><?php endforeach?></select></label>
<label>Requirements Review<select name="review_status"><?php foreach($statuses as $status):?><option value="<?=module_escape($status)?>" <?=$filters['review_status']===$status?'selected':''?>><?=module_escape($status==='All'?'All Review Statuses':$status)?></option><?php endforeach?></select></label>
<label class="requirements-search">Search<input type="search" name="search" maxlength="120" placeholder="Name, barangay, or batch" value="<?=module_escape($filters['search'])?>"></label>
<button class="module-btn" type="submit">Apply Filters</button><a class="module-btn secondary" href="requirements_report.php?role=<?=module_escape($role)?>">Reset</a></form>
<div class="requirements-actions"><p><?=module_escape($summary)?></p><div><a class="module-btn secondary" data-requirements-format="print" target="_blank" rel="noopener" href="requirements_report.php?<?=module_escape(http_build_query($query+['format'=>'print']))?>">Print Filtered List</a><a class="module-btn" data-requirements-format="xlsx" href="requirements_report.php?<?=module_escape(http_build_query($query+['format'=>'xlsx']))?>">Download Excel</a></div></div>
<?php rr_table(array_slice($rows,$offset,25),$columns,$offset);?>
<nav class="module-pager" aria-label="Submission report pages"><?php if($page>1):?><a class="module-btn secondary" href="?<?=module_escape(http_build_query($query+['page'=>$page-1]))?>">Previous</a><?php endif?><span>Page <?=$page?> of <?=$pages?></span><?php if($page<$pages):?><a class="module-btn secondary" href="?<?=module_escape(http_build_query($query+['page'=>$page+1]))?>">Next</a><?php endif?></nav>
</section><script src="requirements_report.js"></script><?php module_shell_end();
