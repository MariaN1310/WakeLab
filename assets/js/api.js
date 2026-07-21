function escHtml(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── GUARDAR SERVER ──────────────────────────────────────────────
function saveServer(e,id){
    e.preventDefault();
    const hyp=document.getElementById('hyp_'+id).value;
    const isPC = hyp==='windows'||hyp==='linux';
    const authMap={pve:'pve_token',pbs:'pbs_token',truenas:'truenas_apikey',omv:'omv_password'};
    const secret=document.getElementById('tsc_'+id)?.value||'';
    const isVm = document.getElementById('is_vm_'+id)?.checked;
    const defaultPorts={pve:'8006',pbs:'8007',truenas:'443',omv:'80',windows:'22',linux:'22'};
    const rawAddr=(document.getElementById('url_'+id)?.value||'').trim();
    const noProto=rawAddr.replace(/^https?:\/\//i,'');
    const portMatch=noProto.match(/:(\d+)$/);
    const parsedIp=portMatch?noProto.slice(0,noProto.lastIndexOf(':')):noProto.split('/')[0];
    const parsedPort=portMatch?portMatch[1]:defaultPorts[hyp]||'8006';
    const p={action:'update_server',id,
        hostname:   document.getElementById('hn_'+id).value,
        ip:         parsedIp,
        port:       parsedPort,
        mac:        document.getElementById('mac_'+id).value,
        proxmox_server_id: isVm ? (document.getElementById('pve_srv_'+id)?.value||null) : null,
        proxmox_vmid:      isVm ? (document.getElementById('vmid_'+id)?.value||null)    : null,
        depends_on_server_id: document.getElementById('dep_'+id)?.value || null,
        hypervisor_type:hyp,
        api_enabled: isPC ? '1' : (document.getElementById('api_toggle_'+id)?.checked?'1':'0'),
        notes:      document.getElementById('not_'+id).value,
        url:        noProto,
        auth_type:  isPC?'none':(authMap[hyp]||'none'),
        api_user:   document.getElementById('apu_'+id)?.value||'',
        token_id:   document.getElementById('tid_'+id)?.value||'',
    };
    if(!isPC && secret) p.token_secret=secret;
    fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)})
    .then(r=>r.json())
    .then(d=>{
        if (d.status !== 'success') { showToast('Error: '+d.message,'err'); return; }
        showToast('Saved. Testing connection…', 'ok');
        fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},
            body:JSON.stringify({action:'test_connection', server_id: id})})
        .then(r=>r.json())
        .then(td=>{
            const ok = td.data?.ok;
            if (ok) showToast('Connection verified ✓', 'ok');
            else    showToast('Saved, but no connection — check IP and credentials', 'warn');
        })
        .catch(()=>{});
    })
    .catch(()=>showToast('Network error','err'));
}

function confirmDeleteServer(id, hostname) {
    pendingAction = { server_id: id, command: '_delete' };
    document.getElementById('modal-icon').textContent  = '🗑';
    document.getElementById('modalTitle').textContent  = 'Delete server';
    document.getElementById('modalMsg').textContent    = 'This action is irreversible. Associated schedules, tokens and idle config will also be deleted.';
    document.getElementById('modal-srv-badge').textContent = hostname;
    const cb = document.getElementById('confirmBtn');
    cb.className = 'btn btn-outline-danger'; cb.style = 'flex:1;padding:8px 0';
    cb.dataset.mode = 'delete';
    cb.textContent = 'Delete'; cb.disabled = false;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('actionModal')).show();
}

