<?php

function ensureSpesParentStatusCapacity(mysqli $conn): void
{
    $result = $conn->query("SHOW COLUMNS FROM beneficiaries LIKE 'parents_status'");
    $column = $result ? $result->fetch_assoc() : null;
    if (!$column) return;

    $type = strtolower((string)($column['Type'] ?? ''));
    if (!preg_match('/^varchar\((\d+)\)$/', $type, $matches) || (int)$matches[1] >= 500) return;

    $nullSql = strtoupper((string)($column['Null'] ?? 'YES')) === 'NO' ? 'NOT NULL' : 'NULL';
    $defaultSql = $column['Default'] !== null
        ? " DEFAULT '" . $conn->real_escape_string((string)$column['Default']) . "'"
        : '';
    $conn->query("ALTER TABLE beneficiaries MODIFY parents_status VARCHAR(500) $nullSql$defaultSql");
}
