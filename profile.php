<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user    = currentUser();
$db      = getDB();
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- LOGIC: UPDATE BASIC PROFILE ---
    if ($action === 'update_profile') {
        $name  = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($name) {
            $db->prepare('UPDATE users SET full_name=?, phone=? WHERE id=?')->execute([$name, $phone, $user['id']]);
            $success = 'Profile details updated successfully.';
            $user = currentUser();
        }
    }

    // --- LOGIC: UPLOAD PROFILE PICTURE ---
    // Inside profile.php -> upload_profile_pic block
if ($action === 'upload_profile_pic') {
  if (!empty($_FILES['profile_pic']['name'])) {
      $identifier = ($user['matric_number'] ?? $user['PF_NO'] ?? $user['id']) . '_avatar';

      // CRITICAL FIX: Pass 'profiles' as the 4th argument here
      $path = saveSignature($_FILES['profile_pic'], $identifier, $user['role'], 'profiles');

      if ($path) {
          $db->prepare('UPDATE users SET profile_picture=? WHERE id=?')->execute([$path, $user['id']]);
          $success = 'Profile picture updated successfully.';
          $user = currentUser();
      } else {
          $error = 'Failed to upload profile picture.';
      }
  }
}

    // --- LOGIC: UPLOAD SIGNATURE ---
    if ($action === 'upload_signature') {
        if (!empty($_FILES['signature_file']['name'])) {
            $identifier = ($user['matric_number'] ?? $user['PF_NO'] ?? $user['id']) . '_sign';
            $path = saveSignature($_FILES['signature_file'], $identifier, $user['role']);

            if ($path) {
                $db->prepare('UPDATE users SET signature_path=? WHERE id=?')->execute([$path, $user['id']]);
                $success = 'Digital signature updated successfully.';
                $user = currentUser();
            } else {
                $error = 'Failed to upload signature. Use a clear PNG/JPG under 2MB.';
            }
        }
    }

    // --- LOGIC: CHANGE PASSWORD ---
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } elseif (strlen($new) < 6) {
            $error = 'New password must be at least 6 characters.';
        } else {
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]);
            $success = 'Password changed successfully.';
        }
    }
}

$pageTitle = 'My Profile';
$activeNav = 'profile.php';
include __DIR__ . '/includes/layout.php';
?>

<div class="mb-8">
  <h2 class="text-3xl font-black text-[#001e40] tracking-tight">My Profile</h2>
  <p class="text-[#43474f] mt-1 text-sm">Manage your account information and digital identity</p>
</div>

