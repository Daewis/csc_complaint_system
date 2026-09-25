<?php
require_once __DIR__ . '/includes/auth.php';

// ✅ Use the new helper (prevents weird edge cases)
redirectIfLoggedIn();

$error = '';
$success = '';

// Surface any success message coming from registration / password reset
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'registered':   $success = 'Account created successfully. You can now log in.'; break;
        case 'verified':      $success = 'Email verified successfully! You can now log in.'; break;
        case 'password_reset':$success = 'Password updated successfully. Please log in with your new password.'; break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';

    if (login($identifier, $password)) {
        $user = currentUser();
        header('Location: ' . BASE_URL . getRoleDashboard($_SESSION['role'], $user));
        exit;
    } else {
        // Use the more-specific error surfaced by login() if it set one
        $error = $_SESSION['login_error'] ?? 'Invalid credentials. Please check your Matric/PF Number and password.';
        unset($_SESSION['login_error']);
    }
}

// Handle URL errors
$urlError = $_GET['error'] ?? '';
if ($urlError === 'unauthorized') {
    $error = 'You are not authorized to access that page.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="google-site-verification" content="WsPDbEfWnpQQ2OWRyUrsBZfNmyTrmeXWKwb0QcppGmQ" />
  <title>Sign In | LASU Result Complaint Portal</title>
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <style>
    .material-symbols-outlined{
    font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;
}

body{
    font-family:'Inter',sans-serif;
}

/* =========================
   MOBILE RESPONSIVE DESIGN
   ========================= */

@media (max-width:1023px){

    body{
        padding:0;
        align-items:stretch;
    }

    body > div{
        min-height:100vh;
        max-width:100% !important;
        border-radius:0 !important;
        box-shadow:none !important;
    }

    body > div{
        display:flex !important;
        flex-direction:column !important;
    }

    /* HERO SECTION */

    .lg\:col-span-5{
        width:100%;
        padding:24px !important;
        justify-content:flex-start !important;
    }

    .lg\:col-span-5 .flex.items-center.gap-4.mb-12{
        margin-bottom:20px !important;
    }

    .lg\:col-span-5 img{
        width:52px !important;
        height:52px !important;
    }

    .lg\:col-span-5 h1{
        font-size:15px !important;
        line-height:1.3 !important;
    }

    .lg\:col-span-5 h2{
        font-size:28px !important;
        line-height:1.1 !important;
        margin-bottom:12px !important;
    }

    .lg\:col-span-5 p{
        max-width:none !important;
    }

    /* Hide feature list on mobile */

    .lg\:col-span-5 .space-y-6{
        display:none !important;
    }

    /* FORM SECTION */

    .lg\:col-span-7{
        width:100%;
        background:#fff;
        padding:24px !important;
        border-radius:24px 24px 0 0;
        margin-top:-20px;
        flex:1;
    }

    /* Tabs */

    .lg\:col-span-7 .flex.gap-8.mb-12{
        margin-bottom:24px !important;
        gap:20px !important;
    }

    .lg\:col-span-7 .flex.gap-8.mb-12 button{
        font-size:13px !important;
    }

    /* Header */

    #login_header{
        margin-bottom:24px !important;
    }

    #login_title{
        font-size:24px !important;
    }

    /* Inputs */

    input[type="text"],
    input[type="password"]{
        height:52px;
        font-size:14px;
    }

    /* Button */

    button[type="submit"]{
        height:56px;
    }

    /* Registration Card */

    #registration_links{
        margin-top:24px !important;
    }

    #student_reg_link{
        padding:16px !important;
    }

    #student_reg_link .w-12{
        width:42px !important;
        height:42px !important;
    }
}

/* SMALL PHONES */

@media (max-width:480px){

    .lg\:col-span-5{
        padding:20px !important;
    }

    .lg\:col-span-5 h2{
        font-size:24px !important;
    }

    .lg\:col-span-7{
        padding:20px !important;
    }

    .lg\:col-span-7 .flex.gap-8.mb-12{
        gap:12px !important;
    }

    .lg\:col-span-7 .flex.gap-8.mb-12 button{
        font-size:12px !important;
    }
}
  </style>
  <script>
    tailwind.config={theme:{extend:{colors:{
      "primary":"#001e40","secondary-container":"#fecb00","on-secondary-container":"#6e5700",
      "surface":"#f7f9ff","surface-container-lowest":"#ffffff","surface-container-low":"#edf4ff",
      "surface-container-highest":"#d2e4f9","on-surface":"#0b1d2c","on-surface-variant":"#43474f",
      "error-container":"#ffdad6","on-error-container":"#93000a"
    },fontFamily:{body:['Inter']},borderRadius:{DEFAULT:'0.125rem',lg:'0.5rem',xl:'0.75rem',full:'9999px'}}}}
  </script>
</head>
<body class="min-h-screen bg-[#f7f9ff] flex items-center justify-center px-4 py-8">

