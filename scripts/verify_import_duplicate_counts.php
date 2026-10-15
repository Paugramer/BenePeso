<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../db.php';require __DIR__.'/../beneficiary_import_helper.php';
$conn->query("CREATE TEMPORARY TABLE beneficiaries (beneficiary_id INT PRIMARY KEY,program_id INT,full_name VARCHAR(100),email VARCHAR(100),approval_status VARCHAR(20),availment_status VARCHAR(30),updated_at DATETIME) ENGINE=InnoDB");
$rows=[];for($i=1;$i<=100;$i++)$rows[]=['full_name'=>'Sample Person '.$i,'email'=>'sample'.$i.'@example.invalid'];
for($i=1;$i<=7;$i++)$conn->query("INSERT INTO beneficiaries VALUES ($i,1,'Sample Person $i','sample$i@example.invalid','Approved','Not Yet Availed',NOW())");
try{validateBeneficiaryImportDuplicates($conn,1,$rows,'Not Yet Availed');throw new LogicException('Duplicates not detected');}catch(BeneficiaryImportValidationException $e){if(strpos($e->getMessage(),'7 duplicate records found.')!==0)throw $e;}
$rows[0]['email']='changed@example.invalid';try{validateBeneficiaryImportDuplicates($conn,1,$rows,'Not Yet Availed');throw new LogicException('Duplicates not detected');}catch(BeneficiaryImportValidationException $e){if(strpos($e->getMessage(),'6 duplicate records found.')!==0)throw $e;}
validateBeneficiaryImportDuplicates($conn,2,$rows,'Not Yet Availed');
if((int)$conn->query('SELECT COUNT(*) n FROM beneficiaries')->fetch_assoc()['n']!==7)throw new RuntimeException('Precheck wrote rows');
echo "Variable duplicate count: 7, then 6 after a legitimate change; different batches allowed; no rows written (temporary tables only).\n";
