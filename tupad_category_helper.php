<?php

function ensure_tupad_category_schema(mysqli $conn): void
{
    $result = $conn->query("SHOW COLUMNS FROM programs LIKE 'tupad_category'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE programs ADD COLUMN tupad_category VARCHAR(120) NULL AFTER program_name");
    }
}

function tupad_category_options(): array
{
    return [
        'Regular TUPAD',
        'TUPAD Brigada',
        'TUPAD Community Clean-up',
        'TUPAD Post-Calamity',
        'Other TUPAD Initiative',
    ];
}

function available_tupad_categories(mysqli $conn): array
{
    $categories = array_fill_keys(tupad_category_options(), true);
    $result = $conn->query("SELECT DISTINCT TRIM(tupad_category) AS category FROM programs WHERE program_name LIKE '%TUPAD%' AND TRIM(COALESCE(tupad_category, '')) <> '' ORDER BY category");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $categories[(string)$row['category']] = true;
        }
    }
    return array_keys($categories);
}

function submitted_tupad_category(array $source, string $programName): ?string
{
    if (stripos($programName, 'TUPAD') === false) {
        return null;
    }

    $category = trim((string)($source['tupad_category'] ?? ''));
    if ($category === 'Other TUPAD Initiative') {
        $custom = trim((string)($source['other_tupad_category'] ?? ''));
        if ($custom !== '') {
            $category = $custom;
        }
    }

    return $category !== '' ? substr($category, 0, 120) : 'Regular TUPAD';
}
