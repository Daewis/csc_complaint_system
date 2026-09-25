<?php

function validateCourseRemoval(
    array $scanData,
    array $course
): array {

    if (($course['status'] ?? 'C') === 'C') {
        return [
            'eligible' => false,
            'reason' => 'Compulsory courses cannot be removed.'
        ];
    }

    $currentUnits = (int)($scanData['total_units'] ?? 0);

    $remaining = $currentUnits - (int)$course['credit_units'];

    if ($remaining < MIN_UNITS) {
        return [
            'eligible' => false,
            'reason' => "Removing this course reduces total units to {$remaining}, below minimum allowed (" . MIN_UNITS . ")."
        ];
    }

    return [
        'eligible' => true,
        'reason' => 'Eligible for course removal.'
    ];
}
