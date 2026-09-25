<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Under Maintenance | LASU Result Complaint Portal</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; }
    .material-symbols-outlined { font-variation-settings: 'wght' 400, 'opsz' 24; }

    @keyframes spin-slow { to { transform: rotate(360deg); } }
    @keyframes pulse-soft {
      0%, 100% { transform: scale(1); opacity: 0.2; }
      50%       { transform: scale(1.05); opacity: 0.05; }
    }
    @keyframes float {
      0%, 100% { transform: translateY(0px); }
      50%       { transform: translateY(-8px); }
    }
    @keyframes countdown-tick {
      0%   { transform: scale(1); }
      50%  { transform: scale(1.05); }
      100% { transform: scale(1); }
    }
    .spin-slow    { animation: spin-slow 12s linear infinite; }
    .pulse-soft   { animation: pulse-soft 4s ease-in-out infinite; }
    .float-anim   { animation: float 5s ease-in-out infinite; }
    .tick-anim    { animation: countdown-tick 0.3s ease-in-out; }
  </style>
</head>
<body class="bg-[#0b1329] min-h-screen flex flex-col items-center justify-between px-6 py-8 relative overflow-x-hidden selection:bg-[#fecb00] selection:text-[#0b1329]">

  <div class="fixed inset-0 pointer-events-none overflow-hidden z-0">
    <div class="absolute top-[-10%] left-[-20%] w-[600px] h-[600px] rounded-full bg-[#fecb00]/5 blur-[120px]"></div>
    <div class="absolute bottom-[10%] right-[-10%] w-[500px] h-[500px] rounded-full bg-blue-500/5 blur-[150px]"></div>
    <div class="pulse-soft absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[700px] h-[700px] rounded-full border border-white/[0.02]"></div>
  </div>

  <header class="w-full max-w-5xl relative z-10 flex justify-between items-center border-b border-white/[0.06] pb-5 mb-8 lg:mb-0">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 bg-gradient-to-br from-white/10 to-white/[0.02] border border-white/10 rounded-xl flex items-center justify-center shadow-inner">
        <span class="material-symbols-outlined text-[#fecb00] text-lg">school</span>
      </div>
      <div>
        <p class="text-white font-extrabold text-sm tracking-tight uppercase leading-none">LASU Result</p>
        <p class="text-white/40 text-[9px] uppercase tracking-[0.2em] font-bold mt-0.5">Complaint Portal</p>
      </div>
    </div>
    <span class="text-[10px] text-white/30 uppercase tracking-widest font-semibold border border-white/10 rounded-full px-3 py-1 bg-white/[0.02]">System Status: Updating</span>
  </header>

  <main class="w-full max-w-5xl relative z-10 my-auto grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-16 items-center">

    <div class="lg:col-span-7 text-center lg:text-left space-y-6">
      <div class="inline-flex items-center gap-2 bg-[#fecb00]/10 border border-[#fecb00]/20 text-[#fecb00] text-[10px] font-bold px-3 py-1.5 rounded-full uppercase tracking-widest">
        <span class="w-1.5 h-1.5 rounded-full bg-[#fecb00] animate-pulse"></span>
        Optimizations In Progress
      </div>

      <h1 class="text-white font-black text-3xl md:text-5xl tracking-tight leading-[1.15]">
        We are upgrading your <br class="hidden md:block">
        <span class="text-transparent bg-clip-text bg-gradient-to-r from-[#fecb00] to-yellow-200">portal experience.</span>
      </h1>

      <p class="text-white/50 text-sm md:text-base leading-relaxed max-w-xl mx-auto lg:mx-0">
        The system is momentarily down for scheduled system adjustments. We are fine-tuning performance parameters to ensure seamless discrepancy management processing.
      </p>

      <div class="pt-4 max-w-md mx-auto lg:mx-0">
        <p class="text-white/40 text-[10px] font-bold uppercase tracking-[0.2em] mb-3 text-center lg:text-left">Estimated Completion</p>
        <div class="grid grid-cols-3 gap-3" id="countdown">
          <div class="bg-gradient-to-b from-white/[0.05] to-transparent border border-white/10 rounded-xl p-3 text-center backdrop-blur-sm">
            <span id="cd-hours" class="text-white font-bold text-2xl tabular-nums block leading-tight">00</span>
            <span class="text-white/30 text-[9px] uppercase tracking-widest font-medium mt-1 block">Hours</span>
          </div>
          <div class="bg-gradient-to-b from-white/[0.05] to-transparent border border-white/10 rounded-xl p-3 text-center backdrop-blur-sm">
            <span id="cd-minutes" class="text-white font-bold text-2xl tabular-nums block leading-tight">00</span>
            <span class="text-white/30 text-[9px] uppercase tracking-widest font-medium mt-1 block">Minutes</span>
          </div>
          <div class="bg-gradient-to-b from-white/[0.05] to-transparent border border-white/10 rounded-xl p-3 text-center backdrop-blur-sm">
            <span id="cd-seconds" class="text-white font-bold text-2xl tabular-nums block leading-tight text-[#fecb00]">00</span>
            <span class="text-[#fecb00]/50 text-[9px] uppercase tracking-widest font-medium mt-1 block">Seconds</span>
          </div>
        </div>
      </div>
    </div>

    <div class="lg:col-span-5 space-y-5 w-full max-w-md mx-auto">

      <div class="hidden lg:flex justify-center items-center mb-2">
        <div class="relative w-24 h-24 float-anim flex items-center justify-center">
          <div class="spin-slow absolute inset-0 rounded-full border-2 border-dashed border-white/10"></div>
          <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-[#fecb00] to-yellow-500 flex items-center justify-center shadow-lg shadow-[#fecb00]/10">
            <span class="material-symbols-outlined text-[#0b1329] text-2xl" style="font-variation-settings:'FILL' 1">architecture</span>
          </div>
        </div>
      </div>

      <div class="bg-white/[0.02] border border-white/[0.06] rounded-2xl p-5 backdrop-blur-md shadow-2xl">
        <p class="text-white/40 text-[10px] font-bold uppercase tracking-[0.2em] mb-4 flex justify-between items-center">
          <span>Deployment Checklist</span>
          <span class="inline-block w-2 h-2 rounded-full bg-green-500 animate-ping"></span>
        </p>
        <div class="space-y-3.5">
          <?php
          $steps = [
            ['Database optimisation',      'done'],
            ['Security patches applied',   'done'],
            ['New feature deployment',     'active'],
            ['Final testing & QA',         'pending'],
            ['System back online',         'pending'],
          ];
          foreach ($steps as [$label, $state]):
            $text   = $state === 'done'   ? 'text-white/40 line-through' : ($state === 'active' ? 'text-white font-medium' : 'text-white/25');
            $icon   = $state === 'done'   ? 'check_circle'               : ($state === 'active' ? 'published_with_changes' : 'circle');
            $icol   = $state === 'done'   ? 'text-green-400/60'          : ($state === 'active' ? 'text-[#fecb00] spin-slow' : 'text-white/10');
            $fill   = $state === 'done'   ? "font-variation-settings:'FILL' 1" : "";
          ?>
          <div class="flex items-center justify-between border-b border-white/[0.02] pb-2.5 last:border-0 last:pb-0">
            <div class="flex items-center gap-3">
              <span class="material-symbols-outlined text-base <?= $icol ?>" style="<?= $fill ?>"><?= $icon ?></span>
              <span class="text-xs md:text-sm <?= $text ?>"><?= $label ?></span>
            </div>
            <?php if ($state === 'active'): ?>
            <span class="text-[8px] font-bold text-[#fecb00] uppercase tracking-wider bg-[#fecb00]/10 border border-[#fecb00]/20 px-2 py-0.5 rounded-md">Live</span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="bg-gradient-to-r from-white/[0.02] to-transparent border border-white/[0.06] rounded-xl p-3.5 text-center lg:text-left flex flex-col sm:flex-row items-center gap-3 justify-center lg:justify-start">
        <span class="material-symbols-outlined text-white/30 text-lg">contact_support</span>
        <p class="text-white/40 text-xs">
          Direct inquiry or technical emergencies:
          <a href="mailto:support@lasu.edu.ng" class="text-white/70 hover:text-[#fecb00] font-semibold transition-colors underline underline-offset-4">support@lasu.edu.ng</a>
        </p>
      </div>

    </div>
  </main>

  <footer class="w-full max-w-5xl relative z-10 text-center border-t border-white/[0.06] pt-5 mt-8">
    <p class="text-white/20 text-[9px] uppercase tracking-[0.25em] font-medium">
      Lagos State University &bull; Academic Redress Management Framework
    </p>
  </footer>

  <script>
    // ── Countdown timer ──────────────────────────────────────────────────
    // Change to your desired system resolution window runtime (Hours config)
    const END_TIME = new Date();
    END_TIME.setHours(END_TIME.getHours() + 6);

    function pad(n) { return String(n).padStart(2, '0'); }

    function tick() {
      const diff = END_TIME - Date.now();
      if (diff <= 0) {
        window.location.reload();
        return;
      }
      const h = Math.floor(diff / 3600000);
      const m = Math.floor((diff % 3600000) / 60000);
      const s = Math.floor((diff % 60000) / 1000);

      const sEl = document.getElementById('cd-seconds');
      const prev = sEl.textContent;
      const newS = pad(s);

      document.getElementById('cd-hours').textContent   = pad(h);
      document.getElementById('cd-minutes').textContent = pad(m);
      sEl.textContent = newS;

      if (prev !== newS) {
        sEl.classList.remove('tick-anim');
        void sEl.offsetWidth; // force dynamic rendering reset
        sEl.classList.add('tick-anim');
      }
    }

    tick();
    setInterval(tick, 1000);
  </script>

</body>
</html>
