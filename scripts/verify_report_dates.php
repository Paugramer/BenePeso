<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../report_date_helper.php';
require_once __DIR__.'/../program_slot_helper.php';
function date_check(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
$range=report_date_filters(['date_from'=>'2026-01-10','date_to'=>'2026-01-10']);
date_check(report_date_matches('2026-01-10 00:00:00',$range),'Start of day excluded');
date_check(report_date_matches('2026-01-10 23:59:59',$range),'End of day excluded');
date_check(!report_date_matches('2026-01-11 00:00:00',$range),'Next day included');
date_check(!report_date_matches('Not recorded',$range),'Unknown date included in range');
date_check(report_date_matches('',report_date_filters([])),'Unfiltered report lost undated records');
date_check(report_date_matches('2026-03-01',report_date_filters(['date_from'=>'2026-01-10'])),'From-only filter failed');
date_check(report_date_matches('2026-01-01',report_date_filters(['date_to'=>'2026-01-10'])),'To-only filter failed');
foreach ([['date_from'=>'2026-02-29'],['date_to'=>'2026-13-01'],['date_from'=>[]],
    ['date_to'=>"2026-01-01\x00"],['date_from'=>'2026-02-01','date_to'=>'2026-01-01']] as $invalid) {
    try { report_date_filters($invalid); throw new RuntimeException('Invalid range accepted'); }
    catch (InvalidArgumentException $expected) {}
}
date_check(report_date_filters(['date_from'=>'2024-02-29'])['date_from']==='2024-02-29','Leap date rejected');
foreach ([[100,49,51,false],[100,10,90,true],[100,0,100,true],[10,1,9,true],[0,0,0,false],[100,-2,102,true]] as [$total,$left,$filled,$warning]) {
    $capacity=program_slot_summary($total,$left);
    date_check($capacity['filled']===$filled && $capacity['remaining']===max(0,$left) && $capacity['warning']===$warning,'Capacity calculation failed');
}
echo "Date validation, inclusive bounds, open ranges and occupied-slot thresholds passed\n";

// Existing fixtures use temporary tables only, including legacy and missing dates.
require __DIR__.'/verify_requirements_report.php';
foreach ([
    ['2026-01-02','2026-01-03',[2,3]], ['2026-01-06','2026-01-06',[6]],
    ['2026-01-10','2026-01-10',[1]], ['2026-02-01','2026-02-28',[]],
] as [$from,$to,$expected]) {
    $dateFilters=$filters+['date_from'=>$from,'date_to'=>$to];
    $ids=array_map('intval',array_column(requirements_report_rows($conn,$dateFilters),'beneficiary_id'));
    sort($ids); date_check($ids===$expected,'Submission date query returned wrong records');
}
// Compare SQL list/export filtering with preview filtering on the same timestamps.
$conn->query('ALTER TABLE beneficiaries ADD created_at DATETIME NULL');
$conn->query("UPDATE beneficiaries SET created_at=CASE beneficiary_id WHEN 1 THEN '2026-01-10 00:00:00' WHEN 2 THEN '2026-01-10 23:59:59' WHEN 3 THEN '2026-01-11 00:00:00' ELSE NULL END");
foreach ([$range,report_date_filters(['date_from'=>'2026-01-11']),report_date_filters(['date_to'=>'2026-01-10']),report_date_filters([])] as $bounds) {
    $where=['1=1'];$params=[];$types='';report_date_sql($bounds,$where,$params,$types);
    $stmt=$conn->prepare('SELECT beneficiary_id FROM beneficiaries b WHERE '.implode(' AND ',$where).' ORDER BY beneficiary_id');
    if($types!=='')$stmt->bind_param($types,...$params);
    $stmt->execute();$sqlIds=array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC),'beneficiary_id');$stmt->close();
    $records=$conn->query('SELECT beneficiary_id,created_at FROM beneficiaries ORDER BY beneficiary_id')->fetch_all(MYSQLI_ASSOC);
    $previewIds=array_column(array_filter($records,static fn($row)=>report_date_matches((string)$row['created_at'],$bounds)),'beneficiary_id');
    date_check(array_map('intval',$sqlIds)===array_map('intval',$previewIds),'List/export and preview date ranges differ');
}
echo "Submission/legacy dates and SQL list/export versus preview results passed; temporary tables only\n";
