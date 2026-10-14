<?php

class BeneficiaryImportValidationException extends RuntimeException {}

function beneficiaryImportErrorExplanation(Throwable $error): string
{
    return $error instanceof BeneficiaryImportValidationException
        ? $error->getMessage()
        : 'The file could not be imported. Check its contents and try again. No records were saved.';
}

function cleanBeneficiaryImportText($value): string
{
    $value = (string)$value;
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

    if ($value !== '' && !mb_check_encoding($value, 'UTF-8')) {
        $detected = mb_detect_encoding($value, ['Windows-1252', 'ISO-8859-1', 'UTF-8'], true);
        $value = mb_convert_encoding($value, 'UTF-8', $detected ?: 'Windows-1252');
    }

    for ($pass = 0; $pass < 2; $pass++) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) break;
        $value = $decoded;
    }

    $value = str_replace(["\xC2\xA0", "\r\n", "\r"], [' ', "\n", "\n"], $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
    return trim($value);
}

function beneficiaryImportHeaderKey($value): string
{
    $value = strtolower(cleanBeneficiaryImportText($value));
    return preg_replace('/[^a-z0-9]/', '', $value) ?? '';
}

/** Compare the actual column schema with the selected program's import template. */
function validateBeneficiaryImportTemplate(array $headers, string $programName): void
{
    $program = '';
    foreach (['MSME', 'SPES', 'TUPAD'] as $candidate) {
        if (stripos($programName, $candidate) !== false) {
            $program = $candidate;
            break;
        }
    }
    if ($program === '') throw new BeneficiaryImportValidationException('Select a valid program batch before importing.');
    $templateRows = readBeneficiaryImportRows(__DIR__ . '/assets/templates/' . $program . '_Import_Blank.xlsx', 'xlsx');
    $expected = array_map('beneficiaryImportHeaderKey', $templateRows[0] ?? []);
    $actual = array_map('beneficiaryImportHeaderKey', $headers);
    // Blank trailing spreadsheet cells are harmless; missing, extra and duplicate columns are not.
    while ($actual && end($actual) === '') array_pop($actual);
    if (!$expected || count($actual) !== count(array_unique($actual))) {
        throw new BeneficiaryImportValidationException('The file has missing or duplicate columns. Use the template for this program.');
    }
    sort($expected);
    sort($actual);
    if ($actual !== $expected) throw new BeneficiaryImportValidationException('The columns do not match this program. Download its import template and try again.');
}

function beneficiaryImportColumnIndex(string $letters): int
{
    $number = 0;
    foreach (str_split($letters) as $letter) {
        $number = ($number * 26) + (ord($letter) - 64);
    }
    return max(0, $number - 1);
}

function beneficiaryImportXmlText(SimpleXMLElement $node): string
{
    $text = isset($node->t) ? (string)$node->t : '';
    if (isset($node->r)) {
        foreach ($node->r as $run) $text .= (string)$run->t;
    }
    return cleanBeneficiaryImportText($text);
}

