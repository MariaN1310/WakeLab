// ── DESBLOQUEAR IDLE TRAS DEPLOY EXITOSO (#51) ────────────────
function unlockIdleSection(id) {
    // Ocultar badge 🔒 del header del accordion
    const badge = document.getElementById('idle-lock-badge-' + id);
    if (badge) badge.style.display = 'none';

    // Habilitar toggle "Script activo" en accordion
    const tog = document.getElementById('idle-toggle-' + id);
    if (tog) tog.disabled = false;

    // Habilitar quick toggle IDLE en dashboard card
    const qtog = document.getElementById('qidl-' + id);
    if (qtog) qtog.disabled = false;

    // Ocultar sección "Autorizar clave SSH"
    const authSec = document.getElementById('ssh-authorize-' + id);
    if (authSec) authSec.style.display = 'none';

    // Ocultar bloque SSH deploy (ya no necesario)
    ['tn_ssh', 'omv_ssh'].forEach(prefix => {
        const form = document.getElementById(`ssh-block-form-${prefix}_${id}`);
        const ok   = document.getElementById(`ssh-block-ok-${prefix}_${id}`);
        if (form) form.style.display = 'none';
        if (ok)   ok.style.display   = '';
    });
}

// ── VISIBILIDAD DE HOSTS ───────────────────────────────────────
function toggleServerVisibility(id, visible) {
    // Actualizar DOM en el momento
    const col    = document.getElementById('card-col-' + id);
    const sbItem = document.getElementById('sb-srv-' + id);
    if (col)    col.style.display    = visible ? '' : 'none';
    if (sbItem) sbItem.style.display = visible ? '' : 'none';

    fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'toggle_visibility', server_id:id, visible})})
    .then(r=>r.json())
    .then(d=>{
        if (d.status==='success') showToast(visible ? 'Host visible on dashboard' : 'Host hidden from dashboard', 'info');
        else {
            // Revert toggle if failed
            const cb = document.getElementById('vis-' + id);
            if (cb) cb.checked = !visible;
            if (col)    col.style.display    = !visible ? '' : 'none';
            if (sbItem) sbItem.style.display = !visible ? '' : 'none';
            showToast('Error: '+d.message, 'err');
        }
    })
    .catch(()=>showToast('Network error','err'));
}

// ── HELPERS ────────────────────────────────────────────────────
/** Formatea timestamp DB → respeta zona horaria de UI prefs */
function fmtTs(ts) {
    if (!ts) return '—';
    // Si fmtTimestamp está disponible (app.js), usarlo
    if (typeof fmtTimestamp === 'function') return fmtTimestamp(ts);
    const s = String(ts).replace('T', ' ');
    const m = s.match(/\d{4}-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/);
    return m ? `${m[2]}-${m[1]} ${m[3]}:${m[4]}` : ts;
}

/** Formatea unix timestamp en segundos → '28-04 14:30' */
function fmtUnix(sec) {
    if (!sec) return '—';
    const d  = new Date(sec * 1000);
    const dd = String(d.getDate()).padStart(2,'0');
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const hh = String(d.getHours()).padStart(2,'0');
    const mi = String(d.getMinutes()).padStart(2,'0');
    return `${dd}-${mm} ${hh}:${mi}`;
}

/** Formatea ISO string o Date object → '28-04 14:30' */
function fmtIso(iso) {
    if (!iso) return '—';
    const d  = new Date(iso);
    if (isNaN(d)) return String(iso);
    const dd = String(d.getDate()).padStart(2,'0');
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const hh = String(d.getHours()).padStart(2,'0');
    const mi = String(d.getMinutes()).padStart(2,'0');
    return `${dd}-${mm} ${hh}:${mi}`;
}

/** Puebla los dos <select> generados por timePair() dado un string "HH:MM:SS" o "HH:MM" */
function setTimePair(id, timeStr) {
    const parts = (timeStr || '00:00').split(':');
    const hSel = document.getElementById(id + '_h');
    const mSel = document.getElementById(id + '_m');
    const h = parseInt(parts[0] || '0', 10);
    const raw = parseInt(parts[1] || '0', 10);
    const rounded = Math.round(raw / 5) * 5;
    if (rounded >= 60) {
        if (hSel) hSel.value = String((h + 1) % 24).padStart(2, '0');
        if (mSel) mSel.value = '00';
    } else {
        if (hSel) hSel.value = String(h).padStart(2, '0');
        if (mSel) mSel.value = String(rounded).padStart(2, '0');
    }
}

function showToast(msg, type='ok', detail=''){
    const icons = {
        ok:   'bi-check-circle-fill',
        err:  'bi-x-circle-fill',
        warn: 'bi-exclamation-triangle-fill',
        info: 'bi-info-circle-fill',
    };
    const el = document.getElementById('toast-el');
    el.className = 'toast toast-' + type;
    const iconEl = document.getElementById('toast-icon');
    if (iconEl) iconEl.className = 'bi ' + (icons[type] || icons.ok);
    document.getElementById('toast-msg').textContent = msg;
    const detailEl = document.getElementById('toast-detail');
    if (detailEl) { detailEl.textContent = detail || ''; detailEl.style.display = detail ? '' : 'none'; }
    bootstrap.Toast.getOrCreateInstance(el, {delay: type==='warn' ? 6000 : 3500}).show();
}

// ── NAVEGACIÓN ─────────────────────────────────────────────────
// Navegación mediante nav oculto + sidebar visual
function sidebarNav(sidebarEl, hiddenBtnId) {
    const hidden = document.getElementById(hiddenBtnId);
    if (hidden) bootstrap.Tab.getOrCreateInstance(hidden).show();
    _setSidebarActive(sidebarEl);
    const ca = document.querySelector('.content-area');
    if (ca) ca.scrollTop = 0;
    window.scrollTo({ top: 0 });
}

function _setSidebarActive(el) {
    document.querySelectorAll('.sidebar-item').forEach(b => b.classList.remove('active'));
    if (el) el.classList.add('active');
    closeSidebar();
}

// Ir a tab de servidor desde card del dashboard o desde JS
function showSrvTab(id) {
    const sbBtn = document.getElementById('sb-srv-' + id);
    sidebarNav(sbBtn, 'htab-srv-' + id);
}

// Ir a tab + abrir sección de config (schedule/idle)
function showSrvConfig(id) {
    showSrvTab(id);
    setTimeout(() => {
        const schCollapse = document.getElementById('acc-sch-' + id);
        if (schCollapse) {
            bootstrap.Collapse.getOrCreateInstance(schCollapse, { toggle: false }).show();
            schCollapse.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, 220); // esperar a que termine la transición del tab
}


// Mobile sidebar (hamburger)
function _lockBodyScroll(lock) {
    document.body.style.overflow = lock ? 'hidden' : '';
    document.documentElement.style.overflow = lock ? 'hidden' : '';
}
function toggleSidebar() {
    const open = document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebar-overlay').classList.toggle('open');
    _lockBodyScroll(open);
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebar-overlay').classList.remove('open');
    _lockBodyScroll(false);
}

// Desktop sidebar collapse (icon-only mode)
function toggleSidebarCollapse() {
    const sidebar = document.getElementById('sidebar');
    const icon    = document.getElementById('sidebar-collapse-icon');
    const collapsed = sidebar.classList.toggle('collapsed');
    icon.innerHTML  = collapsed ? '&#8250;&#8250;' : '&#8249;&#8249;';
    localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
}

// Restaurar estado de collapse al cargar
document.addEventListener('DOMContentLoaded', () => {
    if (localStorage.getItem('sidebarCollapsed') === '1') {
        const sidebar = document.getElementById('sidebar');
        const icon    = document.getElementById('sidebar-collapse-icon');
        if (sidebar) sidebar.classList.add('collapsed');
        if (icon)    icon.innerHTML = '&#8250;&#8250;';
    }
});

// Quick toggles en cards del dashboard — debounced para evitar spam de clicks
// Sincroniza toggles del card dashboard ↔ panel detalle + colores de horario
function syncQuickCfg(srvId, schOn, shutOn) {
    // Card dashboard
    const qsch  = document.getElementById('qsch-'  + srvId);
    const qshut = document.getElementById('qshut-' + srvId);
    if (qsch)  qsch.checked  = !!schOn;
    if (qshut) qshut.checked = !!shutOn;

    // Texto y colores de quick-time
    const card = document.getElementById('card-' + srvId);
    if (card) {
        const times = card.querySelectorAll('.quick-time');
        // Leer horarios actuales desde los selectores del panel detalle
        const bh = document.getElementById('boot_' + srvId + '_h')?.value;
        const bm = document.getElementById('boot_' + srvId + '_m')?.value;
        const sh = document.getElementById('shut_' + srvId + '_h')?.value;
        const sm = document.getElementById('shut_' + srvId + '_m')?.value;
        if (times[0]) {
            if (bh !== undefined) times[0].textContent = bh + ':' + bm;
            times[0].style.color   = schOn  ? 'var(--green)' : '';
            times[0].style.opacity = schOn  ? ''             : '.35';
        }
        if (times[1]) {
            if (sh !== undefined) times[1].textContent = sh + ':' + sm;
            times[1].style.color   = shutOn ? 'var(--red)' : '';
            times[1].style.opacity = shutOn ? ''           : '.35';
        }
    }

    // Panel detalle — actualiza estado visual del bloque sin re-guardar
    const schTab  = document.getElementById('sch-toggle-'  + srvId);
    const shutTab = document.getElementById('shut-toggle-' + srvId);
    if (schTab  && schTab.checked  !== !!schOn)  {
        schTab.checked = !!schOn;
        // Actualizar opacidad del bloque de encendido
        const bb = document.getElementById('boot-block-'   + srvId);
        const br = document.getElementById('boot-row-'     + srvId);
        const bo = document.getElementById('boot-row-off-' + srvId);
        if (bb) { bb.style.opacity = schOn ? '1' : '.45'; bb.style.borderColor = 'color-mix(in srgb,var(--green) ' + (schOn ? '20%' : '10%') + ',transparent)'; }
        if (br) br.style.display = schOn ? '' : 'none';
        if (bo) bo.style.display = schOn ? 'none' : '';
    }
    if (shutTab && shutTab.checked !== !!shutOn) {
        shutTab.checked = !!shutOn;
        const sb  = document.getElementById('shut-block-'   + srvId);
        const sr  = document.getElementById('shut-row-'     + srvId);
        const sro = document.getElementById('shut-row-off-' + srvId);
        if (sb)  { sb.style.opacity = shutOn ? '1' : '.45'; sb.style.borderColor = 'color-mix(in srgb,var(--red) ' + (shutOn ? '20%' : '10%') + ',transparent)'; }
        if (sr)  sr.style.display  = shutOn ? '' : 'none';
        if (sro) sro.style.display = shutOn ? 'none' : '';
    }
}

const _quickToggleTimers = new Map();
function quickToggle(srvId, type, active) {
    const key = srvId + '_' + type;
    if (_quickToggleTimers.has(key)) clearTimeout(_quickToggleTimers.get(key));
    _quickToggleTimers.set(key, setTimeout(() => {
        _quickToggleTimers.delete(key);
        const action = type === 'schedule' ? 'set_schedule_active'
                     : type === 'shutdown' ? 'set_shutdown_active'
                     : 'set_idle_active';
        fetch('php/api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action, server_id: srvId, active: active ? 1 : 0 })
        }).then(r => r.json()).then(d => {
            if (d.status !== 'success') {
                showToast(d.message || 'Error saving', 'err');
                const elId = type === 'schedule' ? 'qsch-' : type === 'shutdown' ? 'qshut-' : 'qidl-';
                const el = document.getElementById(elId + srvId);
                if (el) el.checked = !active;
            } else {
                showToast(type === 'schedule' ? 'Boot schedule updated' : type === 'shutdown' ? 'Shutdown schedule updated' : 'Idle updated', 'ok');
                // Idle — solo sincronizar toggle del panel
                if (type === 'idle') {
                    const tabEl = document.getElementById('idle-toggle-' + srvId);
                    if (tabEl) tabEl.checked = active;
                } else {
                    // schedule/shutdown — usar syncQuickCfg para actualizar todo coherentemente
                    const schOn  = type === 'schedule' ? active : !!(document.getElementById('qsch-'  + srvId)?.checked);
                    const shutOn = type === 'shutdown' ? active : !!(document.getElementById('qshut-' + srvId)?.checked);
                    syncQuickCfg(srvId, schOn, shutOn);
                }
            }
        }).catch(() => showToast('Network error', 'err'));
    }, 400));
}

// ── MODAL ACCIÓN (servidor y guests) ──────────────────────────
let pendingAction=null, pendingGuest=null;

const _AM_COLORS = {
    red:   { hdr:'rgba(248,81,73,.08)',  ring:'rgba(248,81,73,.18)',  icon:'#f85149', btn:'btn-danger'   },
    green: { hdr:'rgba(63,185,80,.08)',  ring:'rgba(63,185,80,.18)',  icon:'#3fb950', btn:'btn-success'  },
    blue:  { hdr:'rgba(88,166,255,.08)', ring:'rgba(88,166,255,.18)', icon:'#58a6ff', btn:'btn-primary'  },
};

const AM = {
    shutdown: { label:'Shutdown',  biIcon:'bi-power',                color:'red',   desc:'The server will be safely shut down.' },
    reboot:   { label:'Reboot',    biIcon:'bi-arrow-clockwise',      color:'blue',  desc:'The server will be rebooted.' },
    wol:      { label:'Wake up',   biIcon:'bi-lightning-charge-fill',color:'green', desc:'A magic packet will be sent to wake the server.' },
};
const GM = {
    stop:   { label:'Shutdown', biIcon:'bi-power',           color:'red',   desc:'The guest will be safely shut down.' },
    reboot: { label:'Reboot',   biIcon:'bi-arrow-clockwise', color:'blue',  desc:'The guest will be rebooted.' },
    start:  { label:'Start',    biIcon:'bi-play-fill',        color:'green', desc:'The guest will be started.' },
};

function _applyModal(title, desc, badge, badgeIcon, m) {
    const c = _AM_COLORS[m.color] || _AM_COLORS.red;
    document.getElementById('am-header').style.background   = c.hdr;
    document.getElementById('am-icon-ring').style.background = c.ring;
    document.getElementById('am-icon-ring').style.color      = c.icon;
    const iconEl = document.getElementById('modal-icon');
    iconEl.className = 'bi ' + m.biIcon;
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalMsg').textContent   = desc;
    const badgeEl = document.getElementById('modal-srv-badge');
    badgeEl.innerHTML = `<i class="bi ${badgeIcon} me-1"></i>${badge}`;
    const cb = document.getElementById('confirmBtn');
    cb.className = 'btn am-confirm-btn ' + c.btn;
    cb.textContent = 'Confirm'; cb.disabled = false;
}

function confirmAction(id, hn, cmd) {
    pendingAction = { server_id: id, command: cmd, hostname: hn };
    const m = AM[cmd] || { label:cmd, biIcon:'bi-question', color:'red', desc:'' };
    _applyModal(m.label, m.desc, hn, 'bi-server', m);
    document.getElementById('confirmBtn').dataset.mode = 'server';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('actionModal')).show();
}

