<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = currentUser();
$db   = getDB();

// ── Handle "Mark all as read" action (explicit button) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    $count = markAllNotificationsRead((int)$user['id']);
    header('Location: notifications.php?marked=' . $count);
    exit;
}

// ── Fetch notifications (DO NOT auto-mark as read on page load) ────────────
// Best-practice: only flip is_read when the user explicitly acts on a
// notification. Previously this page marked EVERY notification as read on
// every visit, which made the unread badge essentially meaningless.
$stmt = $db->prepare('
    SELECT n.*, c.ticket_number
    FROM notifications n
    LEFT JOIN complaints c ON n.complaint_id = c.id
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
    LIMIT 100
');
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();

// Per-type counts for filter pills
$unreadCount = getUnreadCount((int)$user['id']);

$pageTitle = 'Notifications';
$activeNav = 'notifications.php';
include __DIR__ . '/includes/layout.php';

// Role → complaint view path
$dashMap = [
    'student'       => 'student/view_complaint.php',
    'level_adviser' => 'level_adviser/review_complaint.php',
    'hod'           => 'hod/review_complaint.php',
    'lecturer'      => 'lecturer/verify_complaint.php',
];
?>

<div class="mb-8 flex flex-wrap items-end justify-between gap-4">
  <div>
    <h2 class="text-3xl font-black text-[#001e40] tracking-tight">Notifications</h2>
    <p class="text-[#43474f] mt-1 text-sm">
        Your activity feed and system alerts
        <?php if ($unreadCount > 0): ?>
          &bull; <span class="font-bold text-[#001e40]"><?= $unreadCount ?> unread</span>
        <?php endif; ?>
    </p>
  </div>

  <?php if ($unreadCount > 0): ?>
  <form method="POST" class="inline">
    <input type="hidden" name="action" value="mark_all_read">
    <button type="submit"
      class="flex items-center gap-2 bg-[#001e40] text-white px-5 py-3 rounded-xl font-bold text-sm shadow-lg shadow-[#001e40]/20 hover:bg-[#003366] transition-all">
      <span class="material-symbols-outlined text-base">done_all</span>
      Mark all as read
    </button>
  </form>
  <?php endif; ?>
</div>

<?php if (isset($_GET['marked']) && (int)$_GET['marked'] > 0): ?>
<div class="mb-5 flex items-center gap-3 bg-green-50 text-green-800 border border-green-200 px-5 py-3 rounded-xl text-sm font-medium">
  <span class="material-symbols-outlined text-base">check_circle</span>
  <?= (int)$_GET['marked'] ?> notification(s) marked as read.
</div>
<?php endif; ?>

<div class="max-w-3xl">
  <?php if (empty($notifications)): ?>
  <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] flex flex-col items-center py-20 text-[#43474f]">
    <span class="material-symbols-outlined text-5xl opacity-30 mb-3">notifications_none</span>
    <p class="font-bold text-sm">No notifications yet.</p>
    <p class="text-xs mt-1">You're all caught up!</p>
  </div>
  <?php else: ?>
  <div class="space-y-3" id="notifications-list">
    <?php foreach ($notifications as $n): ?>
    <div data-notification-id="<?= (int)$n['id'] ?>"
         class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] p-5 flex items-start gap-4 transition-all duration-300
                <?= !$n['is_read'] ? 'border-l-4 border-[#fecb00]' : '' ?>">

      <!-- Icon -->
      <div class="w-9 h-9 rounded-lg <?= !$n['is_read'] ? 'bg-[#ffe08b]/50' : 'bg-[#edf4ff]' ?> flex items-center justify-center flex-shrink-0">
        <span class="material-symbols-outlined text-[#001e40] text-sm">notifications</span>
      </div>

      <!-- Body -->
      <div class="flex-1 min-w-0">
        <p class="text-sm text-[#0b1d2c] leading-relaxed <?= $n['is_read'] ? 'opacity-70' : 'font-medium' ?>">
          <?= sanitize($n['message']) ?>
        </p>
        <?php if ($n['complaint_id'] && $n['ticket_number'] && isset($dashMap[$user['role']])): ?>
          <?php $link = BASE_URL . $dashMap[$user['role']] . '?id=' . (int)$n['complaint_id']; ?>
          <a href="<?= $link ?>"
             class="inline-flex items-center gap-1 mt-1.5 text-xs text-[#001e40] font-bold hover:underline underline-offset-2">
            <span class="material-symbols-outlined text-xs">open_in_new</span>
            View <?= sanitize($n['ticket_number']) ?>
          </a>
        <?php endif; ?>
      </div>

      <!-- Timestamp + actions -->
      <div class="flex flex-col items-end gap-2 flex-shrink-0">
        <span class="text-[10px] text-[#43474f] whitespace-nowrap"><?= formatDate($n['created_at']) ?></span>
        <div class="flex items-center gap-1">
          <?php if (!$n['is_read']): ?>
          <button onclick="markNotificationRead(<?= (int)$n['id'] ?>, this)"
                  title="Mark as read"
                  class="p-1.5 rounded-lg text-gray-400 hover:text-green-600 hover:bg-green-50 transition-all">
            <span class="material-symbols-outlined text-sm">check_circle</span>
          </button>
          <?php endif; ?>
          <button onclick="deleteNotification(<?= (int)$n['id'] ?>, this)"
                  title="Delete notification"
                  class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition-all">
            <span class="material-symbols-outlined text-sm">delete</span>
          </button>
        </div>
      </div>

    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script>
