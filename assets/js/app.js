// ── POLLING get_status ─────────────────────────────────────────

const prevHostStatus = new Map();
const pendingShutdownCheck = new Map();
const serverShutdownTimeout = new Map();
const srvDependsOn = new Map(); // id → depends_on_server_id
let currentPollStatus = new Map(); // se actualiza cada poll antes del forEach
let firstPoll = true;

// #19 — Registrar verificación post-apagado
function registerShutdownCheck(id, hostname, delaySec = 90) {
    if (pendingShutdownCheck.has(id)) clearTimeout(pendingShutdownCheck.get(id));
    const timer = setTimeout(() => {
        pendingShutdownCheck.delete(id);
        if (prevHostStatus.get(id) === 'online') {
            showToast(`${hostname} — did not respond to shutdown`, 'warn', 'Check the host manually');
            fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({action:'log_event', server_id:id, level:'warn',
                    message:`Shutdown failed: ${hostname} still online after ${delaySec}s`})});
        }
    }, delaySec * 1000);
    pendingShutdownCheck.set(id, timer);
}
window.registerShutdownCheck = registerShutdownCheck;

function fmtUptime(sec) {
    if (sec == null || sec < 0) return '—';
    const d = Math.floor(sec / 86400);
    const h = Math.floor((sec % 86400) / 3600);
    const m = Math.floor((sec % 3600) / 60);
    if (d > 0) return d + 'd ' + h + 'h';
    if (h > 0) return h + 'h ' + m + 'm';
    return m + 'm';
}

function colorFor(type, status) {
    if (status === 'online')  return 'green';
    if (status === 'offline') return 'gray';
    return 'amber';
}

function statusClass(status) {
    if (status === 'online')  return 'green';
    if (status === 'offline') return 'red';
    return 'amber';
}

function setSyncState(state) {
    const btn = document.getElementById('live-indicator');
    btn.className = 'sync-btn' + (state !== 'live' ? ' ' + state : '');
    btn.title = state === 'syncing' ? 'syncing…' : state === 'error' ? 'error — click to retry' : 'sync now';
    btn.disabled = state === 'syncing';
}

let _polling = false;
async function refreshStatus() {
    if (_polling) return;
    _polling = true;
    setSyncState('syncing');
    try {
        const uiPrefs = loadUiPrefs();
        const metricsParam = uiPrefs.hideMetrics ? '&metrics=0' : '';
        const resp = await fetch('php/api.php?action=get_status' + metricsParam).then(r => r.json());

        if (resp.status !== 'success' || !Array.isArray(resp.data)) {
            setSyncState('error');
            console.error('get_status error:', resp.message);
            _polling = false;
            return;
        }

        const servers = resp.data;
        let online = 0, offline = 0, unknown = 0;

        // Mapa de status actual de este poll — para chequear dependencias (módulo-level para _notifyStateChange)
        currentPollStatus = new Map(servers.map(s => [s.id, s.status]));

        servers.forEach(srv => {
            const { id, status, hypervisor_type, ip, hostname, ping_ms,
                    node_cpu, node_mem, node_mem_total, node_disk, node_disk_total,
                    node_uptime, vms, extra, pending_action, shutdown_timeout,
                    depends_on_server_id, proxmox_server_id } = srv;
            if (shutdown_timeout) serverShutdownTimeout.set(id, shutdown_timeout);
            const parentId = depends_on_server_id || proxmox_server_id || null;
            if (parentId) srvDependsOn.set(id, parseInt(parentId));

            // ── State-change notifications ────────────────────────
            if (!firstPoll && prevHostStatus.has(id) && prevHostStatus.get(id) !== status) {
                _notifyStateChange(id, hostname, status, pending_action);
            }
            prevHostStatus.set(id, status);

            const color = colorFor(hypervisor_type, status);
            if (status === 'online')       online++;
            else if (status === 'offline') offline++;
            else                           unknown++;

            // ── Sidebar dot ───────────────────────────────────
            const sdot = document.getElementById('sdot-' + id);
            if (sdot) sdot.className = 'dot dot-' + color + ' sidebar-dot';

            // ── Tab dot (compat) ─────────────────────────────
            const tdot = document.getElementById('tdot-' + id);
            if (tdot) tdot.className = 'tdot dot-' + color;

            // ── Dashboard card ────────────────────────────────
            const card = document.getElementById('card-' + id);
            if (card) {
                // Sin borde de color — solo el dot cambia
                const dot = document.getElementById('dot-' + id);
                if (dot) dot.className = 'dot dot-' + color;

                // Tag para hide-offline CSS — tagear el wrapper col-*, no la card
                const cardCol = card.parentElement;
                if (cardCol) cardCol.dataset.srvStatus = status;

                const cpuTxt = node_cpu !== null ? node_cpu + '%' : '—';
                const ramTxt = node_mem_total !== null
                    ? (node_mem !== null ? node_mem : '—') + '/' + node_mem_total + ' GB'
                    : '—';

                const metricsHtml = uiPrefs.hideMetrics ? '' :
                    `<div class="metric"><div class="metric-label">CPU</div>
                        <div class="metric-val ${node_cpu !== null ? (node_cpu > 80 ? 'amber' : 'blue') : ''} sm">${cpuTxt}</div></div>
                    <div class="metric"><div class="metric-label">RAM</div>
                        <div class="metric-val blue sm">${ramTxt}</div></div>`;
                document.getElementById('card-metrics-' + id).innerHTML =
                    `<div class="metric"><div class="metric-label">Status</div>
                        <div class="metric-val ${statusClass(status)} sm">${status}</div></div>` + metricsHtml;

                const _hn = escHtml(hostname);
                const isOnline = status === 'online';
                const normalBtns = isOnline
                    ? `<button class="btn btn-outline-primary rack-hidden" data-sid="${id}" data-hn="${_hn}" data-act="reboot"  onclick="event.stopPropagation();confirmAction(+this.dataset.sid,this.dataset.hn,this.dataset.act)">reboot</button>
                       <button class="btn btn-outline-danger  rack-hidden" data-sid="${id}" data-hn="${_hn}" data-act="shutdown" onclick="event.stopPropagation();confirmAction(+this.dataset.sid,this.dataset.hn,this.dataset.act)">shutdown</button>`
                    : `<button class="btn btn-outline-success rack-hidden" data-sid="${id}" data-hn="${_hn}" data-act="wol"     onclick="event.stopPropagation();confirmAction(+this.dataset.sid,this.dataset.hn,this.dataset.act)">wake up</button>`;
                const rackBtns = `
                    <button class="rack-btn rack-btn-wake rack-only${isOnline ? ' rack-btn-disabled' : ''}"
                        ${isOnline ? 'disabled' : `data-sid="${id}" data-hn="${_hn}" data-act="wol" onclick="event.stopPropagation();confirmAction(+this.dataset.sid,this.dataset.hn,this.dataset.act)"`}>
                        <i class="bi bi-lightning-charge-fill"></i><span>Wake</span>
                    </button>
                    <button class="rack-btn rack-btn-reboot rack-only${!isOnline ? ' rack-btn-disabled' : ''}"
                        ${!isOnline ? 'disabled' : `data-sid="${id}" data-hn="${_hn}" data-act="reboot" onclick="event.stopPropagation();confirmAction(+this.dataset.sid,this.dataset.hn,this.dataset.act)"`}>
                        <i class="bi bi-arrow-clockwise"></i><span>Reboot</span>
                    </button>
                    <button class="rack-btn rack-btn-shut rack-only${!isOnline ? ' rack-btn-disabled' : ''}"
                        ${!isOnline ? 'disabled' : `data-sid="${id}" data-hn="${_hn}" data-act="shutdown" onclick="event.stopPropagation();confirmAction(+this.dataset.sid,this.dataset.hn,this.dataset.act)"`}>
                        <i class="bi bi-power"></i><span>Shutdown</span>
                    </button>`;
                document.getElementById('card-btns-' + id).innerHTML = normalBtns + rackBtns;
            }

            // ── Tab servidor ──────────────────────────────────
            const stEl = document.getElementById('status-' + id);
            if (stEl) {
                stEl.textContent = status;
                stEl.className   = 'srv-stat-val ' + statusClass(status);
            }

            const ctrlPanel = document.getElementById('ctrl-panel-' + id);
            if (ctrlPanel) ctrlPanel.style.borderTopColor =
                status==='online' ? 'var(--green)' : status==='offline' ? 'var(--red)' : 'var(--amber)';

            const uptimeEl = document.getElementById('uptime-' + id);
            if (uptimeEl) uptimeEl.textContent = node_uptime != null ? fmtUptime(node_uptime) : '—';

            const cpuEl = document.getElementById('cpu-' + id);
            if (cpuEl) cpuEl.textContent = node_cpu !== null ? node_cpu + '%' : '—';

            const ramEl = document.getElementById('ram-' + id);
            if (ramEl) ramEl.textContent = node_mem_total !== null
                ? (node_mem !== null ? node_mem : '—') + '/' + node_mem_total + ' GB' : '—';

            const diskEl = document.getElementById('disk-' + id);
            if (diskEl) diskEl.textContent = node_disk_total !== null
                ? (node_disk !== null ? node_disk : '—') + '/' + node_disk_total + ' GB' : '—';


            // ── OMV accordion ─────────────────────────────────
            const omvEl = document.getElementById('omv-extra-' + id);
            if (omvEl) {
                if (status === 'online') {
                    omvEl.innerHTML = renderOMVExtra(extra);
                    const sumEl = document.getElementById('omv-summary-' + id);
                    if (sumEl) {
                        const fsCount = (extra?.filesystems || []).filter(f => f.mounted || f._mounted).length;
                        const tempCount = extra?.disk_temps ? Object.keys(extra.disk_temps).length : 0;
                        sumEl.textContent = [
                            fsCount ? fsCount + ' fs' : '',
                            tempCount ? tempCount + ' disks' : '',
                        ].filter(Boolean).join(' · ');
                    }
                } else {
                    omvEl.innerHTML = '';
                    const sumEl = document.getElementById('omv-summary-' + id);
                    if (sumEl) sumEl.textContent = '';
                }
            }

            const wolBtn    = document.getElementById('wol-btn-' + id);
            if (wolBtn) wolBtn.style.display = (status === 'online') ? 'none' : '';
            const rebootBtn = document.getElementById('reboot-btn-' + id);
            if (rebootBtn) rebootBtn.style.display = (status === 'online') ? '' : 'none';

            // ── Ping inline (tab y card) ──────────────────────
            const pingCls2 = ping_ms === null ? 'ping-none' : ping_ms > 200 ? 'ping-slow' : 'ping-ok';
            const pingTxt2 = ping_ms !== null ? ping_ms + 'ms' : '—';

            const pingEl = document.getElementById('ping-' + id);
            if (pingEl) { pingEl.textContent = pingTxt2; pingEl.className = pingCls2; }

            const cardPingEl = document.getElementById('card-ping-' + id);
            if (cardPingEl) { cardPingEl.textContent = pingTxt2; cardPingEl.className = pingCls2; }

            // ── Alert badge TrueNAS ────────────────────────────
            const alertBadge  = document.getElementById('tab-alert-' + id);
            const sbAlertBadge = document.getElementById('sb-alert-' + id);
            if (alertBadge || sbAlertBadge) {
                const alerts  = extra?.alerts || [];
                const criticals = alerts.filter(a => {
                    if (a.dismissed) return false;
                    const lvl = (a.level || a.klass || '').toUpperCase();
                    return lvl === 'CRITICAL' || lvl === 'ALERT';
                });
                const show = criticals.length > 0;
                if (alertBadge)   { alertBadge.textContent   = criticals.length; alertBadge.style.display   = show ? '' : 'none'; }
                if (sbAlertBadge) { sbAlertBadge.textContent = criticals.length; sbAlertBadge.style.display = show ? '' : 'none'; }
            }

            // ── Auto-refresh logs si el accordion está abierto ───
            const logsAccordion = document.getElementById('acc-logs-' + id);
            if (logsAccordion && logsAccordion.classList.contains('show')) {
                loadServerLogs(id);
            }

            // ── Refresh drawer si está abierto para este server ─
            if (currentVm && currentVm.srvId === id) {
                const fresh = (vms || []).find(v => v.vmid === currentVm.vmid);
                if (fresh) refreshVmDrawer(fresh);
            }

            // ── DB table badge ────────────────────────────────
            const dbp = document.getElementById('db-status-' + id);
            if (dbp) {
                dbp.textContent = status;
                dbp.className   = 'badge '
                    + (status === 'online'  ? 'bg-success'
                     : status === 'offline' ? 'bg-danger'
                     : 'bg-warning');
                const td = dbp.closest('td');
                if (td) {
                    td.dataset.order = status === 'online' ? 2 : status === 'offline' ? 1 : 0;
                    if (_hdbDT) _hdbDT.cell(td).invalidate('data').draw(false);
                }
            }

            // ── VMs / guests ──────────────────────────────────
            renderVMs(id, vms, hypervisor_type, extra || {});
        });

        document.getElementById('count-online').textContent  = online;
        document.getElementById('count-offline').textContent = offline + (unknown > 0 ? ` (+${unknown}?)` : '');

        // Auto-colapsar: colapsar lista de VMs/LXC si todos están apagados
        if (uiPrefs.autoCollapse && !uiPrefs.hideMetrics) {
            servers.forEach(srv => {
                const vms = srv.vms || [];
                if (!vms.length) return;
                const allDown = vms.every(v => (v.status||'').toLowerCase() !== 'running');
                const colBody = document.getElementById('acc-vms-' + srv.id);
                if (!colBody) return;
                const bsCol = bootstrap.Collapse.getOrCreateInstance(colBody, { toggle: false });
                if (allDown) bsCol.hide(); else bsCol.show();
            });
        }

        setSyncState('live');
        firstPoll = false;

    } catch (e) {
        console.error('refreshStatus error:', e);
        setSyncState('error');
        // Limpiar skeleton loaders para que no queden colgados
        document.querySelectorAll('.skeleton-vm, .skeleton-metric, .skeleton-line').forEach(s => s.remove());
    } finally {
        _polling = false;
    }
}