function confirmGuestAction(srvId, vmid, vmtype, vmname, cmd) {
    pendingGuest = { server_id:srvId, vmid, vmtype, command:cmd };
    const m = GM[cmd] || { label:cmd, biIcon:'bi-question', color:'red', desc:'' };
    _applyModal(m.label + ' — ' + vmname, m.desc, vmtype.toUpperCase() + ' #' + vmid, 'bi-cpu', m);
    const cb = document.getElementById('confirmBtn');
    cb.dataset.mode = 'guest';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('actionModal')).show();
}

function closeModal(){
    bootstrap.Modal.getOrCreateInstance(document.getElementById('actionModal')).hide();
    pendingAction=null; pendingGuest=null;
}

document.getElementById('actionModal').addEventListener('hidden.bs.modal', ()=>{
    pendingAction=null; pendingGuest=null;
    const cb = document.getElementById('confirmBtn');
    cb.disabled = false; cb.textContent = 'Confirm';
});

document.getElementById('confirmBtn').addEventListener('click',()=>{
    const cb=document.getElementById('confirmBtn');
    cb.textContent='Sending…'; cb.disabled=true;

    if(cb.dataset.mode==='guest'){
        if(!pendingGuest){cb.textContent='Execute';cb.disabled=false;return;}
        const pg=pendingGuest;
        fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},
            body:JSON.stringify({action:'guest_action',...pg})})
        .then(r=>r.json())
        .then(d=>{
            closeModal();
            if(d.status==='already'){showToast(d.message||'Already in that state','info');return;}
            if(d.status==='locked') {showToast(d.message||'Signal in progress, please wait','warn');return;}
            if(d.status==='success') showToast(`#${pg.vmid} — ${pg.command} OK`,'ok');
            else showToast('Error: '+(d.message||'check logs'),'err');
            setTimeout(refreshStatus,2500);
            setTimeout(loadLogs,600);
        })
        .catch(()=>{showToast('Network error','err');cb.textContent='Execute';cb.disabled=false;});

    } else if(cb.dataset.mode==='delete'){
        if(!pendingAction){cb.textContent='Delete';cb.disabled=false;return;}
        const pa=pendingAction;
        fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},
            body:JSON.stringify({action:'delete_server',server_id:pa.server_id})})
        .then(r=>r.json())
        .then(d=>{
            closeModal();
            if(d.status==='success'){showToast('Server deleted','ok');setTimeout(()=>location.reload(),1000);}
            else showToast(d.message||'Error','err');
        })
        .catch(()=>{showToast('Error','err');cb.textContent='Delete';cb.disabled=false;});

    } else {
        if(!pendingAction){cb.textContent='Execute';cb.disabled=false;return;}
        const pa=pendingAction;
        fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},
            body:JSON.stringify({action:'server_action',...pa})})
        .then(r=>r.json())
        .then(d=>{
            closeModal();
            if(d.status==='already'){showToast(d.message||'Already in that state','info');return;}
            if(d.status==='locked') {showToast(d.message||'Signal in progress, please wait','warn');return;}
            if(d.status==='success'){
                const _tm={wol:'WoL signal sent',shutdown:'Shutdown signal sent',reboot:'Reboot signal sent'};
                showToast((_tm[pa.command]||'Signal sent'), 'ok', pa.hostname||'');
                // #19 — Verificar apagado efectivo después de 90s
                if (pa.command === 'shutdown') {
                    const shutTimeout = window.serverShutdownTimeout?.get(pa.server_id) ?? 90;
                    window.registerShutdownCheck?.(pa.server_id, pa.hostname, shutTimeout);
                }
            } else showToast('Error: '+(d.message||'check logs'),'err');
            setTimeout(loadLogs,600);
        })
        .catch(()=>{showToast('Network error','err');cb.textContent='Execute';cb.disabled=false;});
    }
});

// ── MODAL AGREGAR ──────────────────────────────────────────────
function openAddModal(){bootstrap.Modal.getOrCreateInstance(document.getElementById('addModal')).show();}
function closeAddModal(){bootstrap.Modal.getOrCreateInstance(document.getElementById('addModal')).hide();}

document.getElementById('addModal').addEventListener('hidden.bs.modal', () => {
    const form = document.getElementById('addModal').querySelector('form');
    if (form) { form.reset(); form.style.display = ''; }
    document.getElementById('new-api-row').style.display          = '';
    document.getElementById('new-token-section').style.display    = 'none';
    document.getElementById('new-ssh-section').style.display      = 'none';
    document.getElementById('new-vm-section').style.display       = 'none';
    document.getElementById('new-idle-row').style.display         = 'none';
    document.getElementById('new-idle-ssh-section').style.display = 'none';
    document.getElementById('new-step2').style.display            = 'none';
    document.getElementById('new-step2-idle').style.display       = 'none';
    document.getElementById('new-deploy-result').style.display    = 'none';
    const deployBtn = document.getElementById('new-deploy-btn');
    if (deployBtn) { deployBtn.textContent = 'Deploy idle script'; deployBtn.disabled = false; deployBtn.className = 'btn btn-outline-primary w-100'; }
    const vmToggleRow = document.getElementById('new_is_vm')?.closest('.fmodal-toggle-row');
    if (vmToggleRow) vmToggleRow.style.display = '';
    window._newServerId = null;
    // Reset type picker
    document.querySelectorAll('#new-type-picker .type-btn').forEach(b => b.classList.remove('selected'));
    const newHyp = document.getElementById('new_hyp');
    if (newHyp) newHyp.value = '';
});

function onNewHypChange(val){
    const apiRow  = document.getElementById('new-api-row');
    const sec     = document.getElementById('new-token-section');
    const sshSec  = document.getElementById('new-ssh-section');
    const apiChk  = document.getElementById('new_api');
    const isTN    = val === 'truenas';
    const isOMV   = val === 'omv';
    const isPC    = val === 'windows' || val === 'linux';
    const userRow = document.getElementById('nr-user-row');
    const tidRow  = document.getElementById('nr-tid-row');
    const lbl     = document.getElementById('nr-secret-label');
    const userLbl = userRow?.querySelector('.form-label');
    const portEl  = document.getElementById('new_port');
    const sshUser = document.getElementById('new_ssh_user');
    const vmToggleRow = document.getElementById('new_is_vm')?.closest('.fmodal-toggle-row');
    const vmSection   = document.getElementById('new-vm-section');

    if (isPC) {
        // PC: hide API section, show SSH section, set port 22, hide VM toggle
        if (apiRow)      apiRow.style.display      = 'none';
        if (sec)         sec.style.display          = 'none';
        if (sshSec)      sshSec.style.display       = 'block';
        if (portEl)      portEl.value               = 22;
        if (sshUser)     sshUser.placeholder        = val === 'windows' ? 'Administrator' : 'root';
        if (vmToggleRow) vmToggleRow.style.display  = 'none';
        if (vmSection)   vmSection.style.display    = 'none';
        // Idle toggle: linux yes, windows no
        const idleRow = document.getElementById('new-idle-row');
        if (idleRow) idleRow.style.display = val === 'linux' ? '' : 'none';
        if (val !== 'linux') {
            const idleTog = document.getElementById('new_idle');
            if (idleTog) idleTog.checked = false;
            onNewIdleChange(false);
        }
        // linux idle: no SSH section needed (usa new_ssh_user/pass)
        if (val === 'linux') onNewIdleChange(document.getElementById('new_idle')?.checked || false);
        return;
    }

    // Non-PC: restore API section, hide SSH section
    if (apiRow)      apiRow.style.display      = '';
    if (sshSec)      sshSec.style.display      = 'none';
    if (sec)         sec.style.display         = apiChk?.checked ? 'block' : 'none';
    if (vmToggleRow) vmToggleRow.style.display = '';

    // Port defaults per type
    const portMap = {pve:8006, pbs:8007, truenas:443, omv:80};
    if (portEl && portMap[val]) portEl.value = portMap[val];

    // TrueNAS: solo API Key (sin usuario ni token ID)
    // OMV: usuario + password (sin token ID)
    // PVE/PBS: usuario + token ID + secret
    if (userRow) userRow.style.display = isTN ? 'none' : '';
    if (tidRow)  tidRow.style.display  = (isTN || isOMV) ? 'none' : '';
    if (userLbl) userLbl.textContent   = isOMV ? 'User' : 'API User';
    if (lbl)     lbl.textContent       = isTN  ? 'API Key' : isOMV ? 'Password' : 'Token Secret';

    // OMV: pre-fill usuario admin
    const apuEl = document.getElementById('new_apu');
    if (apuEl && isOMV) apuEl.value = apuEl.value || 'admin';

    // Idle toggle: visible para tipos con soporte (pve, pbs, truenas, omv, linux)
    const idleRow = document.getElementById('new-idle-row');
    const supportsIdle = ['pve','pbs','truenas','omv','linux'].includes(val);
    if (idleRow) idleRow.style.display = supportsIdle ? '' : 'none';
    if (!supportsIdle) {
        const idleTog = document.getElementById('new_idle');
        if (idleTog) idleTog.checked = false;
        onNewIdleChange(false);
    }
}

function onNewIdleChange(checked) {
    const val = document.getElementById('new_hyp')?.value || '';
    // linux usa los campos SSH del formulario principal (new_ssh_user/pass)
    const needsSshSection = checked && ['pve','pbs','truenas','omv'].includes(val);
    const sshSec = document.getElementById('new-idle-ssh-section');
    if (sshSec) sshSec.style.display = needsSshSection ? '' : 'none';
}

function onNewApiChange(checked){
    document.getElementById('new-token-section').style.display=checked?'block':'none';
}

function onNewIsVmChange(checked){
    document.getElementById('new-vm-section').style.display=checked?'block':'none';
    const macRow=document.getElementById('new-mac-row');
    const macInput=document.getElementById('new_mac');
    if(macRow) macRow.style.display=checked?'none':'';
    if(macInput){ if(checked) macInput.removeAttribute('required'); }
    if(!checked){
        document.getElementById('new_pve_srv').value='';
        document.getElementById('new_vmid').value='';
    }
    // Auto-select boot method hint (not in add modal since no method field there)
}

function onIsVmChange(id,checked){
    document.getElementById('vm-section-'+id).style.display=checked?'block':'none';
    const macRow=document.getElementById('mac-row-'+id);
    if(macRow) macRow.style.display=checked?'none':'';
    if(!checked){
        document.getElementById('pve_srv_'+id).value='';
        document.getElementById('vmid_'+id).value='';
    }
    // Auto-select boot method based on VM status
    const methEl = document.getElementById('meth_'+id);
    if(methEl) methEl.value = checked ? 'Proxmox API' : 'Wake on LAN';
}

