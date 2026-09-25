<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
$user = currentUser();
$db   = getDB();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

// Only delete if it belongs to the logged-in user
$stmt = $db->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $user['id']]);

if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Not found or unauthorized']);
}
