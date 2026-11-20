<?php
// Use the shared template preview so date filters also control print and export.
$selectedProgramName=$program;$reportProgramTitle=getReportProgramTitle($program);$barangays=[];$businessNatures=[];
$result=$conn->query("SELECT DISTINCT barangay FROM beneficiaries WHERE barangay IS NOT NULL AND barangay<>'' ORDER BY barangay");while($record=$result->fetch_assoc())$barangays[]=$record['barangay'];
$result=$conn->query("SELECT DISTINCT business_nature FROM beneficiaries WHERE business_nature IS NOT NULL AND business_nature<>'' ORDER BY business_nature");while($record=$result->fetch_assoc()){foreach(official_report_list($record['business_nature']) as $nature)$businessNatures[]=$nature;}$businessNatures=array_values(array_unique($businessNatures));
?>
<link rel="stylesheet" href="report_preview.css?v=10"><link rel="stylesheet" href="report_ui.css?v=1">
<div class="modal" id="generateReportModal" aria-hidden="true">
  <div class="modal-backdrop" data-close-report></div>
  <div class="modal-dialog split-layout" style="position: relative; overflow: hidden !important;">
    
    <button type="button" class="modal-close-icon report-close-button" data-close-report aria-label="Close generate report"><i class="ph-bold ph-x"></i></button>

    <div class="report-controls" id="reportFiltersPanel">
        <div class="report-controls-heading">
            <div class="report-title-row"><span class="report-title-icon" aria-hidden="true"><i class="ph-bold ph-file-text"></i></span><div><div class="modal-title">Generate Report</div><div class="report-program-label"><?php echo module_escape($selectedProgramName); ?> beneficiary records</div></div></div>
            <div class="modal-sub">Choose filters and columns, then print the preview or export an editable workbook.</div>
        </div>
        
        <form method="GET" action="export_report.php" target="_blank" class="report-form">
            <input type="hidden" name="program_name" value="<?php echo module_escape($selectedProgramName); ?>">
            
            <div class="report-filter-list" style="flex: 1; overflow-y: auto; padding-right: 8px; display: flex; flex-direction: column; gap: 16px; margin-bottom: 16px;">
<?php render_report_date_fields($reportDates,"preview_dates"); ?>
                <div class="form-group" style="margin:0;">
                    <label>Select Batch Filter</label>
                    <select name="program_id" id="report_batch_select">
                        <option value="0">All Batches</option>
                        <?php foreach($batches as $b): ?><option value="<?php echo $b['program_id']; ?>"><?php echo module_escape($b['program_code']); ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin:0;">
                    <label>Select Barangay</label>
                    <select name="report_barangay" id="report_brgy_select">
                        <option value="All">Municipality of Vinzons</option>
                        <?php foreach ($barangays as $barangay): ?><option value="<?php echo module_escape($barangay); ?>"><?php echo module_escape($barangay); ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin:0;">
                    <label>Status Filter</label>
                    <select name="report_outcome" id="report_avail_select">
