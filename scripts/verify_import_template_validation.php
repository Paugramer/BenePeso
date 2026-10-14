<?php
// Read-only regression check: no database connection or beneficiary writes.
require_once __DIR__ . '/../beneficiary_import_helper.php';

foreach (['MSME', 'SPES', 'TUPAD'] as $source) {
    $rows = readBeneficiaryImportRows(__DIR__ . '/../assets/templates/' . $source . '_Import_Blank.xlsx', 'xlsx');
    $headers = $rows[0];
    foreach (['MSME', 'SPES', 'TUPAD'] as $target) {
        $accepted = true;
        try {
            validateBeneficiaryImportTemplate($headers, $target);
        } catch (RuntimeException $error) {
            $accepted = false;
        }
        if ($accepted !== ($source === $target)) {
            throw new RuntimeException("Unexpected template result: $source -> $target");
        }
    }
    // Column order may change; required fields and their uniqueness may not.
    validateBeneficiaryImportTemplate(array_reverse($headers), $source);
    foreach ([array_slice($headers, 1), array_merge($headers, ['Unknown Column']), array_merge($headers, [$headers[0]])] as $invalid) {
        $rejected = false;
        try {
            validateBeneficiaryImportTemplate($invalid, $source);
        } catch (RuntimeException $error) {
            $rejected = true;
        }
        if (!$rejected) throw new RuntimeException("Invalid $source column schema accepted.");
    }
}
echo "Matching templates and reordered columns accepted; cross-program and invalid schemas rejected.\n";
