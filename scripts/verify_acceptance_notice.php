<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../application_acceptance_helper.php';
require __DIR__ . '/../email_helper.php';

function acceptance_check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$today = document_submission_today();
acceptance_check(acceptance_notice_deadline($today) === $today, 'Today must be accepted in Manila time.');
foreach ([null, [], '', '2026-02-30', '2000-01-01', $today.'junk'] as $invalid) {
    try { acceptance_notice_deadline($invalid); throw new RuntimeException('Invalid deadline accepted.'); }
    catch (InvalidArgumentException $expected) {}
}

$conn->query('CREATE TEMPORARY TABLE beneficiaries (beneficiary_id INT PRIMARY KEY, approval_status VARCHAR(20)) ENGINE=InnoDB');
$conn->query("INSERT INTO beneficiaries VALUES (1,'Pending')");
$conn->query('CREATE TEMPORARY TABLE application_acceptance_notices (beneficiary_id INT PRIMARY KEY,submission_deadline DATE NOT NULL,requirements_json TEXT NOT NULL,accepted_by_admin INT NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$requirements = ['Application Form', 'Government-issued ID (front and back)', '<script>alert(1)</script>'];
$conn->query('CREATE TEMPORARY TABLE beneficiary_tupad_details (beneficiary_id INT,is_pwd VARCHAR(3),has_work_limitation VARCHAR(3))');
$conn->query("INSERT INTO beneficiary_tupad_details VALUES (1,'Yes','No')");
$conn->query('CREATE TEMPORARY TABLE application_requirement_reviews (beneficiary_id INT,requirement_key VARCHAR(64),review_status VARCHAR(30),reviewer_note TEXT)');
$conn->query('CREATE TEMPORARY TABLE beneficiary_documents (beneficiary_id INT,document_type VARCHAR(64),verification_status VARCHAR(30),reviewer_note TEXT)');
$tupad = acceptance_notice_requirements($conn,['beneficiary_id'=>1,'program_name'=>'TUPAD','requirements'=>"Requirements:\n- Valid ID\n- Application Form"]);
acceptance_check(in_array('Fitness-to-work certificate',$tupad,true) && in_array('Government-issued ID (front and back)',$tupad,true) && in_array('Application Form',$tupad,true), 'TUPAD checklist must include individualized fitness requirements.');
$spes = acceptance_notice_requirements($conn,['beneficiary_id'=>1,'program_name'=>'SPES','requirements'=>"Latest semester grades\nPlastic envelope"]);
acceptance_check(in_array('SPES Application Form',$spes,true) && in_array('Latest semester grades',$spes,true) && !in_array('Plastic envelope',$spes,true), 'SPES must use the existing checklist rules.');
$msme = acceptance_notice_requirements($conn,['beneficiary_id'=>1,'program_name'=>'MSME Profiling','requirements'=>"Business Permit\nBarangay Clearance"]);
acceptance_check($msme===['Business Permit','Barangay Clearance'], 'MSME requirements must match its configured checklist.');
$conn->begin_transaction();
$conn->query("UPDATE beneficiaries SET approval_status='Approved' WHERE beneficiary_id=1");
save_acceptance_notice($conn,1,$today,$requirements,123);
$conn->commit();
$saved = $conn->query('SELECT * FROM application_acceptance_notices WHERE beneficiary_id=1')->fetch_assoc();
acceptance_check(json_decode($saved['requirements_json'],true) === $requirements && $saved['submission_deadline'] === $today, 'Notice snapshot must be preserved.');
$conn->begin_transaction();
$conn->query("UPDATE beneficiaries SET approval_status='Rejected' WHERE beneficiary_id=1");
try { save_acceptance_notice($conn,1,$today,$requirements,123); throw new RuntimeException('Duplicate snapshot accepted.'); }
catch (mysqli_sql_exception $expected) { $conn->rollback(); }
acceptance_check($conn->query('SELECT approval_status FROM beneficiaries WHERE beneficiary_id=1')->fetch_assoc()['approval_status'] === 'Approved', 'Notice failure must rollback approval changes.');

foreach (['TUPAD','SPES','MSME Profiling'] as $program) {
    $body = acceptance_notice_body('Juan <Test>', $program, $today, $requirements, $program==='SPES');
    acceptance_check(strpos($body,'&lt;script&gt;')!==false && strpos($body,'<script>')===false, 'Requirement text must be escaped.');
    acceptance_check(strpos($body,'PESO Vinzons office')!==false && strpos($body,'Keep your registered email active')!==false && strpos($body,'on or before')!==false, 'Email needs office, deadline, and email reminder.');
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->setFrom('test@example.invalid','PESO Test'); $mail->addAddress('recipient@example.invalid');
    $mail->isHTML(true); $mail->CharSet='UTF-8'; $mail->Subject='Acceptance preview';
    benepeso_prepare_email_content($mail,'Application accepted',$body);
    acceptance_check($mail->preSend(), 'Offline MIME generation failed.');
    $mime = $mail->getSentMIMEMessage();
    acceptance_check(strpos($mail->Body,'cid:benepeso-office-logo')!==false && strpos($mime,'Content-ID: <benepeso-office-logo>')!==false && strpos($mime,'Content-Type: image/png')!==false, 'Email must include the actual embedded PNG.');
    acceptance_check(strpos($mail->AltBody,'Application Form')!==false && strpos($mail->AltBody,'PESO Vinzons office')!==false, 'Plain text must retain instructions.');
}
echo "Deadline validation, safe email content, all-program instructions, embedded logo MIME, saved snapshot and atomic rollback passed. No emails sent; temporary tables only.\n";
