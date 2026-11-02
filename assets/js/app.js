
"use strict";

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        new bootstrap.Tooltip(el);
    });

    document.querySelectorAll('.alert-success,.alert-info').forEach(alertEl => {
        setTimeout(() => bootstrap.Alert.getOrCreateInstance(alertEl)?.close(), 5000);
    });

    document.querySelectorAll('.sidebar-item').forEach(item => {
        item.addEventListener('click', () => {
            if (window.innerWidth < 768 && typeof closeSidebar === 'function') closeSidebar();
        });
    });

    document.querySelectorAll('.attendance-check-toggle').forEach(box => {
        syncAttendanceCheckbox(box);
        box.addEventListener('change', () => syncAttendanceCheckbox(box));
    });
});

function apiPost(url, data = {}) {
    const csrfKey = typeof CSRF_TOKEN_NAME !== 'undefined' ? CSRF_TOKEN_NAME : '_csrf_token';
    data[csrfKey] = typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '';
    return fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        body: JSON.stringify(data)
    }).then(r => r.json());
}

function apiGet(url) {
    return fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}}).then(r => r.json());
}

function filterTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    if (!input || !table) return;
    input.addEventListener('keyup', () => {
        const q = input.value.toLowerCase();
        table.querySelectorAll('tbody tr').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
}

function syncAttendanceCheckbox(box) {
    const row = box.closest('tr');
    if (!row) return;
    const hidden = row.querySelector('.attendance-status-hidden');
    const badge = row.querySelector('.attendance-live-badge');
    const checked = box.checked;
    if (hidden) hidden.value = checked ? 'present' : 'absent';
    if (badge) {
        badge.className = 'badge attendance-live-badge bg-' + (checked ? 'success' : 'danger');
        badge.textContent = checked ? 'Present' : 'Absent';
    }
    row.classList.toggle('table-success', checked);
    row.classList.toggle('table-light', !checked);
}

document.addEventListener('click', async e => {
    const unread = e.target.closest('.notif-item.notif-unread');
    if (!unread) return;
    try {
        await fetch(BASE_URL + '/notifications/mark_read.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({_csrf_token: typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : ''})
        });
        unread.classList.remove('notif-unread');
        document.querySelectorAll('.notif-badge').forEach(b => b.remove());
    } catch (err) {}
});

document.addEventListener('submit', e => {
    const msg = e.target.dataset.confirm;
    if (msg && !confirm(msg)) e.preventDefault();
});

let __pusamsAutoSyncTimer = null;
let __pusamsAutoSyncBusy  = false;
let __pusamsDataVersion   = null;
let __pusamsReloadPending = false;

function __pusamsUserBusyEditing() {
    const el = document.activeElement;
    if (!el) return false;
    const tag = (el.tagName || '').toLowerCase();
    if (tag === 'input') {
        const t = (el.type || 'text').toLowerCase();
        if (['button','submit','reset','checkbox','radio','file','image','hidden'].indexOf(t) !== -1) return false;
        return true;
    }
    if (tag === 'textarea' || tag === 'select') return true;
    if (el.isContentEditable) return true;
    
    if (document.querySelector('.modal.show')) return true;
    return false;
}

function __pusamsSoftReload() {
    
    return;
}

function initAutoSyncHeartbeat() {
    if (typeof BASE_URL === 'undefined') return;
    if (__pusamsAutoSyncTimer) return;
    const beat = () => triggerAutoSync(false);
    __pusamsAutoSyncTimer = window.setInterval(beat, 10000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) triggerAutoSync(true);
    });
    window.addEventListener('focus', () => triggerAutoSync(true));
    
    try {
        const sy = sessionStorage.getItem('__pusams_scroll');
        if (sy !== null) {
            sessionStorage.removeItem('__pusams_scroll');
            window.scrollTo(0, parseInt(sy, 10) || 0);
        }
    } catch (e) {}
}

