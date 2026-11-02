  </main><!-- /.page-content -->
</div><!-- /.main-wrapper -->

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer" style="z-index:9999"></div>

<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/chart.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>

<script>
  window.BASE_URL  = '<?= BASE_URL ?>';
  window.CSRF_TOKEN = '<?= generateCsrfToken() ?>';
</script>
<script src="<?= BASE_URL ?>/assets/js/cascade.js?v=19.1"></script>
<script src="<?= BASE_URL ?>/assets/js/pusams-clean.js?v=1" defer></script>
<script>
const CSRF_TOKEN = window.CSRF_TOKEN;
const BASE_URL   = window.BASE_URL;

function toggleDarkMode() {
    const isDark = document.body.classList.toggle('dark-mode');
    document.body.setAttribute('data-bs-theme', isDark ? 'dark' : 'light');
    document.cookie = 'dark_mode=' + (isDark ? '1' : '0') + ';path=/;max-age=31536000';
    location.reload();
}

function toggleSidebar() {
    document.getElementById('mainSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}
function closeSidebar() {
    document.getElementById('mainSidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('show');
}

function showToast(msg, type='success') {
    const id = 'toast_' + Date.now();
    const html = `<div id="${id}" class="toast align-items-center text-bg-${type} border-0" role="alert">
        <div class="d-flex"><div class="toast-body">${msg}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
    </div>`;
    document.getElementById('toastContainer').insertAdjacentHTML('beforeend', html);
    const el = document.getElementById(id);
    new bootstrap.Toast(el, {delay: 4000}).show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}

setTimeout(() => {
    document.querySelectorAll('#flashContainer .alert').forEach(a => {
        bootstrap.Alert.getOrCreateInstance(a)?.close();
    });
}, 5000);

document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', e => {
        if (!confirm(btn.dataset.confirm || 'Are you sure?')) e.preventDefault();
    });
});
</script>
<?php if (function_exists('renderRelatedCascadeJS')) echo renderRelatedCascadeJS(); ?>
</body>
</html>