// ── GUARDAR SSH TRUENAS ────────────────────────────────────────
// ── GUARDAR SSH (TrueNAS y OMV comparten el mismo endpoint) ────
function saveSSH(id, prefix){
    // new IDs: prefix_ssh_{id}_user / prefix_ssh_{id}_pass / prefix_ssh_{id}_port
    const uid  = prefix+'_ssh_'+id;
    const user = document.getElementById(uid+'_user')?.value || '';
    // only send pass if password auth is selected (btn-pass active)
    const passBtnActive = document.getElementById('btn-pass-'+uid)?.classList.contains('active');
    const pass = passBtnActive ? (document.getElementById(uid+'_pass')?.value || '') : '';
    const port = parseInt(document.getElementById(uid+'_port')?.value||'22',10)||22;
    fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
        action:'save_truenas_ssh', server_id:id, ssh_user:user, ssh_pass:pass, ssh_port:port,
    })})
    .then(r=>r.json())
    .then(d=>{
        if(d.status==='success') showToast('SSH saved','ok');
        else showToast('Error: '+d.message,'err');
    })
    .catch(()=>showToast('Network error','err'));
}
function saveTrueNASSSH(id){ saveSSH(id,'tn'); }
function saveOMVSSH(id)     { saveSSH(id,'omv'); }

function tokenReconfigure(srvId) {
    const section = document.getElementById('token-configured-' + srvId);
    if (!section) return;
    // Detectar tipo por los hidden inputs
    const apu = section.querySelector('#apu_' + srvId)?.value || '';
    const tid = section.querySelector('#tid_' + srvId)?.value || '';
    const isTruenas = apu === 'truenas';
    const isPveOrPbs = !isTruenas;

    if (isTruenas) {
        section.innerHTML =
            '<div class="cfg-section-title">api key</div>' +
            '<div class="form-row"><span class="form-label">API Key</span>' +
            '<input id="tsc_' + srvId + '" class="form-control" type="password" placeholder="paste new API key" autocomplete="new-password"></div>' +
            '<input type="hidden" id="apu_' + srvId + '" value="truenas">' +
            '<input type="hidden" id="tid_' + srvId + '" value="apikey">';
    } else {
        section.innerHTML =
            '<div class="cfg-section-title">API token</div>' +
            '<div class="form-row"><span class="form-label">User</span>' +
            '<input id="apu_' + srvId + '" class="form-control" value="' + escHtml(apu) + '"></div>' +
            '<div class="form-row"><span class="form-label">Token ID</span>' +
            '<input id="tid_' + srvId + '" class="form-control" value="' + escHtml(tid) + '"></div>' +
            '<div class="form-row"><span class="form-label">Secret</span>' +
            '<input id="tsc_' + srvId + '" class="form-control" type="password" placeholder="new UUID" autocomplete="new-password"></div>';
    }
}

function sshBlockReset(uid, srvId) {
    fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'reset_ssh_key_ok', server_id: srvId})})
    .then(() => {
        const badge = document.getElementById('ssh-block-ok-'   + uid);
        const form  = document.getElementById('ssh-block-form-' + uid);
        if (badge) badge.style.display = 'none';
        if (form)  form.style.display  = '';
    });
}

function sshAuthorizeReset(srvId) {
    fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'reset_ssh_key_ok', server_id: srvId})})
    .then(() => {
        const ok  = document.getElementById('ssh-authorized-' + srvId);
        const cfg = document.getElementById('ssh-authorize-'  + srvId);
        if (ok)  ok.style.display  = 'none';
        if (cfg) cfg.style.display = '';
    });
}

function setSshAuth(uid, usePass) {
    const passRow = document.getElementById('pass-row-'+uid);
    const btnKey  = document.getElementById('btn-key-'+uid);
    const btnPass = document.getElementById('btn-pass-'+uid);
    if (!passRow) return;
    passRow.style.display = usePass ? '' : 'none';
    btnKey.classList.toggle('active', !usePass);
    btnPass.classList.toggle('active', usePass);
}

// ── GUARDAR SCHEDULE ───────────────────────────────────────────
// ── AUTO-SAVE (debounce) ───────────────────────────────────────
const _saveTimers = {};
function autoSaveSchedule(id) {
    clearTimeout(_saveTimers['sch_'+id]);
    _saveTimers['sch_'+id] = setTimeout(() => saveSchedule(id), 700);
}
function autoSaveIdle(id) {
    clearTimeout(_saveTimers['idl_'+id]);
    _saveTimers['idl_'+id] = setTimeout(() => saveIdle(id), 700);
}

