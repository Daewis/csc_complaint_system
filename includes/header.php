<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$user = currentUser();
$unread = $user ? getUnreadCount($user['id']) : 0;
checkSLAStatus();
$pageTitle = $pageTitle ?? APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= sanitize($pageTitle) ?> | <?= APP_NAME ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php if ($user): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>">
      <i class="bi bi-mortarboard-fill me-2"></i>UniPortal
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav me-auto">
        <?php if ($user['role'] === 'student'): ?>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>student/dashboard.php"><i class="bi bi-grid me-1"></i>Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>student/new_complaint.php"><i class="bi bi-plus-circle me-1"></i>New Complaint</a></li>
        <?php elseif ($user['role'] === 'level_adviser'): ?>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>level_adviser/dashboard.php"><i class="bi bi-grid me-1"></i>Dashboard</a></li>
        <?php elseif ($user['role'] === 'hod'): ?>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>hod/dashboard.php"><i class="bi bi-grid me-1"></i>Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>hod/batch_process.php"><i class="bi bi-layers me-1"></i>Batch Process</a></li>
        <?php elseif ($user['role'] === 'lecturer'): ?>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>lecturer/dashboard.php"><i class="bi bi-grid me-1"></i>Dashboard</a></li>
        <?php elseif ($user['role'] === 'admin'): ?>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>admin/dashboard.php"><i class="bi bi-grid me-1"></i>Dashboard</a></li>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav align-items-center">
        <li class="nav-item me-2">
          <a class="nav-link position-relative" href="<?= BASE_URL ?>notifications.php">
            <i class="bi bi-bell fs-5"></i>
            <?php if ($unread > 0): ?>
              <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread ?></span>
            <?php endif; ?>
          </a>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown">
            <?php if (!empty($user['signature_path'])): ?>
              <img src="<?= BASE_URL . ltrim($user['signature_path'], '/') ?>" class="rounded-circle" width="32" height="32" style="object-fit:cover;">
            <?php else: ?>
              <span class="avatar-circle"><?= strtoupper(substr($user['full_name'], 0, 1)) ?></span>
            <?php endif; ?>
            <span class="d-none d-md-inline"><?= sanitize($user['full_name']) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><span class="dropdown-item-text text-muted small"><?= ucfirst(str_replace('_', ' ', $user['role'])) ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= BASE_URL ?>profile.php"><i class="bi bi-person me-2"></i>Profile & Signature</a></li>
            <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
<?php endif; ?>
<div class="main-content">
