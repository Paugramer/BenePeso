<?php
// Original report-only markup and preview code recovered from admin_beneficiaries.php at 969802a.
$selectedProgramName=$program;$reportProgramTitle=getReportProgramTitle($program);$barangays=[];$businessNatures=[];
$result=$conn->query("SELECT DISTINCT barangay FROM beneficiaries WHERE barangay IS NOT NULL AND barangay<>'' ORDER BY barangay");while($record=$result->fetch_assoc())$barangays[]=$record['barangay'];
$result=$conn->query("SELECT DISTINCT business_nature FROM beneficiaries WHERE business_nature IS NOT NULL AND business_nature<>'' ORDER BY business_nature");while($record=$result->fetch_assoc()){foreach(official_report_list($record['business_nature']) as $nature)$businessNatures[]=$nature;}$businessNatures=array_values(array_unique($businessNatures));
?>
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
                        <?php foreach (getReportColumnDefinitions($selectedProgramName) as $columnIndex => $column): ?>
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

<script>
(()=>{
    let allBeneficiariesData = [];
    const currentProgramName = <?php echo json_encode($selectedProgramName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const currentReportTitle = <?php echo json_encode($reportProgramTitle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    let reportDataPromise = null;

    function loadReportData() {
      if (reportDataPromise) return reportDataPromise;
      const url = `beneficiary_report_data.php?program_name=${encodeURIComponent(currentProgramName)}`;
      reportDataPromise = fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(response => response.json().then(payload => ({ response, payload })))
        .then(({ response, payload }) => {
          if (!response.ok || !payload.ok || !Array.isArray(payload.records)) {
            throw new Error(payload.message || 'Report records could not be loaded.');
          }
          allBeneficiariesData = payload.records;
          return allBeneficiariesData;
        })
        .catch(error => {
          reportDataPromise = null;
          throw error;
        });
      return reportDataPromise;
    }
    
    let previewData = [];
    let currentPreviewPage = 1;
    const previewItemsPerPage = 10;

function showBeneficiaryNotice(title,message){let note=document.getElementById('originalReportMessage');if(!note){note=document.createElement('p');note.id='originalReportMessage';note.setAttribute('role','status');document.querySelector('#generateReportModal .report-controls-heading').append(note);}note.textContent=title+': '+message;}
const reportModal=document.getElementById('generateReportModal');document.addEventListener('keydown',event=>{if(event.key==='Escape'&&reportModal.classList.contains('show'))setModalVisibility(reportModal,false,document.getElementById('openReportModal'));});
function setModalVisibility(modal,open,trigger){modal.classList.toggle('show',open);modal.setAttribute('aria-hidden',String(!open));if(!open){modal.classList.remove('filters-open');trigger?.focus();}}
      const openReportBtn = document.getElementById('openReportModal');
      const reportFilterToggle = document.querySelector('#generateReportModal .report-filter-toggle');
      if(openReportBtn) {
          openReportBtn.addEventListener('click', async () => {
              setModalVisibility(reportModal, true);
              reportModal.classList.remove('filters-open');
              reportFilterToggle?.setAttribute('aria-expanded', 'false');
              openReportBtn.disabled = true;
              openReportBtn.setAttribute('aria-busy', 'true');
              try {
                  await loadReportData();
                  updateReportPreview();
              } catch (error) {
                  setModalVisibility(reportModal, false, openReportBtn);
                  showBeneficiaryNotice('Report Unavailable', error.message || 'Report records could not be loaded. Please try again.');
              } finally {
                  openReportBtn.disabled = false;
                  openReportBtn.removeAttribute('aria-busy');
              }
          });
      }
      reportFilterToggle?.addEventListener('click', () => {
          const open = reportModal.classList.toggle('filters-open');
          reportFilterToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      document.querySelectorAll('#generateReportModal [data-close-report]').forEach(btn => btn.addEventListener('click', () => {
          setModalVisibility(reportModal, false, openReportBtn);
          reportFilterToggle?.setAttribute('aria-expanded', 'false');
      }, true));

      const reportBrgySelect = document.getElementById('report_brgy_select');
      const reportAvailSelect = document.getElementById('report_avail_select');
      const reportBatchSelect = document.getElementById('report_batch_select');
      const reportSpesGroupSelect = document.getElementById('report_spes_group_select');
      const reportNatureSelect = document.getElementById('report_nature_select');
      const reportPrintButton = document.getElementById('report_print_button');
      const reportPreviewTable = document.getElementById('preview_main_table');
      const reportTableViewport = document.querySelector('#generateReportModal .scrollable-table-wrap');
      const reportZoomValue = document.querySelector('#generateReportModal .report-zoom-value');
      const reportZoomButtons = Array.from(document.querySelectorAll('#generateReportModal [data-report-zoom]'));
      const reportColumnInputs = Array.from(document.querySelectorAll('.report-column-list input[type="checkbox"]'));
      const reportColumnCount = document.querySelector('.report-column-count');
      const reportColumnDefinitions = <?php echo json_encode(getReportColumnDefinitions($selectedProgramName), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      const getReportColumnsPerPage = () => window.innerWidth <= 520 ? 2 : (window.innerWidth <= 900 ? 4 : 8);
      let currentReportColumnPage = 1;
      let showAllReportColumns = false;
      let reportZoom = 1;

      function setReportZoom(nextZoom) {
          reportZoom = Math.min(1.5, Math.max(0.65, Math.round(nextZoom * 10) / 10));
          if (reportPreviewTable) reportPreviewTable.style.zoom = String(reportZoom);
          if (reportZoomValue) reportZoomValue.value = `${Math.round(reportZoom * 100)}%`;
          reportZoomButtons.forEach(button => {
              button.disabled = (button.dataset.reportZoom === 'out' && reportZoom <= 0.65)
                  || (button.dataset.reportZoom === 'in' && reportZoom >= 1.5);
              button.classList.toggle('is-active', button.dataset.reportZoom === 'fit' && reportZoom === 1);
          });
          if (reportZoom === 1 && reportTableViewport) reportTableViewport.scrollLeft = 0;
      }

      reportZoomButtons.forEach(button => button.addEventListener('click', () => {
          const action = button.dataset.reportZoom;
          setReportZoom(action === 'in' ? reportZoom + 0.1 : (action === 'out' ? reportZoom - 0.1 : 1));
      }));
      setReportZoom(1);

      const reportMultiValueKeys = ['primary_products','product_price','business_nature','assets_owned','utility_needs','source_of_capital','mode_of_payment','distribution_channels','assistance_availed','past_programs','programs_needed','challenges_encountered','special_skills','parents_status','ownership_type','hr_skills','skills_training_needed','type_of_beneficiary','employment_type','spes_history'];

      function uniqueReportListItems(value) {
          let source = value;
          if (!Array.isArray(source)) {
              const raw = String(source || '').trim();
              try {
                  const decoded = JSON.parse(raw);
                  if (Array.isArray(decoded)) source = decoded;
              } catch (error) {}
          }
          const flattened = (Array.isArray(source) ? source.flat(Infinity) : String(source || '').split(/[,;\r\n]+/));
          const seen = new Set();
          return flattened.map(item => String(item ?? '').trim()).filter(item => {
              const key = item.replace(/\s+/g, ' ').toLowerCase();
              if (!key || seen.has(key)) return false;
              seen.add(key);
              return true;
          });
      }

      function formatReportList(items) {
          const values = Array.isArray(items) ? items.filter(Boolean) : uniqueReportListItems(items);
          return values.length <= 1 ? (values[0] || '') : values.map(item => `• ${item}`).join('\n');
      }

      function formatStructuredReportValue(value) {
          const raw = String(value ?? '').trim();
          if (!raw) return '';
          try {
              const decoded = JSON.parse(raw);
              if (Array.isArray(decoded)) return formatReportList(uniqueReportListItems(decoded));
          } catch (error) {}
          if (/[;\r\n]/.test(raw)) {
              const items = raw.split(/[;\r\n]+/).map(item => item.trim()).filter(Boolean);
              return formatReportList([...new Map(items.map(item => [item.replace(/\s+/g, ' ').toLowerCase(), item])).values()]);
          }
          return raw;
      }

      function formatSpesHistory(row) {
          let history = row.spes_history;
          for (let attempt = 0; attempt < 2 && typeof history === 'string'; attempt++) {
              try { history = JSON.parse(history); } catch (error) { break; }
          }
          let entries = [];
          if (Array.isArray(history)) {
              entries = history.map(entry => {
                  if (!entry || typeof entry !== 'object') return '';
                  const establishment = String(entry.establishment ?? entry[1] ?? '').trim();
                  const year = String(entry.year ?? entry[2] ?? '').trim();
                  const id = String(entry.id ?? entry[3] ?? '').trim();
                  return [establishment, year ? `Year: ${year}` : '', id ? `SPES ID: ${id}` : ''].filter(Boolean).join(' | ');
              }).filter(Boolean);
          }
          if (!entries.length) {
              entries = [1,2,3,4].map(number => {
                  const establishment = String(row[`spes_history_${number}_establishment`] || '').trim();
                  const year = String(row[`spes_history_${number}_year`] || '').trim();
                  const id = String(row[`spes_history_${number}_id`] || '').trim();
                  return [establishment, year ? `Year: ${year}` : '', id ? `SPES ID: ${id}` : ''].filter(Boolean).join(' | ');
              }).filter(Boolean);
          }
          return entries.length ? entries.map(entry => `• ${entry}`).join('\n') : (typeof history === 'string' ? history : '');
      }

      function reportProductParts(row) {
          let names = [];
          let prices = [];
          const raw = String(row.primary_products || '').trim();
          try {
              const decoded = JSON.parse(raw);
              if (Array.isArray(decoded)) decoded.forEach(product => {
                  if (!Array.isArray(product) || !String(product[0] || '').trim()) return;
                  names.push(String(product[0]).trim());
                  prices.push(String(product[1] || '').trim());
              });
          } catch (error) {
              raw.split(/,\s*(?![^()]*\))/).filter(Boolean).forEach(product => {
                  const match = product.trim().match(/^(.*?)\s*\((?:₱|PHP\s*)?([0-9.,]+)\)$/i);
                  names.push(match ? match[1].trim() : product.trim());
                  if (match) prices.push(match[2].trim());
              });
          }
          if (String(row.product_price || '').trim()) prices = String(row.product_price).split(',').map(price => price.trim());
          const groups = new Map();
          names.forEach((name, index) => {
              const cleanName = String(name || '').trim();
              if (!cleanName) return;
              const groupKey = cleanName.replace(/\s+/g, ' ').toLowerCase();
              if (!groups.has(groupKey)) groups.set(groupKey, { name: cleanName, prices: [] });
              const price = String(prices[index] || '').trim();
              const group = groups.get(groupKey);
              if (price && !group.prices.includes(price)) group.prices.push(price);
          });
          const grouped = Array.from(groups.values());
          return {
              names: formatReportList(grouped.map(group => group.name)),
              prices: formatReportList(grouped.map(group => group.prices.length ? group.prices.join(' / ') : '—'))
          };
      }

      function reportCellValue(row, key) {
          const products = (key === 'primary_products' || key === 'product_price') ? reportProductParts(row) : null;
          if (key === 'primary_products') return products.names;
          if (key === 'product_price') return products.prices;
          if (key === 'spes_history') return formatSpesHistory(row);
          if (key === 'employment_type') return formatReportList(uniqueReportListItems(row.employment_type || [row.emp_regular ? 'Regular' : '', row.emp_seasonal ? 'Seasonal' : '', row.emp_contractual ? 'Contractual' : '', row.emp_family ? 'Family' : ''].filter(Boolean)));
          if (reportMultiValueKeys.includes(key)) return formatReportList(uniqueReportListItems(row[key]));
          if (key === 'owner_name') return row.full_name || [row.first_name, row.middle_name, row.last_name, row.ext_name].filter(Boolean).join(' ');
          if (key === 'owner_contact') return row.contact_no || '';
          if (key === 'complete_address') return row.address || [row.street_purok_zone, row.barangay, 'Vinzons, Camarines Norte'].filter(Boolean).join(' ');
          if (key === 'school') return row.tert_school || row.sec_school || row.elem_school || '';
          if (key === 'course') return row.tert_course || row.sec_degree || '';
          if (key === 'level') return row.tert_year_level || row.sec_year_level || row.elem_year_level || '';
          if (key === 'parent_name') return row.father_name || row.mother_name || row.gsis_beneficiary_name || '';
          if (key === 'parent_occupation') return row.father_occupation || row.mother_occupation || '';
          if (key === 'hr_total') return row.hr_total || ((parseInt(row.hr_male) || 0) + (parseInt(row.hr_female) || 0)) || '';
          if (key === 'current_capital') return row.current_capital || row.initial_capital || '';
          if (key === 'remarks') return row.approval_note || row.remarks || '';
          if (key === 'approval_status') return String(row.approval_status || '').toUpperCase() === 'APPROVED' ? 'QUALIFIED' : (String(row.approval_status || '').toUpperCase() === 'REJECTED' ? 'DISQUALIFIED' : row.approval_status || '');
          if (['dti_assistance','dole_assistance','lgu_assistance','tesda_training'].includes(key) && !row[key]) return String(row.assistance_availed || '').toUpperCase().includes(key.split('_')[0].toUpperCase()) ? 'Yes' : '';
          if (key === 'financial_assistance' && !row[key]) return String(row.programs_needed || '').toLowerCase().includes('financ') ? 'Needed' : '';
          if (key === 'livelihood_assistance' && !row[key]) return String(row.assistance_availed || '').toLowerCase().includes('livelihood') ? 'Yes' : '';
          if (key === 'business_training' && !row[key]) return String(row.past_programs || '').toLowerCase().includes('training') ? 'Yes' : '';
          return formatStructuredReportValue(row[key] ?? '');
      }

      function escapeReportValue(value) {
          const div = document.createElement('div');
          div.textContent = String(value ?? '');
          return div.innerHTML;
      }
      
      if(reportBrgySelect && reportAvailSelect) {
          reportBrgySelect.addEventListener('change', updateReportPreview);
          reportAvailSelect.addEventListener('change', updateReportPreview);
          if (reportBatchSelect) reportBatchSelect.addEventListener('change', updateReportPreview);
          if (reportSpesGroupSelect) reportSpesGroupSelect.addEventListener('change', updateReportPreview);
          if (reportNatureSelect) reportNatureSelect.addEventListener('change', updateReportPreview);
      }
      if (reportPrintButton) reportPrintButton.addEventListener('click', printFilteredReport);
      reportColumnInputs.forEach(input => input.addEventListener('change', () => {
          if (!reportColumnInputs.some(column => column.checked)) input.checked = true;
          currentReportColumnPage = 1;
          updateReportPreview();
      }));
      document.querySelectorAll('[data-report-columns]').forEach(button => button.addEventListener('click', () => {
          const selectAll = button.dataset.reportColumns === 'all';
          reportColumnInputs.forEach(input => { input.checked = selectAll || input.dataset.default === '1'; });
          currentReportColumnPage = 1;
          updateReportPreview();
      }));
      document.querySelectorAll('[data-report-column-page]').forEach(button => button.addEventListener('click', () => {
          const selectedCount = reportColumnInputs.filter(input => input.checked).length;
          const reportColumnsPerPage = getReportColumnsPerPage();
          const totalPages = Math.max(1, Math.ceil(selectedCount / reportColumnsPerPage));
          const direction = button.dataset.reportColumnPage === 'next' ? 1 : -1;
          currentReportColumnPage = Math.min(totalPages, Math.max(1, currentReportColumnPage + direction));
          applyReportColumnVisibility();
      }));
      window.addEventListener('resize', () => {
          if (reportModal?.classList.contains('show')) applyReportColumnVisibility();
      });

      function applyReportColumnVisibility() {
          const table = document.getElementById('preview_main_table');
          const thead = document.getElementById('preview_table_head');
          if (!table || !thead || reportColumnInputs.length === 0) return;

          if (thead.rows.length > 1) {
              const labels = Array.from(thead.rows[thead.rows.length - 1].cells).map(cell => cell.textContent);
              thead.innerHTML = `<tr><th>No.</th>${labels.map(label => `<th>${label}</th>`).join('')}</tr>`;
          }

          const selectedIndexes = reportColumnInputs.filter(input => input.checked).map(input => Number(input.dataset.columnIndex));
          const reportColumnsPerPage = getReportColumnsPerPage();
          const totalColumnPages = Math.max(1, Math.ceil(selectedIndexes.length / reportColumnsPerPage));
          currentReportColumnPage = Math.min(totalColumnPages, Math.max(1, currentReportColumnPage));
          const columnStart = (currentReportColumnPage - 1) * reportColumnsPerPage;
          const pageIndexes = showAllReportColumns ? selectedIndexes : selectedIndexes.slice(columnStart, columnStart + reportColumnsPerPage);
          const visibleIndexes = new Set([0, ...pageIndexes]);
          const cellFontSize = showAllReportColumns ? (visibleIndexes.size > 22 ? '5.5px' : '7px') : '9px';
          const headerLabels = Array.from(thead.rows[0]?.cells || []).map(cell => cell.textContent.trim());
          table.classList.remove('report-detail-view');
          table.style.width = '100%';
          table.style.minWidth = '0';
          table.querySelectorAll('tr').forEach(row => {
              if (row.cells.length === 1 && row.cells[0].hasAttribute('colspan')) {
                  row.cells[0].colSpan = visibleIndexes.size;
                  return;
              }
              Array.from(row.cells).forEach((cell, index) => {
                  cell.style.display = visibleIndexes.has(index) ? '' : 'none';
                  cell.style.fontSize = cellFontSize;
                  cell.classList.toggle('report-column-visible', visibleIndexes.has(index));
                  if (row.parentElement?.tagName === 'TBODY') cell.dataset.label = headerLabels[index] || '';
              });
          });
          if (reportColumnCount) reportColumnCount.textContent = `${selectedIndexes.length} selected`;
          const pageStatus = document.querySelector('.report-column-page-status');
          if (pageStatus) {
              const firstVisible = selectedIndexes.length ? columnStart + 1 : 0;
              const lastVisible = Math.min(columnStart + reportColumnsPerPage, selectedIndexes.length);
              pageStatus.textContent = showAllReportColumns ? `All ${selectedIndexes.length} columns` : `Columns ${firstVisible}–${lastVisible} of ${selectedIndexes.length}`;
          }
          document.querySelectorAll('[data-report-column-page]').forEach(button => {
              button.disabled = showAllReportColumns || (button.dataset.reportColumnPage === 'previous' ? currentReportColumnPage <= 1 : currentReportColumnPage >= totalColumnPages);
          });
      }

      function updateReportPreview() {
          const selectedBrgy = reportBrgySelect.value;
          const selectedAvail = reportAvailSelect.value;
          const selectedBatchId = reportBatchSelect ? reportBatchSelect.value : '0';
          const selectedSpesGroup = reportSpesGroupSelect ? reportSpesGroupSelect.value : 'all';
          const selectedNature = reportNatureSelect ? reportNatureSelect.value : 'All';
          
          const subtitleParts = [];
          if (selectedBatchId !== '0' && reportBatchSelect) subtitleParts.push(`Batch: ${reportBatchSelect.options[reportBatchSelect.selectedIndex].text}`);
          subtitleParts.push(selectedBrgy === 'All' || selectedBrgy === '' ? 'Municipality of Vinzons' : `Barangay ${selectedBrgy}, Vinzons`);
          if (selectedAvail !== 'All') subtitleParts.push(`Status: ${selectedAvail === 'Not Yet Availed' ? 'Waiting for Requirements' : (selectedAvail === 'Requirements Received' ? 'Documents Submitted' : selectedAvail)}`);
          if (selectedNature !== 'All') subtitleParts.push(`Vendor Type: ${selectedNature}`);
          if (selectedSpesGroup !== 'all') subtitleParts.push(selectedSpesGroup === 'graduate' ? 'SPES Graduates' : 'SPES Babies');
          const subtitleText = subtitleParts.join(' | ');
          const subTitle = document.getElementById('preview_subtitle_top');
          if (subTitle) subTitle.textContent = subtitleText;
          
          const thead = document.getElementById('preview_table_head');
          
          if (thead) {
              if (reportColumnDefinitions.length) {
                  thead.innerHTML = `<tr><th>No.</th>${reportColumnDefinitions.map(column => `<th>${escapeReportValue(column.label)}</th>`).join('')}</tr>`;
              } else if (currentProgramName.toUpperCase().includes('MSME')) {
                  thead.innerHTML = `
                  <tr>
                      <th rowspan="2">No.</th>
                      <th colspan="13">I. BUSINESS PROFILE</th>
                      <th colspan="9">II. OWNER/ENTREPRENEUR INFORMATION</th>
                      <th colspan="2">III. BUSINESS OPERATIONS</th>
                      <th colspan="5">IV. HUMAN RESOURCES</th>
                      <th colspan="7">V. FINANCIAL INFORMATION</th>
                      <th colspan="7">VI. GOVERNMENT ASSISTANCE</th>
                  </tr>
                  <tr>
                      <th>Business/Trade Name</th><th>Type of Ownership</th><th>Nature of Business</th><th>Primary Products Offered</th><th>Product Price</th><th>Year Business Started</th><th>Business Permit No.</th><th>Valid Until</th><th>DTI Registration No.</th><th>Tax Identification No. (TIN)</th><th>Landline/Mobile Number</th><th>Email</th><th>Website/Social Media</th>
                      <th>Full Name</th><th>Contact Number</th><th>Sex</th><th>Date of Birth</th><th>Age</th><th>Civil Status</th><th>Complete Address</th><th>Educational Attainment</th><th>Work Experience</th>
                      <th>Business Assets Owned</th><th>Utility Needs</th>
                      <th>Number of Workers</th><th>Male Employees</th><th>Female Employees</th><th>Employment Type</th><th>Skills Needed</th>
                      <th>Estimated Daily Sales</th><th>Estimated Monthly Sales</th><th>Estimated Capital</th><th>Source of Capital</th><th>Average Monthly Expenses</th><th>Banking Access</th><th>Existing Loans/Credit</th>
                      <th>DTI Assistance</th><th>DOLE Assistance</th><th>LGU Assistance</th><th>TESDA Training</th><th>Financial Assistance</th><th>Livelihood Assistance</th><th>Business Training</th>
                  </tr>`;
              } else if (currentProgramName.toUpperCase().includes('SPES')) {
                  thead.innerHTML = `
                  <tr>
                      <th>No.</th>
                      <th>Last Name</th>
                      <th>First Name</th>
                      <th>Middle Name</th>
                      <th>Sex</th>
                      <th>Date of Birth</th>
                      <th>Age</th>
                      <th>Complete Address</th>
                      <th>Barangay</th>
                      <th>Contact Number</th>
                      <th>School</th>
                      <th>Course/Strand</th>
                      <th>Level</th>
                      <th>Parent/Guardian Name</th>
                      <th>Parent Occupation</th>
                      <th>Est. Monthly Income</th>
                      <th>Date of Application</th>
                      <th>Status</th>
                      <th>Work Assignment</th>
                      <th>Start Date</th>
                      <th>End Date</th>
                      <th>Remarks</th>
                  </tr>`;
              } else if (currentProgramName.toUpperCase().includes('TUPAD')) {
                  thead.innerHTML = `
                  <tr>
                      <th>No.</th>
                      <th>Last Name</th>
                      <th>First Name</th>
                      <th>Middle Name</th>
                      <th>Extension Name</th>
                      <th>Sex</th>
                      <th>Date of Birth</th>
                      <th>Age</th>
                      <th>Civil Status</th>
                      <th>Contact Number</th>
                      <th>Complete Address</th>
                      <th>Barangay</th>
                      <th>Educational Attainment</th>
                      <th>Occupation</th>
                      <th>Skills</th>
                      <th>Family Income</th>
                      <th>Dependents</th>
                      <th>ID Type</th>
                      <th>ID Number</th>
                      <th>Nature of Project</th>
                      <th>Work Assignment</th>
                      <th>Start Date</th>
                      <th>End Date</th>
                      <th>Days of Work</th>
                      <th>Salary/Wage</th>
                      <th>Status</th>
                      <th>Remarks</th>
                  </tr>`;
              } else {
                  thead.innerHTML = `<tr><th>No.</th><th>Names</th><th>Barangay</th></tr>`;
              }
              
              let filtered = allBeneficiariesData.filter(b => {
                  let matchBrgy = selectedBrgy === 'All' || b.barangay === selectedBrgy;
                  let matchAvail = selectedAvail === 'All' || (b.approval_status === 'Rejected' ? 'Rejected' : b.availment_status) === selectedAvail;
                  let matchBatch = selectedBatchId === '0' || b.program_id == selectedBatchId;
                  let matchNature = selectedNature === 'All' || uniqueReportListItems(b.business_nature)
                      .some(nature => nature.toLowerCase() === selectedNature.toLowerCase());
                  let matchSpesGroup = selectedSpesGroup === 'all' || b.spes_lifecycle === selectedSpesGroup;
                  return matchBrgy && matchAvail && matchBatch && matchNature && matchSpesGroup;
              });

              filtered.sort((a, b) => {
                  if (selectedBrgy === 'All') {
                      let brgyCmp = (a.barangay || '').localeCompare(b.barangay || '');
                      if (brgyCmp !== 0) return brgyCmp;
                  }
                  let nameA = ((a.last_name||'') + ' ' + (a.first_name||'')).trim().toLowerCase();
                  let nameB = ((b.last_name||'') + ' ' + (b.first_name||'')).trim().toLowerCase();
                  return nameA.localeCompare(nameB);
              });
              
              previewData = filtered;
              currentPreviewPage = 1;
              renderPreviewPage();
          }
      }
      
      window.changePreviewPage = function(pageNumber) {
          const totalPages = Math.ceil(previewData.length / previewItemsPerPage);
          if (pageNumber >= 1 && pageNumber <= totalPages) {
              currentPreviewPage = pageNumber;
              renderPreviewPage();
          }
      };
      
      function renderPreviewPage() {
          const tbody = document.getElementById('preview_table_body');
          if (!tbody) return;
          tbody.innerHTML = '';
          
          const pag = document.getElementById('preview_pagination');

          if(previewData.length === 0) {
              let colspan = reportColumnDefinitions.length + 1;
              if (!reportColumnDefinitions.length && currentProgramName.toUpperCase().includes('MSME')) colspan = 47;
              if (!reportColumnDefinitions.length && currentProgramName.toUpperCase().includes('SPES')) colspan = 22;
              tbody.innerHTML = `<tr><td colspan="${colspan}" style="text-align: center; padding: 24px; color: #667085; font-style: italic;">No records match these filters</td></tr>`;
              
              pag.innerHTML = '';
              pag.style.display = 'none';
              applyReportColumnVisibility();
              return;
          }
          
          let startIndex = (currentPreviewPage - 1) * previewItemsPerPage;
          let endIndex = startIndex + previewItemsPerPage;
          let pageData = previewData.slice(startIndex, endIndex);
          
          pageData.forEach((b, i) => {
              let globalIndex = startIndex + i + 1;
               if (reportColumnDefinitions.length) {
                  tbody.innerHTML += `<tr><td style="text-align:center;">${globalIndex}</td>${reportColumnDefinitions.map(column => {
                      const value = escapeReportValue(reportCellValue(b, column.key));
                      const title = value.replace(/\n/g, ' | ').replace(/"/g, '&quot;');
                      const cellClass = reportMultiValueKeys.includes(column.key) || value.includes('\n') ? 'report-multivalue' : '';
                      return `<td class="${cellClass}" title="${title}">${value}</td>`;
                  }).join('')}</tr>`;
              } else if (currentProgramName.toUpperCase().includes('MSME')) {
                  let ownerName = `${b.first_name || ''} ${b.middle_name || ''} ${b.last_name || ''}`.trim();
                  let address = `${b.street_purok_zone || ''} ${b.barangay || ''} Vinzons, CN`.trim();
                  
                  let hrMale = parseInt(b.hr_male) || 0;
                  let hrFem = parseInt(b.hr_female) || 0;
                  let totalHR = b.hr_total ? b.hr_total : (hrMale + hrFem);
                  let empType = b.employment_type ? b.employment_type : [b.emp_regular ? 'Regular' : '', b.emp_seasonal ? 'Seasonal' : '', b.emp_contractual ? 'Contractual' : '', b.emp_family ? 'Family' : ''].filter(Boolean).join(', ');
                  
                  let prodCombined = b.primary_products || '';
                  if (b.product_price && prodCombined !== '') prodCombined += ' (' + b.product_price + ')';

                  let hasDTI = b.dti_assistance ? b.dti_assistance : ((b.assistance_availed || '').includes('DTI') ? 'Yes' : '');
                  let hasDOLE = b.dole_assistance ? b.dole_assistance : ((b.assistance_availed || '').includes('DOLE') ? 'Yes' : '');
                  let hasLGU = b.lgu_assistance ? b.lgu_assistance : ((b.assistance_availed || '').includes('LGU') ? 'Yes' : '');
                  let hasTESDA = b.tesda_training ? b.tesda_training : ((b.assistance_availed || '').includes('TESDA') ? 'Yes' : '');
                  let hasFin = b.financial_assistance ? b.financial_assistance : ((b.programs_needed || '').includes('Financing') ? 'Needed' : '');
                  let hasLiv = b.livelihood_assistance ? b.livelihood_assistance : ((b.assistance_availed || '').includes('Livelihood') ? 'Yes' : '');
                  let hasTrain = b.business_training ? b.business_training : ((b.past_programs || '').includes('Training') ? 'Yes' : '');
                  
                  tbody.innerHTML += `
                  <tr>
                      <td style="text-align: center;">${globalIndex}</td>
                      <td>${b.business_name || ''}</td>
                      <td>${b.ownership_type || ''}</td>
                      <td>${b.business_nature || ''}</td>
                      <td>${prodCombined}</td>
                      <td>${b.product_price || ''}</td>
                      <td>${b.year_started || ''}</td>
                      <td>${b.business_permit_no || ''}</td>
                      <td>${b.permit_validity || ''}</td>
                      <td>${b.dti_no || ''}</td>
                      <td>${b.tin_no || ''}</td>
                      <td>${b.contact_no || ''}</td>
                      <td>${b.business_email || ''}</td>
                      <td>${b.business_social_media || ''}</td>
                      <td style="font-weight:600; color:#101828;">${ownerName}</td>
                      <td>${b.contact_no || ''}</td>
                      <td>${b.sex || ''}</td>
                      <td>${b.birthdate || ''}</td>
                      <td>${b.age || ''}</td>
                      <td>${b.civil_status || ''}</td>
                      <td>${address}</td>
                      <td>${b.educational_attainment || ''}</td>
                      <td>${b.work_experience || ''}</td>
                      <td>${b.assets_owned || ''}</td>
                      <td>${b.utility_needs || ''}</td>
                      <td>${totalHR}</td>
                      <td>${b.hr_male || ''}</td>
                      <td>${b.hr_female || ''}</td>
                      <td>${empType}</td>
                      <td>${b.skills_training_needed || ''}</td>
                      <td>${b.daily_earnings || ''}</td>
                      <td>${b.monthly_earnings || ''}</td>
                      <td>${b.current_capital || b.initial_capital || ''}</td>
                      <td>${b.source_of_capital || ''}</td>
                      <td>${b.monthly_expenses || ''}</td>
                      <td>${b.banking_access || ''}</td>
                      <td>${b.existing_loans || ''}</td>
                      <td>${hasDTI}</td>
                      <td>${hasDOLE}</td>
                      <td>${hasLGU}</td>
                      <td>${hasTESDA}</td>
                      <td>${hasFin}</td>
                      <td>${hasLiv}</td>
                      <td>${hasTrain}</td>
                  </tr>`;
              } else if (currentProgramName.toUpperCase().includes('SPES')) {
                  let dob = b.birthdate ? b.birthdate.replace(/-/g, '/') : '';
                  let address = `${b.street_purok_zone || ''} ${b.barangay || ''} Vinzons, CN`.trim();
                  let school = b.tert_school || b.sec_school || b.elem_school || '';
                  let course = b.tert_course || b.sec_degree || '';
                  let level = b.tert_year_level || b.sec_year_level || b.elem_year_level || '';
                  let parentName = b.father_name || b.mother_name || '';
                  let parentOcc = b.father_occupation || b.mother_occupation || '';
                  let dateApp = b.created_at ? new Date(b.created_at).toLocaleDateString() : '';

                  tbody.innerHTML += `
                  <tr>
                      <td style="text-align: center;">${globalIndex}</td>
                      <td style="font-weight:600; color:#101828;">${b.last_name || ''}</td>
                      <td style="font-weight:600; color:#101828;">${b.first_name || ''}</td>
                      <td>${b.middle_name || ''}</td>
                      <td>${b.sex || ''}</td>
                      <td>${dob}</td>
                      <td>${b.age || ''}</td>
                      <td>${address}</td>
                      <td>${b.barangay || ''}</td>
                      <td>${b.contact_no || ''}</td>
                      <td>${school}</td>
                      <td>${course}</td>
                      <td>${level}</td>
                      <td>${parentName}</td>
                      <td>${parentOcc}</td>
                      <td>${b.avg_monthly_income || ''}</td>
                      <td>${dateApp}</td>
                      <td style="font-weight:bold; color:#1f7a54;">${b.approval_status === 'Approved' ? 'Accepted' : (b.approval_status || '')}</td>
                      <td></td>
                      <td>${b.date_availed || ''}</td>
                      <td>${b.date_completed || ''}</td>
                      <td></td>
                  </tr>`;
              } else if (currentProgramName.toUpperCase().includes('TUPAD')) {
                  let dob = b.birthdate ? b.birthdate.replace(/-/g, '/') : '';
                  let address = `${b.street_purok_zone || ''} ${b.barangay || ''} Vinzons, CN`.trim();
                  
                  tbody.innerHTML += `
                  <tr>
                      <td style="text-align: center;">${globalIndex}</td>
                      <td style="font-weight:600; color:#101828;">${b.last_name || ''}</td>
                      <td style="font-weight:600; color:#101828;">${b.first_name || ''}</td>
                      <td>${b.middle_name || ''}</td>
                      <td>${b.ext_name || ''}</td>
                      <td>${b.sex || ''}</td>
                      <td>${dob}</td>
                      <td>${b.age || ''}</td>
                      <td>${b.civil_status || ''}</td>
                      <td>${b.contact_no || ''}</td>
                      <td>${address}</td>
                      <td>${b.barangay || ''}</td>
                      <td>${b.educational_attainment || ''}</td>
                      <td>${b.occupation || ''}</td>
                      <td>${b.skills_training_needed || ''}</td>
                      <td>${b.avg_monthly_income || ''}</td>
                      <td>${b.dependent_name || ''}</td>
                      <td>${b.type_of_id || ''}</td>
                      <td>${b.id_number || ''}</td>
                      <td></td>
                      <td></td>
                      <td>${b.date_availed || ''}</td>
                      <td>${b.date_completed || ''}</td>
                      <td></td>
                      <td></td>
                      <td>${b.availment_status === 'Not Yet Availed' ? 'Waiting for Requirements' : (b.availment_status || '')}</td>
                      <td></td>
                  </tr>`;
              } else {
                  let dispName = b.full_name || '';
                  if (!dispName || dispName.toLowerCase().includes("null null")) dispName = `${b.first_name || ''} ${b.middle_name || ''} ${b.last_name || ''} ${b.ext_name || ''}`.trim().replace(/\s+/g, ' ');
                  tbody.innerHTML += `<tr><td style="text-align: center;">${globalIndex}</td><td style="font-weight: bold; text-transform: uppercase; color:#101828;">${dispName}</td><td style="text-transform: uppercase;">${b.barangay || '—'}</td></tr>`;
              }
          });
          
          const totalPages = Math.ceil(previewData.length / previewItemsPerPage);
          
          pag.style.display = 'flex';
          pag.style.justifyContent = 'space-between';
          pag.style.alignItems = 'center';
          pag.style.width = '100%';
          pag.style.padding = '16px';
          pag.style.borderTop = '1px solid #eaecf0';

          if(previewData.length === 0) {
              pag.innerHTML = '';
              pag.style.display = 'none';
          } else {
              pag.innerHTML = `
                  <div class="report-page-summary" style="font-size: 13px; color: #667085;">
                      Showing <strong>${startIndex + 1}</strong> to <strong>${Math.min(endIndex, previewData.length)}</strong> of <strong>${previewData.length}</strong> entries
                  </div>
                  <div class="report-page-actions" style="display: flex; align-items: center; gap: 8px;">
                      <button type="button" class="report-page-button" style="padding: 6px 12px; border: 1px solid #d0d5dd; background: #fff; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; color: #344054; display: flex; align-items: center; gap: 4px;" onclick="changePreviewPage(${currentPreviewPage - 1})" ${currentPreviewPage === 1 ? 'disabled' : ''}>
                          <i class="ph-bold ph-caret-left"></i> Prev
                      </button>
                      <span class="report-page-indicator" style="font-size: 13px; font-weight: 600; color: #344054; margin: 0 4px;">Page ${currentPreviewPage} of ${totalPages}</span>
                      <button type="button" class="report-page-button" style="padding: 6px 12px; border: 1px solid #d0d5dd; background: #fff; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; color: #344054; display: flex; align-items: center; gap: 4px;" onclick="changePreviewPage(${currentPreviewPage + 1})" ${currentPreviewPage === totalPages ? 'disabled' : ''}>
                          Next <i class="ph-bold ph-caret-right"></i>
                      </button>
                  </div>
              `;
          }
          applyReportColumnVisibility();
      }

      function printFilteredReport() {
          updateReportPreview();
          if (previewData.length === 0) {
              showBeneficiaryNotice('No Records Found', 'No records match the selected report filters.');
              return;
          }

          const tbody = document.getElementById('preview_table_body');
          const thead = document.getElementById('preview_table_head');
          const originalPage = currentPreviewPage;
          const totalPages = Math.ceil(previewData.length / previewItemsPerPage);
          const printRows = [];

          showAllReportColumns = true;
          for (let page = 1; page <= totalPages; page++) {
              currentPreviewPage = page;
              renderPreviewPage();
              tbody.querySelectorAll('tr').forEach(row => printRows.push(row.cloneNode(true)));
          }
          const printHead = thead.cloneNode(true);
          showAllReportColumns = false;
          currentPreviewPage = originalPage;
          renderPreviewPage();

          const printWindow = window.open('', '_blank', 'width=1400,height=900');
          if (!printWindow) {
              showBeneficiaryNotice('Print Window Blocked', 'Please allow pop-ups for BENEPESO, then try printing the report again.');
              return;
          }

          const printColumnCount = Array.from(printHead.rows[0]?.cells || []).filter(cell => cell.style.display !== 'none').length;
          const printFontSize = printColumnCount > 30 ? '4.5pt' : (printColumnCount > 20 ? '5.5pt' : '7pt');
          const officialHeaderUrl = new URL('assets/peso_official_report_header.png?v=20260820-compact', window.location.href).href;
          printWindow.document.write(`<!DOCTYPE html><html><head><title>Beneficiary Report</title><style>
              @page { size: A3 landscape; margin: 8mm; }
              @page { size: A3 landscape; margin: 8mm; }
              * { box-sizing: border-box; }
              body { margin: 0; color: #142b20; font-family: Arial, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
              .official-header-wrap { width: 100%; margin: 0 0 4px; text-align: center; }
              .official-header { display: block; width: 100%; height: auto; margin: 0; }
              .print-brand { margin-bottom: 6px; text-align: center; color: #142b20; }
              h1 { margin: 0 0 2px; font-size: 15pt; line-height: 1.15; }
              p { margin: 0; font-size: 9pt; color: #466557; }
              table { width: 100%; table-layout: auto; border-collapse: collapse; }
              thead { display: table-header-group; }
              th, td { border: 1px solid #9eb8aa; padding: 3px 2px; font-size: ${printFontSize}; line-height: 1.2; overflow-wrap: anywhere; vertical-align: middle; text-align: center; }
              th { background: #21845b; color: #fff; font-weight: 700; text-align: center; }
              tbody tr:nth-child(even) td { background: #f1f7f4; }
          </style></head><body><div class="official-header-wrap"><img id="print_official_header" class="official-header" src="${officialHeaderUrl}" alt="PESO Vinzons official letterhead"></div><div class="print-brand"><h1 id="print_report_title"></h1><p id="print_report_subtitle"></p></div><table><thead id="print_report_head"></thead><tbody id="print_report_body"></tbody></table></body></html>`);
          printWindow.document.close();
          printWindow.document.getElementById('print_report_title').textContent = currentReportTitle;
          printWindow.document.getElementById('print_report_subtitle').textContent = document.getElementById('preview_subtitle_top')?.textContent || '';
          printWindow.document.getElementById('print_report_head').replaceWith(printWindow.document.importNode(printHead, true));
          const printBody = printWindow.document.getElementById('print_report_body');
          printRows.forEach(row => printBody.appendChild(printWindow.document.importNode(row, true)));
          const printHeader = printWindow.document.getElementById('print_official_header');
          const startPrint = () => window.setTimeout(() => { printWindow.focus(); printWindow.print(); }, 150);
          if (printHeader.complete) startPrint(); else { printHeader.onload = startPrint; printHeader.onerror = startPrint; }
      }


})();
</script>
