<?php
/** Shared navigation for the historical and submitted-document report views. */
function render_report_tabs(string $role, string $program, int $batch, string $search, string $active = 'All', string $spesGroup = 'all'): void {
    $prefix = $role === 'admin' ? 'admin' : 'peso_staff';
    $base = ['program_name'=>$program,'program_id'=>$batch,'search'=>$search];
    $tabs = ['All'=>'All Records','Completed'=>'Completed','Rejected'=>'Rejected'];
    ?>
    <nav class="report-tabs custom-tabs" aria-label="Report views">
    <?php foreach ($tabs as $value=>$label): ?>
      <a class="tab-item <?=$active===$value && $spesGroup==='all'?'active':''?>" href="<?=$prefix?>_beneficiaries.php?<?=module_escape(http_build_query($base+['outcome'=>$value,'spes_group'=>'all']))?>"><?=module_escape($label)?></a>
    <?php endforeach; ?>
    <?php if (stripos($program,'SPES')!==false): foreach (['baby'=>'SPES Baby','graduate'=>'Graduated'] as $value=>$label): ?>
      <a class="tab-item <?=$active!=='documents' && $spesGroup===$value?'active':''?>" href="<?=$prefix?>_beneficiaries.php?<?=module_escape(http_build_query($base+['outcome'=>'All','spes_group'=>$value]))?>"><?=module_escape($label)?></a>
    <?php endforeach; endif; ?>
      <a class="tab-item <?=$active==='documents'?'active':''?>" <?=$active==='documents'?'aria-current="page"':''?> href="requirements_report.php?<?=module_escape(http_build_query(['role'=>$role]+$base))?>">Documents Submitted</a>
    </nav>
<?php }