// ── Panel extra: OMV ─────────────────────────────────────────
function renderOMVExtra(extra) {
    const esc = s => String(s ?? '').replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]));
    let html = '';

    // Filesystems montados
    const fs = (extra?.filesystems || []).filter(f => f.mounted || f._mounted);
    if (fs.length) {
        html += '<div class="sec-mini mb-2">filesystems</div><div class="extra-grid">';
        fs.forEach(f => {
            const used  = f.used  != null ? (parseInt(f.used)  / 1073741824).toFixed(1) : null;
            const total = f.size  != null ? (parseInt(f.size)  / 1073741824).toFixed(1) : null;
            const pct   = f.percentage != null ? parseInt(f.percentage)
                        : (used && total && parseFloat(total) > 0 ? Math.round(parseFloat(used)/parseFloat(total)*100) : null);
            const cls   = pct > 90 ? 'red' : pct > 70 ? 'amber' : 'blue';
            const label = f.label || f.devicefile || f.mountpoint || '?';
            html += `<div class="metric"><div class="metric-label">${esc(label)}</div>
                <div class="metric-val ${cls} sm">${used ?? '—'}/${total ?? '—'} GB${pct != null ? ` <span style="color:var(--text-dim);font-size:10px">${pct}%</span>` : ''}</div></div>`;
        });
        html += '</div>';
    }

    // Temperaturas de disco
    const temps = extra?.disk_temps;
    if (temps && typeof temps === 'object' && Object.keys(temps).length) {
        const entries = Object.entries(temps);
        const vals = entries.map(([,v]) => typeof v === 'number' ? v : null).filter(v => v !== null);
        html += '<div class="sec-mini mt-3 mb-2">disk temperatures</div><div class="extra-grid">';
        if (entries.length <= 8) {
            entries.forEach(([name, temp]) => {
                const t = typeof temp === 'number' ? temp : null;
                const cls = t > 50 ? 'red' : t > 40 ? 'amber' : 'green';
                html += `<div class="metric"><div class="metric-label">${esc(name)}</div>
                    <div class="metric-val ${t !== null ? cls : ''} sm">${t !== null ? Math.round(t) + '°C' : '—'}</div></div>`;
            });
        } else {
            const avg = vals.length ? Math.round(vals.reduce((a,b)=>a+b,0)/vals.length) : null;
            const max = vals.length ? Math.max(...vals) : null;
            html += `<div class="metric"><div class="metric-label">Average</div><div class="metric-val ${avg > 50 ? 'red' : avg > 40 ? 'amber' : 'green'} sm">${avg != null ? avg + '°C' : '—'}</div></div>`;
            html += `<div class="metric"><div class="metric-label">Max</div><div class="metric-val ${max > 50 ? 'red' : max > 40 ? 'amber' : 'green'} sm">${max != null ? max + '°C' : '—'}</div></div>`;
            html += `<div class="metric"><div class="metric-label">Disks</div><div class="metric-val sm">${entries.length}</div></div>`;
        }
        html += '</div>';
    }

    return html || '<div class="text-center" style="color:var(--text-dim);font-size:12px;padding:8px 0">No OMV data available</div>';
}

async function manualSync() {
    await fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'invalidate_cache'})}).catch(()=>{});
    refreshStatus();
}

// ── Filtro del dashboard ─────────────────────────────────────
function filterDashboard(q) {
    const term = (q || '').trim().toLowerCase();
    document.querySelectorAll('.srv-card-col').forEach(col => {
        const name = col.querySelector('.srv-name')?.textContent?.toLowerCase() || '';
        const ip   = (col.dataset.ip || '').toLowerCase();
        col.style.display = (!term || name.includes(term) || ip.includes(term)) ? '' : 'none';
    });
}

// Limpiar filtro al salir del dashboard
document.getElementById('htab-dashboard')?.addEventListener('hide.bs.tab', () => {
    const inp = document.getElementById('dash-search');
    if (inp) { inp.value = ''; filterDashboard(''); }
});

// sb-dashboard ya tiene class="active" en el HTML, no hace falta init JS

// ── DataTables shared init ────────────────────────────────────