// Delegar change/input en los contenedores con data-autosave
document.addEventListener('change', e => {
    const s = e.target.closest('[data-autosave-sch]');
    if (s) { autoSaveSchedule(parseInt(s.dataset.autosaveSch)); return; }
    const i = e.target.closest('[data-autosave-idl]');
    if (i) autoSaveIdle(parseInt(i.dataset.autosaveIdl));
});
document.addEventListener('input', e => {
    if (e.target.type !== 'range') return;
    const i = e.target.closest('[data-autosave-idl]');
    if (i) autoSaveIdle(parseInt(i.dataset.autosaveIdl));
});

function saveSchedule(id){
    const active=document.getElementById('sch-toggle-'+id)?.checked?1:0;
    const shutActive=document.getElementById('shut-toggle-'+id)?.checked?1:0;
    const days=[...document.querySelectorAll(`#days-${id} .day-btn.on`)].map(b=>b.dataset.day);
    const displayTz=(loadUiPrefs?loadUiPrefs():JSON.parse(localStorage.getItem('wakelab_ui')||'{}')).timezone||'';
    fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
        action:'update_schedule',server_id:id,active,shutdown_active:shutActive,days,
        boot_time:  document.getElementById('boot_'+id+'_h').value+':'+document.getElementById('boot_'+id+'_m').value+':00',
        shutdown_time: document.getElementById('shut_'+id+'_h').value+':'+document.getElementById('shut_'+id+'_m').value+':00',
        method:     document.getElementById('meth_'+id).value,
        display_tz: displayTz,
    })}).then(r=>r.json()).then(()=>{
        showToast('Schedule saved','ok');
        syncQuickCfg(id, active, shutActive);
    }).catch(()=>showToast('Error','err'));
}

// ── GUARDAR IDLE ───────────────────────────────────────────────
function _idlePayload(id){
    return {
        action:'update_idle',server_id:id,
        active: document.getElementById('idle-toggle-'+id).checked?1:0,
        idle_limit_sec:     parseInt(document.getElementById('idle-limit-'+id).value)*60,
        check_interval_sec: 300,
        remote_path: (document.getElementById('apu_'+id)?.value === 'truenas')
            ? '/root/idle-shutdown.sh'
            : '/usr/local/bin/idle-shutdown.sh',
        detectors:{
            smb:      document.getElementById('det-smb-'+id).checked,
            jellyfin: document.getElementById('det-jellyfin-'+id).checked,
            qbit:     document.getElementById('det-qbit-'+id).checked,
            ssh:      document.getElementById('det-ssh-'+id).checked,
            pbs:      document.getElementById('det-pbs-'+id).checked,
            cpu:      document.getElementById('det-cpu-'+id).checked,
            cpu_threshold: parseInt(document.getElementById('idle-cpu-'+id).value),
        },
        detector_params:{
            jellyfin:{
                host:  document.getElementById('prm-jf-host-'+id)?.value||'localhost',
                port:  document.getElementById('prm-jf-port-'+id)?.value||'8096',
                token: document.getElementById('prm-jf-token-'+id)?.value||'',
            },
            qbit:{
                host: document.getElementById('prm-qb-host-'+id)?.value||'localhost',
                port: document.getElementById('prm-qb-port-'+id)?.value||'8080',
            },
            pbs:{
                host: document.getElementById('prm-pbs-host-'+id)?.value||'localhost',
                port: document.getElementById('prm-pbs-port-'+id)?.value||'8007',
            }
        }
    };
}

function saveIdle(id){
    fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(_idlePayload(id))})
    .then(r=>r.json()).then(d=>{
        showToast(d.status==='success'?'Config saved':'Error: '+(d.message||'see logs'), d.status==='success'?'ok':'err');
        const active=document.getElementById('idle-toggle-'+id)?.checked?1:0;
        const c=document.getElementById('qidl-'+id);
        if(c) c.checked=!!active;
    }).catch(()=>showToast('Error','err'));
}

