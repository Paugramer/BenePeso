<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'tupad_document_helper.php';
ensure_tupad_document_schema($conn);

$role = auth_first_active_role(['admin', 'peso_staff']);
if ($role === null) { http_response_code(403); exit('Authorized PESO reviewers only.'); }
$reviewerId = (int)$_SESSION[auth_role_id_key($role)];
$beneficiaryId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: (int)($_POST['beneficiary_id'] ?? 0);

$stmt = $conn->prepare("SELECT b.beneficiary_id,b.full_name,b.approval_status,b.availment_status,t.is_pregnant,t.is_pwd,t.has_work_limitation,p.program_name FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id LEFT JOIN beneficiary_tupad_details t ON t.beneficiary_id=b.beneficiary_id WHERE b.beneficiary_id=? AND UPPER(p.program_name) LIKE '%TUPAD%' LIMIT 1");
$stmt->bind_param('i', $beneficiaryId);
$stmt->execute();
$beneficiary = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$beneficiary) { http_response_code(404); exit('TUPAD beneficiary record not found.'); }
if (($beneficiary['approval_status'] ?? '') !== 'Approved') { http_response_code(409); exit('Document verification becomes available only after the TUPAD application is approved.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_verify_csrf($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request token.'); }
    $documentId = (int)($_POST['document_id'] ?? 0);
    $status = trim((string)($_POST['verification_status'] ?? ''));
    $note = trim((string)($_POST['reviewer_note'] ?? ''));
    if (!in_array($status, ['Pending','Verified','Needs Resubmission'], true) || ($status === 'Needs Resubmission' && $note === '')) {
        $_SESSION['document_review_error'] = 'Choose a valid decision and provide a note when resubmission is needed.';
    } else {
        $update = $conn->prepare('UPDATE beneficiary_documents SET verification_status=?,reviewer_note=?,reviewed_by_role=?,reviewed_by_id=?,reviewed_at=NOW() WHERE document_id=? AND beneficiary_id=?');
        $update->bind_param('sssiii', $status, $note, $role, $reviewerId, $documentId, $beneficiaryId);
        $update->execute();
        $update->close();
        if ($status === 'Needs Resubmission') {
            $_SESSION['document_review_success'] = 'Resubmission requested. The beneficiary remains blocked from Ongoing until the corrected document is presented and verified.';
        } elseif (tupad_documents_are_verified($conn, $beneficiaryId)) {
            $_SESSION['document_review_success'] = 'Review saved. All required documents are verified; this beneficiary is now eligible to move to Ongoing.';
        } else {
            $_SESSION['document_review_success'] = 'Document review saved. Complete verification of every required document before moving the beneficiary to Ongoing.';
        }
    }
    header('Location: tupad_documents.php?id=' . $beneficiaryId); exit();
}

