-- ============================================================
-- WakeLab — DB Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS wakelab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE wakelab;

-- ────────────────────────────────────────────────────────────
-- 1. SERVIDORES
--    hypervisor_type: 'pve' | 'pbs' | 'truenas'
--    port: puerto de la API/UI del hypervisor
--    url:  URL de acceso directa (panel web, etc.)
--    proxmox_server_id / proxmox_vmid: si este server es una VM hosteada en otro PVE
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS servers (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    hostname          VARCHAR(50)  NOT NULL UNIQUE,
    ip                VARCHAR(45)  NOT NULL,
    port              SMALLINT     NOT NULL DEFAULT 8006,
    mac               VARCHAR(17)  NOT NULL DEFAULT '',
    role              VARCHAR(20)  NOT NULL DEFAULT 'pve',
    hypervisor_type   ENUM('pve','pbs','truenas','omv','linux','windows','generic') NOT NULL DEFAULT 'pve',
    notes             TEXT,
    url               VARCHAR(512) DEFAULT NULL,
    last_seen         DATETIME     DEFAULT CURRENT_TIMESTAMP,
    api_enabled       BOOLEAN      DEFAULT 0,
    proxmox_server_id INT          DEFAULT NULL,
    proxmox_vmid      INT          DEFAULT NULL,
    depends_on_server_id INT       DEFAULT NULL,
    ups_managed       TINYINT(1)   NOT NULL DEFAULT 0,
    ups_priority      INT          NOT NULL DEFAULT 10,
    ups_last_state    VARCHAR(10)  DEFAULT NULL,
    ups_ignore_delay  TINYINT(1)   NOT NULL DEFAULT 0,
    ups_last_resort   TINYINT(1)   NOT NULL DEFAULT 0
);