function onApiChange(id,checked){
    const sec=document.getElementById('token-section-'+id);
    if(sec) sec.style.display=checked?'':'none';
}

// ── VM DRAWER (offcanvas) ──────────────────────────────────────
let currentVm = null;

function updateDrawerBars(vm) {
    const isLxc   = vm.type === 'lxc';
    const ramUsed = vm.mem    ?? 0, ramMax = vm.maxmem  ?? 0;
    const dskUsed = vm.disk   ?? 0, dskMax = vm.maxdisk ?? 0;
    const ramPct  = ramMax ? Math.round(ramUsed / ramMax * 100) : 0;

    document.getElementById('vd-ram-txt').textContent = ramMax ? fmtBytes(ramUsed) + ' / ' + fmtBytes(ramMax) : '—';
    document.getElementById('vd-ram-pct').textContent = ramMax ? ramPct + '%' : '';
    const ramBar = document.getElementById('vd-ram-bar');
    ramBar.style.width = ramPct + '%';
    ramBar.className   = 'prog-bar ' + (ramPct > 85 ? 'prog-red' : ramPct > 60 ? 'prog-amber' : 'prog-blue');

    const dskBar = document.getElementById('vd-dsk-bar');
    if (isLxc) {
        const dskPct = dskMax ? Math.round(dskUsed / dskMax * 100) : 0;
        document.getElementById('vd-dsk-txt').textContent = dskMax ? fmtBytes(dskUsed) + ' / ' + fmtBytes(dskMax) : '—';
        document.getElementById('vd-dsk-pct').textContent = dskMax ? dskPct + '%' : '';
        dskBar.style.width = dskPct + '%';
        dskBar.className   = 'prog-bar ' + (dskPct > 85 ? 'prog-red' : dskPct > 60 ? 'prog-amber' : 'prog-green');
    } else {
        document.getElementById('vd-dsk-txt').textContent = dskMax ? fmtBytes(dskMax) : '—';
        document.getElementById('vd-dsk-pct').textContent = '';
        dskBar.style.width = dskMax ? '100%' : '0%';
        dskBar.className   = 'prog-bar prog-green';
    }
}

function openVmDrawer(srvId, vm) {
    if (!srvId || !vm?.vmid) return;
    currentVm = { srvId, ...vm };
    const isLxc = vm.type === 'lxc';

    const drawerBody = document.querySelector('#vmDrawer .offcanvas-body');
    if (drawerBody) drawerBody.scrollTop = 0;

    document.getElementById('vd-name').textContent       = vm.name || 'vm-' + vm.vmid;
    const badge = document.getElementById('vd-type-badge');
    badge.textContent  = isLxc ? 'LXC' : 'VM';
    badge.className    = isLxc ? 'vm-type lxc' : 'vm-type';

    document.getElementById('vd-id').textContent   = '#' + vm.vmid;
    document.getElementById('vd-cpu').textContent  = vm.cpu != null ? (vm.cpu*100).toFixed(0)+'%' : '—';
    document.getElementById('vd-vcpu').textContent = vm.cpus ? vm.cpus+' vCPU' : '—';
    document.getElementById('vd-status').innerHTML = vmPill(vm.status);

    // Restaurar visibilidad de secciones (pueden haber sido ocultadas por openTNDrawer)
    const barsEl = document.getElementById('vd-bars');
    if (barsEl) barsEl.style.display = '';
    ['vd-section-schedule','vd-url-section'].forEach(eid => {
        const se = document.getElementById(eid);
        if (se) se.style.display = '';
    });

    updateDrawerBars(vm);

    const isRunning = vm.status === 'running';
    document.getElementById('vd-actions').innerHTML = isRunning ? `
        <button class="btn btn-outline-primary" style="flex:1;padding:8px 0" onclick="drawerAction('reboot')">reboot</button>
        <button class="btn btn-outline-danger"  style="flex:1;padding:8px 0" onclick="drawerAction('stop')">shutdown</button>
    ` : `
        <button class="btn btn-outline-success" style="flex:1;padding:8px 0" onclick="drawerAction('start')">wake up</button>
    `;

    // Reset drawer a defaults
    document.getElementById('vd-sch-toggle').checked  = false;
    document.getElementById('vd-shut-toggle').checked = false;
    ['vd-boot-block','vd-shut-block'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.style.opacity = '.45'; el.style.borderColor = ''; }
    });
    ['vd-boot-row','vd-shut-row'].forEach(id => { const el=document.getElementById(id); if(el) el.style.display='none'; });
    ['vd-boot-row-off','vd-shut-row-off'].forEach(id => { const el=document.getElementById(id); if(el) el.style.display=''; });

    bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('vmDrawer')).show();

    // Secciones de schedule/idle vs sección vinculada
    const secSch    = document.getElementById('vd-section-schedule');
    const secLinked = document.getElementById('vd-linked-section');

    if (vm.linked_server_id) {
        // Este guest es un host registrado — mostrar sección compacta, ocultar las de edición
        if (secSch)    secSch.style.display    = 'none';
        if (secLinked) secLinked.style.display = '';

        // Botón "ir al tab" del servidor vinculado
        const gotoBtn = document.getElementById('vd-linked-goto');
        if (gotoBtn) {
            gotoBtn.onclick = () => {
                bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('vmDrawer')).hide();
                showSrvTab(vm.linked_server_id);
            };
        }

        // Cargar y mostrar schedule/idle del servidor vinculado
        const infoEl = document.getElementById('vd-linked-info');
        if (infoEl) infoEl.innerHTML = '<span style="color:var(--text-dim)">loading…</span>';
        fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'get_server_schedule', server_id: vm.linked_server_id})})
        .then(r=>r.json())
        .then(resp => {
            if (resp.status === 'success') populateLinkedInfo(resp.data.schedule, resp.data.idle, vm.linked_hostname);
        }).catch(()=>{ if(infoEl) infoEl.innerHTML='<span style="color:#f85149">error loading data</span>'; });

    } else {
        // VM normal — secciones de edición visibles, sección vinculada oculta
        if (secSch)    secSch.style.display    = '';
        if (secLinked) secLinked.style.display = 'none';

        // Cargar schedule + idle + meta en un solo fetch
        const _vdDtz = (typeof loadUiPrefs === 'function' ? loadUiPrefs() : JSON.parse(localStorage.getItem('wakelab_ui')||'{}')).timezone || '';
        fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'get_guest_data', server_id: srvId, vmid: vm.vmid, display_tz: _vdDtz})})
        .then(r=>r.json())
        .then(resp => {
            const s = resp.data?.schedule;
            if (s) {
                const bootOn = !!parseInt(s.boot_active ?? 0);
                const shutOn = !!parseInt(s.shutdown_active ?? 0);
                setTimePair('vd-boot-time',     s.boot_time     || '08:00:00');
                setTimePair('vd-shutdown-time', s.shutdown_time || '00:00:00');
                document.getElementById('vd-sch-toggle').checked  = bootOn;
                document.getElementById('vd-shut-toggle').checked = shutOn;
                const bootBlock = document.getElementById('vd-boot-block');
                const shutBlock = document.getElementById('vd-shut-block');
                if (bootBlock) { bootBlock.style.opacity = bootOn?'1':'.45'; bootBlock.style.borderColor='color-mix(in srgb,var(--green) '+(bootOn?'20%':'10%')+',transparent)'; }
                if (shutBlock) { shutBlock.style.opacity = shutOn?'1':'.45'; shutBlock.style.borderColor='color-mix(in srgb,var(--red) '+(shutOn?'20%':'10%')+',transparent)'; }
                document.getElementById('vd-boot-row').style.display     = bootOn ? '' : 'none';
                document.getElementById('vd-boot-row-off').style.display = bootOn ? 'none' : '';
                document.getElementById('vd-shut-row').style.display     = shutOn ? '' : 'none';
                document.getElementById('vd-shut-row-off').style.display = shutOn ? 'none' : '';
            }
            // URL meta para LXC
            if (isLxc) {
                const m = resp.data?.meta;
                if (m && m.url) {
                    document.getElementById('vd-url-input').value            = m.url;
                    document.getElementById('vd-url-open-row').style.display = 'block';
                    document.getElementById('vd-url-toggle-btn').textContent  = 'edit ▾';
                    const link = document.getElementById('vd-url-open-link');
                    if (link) link.href = /^https?:\/\//i.test(m.url) ? m.url : '#';
                } else {
                    document.getElementById('vd-url-config').style.display  = 'block';
                    document.getElementById('vd-url-toggle-btn').textContent = 'hide ▴';
                }
            }
        }).finally(() => {
            ['vd-boot-time_h','vd-boot-time_m','vd-shutdown-time_h','vd-shutdown-time_m'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.onchange = () => saveVmSchedule();
            });
        });
    }

    const urlSection = document.getElementById('vd-url-section');
    urlSection.style.display = isLxc ? 'block' : 'none';
    if (isLxc) {
        document.getElementById('vd-url-input').value = '';
        document.getElementById('vd-url-open-row').style.display = 'none';
        document.getElementById('vd-url-config').style.display   = 'none';
        document.getElementById('vd-url-toggle-btn').textContent  = 'configure ▾';
    }
}

function closeVmDrawer() {
    bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('vmDrawer')).hide();
    currentVm = null;
}

document.getElementById('vmDrawer').addEventListener('hidden.bs.offcanvas', ()=>{ currentVm = null; });

// ── Drawer simplificado para TrueNAS apps/VMs ─────────────────
function openTNDrawer(srvId, item) {
    currentVm = { srvId, ...item };
    const isTNApp = item.type === 'truenas_app';
    const isRunning = (item.state || '').toUpperCase() === 'RUNNING';

    const drawerBody = document.querySelector('#vmDrawer .offcanvas-body');
    if (drawerBody) drawerBody.scrollTop = 0;

    document.getElementById('vd-name').textContent = item.name;
    const badge = document.getElementById('vd-type-badge');
    badge.textContent = isTNApp ? 'App' : 'VM';
    badge.className   = isTNApp ? 'vm-type' : 'vm-type';

    document.getElementById('vd-id').textContent   = isTNApp ? '' : ('#' + item.vmid);
    document.getElementById('vd-cpu').textContent  = isTNApp ? '—' : (item.vcpus ? item.vcpus + ' vCPU' : '—');
    document.getElementById('vd-vcpu').textContent = '';
    document.getElementById('vd-status').innerHTML = tnStatePill(item.state || 'UNKNOWN');

    // Ocultar métricas no disponibles
    const barsEl = document.getElementById('vd-bars');
    if (barsEl) barsEl.style.display = 'none';

    document.getElementById('vd-actions').innerHTML = isRunning ? `
        <button class="btn btn-outline-danger" style="flex:1;padding:8px 0" onclick="tnDrawerAction('stop')">stop</button>
    ` : `
        <button class="btn btn-outline-success" style="flex:1;padding:8px 0" onclick="tnDrawerAction('start')">start</button>
    `;

    // Ocultar secciones PVE-específicas
    ['vd-section-schedule','vd-linked-section','vd-url-section'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('vmDrawer')).show();
}

function tnDrawerAction(cmd) {
    if (!currentVm) return;
    const name = currentVm.name;
    const srvId = currentVm.srvId;
    const isTNApp = currentVm.type === 'truenas_app';
    const body = isTNApp
        ? { action: 'guest_action', server_id: srvId, vmtype: 'truenas_app', app_name: currentVm.app_name, command: cmd }
        : { action: 'guest_action', server_id: srvId, vmtype: 'truenas_vm',  vmid: currentVm.vmid,         command: cmd };

    fetch('php/api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'success') showToast(`${name} — ${cmd}`, 'ok');
        else showToast(`Error: ${d.message || 'unknown'}`, 'err');
    })
    .catch(() => showToast('Network error', 'err'));
}

function drawerAction(cmd) {
    if (!currentVm) return;
    const vmName = escHtml(currentVm.name || 'vm-' + currentVm.vmid);
    confirmGuestAction(currentVm.srvId, currentVm.vmid, currentVm.type, vmName, cmd);
}

// Actualiza solo los campos dinámicos del drawer sin resetear scroll ni secciones
function refreshVmDrawer(vm) {
    if (!vm) return;
    Object.assign(currentVm, vm);

    document.getElementById('vd-cpu').textContent    = vm.cpu != null ? (vm.cpu*100).toFixed(0)+'%' : '—';
    document.getElementById('vd-status').innerHTML = vmPill(vm.status);
    updateDrawerBars(vm);

    const isRunning = vm.status === 'running';
    document.getElementById('vd-actions').innerHTML = isRunning ? `
        <button class="btn btn-outline-primary" style="flex:1;padding:8px 0" onclick="drawerAction('reboot')">reboot</button>
        <button class="btn btn-outline-danger"  style="flex:1;padding:8px 0" onclick="drawerAction('stop')">shutdown</button>
    ` : `
        <button class="btn btn-outline-success" style="flex:1;padding:8px 0" onclick="drawerAction('start')">wake up</button>
    `;
}