// Cargar logs / inicializar DTs cuando se muestra la sección
document.getElementById('hiddenTabs').addEventListener('shown.bs.tab', e => {
    const target = e.target.dataset.bsTarget || '';
    if (target === '#tab-logs')       startLogAutoRefresh();
    else                              stopLogAutoRefresh();
    if (target === '#tab-hosts-db')   initHdbDT();
    if (target === '#tab-wake-proxy') initWpDT();
});

const POLLING_INTERVAL_MS = window.APP_CONFIG?.pollingInterval || 30000;

// Apply UI prefs immediately (before first poll)
applyUiPrefs();

// Invalidar cache antes del primer poll para evitar notificaciones falsas (#28)
fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'invalidate_cache'})})
    .catch(()=>{})
    .finally(() => {
        refreshStatus();
        setInterval(refreshStatus, POLLING_INTERVAL_MS);
    });
loadLogs(); // carga inicial
loadNotifyGlobal(); // carga delay y toggle global al arrancar (no esperar al tab de settings)

// ── PUSH NOTIFICATIONS ───────────────────────────────────────────────────────

let _pushSub = null;

function _b64uToUint8(b64) {
    const pad = '='.repeat((4 - b64.length % 4) % 4);
    const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, c => c.charCodeAt(0));
}

async function initPush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        _setPushUI('unsupported'); return;
    }
    try {
        await navigator.serviceWorker.ready;
        const reg = await navigator.serviceWorker.getRegistration('./');
        if (!reg) { _setPushUI('unsubscribed'); return; }
        _pushSub = await reg.pushManager.getSubscription();
        _setPushUI(_pushSub ? 'subscribed' : 'unsubscribed');
    } catch { _setPushUI('error'); }
}

async function togglePushSubscription() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
    const reg = await navigator.serviceWorker.ready;

    if (_pushSub) {
        await _pushSub.unsubscribe();
        await fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ action:'push_unsubscribe', endpoint: _pushSub.endpoint }) });
        _pushSub = null;
        _setPushUI('unsubscribed');
        showToast('Push disabled', 'info');
        return;
    }

    const perm = await Notification.requestPermission();
    if (perm !== 'granted') { _setPushUI('denied'); showToast('Permission denied', 'err'); return; }

    const vr = await fetch('php/api.php?action=get_vapid_public').then(r => r.json());
    if (!vr.data?.public_key) { showToast('VAPID not configured', 'err'); return; }

    try {
        _pushSub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: _b64uToUint8(vr.data.public_key)
        });
        const j = _pushSub.toJSON();
        await fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ action:'push_subscribe',
                endpoint: j.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth }) });
        _setPushUI('subscribed');
        showToast('Push enabled ✓', 'ok');
    } catch (e) { showToast('Error subscribing: ' + e.message, 'err'); }
}

function _setPushUI(state) {
    const btn = document.getElementById('push-subscribe-btn');
    const txt = document.getElementById('push-status-text');
    if (!btn || !txt) return;
    const map = {
        subscribed:   ['Disable',        'btn-outline-danger',    '<i class="bi bi-circle-fill me-1" style="color:#3fb950;font-size:9px;vertical-align:middle"></i>Active on this device',  false, 'var(--text)'],
        unsubscribed: ['Enable',         'btn-outline-primary',   '<i class="bi bi-circle me-1" style="font-size:9px;vertical-align:middle"></i>Not active on this device',                 false, 'var(--text-muted)'],
        unsupported:  ['Not supported',  'btn-outline-secondary', '<i class="bi bi-x-circle me-1"></i>Browser not compatible',                                                               true,  '#f85149'],
        denied:       ['Blocked',        'btn-outline-warning',   '<i class="bi bi-slash-circle me-1"></i>Permission denied — enable it in browser settings',                                true,  '#d29922'],
        error:        ['SW Error',       'btn-outline-danger',    '<i class="bi bi-exclamation-triangle me-1"></i>Error registering Service Worker',                                         false, '#f85149'],
    };
    const [label, cls, status, disabled, color] = map[state] || map.unsubscribed;
    btn.className = 'btn btn-sm ' + cls;
    btn.textContent = label;
    btn.disabled = disabled;
    txt.innerHTML = status;
    txt.className = 'push-status-txt';
    txt.style.color = color;
}

async function loadPushSettings() {
    const r = await fetch('php/api.php?action=get_push_settings').then(r => r.json());
    if (r.status !== 'success') return;
    const { enabled, vapid_ready, subscription_count } = r.data;
    const el = document.getElementById('push-enabled');
    if (el) el.checked = enabled;
    if (!vapid_ready) {
        await fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ action:'generate_vapid' }) });
    }
    const cnt = document.getElementById('push-device-count');
    if (cnt) cnt.textContent = subscription_count;
}

async function sendTestPush() {
    const r = await fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'send_push_event', event:'test',
            title:'🔔 WakeLab Test', body:'Notifications are working correctly', tag:'wakelab-test' })
    }).then(r => r.json());
    showToast(r.status === 'success' ? 'Push sent ✓' : 'Error: ' + r.message, r.status === 'success' ? 'ok' : 'err');
}

// ── Batch notifications (#25) ─────────────────────────────────────────────
// Por tipo de evento: cada tipo tiene su propio timer y acumulador.
// Cada nueva notificación del mismo tipo resetea el timer (extiende el delay).
// Tipos distintos se mandan por separado con su propio timer.
const _batchTimers = new Map(); // eventType → timerId
const _batchQueue  = new Map(); // eventType → Map<id, {hostname, title, body, pending}>

const _BATCH_LABELS = {
    server_down: { multi: (n, hosts) => [`${n} hosts offline`, hosts.join(', ') + ' se desconectaron sin acción previa registrada'] },
    server_up:   { multi: (n, hosts) => [`${n} hosts online`,  hosts.join(', ') + ' volvieron online'] },
};

function _queueNotify(event, id, hostname, title, body, pending = null) {

    // Ventana mínima = 3× poll interval — margen para hosts que transicionan en distintos ciclos
    const cfgMs  = (_notifyDownDelaySec || 0) * 1000;
    const delayMs = cfgMs > 0 ? Math.max(cfgMs, POLLING_INTERVAL_MS * 3) : 0;

    if (!_batchQueue.has(event)) _batchQueue.set(event, new Map());
    _batchQueue.get(event).set(id, { hostname, title, body, pending });

    // Resetear timer — extiende el delay cada vez que llega uno nuevo del mismo tipo
    if (_batchTimers.has(event)) clearTimeout(_batchTimers.get(event));

    const fire = () => {
        _batchTimers.delete(event);
        const queue = _batchQueue.get(event);
        if (!queue || queue.size === 0) return;
        const entries = [...queue.entries()];
        _batchQueue.delete(event);

        // Para server_down sin PA: re-verificar dependencias al momento de disparar.
        // El batch delay (~3 polls) da tiempo a que el padre aparezca offline en prevHostStatus.
        const shutActions = ['manual_shutdown','schedule_shutdown','idle_shutdown','ups_shutdown','ups_shutdown_timer','host_shutdown'];
        const batchHasShutdown = event === 'server_down' && entries.some(([, { pending: pa }]) => shutActions.includes(pa));
        const effectiveEntries = event === 'server_down'
            ? entries.filter(([eid, { pending: pa }]) => {
                if (pa) return true; // acción intencional conocida, siempre notificar
                // Sin PA: suprimir si otro servidor en el mismo batch tiene shutdown PA
                // (casi certero que es VM/dependencia del host que se apagó)
                if (batchHasShutdown) return false;
                const depId = srvDependsOn.get(eid);
                return !depId || prevHostStatus.get(depId) !== 'offline'; // suprimir si padre offline
            })
            : entries;

        if (effectiveEntries.length === 0) return;

        if (effectiveEntries.length === 1) {
            const [eid, { title: t, body: b, pending: pa }] = effectiveEntries[0];
            _pushEvent(event, t, b, eid, pa);
        } else {
            const hosts   = effectiveEntries.map(([, v]) => v.hostname);
            const pending = effectiveEntries.map(([, v]) => v.pending);
            const wolActions = ['manual_wol', 'schedule_wol'];
            let t, b;
            if (event === 'server_up') {
                const allWol = pending.every(p => wolActions.includes(p));
                t = `${effectiveEntries.length} hosts encendidos`;
                b = hosts.join(', ') + (allWol ? ' encendidos correctamente (WoL)' : ' volvieron online');
                _pushEvent(event, t, b, null, pending.find(p => p) ?? null);
            } else if (event === 'server_down') {
                const allShut = pending.every(p => shutActions.includes(p));
                t = `${effectiveEntries.length} hosts offline`;
                b = hosts.join(', ') + (allShut ? ' apagados' : ' se desconectaron');
                const batchPa = pending.find(p => shutActions.includes(p)) ?? null;
                _pushEvent(event, t, b, null, batchPa);
            } else {
                const label = _BATCH_LABELS[event];
                [t, b] = label ? label.multi(effectiveEntries.length, hosts) : [`${effectiveEntries.length} eventos`, hosts.join(', ')];
                _pushEvent(event, t, b, null, null);
            }
        }
    };

    if (delayMs > 0) {
        _batchTimers.set(event, setTimeout(fire, delayMs));
    } else {
        fire();
    }
}

