<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../db.php';
require __DIR__.'/../beneficiary_import_helper.php';
// Isolate the returning-SPES decision; its real lookup uses historical records.
function spes_beneficiary_is_returning(mysqli $conn, int $id): bool { return $id === 900008; }

$conn->query('CREATE TEMPORARY TABLE programs (program_id INT PRIMARY KEY,program_name VARCHAR(100)) ENGINE=InnoDB');
$conn->query("INSERT INTO programs VALUES(700001,'MSME Profiling'),(700002,'TUPAD'),(700003,'SPES')");
$conn->query('CREATE TEMPORARY TABLE beneficiaries (beneficiary_id INT PRIMARY KEY,program_id INT,full_name VARCHAR(100),birthdate DATE,email VARCHAR(100),contact_no VARCHAR(20),approval_status VARCHAR(20),availment_status VARCHAR(30),updated_at DATETIME) ENGINE=InnoDB');
$seed = [
    [900001,700001,'Synthetic Active','1990-01-01','active@example.invalid','Approved','Ongoing'],
    [900002,700001,'Synthetic Waiting','1991-01-01','waiting@example.invalid','Approved','Not Yet Availed'],
    [900003,700002,'Synthetic TUPAD','1992-01-01','tupad@example.invalid','Approved','Ongoing'],
    [900004,700003,'Synthetic First SPES','2006-01-01','first@example.invalid','Approved','Requirements Received'],
    [900005,700003,'Synthetic Passed SPES','2005-01-01','passed@example.invalid','Approved','Exam Passed'],
    [900006,700001,'Synthetic Closed','1993-01-01','closed@example.invalid','Approved','Completed'],
    [900007,700001,'Synthetic Pending','1994-01-01','pending@example.invalid','Pending','Not Yet Availed'],
    [900008,700003,'Synthetic Returning SPES','2004-01-01','returning@example.invalid','Approved','Requirements Received'],
    [900009,700001,'Synthetic Shared Name','1980-01-01','shared1@example.invalid','Approved','Not Yet Availed'],
    [900010,700001,'Synthetic Shared Name','2000-01-01','shared2@example.invalid','Approved','Not Yet Availed'],
    [900011,700001,'Synthetic Ambiguous','1990-01-01','ambiguous1@example.invalid','Approved','Not Yet Availed'],
    [900012,700001,'Synthetic Ambiguous','1990-01-01','ambiguous2@example.invalid','Approved','Not Yet Availed'],
];
$stmt=$conn->prepare("INSERT INTO beneficiaries VALUES (?,?,?,?,?,'',?,?,NOW())");
foreach($seed as $record){$stmt->bind_param('iisssss',...$record);$stmt->execute();}$stmt->close();
function check_import_safety(bool $condition, string $message): void { if(!$condition)throw new LogicException($message); }
function reject_import_safety(callable $operation): void {
    try { $operation(); } catch (BeneficiaryImportValidationException $expected) { return; }
    throw new LogicException('Unsafe import was accepted.');
}
function incoming_import_row(array $record): array { return ['full_name'=>$record[2],'birthdate'=>$record[3],'email'=>$record[4]]; }

$active=incoming_import_row($seed[0]);
check_import_safety(!beneficiaryImportIdentityMatches(['full_name'=>'Synthetic Missing','id_number'=>'None'], ['full_name'=>'Synthetic Missing','id_number'=>'None']), 'Missing ID treated as identity proof');
$different=$active;$different['birthdate']='2000-06-01';$different['email']='other@example.invalid';
reject_import_safety(fn()=>resolveBeneficiaryImportMatch($conn,700001,$different));
reject_import_safety(fn()=>updateImportedBeneficiary($conn,900001,$different,'Ongoing'));
reject_import_safety(fn()=>resolveBeneficiaryImportMatch($conn,700001,['full_name'=>$active['full_name'],'email'=>'unknown@example.invalid']));
check_import_safety((int)resolveBeneficiaryImportMatch($conn,700001,incoming_import_row($seed[9]))['beneficiary_id']===900010,'Wrong same-name person selected');
reject_import_safety(fn()=>resolveBeneficiaryImportMatch($conn,700001,incoming_import_row($seed[10])));
check_import_safety(resolveBeneficiaryImportMatch($conn,700002,$active)===null,'Cross-batch record incorrectly matched');
check_import_safety((int)resolveBeneficiaryImportMatch($conn,700001,['full_name'=>$active['full_name'],'email'=>$active['email']])['beneficiary_id']===900001,'Shared email fallback failed');

reject_import_safety(fn()=>validateBeneficiaryImportDuplicates($conn,700001,[$active],'Not Yet Availed'));
reject_import_safety(fn()=>updateImportedBeneficiary($conn,900001,$active,'Not Yet Availed'));
reject_import_safety(fn()=>updateImportedBeneficiary($conn,900002,incoming_import_row($seed[1]),'Ongoing'));
reject_import_safety(fn()=>updateImportedBeneficiary($conn,900003,incoming_import_row($seed[2]),'Completed'));
reject_import_safety(fn()=>updateImportedBeneficiary($conn,900004,incoming_import_row($seed[3]),'Orientation'));
reject_import_safety(fn()=>updateImportedBeneficiary($conn,900007,incoming_import_row($seed[6]),'Requirements Received'));
$closed=incoming_import_row($seed[5]);$closed['email']='changed@example.invalid';
reject_import_safety(fn()=>updateImportedBeneficiary($conn,900006,$closed,'Completed'));

foreach ([900001,900002,900003,900004,900007] as $id) {
    foreach (['Not Qualified','Cancelled'] as $target) {
        reject_import_safety(fn()=>updateImportedBeneficiary($conn,$id,incoming_import_row($seed[$id-900001]),$target));
    }
}

$changed=$active;$changed['email']='new-contact@example.invalid';
check_import_safety(updateImportedBeneficiary($conn,900001,$changed,'Ongoing'),'Legitimate detail update failed');
check_import_safety(updateImportedBeneficiary($conn,900003,incoming_import_row($seed[2]),'Salary Distribution'),'TUPAD legal next step failed');
check_import_safety(updateImportedBeneficiary($conn,900005,incoming_import_row($seed[4]),'Orientation'),'SPES passed next step failed');
check_import_safety(updateImportedBeneficiary($conn,900008,incoming_import_row($seed[7]),'Orientation'),'Returning SPES next step failed');

$successLogged=false;
reject_import_safety(function()use($conn,$seed,$active,&$successLogged){
    runBeneficiaryImportTransaction($conn,[1,2],function($index)use($conn,$seed,$active){
        if($index===1)updateImportedBeneficiary($conn,900002,incoming_import_row($seed[1]),'Requirements Received');
        else updateImportedBeneficiary($conn,900001,$active,'Not Yet Availed');
    },function()use(&$successLogged){$successLogged=true;});
});
$state=$conn->query('SELECT availment_status FROM beneficiaries WHERE beneficiary_id=900002')->fetch_assoc();
check_import_safety($state['availment_status']==='Not Yet Availed'&&!$successLogged,'Partial save or false success log');
check_import_safety(updateImportedBeneficiary($conn,900002,incoming_import_row($seed[1]),'Requirements Received'),'Legal MSME next step failed');
echo "Identity conflicts/ambiguity blocked; legitimate matches and edits allowed; backward/skipped/final/pending transitions blocked; legal MSME/TUPAD/SPES flows and atomic rollback passed (temporary tables only).\n";
