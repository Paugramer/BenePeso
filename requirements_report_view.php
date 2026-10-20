<?php
// Use the original beneficiary page structure and styles, without module overrides.
$prefix=$role==='admin'?'admin':'peso_staff'; $active='report';
$label=$role==='admin'?'Admin Panel':'PESO Staff Panel';
$sidebarName=$_SESSION[$role==='admin'?'admin_name':'staff_name'] ?? 'PESO Vinzons';
$pic_path='img/default_user.svg';
$links=[['dashboard','Dashboard','squares-four',$prefix.'_dashboard.php'],['program','Programs','briefcase',$prefix.'_program.php'],['application','Application','file-text',$prefix.'_application.php'],['report','Report','chart-bar',$prefix.'_beneficiaries.php'],['forms','Forms & Templates','download-simple','forms_downloads.php?role='.$role]];
if($role==='admin')$links[]=['accounts','Manage Accounts','user-circle-gear','admin_accounts.php'];
$links[]=['logs','System Logs','clock-counter-clockwise',$prefix.'_activity_log.php'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BENEPESO | Report</title><link rel="icon" href="img/pesologo.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"><script src="https://unpkg.com/@phosphor-icons/web" defer></script>
<?php foreach(['admin_beneficiaries.css?v=20260924-mobile-performance','shared_sidebar.css?v=6','frontend_polish.css?v=20260929','admin_responsive.css?v=23','system_search_polish.css?v=1','beneficiary_workspace_polish.css?v=4','system_mobile.css?v=24','system_readability.css?v=1'] as $css):?><link rel="stylesheet" href="<?=module_escape($css)?>"><?php endforeach?>
<link rel="stylesheet" href="report_preview.css?v=10"><link rel="stylesheet" href="requirements_report_screen.css?v=2"></head>
<body class="admin-mobile-page admin-beneficiaries-page requirements-native"><div class="page-wrap"><div class="sidebar-overlay" id="sidebarOverlay"></div><?php require __DIR__.'/module_sidebar.php'; ?>
<main class="main-area"><header class="top-area"><div class="top-left"><button class="menu-toggle" id="menuToggle" type="button" aria-label="Open menu"><span></span><span></span><span></span></button><nav class="breadcrumb"><a href="<?=$prefix?>_beneficiaries.php"><i class="ph-fill ph-users"></i> Report</a><span class="separator">/</span><span class="current"><?=module_escape($filters['program_name'] ?: 'All Programs')?></span></nav><div class="top-big"><?=module_escape($filters['program_name'] ?: 'Beneficiary')?> Reports</div><div class="top-sub">View beneficiary records under <?=module_escape($filters['program_name'] ?: 'all programs')?>.</div></div><div class="top-actions"><div class="top-chip"><img class="chip-img" src="<?=module_escape($pic_path)?>" alt=""><?=module_escape($sidebarName)?></div></div></header>
<section class="panel-card" style="flex:1;margin-top:8px;display:flex;flex-direction:column">
<div class="records-toolbar"><?php render_report_tabs($role,$filters['program_name'],$filters['program_id'],$filters['search'],'documents'); ?><button class="btn-main" type="button" id="openReportModal"><i class="ph-bold ph-file-text" style="font-size:1.1rem;margin-right:6px"></i> Generate Report</button></div>
<form method="get" class="filter-container records-filter-container" id="requirementsFilters"><input type="hidden" name="role" value="<?=module_escape($role)?>"><input type="hidden" name="program_name" id="requirementsProgram" value="<?=module_escape($filters['program_name'])?>">
<div class="filter-search system-search"><i class="ph-bold ph-magnifying-glass search-icon"></i><input class="search-input" type="search" name="search" aria-label="Search submissions" placeholder="Search by name or batch..." value="<?=module_escape($filters['search'])?>"></div><button class="beneficiary-filter-toggle" type="button" id="submissionFilterToggle" aria-expanded="false" aria-controls="submissionFilterFields"><i class="ph ph-sliders-horizontal"></i> <span>More filters</span> <i class="ph ph-caret-down beneficiary-filter-caret"></i></button>
<div class="beneficiary-secondary-filters" id="submissionFilterFields" hidden>
<select name="program_id" id="requirementsBatch" class="filter-select auto-submit" aria-label="Batch"><option value="0">All Batches</option><?php foreach($batches as $batch):?><option value="<?=(int)$batch['program_id']?>" <?=$filters['program_id']===(int)$batch['program_id']?'selected':''?>><?=module_escape($batch['program_code'])?></option><?php endforeach?></select>
<select name="barangay" class="filter-select auto-submit" aria-label="Barangay"><option value="All">All Barangays</option><?php foreach($barangays as $item):?><option <?=$filters['barangay']===$item['barangay']?'selected':''?>><?=module_escape($item['barangay'])?></option><?php endforeach?></select>
<select name="review_status" class="filter-select auto-submit" aria-label="Requirements review"><?php foreach($statuses as $status):?><option value="<?=module_escape($status)?>" <?=$filters['review_status']===$status?'selected':''?>><?=module_escape($status==='All'?'All Review Statuses':$status)?></option><?php endforeach?></select></div></form>
<?php
$allSubmissionRows=$rows; $rows=array_slice($rows,$offset,25); $selectedProgramName=$filters['program_name']; $program=$selectedProgramName; $submissionReportMode=true;
require __DIR__.'/report_original_table.php'; $rows=$allSubmissionRows;
?>
<nav class="pagination-wrapper" aria-label="Submission report pages"><?php if($page>1):?><a class="btn-edit" href="?<?=module_escape(http_build_query($query+['page'=>$page-1]))?>">Previous</a><?php endif?><span class="page-info">Page <?=$page?> of <?=$pages?></span><?php if($page<$pages):?><a class="btn-edit" href="?<?=module_escape(http_build_query($query+['page'=>$page+1]))?>">Next</a><?php endif?></nav>
</section></main></div>
<?php require __DIR__.'/report_original_profile.php'; require __DIR__.'/requirements_report_generator.php'; ?>
<script src="module_center.js?v=2"></script><script src="requirements_report.js?v=3"></script></body></html>