function _cancelBatchEntry(event, id) {
    const queue = _batchQueue.get(event);
    if (!queue) return;
    queue.delete(id);
    if (queue.size === 0) {
        _batchQueue.delete(event);
        if (_batchTimers.has(event)) {
            clearTimeout(_batchTimers.get(event));
            _batchTimers.delete(event);
        }
    }
}

function _cancelOfflineNotify(id) {
    _cancelBatchEntry('server_down', id);
}

function _notifyStateChange(id, hostname, status, pending) {
    const pa = pending || null;

    // ── Going OFFLINE ───────────────────────────────────
    if (status === 'offline') {
        // Cancelar verificación de shutdown pendiente — se apagó OK
        if (pendingShutdownCheck.has(id)) {
            clearTimeout(pendingShutdownCheck.get(id));
            pendingShutdownCheck.delete(id);
        }
        if (pa === 'manual_shutdown') {
            showToast(`${hostname} apagado`, 'ok');
            _cancelOfflineNotify(id);
            _queueNotify('server_down', id, hostname,
                `${hostname} — apagado desde WakeLab`,
                `${hostname} fue apagado manualmente desde WakeLab`, pa);
        } else if (pa === 'manual_reboot') {
            showToast(`${hostname} reiniciando…`, 'info');
            _cancelOfflineNotify(id);
        } else if (pa === 'schedule_shutdown') {
            showToast(`${hostname} apagado`, 'ok', 'por schedule');
            _cancelOfflineNotify(id);
            _queueNotify('server_down', id, hostname,
                `${hostname} — apagado por schedule`,
                `${hostname} fue apagado por tarea programada`, pa);
        } else if (pa === 'idle_shutdown') {
            showToast(`${hostname} apagado`, 'ok', 'por inactividad');
            _cancelOfflineNotify(id);
            _queueNotify('server_down', id, hostname,
                `${hostname} — apagado por inactividad`,
                `${hostname} fue apagado automáticamente por inactividad`, pa);
        } else if (pa === 'ups_shutdown') {
            showToast(`${hostname} apagado`, 'warn', 'por UPS');
            _cancelOfflineNotify(id);
            _queueNotify('server_down', id, hostname,
                `${hostname} — apagado por UPS`,
                `${hostname} fue apagado por corte de luz (UPS)`, pa);
        } else if (pa === 'ups_shutdown_timer') {
            showToast(`${hostname} apagado`, 'warn', 'por UPS (timer)');
            _cancelOfflineNotify(id);
            _queueNotify('server_down', id, hostname,
                `${hostname} — apagado por UPS (timer)`,
                `${hostname} fue apagado por UPS tras expirar el timer`, pa);
        } else if (pa === 'host_shutdown') {
            showToast(`${hostname} offline`, 'info', 'host apagado');
            _cancelOfflineNotify(id);
        } else if (pa) {
            // PA conocido pero sin rama específica — tratar como intencional (no inesperado)
            showToast(`${hostname} offline`, 'info');
            _cancelOfflineNotify(id);
        } else {
            const depId = srvDependsOn.get(id);
            const parentOfflineNow = depId && currentPollStatus.get(depId) === 'offline';
            if (parentOfflineNow) {
                // Padre ya offline en este poll — suprimir directamente
                showToast(`${hostname} offline`, 'info', 'host/dependencia apagado');
                _cancelOfflineNotify(id);
            } else {
                // Padre todavía online (o sin dependencia) — encolar y re-verificar al disparar
                // El fire() del batch comprobará prevHostStatus después de 3+ polls
                const hasParent = !!srvDependsOn.get(id);
                showToast(`${hostname} offline`, hasParent ? 'info' : 'err',
                    hasParent ? 'host apagándose…' : 'desconexión inesperada');
                _queueNotify('server_down', id, hostname,
                    `${hostname} — inesperado`,
                    `${hostname} se desconectó sin acción previa registrada`);
            }
        }

    // ── Going ONLINE ────────────────────────────────────
    } else if (status === 'online') {
        _cancelOfflineNotify(id); // si había un offline pendiente, cancelar
        if (pa === 'manual_shutdown') {
            // Volvió online luego de apagado — inusual, silencioso
        } else if (pa === 'manual_wol') {
            showToast(`${hostname} encendido`, 'ok');
            _queueNotify('server_up', id, hostname,
                `${hostname} — encendido`,
                `${hostname} encendido correctamente vía Wake-on-LAN`, pa);
        } else if (pa === 'manual_reboot') {
            showToast(`${hostname} reiniciado`, 'ok');
            _queueNotify('server_up', id, hostname,
                `${hostname} — reiniciado`,
                `${hostname} reiniciado correctamente desde WakeLab`, pa);
        } else if (pa === 'schedule_wol') {
            showToast(`${hostname} encendido`, 'ok', 'por schedule');
            _queueNotify('server_up', id, hostname,
                `${hostname} — encendido por schedule`,
                `${hostname} encendido por tarea programada`, pa);
        } else {
            // Sin PA: verificar si el padre acaba de encenderse (VM arrancando con su host)
            const depId = srvDependsOn.get(id);
            const parentJustCameOnline = depId
                && currentPollStatus.get(depId) === 'online'
                && prevHostStatus.get(depId) !== 'online';
            if (depId && !parentJustCameOnline && currentPollStatus.get(depId) === 'online') {
                // Padre ya estaba online — VM se encendió sola (externo real)
                showToast(`${hostname} volvió online`, 'warn', 'encendido externo');
                _queueNotify('server_up', id, hostname,
                    `${hostname} — externo`,
                    `${hostname} volvió online sin acción previa registrada`);
                fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({action:'log_event', server_id:id, level:'warn',
                        message:`Encendido externo: ${hostname} volvió online sin acción registrada en WakeLab`})});
            } else if (depId) {
                // Padre acaba de encenderse o sigue offline — VM arrancó con su host, silenciar
                showToast(`${hostname} online`, 'info', 'arrancó con host');
            } else {
                // Sin dependencia configurada — encendido externo
                showToast(`${hostname} volvió online`, 'warn', 'encendido externo — no iniciado desde WakeLab');
                _queueNotify('server_up', id, hostname,
                    `${hostname} — externo`,
                    `${hostname} volvió online sin acción previa registrada`);
                fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({action:'log_event', server_id:id, level:'warn',
                        message:`Encendido externo: ${hostname} volvió online sin acción registrada en WakeLab`})});
            }
        }

    // ── Unknown — transición, no spam
    } else if (status === 'unknown') {
        if (!pa) showToast(`${hostname} sin respuesta`, 'warn');
    }
}

function _pushEvent(event, title, body, id, pendingAction) {

    fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
            action: 'send_push_event',
            event, title, body,
            tag:            'server-' + id,
            url:            './',
            server_id:      id,
            pending_action: pendingAction ?? null,
        })
    }).catch(() => {});
}

// Inicializar notificaciones cuando se abre el tab
document.addEventListener('wheel', () => { if (document.activeElement?.type === 'number') document.activeElement.blur(); });

document.addEventListener('shown.bs.tab', e => {
    if (e.target.id === 'htab-push') {
        loadPushSettings();
        initPush();
        loadTelegramSettings();
        loadEmailSettings();
        loadTemplates();
        loadAiConfig();
        loadNotifyEvents();
        loadNotifyGlobal();
        setTimeout(_updateNotifyEventsState, 300);
    }
});

// ── Tab switcher ──────────────────────────────────────────────────────────

// ── UI PREFERENCES (localStorage) ───────────────────────────────────────────

function loadUiPrefs() {
    const def = { hideOffline:false, hideMetrics:false, autoCollapse:false, density:'normal', timezone:'', rackMode:false };
    try { return { ...def, ...JSON.parse(localStorage.getItem('wakelab_ui') || '{}') }; }
    catch { return def; }
}

function loadUpsEvents() {
    const el = document.getElementById('ups-events-list');
    if (!el) return;
    el.innerHTML = '<span style="color:var(--text-dim)">Loading…</span>';
    fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'get_ups_events'})})
    .then(r => r.json()).then(res => {
        if (res.status !== 'success' || !res.data?.length) { el.innerHTML = '<span style="color:var(--text-dim)">No UPS events</span>'; return; }
        const rows = res.data.map(e => {
            const aff = (e.hosts_affected || []).map(h => escHtml(h.hostname) + '(' + escHtml(h.result) + ')').join(', ');
            return `<div style="display:flex;gap:10px;padding:4px 0;border-bottom:1px solid var(--border-dim)">
                <span style="color:var(--text-dim);white-space:nowrap">${escHtml(e.created_at)}</span>
                <span style="font-weight:600;min-width:70px">${escHtml(e.event)}</span>
                <span style="color:var(--text-dim)">${escHtml(e.ups_name)}</span>
                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${aff}</span>
            </div>`;
        });
        el.innerHTML = rows.join('');
    }).catch(() => { el.innerHTML = '<span style="color:var(--red)">Error loading events</span>'; });
}

