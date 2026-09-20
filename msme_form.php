<?php
require_once __DIR__ . '/auth.php';
require_once 'db.php';

const MSME_FORM_PDF = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'forms' . DIRECTORY_SEPARATOR . 'MSME Profiling Form.pdf';

function msme_error(string $message, int $status = 400): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => $message]);
    exit;
}

$beneficiaryId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$beneficiaryId) msme_error('A valid MSME beneficiary record is required.');

$role = auth_first_active_role(['admin', 'peso_staff', 'user']) ?? '';
$userId = 0;
if ($role === 'user') $userId = (int)$_SESSION['user_id'];
if ($role === '') msme_error('Your session has expired. Please sign in again.', 401);

$sql = "SELECT b.*, p.program_name FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id WHERE b.beneficiary_id=?";
if ($role === 'user') $sql .= " AND (b.user_id=? OR (b.user_id IS NULL AND b.email=(SELECT email FROM users WHERE user_id=? LIMIT 1)))";
$sql .= ' LIMIT 1';
$stmt = $conn->prepare($sql);
if (!$stmt) msme_error('The MSME record could not be loaded.', 500);
if ($role === 'user') $stmt->bind_param('iii', $beneficiaryId, $userId, $userId);
else $stmt->bind_param('i', $beneficiaryId);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
if (!$record) msme_error('The record was not found or you do not have permission to view it.', 404);
if (stripos((string)$record['program_name'], 'MSME') === false) msme_error('The MSME form is available only for MSME beneficiaries.');

$action = strtolower((string)($_GET['action'] ?? 'data'));
if ($action === 'template') {
    if (!is_file(MSME_FORM_PDF) || !is_readable(MSME_FORM_PDF)) msme_error('The original MSME PDF is unavailable.', 500);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize(MSME_FORM_PDF));
    header('Content-Disposition: inline; filename="MSME Profiling Form.pdf"');
    header('Cache-Control: private, no-store, max-age=0');
    readfile(MSME_FORM_PDF);
    exit;
}

$fullName = trim((string)($record['full_name'] ?? ''));
if ($fullName === '') $fullName = trim(implode(' ', array_filter([$record['first_name'] ?? '', $record['middle_name'] ?? '', $record['last_name'] ?? '', $record['ext_name'] ?? ''])));
$address = trim((string)($record['address'] ?? ''));
if ($address === '') $address = implode(', ', array_filter(array_map('trim', [$record['street_purok_zone'] ?? '', $record['barangay'] ?? '', $record['municipality'] ?? '', $record['district'] ?? ''])));
$age = (int)($record['age'] ?? 0);
if (!$age && !empty($record['birthdate'])) {
    try { $age = (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($record['birthdate']))->y; } catch (Throwable $e) { $age = 0; }
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
echo json_encode(['id'=>(int)$beneficiaryId, 'full_name'=>$fullName, 'address'=>$address, 'age'=>$age, 'record'=>$record], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
