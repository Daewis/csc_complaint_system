<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// ── AUDIT FALLBACK ─────────────────────────────────────────────────────────────
if (!function_exists('logAudit')) {
    function logAudit(int $actorId, int $targetId, string $action, string $ip, string $note = ''): void {
        try {
            $db   = getDB();
            $stmt = $db->prepare("
                INSERT INTO audit_log (actor_id, target_id, action, ip_address, notes, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$actorId, $targetId, $action, $ip, $note]);
        } catch (Throwable $e) {
            error_log('logAudit failed: ' . $e->getMessage());
        }
    }
}

// ── MAIN LOGIC ────────────────────────────────────────────────────────────────
$error        = '';
$success      = '';
$staffDetails = null;
$pfNo         = $_POST['pf_no'] ?? $_SESSION['reg_pf_no'] ?? '';
$step         = $_SESSION['reg_step'] ?? 'fetch';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── STEP 1: FETCH DETAILS BY PF_NO ──────────────────────────────────────
    if (isset($_POST['fetch_details'])) {
        $pfNo         = trim($_POST['pf_no'] ?? '');
        $staffDetails = lookupStaff($pfNo);

        if (!$staffDetails) {
            $error = 'Staff record not found. Please check your PF_NO or contact the ICT department.';
            $step  = 'fetch';
        } else {
            $db   = getDB();
            $stmt = $db->prepare("SELECT password_hash, is_verified FROM users WHERE PF_NO = ? LIMIT 1");
            $stmt->execute([$pfNo]);
            $existing = $stmt->fetch();

            if ($existing && $existing['password_hash'] !== 'PRE_REGISTERED' && $existing['is_verified'] == 1) {
                $error = 'This account is already registered. Please log in instead.';
                $step  = 'fetch';
            } else {
                $_SESSION['reg_pf_no'] = $pfNo;
                $_SESSION['reg_step']  = 'password';
                $step = 'password';
            }
        }
    }

    // ── STEP 2: VALIDATE PASSWORD → COMMIT REGISTRATION DIRECTLY ──────────────
    elseif (isset($_POST['set_password'])) {
        $pfNo      = trim($_SESSION['reg_pf_no'] ?? $_POST['pf_no'] ?? '');
        $password  = $_POST['password'] ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';

        $staffDetails = lookupStaff($pfNo);

        if (!$staffDetails) {
            $error = 'Session expired. Please start again.';
            $step  = 'fetch';
            unset($_SESSION['reg_pf_no'], $_SESSION['reg_step']);

        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
            $step  = 'password';

        } elseif ($password !== $confirmPw) {
            $error = 'Passwords do not match.';
            $step  = 'password';

        } else {
            // Commit registration directly to DB
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);

            try {
                $db   = getDB();
                $stmt = $db->prepare("
                    UPDATE users
                    SET password_hash = ?, is_verified = 1, is_active = 1
                    WHERE PF_NO = ?
                ");
                $stmt->execute([$passwordHash, $pfNo]);

                // Audit log is best-effort — never blocks registration
                try {
                    logAudit(0, $staffDetails['id'] ?? 0, 'staff_self_registered', getClientIP(),
                        "Staff PF_NO {$pfNo} completed registration — direct (no OTP)");
                } catch (Throwable $e) {
                    error_log('logAudit failed (non-fatal): ' . $e->getMessage());
                }

                unset(
                    $_SESSION['reg_pf_no'],
                    $_SESSION['reg_step']
                );

                $_SESSION['flash_success'] = 'Registration complete! You can now log in.';
                header('Location: login.php');
                exit;

            } catch (Throwable $e) {
                error_log('Registration DB error: ' . $e->getMessage());
                $error = 'Registration failed: ' . $e->getMessage();
                $step  = 'password';
            }
        }
    }

} else {
    if (!in_array($step, ['fetch', 'password'])) {
        $step = 'fetch';
    }
}