function saveUpsSettings(srvId) {
    const managed      = document.getElementById('ups_managed_'      + srvId)?.checked ? 1 : 0;
    const priority     = parseInt(document.getElementById('ups_priority_'     + srvId)?.value) || 10;
    const ignoreDelay  = document.getElementById('ups_ignore_delay_' + srvId)?.checked ? 1 : 0;
    const lastResort   = document.getElementById('ups_last_resort_'  + srvId)?.checked ? 1 : 0;
    _saveBusy();
    fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'save_ups_server', server_id: srvId, ups_managed: managed, ups_priority: priority, ups_ignore_delay: ignoreDelay, ups_last_resort: lastResort})})
    .then(() => _saveDone())
    .catch(() => _saveDone());
}

function saveShutdownTimeout(srvId) {
    const val = parseInt(document.getElementById('shut_timeout_' + srvId)?.value) || 90;
    _saveBusy();
    fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'update_setting', key:`srv_${srvId}_shutdown_timeout`, value: String(val)})})
    .then(() => { serverShutdownTimeout.set(srvId, val); _saveDone(); })
    .catch(() => _saveDone());
}

function saveServerTz(val) {
    fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'update_setting', key:'timezone', value: val || ''})})
    .then(() => showToast('Server TZ saved — reloading…', 'ok'))
    .then(() => setTimeout(() => location.reload(), 600));
}

function saveUiPref(key, val) {
    const prefs = loadUiPrefs();
    prefs[key] = val;
    localStorage.setItem('wakelab_ui', JSON.stringify(prefs));
    applyUiPrefs(prefs);
    // Persistir TZ de visualización en DB y actualizar badges
    if (key === 'timezone') {
        fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'update_setting', key:'timezone_display', value: val || ''})});
        const label = val || '— igual al servidor —';
        document.querySelectorAll('[id^="sch-tz-badge-"]').forEach(b => {
            b.textContent = label;
        });
        // Recargar para que PHP convierta los horarios a la nueva zona
        setTimeout(() => location.reload(), 400);
    }
}

function applyUiPrefs(prefs) {
    prefs = prefs || loadUiPrefs();
    const body = document.getElementById('app-body');
    body.classList.toggle('app-hide-offline', !!prefs.hideOffline);
    body.classList.toggle('app-hide-metrics',  !!prefs.hideMetrics);
    body.classList.toggle('app-compact',       prefs.density === 'compact');
    body.classList.toggle('app-rack',          !!prefs.rackMode);
    // Rack mode: colapsar sidebar automáticamente
    const sidebar = document.getElementById('sidebar');
    if (prefs.rackMode && sidebar && !sidebar.classList.contains('collapsed')) {
        sidebar.classList.add('collapsed');
        document.getElementById('app-body')?.classList.add('sidebar-collapsed');
    }
}

function initUiPrefControls() {
    const prefs = loadUiPrefs();
    const el = id => document.getElementById(id);
    if (el('ui-hide-offline'))  el('ui-hide-offline').checked  = !!prefs.hideOffline;
    if (el('ui-hide-metrics'))  el('ui-hide-metrics').checked  = !!prefs.hideMetrics;
    if (el('ui-auto-collapse')) el('ui-auto-collapse').checked = !!prefs.autoCollapse;
    if (el('ui-density'))       el('ui-density').value         = prefs.density || 'normal';
    if (el('ui-timezone'))      el('ui-timezone').value        = prefs.timezone || '';
    if (el('ui-rack-mode'))     el('ui-rack-mode').checked     = !!prefs.rackMode;
}

function saveWakeSplashMode(val) {
    fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'update_setting', key:'wake_proxy_splash_mode', value: val})});
}

function saveWakeSplashRetries(val) {
    const n = Math.max(1, Math.min(10, parseInt(val) || 3));
    document.getElementById('ui-splash-retries').value = n;
    fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'update_setting', key:'wake_proxy_max_retries', value: String(n)})});
}

async function loadWakeSplashSettings() {
    try {
        const r = await fetch('php/api.php?action=get_config').then(r => r.json());
        const cfg = r.data || {};
        const mode = cfg.wake_proxy_splash_mode || 'detailed';
        const retries = parseInt(cfg.wake_proxy_max_retries ?? '3') || 3;
        const modeEl = document.getElementById('ui-splash-mode');
        const retriesEl = document.getElementById('ui-splash-retries');
        if (modeEl) modeEl.value = mode;
        if (retriesEl) retriesEl.value = retries;
        const tokenEl = document.getElementById('wp-token-val');
        if (tokenEl && cfg.wake_proxy_secret) tokenEl.textContent = cfg.wake_proxy_secret;
    } catch(e) {}
}

async function regenWakeProxyToken(btn) {
    const prev = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    const r = await fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'regenerate_wake_proxy_token' }) }).then(r => r.json());
    btn.disabled = false; btn.innerHTML = prev;
    if (r.status === 'success') {
        const el = document.getElementById('wp-token-val');
        if (el) el.textContent = r.data.secret;
        showToast('Token regenerated — update the header in NPM', 'warn');
    } else {
        showToast('Error: ' + r.message, 'err');
    }
}

// ── SISTEMA SETTINGS ─────────────────────────────────────────────────────────

async function loadSistemaSettings() {
    const r = await fetch('php/api.php?action=get_config').then(r => r.json());
    if (r.status !== 'success') return;
    const d = r.data;
    const el = id => document.getElementById(id);
    if (el('cfg-polling'))       el('cfg-polling').value       = d.polling_interval_sec ?? '';
    if (el('cfg-cache-ttl'))     el('cfg-cache-ttl').value     = d.status_cache_ttl_sec ?? '';
    if (el('cfg-api-timeout'))   el('cfg-api-timeout').value   = d.api_timeout_sec      ?? '';
    if (el('cfg-ping-timeout'))  el('cfg-ping-timeout').value  = d.ping_timeout_sec     ?? '';
    if (el('cfg-wakelab-url'))   el('cfg-wakelab-url').value   = d.wakelab_base_url     ?? '';
    const retSel = el('cfg-log-retention');
    if (retSel) retSel.value = d.event_retention || '1000';
    initUiPrefControls();
    loadWakeSplashSettings();
}

// ── AUTO-SAVE helpers ─────────────────────────────────────────────────────
// Guard beforeunload mientras hay un save en vuelo
let _pendingSaves = 0;
function _saveBusy()  { _pendingSaves++; }
function _saveDone()  { _pendingSaves = Math.max(0, _pendingSaves - 1); }
window.addEventListener('beforeunload', e => {
    if (_pendingSaves > 0) { e.preventDefault(); e.returnValue = ''; }
});

const _autoSaveTimers = new Map();
function _debounce(key, fn, delay = 900) {
    if (_autoSaveTimers.has(key)) clearTimeout(_autoSaveTimers.get(key));
    _autoSaveTimers.set(key, setTimeout(() => { _autoSaveTimers.delete(key); fn(); }, delay));
}

async function _postSetting(key, value) {
    _saveBusy();
    try {
        const r = await fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ action:'update_setting', key, value }) }).then(r => r.json());
        return r;
    } finally { _saveDone(); }
}

async function saveTiempos() {
    const el = id => document.getElementById(id);
    const map = [
        ['cfg-polling',      'polling_interval_sec',  5, 3600],
        ['cfg-cache-ttl',    'status_cache_ttl_sec',  5,  600],
        ['cfg-api-timeout',  'api_timeout_sec',        2,   60],
        ['cfg-ping-timeout', 'ping_timeout_sec',       1,   30],
    ];
    _saveBusy();
    try {
        for (const [elId, key, min, max] of map) {
            let val = parseInt(el(elId)?.value ?? '', 10);
            if (isNaN(val)) continue;
            val = Math.max(min, Math.min(max, val));
            if (el(elId)) el(elId).value = val;
            await fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({ action:'update_setting', key, value: String(val) }) });
        }
    } finally { _saveDone(); }
    showToast('Timings saved', 'ok');
}

async function saveWakelabUrl() {
    const val = (document.getElementById('cfg-wakelab-url')?.value ?? '').trim();
    const r = await _postSetting('wakelab_base_url', val);
    showToast(r.status === 'success' ? 'URL saved' : 'Error: ' + r.message, r.status === 'success' ? 'ok' : 'err');
}

async function saveGlobalSSH() {
    const user = (document.getElementById('cfg-ssh-user')?.value ?? '').trim() || 'root';
    const port = parseInt(document.getElementById('cfg-ssh-port')?.value ?? '22', 10) || 22;
    _saveBusy();
    try {
        const post = (key, value) => fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'update_setting', key, value: String(value)})}).then(r => r.json());
        const [r1, r2] = await Promise.all([post('ssh_default_user', user), post('ssh_default_port', port)]);
        const ok = r1.status === 'success' && r2.status === 'success';
        showToast(ok ? 'Global SSH saved' : 'Error saving', ok ? 'ok' : 'err',
                  ok ? `User: ${user} · Port: ${port}` : '');
    } finally { _saveDone(); }
}