// ── DEPLOY IDLE (guardar → generar → deploy SSH) ───────────────
function deployIdle(id, btn){
    if(btn){btn.textContent='Saving…';btn.disabled=true;}
    const post=(body)=>fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(r=>r.json());
    const reset=()=>{if(btn){btn.textContent='→ Deploy';btn.disabled=false;}};
    post(_idlePayload(id))
    .then(d=>{
        if(d.status!=='success') throw new Error(d.message||'Error saving');
        if(btn) btn.textContent='Generating…';
        return post({action:'generate_idle_script',server_id:id});
    })
    .then(d=>{
        if(d.status!=='success') throw new Error(d.message||'Error generating');
        if(btn) btn.textContent='Deploying…';
        return post({action:'deploy_idle_script',server_id:id,script:d.data.script});
    })
    .then(d=>{
        reset();
        const ok=d.status==='success';
        showToast(ok?'Script deployed successfully':'Deploy failed: '+d.message, ok?'ok':'err');
        document.getElementById('script-output-'+id).innerHTML='';
        if (ok) unlockIdleSection(id);
    })
    .catch(e=>{reset();showToast('Error: '+e.message,'err');});
}

// ── TEST CONEXIÓN ──────────────────────────────────────────────
function testConnection(id){
    const el=document.getElementById('test-steps-'+id);
    if(el) el.innerHTML='<div class="loading"><span class="spinner"></span>testing...</div>';
    fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'test_connection',server_id:id})})
    .then(r=>r.json())
    .then(resp=>{
        if(!el)return;
        const d=resp.data||{};
        const steps=d.steps||[];
        el.innerHTML=steps.map(s=>`
        <div class="test-step ${s.ok?'ok':'fail'}">
            <span class="step-icon">${s.ok?'✓':'✗'}</span>
            <div class="step-info">
                <span class="step-label">${escHtml(s.step)}</span>
                <span class="step-detail">${escHtml(s.detail||'')}</span>
                ${s.debug?.curl_err?`<span style="color:#f85149;font-size:10px">curl: ${escHtml(s.debug.curl_err)}</span>`:''}
                ${s.debug?.http&&s.debug.http!==200?`<span style="color:#d29922;font-size:10px">HTTP ${s.debug.http}</span>`:''}
            </div>
        </div>`).join('');
        showToast(d.ok ? 'Connection OK' : 'Failed — see steps', (resp.status === 'success' && d.ok) ? 'ok' : 'err');
    })
    .catch(()=>showToast('Error running test','err'));
}

// ── LOGS ───────────────────────────────────────────────────────
const _LVL={
    ok:  {icon:'bi-check-circle-fill',         cls:'log-lvl-ok'},
    warn:{icon:'bi-exclamation-triangle-fill',  cls:'log-lvl-warn'},
    err: {icon:'bi-x-circle-fill',              cls:'log-lvl-err'},
    info:{icon:'bi-info-circle-fill',           cls:'log-lvl-info'},
};
function setLogLvl(btn){
    document.querySelectorAll('#log-filter-lvl button').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    loadLogs();
}
function setSrvLogLvl(btn, srvId){
    btn.closest('.btn-group').querySelectorAll('button').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    loadServerLogs(srvId);
}
let _logOffset = 0;
let _logAutoRefresh = null;

function _buildLogUrl(offset = 0) {
    const srv    = document.getElementById('log-filter-srv')?.value || '';
    const lvl    = document.querySelector('#log-filter-lvl .active')?.dataset.lvl || '';
    const search = document.getElementById('log-search')?.value.trim() || '';
    let url = `php/api.php?action=get_logs&limit=50&offset=${offset}`;
    if (srv)    url += '&server='  + encodeURIComponent(srv);
    if (lvl)    url += '&level='   + encodeURIComponent(lvl);
    if (search) url += '&search='  + encodeURIComponent(search);
    return url;
}

