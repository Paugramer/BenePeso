<?php
/** Date-only bounds shared by report lists, previews and downloads. */
function report_date_filters(array $input): array {
    $filters=[];
    foreach (['date_from','date_to'] as $key) {
        $value=$input[$key] ?? '';
        if (!is_string($value)) throw new InvalidArgumentException('Enter a valid date range.');
        if (strpos($value,"\0")!==false) throw new InvalidArgumentException('Enter a valid date range.');
        $value=trim($value);
        if ($value!=='') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)) throw new InvalidArgumentException('Enter valid dates in YYYY-MM-DD format.');
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            if (!$date || $date->format('Y-m-d')!==$value || $value<'1000-01-01') {
                throw new InvalidArgumentException('Enter valid dates in YYYY-MM-DD format.');
            }
        }
        $filters[$key]=$value;
    }
    if ($filters['date_from']!=='' && $filters['date_to']!=='' && $filters['date_from']>$filters['date_to']) {
        throw new InvalidArgumentException('From date must be on or before To date.');
    }
    return $filters;
}

function report_date_request(array $input): array {
    try { return report_date_filters($input); }
    catch (InvalidArgumentException $error) { http_response_code(400); exit($error->getMessage()); }
}

function report_date_matches(string $date, array $filters): bool {
    if (empty($filters['date_from']) && empty($filters['date_to'])) return true;
    $date=substr($date,0,10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || $date<'1000-01-01') return false;
    return (empty($filters['date_from']) || $date >= $filters['date_from'])
        && (empty($filters['date_to']) || $date <= $filters['date_to']);
}

function report_date_sql(array $filters, array &$where, array &$params, string &$types): void {
    foreach (['date_from'=>'>=','date_to'=>'<='] as $key=>$operator) {
        if (empty($filters[$key])) continue;
        $where[]='DATE(b.created_at) '.$operator.' ?';
        $params[]=$filters[$key]; $types.='s';
    }
}

function report_date_label(array $filters, string $basis='Application date'): string {
    if (empty($filters['date_from']) && empty($filters['date_to'])) return '';
    return $basis.': '.($filters['date_from'] ?: 'Any date').' to '.($filters['date_to'] ?: 'Any date');
}

function render_report_date_fields(array $filters, string $prefix, string $basis='Application date', bool $apply=false): void {
    $escape=static fn($value)=>htmlspecialchars($value,ENT_QUOTES,'UTF-8');
    ?>
    <div class="report-date-fields" data-date-range style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;width:100%">
      <?php foreach (['date_from'=>'From','date_to'=>'To'] as $key=>$label): ?>
      <div class="form-group" style="margin:0;flex:1;min-width:145px"><label for="<?=$escape($prefix.'_'.$key)?>"><?=$escape($basis.' · '.$label)?></label><input style="width:100%;min-height:42px;padding:8px;border:1px solid #ccd8d1;border-radius:8px;font:inherit" type="date" id="<?=$escape($prefix.'_'.$key)?>" name="<?=$key?>" value="<?=$escape($filters[$key] ?? '')?>" min="1000-01-01" max="9999-12-31"></div>
      <?php endforeach; ?>
      <?php if($apply):?><button class="btn-main" type="submit">Apply dates</button><?php endif?>
      <button type="button" class="btn-edit" data-clear-report-dates>Clear dates</button>
    </div>
    <script src="report_date_filters.js?v=1" defer></script>
    <?php
}