/**
 * Delete a single notification (AJAX).
 */
function deleteNotification(id, btn) {
    if (!confirm('Delete this notification?')) return;

    const card = btn.closest('[data-notification-id]');
    if (!card) return;

    const formData = new FormData();
    formData.append('id', id);

    fetch('<?= BASE_URL ?>delete_notification.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
            card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            card.style.opacity    = '0';
            card.style.transform   = 'translateX(20px)';
            setTimeout(() => {
                card.remove();
                const list = document.getElementById('notifications-list');
                if (list && list.querySelectorAll('[data-notification-id]').length === 0) {
                    list.outerHTML = `
                        <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] flex flex-col items-center py-20 text-[#43474f]">
                            <span class="material-symbols-outlined text-5xl opacity-30 mb-3">notifications_none</span>
                            <p class="font-bold text-sm">No notifications yet.</p>
                            <p class="text-xs mt-1">You're all caught up!</p>
                        </div>`;
                }
            }, 300);
        } else {
            alert('Error deleting notification.');
        }
      })
      .catch(err => {
        console.error('Delete error:', err);
        alert('Something went wrong. Please try again.');
      });
}

/**
 * Mark a single notification as read (AJAX). The card visually changes from
 * unread to read; the badge count in the header is also refreshed.
 */
function markNotificationRead(id, btn) {
    const card = btn.closest('[data-notification-id]');
    if (!card) return;

    const formData = new FormData();
    formData.append('id', id);

    fetch('<?= BASE_URL ?>mark_notification_read.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
            // Remove the yellow left border + dim the text
            card.classList.remove('border-l-4', 'border-[#fecb00]');
            card.querySelector('p.text-sm').classList.remove('font-medium');
            card.querySelector('p.text-sm').classList.add('opacity-70');
            // Remove the "mark as read" button itself
            btn.remove();
            // Update the badge in the topbar (if any)
            const badge = document.querySelector('header .bg-red-500');
            if (badge) {
                const cur = parseInt(badge.textContent.trim(), 10) || 0;
                if (cur <= 1) {
                    badge.remove();
                } else {
                    badge.textContent = cur - 1;
                }
            }
        } else {
            alert('Could not mark as read.');
        }
      })
      .catch(err => {
        console.error('Mark-as-read error:', err);
        alert('Something went wrong. Please try again.');
      });
}
</script>

<?php include __DIR__ . '/includes/layout_end.php'; ?>
