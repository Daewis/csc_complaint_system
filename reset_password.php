<?php
/**
 * reset_password.php — Step 2 of the password reset flow.
 *
 * Reached from verify_otp.php after the OTP code has been validated.
 * The URL carries `?email=...&code=...` so this page can be sure the
 * user just verified. For safety we DON'T trust the code blindly — we
 * re-check it against the otp_codes table before allowing the update.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
redirectIfLoggedIn();

$email = trim($_GET['email'] ?? '');
$code  = trim($_GET['code']  ?? '');

$error   = '';
$success = '';

if (!$email || !$code) {
    header('Location: forgot_password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword     = $_POST['new_password']     ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Re-verify the OTP so this endpoint can't be called directly without a valid code
    $verify = verifyOtp($email, 'password_reset', $code);

    if (!$verify['ok']) {
        $error = $verify['error'] ?? 'Verification failed. Please restart the password reset flow.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        try {
            $db = getDB();
            $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE email = ?")
               ->execute([$hashed, $email]);

            // Audit log
            $uidStmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $uidStmt->execute([$email]);
            $uid = (int)$uidStmt->fetchColumn();
            try { logAudit(0, $uid, 'password_reset_completed', getClientIP(), 'User completed password reset.'); } catch (Throwable $e) {}

            header('Location: login.php?success=password_reset');
            exit;
        } catch (Throwable $e) {
            $error = 'Failed to update password: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset Password | LASU Result Complaint Portal</title>
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <style>
    .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
    body { font-family: 'Inter', sans-serif; }
  </style>
  <script>
    tailwind.config={theme:{extend:{colors:{
      "primary":"#001e40","secondary-container":"#fecb00","on-secondary-container":"#6e5700",
      "surface":"#f7f9ff","on-surface":"#0b1d2c","on-surface-variant":"#43474f",
      "error-container":"#ffdad6","on-error-container":"#93000a"
    }}}}
  </script>
</head>
<body class="min-h-screen bg-[#f7f9ff] flex items-center justify-center px-4 py-12">

<div class="w-full max-w-md bg-white rounded-[2.5rem] p-10 shadow-[0_20px_50px_rgba(0,0,0,0.05)] border border-gray-100 flex flex-col items-center text-center">
  <div class="w-16 h-16 bg-[#edf4ff] rounded-2xl flex items-center justify-center mb-6 shadow-sm">
    <span class="material-symbols-outlined text-[#001e40] text-3xl">lock</span>
  </div>

  <h1 class="text-3xl font-black text-[#001e40] mb-2">Set New Password</h1>
  <p class="text-[#43474f] text-sm mb-8 max-w-xs">
    Your email has been verified. Choose a new password for your account.
  </p>

  <?php if ($error): ?>
  <div class="mb-6 w-full p-4 rounded-xl text-xs font-bold bg-[#ffdad6] text-[#93000a] flex items-center gap-2">
    <span class="material-symbols-outlined text-base">error</span>
    <div class="text-left"><?= htmlspecialchars($error) ?></div>
  </div>
  <?php endif; ?>

  <form method="POST" class="w-full space-y-6">
    <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
    <input type="hidden" name="code"  value="<?= htmlspecialchars($code) ?>">

    <div class="space-y-2">
      <label class="text-xs font-bold text-[#43474f] uppercase tracking-widest text-left ml-1">New Password</label>
      <div class="relative">
        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[#43474f] text-lg">lock</span>
        <input type="password" id="pwd_input" name="new_password" required minlength="6" autofocus
          class="w-full pl-11 pr-11 py-3.5 bg-[#edf4ff] rounded-xl border-none text-[#0b1d2c] placeholder-[#43474f]/40 focus:ring-2 focus:ring-[#001e40] focus:outline-none text-sm"
          placeholder="Min. 6 characters">
        <button type="button" onclick="var i=document.getElementById('pwd_input');i.type=i.type==='password'?'text':'password';"
          class="absolute right-4 top-1/2 -translate-y-1/2 text-[#43474f] hover:text-[#001e40]">
          <span class="material-symbols-outlined text-lg">visibility</span>
        </button>
      </div>
    </div>

    <div class="space-y-2">
      <label class="text-xs font-bold text-[#43474f] uppercase tracking-widest text-left ml-1">Confirm Password</label>
      <div class="relative">
        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[#43474f] text-lg">lock_reset</span>
        <input type="password" id="pwd_confirm" name="confirm_password" required minlength="6"
          class="w-full pl-11 pr-4 py-3.5 bg-[#edf4ff] rounded-xl border-none text-[#0b1d2c] placeholder-[#43474f]/40 focus:ring-2 focus:ring-[#001e40] focus:outline-none text-sm"
          placeholder="Re-enter password">
      </div>
      <p id="match_msg" class="text-xs font-bold hidden"></p>
    </div>

    <button type="submit" class="w-full bg-[#001e40] text-white py-4 px-6 rounded-2xl font-bold text-md shadow-lg shadow-[#001e40]/20 hover:bg-[#003366] active:scale-[0.98] transition-all flex items-center justify-center gap-2">
      <span class="material-symbols-outlined text-base">how_to_reg</span>
      Reset Password
    </button>

    <div class="pt-4 border-t border-gray-100">
      <a href="login.php" class="block w-full py-4 bg-[#edf4ff] rounded-2xl text-[#43474f] font-bold text-sm hover:bg-[#d2e4f9] transition-colors">
        Back to Login
      </a>
    </div>
  </form>
</div>

<script>
  (function () {
    const pw  = document.getElementById('pwd_input');
    const cf  = document.getElementById('pwd_confirm');
    const msg = document.getElementById('match_msg');
    if (!pw || !cf || !msg) return;
    function check() {
      if (!cf.value) { msg.classList.add('hidden'); return; }
      msg.classList.remove('hidden');
      if (pw.value === cf.value) {
        msg.textContent = '✓ Passwords match';
        msg.className   = 'text-xs font-bold text-[#1a6b36]';
      } else {
        msg.textContent = '✗ Passwords do not match';
        msg.className   = 'text-xs font-bold text-[#93000a]';
      }
    }
    pw.addEventListener('input', check);
    cf.addEventListener('input', check);
  })();
</script>

</body>
</html>