function filterVMs(id, q) {
    const el = document.getElementById('vm-list-' + id);
    if (!el) return;
    const term = q.trim().toLowerCase();
    el.querySelectorAll('.vm-card').forEach(card => {
        const vmid = String(card.dataset.vmidx || '');
        const name = (card.querySelector('.vm-name')?.textContent || '').toLowerCase();
        card.style.display = (!term || name.includes(term) || vmid.includes(term)) ? '' : 'none';
    });
}

function toggleUrlConfig() {
    const cfg = document.getElementById('vd-url-config');
    const btn = document.getElementById('vd-url-toggle-btn');
    const open = cfg.style.display === 'none';
    cfg.style.display = open ? 'block' : 'none';
    const hasUrl = document.getElementById('vd-url-input').value.trim();
    btn.textContent = open ? 'hide ▴' : (hasUrl ? 'edit ▾' : 'configure ▾');
}

// ── LINKED SERVER INFO (VM drawer) ─────────────────────────────
function populateLinkedInfo(schedule, idle, hostname) {
    const el = document.getElementById('vd-linked-info');
    if (!el) return;

    const fmt = t => (t || '').slice(0, 5) || '—';
    let html = `<div style="color:var(--text-dim);font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;margin-bottom:2px">${escHtml(hostname || '')}</div>`;

    if (schedule) {
        const active = parseInt(schedule.active);
        html += `
        <div style="display:flex;justify-content:space-between">
            <span style="color:var(--text-dim)">Boot</span>
            <span style="color:var(--text-muted)">${fmt(schedule.boot_time)}</span>
        </div>
        <div style="display:flex;justify-content:space-between">
            <span style="color:var(--text-dim)">Shutdown</span>
            <span style="color:var(--text-muted)">${fmt(schedule.shutdown_time)}</span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center">
            <span style="color:var(--text-dim)">Schedule</span>
            ${active ? '<span class="badge bg-success">active</span>' : '<span class="badge bg-secondary">inactive</span>'}
        </div>`;
    } else {
        html += '<div style="color:var(--text-dim)">no schedule configured</div>';
    }

    if (idle) {
        const idleActive = parseInt(idle.active);
        const idleMin    = Math.round(parseInt(idle.idle_limit_sec || 0) / 60);
        html += `
        <div style="border-top:1px solid var(--border);padding-top:5px;margin-top:1px;display:flex;justify-content:space-between;align-items:center">
            <span style="color:var(--text-dim)">Idle auto-off</span>
            ${idleActive
                ? `<span class="badge bg-warning">${idleMin} min</span>`
                : '<span class="badge bg-secondary">inactive</span>'}
        </div>`;
    }

    el.innerHTML = html;
}

// ── RENDER GUESTS ───────────────────────────────────────────
const vmDataMap = {};

document.addEventListener('click', e => {
    const card = e.target.closest('.vm-card[data-srvid]');
    if (!card) return;
    const srvId = parseInt(card.dataset.srvid);
    const vmid  = parseInt(card.dataset.vmidx);
    const vm    = vmDataMap[srvId + '_' + vmid];
    if (vm) openVmDrawer(srvId, vm);
});

function vmPill(s){
    if(s==='running') return '<span class="badge bg-success">running</span>';
    if(s==='stopped') return '<span class="badge bg-secondary">stopped</span>';
    if(s==='paused')  return '<span class="badge bg-warning">paused</span>';
    return `<span class="badge bg-secondary">${escHtml(s)}</span>`;
}

function fmtBytes(b){
    if (!b) return '—';
    if (b >= 1099511627776) return (b/1099511627776).toFixed(1)+' TB';
    if (b >= 1073741824)    return (b/1073741824).toFixed(1)+' GB';
    if (b >= 1048576)       return (b/1048576).toFixed(0)+' MB';
    return b+' B';
}

function progressBar(used, total, colorClass){
    if(!total) return '';
    const pct = Math.min(100, Math.round(used/total*100));
    const cls = pct>85?'prog-red':pct>60?'prog-amber':colorClass;
    return `<div class="prog-wrap"><div class="prog-bar ${cls}" style="width:${pct}%"></div></div>`;
}

function renderVMs(id, vms, type, extra) {
    const el = document.getElementById('vm-list-' + id);
    if (!el) return;

    el.querySelectorAll('.skeleton-vm').forEach(s => s.remove());

    // ── TrueNAS: render propio con pools, apps y VMs bhyve ──────
    if (type === 'truenas') {
        renderTrueNAS(id, el, extra || {});
        return;
    }

    // ── PBS: datastores y tareas ─────────────────────────────────
    if (type === 'pbs') {
        renderPBS(id, el, extra || {});
        return;
    }

    // ── PVE: guests estilo Proxmox ────────────────────────────────
    if (!vms || !vms.length) {
        if (!el.querySelector('.vm-card')) {
            el.innerHTML = '<div class="loading" style="grid-column:1/-1">no VMs or containers — check token and permissions</div>';
        }
        return;
    }

    const summary = document.getElementById('vm-summary-' + id);
    if (summary) {
        const running = vms.filter(v => v.status === 'running').length;
        const stopped = vms.filter(v => v.status !== 'running').length;
        const parts = [];
        if (running) parts.push(`${running} running`);
        if (stopped) parts.push(`${stopped} stopped`);
        summary.textContent = parts.length ? '· ' + parts.join(' · ') : '';
    }

    Object.keys(vmDataMap).forEach(k => { if (k.startsWith(id + '_')) delete vmDataMap[k]; });
    vms.forEach(vm => { vmDataMap[id + '_' + vm.vmid] = vm; });

    const existingIds = new Set([...el.querySelectorAll('.vm-card[data-vmidx]')].map(c => parseInt(c.dataset.vmidx)));
    const newIds      = new Set(vms.map(v => v.vmid));

    existingIds.forEach(vmid => {
        if (!newIds.has(vmid)) {
            const old = el.querySelector(`.vm-card[data-vmidx="${vmid}"]`);
            if (old) old.remove();
        }
    });

    vms.forEach(vm => {
        const isLxc    = vm.type === 'lxc';
        const label    = isLxc ? 'LXC' : 'VM';
        const typeClass= isLxc ? 'vm-type lxc' : 'vm-type';
        const cpuPct   = vm.cpu != null ? (vm.cpu*100).toFixed(0)+'%' : '—';
        const cpuCount = vm.cpus ? vm.cpus+' vCPU' : '';
        const ramUsed  = vm.mem    ?? 0, ramMax = vm.maxmem ?? 0;
        const ramTxt   = ramMax ? (isLxc ? fmtBytes(ramUsed)+' / '+fmtBytes(ramMax) : fmtBytes(ramMax)) : '—';
        const ramBar   = isLxc ? progressBar(ramUsed, ramMax, 'prog-blue') : '';
        const dskUsed  = vm.disk   ?? 0, dskMax = vm.maxdisk ?? 0;
        const dskTxt   = isLxc
            ? (dskMax ? fmtBytes(dskUsed)+' / '+fmtBytes(dskMax) : '—')
            : (dskMax ? fmtBytes(dskMax) : '—');
        const dskBar   = isLxc ? progressBar(dskUsed, dskMax, 'prog-green') : '';
        const vmName   = escHtml(vm.name || 'vm-' + vm.vmid);
        const vmUrl    = vm.url || '';

        let card = el.querySelector(`.vm-card[data-vmidx="${vm.vmid}"]`);
        if (!card) {
            card = document.createElement('div');
            card.className = 'vm-card';
            card.dataset.srvid  = id;
            card.dataset.vmidx  = vm.vmid;
            card.style.cursor   = 'pointer';
            el.appendChild(card);
        }

        const urlArrow = vmUrl ? `<a href="${escHtml(vmUrl)}" target="_blank" rel="noopener" title="Open ${vmName}" onclick="event.stopPropagation()" style="color:var(--blue);opacity:.75;margin-left:4px;font-size:12px;line-height:1;text-decoration:none" tabindex="-1">↗</a>` : '';

        card.innerHTML = `
            <div class="vm-card-top">
                <div class="vm-title">
                    <span class="vm-id">#${vm.vmid}</span>
                    <span class="vm-name">${vmName}</span>
                    <span class="${typeClass}">${label}</span>
                    ${urlArrow}
                </div>
                ${vmPill(vm.status)}
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;font-size:11px">
                <div style="display:flex;justify-content:space-between">
                    <span style="color:var(--text-dim)">CPU</span>
                    <span style="color:var(--text-muted);font-weight:500">${cpuPct}${cpuCount?' · '+cpuCount:''}</span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:flex-start">
                    <span style="color:var(--text-dim);line-height:1.3">RAM${!isLxc ? '<br><span style="font-size:9px;color:var(--text-dim);opacity:.6">allocated</span>' : ''}</span>
                    <span style="color:var(--text-muted)">${ramTxt}</span>
                </div>
                ${ramBar}
                <div style="display:flex;justify-content:space-between;align-items:flex-start">
                    <span style="color:var(--text-dim);line-height:1.3">Disk${!isLxc ? '<br><span style="font-size:9px;color:var(--text-dim);opacity:.6">allocated</span>' : ''}</span>
                    <span style="color:var(--text-muted)">${dskTxt}</span>
                </div>
                ${dskBar}
            </div>`;
    });
}

// ── RENDER TRUENAS ──────────────────────────────────────────────

function tnSectionHeader(label, first = false) {
    const d = document.createElement('div');
    d.style.cssText = 'font-size:10px;font-weight:600;color:var(--text-dim);' +
        'letter-spacing:.08em;text-transform:uppercase;' +
        (first ? 'padding:0 2px 6px' : 'border-top:1px solid var(--border);margin-top:12px;padding:8px 2px 6px');
    d.textContent = label;
    return d;
}

function tnStatePill(state) {
    const s = (state || '').toUpperCase();
    if (s === 'ONLINE'   || s === 'RUNNING' || s === 'ACTIVE') return `<span class="badge bg-success">${state.toLowerCase()}</span>`;
    if (s === 'DEPLOYING'|| s === 'PENDING')                    return `<span class="badge bg-warning">${state.toLowerCase()}</span>`;
    if (s === 'FAULTED'  || s === 'ERROR'   || s === 'CRASHED') return `<span class="badge bg-danger">${state.toLowerCase()}</span>`;
    return `<span class="badge bg-secondary">${escHtml(state.toLowerCase())}</span>`;
}