<div class="w-full max-w-5xl grid grid-cols-1 lg:grid-cols-12 gap-0 rounded-2xl overflow-hidden shadow-[0_40px_80px_rgba(11,29,44,0.15)]">

  <div class="lg:col-span-5 bg-[#001e40] p-12 flex flex-col justify-between text-white relative overflow-hidden">
    <div class="absolute -right-20 -top-20 w-64 h-64 bg-white/5 rounded-full"></div>
    <div class="absolute -left-10 -bottom-10 w-48 h-48 bg-[#fecb00]/10 rounded-full"></div>
    <div class="relative z-10">
      <div class="flex items-center gap-4 mb-12">
        <img src="./assets/img/lasu_logo.jpg" alt="LASU Logo" class="w-16 h-16 rounded-xl object-contain bg-white p-1 shadow-lg flex-shrink-0">
        <div>
          <h1 class="text-xl font-black tracking-tight leading-tight">LASU Result<br/> Complaint Portal</h1>
          <p class="text-xs uppercase tracking-widest opacity-60 mt-0.5">Lagos State University</p>
        </div>
      </div>
      <h2 class="text-4xl font-black leading-tight mb-4">Academic Result<br><span class="text-[#fecb00]">Complaint Portal</span></h2>
      <p class="text-white/70 text-base leading-relaxed max-w-xs">
        Dedicated to maintaining the highest standards of fairness and academic integrity through transparent resolution.
      </p>
    </div>


  <div class="relative z-10 mt-12">
    <div class="space-y-6"> <?php
        $pillars = [
            [
                'icon'  => 'bolt',
                'title' => 'Instant Submission',
                'desc'  => 'Lodge your discrepancies directly to the department in real-time.'
            ],
            [
                'icon'  => 'analytics',
                'title' => 'Transparent Tracking',
                'desc'  => 'Monitor the progress of your complaint through every stage of review.'
            ],
            [
                'icon'  => 'verified_user',
                'title' => 'Verified Resolution',
                'desc'  => 'Official feedback and corrections documented within the portal.'
            ]
        ];

        foreach ($pillars as $p):
        ?>
        <div class="flex items-start gap-4 group"> <div class="bg-white/10 p-3 rounded-lg group-hover:bg-[#fecb00]/20 transition-colors">
                <span class="material-symbols-outlined text-[#fecb00]"><?= $p['icon'] ?></span>
            </div>
            <div>
                <h3 class="text-white font-bold text-base leading-tight"><?= $p['title'] ?></h3>
                <p class="text-white/60 text-sm mt-1"><?= $p['desc'] ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    </div>

  </div>

  <div class="lg:col-span-7 bg-white p-8 lg:p-16 flex flex-col justify-center">

    <div class="flex gap-8 mb-12 border-b border-gray-100">
      <button id="student_tab" onclick="switchTab('student')"
        class="pb-4 text-sm font-bold flex items-center gap-2 transition-all text-primary border-b-2 border-secondary-container">
        <span class="material-symbols-outlined text-base">arrow_forward</span>
        Student Login
      </button>
      <button id="staff_tab" onclick="switchTab('staff')"
        class="pb-4 text-sm font-bold flex items-center gap-2 transition-all text-[#43474f] hover:text-primary">
        <span class="material-symbols-outlined text-base">badge</span>
        Staff Portal
      </button>
    </div>

    <div id="login_header" class="mb-8">
      <h3 id="login_title" class="text-2xl font-black text-[#001e40] mb-1">Welcome back</h3>
      <p id="login_subtitle" class="text-[#43474f] text-sm">Access your academic records and manage complaints.</p>
    </div>

    <?php if ($error): ?>
    <div class="mb-6 flex items-center gap-3 bg-[#ffdad6] text-[#93000a] px-4 py-3 rounded-xl text-sm font-medium border border-[#93000a]/10 animate-pulse">
      <span class="material-symbols-outlined text-base">error</span>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="mb-6 flex items-center gap-3 bg-green-100 text-green-800 px-4 py-3 rounded-xl text-sm font-medium border border-green-800/10">
      <span class="material-symbols-outlined text-base">task_alt</span>
      <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['success']) && $_GET['success'] === 'verified'): ?>
    <div class="mb-6 flex items-center gap-3 bg-green-100 text-green-800 px-4 py-3 rounded-xl text-sm font-medium border border-green-800/10">
      <span class="material-symbols-outlined text-base">task_alt</span>
      Email verified successfully! You can now sign in to your portal.
    </div>
    <?php endif; ?>

    <form method="POST" class="space-y-5">
      <input type="hidden" name="role" id="role_input" value="STUDENT">

      <div>
        <label id="email_label" class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">Matric Number or Email</label>
        <div class="relative">
          <span id="email_icon" class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[#43474f] text-lg">arrow_forward</span>
          <input type="text" name="email" id="email_input" required autofocus
            class="w-full pl-11 pr-4 py-3 bg-[#edf4ff] rounded-xl border-none text-[#0b1d2c] placeholder-[#43474f]/40 focus:ring-2 focus:ring-[#001e40] focus:outline-none text-sm"
            placeholder="e.g. 220591011"
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
      </div>

      <div>
        <div class="flex justify-between items-center mb-2">
          <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider">Password</label>
          <a href="<?= BASE_URL ?>forgot_password.php" class="text-xs font-bold text-primary hover:underline">Forgot Password?</a>
        </div>
        <div class="relative">
          <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[#43474f] text-lg">lock</span>
          <input type="password" name="password" required id="pwd_input"
            class="w-full pl-11 pr-11 py-3 bg-[#edf4ff] rounded-xl border-none text-[#0b1d2c] placeholder-[#43474f]/40 focus:ring-2 focus:ring-[#001e40] focus:outline-none text-sm"
            placeholder="••••••••">
          <button type="button" onclick="var i=document.getElementById('pwd_input');i.type=i.type==='password'?'text':'password';"
            class="absolute right-4 top-1/2 -translate-y-1/2 text-[#43474f] hover:text-[#001e40] transition-colors">
            <span class="material-symbols-outlined text-lg">visibility</span>
          </button>
        </div>
      </div>

      <button type="submit"
        class="w-full bg-[#001e40] text-white py-4 rounded-xl font-bold text-sm shadow-lg shadow-[#001e40]/20 hover:bg-[#003366] active:scale-[0.98] transition-all flex items-center justify-center gap-2 mt-4">
        <span id="submit_icon" class="material-symbols-outlined text-base">login</span>
        <span id="submit_text">Sign In to Portal</span>
      </button>
    </form>

    <div id="registration_links" class="mt-12">
      <div id="student_reg_link" class="p-6 rounded-2xl bg-[#edf4ff] flex items-center justify-between group cursor-pointer hover:bg-[#d2e4f9] transition-colors" onclick="window.location.href='register_student.php'">
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 bg-white rounded-xl flex items-center justify-center shadow-sm">
            <span class="material-symbols-outlined text-[#fecb00] filled" style="font-variation-settings:'FILL' 1">person_add</span>
          </div>
          <div>
            <p class="text-sm font-bold text-[#001e40]">New to the portal?</p>
            <p class="text-xs text-[#43474f]">Create a student account to start.</p>
          </div>
        </div>
        <span class="material-symbols-outlined text-[#43474f] group-hover:translate-x-1 transition-transform">arrow_forward</span>
      </div>

      <div id="staff_reg_link" class="hidden mt-4 text-center">
        <p class="text-sm text-[#43474f]">
          First time on the portal? <a href="register_staff.php" class="text-primary font-bold hover:underline">Register Staff Profile</a>
        </p>
      </div>
    </div>
  </div>
</div>

<script>
  function switchTab(role) {
    const studentTab = document.getElementById('student_tab');
    const staffTab = document.getElementById('staff_tab');
    const roleInput = document.getElementById('role_input');
    const loginTitle = document.getElementById('login_title');
    const loginSubtitle = document.getElementById('login_subtitle');
    const emailLabel = document.getElementById('email_label');
    const emailIcon = document.getElementById('email_icon');
    const emailInput = document.getElementById('email_input');
    const submitText = document.getElementById('submit_text');
    const studentRegLink = document.getElementById('student_reg_link');
    const staffRegLink = document.getElementById('staff_reg_link');

    if (role === 'student') {
      studentTab.className = "pb-4 text-sm font-bold flex items-center gap-2 transition-all text-primary border-b-2 border-secondary-container";
      staffTab.className = "pb-4 text-sm font-bold flex items-center gap-2 transition-all text-[#43474f] hover:text-primary";
      roleInput.value = "STUDENT";
      loginTitle.innerText = "Welcome Back";
      loginSubtitle.innerText = "Access your academic records and manage complaints.";
      emailLabel.innerText = "Matric Number or Email";
      emailIcon.innerText = "arrow_forward";
      emailInput.placeholder = "e.g. 220591011";
      submitText.innerText = "Sign In to Portal";
      studentRegLink.style.display = 'flex';
      staffRegLink.style.display = 'none';
    } else {
      staffTab.className = "pb-4 text-sm font-bold flex items-center gap-2 transition-all text-primary border-b-2 border-secondary-container";
      studentTab.className = "pb-4 text-sm font-bold flex items-center gap-2 transition-all text-[#43474f] hover:text-primary";
      roleInput.value = "STAFF";
      loginTitle.innerText = "Staff Login";
      loginSubtitle.innerText = "Please enter your PF_NO or institutional email.";
      emailLabel.innerText = "PF_NO or Email";
      emailIcon.innerText = "badge";
      emailInput.placeholder = "e.g. LS1234";
      submitText.innerText = "Login to Portal";
      studentRegLink.style.display = 'none';
      staffRegLink.style.display = 'block';
      staffRegLink.classList.remove('hidden');
    }
  }
</script>

</body>
</html>
