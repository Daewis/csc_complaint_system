<?php
/**
 * download_template.php — generates a downloadable CSV template for either
 * the staff import or the courses import, so admins/staff have a pre-filled
 * header row they can just open in Excel and fill in.
 *
 * Usage:
 *   download_template.php?type=staff    → staff_whitelist_template.csv
 *   download_template.php?type=courses  → course_catalog_template.csv
 *
 * No login required — these are just CSV headers, no sensitive data.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Allow admin or staff (anyone who can access the import pages)
requireLogin();
$user = currentUser();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    exit('Admins only.');
}

$type = $_GET['type'] ?? 'staff';

if ($type === 'staff') {
    $filename = 'staff_whitelist_template.csv';
    $headers  = ['full_name', 'email', 'pf_no', 'designation', 'department', 'faculty', 'level', 'is_lecturer'];
    $sampleRow = [
        'Dr. Jane Doe',
        'jane.doe@lasu.edu.ng',
        'LS9001',
        'Lecturer',
        'Computer Science',
        'Science',
        '400',
        '1',
    ];
} elseif ($type === 'courses') {
    $filename = 'course_catalog_template.csv';
    $headers  = ['course_code', 'course_title', 'faculty', 'credit_units', 'department', 'level', 'semester', 'status', 'curriculum_type'];
    $sampleRow = [
        'CSC 201',
        'Data Structures and Algorithms',
        'Science',
        '3',
        'Computer Science',
        '200',
        'Harmattan',
        'C',
        'CCMAS',
    ];
} else {
    http_response_code(400);
    exit('Invalid template type. Use ?type=staff or ?type=courses');
}

// Stream as a downloadable CSV
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

// Output UTF-8 BOM so Excel opens UTF-8 characters correctly
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, $headers);
fputcsv($out, $sampleRow);
fclose($out);
