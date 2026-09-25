<?php
/**
 * forgot_password.php — Step 1 of the password reset flow.
 *
 * User enters their email / matric / PF_NO. We look up the user, generate
 * an OTP, send it by email (or surface in dev mode), and redirect to
 * verify_otp.php with purpose=password_reset.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
redirectIfLoggedIn();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');

    if (!$identifier) {
        $error = 'Please enter your email, matric number, or PF number.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, email, full_name FROM users WHERE email = ? OR matric_number = ? OR PF_NO = ? LIMIT 1");
        $stmt->execute([$identifier, $identifier, $identifier]);
        $user = $stmt->fetch();

        if (!$user) {
            // Don't reveal whether an account exists — minimal info leak
            $error = 'No account matches that identifier. Please check and try again.';
        } elseif (!$user['email']) {
            $error = 'This account has no email address on file. Please contact the ICT department.';
        } else {
            // Generate OTP for password reset
            $code = storeOtp($user['email'], 'password_reset');
            $mailResult = sendOtpEmail($user['email'], $code, 'password_reset');

            $_SESSION['otp_purpose'] = 'password_reset';
            $_SESSION['registration_email'] = $user['email'];

            $redirectUrl = 'verify_otp.php?email=' . urlencode($user['email']) . '&purpose=password_reset';
            if (!$mailResult['sent']) {
                $redirectUrl .= '&code=' . urlencode($code) . '&mail_failed=1';
            }
            header('Location: ' . BASE_URL . $redirectUrl);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot Password | LASU Result Complaint Portal</title>
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
    <span class="material-symbols-outlined text-[#001e40] text-3xl">lock_reset</span>
  </div>

  <h1 class="text-3xl font-black text-[#001e40] mb-2">Forgot Password</h1>
  <p class="text-[#43474f] text-sm mb-8 max-w-xs">
    Enter your registered email, matric number, or PF number. We'll send a verification code to reset your password.
  </p>

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

  <form method="POST" class="w-full space-y-6">
    <div class="space-y-2">
      <label class="text-xs font-bold text-[#43474f] uppercase tracking-widest text-left ml-1">Email / Matric / PF Number</label>
      <div class="relative">
        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[#43474f] text-lg">badge</span>
        <input type="text" name="identifier" required autofocus
          class="w-full pl-11 pr-4 py-3.5 bg-[#edf4ff] rounded-xl border-none text-[#0b1d2c] placeholder-[#43474f]/40 focus:ring-2 focus:ring-[#001e40] focus:outline-none text-sm"
          placeholder="e.g. 220591011 / LS1234 / you@st.lasu.edu.ng">
      </div>
    </div>

    <button type="submit" class="w-full bg-[#001e40] text-white py-4 px-6 rounded-2xl font-bold text-md shadow-lg shadow-[#001e40]/20 hover:bg-[#003366] active:scale-[0.98] transition-all flex items-center justify-center gap-2">
      <span class="material-symbols-outlined text-base">send</span>
      Send Verification Code
    </button>

    <div class="pt-4 border-t border-gray-100">
      <a href="login.php" class="block w-full py-4 bg-[#edf4ff] rounded-2xl text-[#43474f] font-bold text-sm hover:bg-[#d2e4f9] transition-colors">
        Back to Login
      </a>
    </div>
  </form>
</div>

</body>
</html>
