<?php
/**
 * Migración UPS — ejecutar una sola vez en instancias existentes
 * Uso: php migrate-ups.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function hasColumn(PDO $pdo, string $table, string $col): bool {
    $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    return in_array($col, $rows);
}

function addColumn(PDO $pdo, string $table, string $col, string $def): void {
    if (hasColumn($pdo, $table, $col)) {
        echo "SKIP: $table.$col ya existe\n";
        return;
    }
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
    echo "OK: ALTER TABLE $table ADD COLUMN $col\n";
}

try {
    addColumn($pdo, 'servers',   'ups_managed',      'TINYINT(1) NOT NULL DEFAULT 0');
    addColumn($pdo, 'servers',   'ups_priority',     'INT NOT NULL DEFAULT 10');
    addColumn($pdo, 'servers',   'ups_last_state',   'VARCHAR(10) DEFAULT NULL');
    addColumn($pdo, 'servers',   'ups_ignore_delay', 'TINYINT(1) NOT NULL DEFAULT 0');
    addColumn($pdo, 'servers',   'ups_last_resort',  'TINYINT(1) NOT NULL DEFAULT 0');
    addColumn($pdo, 'schedules',       'shutdown_active',  'TINYINT(1) NOT NULL DEFAULT 0');
    addColumn($pdo, 'guest_schedules', 'shutdown_active', 'TINYINT(1) NOT NULL DEFAULT 0');
    // Renombrar columna 'active' → 'boot_active' si aún existe con el nombre viejo
    $gsCols = $pdo->query("SHOW COLUMNS FROM `guest_schedules`")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('active', $gsCols) && !in_array('boot_active', $gsCols)) {
        $pdo->exec("ALTER TABLE `guest_schedules` CHANGE `active` `boot_active` TINYINT(1) NOT NULL DEFAULT 1");
        echo "OK: guest_schedules.active renombrado a boot_active\n";
    } elseif (in_array('boot_active', $gsCols)) {
        echo "SKIP: guest_schedules.boot_active ya existe\n";
    }
    // Migrar: schedules existentes con boot_active=1 y shutdown_time ya tenían shutdown activo
    if (in_array('shutdown_active', $pdo->query("SHOW COLUMNS FROM `guest_schedules`")->fetchAll(PDO::FETCH_COLUMN))) {
        $affected2 = $pdo->exec("UPDATE guest_schedules SET shutdown_active=1 WHERE boot_active=1 AND shutdown_time IS NOT NULL AND shutdown_time != '00:00:00' AND shutdown_active=0");
        echo "OK: guest_schedules shutdown_active activado en $affected2 filas existentes\n";
    }

    // Migrar: si ya tenía shutdown_time guardado, activar shutdown_active
    if (hasColumn($pdo, 'schedules', 'shutdown_active')) {
        $affected = $pdo->exec("UPDATE schedules SET shutdown_active=1 WHERE shutdown_time IS NOT NULL AND shutdown_time != '00:00:00' AND shutdown_active=0");
        echo "OK: shutdown_active activado en $affected filas existentes\n";
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS ups_events (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        event          VARCHAR(50)  NOT NULL,
        ups_name       VARCHAR(100) DEFAULT NULL,
        hosts_affected JSON         DEFAULT NULL,
        status         VARCHAR(50)  NOT NULL DEFAULT 'processed',
        created_at     DATETIME     NOT NULL
    )");
    echo "OK: ups_events\n";

    // Fix charset: convert settings table to utf8mb4 (fixes garbled emojis in templates)
    $pdo->exec("ALTER TABLE `settings` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "OK: settings table converted to utf8mb4\n";

    // Re-insert templates with correct encoding (force update existing rows)
    $templates = [
        'tpl_server_down' => "🔴 {hostname} not responding\nNo activity on {ip} since {time}. Check connectivity or hardware status.\n---\n⚠️ {hostname} offline · {date} {time}\nHost {ip} stopped responding. Could be a power cut, unexpected reboot or network failure.\n---\n{hostname} is down.\nIP: {ip} · Last seen: {time} · Check the device.",
        'tpl_server_up'   => "✅ {hostname} back online\n{ip} started responding again at {time}. All good.\n---\n🟢 {hostname} is back · {time}\nHost {ip} is online. Everything is operational.\n---\n{hostname} responding ✅\nConnectivity restored on {ip} · {datetime}",
        'tpl_schedule'    => "📅 Schedule executed · {hostname}\nAction completed at {time}. IP: {ip}\n---\n⏱ {hostname} · {time}\n{body}\n---\n🕐 Schedule · {date}\n{hostname} ({ip}) ran the scheduled action at {time}.",
        'tpl_idle'        => "💤 {hostname} shut down due to inactivity\nExtended inactivity detected. Shutdown executed at {time}.\n---\n🌙 {hostname} idle · {time}\nNo activity recorded during the configured period. Automatic shutdown performed.\n---\n💤 Idle shutdown · {hostname} ({ip})\nShut down due to inactivity. Power it on manually when needed.",
        'tpl_error'       => "❌ Error on {hostname} · {time}\n{body}\n---\n⚠️ WakeLab · issue detected\nHost: {hostname} ({ip}) · {time}\n{body}\n---\n🔧 {hostname} · {time}\n{body} Check the logs for more detail.",
        'tpl_guest_unknown' => "❓ {hostname} unknown state · {time}\n{body}\n---\n⚠️ Guest {hostname} has been in unknown state for a while · {datetime}\nCheck the hypervisor.\n---\n🔍 {hostname} not reporting state · {time}\n{body}",
    ];
    $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`, `description`) VALUES (?, ?, '') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    foreach ($templates as $k => $v) {
        $stmt->execute([$k, $v]);
        // also update the default copy
        $defKey = str_replace('tpl_', 'tpl_default_', $k);
        $stmt->execute([$defKey, $v]);
    }
    echo "OK: notification templates re-inserted with utf8mb4\n";

} catch (PDOException $e) {
    echo "ERR: " . $e->getMessage() . "\n";
}

echo "Migración completada.\n";
