  </div><!-- /p-8 -->
</main><!-- /main -->
<footer class="lg:ml-72 py-4 px-4 lg:px-8 text-center text-[10px] text-[#43474f]/50 uppercase tracking-widest font-medium">
  &copy; <?= date('Y') ?> Lagos State University &bull; Academic Redress System &bull; v<?= APP_VERSION ?>
</footer>
<script src="/assets/js/app.js"></script>
<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (!sidebar || !overlay) return;

    sidebar.classList.toggle('-translate-x-full');
    overlay.classList.toggle('hidden');
}
</script>
</body>
</html>