<?php foreach (['All'=>'All Records','Completed'=>'Completed','Rejected'=>'Rejected','Documents Submitted'=>'Documents Submitted'] as $value=>$label): ?><option value="<?=module_escape($value)?>" <?=$status===$value?'selected':''?>><?=module_escape($label)?></option><?php endforeach; ?>
                    </select>
                </div>
                <?php if (stripos($selectedProgramName, 'SPES') !== false): ?>
                <div class="form-group" style="margin:0;"><label>SPES Classification Filter</label><select name="report_spes_group" id="report_spes_group_select"><option value="all">All SPES Records</option><option value="baby">SPES Baby</option><option value="graduate">SPES Graduate</option></select></div>
                <?php endif; ?>

                <?php if (stripos($selectedProgramName, 'MSME') !== false): ?>
                <div class="form-group" style="margin:0;">
                    <label>Type of Vendor Filter</label>
                    <select name="business_nature" id="report_nature_select">
                        <option value="All">All Vendor Types</option>
                        <?php foreach ($businessNatures as $nature): ?>
                            <option value="<?php echo module_escape($nature); ?>"><?php echo module_escape($nature); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <details class="report-column-picker">
                    <summary><span>Report Columns</span><span class="report-column-count"></span></summary>
                    <div class="report-column-tools">
                        <button type="button" data-report-columns="essential">Common Fields</button>
                        <button type="button" data-report-columns="all">Select All</button>
                    </div>
                    <div class="report-column-list">
                        <?php foreach (array_values(array_filter(official_report_columns($selectedProgramName),static fn($column)=>$column['label']!=='')) as $columnIndex => $column): ?>
                            <label>
                                <input type="checkbox" name="columns[]" value="<?php echo module_escape($column['key']); ?>" data-column-index="<?php echo $columnIndex + 1; ?>" data-default="<?php echo $column['default'] ? '1' : '0'; ?>" checked>
                                <span><?php echo module_escape($column['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </details>

                <div class="form-group" style="margin:0;">
                    <label>Export Format</label>
                    <input type="hidden" name="export_format" value="xlsx">
                    <div class="report-format-card"><span class="report-format-icon" aria-hidden="true"><i class="ph-bold ph-file-xls"></i></span><span><strong>Excel Workbook</strong><small>Editable .xlsx data file</small></span><i class="ph-bold ph-check-circle report-format-check" aria-hidden="true"></i></div>
                </div>
            </div>

            <div class="report-actions">
                <button type="button" class="report-action-button report-action-print" id="report_print_button"><i class="ph-bold ph-printer"></i> Print</button>
                <button type="submit" class="report-action-button report-action-export"><i class="ph-bold ph-download-simple"></i> Export Data</button>
            </div>
        </form>
    </div>

    <div class="report-preview">
        <button type="button" class="report-filter-toggle" aria-controls="reportFiltersPanel" aria-expanded="false"><i class="ph-bold ph-funnel"></i><span>Filters</span></button>
        <div class="spreadsheet-container">
            <div class="preview-header">
                <img class="official-report-header" src="assets/peso_official_report_header.png?v=20260820-compact" alt="PESO Vinzons official letterhead">
                <h2><?php echo module_escape($reportProgramTitle); ?></h2>
                <p id="preview_subtitle_top">Municipality of Vinzons</p>
            </div>
            <div class="report-view-toolbar" aria-label="Report preview controls">
                <span class="report-view-label"><i class="ph-bold ph-magnifying-glass"></i><span>Preview size</span></span>
                <div class="report-zoom-controls">
                    <button type="button" data-report-zoom="out" aria-label="Zoom report out" title="Zoom out"><i class="ph-bold ph-minus"></i></button>
                    <output class="report-zoom-value" aria-live="polite">100%</output>
                    <button type="button" data-report-zoom="in" aria-label="Zoom report in" title="Zoom in"><i class="ph-bold ph-plus"></i></button>
                    <button type="button" class="report-fit-button" data-report-zoom="fit"><i class="ph-bold ph-arrows-in-line-horizontal"></i><span>Fit width</span></button>
                </div>
            </div>
            <div class="report-column-pagination" aria-label="Report column navigation">
                <button type="button" data-report-column-page="previous" aria-label="Previous report columns" title="Previous columns"><i class="ph-bold ph-caret-left"></i> Previous columns</button>
                <span class="report-column-page-status">Columns 1–8</span>
                <button type="button" data-report-column-page="next" aria-label="Next report columns" title="Next columns">Next columns <i class="ph-bold ph-caret-right"></i></button>
            </div>
            <div class="scrollable-table-wrap">
                <table class="spreadsheet-table" id="preview_main_table">
                    <thead id="preview_table_head"></thead>
                    <tbody id="preview_table_body"></tbody>
                </table>
            </div>
            <div id="preview_pagination" class="modern-pagination-controls"></div>
        </div>
    </div>
  </div>
</div>

<script>window.currentReportTitle=<?=json_encode($reportProgramTitle,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script><script src="report_preview.js?v=13" defer></script>
