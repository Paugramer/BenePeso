<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../db.php';require __DIR__.'/../beneficiary_import_helper.php';
$sql=file_get_contents(__DIR__.'/../database_update_phase2_reporting.sql');$sql=str_replace('CREATE TABLE IF NOT EXISTS beneficiary_reporting_details','CREATE TEMPORARY TABLE beneficiary_reporting_details',$sql);$conn->query($sql);
$conn->query("ALTER TABLE beneficiary_reporting_details ADD nm_stall_no VARCHAR(100) DEFAULT '', ADD nm_date_started DATE NULL, ADD owner_contact VARCHAR(30) DEFAULT ''");
$row=['nm_stall_no'=>'NM-001','nm_date_started'=>'2025-01-01','nm_two_day_earnings'=>'2400','nm_total_earnings'=>'45000','owner_contact'=>'09600000001'];
try{runBeneficiaryImportTransaction($conn,[1,2],function($id)use($conn,$row){saveBeneficiaryImportReportDetails($conn,$id,$row,'admin',1);if($id===2)throw new RuntimeException('Synthetic failure');});throw new LogicException('Did not fail');}catch(RuntimeException $e){}
if((int)$conn->query('SELECT COUNT(*) n FROM beneficiary_reporting_details')->fetch_assoc()['n']!==0)throw new LogicException('Partial details saved');
runBeneficiaryImportTransaction($conn,[1],function($id)use($conn,$row){saveBeneficiaryImportReportDetails($conn,$id,$row,'admin',1);});$saved=$conn->query('SELECT * FROM beneficiary_reporting_details WHERE beneficiary_id=1')->fetch_assoc();foreach($row as$key=>$value)if($saved[$key]!==$value)throw new LogicException('Report field lost: '.$key);
echo "All supplemental MSME fields saved correctly; failure rolls back every supplemental row (temporary table only).\n";
