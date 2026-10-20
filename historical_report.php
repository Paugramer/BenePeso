<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_columns.php';
require_once __DIR__ . '/historical_report_helper.php';
require_once __DIR__ . '/spes_lifecycle_helper.php';
require_once __DIR__ . '/module_shell.php';
$moduleRole = $moduleRole ?? auth_first_active_role(['admin','peso_staff']);
if (!$moduleRole) { http_response_code(403); exit('Authorized PESO staff only.'); }
check_user_role($moduleRole);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); exit('Reports are read-only.'); }
$programs = [];
$result = $conn->query('SELECT DISTINCT program_name FROM programs ORDER BY program_name');
while ($result && ($record = $result->fetch_assoc())) $programs[] = $record['program_name'];
$reportPrefix=$moduleRole==='admin'?'admin':'peso_staff';
if(empty($_GET['program_name'])){
 $counts=[];$summary=['total'=>0,'completed'=>0,'rejected'=>0,'other'=>0];
 foreach($conn->query("SELECT p.program_name,COUNT(*) total,SUM(b.approval_status='Approved' AND b.availment_status='Completed') completed,SUM(b.approval_status='Rejected') rejected FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id WHERE ".historical_record_condition()." GROUP BY p.program_name") as $r){$counts[$r['program_name']]=$r;foreach(['total','completed','rejected'] as $key)$summary[$key]+=(int)$r[$key];}
 $summary['other']=$summary['total']-$summary['completed']-$summary['rejected'];$images=[];foreach($conn->query('SELECT program_name,image_path FROM program_categories') as $r)$images[$r['program_name']]=$r['image_path'];
 module_shell_start($moduleRole,'report','Report','Select a program to view beneficiary records and download reports.','report-workspace');
 ?><div class="report-stat-grid"><?php foreach(['total'=>'Beneficiary Records','completed'=>'Completed','rejected'=>'Rejected','other'=>'Not Completed'] as $key=>$label):?><article class="report-stat"><div class="report-stat-top"><span><?=module_escape($label)?></span><span class="report-stat-icon"><i class="ph ph-<?=$key==='total'?'users-three':($key==='completed'?'check-circle':($key==='rejected'?'x-circle':'warning-circle'))?>"></i></span></div><strong><?=$summary[$key]?></strong><p class="report-stat-caption"><?=$key==='total'?'All records':($key==='completed'?'Completed participation':($key==='rejected'?'Rejected applications':'Not completed'))?></p><p class="report-stat-note"><?=$key==='total'?'Beneficiary records across all programs.':($key==='completed'?'Beneficiaries who completed their program.':($key==='rejected'?'Applications not accepted by PESO.':'Not Qualified, Exam Not Passed, and Cancelled.'))?></p></article><?php endforeach?></div>
 <section class="module-panel report-program-panel panel-card"><h2>Programs Overview</h2><p>Select a program to view its beneficiary records.</p><div class="report-program-grid"><?php foreach($programs as $name):?><a class="report-program-card" href="?<?=module_escape(http_build_query(['program_name'=>$name]))?>"><div class="report-program-image"><img src="<?=module_escape($images[$name] ?? 'img/pesobgs.jpg')?>" alt="<?=module_escape($name)?>"><span class="report-program-icon"><i class="ph ph-<?=stripos($name,'SPES')!==false?'student':(stripos($name,'MSME')!==false?'storefront':'hammer')?>"></i></span></div><h3><?=module_escape($name)?></h3><p class="report-program-count"><i class="ph ph-users-three"></i> <?=(int)($counts[$name]['total'] ?? 0)?> Beneficiary Records</p><span class="report-directory-button"><i class="ph ph-folder-open"></i> View Records</span></a><?php endforeach?></div></section><?php
 module_shell_end();return;
}
$program = trim((string)($_GET['program_name'] ?? ($programs[0] ?? '')));
if (!in_array($program, $programs, true)) $program = $programs[0] ?? '';
$status = (string)($_GET['outcome'] ?? 'All');
$outcomes = ['All','Completed','Rejected','Not Qualified','Exam Failed','Cancelled'];
if (!in_array($status, $outcomes, true)) $status = 'All';
$spesGroup=stripos($program,'SPES')!==false && in_array($_GET['spes_group'] ?? '',['baby','graduate'],true) ? $_GET['spes_group'] : 'all';
$search = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 120);
$batch = max(0, (int)($_GET['program_id'] ?? 0));
$page = max(1, (int)($_GET['page'] ?? 1));
$batches = [];
$stmt = $conn->prepare('SELECT program_id,program_code FROM programs WHERE program_name=? ORDER BY created_at DESC');
$stmt->bind_param('s', $program); $stmt->execute(); $result=$stmt->get_result();
while ($record=$result->fetch_assoc()) $batches[]=$record;
$stmt->close();
if ($batch && !in_array($batch, array_map('intval', array_column($batches,'program_id')), true)) $batch=0;
$where = ['p.program_name=?', historical_record_condition()]; $params=[$program]; $types='s';
if($spesGroup!=='all')$where[]=spes_group_condition_sql($spesGroup,'b');
if ($batch) { $where[]='b.program_id=?'; $params[]=$batch; $types.='i'; }
if ($status!=='All') { $where[]=$status==='Rejected' ? "b.approval_status='Rejected'" : 'b.availment_status=?'; if ($status!=='Rejected') { $params[]=$status; $types.='s'; } }
if ($search!=='') { $where[]='(b.full_name LIKE ? OR b.barangay LIKE ? OR p.program_code LIKE ?)'; $like='%'.$search.'%'; array_push($params,$like,$like,$like); $types.='sss'; }
$from=' FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id WHERE '.implode(' AND ', $where);
$stmt=$conn->prepare('SELECT COUNT(*) total'.$from); $stmt->bind_param($types,...$params); $stmt->execute(); $total=(int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
$pages=max(1,(int)ceil($total/25)); $page=min($page,$pages); $offset=($page-1)*25;
$stmt=$conn->prepare('SELECT b.*,p.program_code,p.program_name'.$from.' ORDER BY b.last_name,b.first_name,b.beneficiary_id LIMIT 25 OFFSET ?');
$queryParams=$params; $queryParams[]=$offset; $queryTypes=$types.'i'; $stmt->bind_param($queryTypes,...$queryParams); $stmt->execute(); $result=$stmt->get_result(); $rows=[];
while ($row=$result->fetch_assoc()) $rows[]=$row;
$stmt->close(); $rows=enrich_reporting_rows($conn,$rows); $columns=official_report_columns($program);
$export=['program_name'=>$program,'program_id'=>$batch,'report_outcome'=>$status,'report_search'=>$search,'export_format'=>'xlsx','report_spes_group'=>$spesGroup];
module_shell_start($moduleRole,'report',$program.' Reports','View beneficiary records, review details, and download reports.','report-workspace');
$baseQuery=['program_name'=>$program,'program_id'=>$batch,'search'=>$search];
?>
<section class="module-panel report-record-panel panel-card">
<div class="report-workspace-toolbar panel-head-flex"><nav class="report-tabs custom-tabs" aria-label="Report views"><a class="tab-item" href="requirements_report.php?<?=module_escape(http_build_query(['role'=>$moduleRole,'program_name'=>$program,'program_id'=>$batch,'search'=>$search]))?>">Documents Submitted</a><?php foreach(['All'=>'All Records','Completed'=>'Completed','Rejected'=>'Rejected'] as $value=>$label):?><a class="tab-item <?=$status===$value&&$spesGroup==='all'?'active':''?>" href="?<?=module_escape(http_build_query($baseQuery+['outcome'=>$value,'spes_group'=>'all']))?>"><?=module_escape($label)?></a><?php endforeach?><?php if(stripos($program,'SPES')!==false):?><?php foreach(['baby'=>'SPES Baby','graduate'=>'Graduated'] as $value=>$label):?><a class="tab-item <?=$spesGroup===$value?'active':''?>" href="?<?=module_escape(http_build_query($baseQuery+['outcome'=>'All','spes_group'=>$value]))?>"><?=module_escape($label)?></a><?php endforeach?><?php endif?></nav><a class="module-btn secondary" href="<?=$reportPrefix?>_beneficiaries.php"><i class="ph ph-squares-four"></i> All Programs</a></div>
<form class="report-filters filter-container records-filter-container" method="get"><input type="hidden" name="program_name" value="<?=module_escape($program)?>"><input type="hidden" name="spes_group" value="<?=module_escape($spesGroup)?>">
<div class="report-search-row"><div class="report-search filter-search system-search"><i class="ph ph-magnifying-glass"></i><input class="search-input" type="search" id="reportSearch" aria-label="Search beneficiary records" name="search" maxlength="120" value="<?=module_escape($search)?>" placeholder="Search by name, barangay, or batch..."></div><button class="beneficiary-filter-toggle" type="button" id="reportFilterToggle" aria-expanded="<?=$batch||$status!=='All'?'true':'false'?>" aria-controls="reportFilterFields"><i class="ph ph-sliders-horizontal"></i> More filters</button></div>
<div class="report-filter-fields" id="reportFilterFields" <?=$batch||$status!=='All'?'':'hidden'?>>
<div class="module-field"><label for="reportBatch">Batch</label><select name="program_id" id="reportBatch"><option value="0">All Batches</option><?php foreach($batches as $item):?><option value="<?=(int)$item['program_id']?>" <?=$batch===(int)$item['program_id']?'selected':''?>><?=module_escape($item['program_code'])?></option><?php endforeach?></select></div>
<div class="module-field"><label for="reportOutcome">Status</label><select name="outcome" id="reportOutcome"><?php foreach($outcomes as $item):?><option value="<?=module_escape($item)?>" <?=$status===$item?'selected':''?>><?=module_escape($item==='All'?'All Status':$item)?></option><?php endforeach?></select></div></div></form>
<div class="report-result-bar"><p><?=$total?> beneficiary record<?=$total===1?'':'s'?></p><?php if($rows):?><div class="report-download-actions"><a class="module-btn" href="export_report.php?<?=module_escape(http_build_query($export))?>"><i class="ph ph-download-simple"></i> Download Excel</a><button class="module-btn secondary" type="button" data-print-report><i class="ph ph-printer"></i> Print</button></div><?php endif?></div>
<?php if($rows):?>
<?php require __DIR__.'/report_original_table.php'; ?>
<?php foreach($rows as $row):?><template id="reportRecord-<?=(int)$row['beneficiary_id']?>"><div class="report-detail-identity"><h2><?=module_escape($row['full_name'])?></h2><p><?=module_escape($row['program_code'])?> / <?=module_escape($row['approval_status']==='Rejected'?'Rejected':$row['availment_status'])?></p></div><dl class="report-detail-grid"><?php foreach($columns as $column):if($column['label']==='')continue;?><div><dt><?=module_escape($column['label'])?></dt><dd><?=module_escape(official_report_value($row,$column['key']) ?: '-')?></dd></div><?php endforeach?></dl></template><?php endforeach?>
<div class="report-print-table"><h2><?=module_escape($program)?> Report</h2><table class="module-table"><thead><tr><th>No.</th><th>Batch</th><th>Status</th><?php foreach($columns as $column):?><th><?=module_escape($column['label'])?></th><?php endforeach?></tr></thead><tbody><?php foreach($rows as $index=>$row):?><tr><td><?=$offset+$index+1?></td><td><?=module_escape($row['program_code'])?></td><td><?=module_escape($row['approval_status']==='Rejected'?'Rejected':$row['availment_status'])?></td><?php foreach($columns as $column):?><td><?=module_escape(official_report_value($row,$column['key']))?></td><?php endforeach?></tr><?php endforeach?></tbody></table></div>
<nav class="module-pager" aria-label="Report pages"><?php $paging=['program_name'=>$program,'program_id'=>$batch,'outcome'=>$status,'search'=>$search,'spes_group'=>$spesGroup];?><?php if($page>1):?><a class="module-btn secondary" href="?<?=module_escape(http_build_query($paging+['page'=>$page-1]))?>">Previous</a><?php endif?><span>Page <?=$page?> of <?=$pages?></span><?php if($page<$pages):?><a class="module-btn secondary" href="?<?=module_escape(http_build_query($paging+['page'=>$page+1]))?>">Next</a><?php endif?></nav>
<?php else:?><div class="report-empty"><i class="ph ph-users"></i><h2>No beneficiary records found</h2><p>No completed or closed records match these filters.</p></div><?php endif?></section>
<?php require __DIR__.'/report_original_profile.php'; ?>
<?php require __DIR__.'/report_original_generator.php'; ?>
<script src="report_workspace.js?v=4"></script>
<?php module_shell_end();