async function saveLogSettings() {
    const val = document.getElementById('cfg-log-retention')?.value ?? '1000';
    const r = await _postSetting('event_retention', val);
    showToast(r.status === 'success' ? 'Retention saved' : 'Error: ' + r.message, r.status === 'success' ? 'ok' : 'err');
}

async function clearEvents(btn) {
    if (!confirm('Clear the entire event log? This action cannot be undone.')) return;
    const r = await fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'clear_events' }) }).then(r => r.json());
    showToast(r.status === 'success' ? 'Log cleared' : 'Error: ' + r.message, r.status === 'success' ? 'ok' : 'err');
    if (r.status === 'success') loadLogs();
}

// ── BACKUP / RESTORE ─────────────────────────────────────────────────────────

async function cfgExport() {
    const password = document.getElementById('cfg-export-pass')?.value ?? '';
    const r = await fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'export_config', password }) }).then(r => r.json());
    if (r.status !== 'success') { showToast('Export failed: ' + r.message, 'err'); return; }
    const blob = new Blob([JSON.stringify(r.data, null, 2)], { type: 'application/json' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    const date = new Date().toISOString().slice(0, 10);
    a.href     = url;
    a.download = `wakelab-backup-${date}.json`;
    a.click();
    URL.revokeObjectURL(url);
    showToast('Backup downloaded', 'ok');
}

async function cfgImport() {
    const fileEl = document.getElementById('cfg-import-file');
    const pass   = document.getElementById('cfg-import-pass')?.value ?? '';
    const status = document.getElementById('cfg-import-status');
    if (!fileEl?.files?.length) { showToast('Select a backup file first', 'warn'); return; }
    if (!confirm('This will REPLACE all current servers, schedules and settings with the backup. Continue?')) return;
    status.textContent = 'Reading file…';
    const text = await fileEl.files[0].text();
    let payload;
    try { payload = JSON.parse(text); } catch { showToast('Invalid JSON file', 'err'); status.textContent = ''; return; }
    status.textContent = 'Importing…';
    const r = await fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'import_config', payload, password: pass }) }).then(r => r.json());
    if (r.status === 'success') {
        showToast('Config imported — reloading…', 'ok');
        status.textContent = '';
        setTimeout(() => location.reload(), 1500);
    } else {
        showToast('Import failed: ' + r.message, 'err');
        status.textContent = 'Error: ' + r.message;
    }
}

// ── TIMESTAMP FORMATTING ─────────────────────────────────────────────────────

function fmtTimestamp(ts) {
    if (!ts) return '—';
    const prefs = loadUiPrefs();
    const tz = prefs.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone;
    try {
        const d = new Date(ts.replace(' ', 'T') + 'Z');
        return new Intl.DateTimeFormat('es-AR', {
            timeZone: tz,
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false
        }).format(d);
    } catch { return ts; }
}

// ── SISTEMA SUB-SWITCH ───────────────────────────────────────────────────────

function sysSwitch(sub) {
    ['general','tiempos','ui','wakeproxy','admin'].forEach(s => {
        document.getElementById('sys-tab-' + s)?.classList.remove('active');
        const p = document.getElementById('sys-panel-' + s);
        if (p) p.style.display = 'none';
    });
    document.getElementById('sys-tab-' + sub)?.classList.add('active');
    const active = document.getElementById('sys-panel-' + sub);
    if (active) active.style.display = '';
}

// ── SETTINGS TOP-LEVEL SWITCH ────────────────────────────────────────────────

function settingsSwitch(tab) {
    ['notificaciones','sistema','cuenta','ups'].forEach(t => {
        document.getElementById('sp-tab-' + t)?.classList.remove('active');
        const panel = document.getElementById('sp-panel-' + t);
        if (panel) panel.style.display = 'none';
    });
    document.getElementById('sp-tab-' + tab)?.classList.add('active');
    const active = document.getElementById('sp-panel-' + tab);
    if (active) active.style.display = '';
    if (tab === 'sistema') loadSistemaSettings();
}

function ntSwitch(channel) {
    ['push','tg','email','tpl'].forEach(c => {
        document.getElementById('nt-tab-' + c)?.classList.remove('active');
        const panel = document.getElementById('nt-panel-' + c);
        if (panel) panel.style.display = 'none';
    });
    document.getElementById('nt-tab-' + channel)?.classList.add('active');
    const active = document.getElementById('nt-panel-' + channel);
    if (active) active.style.display = '';
    if (channel === 'tpl') {
        loadTemplates();
        loadAiConfig();
    }
}

// ── Quick channel toggle (auto-save) ─────────────────────────────────────

async function toggleNotifChannel(channel, enabled) {
    await fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'toggle_notify_channel', channel, enabled }) });
    _updateNotifyEventsState();
}

function _updateNotifyEventsState() {
    const globalOn  = document.getElementById('notif-global-toggle')?.checked ?? true;
    const anyEnabled = globalOn && ['push-enabled','tg-enabled','email-enabled']
        .some(id => document.getElementById(id)?.checked);
    const card = document.getElementById('notif-events-card');
    if (!card) return;
    card.style.opacity      = anyEnabled ? '' : '0.4';
    card.style.pointerEvents = anyEnabled ? '' : 'none';
    card.querySelectorAll('.nevt-input').forEach(el => el.disabled = !anyEnabled);
}

// ── Global events ─────────────────────────────────────────────────────────

async function loadNotifyEvents() {
    const r = await fetch('php/api.php?action=get_notify_events').then(r => r.json());
    if (r.status !== 'success') return;
    Object.entries(r.data).forEach(([k, v]) => {
        const el = document.getElementById('nevt-' + k);
        if (el) el.checked = v;
    });
}

async function saveNotifyEvents() {
    const keys = ['server_down','server_up','schedule','idle','error','guest_unknown'];
    const events = {};
    keys.forEach(k => { const el = document.getElementById('nevt-' + k); events[k] = el?.checked ?? true; });
    const r = await fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'save_notify_events', events }) }).then(r => r.json());
    showToast(r.status === 'success' ? 'Events saved' : 'Error: ' + r.message,
              r.status === 'success' ? 'ok' : 'err');
}

// ── Global toggle + timings ────────────────────────────────────────────────

let _notifyDownDelaySec = 60; // segundos de delay para batching de notificaciones

async function loadNotifyGlobal() {
    const r = await fetch('php/api.php?action=get_notify_global').then(r => r.json());
    if (r.status !== 'success') return;
    const { enabled, down_delay_sec, unknown_guest_min } = r.data;
    const tog = document.getElementById('notif-global-toggle');
    if (tog) tog.checked = enabled;
    _applyNotifGlobalState(enabled);
    const dd = document.getElementById('notif-down-delay');
    if (dd) dd.value = down_delay_sec ?? 30;
    const um = document.getElementById('notif-unknown-min');
    if (um) um.value = unknown_guest_min ?? 10;
    _notifyDownDelaySec = down_delay_sec ?? 30;
}

async function saveNotifyGlobal(field, value) {
    const payload = { action: 'save_notify_global' };
    payload[field] = value;
    await fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify(payload) });
    if (field === 'enabled') _applyNotifGlobalState(value);
}

function _applyNotifGlobalState(enabled) {
    const wrap = document.getElementById('notif-channels-wrap');
    if (wrap) {
        wrap.style.opacity      = enabled ? '' : '0.4';
        wrap.style.pointerEvents = enabled ? '' : 'none';
    }
    const evCard = document.getElementById('notif-events-card');
    if (evCard) {
        evCard.style.opacity      = enabled ? '' : '0.4';
        evCard.style.pointerEvents = enabled ? '' : 'none';
    }
    _updateNotifyEventsState();
}

async function saveNotifyTimings() {
    const dd  = parseInt(document.getElementById('notif-down-delay')?.value) || 0;
    const um  = parseInt(document.getElementById('notif-unknown-min')?.value) || 10;
    const r   = await fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'save_notify_global', down_delay_sec: dd, unknown_guest_min: um }) }).then(r => r.json());
    showToast(r.status === 'success' ? 'Saved' : 'Error: ' + r.message,
              r.status === 'success' ? 'ok' : 'err');
    _notifyDownDelaySec = dd;
}

// ── TELEGRAM ──────────────────────────────────────────────────────────────

async function loadTelegramSettings() {
    const r = await fetch('php/api.php?action=get_telegram_settings').then(r => r.json());
    if (r.status !== 'success') return;
    const { enabled, token, chat_id } = r.data;
    const el = id => document.getElementById(id);
    if (el('tg-enabled'))  el('tg-enabled').checked = enabled;
    if (el('tg-token'))    el('tg-token').value      = token;
    if (el('tg-chat-id'))  el('tg-chat-id').value    = chat_id;
}

async function saveTelegramSettings() {
    const el = id => document.getElementById(id);
    _saveBusy();
    try {
        const r  = await fetch('php/api.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                action:  'save_telegram_settings',
                token:   el('tg-token')?.value.trim()   ?? '',
                chat_id: el('tg-chat-id')?.value.trim() ?? '',
            }),
        }).then(r => r.json());
        showToast(r.status === 'success' ? 'Telegram saved' : 'Error: ' + r.message,
                  r.status === 'success' ? 'ok' : 'err');
    } finally { _saveDone(); }
}

