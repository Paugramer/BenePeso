<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/module_shell.php';
$requestedRole=(string)($_GET['role'] ?? '');
$role=in_array($requestedRole,['admin','peso_staff'],true) ? $requestedRole : auth_first_active_role(['admin','peso_staff']);
if (!$role) { http_response_code(403); exit('Please sign in as Admin or PESO Staff.'); }
check_user_role($role);
module_shell_start($role,'forms','Forms & Templates','Download official forms and blank templates for each program.','forms-workspace');
$documents=[
 'TUPAD'=>[
 ['TUPAD Application Form','assets/forms/TUPAD Application Form.pdf'],
 ['TUPAD Report Template','assets/report_templates/TUPAD_Report_Blank.xlsx'],
 ['TUPAD Import Template','assets/templates/TUPAD_Import_Blank.xlsx']],
 'SPES'=>[
 ['SPES Form 2 — Application Form','assets/forms/SPES Form 2 - Application Form.pdf'],
 ['SPES Form 2-A — Oath of Undertaking','assets/forms/SPES Form 2-A - Oath of Undertaking.pdf'],
 ['SPES Report Template','assets/report_templates/SPES_Report_Blank.xlsx'],
 ['SPES Import Template','assets/templates/SPES_Import_Blank.xlsx']],
 'MSME'=>[
 ['MSME Profiling Form','assets/forms/MSME Profiling Form.pdf'],
 ['MSME Report Template','assets/report_templates/MSME_Report_Blank.xlsx'],
 ['MSME Import Template','assets/templates/MSME_Import_Blank.xlsx']]];
$documents=['MSME'=>$documents['MSME'],'SPES'=>$documents['SPES'],'TUPAD'=>$documents['TUPAD']];
foreach($documents as $program=>$items):?>
<section class="module-panel"><h2><?=module_escape($program)?></h2><div class="document-grid">
<?php foreach($items as [$name,$path]): if(!is_file(__DIR__.'/'.$path)) continue;?><article class="document-card" tabindex="0" role="button" aria-label="Preview document"><div class="document-badges"><span class="document-badge"><?=module_escape($program)?></span><span class="document-badge file-badge">.<?=module_escape(strtoupper(pathinfo($path,PATHINFO_EXTENSION)))?></span></div><h3><?=module_escape($name)?></h3><p class="document-description"><?=strpos($name,'Import')!==false?'Blank workbook for uploading beneficiary records.':(strpos($name,'Report')!==false?'Official blank report format.':'Official form for program requirements.')?></p><a class="module-btn secondary" download="<?=module_escape(str_ireplace('_Blank','',basename($path)))?>" href="<?=module_escape(str_replace('%2F','/',rawurlencode($path)))?>" aria-label="Download <?=module_escape($name)?>"><i class="ph ph-eye" aria-hidden="true"></i> Preview</a></article><?php endforeach?></div></section>
<?php endforeach; ?><dialog id="documentPreviewModal" aria-labelledby="documentPreviewTitle"><header><h2 id="documentPreviewTitle">Document Preview</h2><button type="button" data-close-document aria-label="Close preview">&#215;</button></header><div class="document-preview-body"><p id="documentPreviewMessage" role="status">Loading preview...</p><iframe id="documentPreviewFrame" title="Document preview" hidden></iframe></div><footer><button type="button" class="module-btn secondary" data-close-document>Close</button><a id="documentPreviewDownload" class="module-btn" download hidden><i class="ph ph-download-simple" aria-hidden="true"></i> Download</a></footer></dialog><script src="module_documents.js?v=5"></script><?php module_shell_end();
