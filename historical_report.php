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
$export=['program_name'=>$program,'program_id'=>$batch,'report_outcome'=>$status,'report_search'=>$search,'export_format'=>'xlsx','spes_group'=>$spesGroup];
module_shell_start($moduleRole,'report','Report','Beneficiary records. Search, review, and download reports.');
?>
<section class="module-panel"><form class="module-filters" method="get">
<div class="module-field"><label for="reportProgram">Program</label><select name="program_name" id="reportProgram"><?php foreach($programs as $name):?><option <?=$program===$name?'selected':''?> value="<?=module_escape($name)?>"><?=module_escape($name)?></option><?php endforeach?></select></div>
<div class="module-field"><label for="reportBatch">Batch</label><select id="reportBatch" name="program_id"><option value="0">All batches</option><?php foreach($batches as $item):?><option value="<?=(int)$item['program_id']?>" <?=$batch===(int)$item['program_id']?'selected':''?>><?=module_escape($item['program_code'])?></option><?php endforeach?></select></div>
<div class="module-field"><label for="reportOutcome">Outcome</label><select id="reportOutcome" name="outcome"><?php foreach($outcomes as $item):?><option <?=$status===$item?'selected':''?>><?=module_escape($item)?></option><?php endforeach?></select></div>
<?php if(stripos($program,'SPES')!==false):?><div class="module-field"><label for="reportSpesGroup">SPES Classification</label><select name="spes_group" id="reportSpesGroup"><?php foreach(['all'=>'All Classifications','baby'=>'SPES Baby','graduate'=>'Graduated'] as $key=>$label):?><option value="<?=module_escape($key)?>" <?=$spesGroup===$key?'selected':''?>><?=module_escape($label)?></option><?php endforeach?></select></div><?php endif?>
<div class="module-field"><label for="reportSearch">Search</label><input id="reportSearch" name="search" maxlength="120" value="<?=module_escape($search)?>" placeholder="Name, barangay, or batch"></div><button class="module-btn" type="submit">Apply Filters</button></form></section>
<section class="module-panel"><h2><?=module_escape($program ?: 'Program records')?></h2><p><?=$total?> record<?=$total===1?'':'s'?>. Completed, rejected, not qualified, exam failed, and cancelled records appear here.</p>
<?php if($rows):?><div class="module-actions"><a class="module-btn" href="export_report.php?<?=module_escape(http_build_query($export))?>"><i class="ph ph-download-simple"></i> Download Excel</a><button class="module-btn secondary" type="button" data-print-report>Print This Page</button></div>
<div class="module-table-wrap" role="region" aria-label="Beneficiary records; scroll horizontally to see all report columns" tabindex="0"><table class="module-table"><thead><tr><th>No.</th><th>Batch</th><th>Outcome</th><?php foreach($columns as $column):?><th><?=module_escape($column['label'])?></th><?php endforeach?></tr></thead><tbody>
<?php foreach($rows as $index=>$row):?><tr><td><?=$offset+$index+1?></td><td><?=module_escape($row['program_code'])?></td><td><?=module_escape($row['approval_status']==='Rejected'?'Rejected':$row['availment_status'])?></td><?php foreach($columns as $column):?><td><?=module_escape(official_report_value($row,$column['key']))?></td><?php endforeach?></tr><?php endforeach?></tbody></table></div>
<div class="report-mobile-records"><?php foreach($rows as $row):?><details class="report-record"><summary><strong><?=module_escape($row['full_name'])?></strong><span><?=module_escape($row['program_code'])?> · <?=module_escape($row['approval_status']==='Rejected'?'Rejected':$row['availment_status'])?></span></summary><dl><?php foreach($columns as $column): if($column['label']==='') continue;?><div><dt><?=module_escape($column['label'])?></dt><dd><?=module_escape(official_report_value($row,$column['key']) ?: '—')?></dd></div><?php endforeach?></dl></details><?php endforeach?></div>
<nav class="module-pager" aria-label="Report pages"><?php $paging=['program_name'=>$program,'program_id'=>$batch,'outcome'=>$status,'search'=>$search,'spes_group'=>$spesGroup];?><?php if($page>1):?><a class="module-btn secondary" href="?<?=module_escape(http_build_query($paging+['page'=>$page-1]))?>">Previous</a><?php endif?><span>Page <?=$page?> of <?=$pages?></span><?php if($page<$pages):?><a class="module-btn secondary" href="?<?=module_escape(http_build_query($paging+['page'=>$page+1]))?>">Next</a><?php endif?></nav>
<?php else:?><div class="module-empty"><i class="ph ph-files" aria-hidden="true"></i><p>No records match these filters.</p></div><?php endif?></section>
<?php module_shell_end();
