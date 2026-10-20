<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../db.php';
require __DIR__.'/../requirements_report_helper.php';
function rr_check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$conn->query('CREATE TEMPORARY TABLE programs (program_id INT PRIMARY KEY,program_name VARCHAR(100),program_code VARCHAR(50),requirements TEXT) ENGINE=InnoDB');
$conn->query("INSERT INTO programs VALUES (1,'MSME Profiling','MSME-1','Business Permit'),(2,'MSME Profiling','MSME-2','Business Permit'),(3,'SPES','SPES-1','Grades')");
$conn->query('CREATE TEMPORARY TABLE beneficiaries (beneficiary_id INT PRIMARY KEY,program_id INT,full_name VARCHAR(100),barangay VARCHAR(100),approval_status VARCHAR(20),availment_status VARCHAR(40),date_availed DATE NULL) ENGINE=InnoDB');
$conn->query("INSERT INTO beneficiaries VALUES
 (1,1,'SYNTHETIC VERIFIED','Alpha','Approved','Requirements Received','2026-01-01'),
 (2,1,'SYNTHETIC FOLLOWUP','Alpha','Approved','Requirements Received','2026-01-02'),
 (3,1,'SYNTHETIC LATER','Beta','Approved','Orientation','2026-02-01'),
 (4,2,'SYNTHETIC COMPLETED','Beta','Approved','Completed','2026-03-01'),
 (5,1,'SYNTHETIC WAITING','Alpha','Pending','Not Yet Availed',NULL),
 (6,1,'SYNTHETIC LEGACY','Alpha','Approved','Requirements Received','2026-01-06'),
 (7,1,'SYNTHETIC NO EVIDENCE','Alpha','Approved','Ongoing','2026-01-07'),
 (8,2,'SYNTHETIC CLOSED','Alpha','Rejected','Cancelled','2026-01-08'),
 (9,3,'SYNTHETIC SPES','Alpha','Approved','Requirements Received',NULL)");
$conn->query('CREATE TEMPORARY TABLE application_document_submissions (beneficiary_id INT PRIMARY KEY,received_at DATETIME,requested_at DATETIME NULL,resubmission_deadline DATE NULL,resubmission_note TEXT) ENGINE=InnoDB');
$conn->query("INSERT INTO application_document_submissions VALUES
 (1,'2026-01-10 09:00:00',NULL,NULL,''),(2,'2026-01-02 09:00:00','2026-01-03 09:00:00','2026-01-20','Bring a clear copy.'),
 (3,'2026-01-03 09:00:00',NULL,NULL,''),(4,'2026-01-04 09:00:00',NULL,NULL,''),(8,'2026-01-08 09:00:00',NULL,NULL,'')");
$conn->query('CREATE TEMPORARY TABLE application_requirement_reviews (beneficiary_id INT,requirement_key VARCHAR(64),review_status VARCHAR(30),reviewer_note TEXT) ENGINE=InnoDB');
$key=hash('sha256','Business Permit');
$stmt=$conn->prepare('INSERT INTO application_requirement_reviews VALUES (?,?,?,?)');
foreach([[1,'Verified'],[2,'Invalid Document'],[3,'Verified'],[6,'Received']] as [$id,$status]){$note='Synthetic only';$stmt->bind_param('isss',$id,$key,$status,$note);$stmt->execute();}$stmt->close();
$filters=['program_name'=>'','program_id'=>0,'barangay'=>'All','review_status'=>'All','search'=>''];
$rows=requirements_report_rows($conn,$filters);$indexed=array_column($rows,null,'beneficiary_id');
rr_check(count($rows)===7,'Submission evidence scope is incorrect.');
rr_check(!isset($indexed[5])&&!isset($indexed[7]),'Unsubmitted records leaked into the list.');
rr_check(isset($indexed[3],$indexed[4],$indexed[8]),'Later-stage or closed submissions disappeared.');
rr_check($indexed[1]['submitted_on']==='2026-01-10','Latest recorded submission was not used.');
rr_check($indexed[3]['submitted_on']==='2026-01-03','Orientation date replaced the submission date.');
rr_check($indexed[6]['submitted_on']==='2026-01-06'&&$indexed[9]['submitted_on']==='Not recorded','Legacy or unknown date handling failed.');
rr_check($indexed[1]['review_status']==='Verified / Complete'&&$indexed[3]['review_status']==='Verified / Complete','Verified checklist classification failed.');
rr_check($indexed[2]['review_status']==='Needs Resubmission'&&$indexed[2]['followup_deadline']==='2026-01-20','Resubmission classification failed.');
rr_check($indexed[4]['review_status']==='Not Reviewed','Completion alone was treated as verified evidence.');
rr_check($indexed[6]['review_status']==='Under Review','Partial checklist classification failed.');
foreach([
 ['program_name','SPES',1],['program_id',2,2],['barangay','Beta',2],
 ['review_status','Verified / Complete',2],['review_status','Needs Resubmission',1],
 ['review_status','Under Review',1],['review_status','Not Reviewed',3],['search','MSME-2',2],['search','FOLLOWUP',1],
] as [$key,$value,$expected]){ $filtered=$filters;$filtered[$key]=$value;rr_check(count(requirements_report_rows($conn,$filtered))===$expected,'Filter failed: '.$key.'='.$value); }
$combined=$filters;$combined['program_id']=1;$combined['barangay']='Alpha';$combined['review_status']='Verified / Complete';
rr_check(array_column(requirements_report_rows($conn,$combined),'beneficiary_id')===[1],'Combined filters mismatch.');
rr_check(requirements_report_review_status([],['requested_at'=>'2026-01-01'])==='Needs Resubmission','Request without checklist was lost.');
rr_check(requirements_report_review_status([['status'=>'Verified']],['requested_at'=>'2026-01-01'])==='Needs Resubmission','Outstanding request incorrectly marked complete.');
rr_check(requirements_report_review_status([],[])==='Not Reviewed','Empty checklist marked complete.');
rr_check((int)$conn->query('SELECT COUNT(*) n FROM beneficiaries')->fetch_assoc()['n']===9,'Report mutated fixture records.');
echo "Submission evidence, latest/legacy dates, review states, nine filters and combined filters passed; temporary tables only.\n";