function readBeneficiaryXlsxRows(string $filepath): array
{
    $archive = null;
    $temporaryArchive = null;
    $readEntry = null;

    if (class_exists('ZipArchive')) {
        $archive = new ZipArchive();
        if ($archive->open($filepath) !== true) throw new RuntimeException('The Excel workbook could not be opened.');
        $readEntry = static fn(string $path) => $archive->getFromName($path);
    } elseif (class_exists('PharData')) {
        $temporaryArchive = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'benepeso_import_' . bin2hex(random_bytes(8)) . '.zip';
        if (!copy($filepath, $temporaryArchive)) throw new RuntimeException('The Excel workbook could not be prepared for reading.');
        try {
            $archive = new PharData($temporaryArchive, 0, null, Phar::ZIP);
        } catch (Throwable $error) {
            @unlink($temporaryArchive);
            throw new RuntimeException('The Excel workbook is damaged or is not a valid .xlsx file.');
        }
        $readEntry = static function (string $path) use ($archive) {
            return isset($archive[$path]) ? $archive[$path]->getContent() : false;
        };
    } else {
        throw new RuntimeException('Excel import requires the PHP ZIP or Phar extension.');
    }

    try {
        $sharedStrings = [];
        $sharedXml = $readEntry('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $xml = simplexml_load_string($sharedXml);
            if ($xml !== false) {
                foreach ($xml->si as $item) $sharedStrings[] = beneficiaryImportXmlText($item);
            }
        }

        $sheetPath = 'xl/worksheets/sheet1.xml';
        $sheetXml = $readEntry($sheetPath);
        if ($sheetXml === false) throw new RuntimeException('The workbook does not contain a readable first worksheet.');

        $xml = simplexml_load_string($sheetXml);
        if ($xml === false || !isset($xml->sheetData)) {
            throw new RuntimeException('The first worksheet is not valid Excel data.');
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $cell) {
                $reference = (string)$cell['r'];
                preg_match('/^([A-Z]+)/i', $reference, $match);
                $index = isset($match[1]) ? beneficiaryImportColumnIndex(strtoupper($match[1])) : count($rowData);
                while (count($rowData) <= $index) $rowData[] = '';

                $type = (string)$cell['t'];
                if ($type === 's') {
                    $value = $sharedStrings[(int)$cell->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = beneficiaryImportXmlText($cell->is);
                } else {
                    $value = cleanBeneficiaryImportText((string)$cell->v);
                }
                $rowData[$index] = $value;
            }
            $rows[] = $rowData;
        }
        return $rows;
    } finally {
        if ($archive instanceof ZipArchive) $archive->close();
        unset($archive);
        if ($temporaryArchive && is_file($temporaryArchive)) @unlink($temporaryArchive);
    }
}

function convertBeneficiaryCsvToUtf8(string $raw): string
{
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) return substr($raw, 3);
    if (strncmp($raw, "\xFF\xFE", 2) === 0) return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
    if (strncmp($raw, "\xFE\xFF", 2) === 0) return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
    if (mb_check_encoding($raw, 'UTF-8')) return $raw;

    $detected = mb_detect_encoding($raw, ['Windows-1252', 'ISO-8859-1'], true);
    return mb_convert_encoding($raw, 'UTF-8', $detected ?: 'Windows-1252');
}

function detectBeneficiaryCsvDelimiter(string $raw): string
{
    $sampleLines = preg_split('/\R/u', $raw, 8) ?: [];
    $delimiters = [",", ";", "\t", "|"];
    $bestDelimiter = ',';
    $bestScore = 1;

    foreach ($delimiters as $delimiter) {
        $score = 0;
        foreach ($sampleLines as $line) {
            if (trim($line) !== '') $score = max($score, count(str_getcsv($line, $delimiter)));
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestDelimiter = $delimiter;
        }
    }
    return $bestDelimiter;
}

function readBeneficiaryCsvRows(string $filepath): array
{
    $raw = file_get_contents($filepath);
    if ($raw === false) throw new RuntimeException('The CSV file could not be read.');
    $raw = convertBeneficiaryCsvToUtf8($raw);
    $delimiter = detectBeneficiaryCsvDelimiter($raw);

    $stream = fopen('php://temp', 'w+b');
    if (!$stream) throw new RuntimeException('A temporary CSV reader could not be created.');
    fwrite($stream, $raw);
    rewind($stream);

    $rows = [];
    while (($row = fgetcsv($stream, 0, $delimiter)) !== false) {
        $rows[] = array_map('cleanBeneficiaryImportText', $row);
    }
    fclose($stream);
    return $rows;
}

function readBeneficiaryImportRows(string $filepath, string $extension): array
{
    if ($extension === 'xlsx') return readBeneficiaryXlsxRows($filepath);
    if ($extension === 'csv') return readBeneficiaryCsvRows($filepath);
    throw new RuntimeException('Only .xlsx and .csv files are supported.');
}