function _renderLogRow(ev) {
    const l   = _LVL[ev.level] || _LVL.info;
    const ts  = fmtTs(ev.timestamp);
    const ago = _logAgo(ev.timestamp);
    return `<div class="log-line" data-lvl="${ev.level}">
        <span class="log-lvl-bar log-bar-${ev.level}"></span>
        <i class="bi ${l.icon} log-lvl-icon ${l.cls}"></i>
        <span class="log-time" title="${escHtml(ev.timestamp)}">${ts}<span class="log-ago">${ago}</span></span>
        <span class="log-srv">${escHtml(ev.hostname || 'system')}</span>
        <span class="log-msg">${escHtml(ev.message)}</span>
    </div>`;
}

function _logAgo(ts) {
    if (!ts) return '';
    const d = new Date(ts.replace(' ', 'T') + 'Z');
    if (isNaN(d)) return '';
    const sec = Math.floor((Date.now() - d.getTime()) / 1000);
    if (sec < 60)   return ' · now';
    if (sec < 3600) return ' · ' + Math.floor(sec / 60) + 'm';
    if (sec < 86400)return ' · ' + Math.floor(sec / 3600) + 'h';
    return ' · ' + Math.floor(sec / 86400) + 'd';
}

function loadLogs(reset = true, silent = false) {
    if (reset) _logOffset = 0;
    const el = document.getElementById('log-entries');
    if (!el) return;
    if (reset && !silent && !el.querySelector('.log-line')) {
        el.innerHTML = '<div class="log-loading"><span class="spinner"></span>loading…</div>';
    }

    fetch(_buildLogUrl(_logOffset)).then(r => r.json()).then(resp => {
        const data    = resp.data || {};
        const rows    = data.rows || [];
        const hasMore = data.has_more || false;

        if (reset) {
            el.innerHTML = rows.length
                ? rows.map(_renderLogRow).join('')
                : '<div class="log-empty"><i class="bi bi-journal-x"></i><span>no events</span></div>';
        } else {
            // quitar botón "cargar más" viejo si existe
            el.querySelector('.log-load-more')?.remove();
            rows.forEach(ev => el.insertAdjacentHTML('beforeend', _renderLogRow(ev)));
        }

        // botón "cargar más"
        el.querySelector('.log-load-more')?.remove();
        if (hasMore) {
            el.insertAdjacentHTML('beforeend',
                `<div class="log-load-more"><button onclick="loadMoreLogs()">load more</button></div>`);
        }

        // contador
        const counter = document.getElementById('log-count');
        if (counter) {
            const visible = el.querySelectorAll('.log-line').length;
            counter.textContent = visible + (hasMore ? '+' : '') + ' events';
        }
    }).catch(() => {
        if (reset) {
            const el2 = document.getElementById('log-entries');
            if (el2) el2.innerHTML = '<div class="log-empty"><i class="bi bi-wifi-off"></i><span>error loading</span></div>';
        }
    });
}

function loadMoreLogs() {
    _logOffset += 50;
    loadLogs(false);
}

function startLogAutoRefresh() {
    stopLogAutoRefresh();
    loadLogs();
    _logAutoRefresh = setInterval(() => {
        if (document.getElementById('tab-logs')?.classList.contains('active')) loadLogs(true, true);
    }, 15000);
}

function stopLogAutoRefresh() {
    if (_logAutoRefresh) { clearInterval(_logAutoRefresh); _logAutoRefresh = null; }
}

