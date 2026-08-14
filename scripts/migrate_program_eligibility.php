<?php
require dirname(__DIR__) . '/db.php';
require dirname(__DIR__) . '/program_eligibility_helper.php';

ensure_program_eligibility_schema($conn);

// Existing TUPAD records start with the standard one-active-beneficiary-per-household safeguard.
$conn->query("UPDATE program_categories SET one_per_household = 1 WHERE UPPER(program_name) LIKE '%TUPAD%'");
$conn->query("UPDATE programs SET one_per_household = 1 WHERE UPPER(program_name) LIKE '%TUPAD%'");
$conn->query("UPDATE program_categories SET minimum_age = 18, maximum_age = 30 WHERE UPPER(program_name) = 'SPES'");
$conn->query("UPDATE programs SET minimum_age = 18, maximum_age = 30 WHERE UPPER(program_name) = 'SPES'");

foreach (['program_categories', 'programs'] as $table) {
    echo $table . ':' . PHP_EOL;
    $result = $conn->query(
        "SHOW COLUMNS FROM `$table` WHERE Field IN ('eligible_sex','minimum_age','maximum_age','one_per_household')"
    );
    while ($row = $result->fetch_assoc()) {
        $default = $row['Default'] === null ? 'NULL' : $row['Default'];
        echo $row['Field'] . ' ' . $row['Type'] . ' DEFAULT=' . $default . PHP_EOL;
    }
}

$tupad = $conn->query("SELECT program_name, eligible_sex, minimum_age, maximum_age, one_per_household FROM programs WHERE UPPER(program_name) LIKE '%TUPAD%'");
echo 'TUPAD rules:' . PHP_EOL;
while ($row = $tupad->fetch_assoc()) echo json_encode($row) . PHP_EOL;
