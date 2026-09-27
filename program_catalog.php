<?php

/**
 * Permanent PESO program families. Database rows represent dated batches, so
 * the directory must not disappear merely because no batch is currently open.
 *
 * @return array<string, array{name:string,description:string,image:string}>
 */
function benepeso_program_catalog(): array
{
    return [
        'tupad' => [
            'name' => 'TUPAD',
            'description' => 'Community-based emergency employment assistance for qualified workers.',
            'image' => 'img/tupads.png',
        ],
        'spes' => [
            'name' => 'SPES',
            'description' => 'Employment opportunities that help qualified students continue their education.',
            'image' => 'img/spes.png',
        ],
        'msme' => [
            'name' => 'MSME Profiling',
            'description' => 'Business profiling and livelihood support for local micro and small enterprises.',
            'image' => 'img/msme.png',
        ],
    ];
}

function benepeso_program_family_key(string $programName): string
{
    $name = strtoupper($programName);
    if (str_contains($name, 'TUPAD')) return 'tupad';
    if (str_contains($name, 'SPES')) return 'spes';
    if (str_contains($name, 'MSME')) return 'msme';
    return '';
}

/** @return array<string, mixed> */
function benepeso_unavailable_program(string $family, array $program): array
{
    return [
        'program_id' => 0,
        'program_name' => $program['name'],
        'program_code' => 'No active batch',
        'description' => $program['description'],
        'image_path' => $program['image'],
        'start_date' => null,
        'end_date' => null,
        'venue' => 'PESO Vinzons',
        'requirements' => 'Requirements will be published when PESO opens a new batch.',
        'eligibility' => 'Eligibility rules will be published with the next official batch.',
        'eligible_sex' => 'Any',
        'minimum_age' => 18,
        'maximum_age' => null,
        'one_per_household' => 0,
        'status' => 'Unavailable',
        'approval_status' => 'Approved',
        'remaining_slots' => 0,
        'total_served' => 0,
        'batch_count' => 0,
        'categories' => [],
        'tupad_category' => '',
        'user_approval_status' => null,
        'user_approval_note' => null,
        'user_availment_status' => null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => null,
        'catalog_only' => true,
        'program_family' => $family,
    ];
}