function addNewServer(e){
    e.preventDefault();
    const hyp=document.getElementById('new_hyp').value;
    if(!hyp){ showToast('Select the system type','warn'); return; }
    const isPC = hyp==='windows' || hyp==='linux';
    const authMap={pve:'pve_token',pbs:'pbs_token',truenas:'truenas_apikey',omv:'omv_password'};
    const addr=document.getElementById('new_addr').value.trim();
    const noProto=addr.replace(/^https?:\/\//i,'');
    const portMatch=noProto.match(/:(\d+)$/);
    const defaultPorts={pve:'8006',pbs:'8007',truenas:'443',omv:'80',windows:'22',linux:'22'};
    const ip=portMatch?noProto.slice(0,noProto.lastIndexOf(':')):noProto;
    const port=portMatch?portMatch[1]:(document.getElementById('new_port')?.value||defaultPorts[hyp]||'8006');
    const apiEnabled=isPC?'1':(document.getElementById('new_api')?.checked?'1':'0');
    const payload={
        action:'add_server',
        hostname:   document.getElementById('new_hn').value,
        ip,port,
        url:        noProto,
        mac:        document.getElementById('new_mac').value,
        proxmox_server_id: !isPC && document.getElementById('new_is_vm')?.checked ? (document.getElementById('new_pve_srv')?.value||null) : null,
        proxmox_vmid:      !isPC && document.getElementById('new_is_vm')?.checked ? (document.getElementById('new_vmid')?.value||null) : null,
        depends_on_server_id: document.getElementById('new_dep')?.value || null,
        role:       document.getElementById('new_role').value,
        hypervisor_type: hyp,
        api_enabled:apiEnabled,
        auth_type:  isPC?'none':(authMap[hyp]||'none'),
        api_user:   !isPC&&apiEnabled==='1'?(document.getElementById('new_apu')?.value||''):'',
        token_id:   !isPC&&apiEnabled==='1'?(document.getElementById('new_tid')?.value||''):'',
        token_secret:!isPC&&apiEnabled==='1'?(document.getElementById('new_tsc')?.value||''):'',
    };
    if (isPC) {
        payload.pc_ssh_user = document.getElementById('new_ssh_user')?.value||'';
        payload.pc_ssh_pass = document.getElementById('new_ssh_pass')?.value||'';
    }
    if (!hyp)              { showToast('Choose a system type', 'err'); return; }
    if (!document.getElementById('new_role').value) { showToast('Choose a role', 'err'); return; }
    if (!payload.hostname) { showToast('Hostname missing', 'err'); return; }
    if (!payload.ip)       { showToast('IP or URL missing', 'err'); return; }
    if (!isPC && apiEnabled==='1' && !payload.token_secret) {
        showToast('Token secret / API key missing', 'err'); return;
    }

    const btn = document.querySelector('#addModal button[type="submit"]');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }

    fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(r=>r.json())
    .then(d=>{
        if (d.status !== 'success') {
            showToast(d.message||'Error saving', 'err');
            if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
            return;
        }
        const newId = d.data?.id;
        if (!newId) { showToast('Host registered', 'ok'); setTimeout(()=>location.reload(), 1200); return; }

        const idleTog    = document.getElementById('new_idle');
        const idleWanted = idleTog?.checked;
        const hyp        = document.getElementById('new_hyp')?.value || '';
        const needsSshSave = idleWanted && ['pve','pbs','truenas','omv'].includes(hyp);

        const showStep2 = () => {
            if (btn) { btn.disabled = false; btn.textContent = 'Register'; }
            const form = document.getElementById('addModal').querySelector('form');
            if (form) form.style.display = 'none';
            document.getElementById('new-step2').style.display = '';
            if (idleWanted) document.getElementById('new-step2-idle').style.display = '';
            window._newServerId = newId;
        };

        const testAndFinish = () => {
            showToast('Host registered. Testing connection…', 'ok');
            fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},
                body:JSON.stringify({action:'test_connection', server_id: newId})})
            .then(r=>r.json())
            .then(td=>{
                if (td.data?.ok) showToast('Connection verified ✓', 'ok');
                else             showToast('Host saved but no connection — check IP and credentials', 'warn');
                localStorage.setItem('wl_new_guide', hyp || 'generic');
                setTimeout(()=>location.reload(), 2000);
            })
            .catch(()=>{
                localStorage.setItem('wl_new_guide', hyp || 'generic');
                setTimeout(()=>location.reload(), 1200);
            });
        };

        if (idleWanted) {
            if (needsSshSave) {
                const uid  = 'new_idle';
                const user = document.getElementById('new_idle_user')?.value || '';
                const passBtnActive = document.getElementById('btn-pass-'+uid)?.classList.contains('active');
                const pass = passBtnActive ? (document.getElementById('new_idle_pass')?.value || '') : '';
                const port = parseInt(document.getElementById('new_idle_port')?.value || '22', 10) || 22;
                fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
                    action:'save_truenas_ssh', server_id:newId, ssh_user:user, ssh_pass:pass, ssh_port:port
                })})
                .then(r=>r.json())
                .then(()=>showStep2())
                .catch(()=>showStep2());
            } else {
                showStep2();
            }
        } else {
            testAndFinish();
        }
    })
    .catch(()=>{
        showToast('Network error','err');
        if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
    });
}