async function testTelegram() {
    const el = id => document.getElementById(id);
    const r  = await fetch('php/api.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
            action:  'test_telegram',
            token:   el('tg-token')?.value.trim()   ?? '',
            chat_id: el('tg-chat-id')?.value.trim() ?? '',
        }),
    }).then(r => r.json());
    showToast(r.status === 'success' ? 'Message sent ✓' : 'Error: ' + r.message,
              r.status === 'success' ? 'ok' : 'err');
}

// ── EMAIL ─────────────────────────────────────────────────────────────────

async function loadEmailSettings() {
    const r = await fetch('php/api.php?action=get_email_settings').then(r => r.json());
    if (r.status !== 'success') return;
    const { enabled, smtp_host, smtp_port, smtp_secure, smtp_user, smtp_pass,
            from, from_name, to, wakelab_url } = r.data;
    const el = id => document.getElementById(id);
    if (el('email-enabled'))      el('email-enabled').checked    = enabled;
    if (el('email-smtp-host'))    el('email-smtp-host').value    = smtp_host;
    if (el('email-smtp-port'))    el('email-smtp-port').value    = smtp_port;
    if (el('email-smtp-secure'))  el('email-smtp-secure').value  = smtp_secure;
    if (el('email-smtp-user'))    el('email-smtp-user').value    = smtp_user;
    if (el('email-smtp-pass'))    el('email-smtp-pass').value    = smtp_pass;
    if (el('email-from'))         el('email-from').value         = from;
    if (el('email-from-name'))    el('email-from-name').value    = from_name;
    if (el('email-to'))           el('email-to').value           = to;
    if (el('email-wakelab-url'))  el('email-wakelab-url').value  = wakelab_url ?? '';
    _updateSmtpStatusBanner(smtp_host);
}

function _updateSmtpStatusBanner(smtpHost) {
    const dot = document.getElementById('email-smtp-status-dot');
    const txt = document.getElementById('email-smtp-status-txt');
    if (!dot || !txt) return;
    if (smtpHost && smtpHost.trim()) {
        dot.style.color = 'var(--green)';
        txt.textContent = 'SMTP configured: ' + smtpHost.trim();
    } else {
        dot.style.color = 'var(--red)';
        txt.textContent = 'SMTP not configured — alerts and password recovery will not work.';
    }
}

async function saveEmailSettings() {
    const el = id => document.getElementById(id);
    _saveBusy();
    try {
        const r  = await fetch('php/api.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                action:      'save_email_settings',
                smtp_host:   el('email-smtp-host')?.value.trim()   ?? '',
                smtp_port:   el('email-smtp-port')?.value          ?? '587',
                smtp_secure: el('email-smtp-secure')?.value         ?? 'tls',
                smtp_user:   el('email-smtp-user')?.value.trim()   ?? '',
                smtp_pass:   el('email-smtp-pass')?.value           ?? '',
                from:        el('email-from')?.value.trim()         ?? '',
                from_name:   el('email-from-name')?.value.trim()    ?? 'WakeLab',
                to:          el('email-to')?.value.trim()            ?? '',
                wakelab_url: el('email-wakelab-url')?.value.trim()  ?? '',
            }),
        }).then(r => r.json());
        showToast(r.status === 'success' ? 'Email saved' : 'Error: ' + r.message,
                  r.status === 'success' ? 'ok' : 'err');
        if (r.status === 'success') _updateSmtpStatusBanner(el('email-smtp-host')?.value.trim() ?? '');
    } finally { _saveDone(); }
}

// ── IA CONFIG ─────────────────────────────────────────────────────────────

const _AI_DEFAULTS = {
    openai:     { model: 'gpt-4o-mini',       keyHint: 'sk-…' },
    anthropic:  { model: 'claude-haiku-4-5',  keyHint: 'sk-ant-…' },
    gemini:     { model: 'gemini-2.0-flash',  keyHint: 'AIza…' },
};

function updateAiPlaceholder(provider) {
    const d = _AI_DEFAULTS[provider] || _AI_DEFAULTS.openai;
    const mdl = document.getElementById('ai-model');
    const key = document.getElementById('ai-api-key');
    if (mdl && !mdl.value) mdl.placeholder = d.model;
    if (key && !key.value) key.placeholder = d.keyHint;
}

async function loadAiConfig() {
    const r = await fetch('php/api.php?action=get_ai_config').then(r => r.json());
    if (r.status !== 'success') return;
    const d = r.data;

    const chk = document.getElementById('ai-enabled');
    if (chk) {
        chk.checked = d.ai_enabled === '1';
        _toggleAiBody(chk.checked);
    }

    const provider = d.ai_provider || 'openai';
    const sel = document.getElementById('ai-provider');
    if (sel) sel.value = provider;

    const mdl = document.getElementById('ai-model');
    if (mdl) { mdl.value = d.ai_model || ''; mdl.placeholder = _AI_DEFAULTS[provider]?.model || ''; }

    const key = document.getElementById('ai-api-key');
    if (key) key.placeholder = d.ai_api_key === '••••••••' ? '(saved)' : (_AI_DEFAULTS[provider]?.keyHint || 'API Key');

    // Personalización
    const g = id => document.getElementById(id);
    if (g('ai-use-emojis'))    g('ai-use-emojis').checked    = (d.ai_use_emojis ?? '1') === '1';
    if (g('ai-highlight'))     g('ai-highlight').checked      = (d.ai_highlight  ?? '1') === '1';
    if (g('ai-no-repeat'))     g('ai-no-repeat').checked      = (d.ai_no_repeat  ?? '1') === '1';
    if (g('ai-tone'))          g('ai-tone').value             = d.ai_tone     || 'informal';
    if (g('ai-language'))      g('ai-language').value         = d.ai_language || 'en';
    if (g('ai-extra-context')) g('ai-extra-context').value    = d.ai_extra_context || '';
}

function _toggleAiBody(enabled) {
    const body = document.getElementById('ai-config-body');
    if (body) {
        body.style.opacity  = enabled ? '1' : '0.4';
        body.style.pointerEvents = enabled ? '' : 'none';
    }
}

async function saveAiEnabled(chk) {
    _toggleAiBody(chk.checked);
    await fetch('php/api.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'update_setting', key: 'ai_enabled', value: chk.checked ? '1' : '0' }),
    });
}

async function saveAiConfig() {
    const g = id => document.getElementById(id);
    const provider  = g('ai-provider')?.value         || 'openai';
    const model     = g('ai-model')?.value.trim()      || '';
    const keyVal    = g('ai-api-key')?.value.trim()    || '';
    const useEmojis = g('ai-use-emojis')?.checked ? '1' : '0';
    const highlight = g('ai-highlight')?.checked  ? '1' : '0';
    const noRepeat  = g('ai-no-repeat')?.checked  ? '1' : '0';
    const tone      = g('ai-tone')?.value          || 'informal';
    const language  = g('ai-language')?.value      || 'en';
    const extraCtx  = g('ai-extra-context')?.value.trim() || '';

    const post = (key, value) => fetch('php/api.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'update_setting', key, value }),
    });

    _saveBusy();
    try {
        const saves = [
            post('ai_provider',      provider),
            post('ai_model',         model),
            post('ai_use_emojis',    useEmojis),
            post('ai_highlight',     highlight),
            post('ai_no_repeat',     noRepeat),
            post('ai_tone',          tone),
            post('ai_language',      language),
            post('ai_extra_context', extraCtx),
        ];
        if (keyVal && keyVal !== '(saved)') saves.push(post('ai_api_key', keyVal));
        await Promise.all(saves);
    } finally { _saveDone(); }

    showToast('AI configuration saved', 'ok');
    if (keyVal && g('ai-api-key')) { g('ai-api-key').value = ''; g('ai-api-key').placeholder = '(saved)'; }
}

async function testAiConfig(btn) {
    const prev = btn?.innerHTML;
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Generating…'; }
    const res = document.getElementById('ai-test-result');
    const msg = document.getElementById('ai-test-msg');
    if (res) res.style.display = 'none';

    const r = await fetch('php/api.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'test_ai' }),
    }).then(r => r.json()).catch(() => ({ status: 'error', message: 'Network error' }));

    if (btn) { btn.disabled = false; btn.innerHTML = prev; }

    if (r.status === 'success' && msg && res) {
        msg.innerHTML = (r.data?.message || '').replace(/\n/g, '<br>');
        msg.style.whiteSpace = '';
        const meta = document.getElementById('ai-test-meta');
        const tgNote = r.data?.tg_sent ? ' · enviado a Telegram ✓' : '';
        if (meta) meta.textContent = (r.data?.provider || '') + ' · ' + (r.data?.model || '') + tgNote;
        res.style.display = '';
    } else {
        showToast('AI Error: ' + (r.message || 'No response'), 'err');
    }
}

// ── PLANTILLAS ────────────────────────────────────────────────────────────

async function loadTemplates() {
    const r = await fetch('php/api.php?action=get_templates').then(r => r.json());
    if (r.status !== 'success') return;
    Object.entries(r.data).forEach(([key, val]) => {
        const ta = document.getElementById('tpl-' + key);
        if (ta) ta.value = val;
    });
}

