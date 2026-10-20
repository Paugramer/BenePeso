<?php $selectedProgramName=$filters['program_name']; $reportProgramTitle=getReportProgramTitle($selectedProgramName); $submissionColumns=array_values(array_filter(official_report_columns($selectedProgramName),static fn($column)=>$column['label']!=='')); ?>
<div class="modal" id="generateReportModal" data-submission-report="1" aria-hidden="true">
  <div class="modal-backdrop" data-close-report></div>
  <div class="modal-dialog split-layout" style="position: relative; overflow: hidden !important;">
    
    <button type="button" class="modal-close-icon report-close-button" data-close-report aria-label="Close generate report"><i class="ph-bold ph-x"></i></button>

    <div class="report-controls" id="reportFiltersPanel">
        <div class="report-controls-heading">
            <div class="report-title-row"><span class="report-title-icon" aria-hidden="true"><i class="ph-bold ph-file-text"></i></span><div><div class="modal-title">Generate Report</div><div class="report-program-label"><?php echo module_escape($selectedProgramName); ?> beneficiary records</div></div></div>
            <div class="modal-sub">Choose filters and columns, then print the preview or export an editable workbook.</div>
        </div>
        
        <form method="GET" action="requirements_report.php" target="_blank" class="report-form">
            <input type="hidden" name="program_name" value="<?php echo module_escape($selectedProgramName); ?>">
            
            <input type="hidden" name="role" value="<?=module_escape($role)?>"><input type="hidden" name="search" value="<?=module_escape($filters['search'])?>"><div class="report-filter-list" style="flex: 1; overflow-y: auto; padding-right: 8px; display: flex; flex-direction: column; gap: 16px; margin-bottom: 16px;">
                <div class="form-group" style="margin:0;">
                    <label>Select Batch Filter</label>
                    <select name="program_id" id="report_batch_select">
                        <option value="0">All Batches</option>
                        <?php foreach($batches as $b): ?><option value="<?php echo $b['program_id']; ?>" <?=$filters['program_id']===(int)$b['program_id']?'selected':''?>><?php echo module_escape($b['program_code']); ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin:0;">
                    <label>Select Barangay</label>
                    <select name="barangay" id="report_brgy_select">
                        <option value="All">Municipality of Vinzons</option>
                        <?php foreach ($barangays as $barangay): ?><option value="<?php echo module_escape($barangay['barangay']); ?>" <?=$filters['barangay']===$barangay['barangay']?'selected':''?>><?php echo module_escape($barangay['barangay']); ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin:0"><label>Requirements Review</label><select name="review_status" id="report_avail_select"><?php foreach($statuses as $status):?><option value="<?=module_escape($status)?>" <?=$filters['review_status']===$status?'selected':''?>><?=module_escape($status==='All'?'All Review Statuses':$status)?></option><?php endforeach?></select></div>
<details class="report-column-picker">
                    <summary><span>Preview Columns</span><span class="report-column-count"></span></summary><p class="report-preview-format-note">Print and Export Data use the complete original report format.</p>
                    <div class="report-column-tools">
                        <button type="button" data-report-columns="essential">Common Fields</button>
                        <button type="button" data-report-columns="all">Select All</button>
                    </div>
                    <div class="report-column-list">
                        <?php foreach ($submissionColumns as $columnIndex => $column): ?>
                            <label>
                                <input type="checkbox" name="columns[]" value="<?php echo module_escape($column['key']); ?>" data-column-index="<?php echo $columnIndex + 1; ?>" data-default="<?php echo $column['default'] ? '1' : '0'; ?>" checked>
                                <span><?php echo module_escape($column['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </details>

                <div class="form-group" style="margin:0;">
                    <label>Export Format</label>
                    <input type="hidden" name="format" value="xlsx">
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
                <h2><?=module_escape($reportProgramTitle)?></h2>
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


<script>window.currentReportTitle=<?=json_encode($reportProgramTitle,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script><script src="report_preview.js?v=9" defer></script>