function deployIdleForNew() {
    const id = window._newServerId;
    if (!id) return;
    const btn    = document.getElementById('new-deploy-btn');
    const result = document.getElementById('new-deploy-result');
    if (btn) { btn.textContent = 'Authorizing SSH key…'; btn.disabled = true; }
    const post = body => fetch('php/api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(r=>r.json());
    post({action:'authorize_wakelab_key', server_id:id})
    .then(d=>{
        if (d.status !== 'success') throw new Error(d.message || 'Error authorizing SSH key');
        if (btn) btn.textContent = 'Saving config…';
        return post({action:'update_idle', server_id:id, active:1, threshold_minutes:30, detectors:[], detector_params:{}});
    })
    .then(()=>{
        if (btn) btn.textContent = 'Generating script…';
        return post({action:'generate_idle_script', server_id:id});
    })
    .then(d=>{
        if (d.status !== 'success') throw new Error(d.message || 'Error generating');
        if (btn) btn.textContent = 'Deploying…';
        return post({action:'deploy_idle_script', server_id:id, script:d.data.script});
    })
    .then(d=>{
        if (d.status !== 'success') throw new Error(d.message || 'Deploy failed');
        if (btn) { btn.textContent = '✓ Done'; btn.disabled = true; btn.className = 'btn btn-outline-success w-100'; }
        if (result) { result.style.display = ''; result.innerHTML = '<span style="color:var(--green)">SSH key authorized · Script active · You can configure detectors from the host config</span>'; }
        showToast('Idle script deployed ✓', 'ok');
    })
    .catch(e=>{
        if (btn) { btn.textContent = 'Deploy idle script'; btn.disabled = false; }  // button label intentionally kept as-is
        showToast('Error: ' + e.message, 'err');
    });
}

function saveGuestMeta() {
    if (!currentVm) return;
    let url = document.getElementById('vd-url-input').value.trim();
    if (!url) { showToast('Enter a URL', 'err'); return; }
    if (!/^https?:\/\//i.test(url)) url = 'http://' + url;
    fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({
        action: 'update_guest_meta', server_id: currentVm.srvId, vmid: currentVm.vmid, url,
    })})
    .then(r=>r.json())
    .then(d => {
        if (d.status==='success') {
            showToast('URL saved', 'ok');
            document.getElementById('vd-url-open-row').style.display = 'block';
            document.getElementById('vd-url-config').style.display   = 'none';
            document.getElementById('vd-url-toggle-btn').textContent  = 'editar ▾';
            const link = document.getElementById('vd-url-open-link');
            if (link) link.href = url;
        } else showToast(d.message||'Error', 'err');
    })
    .catch(()=>showToast('Error','err'));
}