function renderTrueNAS(id, el, extra) {
    const pools     = extra.pools     || [];
    const apps      = extra.apps      || [];
    const vms       = extra.vms       || [];
    const disks     = extra.disks     || [];
    const alerts    = extra.alerts    || [];
    const diskTemps = extra.disk_temps || {};

    if (!pools.length && !apps.length && !vms.length && !disks.length) {
        el.className = '';
        el.style.cssText = '';
        el.innerHTML = '<div class="loading">no data — check API key and permissions</div>';
        return;
    }

    // Resumen en el header del accordion
    const summary = document.getElementById('vm-summary-' + id);
    if (summary) {
        const parts = [];
        if (pools.length) {
            const ok = pools.filter(p => (p.status || '').toUpperCase() === 'ONLINE').length;
            parts.push(`${ok}/${pools.length} pools`);
        }
        if (apps.length) {
            const running = apps.filter(a => (a.state || '').toUpperCase() === 'RUNNING').length;
            parts.push(`${running}/${apps.length} apps`);
        }
        if (vms.length) {
            const running = vms.filter(v => {
                const s = (v.status?.state || v.status || '').toUpperCase();
                return s === 'RUNNING';
            }).length;
            parts.push(`${running}/${vms.length} VMs`);
        }
        summary.textContent = parts.length ? '· ' + parts.join(' · ') : '';
    }

    el.innerHTML = '';
    el.className = '';
    el.style.cssText = 'display:flex;flex-direction:column;gap:0;margin-top:2px';

    // ── Pools ──────────────────────────────────────────────────
    if (pools.length) {
        el.appendChild(tnSectionHeader('Pools ZFS', true));
        const poolGrid = document.createElement('div');
        poolGrid.className = 'vm-grid'; poolGrid.style.marginTop = '0';
        pools.forEach(pool => {
            const card   = document.createElement('div');
            card.className = 'vm-card';
            const status  = pool.status || 'UNKNOWN';
            const total   = pool.size      || 0;
            const used    = pool.allocated || 0;
            const bar     = total ? progressBar(used, total, 'prog-green') : '';
            // Scrub info
            const scan    = pool.scan || null;
            const scrubState  = scan?.state || null;
            const scrubErrors = scan?.errors ?? null;
            const scrubDate   = scan?.end_time?.$date
                ? fmtIso(scan.end_time.$date) : (scan?.end_time ? fmtUnix(scan.end_time) : null);
            const scrubOk  = scrubState === 'FINISHED' && scrubErrors === 0;
            const scrubBad = scrubErrors > 0;
            const scrubCol = scrubBad ? 'var(--red)' : scrubOk ? 'var(--green)' : 'var(--text-dim)';
            card.innerHTML = `
                <div class="vm-card-top">
                    <div class="vm-title">
                        <span class="vm-name">${escHtml(pool.name)}</span>
                    </div>
                    ${tnStatePill(status)}
                </div>
                <div style="display:flex;flex-direction:column;gap:6px;font-size:11px;margin-top:4px">
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:var(--text-dim)">Used</span>
                        <span style="color:var(--text-muted)">${total ? fmtBytes(used) + ' / ' + fmtBytes(total) : '—'}</span>
                    </div>
                    ${bar}
                    ${scrubState ? `<div style="display:flex;justify-content:space-between;margin-top:2px">
                        <span style="color:var(--text-dim)">Scrub</span>
                        <span style="color:${scrubCol};font-size:10px">
                            ${scrubBad ? scrubErrors + ' errors' : scrubOk ? 'OK' : escHtml(scrubState.toLowerCase())}
                            ${scrubDate ? '<span style="color:var(--text-dim)"> · ' + scrubDate + '</span>' : ''}
                        </span>
                    </div>` : ''}
                </div>`;
            poolGrid.appendChild(card);
        });
        el.appendChild(poolGrid);
    }

    // ── Apps ───────────────────────────────────────────────────
    if (apps.length) {
        el.appendChild(tnSectionHeader('Apps', !pools.length));
        const appGrid = document.createElement('div');
        appGrid.className = 'vm-grid'; appGrid.style.marginTop = '0';
        apps.forEach(app => {
            const card    = document.createElement('div');
            card.className = 'vm-card';
            card.style.cursor = 'pointer';
            const state   = app.state || app.status || 'UNKNOWN';
            const version = app.human_version || app.version || '—';
            const train   = app.metadata?.train || '';
            card.innerHTML = `
                <div class="vm-card-top">
                    <div class="vm-title">
                        <span class="vm-name">${escHtml(app.name || app.id)}</span>
                        <span class="vm-type">App</span>
                    </div>
                    ${tnStatePill(state)}
                </div>
                <div style="font-size:11px;color:var(--text-dim);margin-top:4px">
                    v${escHtml(String(version))}${train ? ' · ' + escHtml(train) : ''}
                </div>`;
            card.addEventListener('click', () => openTNDrawer(id, {
                name: app.name || app.id, type: 'truenas_app',
                app_name: app.name || app.id, state, version: String(version),
            }));
            appGrid.appendChild(card);
        });
        el.appendChild(appGrid);
    }

    // ── VMs bhyve ──────────────────────────────────────────────
    if (vms.length) {
        el.appendChild(tnSectionHeader('VMs', !pools.length && !apps.length));
        const vmGrid = document.createElement('div');
        vmGrid.className = 'vm-grid'; vmGrid.style.marginTop = '0';
        vms.forEach(vm => {
            const card  = document.createElement('div');
            card.className = 'vm-card';
            card.style.cursor = 'pointer';
            const state = vm.status?.state || vm.status || 'UNKNOWN';
            const mem   = vm.memory ? fmtBytes(vm.memory * 1048576) : '—'; // TrueNAS devuelve MB
            card.innerHTML = `
                <div class="vm-card-top">
                    <div class="vm-title">
                        <span class="vm-id">#${vm.id}</span>
                        <span class="vm-name">${escHtml(vm.name)}</span>
                        <span class="vm-type">VM</span>
                    </div>
                    ${tnStatePill(state)}
                </div>
                <div style="display:flex;flex-direction:column;gap:6px;font-size:11px;margin-top:4px">
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:var(--text-dim)">CPU</span>
                        <span style="color:var(--text-muted)">${vm.vcpus ?? '—'} vCPU</span>
                    </div>
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:var(--text-dim)">RAM</span>
                        <span style="color:var(--text-muted)">${mem}</span>
                    </div>
                </div>`;
            card.addEventListener('click', () => openTNDrawer(id, {
                name: vm.name, type: 'truenas_vm',
                vmid: vm.id, state, vcpus: vm.vcpus, memory: vm.memory,
            }));
            vmGrid.appendChild(card);
        });
        el.appendChild(vmGrid);
    }

    // ── Discos / SMART ─────────────────────────────────────────
    if (disks.length) {
        const physDisks = disks.filter(d => d.type !== 'UNKNOWN' && !d.devname?.startsWith('cd'));
        if (physDisks.length) {
            el.appendChild(tnSectionHeader('Disks'));
            const diskGrid = document.createElement('div');
            diskGrid.className = 'vm-grid'; diskGrid.style.marginTop = '0';
            physDisks.forEach(disk => {
                const card = document.createElement('div');
                card.className = 'vm-card';
                const name    = disk.devname || disk.name || '?';
                const model   = disk.model   || '—';
                const serial  = disk.serial  || '';
                const sizeB   = disk.size    || 0;
                const sizeTxt = sizeB ? fmtBytes(sizeB) : '—';
                const type    = disk.type    || '';
                const smart   = disk.smartnr  != null ? disk.smartnr  : null;
                // temperatura: del campo directo o del mapa disk_temps por nombre/devname
                const tempEntry = diskTemps ? (diskTemps[name] || diskTemps[disk.name] || null) : null;
                const rawTemp   = disk.temperature != null
                    ? disk.temperature
                    : (tempEntry !== null && tempEntry !== undefined ? (typeof tempEntry === 'object' ? tempEntry.avg : tempEntry) : null);
                const rawMax    = (tempEntry !== null && typeof tempEntry === 'object') ? tempEntry.max : null;
                const temp      = rawTemp != null ? Math.round(rawTemp) + '°C' : null;
                let healthBadge = '';
                if (smart !== null) {
                    healthBadge = smart === 0
                        ? '<span class="badge bg-success">SMART OK</span>'
                        : `<span class="badge bg-danger">SMART ${smart} err</span>`;
                }
                const tempBg  = rawTemp > 55 ? 'rgba(255,80,80,.15)' : rawTemp > 45 ? 'rgba(230,160,50,.15)' : 'rgba(80,200,120,.12)';
                const tempFg  = rawTemp > 55 ? 'var(--red)' : rawTemp > 45 ? 'var(--amber)' : 'var(--green)';
                const tempPill = temp ? `<span style="display:inline-flex;align-items:center;gap:3px;padding:2px 7px;
                    border-radius:5px;background:${tempBg};font-size:10px;font-variant-numeric:tabular-nums">
                    <span style="color:${tempFg};font-weight:600">${temp}</span>${rawMax != null
                        ? `<span style="color:var(--text-dim);font-weight:400">· max ${Math.round(rawMax)}°</span>` : ''}
                </span>` : '';
                const smartColor = smart === null ? 'var(--text-dim)' : smart === 0 ? 'var(--green)' : 'var(--red)';
                const smartTxt   = smart === null ? '—' : smart === 0 ? 'OK' : smart + ' err';
                card.innerHTML = `
                    <div class="vm-card-top">
                        <div class="vm-title">
                            <span class="vm-name">${escHtml(name)}</span>
                            ${type ? `<span class="vm-type">${escHtml(type)}</span>` : ''}
                        </div>
                        ${tempPill}
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-top:5px;font-size:11px">
                        <span style="color:var(--text-dim);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:130px">${escHtml(model)}</span>
                        <span style="color:var(--text-muted);font-weight:500;flex-shrink:0;margin-left:4px">${sizeTxt}</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;font-size:10px">
                        ${serial ? `<span style="color:var(--text-dim)">S/N <span style="color:var(--text-muted)">${escHtml(serial)}</span></span>` : '<span></span>'}
                        <span>SMART <span style="color:${smartColor};font-weight:600">${smartTxt}</span></span>
                    </div>`;
                diskGrid.appendChild(card);
            });
            el.appendChild(diskGrid);
        }
    }

    // ── Alertas activas ────────────────────────────────────────
    const critAlerts = alerts.filter(a => {
        if (a.dismissed) return false;
        const lvl = (a.level || a.klass || '').toUpperCase();
        return lvl === 'CRITICAL' || lvl === 'ALERT' || lvl === 'WARNING';
    });
    if (critAlerts.length) {
        el.appendChild(tnSectionHeader('Alerts'));
        const alertGrid = document.createElement('div');
        alertGrid.className = 'vm-grid'; alertGrid.style.marginTop = '0';
        critAlerts.forEach(alert => {
            const card = document.createElement('div');
            card.className = 'vm-card';
            const lvl  = (alert.level || alert.klass || 'WARN').toUpperCase();
            const cls  = lvl === 'CRITICAL' || lvl === 'ALERT' ? 'bg-danger' : 'bg-warning';
            const txt  = alert.formatted?.text || alert.text || alert.message || JSON.stringify(alert);
            const uuid = alert.uuid || '';
            const dt   = alert.datetime?.$date
                ? fmtIso(alert.datetime.$date)
                : '';
            const safeUuid = escHtml(uuid);
            card.innerHTML = `
                <div class="vm-card-top" style="gap:8px">
                    <span class="badge ${cls}" style="flex-shrink:0;align-self:flex-start">${lvl.toLowerCase()}</span>
                    <div style="flex:1;min-width:0;font-size:10px;color:var(--text-muted);line-height:1.5;word-break:break-word">${escHtml(txt)}</div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px">
                    <span style="font-size:9px;color:var(--text-dim)">${dt}</span>
                    ${uuid ? `<button onclick="dismissTrueNASAlert(${id},'${safeUuid}',this)"
                        style="background:var(--bg2);border:1px solid var(--border);color:var(--text-dim);
                               cursor:pointer;font-size:10px;border-radius:4px;padding:2px 8px;line-height:1.4"
                        title="Dismiss">✕ dismiss</button>` : ''}
                </div>`;
            alertGrid.appendChild(card);
        });
        el.appendChild(alertGrid);
    }
}

async function dismissTrueNASAlert(srvId, uuid, btn) {
    btn.disabled = true;
    btn.textContent = '…';
    try {
        const r = await fetch('php/api.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({action:'dismiss_truenas_alert', server_id: srvId, uuid})
        });
        const d = await r.json();
        if (d.status === 'success') {
            btn.closest('.vm-card').remove();
        } else {
            btn.disabled = false;
            btn.textContent = '×';
            alert('Error: ' + (d.message || 'could not dismiss'));
        }
    } catch(e) {
        btn.disabled = false;
        btn.textContent = '×';
    }
}

