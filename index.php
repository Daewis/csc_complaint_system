<?php

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . getRoleDashboard($_SESSION['role']));
} else {

    header('Location: login.php');
}
exit;