function triggerAutoSync(force) {
    if (__pusamsAutoSyncBusy || document.hidden) return;
    __pusamsAutoSyncBusy = true;
    const token = (typeof CSRF_TOKEN !== 'undefined') ? CSRF_TOKEN : '';
    fetch(BASE_URL + '/api/auto_sync.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': token},
        body: JSON.stringify({force: !!force, _csrf: token, _csrf_token: token})
    })
        .then(r => r.ok ? r.json() : null)
        .then(j => {
            const stamp = document.getElementById('pusamsLiveStamp');
            if (stamp) {
                const d = new Date();
                stamp.textContent =
                    d.getHours().toString().padStart(2,'0') + ':' +
                    d.getMinutes().toString().padStart(2,'0') + ':' +
                    d.getSeconds().toString().padStart(2,'0');
            }
            if (!j || !j.ok) return;

            
            
            
            
            const incoming = j.data_version || null;
            if (incoming && __pusamsDataVersion === null) {
                __pusamsDataVersion = incoming;
            } else if (incoming) {
                __pusamsDataVersion = incoming;
            }
        })
        .catch(() => null)
        .finally(() => { __pusamsAutoSyncBusy = false; });
}

document.addEventListener('submit', () => {
    setTimeout(() => triggerAutoSync(true), 800);
});

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAutoSyncHeartbeat);
} else {
    initAutoSyncHeartbeat();
}

(function initScrollMemory(){
    if (typeof window === 'undefined' || !window.sessionStorage) return;

    var STORAGE_KEY = '__pusams_scroll_positions';
    var MAX_ENTRIES = 30; 

    
    try {
        if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    } catch (e) {}

    function pageKey() {
        
        return (window.location.pathname || '') + (window.location.search || '');
    }

    function readMap() {
        try {
            var raw = sessionStorage.getItem(STORAGE_KEY);
            if (!raw) return {};
            var obj = JSON.parse(raw);
            return (obj && typeof obj === 'object') ? obj : {};
        } catch (e) { return {}; }
    }

    function writeMap(map) {
        try {
            
            var keys = Object.keys(map);
            if (keys.length > MAX_ENTRIES) {
                
                keys.sort(function(a, b){
                    return (map[a].t || 0) - (map[b].t || 0);
                });
                var drop = keys.length - MAX_ENTRIES;
                for (var i = 0; i < drop; i++) delete map[keys[i]];
            }
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(map));
        } catch (e) {}
    }

    function saveScroll() {
        var y = window.scrollY || window.pageYOffset || 0;
        
        var map = readMap();
        if (y < 20) {
            
            if (map[pageKey()]) {
                delete map[pageKey()];
                writeMap(map);
            }
            return;
        }
        map[pageKey()] = { y: y, t: Date.now() };
        writeMap(map);
    }

    function restoreScroll() {
        var map = readMap();
        var entry = map[pageKey()];
        if (!entry || typeof entry.y !== 'number' || entry.y < 20) return;
        
        var attempts = 0;
        var maxAttempts = 20; 
        var targetY = entry.y;
        function tryScroll() {
            attempts++;
            
            var docHeight = Math.max(
                document.body ? document.body.scrollHeight : 0,
                document.documentElement ? document.documentElement.scrollHeight : 0
            );
            if (docHeight >= targetY + window.innerHeight * 0.5 || attempts >= maxAttempts) {
                window.scrollTo(0, targetY);
                return;
            }
            setTimeout(tryScroll, 50);
        }
        
        window.scrollTo(0, targetY);
        setTimeout(tryScroll, 50);
    }

    
    
    window.addEventListener('pagehide', saveScroll);
    window.addEventListener('beforeunload', saveScroll);
    
    document.addEventListener('visibilitychange', function(){
        if (document.hidden) saveScroll();
    });

    
    window.addEventListener('pageshow', function(ev){
        if (ev.persisted) {
            
            restoreScroll();
        }
    });

    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restoreScroll);
    } else {
        restoreScroll();
    }
})();