function openGuestUrl() {
    let url = document.getElementById('vd-url-input').value.trim();
    if (!url) { showToast('Configure the URL first', 'err'); return; }
    if (!/^https?:\/\//i.test(url)) url = 'http://' + url;
    const link = document.getElementById('vd-url-open-link');
    if (link) link.href = url;
    window.open(url, '_blank');
}

function saveVmSchedule() {
    if (!currentVm) return;
    const active    = document.getElementById('vd-sch-toggle').checked;
    const shutActive = document.getElementById('vd-shut-toggle').checked;
    const bootTime  = document.getElementById('vd-boot-time_h').value+':'+document.getElementById('vd-boot-time_m').value;
    const shutTime  = document.getElementById('vd-shutdown-time_h').value+':'+document.getElementById('vd-shutdown-time_m').value;
    const displayTz = (loadUiPrefs ? loadUiPrefs() : JSON.parse(localStorage.getItem('wakelab_ui')||'{}')).timezone || '';
    fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({
        action:           'update_guest_schedule',
        server_id:        currentVm.srvId,
        vmid:             currentVm.vmid,
        vmtype:           currentVm.type,
        boot_time:        bootTime + ':00',
        shutdown_time:    shutTime + ':00',
        active:           active ? 1 : 0,
        shutdown_active:  shutActive ? 1 : 0,
        display_tz:       displayTz,
    })})
    .then(r=>r.json())
    .then(d => showToast(d.status==='success' ? 'Schedule saved' : (d.message||'Error'), d.status==='success'?'ok':'err'))
    .catch(()=>showToast('Network error','err'));
}

function saveVmIdle() {
    if (!currentVm) return;
    const active  = document.getElementById('vd-idle-toggle').checked;
    const minutes = parseInt(document.getElementById('vd-idle-min').value) || 30;
    fetch('php/api.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({
        action:          'update_guest_idle',
        server_id:       currentVm.srvId,
        vmid:            currentVm.vmid,
        vmtype:          currentVm.type,
        idle_limit_sec:  minutes * 60,
        active:          active ? 1 : 0,
    })})
    .then(r=>r.json())
    .then(d => showToast(d.status==='success' ? 'Idle saved' : (d.message||'Error'), d.status==='success'?'ok':'err'))
    .catch(()=>showToast('Network error','err'));
}

// Cerrar con Escape — Bootstrap handles modal/offcanvas automatically,
// but keep for any custom logic
document.addEventListener('keydown', e => { if (e.key==='Escape') closeVmDrawer(); });

function loadServerLogs(srvId) {
    const el = document.getElementById('srv-log-list-' + srvId);
    if (!el) return;
    const lvl = document.querySelector(`#srv-log-filter-lvl-${srvId} .active`)?.dataset.lvl||'';
    let url = `php/api.php?action=get_logs&server_id=${srvId}&limit=30`;
    if(lvl) url += '&level='+encodeURIComponent(lvl);
    fetch(url)
        .then(r => r.json())
        .then(d => {
            const rows = d.data?.rows || d.data || [];
            if (d.status !== 'success' || !rows.length) {
                el.innerHTML = '<div class="loading">no events recorded for this host</div>';
                return;
            }
            el.innerHTML = rows.map(ev => {
                const l   = _LVL[ev.level] || _LVL.info;
                const msg = ev.message || '';
                return `<div class="srv-log-row">`
                    +`<i class="bi ${l.icon} log-lvl-icon ${l.cls}" title="${ev.level}"></i>`
                    +`<span class="srv-log-ts">${fmtTs(ev.timestamp)}</span>`
                    +`<span class="srv-log-msg" title="${escHtml(msg)}">${escHtml(msg)}</span>`
                    +`</div>`;
            }).join('');
        })
        .catch(() => { if (el) el.innerHTML = '<div class="loading">error loading logs</div>'; });
}

// ── WAKE PROXY API ───────────────────────────────────────────

function apiWakeProxy(payload) {
    return fetch('php/api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload),
    }).then(r => r.json());
}

function wpAdd(data)       { return apiWakeProxy({action:'add_wake_proxy',    ...data}); }
function wpUpdate(data)    { return apiWakeProxy({action:'update_wake_proxy',  ...data}); }
function wpDelete(id)      { return apiWakeProxy({action:'delete_wake_proxy',  id}); }
function wpList()          { return fetch('php/api.php?action=get_wake_proxies').then(r=>r.json()); }

function wpGetGuests(serverId) {
    return fetch(`php/api.php?action=get_pve_guests&server_id=${serverId}`).then(r => r.json());
}