// ── RENDER PBS ─────────────────────────────────────────────────
function renderPBS(id, el, extra) {
    const datastores = extra.datastores || [];
    const tasks      = extra.tasks      || [];

    if (!datastores.length && !tasks.length) {
        el.className = '';
        el.style.cssText = '';
        el.innerHTML = '<div class="loading">no datastores or tasks — check token and permissions</div>';
        return;
    }

    // Resumen en header del accordion
    const summary = document.getElementById('vm-summary-' + id);
    if (summary) {
        const parts = [];
        if (datastores.length) parts.push(datastores.length + ' datastore' + (datastores.length > 1 ? 's' : ''));
        if (tasks.length) {
            const running = tasks.filter(t => !t.status && !t.endtime).length;
            parts.push(running ? running + ' active' : tasks.length + ' tasks');
        }
        summary.textContent = parts.length ? '· ' + parts.join(' · ') : '';
    }

    // Usar block en lugar de grid para el contenedor raíz — cada sección tiene su propio vm-grid
    el.innerHTML = '';
    el.className = '';
    el.style.cssText = 'display:flex;flex-direction:column;gap:10px';

    // Último backup exitoso por datastore (extraído de tasks)
    const lastBackup = {};
    tasks.forEach(t => {
        const store = t.store || (t.worker_id || '').split(':')[0] || '';
        if ((t.worker_type || t.type || '') === 'backup' && (t.status || '').toUpperCase() === 'OK' && t.endtime) {
            if (!lastBackup[store] || t.endtime > lastBackup[store]) lastBackup[store] = t.endtime;
        }
    });

    // ── Datastores ─────────────────────────────────────────────
    if (datastores.length) {
        el.appendChild(tnSectionHeader('Datastores', true));
        const dsGrid = document.createElement('div');
        dsGrid.className = 'vm-grid';
        dsGrid.style.marginTop = '0';
        datastores.forEach(ds => {
            const card  = document.createElement('div');
            card.className = 'vm-card';
            const used  = ds.used  || 0;
            const total = ds.total || 0;
            const pct   = total ? Math.round(used/total*100) : 0;
            const barCls = pct > 85 ? 'prog-red' : pct > 60 ? 'prog-amber' : 'prog-green';
            const bar   = total ? `<div class="prog-wrap"><div class="prog-bar ${barCls}" style="width:${pct}%"></div></div>` : '';
            const dsName = ds.store || ds.name || '?';
            const ts    = lastBackup[dsName];
            const lastBkTxt = ts
                ? fmtUnix(ts)
                : null;
            card.innerHTML = `
                <div class="vm-card-top">
                    <div class="vm-title">
                        <span class="vm-name">${escHtml(dsName)}</span>
                        <span class="vm-type">DS</span>
                    </div>
                    <span class="badge bg-success">online</span>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px;font-size:11px;margin-top:4px">
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:var(--text-dim)">Used</span>
                        <span style="color:var(--text-muted)">${total ? fmtBytes(used) + ' / ' + fmtBytes(total) + ' (' + pct + '%)' : '—'}</span>
                    </div>
                    ${bar}
                    ${lastBkTxt ? `<div style="display:flex;justify-content:space-between;margin-top:2px">
                        <span style="color:var(--text-dim)">Last backup</span>
                        <span style="color:var(--green);font-size:10px">✓ ${lastBkTxt}</span>
                    </div>` : ''}
                </div>`;
            dsGrid.appendChild(card);
        });
        el.appendChild(dsGrid);
    }

    // ── Tareas recientes ────────────────────────────────────────
    if (tasks.length) {
        const PBS_PAGE = 6;
        el.appendChild(tnSectionHeader('Recent tasks'));

        // Search box
        const searchWrap = document.createElement('div');
        searchWrap.style.cssText = 'margin-bottom:8px';
        searchWrap.innerHTML = `<input type="text" placeholder="Search task…"
            style="width:100%;background:var(--bg2);border:1px solid var(--border);border-radius:6px;
                   padding:5px 8px;font-size:11px;color:var(--text-muted);outline:none"
            oninput="pbsTaskFilter(this)">`;
        el.appendChild(searchWrap);

        const taskList = document.createElement('div');
        taskList.id        = 'pbs-tasks-' + id;
        taskList.style.cssText = 'border-top:1px solid var(--border)';
        el.appendChild(taskList);

        const showMore = document.createElement('div');
        showMore.id = 'pbs-more-' + id;
        showMore.style.cssText = 'text-align:center;margin-top:6px';
        el.appendChild(showMore);

        // Guardar tasks en el elemento para el buscador
        taskList._allTasks = tasks;
        taskList._pbsId    = id;
        renderPBSTasks(taskList, showMore, tasks, PBS_PAGE, 0);
    }

}

function pbsTaskRow(task) {
    const wtype    = task.worker_type || task.type || '—';
    const wid      = task.worker_id   || task.id   || '';
    const stateRaw = task.status || (task.endtime ? '?' : 'running');
    const stateL   = stateRaw.toLowerCase();
    let dot;
    if (stateL === 'ok')           dot = '<span style="color:var(--green);font-size:11px">●</span>';
    else if (stateL === 'running') dot = '<span style="color:var(--blue);font-size:11px">●</span>';
    else                           dot = '<span style="color:var(--red);font-size:11px">●</span>';
    const start = task.starttime ? fmtUnix(task.starttime) : '—';
    const dur = (task.starttime && task.endtime)
        ? (task.endtime - task.starttime < 60
            ? Math.round(task.endtime - task.starttime) + 's'
            : Math.round((task.endtime - task.starttime)/60) + 'min')
        : '';
    const widShort = wid.length > 20 ? wid.slice(0,20)+'…' : wid;
    return `<div style="display:flex;align-items:center;gap:8px;padding:5px 2px;border-bottom:1px solid var(--border);font-size:11px">
        ${dot}
        <span style="color:var(--text-muted);min-width:64px">${escHtml(wtype)}</span>
        <span style="color:var(--text-dim);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(widShort)}</span>
        <span style="color:var(--text-dim);white-space:nowrap">${start}</span>
        ${dur ? `<span style="color:var(--text-dim);min-width:36px;text-align:right">${dur}</span>` : '<span style="min-width:36px"></span>'}
    </div>`;
}

function renderPBSTasks(listEl, moreEl, tasks, pageSize, offset) {
    const slice = tasks.slice(0, offset + pageSize);
    listEl.innerHTML = slice.map(pbsTaskRow).join('');
    const remaining = tasks.length - slice.length;
    if (remaining > 0) {
        moreEl.innerHTML = `<button class="btn btn-outline-secondary btn-sm" style="font-size:10px"
            onclick="(function(b){
                const l=b.closest('div').previousElementSibling;
                const m=b.closest('div');
                renderPBSTasks(l,m,l._allTasks,${pageSize},${offset + pageSize});
            })(this)">Show ${Math.min(remaining, pageSize)} more of ${remaining}</button>`;
    } else {
        moreEl.innerHTML = '';
    }
    listEl._allTasks = listEl._allTasks || tasks;
}

function pbsTaskFilter(input) {
    const q      = input.value.toLowerCase();
    const wrap   = input.parentElement.parentElement;
    const listEl = wrap.querySelector('[id^="pbs-tasks-"]');
    const moreEl = wrap.querySelector('[id^="pbs-more-"]');
    if (!listEl) return;
    const all = listEl._allTasks || [];
    const filtered = q ? all.filter(t => {
        const s = ((t.worker_type||'')+(t.worker_id||'')+(t.status||'')+(t.type||'')).toLowerCase();
        return s.includes(q);
    }) : all;
    renderPBSTasks(listEl, moreEl, filtered, 6, 0);
}

// ── TIPO HYPERVISOR DINÁMICO (edición) ─────────────────────────
function onHypChange(id, val){
    const sec=document.getElementById('token-section-'+id);
    if(!sec)return;
    const apiOn=document.getElementById('api_toggle_'+id)?.checked;
    const isTN=val==='truenas', isPVE=val==='pve'||val==='pbs';
    let html='';
    if(isPVE){
        const lbl=val==='pve'?'PVE: Datacenter → API Tokens → Add. Uncheck <code>Privilege Separation</code>.':'PBS: Configuration → User Management → API Tokens.';
        html=`<hr style="margin:8px 0"><div class="cfg-col">
        <div class="sec-mini mt-0">token api</div>
        <div class="info-box">${lbl} Usuario: <code>root@pam</code>, Token ID: <code>panel</code></div>
        <div class="row g-2">
          <div class="col-4"><div class="form-row"><span class="form-label">Usuario</span><input id="apu_${id}" class="form-control" value="root@pam"></div></div>
          <div class="col-4"><div class="form-row"><span class="form-label">Token ID</span><input id="tid_${id}" class="form-control" value="panel"></div></div>
          <div class="col-4"><div class="form-row"><span class="form-label">Secret</span><input id="tsc_${id}" class="form-control" type="password" placeholder="UUID" autocomplete="new-password"></div></div>
        </div></div>`;
    } else if(isTN){
        html=`<hr style="margin:8px 0"><div class="cfg-col"><div class="row g-2">
        <div class="col-md-5">
          <div class="sec-mini mt-0">api key</div>
          <div class="form-row"><span class="form-label">API Key</span><input id="tsc_${id}" class="form-control" type="password" placeholder="paste API key" autocomplete="new-password"></div>
          <input type="hidden" id="apu_${id}" value="truenas"><input type="hidden" id="tid_${id}" value="apikey">
        </div>
        <div class="col-md-7">
          <div class="sec-mini mt-0">ssh <span style="font-size:9px;color:#8b949e">(deploy idle script — requires sshpass)</span></div>
          <div class="row g-1">
            <div class="col-5"><div class="form-row"><span class="form-label">Usuario</span><input id="tn_ssh_user_${id}" class="form-control" placeholder="truenas_admin"></div></div>
            <div class="col-3"><div class="form-row"><span class="form-label">Puerto</span><input id="tn_ssh_port_${id}" class="form-control" type="number" min="1" max="65535" placeholder="22"></div></div>
            <div class="col-4"><div class="form-row"><span class="form-label">Password</span><input id="tn_ssh_pass_${id}" class="form-control" type="password" placeholder="password" autocomplete="new-password"></div></div>
          </div>
          <button type="button" class="btn btn-outline-success btn-sm mt-1" onclick="saveTrueNASSSH(${id})">save SSH</button>
        </div></div></div>`;
    }
    sec.innerHTML=html;
    sec.style.display=(apiOn&&(isPVE||isTN))?'':'none';
}

function selectHostType(prefix, val) {
    const isEdit = prefix.startsWith('edit_');
    const id     = isEdit ? prefix.slice(5) : null;
    const picker = document.getElementById(isEdit ? `edit-type-picker-${id}` : 'new-type-picker');
    const hidden = document.getElementById(isEdit ? `hyp_${id}` : 'new_hyp');
    if (!picker || !hidden) return;
    picker.querySelectorAll('.type-btn').forEach(b => b.classList.remove('selected'));
    const btn = picker.querySelector(`[data-val="${val}"]`);
    if (btn) btn.classList.add('selected');
    hidden.value = val;
    if (isEdit) onHypChange(parseInt(id), val);
    else        onNewHypChange(val);
}

// ── WAKE PROXY UI ────────────────────────────────────────────

let _wpDT = null;

const _DT_SHARED_LANG = {
    search:           '',
    lengthMenu:       '_MENU_ per page',
    info:             '_START_–_END_ of _TOTAL_',
    infoEmpty:        '',
    infoFiltered:     '(of _MAX_ total)',
    paginate:         { previous: '‹', next: '›' },
    zeroRecords:      'No results for the search.',
};

let _hdbDT = null;
function initHdbDT() {
    if (_hdbDT) { _hdbDT.destroy(); _hdbDT = null; }
    if (!document.getElementById('hdb-table')) return;
    _hdbDT = $('#hdb-table').DataTable({
        language: Object.assign({}, _DT_SHARED_LANG, {
            searchPlaceholder: 'Search host, IP, MAC...',
            emptyTable:        'No registered hosts.',
        }),
        pageLength: 25,
        order:      [[0, 'desc'], [1, 'asc']],
        autoWidth:  false,
        columnDefs: [
            { searchable: false, targets: [0, -1] },
            { orderable: false, targets: [-1] },
        ],
    });
}

function initWpDT() {
    if (_wpDT) { _wpDT.destroy(); _wpDT = null; }
    if (!document.getElementById('wp-table')) return;
    _wpDT = $('#wp-table').DataTable({
        language: Object.assign({}, _DT_SHARED_LANG, {
            searchPlaceholder: 'Search proxy...',
            emptyTable:        'No proxies configured. Use "+ add proxy" to create one.',
        }),
        pageLength: 10,
        order:      [[0, 'asc'], [1, 'asc']],
        autoWidth:  false,
        columnDefs: [
            { orderable: false, searchable: false, targets: [0, 6, 7] },
            { width: '7%',   targets: 0 },
            { width: '13%',  targets: 1 },
            { width: '20%',  targets: 2 },
            { width: '18%',  targets: 3 },
            { width: '13%',  targets: 4 },
            { width: '10%',  targets: 5 },
            { width: '8%',   targets: 6 },
            { width: '11%',  targets: 7 },
        ],
    });
}

const BOOT_TIMEOUT_DEFAULTS = { 1: 120, 2: 240, 3: 360 };

function wpLayer() {
    const guest  = document.getElementById('wp-guest')?.value  || '';
    const docker = (document.getElementById('wp-docker')?.value || '').trim();
    return docker ? 3 : (guest ? 2 : 1);
}

function updateWpTimeoutHint() {
    const layer = wpLayer();
    const el = document.getElementById('wp-timeout-hint');
    if (!el) return;
    const labels = {1:'(solo host)', 2:'(host + guest)', 3:'(host + guest + docker)'};
    el.textContent = labels[layer] || '';
    const cur = parseInt(document.getElementById('wp-timeout')?.value || '0');
    const def = BOOT_TIMEOUT_DEFAULTS[layer];
    // Only auto-update if value matches one of the defaults (user hasn't customised)
    const allDefaults = Object.values(BOOT_TIMEOUT_DEFAULTS);
    if (allDefaults.includes(cur) || cur === 0) {
        document.getElementById('wp-timeout').value = def;
    }
}