// Keep staffDetails populated across steps
if (in_array($step, ['password']) && !$staffDetails) {
    $pfNo         = $_SESSION['reg_pf_no'] ?? '';
    $staffDetails = lookupStaff($pfNo);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Staff Registration | LASU Academic Portal</title>
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">

  <style>
    .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
    body { font-family: 'Inter', sans-serif; }
  </style>
</head>
<body class="min-h-screen bg-[#f7f9ff] flex items-center justify-center px-4 py-12">

<div class="w-full max-w-4xl">

  <!-- Header -->
  <div class="text-center mb-10">
    <h1 class="text-4xl font-black text-[#001e40] mb-2">Staff Portal Registration</h1>
    <p class="text-[#43474f] text-lg">Initialize your institutional profile using your PF_NO</p>

    <!-- Step indicator -->
    <div class="flex items-center justify-center gap-2 mt-6">
      <?php
        $steps = [
          ['fetch',    'badge',         'Lookup'],
          ['password', 'lock',          'Password'],
        ];
        $currentIdx = array_search($step, array_column($steps, 0));
        foreach ($steps as $i => [$sid, $icon, $label]):
          $isActive = ($step === $sid);
          $isDone   = ($currentIdx > $i);
      ?>
      <div class="flex items-center gap-1">
        <div class="flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-bold
          <?= $isActive ? 'bg-[#001e40] text-white' : ($isDone ? 'bg-[#c8f0d4] text-[#1a6b36]' : 'bg-gray-100 text-gray-400') ?>">
          <span class="material-symbols-outlined text-sm"><?= $isDone ? 'check_circle' : $icon ?></span>
          <?= $label ?>
        </div>
        <?php if ($i < count($steps) - 1): ?>
          <span class="material-symbols-outlined text-gray-300 text-sm">chevron_right</span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Flash messages -->
  <?php if ($error): ?>
  <div class="mb-6 max-w-2xl mx-auto flex items-center gap-3 bg-[#ffdad6] text-[#93000a] px-4 py-3 rounded-xl text-sm font-bold">
    <span class="material-symbols-outlined text-base">error</span>
    <?= htmlspecialchars($error) ?>
  </div>
  <?php endif; ?>

  <?php if ($success): ?>
  <div class="mb-6 max-w-2xl mx-auto flex items-center gap-3 bg-[#c8f0d4] text-[#1a6b36] px-4 py-3 rounded-xl text-sm font-bold">
    <span class="material-symbols-outlined text-base">mark_email_read</span>
    <?= htmlspecialchars($success) ?>
  </div>
  <?php endif; ?>


  <!-- ════════════════════════════════════════════════════════════
       STEP 1 — PF_NO LOOKUP
       ════════════════════════════════════════════════════════════ -->
  <?php if ($step === 'fetch'): ?>
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
    <div class="lg:col-span-5 flex flex-col gap-6">
      <div class="bg-white p-8 rounded-2xl shadow-sm border border-gray-100">
        <form method="POST" class="space-y-6">
          <div>
            <label class="block text-xs font-bold text-[#001e40] uppercase tracking-widest mb-2">PF_NO</label>
            <div class="relative">
              <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[#43474f] text-lg">badge</span>
              <input type="text" name="pf_no" required autofocus
                class="w-full pl-11 pr-4 py-3 bg-[#edf4ff] rounded-xl border-none text-[#0b1d2c] placeholder-[#43474f]/40 focus:ring-2 focus:ring-[#001e40] focus:outline-none text-sm"
                placeholder="e.g. LS1234"
                value="<?= htmlspecialchars($pfNo) ?>">
            </div>
          </div>
          <button type="submit" name="fetch_details"
            class="w-full bg-[#001e40] text-white py-3.5 rounded-xl font-bold text-sm shadow-lg shadow-[#001e40]/20 hover:bg-[#003366] active:scale-[0.98] transition-all flex items-center justify-center gap-2">
            Fetch Details
            <span class="material-symbols-outlined text-base">arrow_forward</span>
          </button>
        </form>
      </div>

      <div class="bg-[#fecb00] p-6 rounded-2xl flex items-start gap-4">
        <div class="bg-[#001e40]/10 p-2 rounded-lg">
          <span class="material-symbols-outlined text-[#6e5700] text-xl">info</span>
        </div>
        <div>
          <p class="text-[#6e5700] font-bold text-sm mb-1">Not finding your record?</p>
          <p class="text-[#6e5700]/70 text-xs leading-relaxed">
            Your PF_NO must be pre-registered by the admin. Contact the ICT department if your record is missing.
          </p>
        </div>
      </div>
    </div>

    <div class="lg:col-span-7">
      <div class="bg-[#edf4ff] rounded-2xl min-h-[360px] flex flex-col items-center justify-center text-center gap-4 border border-gray-100 relative overflow-hidden">
        <div class="absolute top-0 right-0 p-8 opacity-5 select-none pointer-events-none">
          <span class="material-symbols-outlined text-9xl">school</span>
        </div>
        <span class="material-symbols-outlined text-5xl text-[#001e40]/10">badge</span>
        <p class="text-[#43474f] text-sm font-medium">Enter your PF_NO on the left<br>to fetch your institutional record.</p>
      </div>
    </div>
  </div>


  <!-- ════════════════════════════════════════════════════════════
       STEP 2 — SET PASSWORD
       ════════════════════════════════════════════════════════════ -->
  <?php elseif ($step === 'password' && $staffDetails): ?>
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

    <div class="lg:col-span-5 flex flex-col gap-6">
      <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <p class="text-xs font-bold text-[#43474f] uppercase tracking-widest mb-4">Verified Record</p>
        <div class="flex items-center gap-4 mb-4">
          <div class="w-14 h-14 rounded-2xl bg-[#001e40] text-white flex items-center justify-center font-black text-xl">
            <?= strtoupper(substr($staffDetails['firstName'], 0, 1)) ?>
          </div>
          <div>
            <p class="font-black text-[#001e40]">
              <?= htmlspecialchars($staffDetails['title'] . ' ' . $staffDetails['firstName'] . ' ' . $staffDetails['surname']) ?>
            </p>
            <p class="text-xs text-[#43474f]"><?= htmlspecialchars($staffDetails['email']) ?></p>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3 text-xs">
          <div>
            <p class="text-[#43474f] uppercase tracking-widest mb-0.5">Department</p>
            <p class="font-bold text-[#6e5700]"><?= htmlspecialchars($staffDetails['department']) ?></p>
          </div>
          <div>
            <p class="text-[#43474f] uppercase tracking-widest mb-0.5">Faculty</p>
            <p class="font-bold text-[#001e40]"><?= htmlspecialchars($staffDetails['faculty'] ?: '—') ?></p>
          </div>
        </div>
      </div>
      <a href="register_staff.php"
        class="text-center text-xs text-[#43474f] hover:text-[#001e40] font-semibold transition-colors">
        ← Not you? Go back
      </a>
    </div>

    <div class="lg:col-span-7">
      <div class="bg-[#edf4ff] rounded-2xl p-8 border border-gray-100">
        <h3 class="text-sm font-black text-[#001e40] uppercase tracking-widest mb-6">Set Your Password</h3>
        <form method="POST" class="space-y-5">
          <input type="hidden" name="pf_no" value="<?= htmlspecialchars($pfNo) ?>">

          <div>
            <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">Password</label>
            <div class="relative">
              <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[#43474f] text-lg">lock</span>
              <input type="password" name="password" id="pwd_input" required minlength="6" autofocus
                class="w-full pl-11 pr-11 py-3 bg-white rounded-xl border-none text-[#0b1d2c] placeholder-[#43474f]/40 focus:ring-2 focus:ring-[#001e40] focus:outline-none text-sm"
                placeholder="Min. 6 characters">
              <button type="button"
                onclick="var i=document.getElementById('pwd_input');i.type=i.type==='password'?'text':'password';"
                class="absolute right-4 top-1/2 -translate-y-1/2 text-[#43474f] hover:text-[#001e40]">
                <span class="material-symbols-outlined text-lg">visibility</span>
              </button>
            </div>
          </div>

          <div>
            <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">Confirm Password</label>
            <div class="relative">
              <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[#43474f] text-lg">lock_reset</span>
              <input type="password" name="confirm_password" id="pwd_confirm" required minlength="6"
                class="w-full pl-11 pr-4 py-3 bg-white rounded-xl border-none text-[#0b1d2c] placeholder-[#43474f]/40 focus:ring-2 focus:ring-[#001e40] focus:outline-none text-sm"
                placeholder="Re-enter password">
            </div>
          </div>

          <p id="match_msg" class="text-xs font-bold hidden"></p>

          <div class="pt-2 flex items-center justify-between gap-4">
            <p class="text-[10px] text-[#43474f]/60 font-bold uppercase tracking-widest leading-relaxed">
              Click register to complete setup.
            </p>
            <button type="submit" name="set_password"
              class="bg-[#fecb00] text-[#001e40] px-6 py-3 rounded-xl font-bold text-sm shadow-lg shadow-[#fecb00]/20 hover:bg-[#e6b800] active:scale-[0.98] transition-all flex items-center gap-2 whitespace-nowrap">
              Register
              <span class="material-symbols-outlined text-base">how_to_reg</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="mt-8 text-center">
    <a href="login.php" class="text-[#43474f] hover:text-[#001e40] transition-colors font-semibold text-sm">
      Already registered? <span class="text-[#001e40] underline underline-offset-4">Login to Portal</span>
    </a>
  </div>
</div>

<!-- ── SCRIPTS ─────────────────────────────────────────────────────────────────-->
<script>
  // ── Password match indicator (step 2 only) ────────────────────────────────
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