function normalizeBeneficiaryImportDate(string $value): string
{
    $value = cleanBeneficiaryImportText($value);
    if ($value === '') return '';

    // A compact YYYYMMDD value is a date, not an Excel serial number.
    if (preg_match('/^\d{8}$/', $value)) {
        $date = DateTimeImmutable::createFromFormat('!Ymd', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date instanceof DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Ymd') === $value) {
            return $date->format('Y-m-d');
        }
        return '';
    }

    if (is_numeric($value) && (float)$value > 1 && (float)$value <= 2958465) {
        $days = (int)floor((float)$value);
        $date = new DateTimeImmutable('1899-12-30');
        return $date->modify("+$days days")->format('Y-m-d');
    }

    // Year-first input is unambiguous and is the preferred CSV format.
    foreach (['!Y-m-d', '!Y/m/d', '!F j, Y', '!M j, Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date instanceof DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date->format('Y-m-d');
        }
    }

    // Accept day-first or month-first numeric input only when its order is
    // unambiguous. For example, 13/02/2026 is day-first and 02/13/2026 is
    // month-first; 02/03/2026 is rejected instead of being guessed.
    if (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $value, $parts)) {
        $first = (int)$parts[1];
        $second = (int)$parts[2];
        if ($first > 12 && $second <= 12) {
            $format = '!j/n/Y';
        } elseif ($second > 12 && $first <= 12) {
            $format = '!n/j/Y';
        } else {
            return '';
        }
        $normalized = str_replace('-', '/', $value);
        $date = DateTimeImmutable::createFromFormat($format, $normalized);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date instanceof DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date->format('Y-m-d');
        }
    }

    return '';
}

function normalizeBeneficiaryImportCivilStatus(string $value): string
{
    $key = strtolower(cleanBeneficiaryImportText($value));
    $map = [
        'single' => 'Single',
        'married' => 'Married',
        'widow' => 'Widowed',
        'widower' => 'Widowed',
        'widow/er' => 'Widowed',
        'widowed' => 'Widowed',
        'separated' => 'Legally Separated',
        'legally separated' => 'Legally Separated',
    ];
    return $map[$key] ?? cleanBeneficiaryImportText($value);
}

function beneficiaryTableColumns(mysqli $conn): array
{
    static $columns = null;
    if ($columns !== null) return $columns;
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM beneficiaries');
    if ($result) {
        while ($row = $result->fetch_assoc()) $columns[$row['Field']] = true;
    }
    return $columns;
}

function updateImportedBeneficiary(mysqli $conn, int $beneficiaryId, array $rowData, string $availmentStatus): bool
{
    require_once __DIR__ . '/historical_report_helper.php';
    $check = $conn->prepare('SELECT beneficiary_id FROM beneficiaries b WHERE beneficiary_id=? AND ' . historical_record_condition());
    $check->bind_param('i', $beneficiaryId); $check->execute();
    $archived = $check->get_result()->num_rows > 0; $check->close();
    if ($archived) throw new BeneficiaryImportValidationException('A record already exists in the completed or closed reports and cannot be overwritten. No records were saved.');
    $databaseColumns = beneficiaryTableColumns($conn);
    $blocked = ['beneficiary_id', 'user_id', 'program_id', 'created_by', 'created_at', 'updated_at', 'approval_status', 'availment_status'];
    $fields = [];

    foreach ($rowData as $column => $value) {
        if ($value === '' || isset($fields[$column]) || in_array($column, $blocked, true) || !isset($databaseColumns[$column])) continue;
        $fields[$column] = (string)$value;
    }
    $fields['availment_status'] = $availmentStatus;

    $existingStmt = $conn->prepare('SELECT * FROM beneficiaries WHERE beneficiary_id = ?');
    $existingStmt->bind_param('i', $beneficiaryId);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();
    $hasChanges = false;
    foreach ($fields as $column => $value) {
        $previous = trim((string)($existing[$column] ?? ''));
        $incoming = trim((string)$value);
        $numeric = in_array($column, ['age', 'avg_monthly_income', 'initial_capital', 'current_capital', 'daily_earnings', 'hr_total', 'hr_male', 'hr_female', 'emp_regular', 'emp_seasonal', 'emp_contractual', 'emp_family'], true);
        $same = $numeric && is_numeric($previous) && is_numeric($incoming)
            ? (float)$previous === (float)$incoming : $previous === $incoming;
        if (!$same) {
            $hasChanges = true;
            break;
        }
    }
    if ($existing && !$hasChanges) throw new BeneficiaryImportValidationException('These records already exist with the same details and status. No records were saved.');

    $assignments = array_map(static fn($column) => "`$column` = ?", array_keys($fields));
    $sql = 'UPDATE beneficiaries b SET ' . implode(', ', $assignments) . ', updated_at = NOW() WHERE beneficiary_id = ? AND NOT ' . historical_record_condition();
    $values = array_values($fields);
    $values[] = $beneficiaryId;
    $types = str_repeat('s', count($fields)) . 'i';

    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param($types, ...$values);
    $saved = $stmt->execute();
    $stmt->close();
    return $saved;
}

