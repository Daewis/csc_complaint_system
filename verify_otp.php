<?php
/**
 * verify_otp.php — Pure-PHP OTP verification
 *
 * The OTP code is stored in the local `otp_codes` table with an expiry
 * timestamp. Two purposes are supported:
 *
 *   • 'registration'    — completing student sign-up (data from the user_data
 *                         JSON column is used to create the final users row)
 *   • 'password_reset'  — completing password reset. On success a short-lived
 *                         session flag is set and the user is sent to
 *                         reset_password.php (no code is ever put in the URL).
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

// Determine purpose from URL, POST, or session — default to 'registration'
$purpose = $_GET['purpose'] ?? $_POST['purpose'] ?? ($_SESSION['otp_purpose'] ?? 'registration');
if (!in_array($purpose, ['registration', 'password_reset'], true)) {
    $purpose = 'registration';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['verify'])) {
        $otp = preg_replace('/\D/', '', implode('', $_POST['otp'] ?? []));

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
                            error_log('Registration DB error: ' . $e->getMessage());
                            $error = 'Something went wrong while creating your account. Please try again.';
                        }
                    }
                } else {
                    // password_reset: set a short-lived session flag instead of
                    // passing the code in the URL.
                    unset($_SESSION['otp_purpose']);
                    $_SESSION['reset_email']       = $email;
                    $_SESSION['reset_verified_at'] = time();
                    header('Location: ' . BASE_URL . 'reset_password.php');
                    exit;
                }
            }
        }
    } elseif (isset($_POST['resend'])) {
        // Re-issue a new code for the same purpose, keeping the registration
        // payload from the most recent otp row.
        $db = getDB();
        $stmt = $db->prepare("SELECT user_data FROM otp_codes WHERE email = ? AND purpose = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$email, $purpose]);
        $row = $stmt->fetch();
        $userDataJson = $row['user_data'] ?? null;

        $code = storeOtp($email, $purpose, $userDataJson);
        $mailResult = sendOtpEmail($email, $code, $purpose);

        if ($mailResult['sent']) {
            $success = 'A new verification code has been sent to your email.';
        } else {
            error_log('OTP mail failed (resend): ' . ($mailResult['error'] ?? 'unknown'));
            $error = 'We could not send the code. Please try again shortly.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Verify Email | CSC Result Complaint Portal</title>
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
        <?php for ($i = 0; $i < 6; $i++): ?>
          <input
            type="text"
            name="otp[]"
            inputmode="numeric"
            pattern="[0-9]*"
            maxlength="1"
            autocomplete="one-time-code"
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
      field.value = field.value.replace(/\D/g, '').slice(0, 1);
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

    // Paste a full 6-digit code into any box
    field.addEventListener('paste', (e) => {
      const digits = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, fields.length);
      if (!digits) return;
      e.preventDefault();
      digits.split('').forEach((d, i) => { fields[i].value = d; });
      fields[Math.min(digits.length, fields.length - 1)].focus();
    });
  });

  if (fields.length) fields[0].focus();
</script>

</body>
</html>