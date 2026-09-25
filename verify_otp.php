<?php
/**
 * verify_otp.php — Pure-PHP OTP verification
 *
 * Replaces the old Supabase OTP flow. The OTP code is stored in the local
 * `otp_codes` table with an expiry timestamp. Two purposes are supported:
 *
 *   • 'registration'    — completing student sign-up (data from user_data
 *                         JSON column is used to create the final users row)
 *   • 'password_reset'  — completing password reset (user is redirected to
 *                         reset_password.php?email=...&code=... to set the
 *                         new password)
 *
 * If mail() fails on the host (common on InfinityFree free tier), the
 * register_student.php and forgot_password.php controllers append the
 * generated code as `?code=...` so it can be surfaced on screen during
 * testing. We honour that here by pre-filling the OTP inputs.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$email = $_GET['email'] ?? $_SESSION['registration_email'] ?? '';
$email = trim($email);

if (!$email) {
    header('Location: login.php');
    exit;
}

$error   = '';
$success = '';

// Determine purpose from session OR URL OR DB — default to 'registration'
$purpose = $_GET['purpose'] ?? ($_SESSION['otp_purpose'] ?? 'registration');

// Surfaces the OTP code in dev/test when mail() failed (InfinityFree blocks it)
$devHintCode = isset($_GET['code']) ? trim($_GET['code']) : '';
$mailFailed = isset($_GET['mail_failed']) && $_GET['mail_failed'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['verify'])) {
        $otp = implode('', $_POST['otp'] ?? []);

        if (strlen($otp) !== 6) {
            $error = 'Please enter the 6-digit verification code.';
        } else {
            $result = verifyOtp($email, $purpose, $otp);

            if (!$result['ok']) {
                $error = $result['error'] ?? 'Verification failed.';
            } else {
                // ── Verification succeeded ────────────────────────────────
                if ($purpose === 'registration') {
                    $userData = json_decode($result['user_data'] ?? '{}', true);

                    if (empty($userData) || empty($userData['email'])) {
                        $error = 'Registration data is missing. Please start again.';
                    } else {
                        try {
                            $db = getDB();
                            // Defensive: don't double-insert if the row already
                            // exists from a previous attempt.
                            $exists = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                            $exists->execute([$userData['email']]);
                            if ($exists->fetch()) {
                                // Mark verified and move on
                                $db->prepare("UPDATE users SET is_verified = 1, is_active = 1, password_hash = COALESCE(?, password_hash) WHERE email = ?")
                                   ->execute([$userData['password_hash'] ?? null, $userData['email']]);
                            } else {
                                $db->prepare("
                                    INSERT INTO users (
                                        email, full_name, role, password_hash,
                                        department, faculty, level, matric_number,
                                        is_active, is_verified
                                    )
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1)
                                ")->execute([
                                    $userData['email'],
                                    $userData['full_name'],
                                    $userData['role'] ?? 'student',
                                    $userData['password_hash'],
                                    $userData['department'] ?? null,
                                    $userData['faculty'] ?? null,
                                    $userData['level'] ?? null,
                                    $userData['matric_no'] ?? null,
                                ]);
                            }

                            // Audit log (best-effort)
                            try {
                                $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                                $stmt->execute([$userData['email']]);
                                $uid = (int)$stmt->fetchColumn();
                                if ($uid) {
                                    logAudit(0, $uid, 'student_registered', getClientIP(),
                                        "Student {$userData['email']} verified and registered.");
                                }
                            } catch (Throwable $e) { /* non-fatal */ }

                            unset($_SESSION['registration_email'], $_SESSION['otp_purpose']);
                            header('Location: login.php?success=verified');
                            exit;

                        } catch (PDOException $e) {
                            $error = "Database error: " . $e->getMessage();
                        }
                    }
                } else {
                    // password_reset: forward to reset_password.php with the
                    // verified code so the reset form can pre-fill it.
                    unset($_SESSION['otp_purpose']);
                    header('Location: ' . BASE_URL . 'reset_password.php?email=' . urlencode($email) . '&code=' . urlencode($otp));
                    exit;
                }
            }
        }
    } elseif (isset($_POST['resend'])) {
        // Re-issue a new code for the same purpose.
        // For 'registration' we need the user_data; pull it from the most recent
        // otp row for this email/purpose so we don't lose the payload.
        $db = getDB();
        $stmt = $db->prepare("SELECT user_data FROM otp_codes WHERE email = ? AND purpose = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$email, $purpose]);
        $row = $stmt->fetch();
        $userDataJson = $row['user_data'] ?? null;

        $code = storeOtp($email, $purpose, $userDataJson);
        $mailResult = sendOtpEmail($email, $code, $purpose);

        if (!$mailResult['sent']) {
            // Surface the new code in dev mode again
            header('Location: ' . BASE_URL . 'verify_otp.php?email=' . urlencode($email) . '&purpose=' . urlencode($purpose) . '&code=' . urlencode($code) . '&mail_failed=1');
            exit;
        }
        $success = 'A new verification code has been sent to your email.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Verify Email | LASU Result Complaint Portal</title>
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <style>
    .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
    body { font-family: 'Inter', sans-serif; }
    .otp-input:focus { ring: 2px solid #001e40; border-color: #001e40; }
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
  <div class="w-20 h-20 bg-white rounded-2xl flex items-center justify-center mb-8 shadow-sm overflow-hidden border border-gray-100">
    <?php
    $logoPath = __DIR__ . '/assets/img/lasu_logo.jpg';
    if (file_exists($logoPath)):
    ?>
        <img src="<?= BASE_URL ?>assets/img/lasu_logo.jpg" alt="LASU Logo" class="w-full h-full object-contain p-2" />
    <?php else: ?>
        <span class="material-symbols-outlined text-[#001e40] text-3xl">school</span>
    <?php endif; ?>
  </div>

  <h1 class="text-3xl font-black text-[#001e40] mb-4">
    <?= $purpose === 'password_reset' ? 'Reset Password' : 'Verify Email' ?>
  </h1>

  <p class="text-[#43474f] font-medium mb-2">We've sent a 6-digit code to</p>
  <p class="text-[#001e40] font-bold mb-2 break-all italic text-sm"><?= htmlspecialchars($email) ?></p>
  <p class="text-[10px] text-[#43474f]/60 font-bold uppercase tracking-widest mb-6">Check your spam folder if it's missing</p>

  <?php if ($mailFailed && $devHintCode): ?>
  <div class="mb-6 w-full p-4 rounded-xl text-xs font-bold bg-[#ffe08b]/40 text-[#745b00] flex items-start gap-2 text-left border border-[#fecb00]">
    <span class="material-symbols-outlined text-base flex-shrink-0 mt-0.5">build</span>
    <div>
      <p class="font-black uppercase tracking-wider mb-1">Dev mode — mail() unavailable</p>
      <p>Email sending is disabled on this host. Your verification code is:</p>
      <p class="text-2xl font-black tracking-widest my-1 text-[#001e40]"><?= htmlspecialchars($devHintCode) ?></p>
      <p class="text-[10px] normal-case font-medium">This would normally arrive by email. Set up SMTP (or use a host with mail() enabled) to remove this notice.</p>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="mb-6 w-full p-4 rounded-xl text-xs font-bold bg-[#ffdad6] text-[#93000a] flex items-center gap-2">
    <span class="material-symbols-outlined text-base">error</span>
    <div class="text-left"><?= htmlspecialchars($error) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($success): ?>
  <div class="mb-6 w-full p-4 rounded-xl text-xs font-bold bg-green-100 text-green-800 flex items-center gap-2 text-left">
    <span class="material-symbols-outlined text-base">check_circle</span>
    <?= htmlspecialchars($success) ?>
  </div>
  <?php endif; ?>

  <form method="POST" class="w-full space-y-8">
    <input type="hidden" name="purpose" value="<?= htmlspecialchars($purpose) ?>">
    <div class="space-y-4">
      <label class="text-xs font-bold text-[#43474f] uppercase tracking-widest">Verification Code</label>
      <div class="flex gap-2 justify-center">
        <?php
        // Pre-fill the 6 boxes from $devHintCode when present (dev mode)
        for ($i = 0; $i < 6; $i++):
            $val = ($devHintCode && isset($devHintCode[$i])) ? $devHintCode[$i] : '';
        ?>
          <input
            type="text"
            name="otp[]"
            inputmode="numeric"
            pattern="[0-9]*"
            maxLength="1"
            value="<?= htmlspecialchars($val) ?>"
            class="otp-field w-12 h-14 text-center text-2xl font-black bg-[#edf4ff] border-none rounded-xl focus:ring-2 text-[#001e40] shadow-sm"
            placeholder="-"
            required
          >
        <?php endfor; ?>
      </div>
    </div>

    <div class="space-y-6">
      <button type="submit" name="verify" class="w-full bg-[#001e40] text-white py-4 px-6 rounded-2xl font-bold text-md shadow-lg shadow-[#001e40]/20 hover:bg-[#003366] active:scale-[0.98] transition-all">
        <?= $purpose === 'password_reset' ? 'Verify & Continue' : 'Verify & Complete' ?>
      </button>

      <button type="submit" name="resend" formnovalidate class="text-[#001e40] font-bold text-sm hover:underline transition-all">
        Resend code?
      </button>

      <div class="pt-4 border-t border-gray-100">
        <a href="login.php" class="block w-full py-4 bg-[#edf4ff] rounded-2xl text-[#43474f] font-bold text-sm hover:bg-[#d2e4f9] transition-colors">
          Back to Login
        </a>
      </div>
    </div>
  </form>
</div>

<script>
  const fields = document.querySelectorAll('.otp-field');

  fields.forEach((field, index) => {
    field.addEventListener('input', (e) => {
      if (e.inputType === 'deleteContentBackward') return;
      if (field.value.length === 1 && index < fields.length - 1) {
        fields[index + 1].focus();
      }
    });

    field.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !field.value && index > 0) {
        fields[index - 1].focus();
      }
    });
  });

  // If dev hint pre-filled the boxes, jump focus to the last filled input
  (function () {
    for (let i = 0; i < fields.length; i++) {
      if (!fields[i].value) { fields[i].focus(); break; }
    }
  })();
</script>

</body>
</html>
