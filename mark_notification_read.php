<?php
/**
 * mark_notification_read.php — AJAX endpoint
 *
 * Marks a single notification as read for the logged-in user. Returns JSON.
 * Used by the "✓" button on each notification card in notifications.php.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

// Require login
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

$user = currentUser();
$ok = markNotificationRead($id, (int)$user['id']);

echo json_encode(['success' => $ok]);