$docsStmt = $conn->prepare('SELECT * FROM beneficiary_documents WHERE beneficiary_id=? ORDER BY document_type');
$docsStmt->bind_param('i', $beneficiaryId);
$docsStmt->execute();
$documents = $docsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$docsStmt->close();
$allDocumentsVerified = tupad_documents_are_verified($conn, $beneficiaryId);
$hasResubmission = false;
foreach ($documents as $document) {
    if (($document['verification_status'] ?? '') === 'Needs Resubmission') {
        $hasResubmission = true;
        break;
    }
}
$back = ($role === 'admin' ? 'admin_beneficiaries.php' : 'peso_staff_beneficiaries.php') . '?program_name=' . rawurlencode($beneficiary['program_name']);
$token = auth_csrf_token();
function td_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TUPAD Physical Document Verification</title>
<style>body{margin:0;background:#f4f8f5;color:#173728;font:14px Arial,sans-serif}.wrap{max-width:980px;margin:35px auto;padding:0 20px}.head,.card{background:#fff;border:1px solid #dce9e1;border-radius:14px;padding:22px;margin-bottom:16px}.head a{color:#176b43;text-decoration:none;font-weight:700}.facts{display:flex;gap:12px;flex-wrap:wrap}.fact{background:#f4f8f5;padding:10px 13px;border-radius:8px}.workflow{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:18px}.stage{padding:12px;border:1px solid #dce9e1;border-radius:10px;background:#f8fbf9;color:#63756b}.stage strong{display:block;color:#173728;margin-bottom:4px}.stage.active{border-color:#e5b94f;background:#fff8e6}.stage.ready{border-color:#74bd94;background:#ebf8f0}.stage.problem{border-color:#ef9a9a;background:#fff0f0;color:#8f2525}.stage.problem strong{color:#8f2525}.doc{display:grid;grid-template-columns:1.2fr .8fr 2fr;gap:16px;align-items:start}.status{font-weight:700}.Pending{color:#9a6700}.Verified{color:#177245}.Needs-Resubmission{color:#b42318}label{display:block;font-weight:700;margin-bottom:6px}select,textarea{width:100%;box-sizing:border-box;padding:9px;border:1px solid #cbdacf;border-radius:7px}button{margin-top:8px;background:#176b43;color:#fff;border:0;border-radius:7px;padding:9px 14px;font-weight:700;cursor:pointer}.alert{padding:12px;border-radius:8px;margin:12px 0}.ok{background:#e7f7ed}.err{background:#fdecec}@media(max-width:700px){html,body{width:100%;max-width:100%;overflow-x:hidden}*,*::before,*::after{box-sizing:border-box}.wrap{width:calc(100% - 20px);margin:10px auto;padding:0}.head,.card{width:100%;max-width:100%;min-width:0;margin-bottom:10px;padding:15px;border-radius:12px}.head h1{font-size:23px;line-height:1.2;text-align:center;overflow-wrap:anywhere}.head h2{font-size:17px;text-align:center;overflow-wrap:anywhere}.head p,.card p{font-size:12px;line-height:1.55;overflow-wrap:anywhere}.head>a{display:inline-flex;min-height:42px;align-items:center}.facts{display:grid;grid-template-columns:1fr;gap:6px}.fact{min-width:0;padding:8px 10px;font-size:11px;overflow-wrap:anywhere}.doc,.workflow{grid-template-columns:minmax(0,1fr);gap:8px}.doc>*{min-width:0}.stage{padding:10px;font-size:11px;line-height:1.45}select,textarea{min-height:44px;font-size:16px}button{width:100%;min-height:44px;touch-action:manipulation}.alert{font-size:12px;overflow-wrap:anywhere}}</style></head><body><main class="wrap">
<section class="head"><a href="<?=td_h($back)?>">&larr; Back to beneficiaries</a><h1>TUPAD Physical Document Verification</h1><h2><?=td_h($beneficiary['full_name'])?></h2><div class="facts"><span class="fact">Pregnant: <?=td_h($beneficiary['is_pregnant'] ?: 'Not declared')?></span><span class="fact">PWD: <?=td_h($beneficiary['is_pwd'] ?: 'Not declared')?></span><span class="fact">Work limitation: <?=td_h($beneficiary['has_work_limitation'] ?: 'Not declared')?></span></div></section>
<section class="card"><strong>Document workflow</strong><p>“Documents Submitted” records receipt of the requirements. Physical verification confirms that the originals are valid. Incorrect or incomplete documents must be resubmitted before the beneficiary can move to “Ongoing.”</p><div class="workflow"><div class="stage active"><strong>1. Documents Submitted</strong>Requirements received by PESO</div><div class="stage <?=$hasResubmission?'problem':($allDocumentsVerified?'ready':'active')?>"><strong>2. Physical Verification</strong><?=$hasResubmission?'Correction and resubmission required':($allDocumentsVerified?'All required documents verified':'Inspection still required')?></div><div class="stage <?=$allDocumentsVerified?'ready':''?>"><strong>3. Ready for Ongoing</strong><?=$allDocumentsVerified?'Eligible to begin work':($hasResubmission?'Blocked until corrected documents are verified':'Available after verification')?></div></div></section>
<?php if(isset($_SESSION['document_review_success'])):?><div class="alert ok"><?=td_h($_SESSION['document_review_success']);unset($_SESSION['document_review_success'])?></div><?php endif?>
<?php if(isset($_SESSION['document_review_error'])):?><div class="alert err"><?=td_h($_SESSION['document_review_error']);unset($_SESSION['document_review_error'])?></div><?php endif?>
<?php if(!$documents):?><section class="card">No digital checklist exists for this legacy application. Continue using PESO's existing face-to-face document verification process.</section><?php endif?>
<?php foreach($documents as $doc): $class=str_replace(' ','-',$doc['verification_status']);?><section class="card doc"><div><h3><?=td_h($doc['document_type']==='government_id'?'National ID Photocopy and Original':'Fitness-to-work Certificate')?></h3><p><?=td_h($doc['document_type']==='government_id'?'Check the front-and-back photocopy against the original National ID presented at the office.':'Inspect the fitness-to-work certificate presented personally at PESO Vinzons.')?> No online copy is stored.</p></div><div><label>Physical verification</label><div class="status <?=$class?>"><?=td_h($doc['verification_status'])?></div><small><?=td_h($doc['reviewed_at'] ?: 'Not yet checked at the office')?></small></div><form method="post"><input type="hidden" name="csrf_token" value="<?=td_h($token)?>"><input type="hidden" name="beneficiary_id" value="<?=$beneficiaryId?>"><input type="hidden" name="document_id" value="<?=(int)$doc['document_id']?>"><label>Decision after inspection</label><select name="verification_status" required><?php foreach(['Pending','Verified','Needs Resubmission'] as $option):?><option value="<?=td_h($option)?>" <?=$doc['verification_status']===$option?'selected':''?>><?=td_h($option)?></option><?php endforeach?></select><label style="margin-top:8px">Reviewer note</label><textarea name="reviewer_note" rows="3" maxlength="500" placeholder="For resubmission, state exactly what is incorrect or missing and what the beneficiary must bring."><?=td_h($doc['reviewer_note'])?></textarea><button type="submit">Save Review</button></form></section><?php endforeach?>
</main></body></html>
