<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
if(auth_first_active_role(['admin','peso_staff'])===null) { http_response_code(403);echo json_encode(['error'=>'Please sign in as Admin or PESO Staff.']);exit; }
$program=strtoupper((string)($_GET['program'] ?? ''));
if(!in_array($program,['TUPAD','SPES','MSME'],true)) { http_response_code(400);echo json_encode(['error'=>'Choose a valid program.']);exit; }
$like=$program.'%';
$stmt=$conn->prepare("SELECT program_name,program_code,description,eligibility,requirements,start_date,end_date,venue FROM programs WHERE UPPER(program_name) LIKE ? AND approval_status='Approved' ORDER BY created_at DESC LIMIT 50");
$stmt->bind_param('s',$like);$stmt->execute();$result=$stmt->get_result();$items=[];
while($record=$result->fetch_assoc()) {
    foreach($record as &$value) $value=trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?\s*>|<\/p>|<\/li>/i',"\n",(string)$value)),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    unset($value);$items[]=$record;
}
$stmt->close();echo json_encode(['program'=>$program,'items'=>$items],JSON_UNESCAPED_UNICODE);