-- ────────────────────────────────────────────────────────────
-- 2. TOKENS / CREDENCIALES DE API
--    auth_type: 'pve_token' | 'pbs_token' | 'truenas_apikey' | 'none'
--    token_secret: PVE/PBS = UUID del token; TrueNAS = API key completa
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS api_tokens (
    server_id    INT PRIMARY KEY,
    auth_type    ENUM('pve_token','pbs_token','truenas_apikey','omv_password','none') NOT NULL DEFAULT 'pve_token',
    api_user     VARCHAR(100) NOT NULL DEFAULT 'root@pam',
    token_id     VARCHAR(100) NOT NULL DEFAULT '',
    token_secret VARCHAR(512) NOT NULL DEFAULT '',
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- 3. SCHEDULES DE SERVIDORES
--    days_json: ["mon","tue","wed","thu","fri","sat","sun"]
--               vacío/null = todos los días
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS schedules (
    server_id       INT PRIMARY KEY,
    boot_time       TIME,
    shutdown_time   TIME,
    method          VARCHAR(20) DEFAULT 'Wake on LAN',
    active          BOOLEAN     DEFAULT 0,
    shutdown_active BOOLEAN     DEFAULT 0,
    days_json       JSON,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- 4. IDLE CONFIG
--    detector_params_json: {"jellyfin":{"host":"...","port":8096,"token":"..."},...}
--    remote_path: destino del script idle-shutdown.sh en el servidor
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS idle_config (
    server_id            INT PRIMARY KEY,
    idle_limit_sec       INT     DEFAULT 1800,
    check_interval_sec   INT     DEFAULT 300,
    detectors_json       JSON,
    detector_params_json JSON,
    remote_path          VARCHAR(255) DEFAULT '/usr/local/bin/idle-shutdown.sh',
    active               BOOLEAN DEFAULT 1,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- 5. EVENTOS / LOGS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS events (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    timestamp  DATETIME DEFAULT CURRENT_TIMESTAMP,
    server_id  INT NULL,
    level      ENUM('ok','warn','err','info') DEFAULT 'info',
    message    TEXT,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE SET NULL
);

CREATE INDEX idx_events_timestamp ON events(timestamp);
CREATE INDEX idx_events_server    ON events(server_id);

-- ────────────────────────────────────────────────────────────
-- 7. UPS EVENTS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ups_events (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    event          VARCHAR(50)  NOT NULL,
    ups_name       VARCHAR(100) DEFAULT NULL,
    hosts_affected JSON         DEFAULT NULL,
    status         VARCHAR(50)  NOT NULL DEFAULT 'processed',
    created_at     DATETIME     NOT NULL
);

-- ────────────────────────────────────────────────────────────
-- 6. SETTINGS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
    `key`         VARCHAR(80) NOT NULL PRIMARY KEY,
    `value`       TEXT        NOT NULL,
    `description` VARCHAR(255) NOT NULL DEFAULT '',
    updated_at    DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO settings (`key`, `value`, `description`) VALUES
  ('polling_interval_sec', '30',  'Intervalo de polling del frontend (segundos)'),
  ('api_timeout_sec',      '6',   'Timeout de llamadas a la API de hypervisores (segundos)'),
  ('status_cache_ttl_sec', '25',  'TTL del cache de get_status en el backend (segundos)'),
  ('ping_timeout_sec',     '3',   'Timeout del ping TCP por servidor (segundos)')
ON DUPLICATE KEY UPDATE `description`=VALUES(`description`);

-- Notification templates (seed defaults + immutable copy for reset)
INSERT INTO settings (`key`, `value`, `description`) VALUES
  ('tpl_server_down',
   '🔴 {hostname} not responding\nNo activity on {ip} since {time}. Check connectivity or hardware status.\n---\n⚠️ {hostname} offline · {date} {time}\nHost {ip} stopped responding. Could be a power cut, unexpected reboot or network failure.\n---\n{hostname} is down.\nIP: {ip} · Last seen: {time} · Check the device.',
   'Notification template server offline'),
  ('tpl_server_up',
   '✅ {hostname} back online\n{ip} started responding again at {time}. All good.\n---\n🟢 {hostname} is back · {time}\nHost {ip} is online. Everything is operational.\n---\n{hostname} responding ✅\nConnectivity restored on {ip} · {datetime}',
   'Notification template server online'),
  ('tpl_schedule',
   '📅 Schedule executed · {hostname}\nAction completed at {time}. IP: {ip}\n---\n⏱ {hostname} · {time}\n{body}\n---\n🕐 Schedule · {date}\n{hostname} ({ip}) ran the scheduled action at {time}.',
   'Notification template schedule'),
  ('tpl_idle',
   '💤 {hostname} shut down due to inactivity\nExtended inactivity detected. Shutdown executed at {time}.\n---\n🌙 {hostname} idle · {time}\nNo activity recorded during the configured period. Automatic shutdown performed.\n---\n💤 Idle shutdown · {hostname} ({ip})\nShut down due to inactivity. Power it on manually when needed.',
   'Notification template idle shutdown'),
  ('tpl_error',
   '❌ Error on {hostname} · {time}\n{body}\n---\n⚠️ WakeLab · issue detected\nHost: {hostname} ({ip}) · {time}\n{body}\n---\n🔧 {hostname} · {time}\n{body} Check the logs for more detail.',
   'Notification template error'),
  ('tpl_guest_unknown',
   '❓ {hostname} unknown state · {time}\n{body}\n---\n⚠️ Guest {hostname} has been in unknown state for a while · {datetime}\nCheck the hypervisor.\n---\n🔍 {hostname} not reporting state · {time}\n{body}',
   'Notification template guest unknown'),
  -- Immutable copies for reset (tpl_default_*)
  ('tpl_default_server_down',
   '🔴 {hostname} not responding\nNo activity on {ip} since {time}. Check connectivity or hardware status.\n---\n⚠️ {hostname} offline · {date} {time}\nHost {ip} stopped responding. Could be a power cut, unexpected reboot or network failure.\n---\n{hostname} is down.\nIP: {ip} · Last seen: {time} · Check the device.',
   'Immutable default server_down'),
  ('tpl_default_server_up',
   '✅ {hostname} back online\n{ip} started responding again at {time}. All good.\n---\n🟢 {hostname} is back · {time}\nHost {ip} is online. Everything is operational.\n---\n{hostname} responding ✅\nConnectivity restored on {ip} · {datetime}',
   'Immutable default server_up'),
  ('tpl_default_schedule',
   '📅 Schedule executed · {hostname}\nAction completed at {time}. IP: {ip}\n---\n⏱ {hostname} · {time}\n{body}\n---\n🕐 Schedule · {date}\n{hostname} ({ip}) ran the scheduled action at {time}.',
   'Immutable default schedule'),
  ('tpl_default_idle',
   '💤 {hostname} shut down due to inactivity\nExtended inactivity detected. Shutdown executed at {time}.\n---\n🌙 {hostname} idle · {time}\nNo activity recorded during the configured period. Automatic shutdown performed.\n---\n💤 Idle shutdown · {hostname} ({ip})\nShut down due to inactivity. Power it on manually when needed.',
   'Immutable default idle'),
  ('tpl_default_error',
   '❌ Error on {hostname} · {time}\n{body}\n---\n⚠️ WakeLab · issue detected\nHost: {hostname} ({ip}) · {time}\n{body}\n---\n🔧 {hostname} · {time}\n{body} Check the logs for more detail.',
   'Immutable default error'),
  ('tpl_default_guest_unknown',
   '❓ {hostname} unknown state · {time}\n{body}\n---\n⚠️ Guest {hostname} has been in unknown state for a while · {datetime}\nCheck the hypervisor.\n---\n🔍 {hostname} not reporting state · {time}\n{body}',
   'Default inmutable guest_unknown')
ON DUPLICATE KEY UPDATE `key`=`key`;

-- ────────────────────────────────────────────────────────────
-- 7. SCHEDULES DE GUESTS (VMs / LXCs via Proxmox API)
--    Separado de schedules para no mezclar con servidores físicos.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS guest_schedules (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    server_id     INT NOT NULL,
    vmid          INT NOT NULL,
    vmtype        ENUM('qemu','lxc') NOT NULL DEFAULT 'qemu',
    boot_time        TIME    DEFAULT NULL,
    shutdown_time    TIME    DEFAULT NULL,
    boot_active      BOOLEAN DEFAULT 1,
    shutdown_active  BOOLEAN DEFAULT 0,
    UNIQUE KEY uniq_guest_sch (server_id, vmid),
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- 8. IDLE CONFIG DE GUESTS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS guest_idle (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    server_id      INT NOT NULL,
    vmid           INT NOT NULL,
    vmtype         ENUM('qemu','lxc') NOT NULL DEFAULT 'qemu',
    idle_limit_sec INT     DEFAULT 1800,
    active         BOOLEAN DEFAULT 1,
    UNIQUE KEY uniq_guest_idle (server_id, vmid),
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- 9. METADATA DE GUESTS (URL, notas)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS guest_meta (
    server_id  INT NOT NULL,
    vmid       INT NOT NULL,
    url        VARCHAR(512) DEFAULT NULL,
    notes      TEXT         DEFAULT NULL,
    PRIMARY KEY (server_id, vmid),
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- 10. USUARIOS
--     Un solo usuario admin por instalación (registro bloqueado después del primero).
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS usuarios (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario       VARCHAR(40)  NOT NULL UNIQUE,
    email         VARCHAR(255) NOT NULL UNIQUE,
    contrasena    VARCHAR(255) NOT NULL,
    role          VARCHAR(20)  NOT NULL DEFAULT 'admin',
    session_token VARCHAR(64)  DEFAULT NULL,
    reset_token   VARCHAR(64)  DEFAULT NULL,
    reset_expires DATETIME     DEFAULT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ────────────────────────────────────────────────────────────
-- 11. WAKE PROXY
--     Mapea subdominios wildcard a servicios internos.
--     Capa 1: solo host | Capa 2: host + guest | Capa 3: host + guest + docker
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS wake_proxies (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(100) NOT NULL,
    domain           VARCHAR(255) NOT NULL UNIQUE,
    server_id        INT          NOT NULL,
    guest_vmid       INT          DEFAULT NULL,
    guest_vmtype     ENUM('qemu','lxc') DEFAULT NULL,
    docker_container VARCHAR(100) DEFAULT NULL,
    dest_ip          VARCHAR(45)  NOT NULL,
    dest_port        INT          NOT NULL,
    dest_protocol    ENUM('http','https') DEFAULT 'http',
    boot_timeout_sec INT          DEFAULT 240,
    active           TINYINT(1)   DEFAULT 1,
    last_proxy_hit   DATETIME     DEFAULT NULL,
    created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    INDEX idx_wp_server (server_id)
);

-- ────────────────────────────────────────────────────────────
-- 12. SESIONES DE HOST (para estadísticas de uso #48)
--     Cada fila = un período online de un servidor.
--     ended_at NULL = actualmente online.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS host_sessions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    server_id  INT      NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at   DATETIME DEFAULT NULL,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
);

CREATE INDEX idx_hs_server  ON host_sessions(server_id);
CREATE INDEX idx_hs_started ON host_sessions(started_at);




-- ────────────────────────────────────────────────────────────
-- Performance indexes
-- ────────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_servers_type    ON servers(hypervisor_type);
CREATE INDEX IF NOT EXISTS idx_servers_ups     ON servers(ups_managed);
CREATE INDEX IF NOT EXISTS idx_events_level    ON events(level, timestamp);
CREATE INDEX IF NOT EXISTS idx_ups_evt_created ON ups_events(created_at);
CREATE INDEX IF NOT EXISTS idx_usuarios_session ON usuarios(session_token(64));
CREATE INDEX IF NOT EXISTS idx_usuarios_reset   ON usuarios(reset_token(64));