function openWakeProxyModal(proxy) {
    const isEdit = proxy && proxy.id;
    document.getElementById('wp-modal-title').textContent = isEdit ? 'Edit Wake Proxy' : 'Add Wake Proxy';
    const wpIconEl   = document.getElementById('wp-modal-icon');
    const wpWrapEl   = document.getElementById('wp-modal-icon-wrap');
    if (wpIconEl) { wpIconEl.className = isEdit ? 'bi bi-pencil-fill' : 'bi bi-lightning-charge-fill'; }
    if (wpWrapEl) { wpWrapEl.className = isEdit ? 'fmodal-icon-wrap fmodal-icon-blue' : 'fmodal-icon-wrap fmodal-icon-amber'; }

    document.getElementById('wp-id').value     = isEdit ? proxy.id : '';
    document.getElementById('wp-name').value   = proxy?.name   || '';
    document.getElementById('wp-domain').value = proxy?.domain || '';
    document.getElementById('wp-ip').value     = proxy?.dest_ip     || '';
    document.getElementById('wp-port').value   = proxy?.dest_port   || '';
    document.getElementById('wp-docker').value = proxy?.docker_container || '';
    document.getElementById('wp-timeout').value= proxy?.boot_timeout_sec || 240;
    document.getElementById('wp-active').checked = proxy ? !!parseInt(proxy.active) : true;

    const protoSel = document.getElementById('wp-proto');
    protoSel.value = proxy?.dest_protocol || 'http';

    const srvSel = document.getElementById('wp-server');
    srvSel.value = proxy?.server_id || '';

    // Determine if selected server is PVE
    const selOpt = srvSel.options[srvSel.selectedIndex];
    const isPve  = (selOpt?.dataset.type || '') === 'pve';
    const guestRow  = document.getElementById('wp-guest-row');
    const dockerRow = document.getElementById('wp-docker-row');
    if (guestRow)  guestRow.style.display  = isPve ? '' : 'none';
    if (dockerRow) dockerRow.style.display = (isPve && proxy?.guest_vmid) ? '' : 'none';

    // Reset guest dropdown
    const guestSel = document.getElementById('wp-guest');
    guestSel.innerHTML = '<option value="">— direct service on host —</option>';

    if (proxy?.server_id && isPve) {
        loadWpGuests(proxy.server_id, proxy.guest_vmid, proxy.guest_vmtype);
    }

    updateWpTimeoutHint();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('wpModal')).show();
}

function closeWakeProxyModal() {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('wpModal')).hide();
}

document.getElementById('wpModal').addEventListener('hidden.bs.modal', () => {
    document.getElementById('wp-form').reset();
    document.getElementById('wp-id').value = '';
    document.getElementById('wp-guest-row').style.display  = 'none';
    document.getElementById('wp-docker-row').style.display = 'none';
    document.getElementById('wp-guest').innerHTML = '<option value="">— direct service on host —</option>';
    document.getElementById('wp-timeout').value = 240;
    document.getElementById('wp-active').checked = true;
});

function loadWpGuests(serverId, selectedVmid, selectedVmtype) {
    const sel = document.getElementById('wp-guest');
    if (!serverId) { sel.innerHTML = '<option value="">— direct on host —</option>'; return; }
    wpGetGuests(serverId).then(d => {
        sel.innerHTML = '<option value="">— direct on host —</option>';
        if (d.status === 'success' && d.data?.length) {
            d.data.forEach(g => {
                const val   = g.vmid + '|' + (g.type === 'lxc' ? 'lxc' : 'qemu');
                const label = `[${g.type.toUpperCase()}] ${g.vmid} — ${g.name}`;
                const opt   = new Option(label, val);
                if (selectedVmid && parseInt(g.vmid) === parseInt(selectedVmid)) opt.selected = true;
                sel.add(opt);
            });
        }
        updateWpTimeoutHint();
    }).catch(() => {
        sel.innerHTML = '<option value="">— no access to PVE API —</option>';
    });
}

function onWpServerChange(serverId) {
    const sel = document.getElementById('wp-server');
    const opt = sel?.options[sel.selectedIndex];
    const type = opt?.dataset.type || '';
    const isPve = type === 'pve';

    const guestRow  = document.getElementById('wp-guest-row');
    const dockerRow = document.getElementById('wp-docker-row');
    if (guestRow)  guestRow.style.display  = isPve ? '' : 'none';
    if (dockerRow) dockerRow.style.display = 'none'; // reset until guest chosen

    const guestSel = document.getElementById('wp-guest');
    if (!serverId || !isPve) {
        if (guestSel) guestSel.innerHTML = '<option value="">— direct service on host —</option>';
        updateWpTimeoutHint();
        return;
    }
    if (guestSel) guestSel.innerHTML = '<option value="">— loading… —</option>';
    loadWpGuests(serverId, null, null);
}

function onWpGuestChange(val) {
    const dockerRow = document.getElementById('wp-docker-row');
    if (dockerRow) dockerRow.style.display = val ? '' : 'none';
    updateWpTimeoutHint();
}

function saveWakeProxy(e) {
    e.preventDefault();
    const id = document.getElementById('wp-id').value;
    const guestVal = (document.getElementById('wp-guest')?.value || '').split('|');
    const guestVmid   = guestVal[0] ? parseInt(guestVal[0]) : null;
    const guestVmtype = guestVal[1] || null;

    const payload = {
        name:             document.getElementById('wp-name').value.trim(),
        domain:           document.getElementById('wp-domain').value.trim().toLowerCase(),
        server_id:        parseInt(document.getElementById('wp-server').value),
        guest_vmid:       guestVmid,
        guest_vmtype:     guestVmtype,
        docker_container: document.getElementById('wp-docker').value.trim() || null,
        dest_ip:          document.getElementById('wp-ip').value.trim(),
        dest_port:        parseInt(document.getElementById('wp-port').value),
        dest_protocol:    document.getElementById('wp-proto').value,
        boot_timeout_sec: parseInt(document.getElementById('wp-timeout').value),
        active:           document.getElementById('wp-active').checked ? 1 : 0,
    };

    if (!payload.name)      { showToast('Proxy name is required', 'err'); return; }
    if (!payload.domain)    { showToast('Domain is required', 'err'); return; }
    if (!payload.server_id) { showToast('Please select a server', 'err'); return; }
    if (!payload.dest_ip)   { showToast('Destination IP is required', 'err'); return; }
    if (!payload.dest_port) { showToast('Destination port is required', 'err'); return; }

    const fn = id ? wpUpdate({...payload, id: parseInt(id)}) : wpAdd(payload);
    fn.then(d => {
        if (d.status === 'success') {
            showToast(id ? 'Proxy updated' : 'Proxy created', 'ok');
            closeWakeProxyModal();
            reloadWpTable();
        } else {
            showToast('Error: ' + (d.message || 'unknown'), 'err');
        }
    }).catch(() => showToast('Network error', 'err'));
}

function toggleWakeProxy(id, active) {
    const proxy = (window.WP_DATA || []).find(p => parseInt(p.id) === id);
    if (!proxy) return;
    wpUpdate({...proxy, id, active: active ? 1 : 0, guest_vmid: proxy.guest_vmid || null})
        .then(d => {
            if (d.status !== 'success') showToast('Error: ' + d.message, 'err');
        })
        .catch(() => showToast('Network error', 'err'));
}

function confirmDeleteWakeProxy(id, name) {
    if (!confirm(`Delete proxy "${name}"?`)) return;
    wpDelete(id).then(d => {
        if (d.status === 'success') {
            showToast('Proxy deleted', 'ok');
            reloadWpTable();
        } else {
            showToast('Error: ' + d.message, 'err');
        }
    }).catch(() => showToast('Network error', 'err'));
}

function _updateWpSidebarBadge(entries) {
    const badge = document.getElementById('sb-wp-alert');
    if (!badge) return;
    const issues = entries.filter(wp => !wp.domain || !wp.server_id);
    if (issues.length) {
        badge.textContent = issues.length;
        badge.style.display = '';
        badge.title = issues.map(wp => wp.name + ': ' + (!wp.domain ? 'no domain' : 'no server')).join('\n');
    } else {
        badge.style.display = 'none';
    }
}

function reloadWpTable() {
    wpList().then(d => {
        if (d.status !== 'success') return;
        window.WP_DATA = d.data;
        _updateWpSidebarBadge(d.data);
        if (_wpDT) { _wpDT.destroy(); _wpDT = null; }
        const tbody = document.getElementById('wp-tbody');
        if (!tbody) return;
        const layerLabel = ['','Host','Host + Guest','Host + Guest + Docker'];
        const layerColor = ['','var(--text-muted)','var(--blue)','var(--amber)'];
        tbody.innerHTML = d.data.map(wp => {
            const layer = wp.docker_container ? 3 : (wp.guest_vmid ? 2 : 1);
            const guestInfo = wp.guest_vmid ? ` <span style="color:var(--text-dim)">/ vmid ${wp.guest_vmid}</span>` : '';
            const domainUrl = wp.domain ? `https://${wp.domain}` : '';
            const domainCell = wp.domain
                ? `<a href="${escHtml(domainUrl)}" target="_blank" rel="noopener" style="font-family:monospace;font-size:.82rem">${escHtml(wp.domain)}</a>`
                : '<span style="color:var(--text-dim)">—</span>';
            const srvCell = wp.server_id
                ? `<a href="#" onclick="event.preventDefault();sidebarNav(document.getElementById('sb-srv-${wp.server_id}'),'htab-srv-${wp.server_id}')" style="color:var(--blue)">${escHtml(wp.srv_hostname)}</a>${guestInfo}`
                : escHtml(wp.srv_hostname || '—') + guestInfo;
            return `<tr id="wp-row-${wp.id}">
                <td data-order="0"><span class="badge bg-secondary" id="wp-status-${wp.id}">—</span></td>
                <td class="fw-medium">${escHtml(wp.name)}</td>
                <td>${domainCell}</td>
                <td><span style="font-family:monospace;font-size:.82rem">${escHtml(wp.dest_protocol+'://'+wp.dest_ip+':'+wp.dest_port)}</span></td>
                <td>${srvCell}</td>
                <td><span class="wp-layer-badge" style="color:${layerColor[layer]}">${layerLabel[layer]}</span></td>
                <td><div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch"
                        ${wp.active ? 'checked' : ''}
                        onchange="toggleWakeProxy(${wp.id}, this.checked)">
                </div></td>
                <td>
                    <button class="btn btn-outline-secondary btn-sm py-0 px-2" title="Edit proxy"
                        onclick='openWakeProxyModal(${JSON.stringify(wp)})'><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-outline-danger btn-sm py-0 px-2 ms-1" title="Delete proxy"
                        onclick="confirmDeleteWakeProxy(${wp.id}, '${escAttr(wp.name)}')"><i class="bi bi-trash"></i></button>
                </td>
            </tr>`;
        }).join('');
        initWpDT();
    });
}

function pollWpStatus() {
    const badges = document.querySelectorAll('[id^="wp-status-"]');
    if (!badges.length) return;
    badges.forEach(badge => {
        const id = badge.id.replace('wp-status-', '');
        fetch(`php/api.php?action=wake_proxy_status&id=${id}`)
            .then(r => r.json())
            .then(d => {
                const online = d.data?.status === 'online';
                badge.className  = online ? 'badge bg-success' : 'badge bg-secondary';
                badge.textContent = online ? 'online' : 'offline';
                const td = badge.closest('td');
                if (td) td.dataset.order = online ? '1' : '0';
            })
            .catch(() => {});
    });
}

// Poll proxy statuses + init DataTable when Wake Proxy tab is shown
document.addEventListener('DOMContentLoaded', () => {
    // Init DataTables on load
    initWpDT();

    // Initial poll after short delay
    setTimeout(pollWpStatus, 2000);

    // Re-poll cuando se muestra wake-proxy (evento en nav oculto)
    const ht = document.getElementById('hiddenTabs');
    if (ht) ht.addEventListener('shown.bs.tab', e => {
        if (e.target.dataset.bsTarget === '#tab-wake-proxy') pollWpStatus();
    });

    // Refresh every 30s
    setInterval(pollWpStatus, 30000);

    // Bind docker field to update timeout hint
    const dockerField = document.getElementById('wp-docker');
    if (dockerField) dockerField.addEventListener('input', updateWpTimeoutHint);
});

function escAttr(s) { return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

// ─── SSH KEY ───────────────────────────────────────────────────
let _sshPubkey = null;
let _waitKeyInterval = null;

function loadSshPubkey() {
    fetch('php/api.php?action=get_ssh_pubkey')
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success') {
                _sshPubkey = d.data.pubkey;
                const el = document.getElementById('ssh-pubkey-display');
                if (el) el.textContent = _sshPubkey;
                const ob = document.getElementById('ob-pubkey-text');
                if (ob) ob.textContent = _sshPubkey;
            }
        })
        .catch(() => {});
}

function _clipboardCopy(text, onOk, onErr) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(onOk).catch(() => _clipboardFallback(text, onOk, onErr));
    } else {
        _clipboardFallback(text, onOk, onErr);
    }
}
function _clipboardFallback(text, onOk, onErr) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
    document.body.appendChild(ta);
    ta.focus(); ta.select();
    try { document.execCommand('copy') ? onOk() : onErr(); } catch { onErr(); }
    document.body.removeChild(ta);
}

