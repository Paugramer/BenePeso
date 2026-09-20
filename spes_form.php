<?php
require_once __DIR__ . '/auth.php';
require_once 'db.php';

const SPES_FORM_2_PDF = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'forms' . DIRECTORY_SEPARATOR . 'SPES Form 2 - Application Form.pdf';
const SPES_FORM_2A_PDF = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'forms' . DIRECTORY_SEPARATOR . 'SPES Form 2-A - Oath of Undertaking.pdf';

function spes_error(string $message, int $status = 400): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => $message]);
    exit;
}

$beneficiaryId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$beneficiaryId) spes_error('A valid SPES beneficiary record is required.');

$role = auth_first_active_role(['admin', 'peso_staff', 'user']) ?? '';
$userId = 0;
if ($role === 'user') $userId = (int)$_SESSION['user_id'];
if ($role === '') spes_error('Your session has expired. Please sign in again.', 401);

$sql = "SELECT b.*, p.program_name FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id WHERE b.beneficiary_id=?";
if ($role === 'user') $sql .= " AND (b.user_id=? OR (b.user_id IS NULL AND b.email=(SELECT email FROM users WHERE user_id=? LIMIT 1)))";
$sql .= ' LIMIT 1';
$stmt = $conn->prepare($sql);
if (!$stmt) spes_error('The SPES record could not be loaded.', 500);
if ($role === 'user') $stmt->bind_param('iii', $beneficiaryId, $userId, $userId);
else $stmt->bind_param('i', $beneficiaryId);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
if (!$record) spes_error('The record was not found or you do not have permission to view it.', 404);
if (strtoupper(trim((string)$record['program_name'])) !== 'SPES') spes_error('SPES forms are available only for SPES beneficiaries.');

$action = strtolower((string)($_GET['action'] ?? 'data'));
if ($action === 'template') {
    $form = (string)($_GET['form'] ?? '2');
    $path = $form === '2a' ? SPES_FORM_2A_PDF : SPES_FORM_2_PDF;
    if (!is_file($path) || !is_readable($path)) spes_error('The original SPES PDF is unavailable.', 500);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    header('Cache-Control: private, no-store, max-age=0');
    readfile($path);
    exit;
}

$fullName = trim((string)($record['full_name'] ?? ''));
if ($fullName === '') $fullName = trim(implode(' ', array_filter([$record['first_name'] ?? '', $record['middle_name'] ?? '', $record['last_name'] ?? '', $record['ext_name'] ?? ''])));
$address = trim((string)($record['address'] ?? ''));
if ($address === '') $address = implode(', ', array_filter(array_map('trim', [$record['street_purok_zone'] ?? '', $record['barangay'] ?? '', $record['municipality'] ?? '', $record['district'] ?? ''])));
if (empty($record['permanent_address'])) $record['permanent_address'] = $address;
$age = (int)($record['age'] ?? 0);
if (!$age && !empty($record['birthdate'])) {
    try { $age = (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($record['birthdate']))->y; } catch (Throwable $e) { $age = 0; }
}
$history = [];
$decoded = json_decode((string)($record['spes_history'] ?? ''), true);
if (is_array($decoded)) {
    foreach ($decoded as $entry) if (is_array($entry)) $history[] = ['availment'=>$entry[0] ?? '', 'establishment'=>$entry[1] ?? '', 'year'=>$entry[2] ?? '', 'id'=>$entry[3] ?? ''];
}
for ($i=1; $i<=4; $i++) {
    $stored = $history[$i-1] ?? ['availment'=>'', 'establishment'=>'', 'year'=>'', 'id'=>''];
    $columnEntry = [
        'availment'=>'',
        'establishment'=>trim((string)($record["spes_history_{$i}_establishment"] ?? '')),
        'year'=>trim((string)($record["spes_history_{$i}_year"] ?? '')),
        'id'=>trim((string)($record["spes_history_{$i}_id"] ?? '')),
    ];
    // Admin/PESO edits are stored in the dedicated columns; use the original
    // application JSON only as a fallback for records submitted by users.
    $history[$i-1] = [
        'availment'=>$stored['availment'] ?? '',
        'establishment'=>$columnEntry['establishment'] !== '' ? $columnEntry['establishment'] : ($stored['establishment'] ?? ''),
        'year'=>$columnEntry['year'] !== '' ? $columnEntry['year'] : ($stored['year'] ?? ''),
        'id'=>$columnEntry['id'] !== '' ? $columnEntry['id'] : ($stored['id'] ?? ''),
    ];
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
echo json_encode([
    'id'=>(int)$beneficiaryId, 'full_name'=>$fullName, 'age'=>$age, 'address'=>$address,
    'today'=>['day'=>date('j'),'month'=>date('F'),'year'=>date('Y')],
    'record'=>$record, 'history'=>array_slice($history,0,4)
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
