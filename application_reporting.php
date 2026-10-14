<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/historical_report_helper.php';
require_once __DIR__.'/report_columns.php';
require_once __DIR__.'/module_shell.php';
require_once __DIR__.'/functions.php';
$embedded = ($_GET['embedded'] ?? '') === '1';
$requestedRole=(string)($_GET['role'] ?? '');
$role=in_array($requestedRole,['admin','peso_staff'],true) ? $requestedRole : auth_first_active_role(['admin','peso_staff']);
if (!$role) { http_response_code(403); exit('Authorized PESO staff only.'); }
check_user_role($role);
$id=(int)($_GET['id'] ?? 0);
$stmt=$conn->prepare('SELECT b.*,p.program_name FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id WHERE b.beneficiary_id=?');
$stmt->bind_param('i',$id);$stmt->execute();$record=$stmt->get_result()->fetch_assoc();$stmt->close();
if (!$record) { http_response_code(404);exit('Application not found.'); }
$terminal=$record['approval_status']==='Rejected' || in_array($record['availment_status'],['Completed','Not Qualified','Exam Failed','Cancelled'],true);
if ($terminal || $record['approval_status']!=='Approved') { http_response_code(409);exit('Report details can be edited only for accepted, active applications.'); }
if (!reporting_details_available($conn)) {
    // Follow the existing application convention of creating additive helper tables on first use.
    $conn->query(file_get_contents(__DIR__.'/database_update_phase2_reporting.sql'));
}
$record=enrich_reporting_rows($conn,[$record])[0];
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!auth_verify_csrf($_POST['csrf_token'] ?? null)) { http_response_code(403);exit('Invalid request token.'); }
    $assignment=trim((string)($_POST['work_assignment'] ?? ($record['work_assignment'] ?? '')));
    $parent=trim((string)($_POST['report_parent_name'] ?? ($record['report_parent_name'] ?? '')));
    $occupation=trim((string)($_POST['report_parent_occupation'] ?? ($record['report_parent_occupation'] ?? '')));
    $remarks=trim((string)($_POST['report_remarks'] ?? ($record['report_remarks'] ?? '')));
    $secondary=trim((string)($_POST['report_remarks_secondary'] ?? ($record['report_remarks_secondary'] ?? '')));
    $twoDay=trim((string)($_POST['nm_two_day_earnings'] ?? ($record['nm_two_day_earnings'] ?? '')));
    $totalEarnings=trim((string)($_POST['nm_total_earnings'] ?? ($record['nm_total_earnings'] ?? '')));
    $start=trim((string)($_POST['employment_start'] ?? ($record['employment_start'] ?? '')));$end=trim((string)($_POST['employment_end'] ?? ($record['employment_end'] ?? '')));
    foreach([$start,$end] as $date) if ($date!=='') { $parsed=DateTime::createFromFormat('!Y-m-d',$date); if (!$parsed || $parsed->format('Y-m-d')!==$date) $error='Enter valid employment dates.'; }
    if ($start!=='' && $end!=='' && $end<$start) $error='Employment end must be on or after the start date.';
    if (mb_strlen($assignment)>250 || mb_strlen($parent)>200 || mb_strlen($occupation)>200 || mb_strlen($remarks)>3000 || mb_strlen($secondary)>3000) $error='One or more fields exceed the allowed length.';
    if (mb_strlen($twoDay)>100 || mb_strlen($totalEarnings)>100) $error='Earnings fields must be at most 100 characters.';
    foreach (['nm_two_day_earnings','nm_total_earnings'] as $amountField) {
        if (!array_key_exists($amountField, $_POST)) continue;
        $amount = $_POST[$amountField];
        if (!is_string($amount) || (trim($amount) !== '' && !preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', trim($amount)))) $error='Enter a non-negative amount with up to two decimal places.';
    }
    if ($error==='') {
        $conn->begin_transaction();
        try {
            $stmt=$conn->prepare("SELECT beneficiary_id FROM beneficiaries b WHERE b.beneficiary_id=? AND b.approval_status='Approved' AND NOT ".historical_record_condition()." FOR UPDATE");
            $stmt->bind_param('i',$id);$stmt->execute();$active=$stmt->get_result()->num_rows;$stmt->close();
            if (!$active) throw new RuntimeException('The application is no longer active.');
            $start=$start ?: null;$end=$end ?: null;$actor=(int)$_SESSION[auth_role_id_key($role)];
            $stmt=$conn->prepare('INSERT INTO beneficiary_reporting_details (beneficiary_id,work_assignment,employment_start,employment_end,report_parent_name,report_parent_occupation,report_remarks,report_remarks_secondary,nm_two_day_earnings,nm_total_earnings,updated_by_role,updated_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE work_assignment=VALUES(work_assignment),employment_start=VALUES(employment_start),employment_end=VALUES(employment_end),report_parent_name=VALUES(report_parent_name),report_parent_occupation=VALUES(report_parent_occupation),report_remarks=VALUES(report_remarks),report_remarks_secondary=VALUES(report_remarks_secondary),nm_two_day_earnings=VALUES(nm_two_day_earnings),nm_total_earnings=VALUES(nm_total_earnings),updated_by_role=VALUES(updated_by_role),updated_by_id=VALUES(updated_by_id)');
            $bindTypes='i'.str_repeat('s',10).'i';
            $stmt->bind_param($bindTypes,$id,$assignment,$start,$end,$parent,$occupation,$remarks,$secondary,$twoDay,$totalEarnings,$role,$actor);$stmt->execute();$stmt->close();
            logActivity($conn,$actor,'Beneficiaries','Report Details Update','Beneficiary Record','Updated report details for beneficiary ID: '.$id);
            $conn->commit();header('Location: application_reporting.php?'.http_build_query(['id'=>$id,'role'=>$role,'saved'=>1,'embedded'=>$embedded ? 1 : 0]));exit;
        } catch (Throwable $e) { $conn->rollback();$error='The details could not be saved. Refresh the application and try again.'; }
    }
}
$record=enrich_reporting_rows($conn,[$record])[0];
if ($_SERVER['REQUEST_METHOD']==='POST') foreach(['work_assignment','employment_start','employment_end','report_parent_name','report_parent_occupation','report_remarks','report_remarks_secondary','nm_two_day_earnings','nm_total_earnings'] as $field) $record[$field]=(string)($_POST[$field] ?? ($record[$field] ?? ''));
$prefix=$role==='admin'?'admin':'peso_staff';
$description = stripos($record['program_name'],'SPES') !== false
    ? 'Update the work assignment and remarks.'
    : (stripos($record['program_name'],'MSME') !== false
        ? 'Record verified night market earnings.'
        : 'Status records the progress, such as Completed. Add report notes only when there is an extra detail to explain; otherwise leave them blank.');
$pageTitle = stripos($record['program_name'],'SPES') !== false ? 'Work Assignment' : (stripos($record['program_name'],'MSME') !== false ? 'Night-market Earnings' : 'Remarks');
if ($embedded) {
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=module_escape($pageTitle)?></title><link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="module_center.css"><link rel="stylesheet" href="application_refinements.css?v=13"></head><body class="report-details-page application-embedded"><main class="module-main"><p class="application-editor-description"><?=module_escape($description)?></p><?php
} else module_shell_start($role,'application',$pageTitle,$description,'report-details-page');
?>
<section class="module-panel application-editor-identity"><h2><?=module_escape($record['full_name'])?></h2><p><?=module_escape($record['program_name'])?></p><?php if (!$embedded): ?><a href="<?=module_escape($prefix.'_application.php?'.http_build_query(['program_name'=>$record['program_name'],'program_id'=>$record['program_id']]))?>" class="module-btn secondary">Back to Application</a><?php endif ?></section>
<?php if($error!==''):?><p role="alert" class="module-note"><?=module_escape($error)?></p><?php endif?><?php if(isset($_GET['saved'])):?><p role="status" class="module-note">Changes saved.</p><?php endif?>
<?php if(!$embedded && stripos($record['program_name'],'SPES')!==false): ?>
<section class="module-panel"><h2>From the application</h2><p>These details are displayed automatically. Update parent information through Edit Information and program dates through Status.</p><dl class="report-source-details">
<?php foreach(['report_parent_name'=>'Parent / guardian','report_parent_occupation'=>'Parent occupation','employment_start'=>'Program start date','employment_end'=>'Program completion date'] as $key=>$label): ?><div><dt><?=module_escape($label)?></dt><dd><?=module_escape(official_report_value($record,$key) ?: 'Not yet recorded')?></dd></div><?php endforeach ?>
</dl></section>
<?php endif ?>
<section class="module-panel application-editor-form"><form method="post"><?=auth_csrf_input()?><div class="module-edit-grid">
<?php if(stripos($record['program_name'],'SPES')!==false):?>
<div class="module-field"><label for="work_assignment">Work assignment</label><input id="work_assignment" name="work_assignment" maxlength="250" value="<?=module_escape($record['work_assignment'] ?? '')?>"></div>
<?php endif?>
<?php if(stripos($record['program_name'],'MSME')!==false):?>
<div class="module-field"><label for="nm_two_day_earnings">Weekly earnings (2 market days)</label><input type="number" min="0" max="999999999999.99" step="0.01" inputmode="decimal" name="nm_two_day_earnings" id="nm_two_day_earnings" maxlength="100" value="<?=module_escape($record['nm_two_day_earnings'] ?? '')?>" placeholder="Amount in PHP"></div>
<div class="module-field"><label for="nm_total_earnings">Total night market earnings</label><input type="number" min="0" max="999999999999.99" step="0.01" inputmode="decimal" name="nm_total_earnings" id="nm_total_earnings" maxlength="100" value="<?=module_escape($record['nm_total_earnings'] ?? '')?>" placeholder="Amount in PHP"></div>
<?php endif?>
<div class="module-field application-remarks-field"><label for="report_remarks">Remarks</label><textarea name="report_remarks" id="report_remarks" maxlength="3000" aria-describedby="remarks_help"><?=module_escape($record['report_remarks'] ?? '')?></textarea><small id="remarks_help">Report notes, such as a correction or the reason work was not completed. Do not repeat the Status. Saving notes does not send an email.</small></div>
<?php if(stripos($record['program_name'],'TUPAD')!==false):?><details class="application-remarks-field application-secondary-remarks" <?=trim((string)($record['report_remarks_secondary'] ?? ''))!=='' ? 'open' : ''?>><summary>Second report notes field</summary><div class="module-field application-remarks-field"><label for="report_remarks_secondary">Additional remarks</label><textarea name="report_remarks_secondary" id="report_remarks_secondary" maxlength="3000"><?=module_escape($record['report_remarks_secondary'] ?? '')?></textarea><small>For the second Remarks column in the TUPAD report template.</small></div></details><?php endif?>
</div><div class="module-actions"><button type="submit" class="module-btn">Save Changes</button></div></form></section>
<?php if ($embedded) echo '</main></body></html>'; else module_shell_end();