async function saveTemplates() {
    const tpls = { action: 'save_templates' };
    document.querySelectorAll('.tpl-field').forEach(ta => { tpls[ta.dataset.event] = ta.value; });
    _saveBusy();
    const r = await fetch('php/api.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify(tpls),
    }).then(r => r.json()).finally(() => _saveDone());
    showToast(r.status === 'success' ? 'Templates saved' : 'Error: ' + r.message,
              r.status === 'success' ? 'ok' : 'err');
}

async function resetTemplates(btn) {
    if (!confirm('Restore templates to their default values?')) return;
    const prev = btn?.innerHTML;
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }
    const r = await fetch('php/api.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'reset_templates' }),
    }).then(r => r.json());
    if (btn) { btn.disabled = false; btn.innerHTML = prev; }
    if (r.status !== 'success') { showToast('Error: ' + r.message, 'err'); return; }
    Object.entries(r.data).forEach(([key, val]) => {
        const ta = document.getElementById('tpl-' + key);
        if (ta) ta.value = val;
    });
    showToast('Templates restored', 'ok');
}

// ── DEBUG ─────────────────────────────────────────────────────────────────

async function saveUser(btn) {
    const usuario     = document.getElementById('acc-usuario')?.value.trim();
    const email       = document.getElementById('acc-email')?.value.trim();
    const passCurrent = document.getElementById('acc-pass-current')?.value;
    const passNew     = document.getElementById('acc-pass-new')?.value;
    const passConfirm = document.getElementById('acc-pass-confirm')?.value;
    if (!passCurrent) { showToast('Enter current password', 'err'); return; }
    if (passNew && passNew !== passConfirm) { showToast('New passwords do not match', 'err'); return; }
    btn.disabled = true;
    const r = await fetch('php/api.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'update_user', usuario, email, pass_current: passCurrent, pass_new: passNew || null }),
    }).then(r => r.json());
    btn.disabled = false;
    showToast(r.status === 'success' ? 'Data updated' : r.message,
              r.status === 'success' ? 'ok' : 'err');
    if (r.status === 'success') {
        document.getElementById('acc-pass-current').value = '';
        document.getElementById('acc-pass-new').value = '';
        document.getElementById('acc-pass-confirm').value = '';
    }
}

async function toggleDebugMode(cb) {
    document.getElementById('app-body').classList.toggle('debug-mode-off', !cb.checked);
    await fetch('php/api.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'update_debug_mode', enabled: cb.checked }),
    }).then(r => r.json());
}

function _dbgStart(outId, btn, label) {
    const out = document.getElementById(outId);
    if (out) { out.textContent = '⏳ ' + label; out.style.display = ''; }
    if (btn) { btn.disabled = true; btn._prev = btn.innerHTML; btn.innerHTML = '…'; }
    return out;
}
function _dbgDone(btn) {
    if (btn) { btn.disabled = false; btn.innerHTML = btn._prev || btn.innerHTML; }
}

async function checkSshKey(serverId, btn) {
    const out = _dbgStart('dbg-ssh-' + serverId, btn, 'Checking SSH…');
    const r = await fetch('php/api.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'check_ssh_key', server_id: serverId }),
    }).then(r => r.json());
    _dbgDone(btn);
    if (out) out.textContent = r.status === 'success' ? r.data.detail : '❌ ' + r.message;
}

async function debugIdleActive(serverId, btn) {
    const out = _dbgStart('dbg-idle-active-' + serverId, btn, 'Evaluating…');
    const r = await fetch('php/api.php?action=debug_idle_active&server_id=' + serverId)
        .then(r => r.json()).catch(() => null);
    _dbgDone(btn);
    if (!out) return;
    if (!r || r.status !== 'success') { out.textContent = '❌ ' + (r?.message || 'Error'); return; }
    const d = r.data;
    const lines = d.pasos.map(p => (p.ok ? '✅' : '❌') + ' [paso ' + p.paso + '] ' + p.msg);
    lines.push('');
    lines.push('→ idle_active returns: ' + d.resultado + (d.resultado === '1' ? ' (script will detect idle)' : ' (script will not shut down)'));
    out.textContent = lines.join('\n');
}

async function fetchIdleLog(serverId, btn) {
    const out = _dbgStart('idle-log-' + serverId, btn, 'Reading log…');
    const r = await fetch('php/api.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'get_idle_log', server_id: serverId }),
    }).then(r => r.json());
    _dbgDone(btn);
    if (!out) return;
    if (r.status !== 'success') { out.textContent = '❌ ' + r.message; return; }
    out.textContent = '── Log: ' + r.data.log_path + '\n' + r.data.log;
}

async function debugIdleApi(serverId, hostname, btn) {
    const out = _dbgStart('dbg-api-' + serverId, btn, 'Querying API…');
    try {
        const text = await fetch('php/api.php?action=idle_config&host=' + encodeURIComponent(hostname))
            .then(r => r.text());
        if (out) out.textContent = text || '(no response)';
    } catch(e) {
        if (out) out.textContent = '❌ ' + e.message;
    }
    _dbgDone(btn);
}

async function debugIdleCron(serverId, btn) {
    const out = _dbgStart('dbg-cron-' + serverId, btn, 'Checking via SSH…');
    const r = await fetch('php/api.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'debug_idle_cron', server_id: serverId }),
    }).then(r => r.json());
    _dbgDone(btn);
    if (!out) return;
    if (r.status !== 'success') { out.textContent = '❌ ' + r.message; return; }
    const d = r.data;
    out.textContent = [
        '── Script ──────────────────────────',
        d.script,
        '',
        '── Log ─────────────────────────────',
        'Script writes to: ' + d.log_file,
        'Debug reads from: ' + d.log_path,
        '',
        '── Cron ────────────────────────────',
        d.cron,
        'Cron service: ' + d.crond,
        '',
        '── WakeLab (desde el host) ─────────',
        d.wakelab,
        '',
        '── Entrada esperada ────────────────',
        d.cron_entry,
    ].join('\n');
}

async function cleanIdleHost(serverId, btn) {
    if (!confirm('Delete script, log and cron from the remote server? This clears everything to start from scratch.')) return;
    const out = _dbgStart('dbg-clean-' + serverId, btn, 'Cleaning…');
    const r = await fetch('php/api.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'clean_idle_host', server_id: serverId }),
    }).then(r => r.json());
    _dbgDone(btn);
    if (!out) return;
    if (r.status !== 'success') { out.textContent = '❌ ' + r.message; return; }
    out.textContent = r.data.cleaned
        ? '✅ Cleanup complete. You can re-deploy from scratch.'
        : '⚠️ ' + r.data.result;
    // Limpiar outputs de los otros checks
    ['dbg-api-','dbg-cron-','idle-log-'].forEach(p => {
        const el = document.getElementById(p + serverId);
        if (el) { el.textContent = ''; el.style.display = 'none'; }
    });
}

async function testEmail() {
    const el = id => document.getElementById(id);
    const r  = await fetch('php/api.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
            action:      'test_email',
            smtp_host:   el('email-smtp-host')?.value.trim()      ?? '',
            smtp_port:   el('email-smtp-port')?.value             ?? '587',
            smtp_secure: el('email-smtp-secure')?.value            ?? 'tls',
            smtp_user:   el('email-smtp-user')?.value.trim()      ?? '',
            smtp_pass:   el('email-smtp-pass')?.value              ?? '',
            from:        el('email-from')?.value.trim()            ?? '',
            from_name:   el('email-from-name')?.value.trim()       ?? 'WakeLab',
            to:          el('email-to')?.value.trim()              ?? '',
            wakelab_url: el('email-wakelab-url')?.value.trim()     ?? '',
        }),
    }).then(r => r.json());
    showToast(r.status === 'success' ? 'Email sent ✓' : 'Error: ' + r.message,
              r.status === 'success' ? 'ok' : 'err');
}


// ── Setup Guide ───────────────────────────────────────────────────────────────
function openSetupGuide(type) {
    const el = document.getElementById('setupGuideDrawer');
    if (!el) return;
    const bs = bootstrap.Offcanvas.getOrCreateInstance(el);
    bs.show();
    if (type) {
        const tab = el.querySelector(`[data-guide="${type}"]`);
        if (tab) switchGuideTab(type, tab);
    }
}

function switchGuideTab(type, btn) {
    const drawer = document.getElementById('setupGuideDrawer');
    if (!drawer) return;
    drawer.querySelectorAll('.guide-tab-btn').forEach(t => t.classList.remove('active'));
    drawer.querySelectorAll('.guide-tab-content').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    const panel = drawer.querySelector(`#guide-${type}`);
    if (panel) panel.classList.add('active');
}

function copyGuideCmd(btn) {
    const code = btn.previousElementSibling?.tagName === 'CODE'
        ? btn.previousElementSibling
        : btn.closest('.guide-code')?.querySelector('code');
    if (!code) return;
    const icon = btn.querySelector('i');
    _clipboardCopy(code.innerText.trim(),
        () => { if (icon) { icon.className = 'bi bi-check-lg'; setTimeout(() => icon.className = 'bi bi-copy', 1800); } },
        () => {}
    );
}