function copyWpToken() {
    const text = document.getElementById('wp-token-display')?.textContent || '';
    const icon = document.getElementById('wp-token-copy-icon');
    _clipboardCopy(text,
        () => { if (icon) { icon.className = 'bi bi-check-lg'; setTimeout(() => icon.className = 'bi bi-copy', 2000); } },
        () => showToast('Error copying', 'err')
    );
}

async function regenerateWpToken() {
    if (!confirm('Regenerate the token? You will need to update the NPM block in all your Proxy Hosts.')) return;
    const r = await fetch('php/api.php', { method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'regenerate_wake_proxy_token' }) }).then(r => r.json());
    if (r.status === 'success') {
        const secret = r.data.secret;
        const display = document.getElementById('wp-token-display');
        if (display) display.textContent = secret;
        const npmBlock = document.getElementById('npm-block');
        if (npmBlock) npmBlock.innerHTML = npmBlock.innerHTML.replace(
            /X-Wake-Proxy-Token "[^"]*"/,
            `X-Wake-Proxy-Token "${secret}"`
        );
        showToast('Token regenerated — update NPM in all Proxy Hosts', 'warn');
    } else {
        showToast('Error: ' + r.message, 'err');
    }
}

function copyNpmBlock() {
    const text = document.getElementById('npm-block')?.innerText || '';
    const icon = document.getElementById('npm-copy-icon');
    _clipboardCopy(text,
        () => { if (icon) { icon.className = 'bi bi-check-lg'; setTimeout(() => icon.className = 'bi bi-copy', 2000); } },
        () => showToast('Error copying', 'err')
    );
}

function copySshPubkey() {
    if (!_sshPubkey) return;
    const icon = document.getElementById('ssh-copy-icon');
    _clipboardCopy(_sshPubkey,
        () => {
            showToast('Key copied to clipboard', 'ok');
            if (icon) { icon.className = 'bi bi-check-lg'; setTimeout(() => icon.className = 'bi bi-copy', 2000); }
        },
        () => showToast('Error copying', 'err')
    );
}

function authorizeSSHKey(srvId) {
    const user = document.getElementById('auth_user_' + srvId)?.value?.trim() || 'root';
    const port = parseInt(document.getElementById('auth_port_' + srvId)?.value) || 22;
    const pass = document.getElementById('auth_pass_' + srvId)?.value || '';
    const res  = document.getElementById('auth-result-' + srvId);

    if (res) res.innerHTML = '<span style="color:#8b949e">Connecting...</span>';

    fetch('php/api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'authorize_ssh_key',
            server_id: srvId,
            ssh_user: user,
            ssh_port: port,
            one_time_pass: pass
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'success') {
            // Reemplazar bloque entero con vista mínima
            const section = document.getElementById('ssh-authorize-' + srvId);
            if (section) {
                section.innerHTML =
                    '<div style="display:flex;align-items:center;justify-content:space-between">' +
                    '<span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--green)"><i class="bi bi-check-circle-fill"></i> SSH access authorized</span>' +
                    '<button type="button" class="btn btn-link btn-sm" style="font-size:10px;color:var(--text-dim);padding:0" onclick="sshAuthorizeReset(' + srvId + ')">reconfigure</button>' +
                    '</div>';
            }
        } else {
            if (res) res.innerHTML = '<span style="color:#f85149">✗ ' + escHtml(d.message || 'Error') + '</span>';
        }
    })
    .catch(() => {
        if (res) res.innerHTML = '<span style="color:#f85149">✗ Network error</span>';
    });
}

// Load pubkey cuando se muestra wake-proxy (evento en nav oculto)
document.addEventListener('DOMContentLoaded', () => {
    const ht2 = document.getElementById('hiddenTabs');
    if (ht2) ht2.addEventListener('shown.bs.tab', e => {
        if (e.target.dataset.bsTarget === '#tab-wake-proxy' && !_sshPubkey) loadSshPubkey();
    });
});

// ── ONBOARDING WIZARD ────────────────────────────────────────────────────────
const _ob = {
    step: 1,
    type: '',
    sshMode: 'auto',
    skipApi: false,
    skipSsh: false,
};

function obGoTo(n) {
    if (n === 3 && !_obValidateStep2()) return;

    document.getElementById('ob-step-' + _ob.step)?.classList.remove('active');
    _ob.step = n;
    document.getElementById('ob-step-' + n)?.classList.add('active');
    document.getElementById('ob-progress-fill').style.width = (n * 25) + '%';
    window.scrollTo({ top: 0, behavior: 'smooth' });

    if (n === 3) _obRenderAccessStep();
}

function _obValidateStep2() {
    const hn = document.getElementById('ob-hn').value.trim();
    const ip = document.getElementById('ob-ip').value.trim();
    if (!hn) { document.getElementById('ob-hn').focus(); _obShake('ob-hn'); return false; }
    if (!ip) { document.getElementById('ob-ip').focus(); _obShake('ob-ip'); return false; }
    if (!_ob.type) { _obShake('ob-type-grid'); return false; }
    return true;
}

function _obShake(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.animation = 'none';
    el.offsetHeight;
    el.style.animation = 'obShake .3s ease';
    setTimeout(() => el.style.animation = '', 350);
}

function obSelectType(t) {
    _ob.type = t;
    document.querySelectorAll('.ob-type-btn').forEach(b => {
        b.classList.toggle('selected', b.dataset.val === t);
    });
}

function _obRenderAccessStep() {
    const apiTypes  = ['pve', 'pbs', 'truenas', 'omv'];
    const sshTypes  = ['linux', 'windows', 'generic'];
    const pveTypes  = ['pve'];
    const pbsTypes  = ['pbs'];
    const tnTypes   = ['truenas'];

    const needsApi  = apiTypes.includes(_ob.type);
    const needsSsh  = sshTypes.includes(_ob.type);

    document.getElementById('ob-access-api').style.display  = needsApi  ? '' : 'none';
    document.getElementById('ob-access-ssh').style.display  = needsSsh  ? '' : 'none';
    document.getElementById('ob-access-none').style.display = (!needsApi && !needsSsh) ? '' : 'none';

    if (needsApi) {
        document.getElementById('ob-pve-api-steps').style.display = pveTypes.includes(_ob.type) ? '' : 'none';
        document.getElementById('ob-pbs-api-steps').style.display = pbsTypes.includes(_ob.type) ? '' : 'none';
        document.getElementById('ob-tn-api-steps').style.display  = tnTypes.includes(_ob.type)  ? '' : 'none';

        const istn = tnTypes.includes(_ob.type);
        document.getElementById('ob-user-row').style.display = istn ? 'none' : '';
        document.getElementById('ob-tid-row').style.display  = istn ? 'none' : '';
        document.getElementById('ob-secret-label').textContent = istn ? 'API Key' : 'Token Secret';
    }

    if (needsSsh) {
        const pubEl = document.getElementById('ob-pubkey-text');
        if (pubEl) {
            if (_sshPubkey) {
                pubEl.textContent = _sshPubkey;
            } else {
                clearInterval(_waitKeyInterval);
                loadSshPubkey();
                _waitKeyInterval = setInterval(() => {
                    if (_sshPubkey) { pubEl.textContent = _sshPubkey; clearInterval(_waitKeyInterval); _waitKeyInterval = null; }
                }, 200);
                setTimeout(() => { clearInterval(_waitKeyInterval); _waitKeyInterval = null; }, 8000);
            }
        }
    }
}

function obSshMode(mode) {
    _ob.sshMode = mode;
    document.getElementById('ob-ssh-opt-auto').classList.toggle('ob-ssh-opt-active', mode === 'auto');
    document.getElementById('ob-ssh-opt-manual').classList.toggle('ob-ssh-opt-active', mode === 'manual');
    document.getElementById('ob-ssh-auto-form').style.display   = mode === 'auto'   ? '' : 'none';
    document.getElementById('ob-ssh-manual-form').style.display = mode === 'manual' ? '' : 'none';
}

function obSkipApi() { _ob.skipApi = true; obSave(); }
function obSkipSsh() { _ob.skipSsh = true; obSave(); }

function obCopyPubKey(btn) {
    const txt = document.getElementById('ob-pubkey-text').textContent;
    const i = btn.querySelector('i');
    _clipboardCopy(txt, () => {
        i.className = 'bi bi-check-lg';
        setTimeout(() => i.className = 'bi bi-copy', 1800);
    }, () => {});
}

async function obSave() {
    const hn  = document.getElementById('ob-hn').value.trim();
    const ip  = document.getElementById('ob-ip').value.trim();
    const mac = document.getElementById('ob-mac').value.trim();

    const payload = {
        action:          'add_server',
        hostname:        hn,
        ip:              ip,
        mac:             mac,
        hypervisor_type: _ob.type,
        role:            _obDefaultRole(_ob.type),
        api_enabled:     '0',
    };

    const apiTypes = ['pve', 'pbs', 'truenas', 'omv'];
    const sshTypes = ['linux', 'windows', 'generic'];

    if (apiTypes.includes(_ob.type) && !_ob.skipApi) {
        const istn = _ob.type === 'truenas';
        payload.api_enabled = '1';
        if (!istn) {
            payload.api_user   = document.getElementById('ob-api-user').value.trim();
            payload.token_id   = document.getElementById('ob-api-tid').value.trim();
        }
        payload.token_secret = document.getElementById('ob-api-secret').value.trim();
    }

    if (sshTypes.includes(_ob.type) && !_ob.skipSsh && _ob.sshMode === 'auto') {
        payload.pc_ssh_user = document.getElementById('ob-ssh-user').value.trim();
        payload.pc_ssh_pass = document.getElementById('ob-ssh-pass').value;
    }

    const saveBtn = document.querySelector('#ob-step-3 .ob-btn-primary');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }

    try {
        const r = await fetch('php/api.php', { method: 'POST',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const d = await r.json();

        if (d.status === 'success') {
            document.getElementById('ob-done-title').textContent = hn + ' added!';
            obGoTo(4);
        } else {
            alert('Error: ' + (d.message || d.error || 'Unknown error'));
            if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save &amp; continue'; }
        }
    } catch(e) {
        alert('Network error: ' + e.message);
        if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save &amp; continue'; }
    }
}

function _obDefaultRole(type) {
    const roles = { pve: 'primary', pbs: 'pbs', truenas: 'nas', omv: 'nas', windows: 'pc', linux: 'primary', generic: 'primary' };
    return roles[type] || 'primary';
}

// ── Kiosk token ───────────────────────────────────────────
async function genKioskToken() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const bytes = crypto.getRandomValues(new Uint8Array(8));
    const raw = Array.from(bytes).map(b => chars[b % chars.length]).join('');
    const token = raw.slice(0,4) + '-' + raw.slice(4);
    const res = await fetch('php/api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_setting', key: 'kiosk_token', value: token })
    });
    const data = await res.json();
    if (data.status !== 'success') { showToast('Error guardando token', 'danger'); return; }
    const inp = document.getElementById('kiosk-token-input');
    if (inp) inp.value = token;
    const urlBase = window.location.origin + window.location.pathname.replace(/\/[^/]*$/, '');
    const url = urlBase + '/rack.php?k=' + token;
    const urlEl = document.getElementById('kiosk-url-display');
    if (urlEl) {
        urlEl.textContent = url;
        urlEl.closest('.d-flex')?.style.setProperty('display', 'flex');
    } else {
        // primera vez: mostrar la zona de URL que estaba oculta por el else de PHP
        const hint = document.querySelector('#sys-panel-ui p[style*="Generate a token"]');
        if (hint) hint.remove();
        const container = inp?.closest('.card-body');
        if (container) {
            const div = document.createElement('div');
            div.className = 'd-flex gap-2 align-items-center mt-2';
            div.innerHTML = `<code id="kiosk-url-display" style="font-size:10px;color:var(--text-dim);word-break:break-all;flex:1">${url}</code>
                <button class="btn btn-sm btn-outline-secondary" onclick="copyKioskUrl()" title="Copy URL"><i class="bi bi-copy" id="kiosk-copy-icon"></i></button>
                <a href="${url}" target="_blank" class="btn btn-sm btn-outline-primary" title="Open rack view"><i class="bi bi-box-arrow-up-right"></i></a>`;
            container.appendChild(div);
        }
    }
    const openLink = document.querySelector('#sys-panel-ui a[href*="rack.php"]');
    if (openLink) openLink.href = url;
}

function copyKioskUrl() {
    const url = document.getElementById('kiosk-url-display')?.textContent?.trim();
    if (!url) return;
    navigator.clipboard.writeText(url).then(() => {
        const icon = document.getElementById('kiosk-copy-icon');
        if (icon) { icon.className = 'bi bi-check-lg'; setTimeout(() => icon.className = 'bi bi-copy', 1800); }
    });
}