function insertImportedBeneficiary(
    mysqli $conn,
    array $rowData,
    int $programId,
    string $availmentStatus,
    string $approvalStatus,
    int $createdBy,
    string $source
): bool {
    $databaseColumns = beneficiaryTableColumns($conn);
    $fullName = cleanBeneficiaryImportText($rowData['full_name'] ?? '');
    if ($programId <= 0 || $fullName === '') return false;

    $fields = [
        'program_id' => $programId,
        'full_name' => $fullName,
        'availment_status' => $availmentStatus,
        'approval_status' => $approvalStatus,
        'created_by' => $createdBy,
        'status' => 'Active',
        'municipality' => cleanBeneficiaryImportText($rowData['municipality'] ?? '') ?: 'Vinzons',
        'district' => cleanBeneficiaryImportText($rowData['district'] ?? '') ?: 'Camarines Norte',
    ];
    if (isset($databaseColumns['application_source'])) $fields['application_source'] = $source;

    $blocked = ['beneficiary_id', 'user_id', 'program_id', 'created_by', 'created_at', 'updated_at', 'approval_status', 'availment_status', 'application_source'];
    foreach ($rowData as $column => $value) {
        if ($value === '' || isset($fields[$column]) || in_array($column, $blocked, true) || !isset($databaseColumns[$column])) continue;
        $fields[$column] = (string)$value;
    }

    $columns = array_keys($fields);
    $values = array_values($fields);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $columnSql = implode(', ', array_map(static fn($column) => "`$column`", $columns));
    $types = '';
    foreach ($columns as $column) $types .= in_array($column, ['program_id', 'created_by'], true) ? 'i' : 's';

    $stmt = $conn->prepare("INSERT INTO beneficiaries ($columnSql, created_at, updated_at) VALUES ($placeholders, NOW(), NOW())");
    if (!$stmt) return false;
    $stmt->bind_param($types, ...$values);
    $saved = $stmt->execute();
    $stmt->close();
    return $saved;
}

/** Prepare storage before any transaction; import never runs DDL during record writes. */
function prepareBeneficiaryImportOutbox(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS notification_outbox (
        notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dedupe_key CHAR(64) NOT NULL UNIQUE,
        beneficiary_id INT NOT NULL,
        status_name VARCHAR(80) NOT NULL,
        custom_message TEXT NULL,
        schedule_date VARCHAR(20) NULL,
        schedule_place VARCHAR(255) NULL,
        attempts INT NOT NULL DEFAULT 0,
        last_error VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_notification_outbox_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $result=$conn->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('beneficiaries','notification_outbox')");
    $tables=[];while($row=$result->fetch_assoc())$tables[$row['TABLE_NAME']]=strtolower($row['ENGINE']);
    if(($tables['beneficiaries']??'')!=='innodb'||($tables['notification_outbox']??'')!=='innodb')throw new RuntimeException('Import requires transactional storage.');
}
function queueBeneficiaryImportEmail(mysqli $conn,int $id,string $status,string $message,string $date,string $place): bool {
    $dedupe=hash('sha256',implode('|',[$id,$status,$message,$date,$place]));
    $stmt=$conn->prepare("INSERT INTO notification_outbox (dedupe_key,beneficiary_id,status_name,custom_message,schedule_date,schedule_place,last_error) VALUES (?,?,?,?,?,?, '') ON DUPLICATE KEY UPDATE last_error='',updated_at=NOW()");
    if(!$stmt)return false;$stmt->bind_param('sissss',$dedupe,$id,$status,$message,$date,$place);$ok=$stmt->execute();$stmt->close();return $ok;
}
function runBeneficiaryImportTransaction(mysqli $conn,array $rows,callable $save): void {
    if(!$rows)throw new RuntimeException('No import records.');
    if(!$conn->begin_transaction())throw new RuntimeException('Import transaction could not start.');
    try {foreach($rows as$row)$save($row);if(!$conn->commit())throw new RuntimeException('Import commit failed.');}
    catch(Throwable $error){$conn->rollback();throw $error;}
}
