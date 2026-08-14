<?php
session_start();
require 'db.php';
require_once 'tupad_household_helper.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id']) && empty($_SESSION['peso_staff_id'])) {
    http_response_code(403);
    echo json_encode(['found' => false, 'message' => 'Unauthorized.']);
    exit();
}

$requestedName = trim((string)($_GET['name'] ?? ''));
if ($requestedName === '') {
    echo json_encode(['found' => false]);
    exit();
}

$result = $conn->query("SELECT b.*, p.program_name FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id WHERE p.program_name LIKE '%TUPAD%' ORDER BY b.created_at DESC");
$profile = null;
while ($result && ($row = $result->fetch_assoc())) {
    if (normalize_person_name((string)$row['full_name']) !== normalize_person_name($requestedName)) continue;
    $row['program'] = $row['program_name'];
    $row['name'] = trim((string)$row['full_name']);
    $row['initial'] = strtoupper(substr($row['name'], 0, 1));
    $row['availment'] = $row['availment_status'] ?? 'Not Yet Availed';
    $row['approval'] = $row['approval_status'] ?? 'Pending';
    $row['id'] = (int)$row['beneficiary_id'];
    $row['added_by'] = $row['application_source'] ?: 'Online Applicant';
    $profile = $row;
    break;
}

echo json_encode(['found' => $profile !== null, 'profile' => $profile], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