<?php if ($success): ?>
<div class="mb-6 flex items-center gap-3 bg-green-50 text-green-800 border border-green-200 px-5 py-4 rounded-2xl text-sm font-bold">
  <span class="material-symbols-outlined text-base">check_circle</span><?= sanitize($success) ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6 flex items-center gap-3 bg-[#ffdad6] text-[#93000a] px-5 py-4 rounded-2xl text-sm font-bold">
  <span class="material-symbols-outlined text-base">error</span><?= sanitize($error) ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-12 gap-8">

    <div class="col-span-12 lg:col-span-4 space-y-6">

        <div class="bg-white rounded-[2rem] p-8 text-center shadow-sm border border-gray-100">
            <div class="relative inline-block group">
                <div class="w-32 h-32 rounded-full overflow-hidden border-4 border-[#edf4ff] bg-gray-50 mx-auto">
                    <?php if ($user['profile_picture']): ?>
                        <img src="<?= BASE_URL . $user['profile_picture'] ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center bg-[#001e40] text-[#fecb00] text-3xl font-black">
                            <?= strtoupper(substr($user['full_name'], 0, 2)) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <button onclick="document.getElementById('profile_pic_input').click()"
                        class="absolute bottom-1 right-1 bg-[#fecb00] text-[#001e40] p-2 rounded-full shadow-lg hover:scale-110 transition-transform">
                    <span class="material-symbols-outlined text-sm">photo_camera</span>
                </button>
            </div>

            <form id="profile_pic_form" method="POST" enctype="multipart/form-data" class="hidden">
                <input type="hidden" name="action" value="upload_profile_pic">
                <input type="file" name="profile_pic" id="profile_pic_input" accept="image/*" onchange="document.getElementById('profile_pic_form').submit()">
            </form>

            <h3 class="mt-6 font-black text-xl text-[#001e40]"><?= sanitize($user['full_name']) ?></h3>
            <p class="text-[10px] font-bold text-[#43474f] uppercase tracking-[0.2em] opacity-60 mt-1"><?= str_replace('_', ' ', $user['role']) ?></p>

            <div class="mt-6 pt-6 border-t border-gray-50 space-y-3">
                <div class="flex items-center gap-3 text-left p-3 bg-[#f7f9ff] rounded-2xl">
                    <span class="material-symbols-outlined text-[#001e40] text-lg">badge</span>
                    <div>
                        <p class="text-[9px] uppercase font-bold text-gray-400 tracking-wider">Institutional ID</p>
                        <p class="text-xs font-black text-[#001e40]"><?= sanitize($user['matric_number'] ?? $user['PF_NO'] ?? 'N/A') ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-3 text-left p-3 bg-[#f7f9ff] rounded-2xl">
                    <span class="material-symbols-outlined text-[#001e40] text-lg">school</span>
                    <div class="min-w-0">
                        <p class="text-[9px] uppercase font-bold text-gray-400 tracking-wider">Department</p>
                        <p class="text-xs font-black text-[#001e40] truncate"><?= sanitize($user['department'] ?? 'General') ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-span-12 lg:col-span-8 space-y-6">

        <div class="bg-white rounded-[2rem] p-8 shadow-sm border border-gray-100">
            <h4 class="text-lg font-black text-[#001e40] mb-6 flex items-center gap-2">
                <span class="material-symbols-outlined">person_edit</span> Account Information
            </h4>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <input type="hidden" name="action" value="update_profile">
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-[#43474f] uppercase tracking-widest ml-1">Full Name</label>
                    <input type="text" name="full_name" value="<?= sanitize($user['full_name']) ?>"
                           class="w-full bg-[#f7f9ff] border-none rounded-xl p-4 text-sm font-semibold focus:ring-2 focus:ring-[#001e40]">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-[#43474f] uppercase tracking-widest ml-1">Phone Number</label>
                    <input type="text" name="phone" value="<?= sanitize($user['phone'] ?? '') ?>"
                           class="w-full bg-[#f7f9ff] border-none rounded-xl p-4 text-sm font-semibold focus:ring-2 focus:ring-[#001e40]" placeholder="+234...">
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="bg-[#001e40] text-white px-8 py-4 rounded-xl font-bold text-sm shadow-lg shadow-[#001e40]/20 hover:bg-[#003366] transition-all active:scale-95">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-[2rem] p-8 shadow-sm border border-gray-100">
            <h4 class="text-lg font-black text-[#001e40] mb-2 flex items-center gap-2">
                <span class="material-symbols-outlined">draw</span> Digital Signature
            </h4>
            <p class="text-xs text-[#43474f] mb-8">Upload a transparent PNG of your signature for academic endorsements.</p>

            <div class="flex flex-col md:flex-row items-start gap-10">
                <div class="w-full md:w-56 h-32 bg-[#f7f9ff] border-2 border-dashed border-gray-200 rounded-2xl flex flex-col items-center justify-center p-4 relative overflow-hidden">
                    <?php if($user['signature_path']): ?>
                        <img src="<?= BASE_URL . $user['signature_path'] ?>" class="max-h-full object-contain z-10">
                    <?php else: ?>
                        <span class="text-[10px] font-bold text-gray-300">No Signature Found</span>
                    <?php endif; ?>
                </div>

                <form method="POST" enctype="multipart/form-data" class="flex-1 space-y-5 w-full">
                    <input type="hidden" name="action" value="upload_signature">
                    <div class="relative">
                        <input type="file" name="signature_file" id="sign_input" accept="image/*" required
                               class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-20"
                               onchange="document.getElementById('sign_name').innerText = this.files[0].name">
                        <div class="bg-[#edf4ff] border border-[#d2e4f9] rounded-xl p-4 flex items-center justify-between">
                            <span id="sign_name" class="text-xs font-bold text-[#001e40] truncate pr-4">Choose PNG/JPG...</span>
                            <span class="bg-[#001e40] text-white text-[10px] px-3 py-1.5 rounded-lg font-black uppercase">Browse</span>
                        </div>
                    </div>
                    <button type="submit" class="w-full md:w-auto bg-[#fecb00] text-[#001e40] px-8 py-4 rounded-xl font-bold text-sm shadow-md hover:bg-yellow-500 transition-all">
                        Update Signature
                    </button>
                </form>
            </div>
        </div>

        <div class="bg-white rounded-[2rem] p-8 shadow-sm border border-gray-100">
            <h4 class="text-lg font-black text-[#001e40] mb-6 flex items-center gap-2">
                <span class="material-symbols-outlined">lock</span> Security & Password
            </h4>
            <form method="POST" class="space-y-6">
                <input type="hidden" name="action" value="change_password">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-bold text-[#43474f] uppercase tracking-widest ml-1">Current Password</label>
                        <input type="password" name="current_password" required class="w-full bg-[#f7f9ff] border-none rounded-xl p-4 text-sm font-semibold">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-bold text-[#43474f] uppercase tracking-widest ml-1">New Password</label>
                        <input type="password" name="new_password" required class="w-full bg-[#f7f9ff] border-none rounded-xl p-4 text-sm font-semibold">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-bold text-[#43474f] uppercase tracking-widest ml-1">Confirm New</label>
                        <input type="password" name="confirm_password" required class="w-full bg-[#f7f9ff] border-none rounded-xl p-4 text-sm font-semibold">
                    </div>
                </div>
                <button type="submit" class="bg-[#001e40] text-white px-8 py-4 rounded-xl font-bold text-sm shadow-lg shadow-[#001e40]/20 hover:bg-[#003366] transition-all">
                    Update Password
                </button>
            </form>
        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/layout_end.php'; ?>
