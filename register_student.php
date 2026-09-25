<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

$error   = '';
$success = '';

// Faculties/departments pulled live from the DB
$facultyRows = $db->query("SELECT id, name FROM faculties ORDER BY name")->fetchAll();
$departmentRows = $db->query("SELECT id, faculty_id, name FROM departments ORDER BY name")->fetchAll();

// Build the shape the JS expects: { "Faculty Name": ["Dept1", "Dept2", ...] }
$faculties = [];
foreach ($facultyRows as $f) {
    $faculties[$f['name']] = [];
}
foreach ($departmentRows as $d) {
    foreach ($facultyRows as $f) {
        if ($f['id'] == $d['faculty_id']) {
            $faculties[$f['name']][] = $d['name'];
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName      = trim($_POST['first_name'] ?? '');
    $middleName     = trim($_POST['middle_name'] ?? '');
    $surname        = trim($_POST['surname'] ?? '');
    $matricNo       = trim($_POST['matric_no'] ?? '');
    $faculty        = trim($_POST['faculty'] ?? '');
    $department     = trim($_POST['department'] ?? '');
    $level          = trim($_POST['level'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $password       = $_POST['password'] ?? '';
    $confirmPassword= $_POST['confirm_password'] ?? '';

    // ── Validation ────────────────────────────────────────────────────────
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif (empty($firstName) || empty($surname) || empty($matricNo) || empty($faculty) || empty($department) || empty($level)) {
        $error = 'Please fill in all required fields.';
    } else {
        // Check for pre-existing user with same email or matric number
        // (these columns have unique constraints)
        $existsStmt = $db->prepare("SELECT id FROM users WHERE email = ? OR matric_number = ? LIMIT 1");
        $existsStmt->execute([$email, $matricNo]);
        if ($existsStmt->fetch()) {
            $error = 'An account with this email or matric number already exists. Please log in or use the forgot-password flow.';
        }
    }

    // ── Stash user data + generate OTP ──────────────────────────────────
    if (!$error) {
        $fullName = trim($firstName . ' ' . $middleName . ' ' . $surname);
        $userData = [
            'role'         => 'student',
            'first_name'   => $firstName,
            'middle_name'  => $middleName,
            'surname'      => $surname,
            'full_name'    => $fullName,
            'matric_no'    => $matricNo,
            'faculty'      => $faculty,
            'department'   => $department,
            'level'        => $level,
            'email'        => $email,
            'password_hash'=> password_hash($password, PASSWORD_BCRYPT),
        ];

        $code = storeOtp($email, 'registration', json_encode($userData));
        $mailResult = sendOtpEmail($email, $code, 'registration');

        // Persist email across the verify-otp redirect
        $_SESSION['registration_email'] = $email;

        // If mail() failed (e.g. on InfinityFree free tier), pass the code
        // through the query string so the user can complete verification
        // during testing. Remove `&code=...` once real SMTP works.
        $redirectUrl = 'verify_otp.php?email=' . urlencode($email);
        if (!$mailResult['sent']) {
            $redirectUrl .= '&code=' . urlencode($code) . '&mail_failed=1';
        }
        header('Location: ' . BASE_URL . $redirectUrl);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Student Registration | LASU Result Complaint Portal</title>
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

.custom-scrollbar::-webkit-scrollbar{
  width:6px;
}

.custom-scrollbar::-webkit-scrollbar-track{
  background:transparent;
}

.custom-scrollbar::-webkit-scrollbar-thumb{
  background:#d2e4f9;
  border-radius:10px;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover{
  background:#001e40;
}

/* ===================================================
   MOBILE RESPONSIVE FIX
=================================================== */

@media (max-width:1023px){

  body{
    padding:0 !important;
    display:block !important;
  }

  .mobile-wrapper{
    border-radius:0 !important;
    box-shadow:none !important;
    min-height:100dvh;
  }

  /* HERO */

  .mobile-hero{
    padding:1.25rem !important;
  }

  .mobile-hero .logo-row{
    margin-bottom:1rem !important;
  }

  .mobile-hero img{
    width:3rem !important;
    height:3rem !important;
  }

  .mobile-hero h1{
    font-size:0.95rem !important;
    line-height:1.3 !important;
  }

  .mobile-hero h2{
    font-size:1.5rem !important;
    line-height:1.2 !important;
    margin-bottom:.5rem !important;
  }

  .mobile-hero p{
    font-size:.8rem !important;
  }

  .mobile-pillars{
    display:none !important;
  }

  /* FORM */

  .mobile-form{
    padding:1.5rem !important;
    border-radius:1.5rem 1.5rem 0 0;
    margin-top:-1rem;
    max-height:none !important;
    overflow:visible !important;
  }

  .mobile-form h2{
    font-size:1.6rem !important;
  }

  .mobile-form section{
    gap:1rem;
  }

  .mobile-form .grid{
    gap:1rem !important;
  }

  .mobile-form input,
  .mobile-form select{
    padding:.9rem !important;
        padding-left: 2.5rem !important;
    font-size:14px;
  }

  .mobile-form button[type="submit"]{
    padding:1rem !important;
    font-size:15px !important;
  }

  .mobile-footer{
    flex-direction:column;
    align-items:flex-start !important;
    gap:1rem;
  }
}

/* SMALL DEVICES */

@media (max-width:480px){

  .mobile-form{
    padding:1.25rem !important;
  }

  .mobile-form h2{
    font-size:1.35rem !important;
  }

  .mobile-hero h2{
    font-size:1.3rem !important;
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
<body class="min-h-screen bg-[#f7f9ff] lg:flex lg:items-center lg:justify-center lg:px-4 lg:py-8">

<div class="mobile-wrapper w-full max-w-6xl grid grid-cols-1 lg:grid-cols-12 gap-0 rounded-2xl overflow-hidden shadow-[0_40px_80px_rgba(11,29,44,0.15)]">

  <!-- ── LEFT PANEL (mirrors login.php exactly) ────────────────────────────── -->
  <div class="mobile-hero lg:col-span-5 bg-[#001e40] p-12 flex flex-col justify-between text-white relative overflow-hidden">
    <div class="absolute -right-20 -top-20 w-64 h-64 bg-white/5 rounded-full"></div>
    <div class="absolute -left-10 -bottom-10 w-48 h-48 bg-[#fecb00]/10 rounded-full"></div>

    <div class="relative z-10">
      <!-- Logo + Portal name -->
      <div class="logo-row flex items-center gap-4 mb-12">
        <img src="./assets/img/lasu_logo.jpg" alt="LASU Logo"
             class="w-16 h-16 rounded-xl object-contain bg-white p-1 shadow-lg flex-shrink-0">
        <div>
          <h1 class="text-xl font-black tracking-tight leading-tight">LASU Result<br/> Complaint Portal</h1>
          <p class="text-xs uppercase tracking-widest opacity-60 mt-0.5">Lagos State University</p>
        </div>
      </div>

      <h2 class="text-4xl font-black leading-tight mb-4">
        Academic Result<br><span class="text-[#fecb00]">Complaint Portal</span>
      </h2>
      <p class="text-white/70 text-base leading-relaxed max-w-xs">
        Dedicated to maintaining the highest standards of fairness and academic integrity through transparent resolution.
      </p>
    </div>

    <!-- Feature pillars -->
    <div class="mobile-pillars relative z-10 mt-12">
      <div class="space-y-6">
        <?php
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
        <div class="flex items-start gap-4 group">
          <div class="bg-white/10 p-3 rounded-lg group-hover:bg-[#fecb00]/20 transition-colors">
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


  <main class="mobile-form lg:col-span-7 p-8 lg:p-16 flex flex-col justify-start bg-white lg:overflow-y-auto lg:max-h-screen custom-scrollbar">
    <div class="mb-12">
      <h2 class="text-3xl font-black text-[#001e40] mb-2">Create Account</h2>
      <p class="text-[#43474f] text-sm">Enter your official academic details below.</p>
    </div>

    <?php if ($error): ?>
    <div class="mb-8 p-4 rounded-xl text-sm font-bold bg-[#ffdad6] text-[#93000a] flex items-center gap-3">
      <span class="material-symbols-outlined text-base">error</span>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="space-y-10">
      <section class="space-y-6">
        <div class="flex items-center gap-3">
          <span class="w-8 h-8 rounded-lg bg-[#001e40] flex items-center justify-center text-white">
            <span class="material-symbols-outlined text-base">person</span>
          </span>
          <h3 class="font-bold text-lg text-[#001e40]">Personal Identity</h3>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div class="space-y-2">
            <label class="text-xs font-bold text-[#43474f] uppercase tracking-wider ml-1">First Name</label>
            <input type="text" name="first_name" required placeholder="e.g. Olawale"
              class="w-full bg-[#edf4ff] border-none rounded-xl p-4 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] placeholder-[#43474f]/40"
              value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
          </div>
          <div class="space-y-2">
            <label class="text-xs font-bold text-[#43474f] uppercase tracking-wider ml-1">Middle Name</label>
            <input type="text" name="middle_name" placeholder="e.g. Babatunde"
              class="w-full bg-[#edf4ff] border-none rounded-xl p-4 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] placeholder-[#43474f]/40"
              value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
          </div>
          <div class="md:col-span-2 space-y-2">
            <label class="text-xs font-bold text-[#43474f] uppercase tracking-wider ml-1">Surname</label>
            <input type="text" name="surname" required placeholder="e.g. Adeyemi"
              class="w-full bg-[#edf4ff] border-none rounded-xl p-4 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] placeholder-[#43474f]/40"
              value="<?= htmlspecialchars($_POST['surname'] ?? '') ?>">
          </div>
        </div>
      </section>

      <section class="space-y-6">
        <div class="flex items-center gap-3">
          <span class="w-8 h-8 rounded-lg bg-[#001e40] flex items-center justify-center text-white">
            <span class="material-symbols-outlined text-base">school</span>
          </span>
          <h3 class="font-bold text-lg text-[#001e40]">Academic Records</h3>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div class="space-y-2">
            <label class="text-xs font-bold text-[#43474f] uppercase tracking-wider ml-1">Matric Number</label>
            <input type="text" name="matric_no" required placeholder="210591048" maxLength="9"
              class="w-full bg-[#edf4ff] border-none rounded-xl p-4 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] placeholder-[#43474f]/40"
              value="<?= htmlspecialchars($_POST['matric_no'] ?? '') ?>">
          </div>
          <div class="space-y-2">
  <label class="text-xs font-bold text-[#43474f] uppercase tracking-wider ml-1">Level</label>
  <select name="level" required
    class="w-full bg-[#edf4ff] border-none rounded-xl p-4 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] appearance-none cursor-pointer">
    <option value="" disabled selected>Select Level</option>

    <?php for($i=100; $i<=600; $i+=100): ?>
      <option value="<?= $i ?>" <?= (($_POST['level'] ?? '') == $i) ? 'selected' : '' ?>>
        <?= $i ?>
      </option>
    <?php endfor; ?>

    <option value="others" <?= (($_POST['level'] ?? '') == 'others') ? 'selected' : '' ?>>
      Others
    </option>
  </select>
</div>
          <div class="space-y-2">
            <label class="text-xs font-bold text-[#43474f] uppercase tracking-wider ml-1">Faculty</label>
            <select name="faculty" id="faculty_select" required onchange="updateDepartments()"
              class="w-full bg-[#edf4ff] border-none rounded-xl p-4 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] appearance-none cursor-pointer">
              <option value="" disabled selected>Select Faculty</option>
              <?php foreach($faculties as $fac => $deps): ?>
                <option value="<?= htmlspecialchars($fac) ?>" <?= (($_POST['faculty'] ?? '') == $fac) ? 'selected' : '' ?>><?= htmlspecialchars($fac) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="space-y-2">
            <label class="text-xs font-bold text-[#43474f] uppercase tracking-wider ml-1">Department</label>
            <select name="department" id="department_select" required
              class="w-full bg-[#edf4ff] border-none rounded-xl p-4 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] appearance-none cursor-pointer">
              <option disabled selected>Select Department</option>
            </select>
          </div>
          <div class="md:col-span-2 space-y-2">
  <label class="block text-xs font-bold text-[#43474f] uppercase tracking-wider ml-1 mb-2">
    School Email or Personal Email
</label>

<div class="relative group">
    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[#43474f] text-lg">
        mail
    </span>

    <input
        type="email"
        name="email"
        required
        placeholder="username@st.lasu.edu.ng"
        class="w-full pl-14 pr-4 py-4 bg-[#edf4ff] border-none rounded-xl text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] placeholder-[#43474f]/40"
        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
    >
</div>

    <p class="text-[11px] text-[#43474f]/60 mt-1 ml-1">
        Use your LASU email address. Personal email addresses are temporarily accepted during testing for OTP verification.
    </p>
</div>
        </div>
      </section>

      <section class="space-y-6">
  <div class="flex items-center gap-3">
    <span class="w-8 h-8 rounded-lg bg-[#001e40] flex items-center justify-center text-white">
      <span class="material-symbols-outlined text-base">lock</span>
    </span>
    <h3 class="font-bold text-lg text-[#001e40]">Security Credentials</h3>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <div class="space-y-2 relative">
      <label class="text-xs font-bold text-[#43474f] uppercase tracking-wider ml-1">Password</label>
      <input type="password" id="password" name="password" required placeholder="••••••••"
        class="w-full bg-[#edf4ff] border-none rounded-xl p-4 pr-12 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] placeholder-[#43474f]/40">
      <button type="button" onclick="togglePassword('password')"
        class="absolute right-4 bottom-4 text-[#43474f] hover:text-[#001e40] transition-colors">
        <span class="material-symbols-outlined text-lg">visibility</span>
      </button>
    </div>

    <div class="space-y-2 relative">
      <label class="text-xs font-bold text-[#43474f] uppercase tracking-wider ml-1">Confirm Password</label>
      <input type="password" id="confirm_password" name="confirm_password" required placeholder="••••••••"
        class="w-full bg-[#edf4ff] border-none rounded-xl p-4 pr-12 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] placeholder-[#43474f]/40">
      <button type="button" onclick="togglePassword('confirm_password')"
        class="absolute right-4 bottom-4 text-[#43474f] hover:text-[#001e40] transition-colors">
        <span class="material-symbols-outlined text-lg">visibility</span>
      </button>
    </div>
  </div>
</section>



      <div class="pt-8 space-y-6">
        <button type="submit"
          class="w-full bg-[#001e40] text-white py-5 px-6 rounded-xl font-bold text-lg flex items-center justify-center gap-3 hover:bg-[#003366] transition-all shadow-lg shadow-[#001e40]/20 active:scale-[0.98]">
          Continue to Verification
          <span class="material-symbols-outlined">arrow_forward</span>
        </button>
        <div class="mobile-footer flex items-center justify-between">
          <a href="login.php" class="flex items-center gap-2 text-[#001e40] font-bold hover:gap-4 transition-all">
            <span class="material-symbols-outlined">arrow_back</span>
            Back to Login
          </a>
          <p class="text-sm text-[#43474f]">
            Need help? <a href="#" class="text-[#fecb00] font-bold">Contact ICT Support</a>
          </p>
        </div>
      </div>
    </form>
  </main>
</div>

<script>
  // Safely pass PHP data to JS
  const facultyData = <?= json_encode($faculties) ?>;
  // Use json_encode for the department too to prevent quote/null issues
  const selectedDept = <?= json_encode($department ?? '') ?>;

  const facultySelect = document.getElementById('faculty_select');
  const departmentSelect = document.getElementById('department_select');

  function updateDepartments() {
    const selectedFaculty = facultySelect.value;
    const departments = facultyData[selectedFaculty] || [];

    // Clear current options
    departmentSelect.innerHTML = '<option disabled value="">Select Department</option>';

    // Add new options
    departments.forEach(dept => {
      const option = document.createElement('option');
      option.value = dept;
      option.textContent = dept;

      // Compare against the safely encoded variable
      if (dept === selectedDept) {
        option.selected = true;
      }
      departmentSelect.appendChild(option);
    });
  }

  // Initialize departments if faculty is pre-selected
  if (facultySelect.value && facultySelect.value !== "Select Faculty") {
    updateDepartments();
  }


  function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    input.type = input.type === 'password' ? 'text' : 'password';
  }

</script>

</body>
</html>
