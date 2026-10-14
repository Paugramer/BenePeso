<?php
// Preflight only: reads the uploaded template and never saves beneficiary records.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/beneficiary_import_helper.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function import_preflight_response(bool $valid, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['valid' => $valid, 'message' => $valid ? '' : 'Invalid file or format. Must follow the template.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') import_preflight_response(false, 405);
if (!auth_first_active_role(['admin', 'peso_staff'])) import_preflight_response(false, 403);
if (!auth_verify_csrf($_POST['csrf_token'] ?? null)) import_preflight_response(false, 403);

try {
    $file = $_FILES['csv_file'] ?? null;
    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || !in_array($extension, ['xlsx', 'csv'], true)
        || !is_uploaded_file($file['tmp_name']) || $file['size'] > 10 * 1024 * 1024) {
        import_preflight_response(false, 422);
    }
    $programId = (int)($_POST['program_id'] ?? 0);
    $statement = $conn->prepare('SELECT program_name FROM programs WHERE program_id = ?');
    $statement->bind_param('i', $programId);
    $statement->execute();
    $program = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!$program || strcasecmp(trim($program['program_name']), trim($_POST['program_name'] ?? '')) !== 0) {
        import_preflight_response(false, 422);
    }
    $rows = readBeneficiaryImportRows($file['tmp_name'], $extension);
    $headers = null;
    foreach (array_slice($rows, 0, 25) as $row) {
        $key = beneficiaryImportHeaderKey(implode('', $row));
        if ((strpos($key, 'firstname') !== false && strpos($key, 'lastname') !== false)
            || strpos($key, 'fullname') !== false || strpos($key, 'ownername') !== false
            || strpos($key, 'businesstradename') !== false) {
            $headers = $row;
            break;
        }
    }
    if ($headers === null) import_preflight_response(false, 422);
    validateBeneficiaryImportTemplate($headers, $program['program_name']);
    import_preflight_response(true);
} catch (Throwable $error) {
    error_log('Import preflight: ' . $error->getMessage());
    import_preflight_response(false, 422);
}
