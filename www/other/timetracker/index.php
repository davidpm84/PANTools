<?php
// ═══════════════════════════════════════════════════════════════════════════
//  TimeTracker — Control de horario (40h semanales)
//  Fichero único · SQLite · Acceso directo (sin login)
// ═══════════════════════════════════════════════════════════════════════════

declare(strict_types=1);
date_default_timezone_set('Europe/Madrid');

// ─── Paths ────────────────────────────────────────────────────────────────
$dataDir   = __DIR__ . '/data';
$dbFile    = $dataDir . '/tracker.sqlite';

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}
$htaccess = $dataDir . '/.htaccess';
if (!file_exists($htaccess)) {
    @file_put_contents($htaccess, "Require all denied\n");
}

// ─── DB init ──────────────────────────────────────────────────────────────
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE IF NOT EXISTS sessions (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    start   TEXT NOT NULL,
    end     TEXT,
    notes   TEXT DEFAULT ''
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_sessions_start ON sessions(start)");
$db->exec("CREATE TABLE IF NOT EXISTS vacations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    start_date TEXT NOT NULL,
    end_date   TEXT NOT NULL,
    notes      TEXT DEFAULT ''
)");
$db->exec("CREATE TABLE IF NOT EXISTS month_adjustments (
    period TEXT PRIMARY KEY,
    hours  REAL NOT NULL DEFAULT 0,
    notes  TEXT DEFAULT ''
)");
$db->exec("CREATE TABLE IF NOT EXISTS holidays (
    date  TEXT PRIMARY KEY,
    name  TEXT NOT NULL,
    scope TEXT NOT NULL DEFAULT 'custom'
)");
$db->exec("CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
)");

// ─── Config (settings almacenadas en SQLite) ─────────────────────────────
function loadConfig(PDO $db): array {
    $defaults = [
        'user_name'           => '',
        'weekly_hours'        => ['1'=>8.5,'2'=>8.5,'3'=>8.5,'4'=>8.5,'5'=>6.5,'6'=>0,'7'=>0],
        'intensive_periods'   => [
            ['name' => 'Jornada intensiva de verano', 'from' => '08-01', 'to' => '08-31', 'hours_per_day' => 7],
        ],
        'balance_start_date'  => null,
        'pause_alert_minutes' => 240,
    ];
    $stored = [];
    foreach ($db->query("SELECT key, value FROM settings")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $v = json_decode($r['value'], true);
        $stored[$r['key']] = ($v !== null || $r['value'] === 'null') ? $v : $r['value'];
    }
    return array_merge($defaults, $stored);
}
function saveConfigKey(PDO $db, string $key, $value): void {
    $stmt = $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?)
                          ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $stmt->execute([$key, json_encode($value)]);
}
$config = loadConfig($db);

// ─── Helpers ──────────────────────────────────────────────────────────────
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function jsonOut($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function nowIso(): string { return date('Y-m-d H:i:s'); }

function parseLocalDatetime(string $s): ?string {
    // Accepts "YYYY-MM-DDTHH:MM" or "YYYY-MM-DD HH:MM[:SS]"
    $s = trim($s);
    if ($s === '') return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    $ts = strtotime($s);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

function weekBounds(string $anyDate): array {
    // ISO week: Monday to Sunday
    $ts = strtotime($anyDate);
    $dow = (int)date('N', $ts); // 1=Mon..7=Sun
    $monday = strtotime('-' . ($dow - 1) . ' days', $ts);
    $sunday = strtotime('+6 days', $monday);
    return [date('Y-m-d 00:00:00', $monday), date('Y-m-d 23:59:59', $sunday)];
}

function monthBounds(string $anyDate): array {
    $ts = strtotime($anyDate);
    return [date('Y-m-01 00:00:00', $ts), date('Y-m-t 23:59:59', $ts)];
}

function secondsBetween(string $start, ?string $end): int {
    if (!$end) $end = nowIso();
    return max(0, strtotime($end) - strtotime($start));
}

function fmtDuration(int $sec): string {
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    return sprintf('%dh %02dm', $h, $m);
}

function fmtDurationSigned(int $sec): string {
    $sign = $sec < 0 ? '-' : '+';
    $sec = abs($sec);
    return $sign . fmtDuration($sec);
}

function isWeekday(string $ymd): bool {
    $dow = (int)date('N', strtotime($ymd));
    return $dow >= 1 && $dow <= 5;
}

function workingDaysInRange(string $fromYmd, string $toYmd): int {
    $count = 0;
    $cur = strtotime($fromYmd);
    $end = strtotime($toYmd);
    while ($cur <= $end) {
        if (isWeekday(date('Y-m-d', $cur))) $count++;
        $cur += 86400;
    }
    return $count;
}

// Returns [ymd => true] map of vacation days within [fromYmd, toYmd] that are weekdays
function vacationDayMap(PDO $db, string $fromYmd, string $toYmd): array {
    $stmt = $db->prepare("SELECT start_date, end_date FROM vacations WHERE start_date <= ? AND end_date >= ?");
    $stmt->execute([$toYmd, $fromYmd]);
    $days = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $vs = max(strtotime($v['start_date']), strtotime($fromYmd));
        $ve = min(strtotime($v['end_date']),   strtotime($toYmd));
        for ($t = $vs; $t <= $ve; $t += 86400) {
            $d = date('Y-m-d', $t);
            if (isWeekday($d)) $days[$d] = true;
        }
    }
    return $days;
}

function monthKey(string $ymd): string { return substr($ymd, 0, 7); }

function getMonthAdjustmentHours(PDO $db, string $period): float {
    $stmt = $db->prepare("SELECT hours FROM month_adjustments WHERE period = ?");
    $stmt->execute([$period]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ? (float)$r['hours'] : 0.0;
}

// Returns [ymd => true] map of holiday days within [fromYmd, toYmd] that are weekdays
function holidayDayMap(PDO $db, string $fromYmd, string $toYmd): array {
    $stmt = $db->prepare("SELECT date FROM holidays WHERE date >= ? AND date <= ?");
    $stmt->execute([$fromYmd, $toYmd]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isWeekday($r['date'])) $out[$r['date']] = true;
    }
    return $out;
}

// ─── Objetivos por día (según config de horario + periodos intensivos) ───
function intensivePeriodFor(string $ymd, array $config): ?array {
    $mmdd = substr($ymd, 5); // MM-DD
    foreach (($config['intensive_periods'] ?? []) as $p) {
        $from = $p['from'] ?? ''; $to = $p['to'] ?? '';
        if (!preg_match('/^\d{2}-\d{2}$/', $from) || !preg_match('/^\d{2}-\d{2}$/', $to)) continue;
        if ($from <= $to) {
            if ($mmdd >= $from && $mmdd <= $to) return $p;
        } else {
            // Cruza fin de año (p.ej. 12-20 → 01-05)
            if ($mmdd >= $from || $mmdd <= $to) return $p;
        }
    }
    return null;
}

function dailyTargetSecForDate(string $ymd, array $config): int {
    $dow = (int)date('N', strtotime($ymd)); // 1=Lun..7=Dom
    $intense = intensivePeriodFor($ymd, $config);
    if ($intense) {
        // Los intensivos aplican solo a días laborables (Lun-Vie)
        if ($dow >= 1 && $dow <= 5) {
            return (int) round(((float)$intense['hours_per_day']) * 3600);
        }
        return 0;
    }
    $wh = (float)($config['weekly_hours'][(string)$dow] ?? 0);
    return (int) round($wh * 3600);
}

function weeklyTargetSecFor(string $anyDate, array $config): int {
    [$ws, $we] = weekBounds($anyDate);
    $total = 0;
    for ($t = strtotime(substr($ws, 0, 10)); $t <= strtotime(substr($we, 0, 10)); $t += 86400) {
        $total += dailyTargetSecForDate(date('Y-m-d', $t), $config);
    }
    return $total;
}

function monthlyTargetSecFor(string $anyDate, array $config): int {
    [$ms, $me] = monthBounds($anyDate);
    $total = 0;
    for ($t = strtotime(substr($ms, 0, 10)); $t <= strtotime(substr($me, 0, 10)); $t += 86400) {
        $total += dailyTargetSecForDate(date('Y-m-d', $t), $config);
    }
    return $total;
}

function workingDaysInRangeCfg(string $fromYmd, string $toYmd, array $config): int {
    $count = 0;
    for ($t = strtotime($fromYmd); $t <= strtotime($toYmd); $t += 86400) {
        if (dailyTargetSecForDate(date('Y-m-d', $t), $config) > 0) $count++;
    }
    return $count;
}

// ─── Banco de horas ───────────────────────────────────────────────────────
function computeBalance(PDO $db, string $startYmd, string $endYmd, array $config): array {
    if (strtotime($startYmd) > strtotime($endYmd)) {
        return ['balance_sec' => 0, 'days' => 0, 'worked_sec' => 0, 'target_sec' => 0, 'credit_sec' => 0, 'adjustment_sec' => 0];
    }
    // Ajustes mensuales dentro del rango solicitado (por YYYY-MM)
    $stmt = $db->prepare("SELECT period, hours FROM month_adjustments WHERE period >= ? AND period <= ?");
    $stmt->execute([substr($startYmd, 0, 7), substr($endYmd, 0, 7)]);
    $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $adjSec = 0;
    // Expandimos el rango efectivo para cubrir los meses completos de los ajustes:
    // así el objetivo de los días fuera del rango original (pero dentro del mes del ajuste) se compensa
    $effStart = $startYmd; $effEnd = $endYmd;
    $today    = date('Y-m-d');
    foreach ($adjustments as $r) {
        $adjSec += (int) round(((float)$r['hours']) * 3600);
        $monthFirst = $r['period'] . '-01';
        $monthLast  = date('Y-m-t', strtotime($monthFirst));
        if ($monthFirst < $effStart) $effStart = $monthFirst;
        if ($monthLast  > $effEnd && $monthLast <= $today) $effEnd = $monthLast;
    }

    $vacMap = vacationDayMap($db, $effStart, $effEnd);
    $holMap = holidayDayMap($db, $effStart, $effEnd);
    $endTs   = strtotime($effEnd . ' 23:59:59');
    $startTs = strtotime($effStart . ' 00:00:00');
    // Sessions overlapping effective range
    $stmt = $db->prepare("SELECT start, end FROM sessions WHERE start <= ? AND (end IS NULL OR end >= ?)");
    $stmt->execute([date('Y-m-d H:i:s', $endTs), date('Y-m-d H:i:s', $startTs)]);
    $workedByDay = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $sTs = max(strtotime($s['start']), $startTs);
        $eTs = min(strtotime($s['end'] ?? nowIso()), $endTs, strtotime(nowIso()));
        $t = $sTs;
        while ($t < $eTs) {
            $d = date('Y-m-d', $t);
            $dayEnd = strtotime($d . ' 23:59:59') + 1;
            $chunkEnd = min($eTs, $dayEnd);
            $workedByDay[$d] = ($workedByDay[$d] ?? 0) + ($chunkEnd - $t);
            $t = $chunkEnd;
        }
    }
    $balance = 0; $days = 0; $workedTot = 0; $targetTot = 0; $creditTot = 0;
    for ($t = $startTs; $t <= $endTs; $t += 86400) {
        $d = date('Y-m-d', $t);
        $target = dailyTargetSecForDate($d, $config);
        $worked = $workedByDay[$d] ?? 0;
        $credit = (isset($vacMap[$d]) || isset($holMap[$d])) ? $target : 0;
        $balance += ($worked + $credit) - $target;
        $workedTot += $worked; $targetTot += $target; $creditTot += $credit;
        $days++;
    }
    $balance += $adjSec;
    return [
        'balance_sec'    => $balance,
        'days'           => $days,
        'worked_sec'     => $workedTot,
        'target_sec'     => $targetTot,
        'credit_sec'     => $creditTot,
        'adjustment_sec' => $adjSec,
        'effective_from' => $effStart,
        'effective_to'   => $effEnd,
    ];
}

// Calendario oficial España + Madrid (Comunidad + Capital)
// Fuente: BOE / Comunidad de Madrid. El usuario puede editar/borrar/añadir después.
function officialHolidays(int $year): array {
    $cal = [
        2025 => [
            ['2025-01-01', 'Año Nuevo',                    'nacional'],
            ['2025-01-06', 'Reyes',                        'nacional'],
            ['2025-04-17', 'Jueves Santo',                 'madrid'],
            ['2025-04-18', 'Viernes Santo',                'nacional'],
            ['2025-05-01', 'Día del Trabajador',           'nacional'],
            ['2025-05-02', 'Comunidad de Madrid',          'madrid'],
            ['2025-05-15', 'San Isidro',                   'madrid-capital'],
            ['2025-07-25', 'Santiago Apóstol',             'madrid'],
            ['2025-08-15', 'Asunción de la Virgen',        'nacional'],
            ['2025-11-01', 'Todos los Santos',             'nacional'],
            ['2025-11-10', 'Almudena (trasladado)',        'madrid-capital'],
            ['2025-12-06', 'Constitución Española',        'nacional'],
            ['2025-12-08', 'Inmaculada Concepción',        'nacional'],
            ['2025-12-25', 'Navidad',                      'nacional'],
        ],
        2026 => [
            ['2026-01-01', 'Año Nuevo',                    'nacional'],
            ['2026-01-06', 'Reyes',                        'nacional'],
            ['2026-04-02', 'Jueves Santo',                 'madrid'],
            ['2026-04-03', 'Viernes Santo',                'nacional'],
            ['2026-05-01', 'Día del Trabajador',           'nacional'],
            ['2026-05-04', 'Comunidad de Madrid (traslado)','madrid'],
            ['2026-05-15', 'San Isidro',                   'madrid-capital'],
            ['2026-10-12', 'Fiesta Nacional',              'nacional'],
            ['2026-11-02', 'Todos los Santos (traslado)',  'nacional'],
            ['2026-11-09', 'Almudena',                     'madrid-capital'],
            ['2026-12-07', 'Constitución (traslado)',      'nacional'],
            ['2026-12-08', 'Inmaculada Concepción',        'nacional'],
            ['2026-12-25', 'Navidad',                      'nacional'],
        ],
        2027 => [
            ['2027-01-01', 'Año Nuevo',                    'nacional'],
            ['2027-01-06', 'Reyes',                        'nacional'],
            ['2027-03-25', 'Jueves Santo',                 'madrid'],
            ['2027-03-26', 'Viernes Santo',                'nacional'],
            ['2027-05-03', 'Día del Trabajador (traslado)','nacional'],
            ['2027-05-17', 'San Isidro (traslado)',        'madrid-capital'],
            ['2027-10-12', 'Fiesta Nacional',              'nacional'],
            ['2027-11-01', 'Todos los Santos',             'nacional'],
            ['2027-11-09', 'Almudena',                     'madrid-capital'],
            ['2027-12-06', 'Constitución Española',        'nacional'],
            ['2027-12-08', 'Inmaculada Concepción',        'nacional'],
            ['2027-12-25', 'Navidad',                      'nacional'],
        ],
    ];
    return $cal[$year] ?? [];
}

// ─── Action dispatcher ────────────────────────────────────────────────────
$action = $_REQUEST['action'] ?? '';

// ─── API actions ──────────────────────────────────────────────────────────

// Get the currently open session (end IS NULL) if any
$openStmt = $db->prepare("SELECT * FROM sessions WHERE end IS NULL ORDER BY start DESC LIMIT 1");

if ($action === 'start' || $action === 'resume') {
    $openStmt->execute();
    if ($openStmt->fetch(PDO::FETCH_ASSOC)) {
        jsonOut(['ok' => false, 'error' => 'Ya hay una sesión abierta. Pausa o para primero.'], 400);
    }
    $stmt = $db->prepare("INSERT INTO sessions (start, end, notes) VALUES (?, NULL, '')");
    $stmt->execute([nowIso()]);
    jsonOut(['ok' => true]);
}

if ($action === 'pause' || $action === 'stop') {
    $openStmt->execute();
    $row = $openStmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonOut(['ok' => false, 'error' => 'No hay ninguna sesión abierta.'], 400);
    $stmt = $db->prepare("UPDATE sessions SET end = ? WHERE id = ?");
    $stmt->execute([nowIso(), $row['id']]);
    jsonOut(['ok' => true]);
}

// Diagnóstico rápido de estado
if ($action === 'diag') {
    $sessions = $db->query("SELECT id, start, end FROM sessions ORDER BY start DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $openN    = (int)$db->query("SELECT COUNT(*) FROM sessions WHERE end IS NULL")->fetchColumn();
    $totalN   = (int)$db->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Diag</title></head>";
    echo "<body style='font-family:monospace;max-width:800px;margin:30px auto;padding:20px'>";
    echo "<h2>Diagnóstico TimeTracker</h2>";
    echo "<p><b>PHP version:</b> " . PHP_VERSION . "</p>";
    echo "<p><b>DB path:</b> " . h($dbFile) . "</p>";
    echo "<p><b>DB exists:</b> " . (file_exists($dbFile) ? 'sí (' . filesize($dbFile) . ' bytes, mtime ' . date('Y-m-d H:i:s', filemtime($dbFile)) . ')' : '<b style=color:red>NO</b>') . "</p>";
    echo "<p><b>DB writable:</b> " . (is_writable($dbFile) ? 'sí' : '<b style=color:red>NO</b>') . "</p>";
    echo "<p><b>Data dir writable:</b> " . (is_writable($dataDir) ? 'sí' : '<b style=color:red>NO</b>') . "</p>";
    echo "<p><b>Total sesiones:</b> $totalN · <b>Abiertas (end IS NULL):</b> $openN</p>";
    echo "<p><b>Timezone:</b> " . date_default_timezone_get() . " · Now: " . nowIso() . "</p>";
    echo "<p><b>OPcache:</b> " . (function_exists('opcache_get_status') && opcache_get_status(false) ? 'activo' : 'inactivo') . "</p>";
    if ($sessions) {
        echo "<h3>Últimas 10 sesiones</h3><table border=1 cellpadding=6><tr><th>ID</th><th>Start</th><th>End</th></tr>";
        foreach ($sessions as $s) echo "<tr><td>{$s['id']}</td><td>" . h($s['start']) . "</td><td>" . h($s['end'] ?: '<b style=color:red>NULL (abierta)</b>') . "</td></tr>";
        echo "</table>";
    } else {
        echo "<p><i>No hay sesiones en la BD.</i></p>";
    }
    echo "<p><a href='?action=force_close&mode=discard'>Descartar sesiones abiertas</a> · <a href='?'>← Volver</a></p>";
    echo "</body></html>";
    exit;
}

// Recuperación: cierra o descarta cualquier sesión huérfana con end IS NULL
if ($action === 'force_close') {
    $mode = (string)($_REQUEST['mode'] ?? 'close'); // close | discard
    if ($mode === 'discard') {
        $n = $db->exec("DELETE FROM sessions WHERE end IS NULL");
        $msg = "Sesiones huérfanas eliminadas: $n";
    } else {
        // Cierra poniendo end = start (duración 0, editable después)
        $n = $db->exec("UPDATE sessions SET end = start WHERE end IS NULL");
        $msg = "Sesiones cerradas (duración 0): $n. Puedes editarlas después.";
    }
    // Si es un GET simple desde el navegador, muestra HTML amigable
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_REQUEST['json'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Recuperación</title></head><body style='font-family:sans-serif;max-width:600px;margin:40px auto;padding:20px'>";
        echo "<h2>✔ Listo</h2><p>" . h($msg) . "</p><p><a href='?'>← Volver al TimeTracker</a></p></body></html>";
        exit;
    }
    jsonOut(['ok' => true, 'affected' => $n, 'message' => $msg]);
}

if ($action === 'create') {
    $start = parseLocalDatetime((string)($_POST['start'] ?? ''));
    $end   = parseLocalDatetime((string)($_POST['end'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if (!$start) jsonOut(['ok' => false, 'error' => 'Inicio obligatorio.'], 400);
    if ($end && strtotime($end) <= strtotime($start)) jsonOut(['ok' => false, 'error' => 'El fin debe ser posterior al inicio.'], 400);
    $stmt = $db->prepare("INSERT INTO sessions (start, end, notes) VALUES (?, ?, ?)");
    $stmt->execute([$start, $end, $notes]);
    jsonOut(['ok' => true, 'id' => (int)$db->lastInsertId()]);
}

if ($action === 'update') {
    $id    = (int)($_POST['id'] ?? 0);
    $start = parseLocalDatetime((string)($_POST['start'] ?? ''));
    $end   = parseLocalDatetime((string)($_POST['end'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($id <= 0 || !$start) jsonOut(['ok' => false, 'error' => 'Datos inválidos.'], 400);
    if ($end && strtotime($end) <= strtotime($start)) jsonOut(['ok' => false, 'error' => 'El fin debe ser posterior al inicio.'], 400);
    $stmt = $db->prepare("UPDATE sessions SET start = ?, end = ?, notes = ? WHERE id = ?");
    $stmt->execute([$start, $end, $notes, $id]);
    jsonOut(['ok' => true]);
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) jsonOut(['ok' => false, 'error' => 'Id inválido.'], 400);
    $stmt = $db->prepare("DELETE FROM sessions WHERE id = ?");
    $stmt->execute([$id]);
    jsonOut(['ok' => true]);
}

// ─── Vacaciones ───────────────────────────────────────────────────────────
if ($action === 'vacation_list') {
    $stmt = $db->query("SELECT * FROM vacations ORDER BY start_date DESC");
    jsonOut(['ok' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'vacation_create') {
    $from  = trim((string)($_POST['start_date'] ?? ''));
    $to    = trim((string)($_POST['end_date']   ?? ''));
    $notes = trim((string)($_POST['notes']      ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        jsonOut(['ok' => false, 'error' => 'Fechas inválidas (YYYY-MM-DD).'], 400);
    }
    if (strtotime($to) < strtotime($from)) jsonOut(['ok' => false, 'error' => 'La fecha fin es anterior al inicio.'], 400);
    $stmt = $db->prepare("INSERT INTO vacations (start_date, end_date, notes) VALUES (?, ?, ?)");
    $stmt->execute([$from, $to, $notes]);
    jsonOut(['ok' => true, 'id' => (int)$db->lastInsertId()]);
}

if ($action === 'vacation_delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) jsonOut(['ok' => false, 'error' => 'Id inválido.'], 400);
    $stmt = $db->prepare("DELETE FROM vacations WHERE id = ?");
    $stmt->execute([$id]);
    jsonOut(['ok' => true]);
}

// ─── Festivos ─────────────────────────────────────────────────────────────
if ($action === 'holiday_list') {
    $stmt = $db->query("SELECT * FROM holidays ORDER BY date");
    jsonOut(['ok' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'holiday_create') {
    $date  = trim((string)($_POST['date'] ?? ''));
    $name  = trim((string)($_POST['name'] ?? ''));
    $scope = trim((string)($_POST['scope'] ?? 'custom'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonOut(['ok' => false, 'error' => 'Fecha inválida.'], 400);
    if ($name === '') jsonOut(['ok' => false, 'error' => 'Nombre obligatorio.'], 400);
    $stmt = $db->prepare("INSERT INTO holidays (date, name, scope) VALUES (?, ?, ?)
                          ON CONFLICT(date) DO UPDATE SET name = excluded.name, scope = excluded.scope");
    $stmt->execute([$date, $name, $scope]);
    jsonOut(['ok' => true]);
}

if ($action === 'holiday_delete') {
    $date = trim((string)($_POST['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonOut(['ok' => false, 'error' => 'Fecha inválida.'], 400);
    $stmt = $db->prepare("DELETE FROM holidays WHERE date = ?");
    $stmt->execute([$date]);
    jsonOut(['ok' => true]);
}

if ($action === 'holiday_seed') {
    $year = (int)($_POST['year'] ?? 0);
    if ($year < 2020 || $year > 2035) jsonOut(['ok' => false, 'error' => 'Año fuera de rango.'], 400);
    $list = officialHolidays($year);
    if (!$list) jsonOut(['ok' => false, 'error' => "No hay calendario oficial cargado para $year."], 400);
    $stmt = $db->prepare("INSERT OR IGNORE INTO holidays (date, name, scope) VALUES (?, ?, ?)");
    $added = 0;
    foreach ($list as $h) {
        $stmt->execute([$h[0], $h[1], $h[2]]);
        $added += $stmt->rowCount();
    }
    jsonOut(['ok' => true, 'added' => $added, 'total' => count($list)]);
}

// ─── Settings (horario, banco de horas, intensivos, alerta pausa) ────────
if ($action === 'settings_get') {
    jsonOut(['ok' => true, 'settings' => [
        'user_name'           => $config['user_name'] ?? '',
        'weekly_hours'        => $config['weekly_hours'],
        'intensive_periods'   => array_values($config['intensive_periods']),
        'balance_start_date'  => $config['balance_start_date'],
        'pause_alert_minutes' => (int)$config['pause_alert_minutes'],
    ]]);
}

if ($action === 'settings_update') {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) jsonOut(['ok' => false, 'error' => 'JSON inválido.'], 400);

    if (array_key_exists('user_name', $payload)) {
        saveConfigKey($db, 'user_name', trim((string)$payload['user_name']));
    }
    if (isset($payload['weekly_hours']) && is_array($payload['weekly_hours'])) {
        $wh = [];
        foreach (['1','2','3','4','5','6','7'] as $k) {
            $wh[$k] = max(0, min(24, (float)($payload['weekly_hours'][$k] ?? 0)));
        }
        saveConfigKey($db, 'weekly_hours', $wh);
    }
    if (array_key_exists('balance_start_date', $payload)) {
        $bsd = trim((string)$payload['balance_start_date']);
        saveConfigKey($db, 'balance_start_date', preg_match('/^\d{4}-\d{2}-\d{2}$/', $bsd) ? $bsd : null);
    }
    if (isset($payload['pause_alert_minutes'])) {
        saveConfigKey($db, 'pause_alert_minutes', max(0, min(720, (int)$payload['pause_alert_minutes'])));
    }
    if (isset($payload['intensive_periods']) && is_array($payload['intensive_periods'])) {
        $ip = [];
        foreach ($payload['intensive_periods'] as $p) {
            $name  = trim((string)($p['name'] ?? ''));
            $from  = trim((string)($p['from'] ?? ''));
            $to    = trim((string)($p['to']   ?? ''));
            $hours = (float)($p['hours_per_day'] ?? 0);
            if (!preg_match('/^\d{2}-\d{2}$/', $from) || !preg_match('/^\d{2}-\d{2}$/', $to)) continue;
            if ($name === '' || $hours <= 0 || $hours > 24) continue;
            $ip[] = ['name' => $name, 'from' => $from, 'to' => $to, 'hours_per_day' => $hours];
        }
        saveConfigKey($db, 'intensive_periods', $ip);
    }
    jsonOut(['ok' => true]);
}

// ─── Backup SQLite ────────────────────────────────────────────────────────
if ($action === 'backup') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="timetracker_backup_' . date('Y-m-d_His') . '.sqlite"');
    header('Content-Length: ' . filesize($dbFile));
    readfile($dbFile);
    exit;
}

// ─── Restore SQLite (import de un backup) ────────────────────────────────
if ($action === 'restore') {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonOut(['ok' => false, 'error' => 'No se recibió ningún fichero válido.'], 400);
    }
    $tmp = $_FILES['file']['tmp_name'];
    // Validación básica: cabecera SQLite ("SQLite format 3\0" = 16 bytes)
    $fh = fopen($tmp, 'rb');
    $magic = $fh ? fread($fh, 16) : '';
    if ($fh) fclose($fh);
    if ($magic !== "SQLite format 3\0") {
        jsonOut(['ok' => false, 'error' => 'El fichero no es una BD SQLite válida.'], 400);
    }
    // Comprueba que tiene las tablas esperadas abriéndolo temporalmente
    try {
        $test = new PDO('sqlite:' . $tmp);
        $test->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = $test->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $test = null;
        if (!in_array('sessions', $tables, true)) {
            jsonOut(['ok' => false, 'error' => 'La BD no parece ser de TimeTracker (falta la tabla sessions).'], 400);
        }
    } catch (Throwable $e) {
        jsonOut(['ok' => false, 'error' => 'No se pudo abrir la BD: ' . $e->getMessage()], 400);
    }
    // Backup de seguridad de la BD actual antes de sobrescribir
    $db = null; // libera el handle PDO actual
    $safety = $dataDir . '/tracker_before_restore_' . date('Ymd_His') . '.sqlite';
    if (file_exists($dbFile)) @copy($dbFile, $safety);
    if (!@move_uploaded_file($tmp, $dbFile)) {
        jsonOut(['ok' => false, 'error' => 'No se pudo escribir la nueva BD.'], 500);
    }
    jsonOut(['ok' => true, 'safety_backup' => basename($safety)]);
}

// ─── Informe mensual imprimible ──────────────────────────────────────────
if ($action === 'report') {
    $ref = (string)($_GET['ref'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref)) $ref = date('Y-m-d');
    [$ms, $me] = monthBounds($ref);
    $msD = substr($ms, 0, 10); $meD = substr($me, 0, 10);

    // Precompute
    $vacMap = vacationDayMap($db, $msD, $meD);
    $holMap = holidayDayMap($db, $msD, $meD);
    $holNamesStmt = $db->prepare("SELECT date, name FROM holidays WHERE date BETWEEN ? AND ?");
    $holNamesStmt->execute([$msD, $meD]);
    $holNames = [];
    foreach ($holNamesStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $holNames[$r['date']] = $r['name'];

    $vacStmt = $db->prepare("SELECT * FROM vacations WHERE start_date <= ? AND end_date >= ? ORDER BY start_date");
    $vacStmt->execute([$meD, $msD]);
    $vacRanges = $vacStmt->fetchAll(PDO::FETCH_ASSOC);

    $sessStmt = $db->prepare("SELECT * FROM sessions WHERE start <= ? AND (end IS NULL OR end >= ?) ORDER BY start");
    $sessStmt->execute([$me, $ms]);
    $allSessions = $sessStmt->fetchAll(PDO::FETCH_ASSOC);

    $adjHours = getMonthAdjustmentHours($db, monthKey($ref));
    $targetSec = monthlyTargetSecFor($ref, $config);

    // Days data
    $days = [];
    $totWorked = 0; $totVac = 0; $totHol = 0;
    for ($t = strtotime($msD); $t <= strtotime($meD); $t += 86400) {
        $d = date('Y-m-d', $t);
        $target = dailyTargetSecForDate($d, $config);
        // Worked seconds this day (clamped)
        $workedSec = 0;
        $daySessions = [];
        foreach ($allSessions as $s) {
            $sTs = max(strtotime($s['start']), $t);
            $eTs = min(strtotime($s['end'] ?? nowIso()), $t + 86399);
            if ($eTs > $sTs) {
                $workedSec += ($eTs - $sTs);
                $daySessions[] = $s;
            }
        }
        $isVac = isset($vacMap[$d]);
        $isHol = isset($holMap[$d]);
        $creditSec = ($isVac || $isHol) ? $target : 0;
        $days[$d] = [
            'target' => $target,
            'worked' => $workedSec,
            'credit' => $creditSec,
            'sessions' => $daySessions,
            'is_vac' => $isVac,
            'is_hol' => $isHol,
            'hol_name' => $holNames[$d] ?? null,
        ];
        $totWorked += $workedSec;
        if ($isVac) $totVac += $target;
        else if ($isHol) $totHol += $target;
    }
    $totAdj = (int) round($adjHours * 3600);
    $totAll = $totWorked + $totVac + $totHol + $totAdj;
    $delta  = $totAll - $targetSec;

    $DOW = ['','Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
    $fmtH = function(int $sec): string {
        $sign = $sec < 0 ? '-' : '';
        $sec = abs($sec);
        return $sign . intdiv($sec, 3600) . 'h ' . str_pad((string)intdiv($sec % 3600, 60), 2, '0', STR_PAD_LEFT) . 'm';
    };

    // Render report HTML
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<?php
    $MESES_ES = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $reportTitle = $MESES_ES[(int)date('n', strtotime($ms))] . ' ' . date('Y', strtotime($ms));
?><title>Informe · <?= h($reportTitle) ?></title>
<style>
    body { font-family: -apple-system, Segoe UI, sans-serif; color: #222; max-width: 900px; margin: 20px auto; padding: 20px; }
    h1 { margin: 0 0 5px; font-size: 1.6rem; }
    h2 { border-bottom: 2px solid #00C55E; padding-bottom: 4px; margin-top: 30px; font-size: 1.1rem; }
    .meta { color: #666; font-size: .9rem; margin-bottom: 20px; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; font-size: .9rem; }
    th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; vertical-align: top; }
    th { background: #f4f6f8; font-weight: 600; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .vac { background: #fff8e1; }
    .hol { background: #fde2e2; }
    .weekend { color: #999; }
    .summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 15px 0; }
    .summary .box { border: 1px solid #ddd; border-radius: 6px; padding: 12px; }
    .summary .lbl { font-size: .75rem; color: #666; text-transform: uppercase; letter-spacing: .5px; }
    .summary .val { font-size: 1.4rem; font-weight: 700; margin-top: 2px; }
    .delta-pos { color: #198754; }
    .delta-neg { color: #dc3545; }
    .noprint { margin: 10px 0; }
    @media print { .noprint { display: none; } body { margin: 0; padding: 10mm; } }
</style>
</head><body>
    <div class="noprint">
        <button onclick="window.print()">🖨 Imprimir / Guardar PDF</button>
        <a href="?" style="margin-left:10px">← Volver</a>
    </div>
    <h1>Informe de horario · <?= h($reportTitle) ?></h1>
    <div class="meta">Periodo: <strong><?= h($msD) ?></strong> → <strong><?= h($meD) ?></strong> · Generado <?= h(date('Y-m-d H:i')) ?></div>

    <div class="summary">
        <div class="box"><div class="lbl">Trabajado</div><div class="val"><?= h($fmtH($totWorked)) ?></div></div>
        <div class="box"><div class="lbl">Vacaciones</div><div class="val"><?= h($fmtH($totVac)) ?></div></div>
        <div class="box"><div class="lbl">Festivos</div><div class="val"><?= h($fmtH($totHol)) ?></div></div>
        <div class="box"><div class="lbl">Ajuste manual</div><div class="val"><?= h($fmtH($totAdj)) ?></div></div>
        <div class="box"><div class="lbl">Total mes</div><div class="val"><?= h($fmtH($totAll)) ?></div></div>
        <div class="box"><div class="lbl">Objetivo · Desviación</div>
            <div class="val"><?= h($fmtH($targetSec)) ?>
                <span class="<?= $delta>=0?'delta-pos':'delta-neg' ?>" style="font-size:1rem"> (<?= $delta>=0?'+':'-' ?><?= h($fmtH(abs($delta))) ?>)</span>
            </div>
        </div>
    </div>

    <h2>Detalle diario</h2>
    <table>
        <thead><tr><th>Fecha</th><th>Día</th><th>Estado</th><th class="num">Objetivo</th><th class="num">Trabajado</th><th class="num">Crédito</th><th class="num">Total día</th></tr></thead>
        <tbody>
        <?php foreach ($days as $d => $info):
            $rowClass = $info['is_hol'] ? 'hol' : ($info['is_vac'] ? 'vac' : '');
            $dow = (int)date('N', strtotime($d));
            $isWeekend = $dow >= 6;
            $status = $info['is_hol'] ? '🎉 ' . h($info['hol_name']) : ($info['is_vac'] ? '🏖 Vacaciones' : ($isWeekend ? 'Findem' : ''));
        ?>
            <tr class="<?= $rowClass ?> <?= $isWeekend?'weekend':'' ?>">
                <td><?= h($d) ?></td>
                <td><?= h($DOW[$dow]) ?></td>
                <td><?= $status ?></td>
                <td class="num"><?= h($fmtH($info['target'])) ?></td>
                <td class="num"><?= $info['worked']>0 ? h($fmtH($info['worked'])) : '—' ?></td>
                <td class="num"><?= $info['credit']>0 ? h($fmtH($info['credit'])) : '—' ?></td>
                <td class="num"><strong><?= h($fmtH($info['worked'] + $info['credit'])) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($vacRanges): ?>
    <h2>Vacaciones que solapan con el mes</h2>
    <table>
        <thead><tr><th>Desde</th><th>Hasta</th><th>Notas</th></tr></thead>
        <tbody>
        <?php foreach ($vacRanges as $v): ?>
            <tr><td><?= h($v['start_date']) ?></td><td><?= h($v['end_date']) ?></td><td><?= h($v['notes']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($holNames): ?>
    <h2>Festivos del mes</h2>
    <table>
        <thead><tr><th>Fecha</th><th>Día</th><th>Nombre</th></tr></thead>
        <tbody>
        <?php foreach ($holNames as $d => $n): $dow = (int)date('N', strtotime($d)); ?>
            <tr><td><?= h($d) ?></td><td><?= h($DOW[$dow]) ?></td><td><?= h($n) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($adjHours != 0): ?>
    <h2>Ajuste manual del mes</h2>
    <p><strong><?= h(number_format($adjHours, 2)) ?> h</strong> añadidas manualmente al total.</p>
    <?php endif; ?>

</body></html><?php
    exit;
}

// ─── Ajuste mensual (backfill) ────────────────────────────────────────────
if ($action === 'adjustment_set') {
    $period = trim((string)($_POST['period'] ?? ''));
    $hours  = (float)($_POST['hours'] ?? 0);
    $notes  = trim((string)($_POST['notes'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) jsonOut(['ok' => false, 'error' => 'Periodo inválido (YYYY-MM).'], 400);
    if ($hours == 0) {
        $stmt = $db->prepare("DELETE FROM month_adjustments WHERE period = ?");
        $stmt->execute([$period]);
    } else {
        $stmt = $db->prepare("INSERT INTO month_adjustments (period, hours, notes) VALUES (?, ?, ?)
                              ON CONFLICT(period) DO UPDATE SET hours = excluded.hours, notes = excluded.notes");
        $stmt->execute([$period, $hours, $notes]);
    }
    jsonOut(['ok' => true]);
}

if ($action === 'summary') {
    $today = date('Y-m-d');
    $ref   = (string)($_GET['ref'] ?? $today);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref)) $ref = $today;

    [$wStart, $wEnd] = weekBounds($ref);
    [$mStart, $mEnd] = monthBounds($ref);

    $weeklyTargetSec = weeklyTargetSecFor($ref, $config);

    $sumRange = function(string $from, string $to) use ($db): int {
        $stmt = $db->prepare("SELECT start, end FROM sessions WHERE start < ? AND (end IS NULL OR end > ?) ORDER BY start");
        $stmt->execute([$to, $from]);
        $total = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = max(strtotime($r['start']), strtotime($from));
            $e = min(strtotime($r['end'] ?? nowIso()), strtotime($to));
            if ($e > $s) $total += ($e - $s);
        }
        return $total;
    };

    // Day sessions detail
    $dayFrom = $ref . ' 00:00:00';
    $dayTo   = $ref . ' 23:59:59';
    $stmt = $db->prepare("SELECT * FROM sessions WHERE start < ? AND (end IS NULL OR end > ?) ORDER BY start");
    $stmt->execute([$dayTo, $dayFrom]);
    $daySessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pauses (gaps) for the day between consecutive sessions
    $pauses = [];
    $prevEnd = null;
    foreach ($daySessions as $s) {
        if ($prevEnd !== null && strtotime($s['start']) > strtotime($prevEnd)) {
            $pauses[] = [
                'from' => $prevEnd,
                'to'   => $s['start'],
                'sec'  => strtotime($s['start']) - strtotime($prevEnd),
            ];
        }
        $prevEnd = $s['end'] ?? null;
        if ($prevEnd === null) break;
    }

    // Vacation & holiday credit — cada día suma su objetivo específico (0 si findem o intensivo=0)
    $dayVac   = vacationDayMap($db, $ref, $ref);
    $dayHol   = holidayDayMap($db, $ref, $ref);
    $weekVac  = vacationDayMap($db, substr($wStart, 0, 10), substr($wEnd, 0, 10));
    $weekHol  = holidayDayMap($db, substr($wStart, 0, 10), substr($wEnd, 0, 10));
    $monthVac = vacationDayMap($db, substr($mStart, 0, 10), substr($mEnd, 0, 10));
    $monthHol = holidayDayMap($db, substr($mStart, 0, 10), substr($mEnd, 0, 10));

    $sumCredit = function(array $dayMap) use ($config): int {
        $s = 0; foreach ($dayMap as $d => $_) $s += dailyTargetSecForDate($d, $config); return $s;
    };
    $dayVacSec   = $sumCredit($dayVac);
    $dayHolSec   = $sumCredit(array_diff_key($dayHol,   $dayVac));
    $weekVacSec  = $sumCredit($weekVac);
    $weekHolSec  = $sumCredit(array_diff_key($weekHol,  $weekVac));
    $monthVacSec = $sumCredit($monthVac);
    $monthHolSec = $sumCredit(array_diff_key($monthHol, $monthVac));

    // Monthly adjustment (backfill hours)
    $adjHours = getMonthAdjustmentHours($db, monthKey($ref));
    $adjSec   = (int) round($adjHours * 3600);

    // Monthly target = suma de objetivos día a día (respeta intensivos)
    $monthTargetSec   = monthlyTargetSecFor($ref, $config);
    $monthWorkingDays = workingDaysInRangeCfg(substr($mStart, 0, 10), substr($mEnd, 0, 10), $config);

    // Fetch holiday names for the week (for labels)
    $holNameStmt = $db->prepare("SELECT date, name FROM holidays WHERE date >= ? AND date <= ?");
    $holNameStmt->execute([substr($wStart, 0, 10), substr($wEnd, 0, 10)]);
    $holNames = [];
    foreach ($holNameStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $holNames[$r['date']] = $r['name'];

    // Week breakdown by day (with vacation & holiday flags)
    $weekByDay = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($wStart) + $i * 86400);
        $workedSec = $sumRange($d . ' 00:00:00', $d . ' 23:59:59');
        $isVac = isset($weekVac[$d]);
        $isHol = isset($weekHol[$d]);
        $dayTarget = dailyTargetSecForDate($d, $config);
        $creditSec = ($isVac || $isHol) ? $dayTarget : 0;
        $weekByDay[$d] = [
            'worked'   => $workedSec,
            'credit'   => $creditSec,
            'target'   => $dayTarget,
            'total'    => $workedSec + $creditSec,
            'is_vac'   => $isVac,
            'is_hol'   => $isHol,
            'hol_name' => $holNames[$d] ?? null,
        ];
    }

    // Month breakdown by ISO-week (worked + off-days, clamped to month)
    $monthByWeek = [];
    $cur = strtotime($mStart);
    $end = strtotime($mEnd);
    while ($cur <= $end) {
        [$ws, $we] = weekBounds(date('Y-m-d', $cur));
        $s = max(strtotime($ws), strtotime($mStart));
        $e = min(strtotime($we), strtotime($mEnd));
        $wk = date('o-\WW', $s);
        if (!isset($monthByWeek[$wk])) {
            $vMap = vacationDayMap($db, date('Y-m-d', $s), date('Y-m-d', $e));
            $hMap = holidayDayMap($db, date('Y-m-d', $s), date('Y-m-d', $e));
            $vSec = $sumCredit($vMap);
            $hSec = $sumCredit(array_diff_key($hMap, $vMap));
            $wkTarget = 0;
            for ($tt = $s; $tt <= $e; $tt += 86400) $wkTarget += dailyTargetSecForDate(date('Y-m-d', $tt), $config);
            $monthByWeek[$wk] = [
                'label'    => 'Sem ' . date('W', $s) . ' (' . date('d/m', $s) . '–' . date('d/m', $e) . ')',
                'worked'   => $sumRange(date('Y-m-d H:i:s', $s), date('Y-m-d H:i:s', $e)),
                'vacation' => $vSec,
                'holiday'  => $hSec,
                'target'   => $wkTarget,
            ];
        }
        $cur = $e + 1;
    }
    foreach ($monthByWeek as &$w) $w['total'] = $w['worked'] + $w['vacation'] + $w['holiday'];
    unset($w);

    $openStmt->execute();
    $openRow = $openStmt->fetch(PDO::FETCH_ASSOC);

    $daySec   = $sumRange($dayFrom, $dayTo);
    $weekSec  = $sumRange($wStart, $wEnd);
    $monthSec = $sumRange($mStart, $mEnd);

    // Semana anterior (para retrospectiva en povradar)
    $prevRef = date('Y-m-d', strtotime($ref . ' -7 days'));
    [$pwStart, $pwEnd] = weekBounds($prevRef);
    $pwSec    = $sumRange($pwStart, $pwEnd);
    $pwVacMap = vacationDayMap($db, substr($pwStart, 0, 10), substr($pwEnd, 0, 10));
    $pwHolMap = holidayDayMap($db, substr($pwStart, 0, 10), substr($pwEnd, 0, 10));
    $pwVacSec = $sumCredit($pwVacMap);
    $pwHolSec = $sumCredit(array_diff_key($pwHolMap, $pwVacMap));
    $pwTarget = weeklyTargetSecFor($prevRef, $config);

    $dayHolidayName = null;
    if ($dayHol) {
        $s = $db->prepare("SELECT name FROM holidays WHERE date = ?");
        $s->execute([array_keys($dayHol)[0]]);
        $dayHolidayName = $s->fetchColumn() ?: null;
    }

    // Banco de horas (opcional, si hay balance_start_date)
    $balance = null;
    $bsd = $config['balance_start_date'] ?? null;
    if ($bsd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bsd)) {
        $balance = computeBalance($db, $bsd, date('Y-m-d'), $config);
        $balance['start_date'] = $bsd;
    }

    jsonOut([
        'ok' => true,
        'now' => nowIso(),
        'user_name' => $config['user_name'] ?? '',
        'open' => $openRow ?: null,
        'balance' => $balance,
        'pause_alert_minutes' => (int)$config['pause_alert_minutes'],
        'weekly_target_sec' => $weeklyTargetSec,
        'day' => [
            'date' => $ref,
            'worked_sec'   => $daySec,
            'vacation_sec' => $dayVacSec,
            'holiday_sec'  => $dayHolSec,
            'total_sec'    => $daySec + $dayVacSec + $dayHolSec,
            'is_vacation'  => (bool) $dayVacSec,
            'is_holiday'   => (bool) $dayHolSec,
            'holiday_name' => $dayHolidayName,
            'sessions' => array_map(function($s) {
                return [
                    'id' => (int)$s['id'],
                    'start' => $s['start'],
                    'end' => $s['end'],
                    'notes' => $s['notes'],
                    'sec' => secondsBetween($s['start'], $s['end']),
                ];
            }, $daySessions),
            'pauses' => $pauses,
        ],
        'week' => [
            'from' => $wStart, 'to' => $wEnd,
            'worked_sec'   => $weekSec,
            'vacation_sec' => $weekVacSec,
            'holiday_sec'  => $weekHolSec,
            'total_sec'    => $weekSec + $weekVacSec + $weekHolSec,
            'target_sec'   => $weeklyTargetSec,
            'by_day' => $weekByDay,
        ],
        'week_previous' => [
            'from' => $pwStart, 'to' => $pwEnd,
            'worked_sec'   => $pwSec,
            'vacation_sec' => $pwVacSec,
            'holiday_sec'  => $pwHolSec,
            'total_sec'    => $pwSec + $pwVacSec + $pwHolSec,
            'target_sec'   => $pwTarget,
        ],
        'month' => [
            'from' => $mStart, 'to' => $mEnd,
            'period'         => monthKey($ref),
            'worked_sec'     => $monthSec,
            'vacation_sec'   => $monthVacSec,
            'holiday_sec'    => $monthHolSec,
            'adjustment_sec' => $adjSec,
            'total_sec'      => $monthSec + $monthVacSec + $monthHolSec + $adjSec,
            'target_sec'     => $monthTargetSec,
            'working_days'   => $monthWorkingDays,
            'adjustment_hours' => $adjHours,
            'by_week' => array_values($monthByWeek),
        ],
    ]);
}

if ($action === 'export') {
    $from = (string)($_GET['from'] ?? date('Y-m-01'));
    $to   = (string)($_GET['to']   ?? date('Y-m-t'));
    $stmt = $db->prepare("SELECT * FROM sessions WHERE start >= ? AND start <= ? ORDER BY start");
    $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="timetracker_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'date', 'start', 'end', 'duration_hours', 'notes']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sec = secondsBetween($r['start'], $r['end']);
        fputcsv($out, [
            $r['id'],
            substr($r['start'], 0, 10),
            $r['start'],
            $r['end'] ?? '',
            number_format($sec / 3600, 2, '.', ''),
            $r['notes'],
        ]);
    }
    fclose($out);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  HTML output
// ═══════════════════════════════════════════════════════════════════════════
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TimeTracker · Control de horario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #00C55E;
            --primary-hover: #00a34d;
            --bg-light: #F4F6F8;
            --warning-color: #f0ad4e;
            --danger-color: #d9534f;
        }
        body {
            background-color: var(--bg-light);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar-brand { font-weight: 800; }
        .brand-dot { color: var(--primary-color); }
        .card { border: none; box-shadow: 0 2px 6px rgba(0,0,0,0.04); border-radius: 12px; }
        .btn-primary { background: var(--primary-color); border-color: var(--primary-color); }
        .btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); }
        .stat-tile {
            background: #fff; border-radius: 12px; padding: 18px 20px;
            border-left: 5px solid var(--primary-color);
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
        }
        .stat-tile .label { font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .5px; font-weight: 700; }
        .stat-tile .value { font-size: 1.6rem; font-weight: 800; color: #212529; }
        .stat-tile.warn  { border-left-color: var(--warning-color); }
        .stat-tile.danger{ border-left-color: var(--danger-color); }
        .live-clock { font-size: 3rem; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--primary-color); }
        .live-status { font-size: .9rem; color: #6c757d; }
        .progress-40 { height: 22px; border-radius: 12px; }
        .progress-40 .progress-bar { font-weight: 700; }
        .session-row .duration { font-variant-numeric: tabular-nums; font-weight: 700; }
        .pause-row { background: #fff3cd; }
        .table-sm td, .table-sm th { padding: .55rem .6rem; vertical-align: middle; }
        .btn-icon { padding: 4px 8px; }
        .day-cell { min-height: 60px; padding: 8px; border-radius: 8px; background: #fff; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .day-cell .dow { font-size: .7rem; color: #6c757d; text-transform: uppercase; font-weight: 700; }
        .day-cell .hrs { font-size: 1.1rem; font-weight: 800; color: #212529; }
        .day-cell.today { border: 2px solid var(--primary-color); }
        .day-cell.vac-cell { background: #fff8e1; }
        .day-cell.hol-cell { background: #fde2e2; }
        .month-week-row { display: flex; align-items: center; padding: 10px 12px; border-radius: 8px; background: #fff; margin-bottom: 6px; }
        .month-week-row .lbl { flex: 0 0 220px; font-weight: 600; }
        .month-week-row .prog { flex: 1; margin: 0 12px; }
        .month-week-row .val { font-weight: 700; font-variant-numeric: tabular-nums; }
        .auth-box { max-width: 420px; margin: 8vh auto; }
    </style>
</head>
<body>

    <!-- ─── APP ─── -->
    <nav class="navbar navbar-light bg-white border-bottom">
        <div class="container-fluid px-4">
            <span class="navbar-brand mb-0"><span class="brand-dot"><i class="fa-solid fa-clock"></i></span> TimeTracker</span>
            <div>
                <a href="?action=backup" class="btn btn-outline-secondary btn-sm me-2" title="Descargar backup del SQLite"><i class="fa-solid fa-download"></i> Backup</a>
                <button type="button" class="btn btn-outline-secondary btn-sm me-2" id="btnRestore" title="Restaurar desde un fichero .sqlite"><i class="fa-solid fa-upload"></i> Import</button>
                <input type="file" id="restoreFile" accept=".sqlite,application/octet-stream" style="display:none">
                <a href="../../" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Volver</a>
            </div>
        </div>
    </nav>

    <!-- Banner alerta de pausa -->
    <div id="pauseAlert" class="alert alert-danger m-0 rounded-0 border-0 text-center" style="display:none">
        <strong><i class="fa-solid fa-triangle-exclamation"></i> Descansa un rato:</strong>
        Llevas <span id="pauseAlertMins">–</span> min sin pausar. La ley española obliga a un descanso mínimo cada 6h.
        <button class="btn btn-sm btn-dark ms-2" id="pauseAlertPause"><i class="fa-solid fa-pause"></i> Pausar ahora</button>
    </div>


    <div class="container-fluid p-4">

        <!-- Live tracker -->
        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card p-4 text-center">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Estado actual</div>
                    <div class="live-clock" id="liveClock">--h --m --s</div>
                    <div class="live-status" id="liveStatus">Cargando…</div>
                    <div class="mt-3 d-flex gap-2 justify-content-center flex-wrap">
                        <button id="btnStart" class="btn btn-primary"><i class="fa-solid fa-play"></i> Iniciar jornada</button>
                        <button id="btnPause" class="btn btn-warning text-white"><i class="fa-solid fa-pause"></i> Pausar</button>
                        <button id="btnResume" class="btn btn-success"><i class="fa-solid fa-play"></i> Reanudar</button>
                        <button id="btnStop" class="btn btn-danger"><i class="fa-solid fa-stop"></i> Terminar jornada</button>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <div class="stat-tile">
                            <div class="label">Hoy</div>
                            <div class="value" id="statDay">–</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-tile" id="tileWeek">
                            <div class="label">Semana</div>
                            <div class="value" id="statWeek">–</div>
                            <div class="small text-muted" id="statWeekDelta">–</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-tile">
                            <div class="label">Mes</div>
                            <div class="value" id="statMonth">–</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-tile" id="tileBalance">
                            <div class="label">Banco de horas</div>
                            <div class="value" id="statBalance">–</div>
                            <div class="small text-muted" id="statBalanceSub">–</div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="card p-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong>Progreso hacia 40h esta semana</strong>
                                <span id="weekPct" class="text-muted small">–</span>
                            </div>
                            <div class="progress progress-40">
                                <div id="weekBar" class="progress-bar" role="progressbar" style="width:0%"></div>
                            </div>
                            <div class="small text-muted mt-1" id="weekBreakdown"></div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="card p-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong id="monthProgLabel">Progreso mensual</strong>
                                <span id="monthPct" class="text-muted small">–</span>
                            </div>
                            <div class="progress progress-40">
                                <div id="monthBar" class="progress-bar" role="progressbar" style="width:0%"></div>
                            </div>
                            <div class="small text-muted mt-1" id="monthBreakdown"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs Day / Week / Month / Vacaciones -->
        <ul class="nav nav-tabs mt-4" id="mainTabs">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-day"><i class="fa-solid fa-calendar-day"></i> Hoy</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-week"><i class="fa-solid fa-calendar-week"></i> Semana</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-month"><i class="fa-solid fa-calendar"></i> Mes</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-vac"><i class="fa-solid fa-umbrella-beach"></i> Vacaciones</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-hol"><i class="fa-solid fa-star"></i> Festivos</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-cfg"><i class="fa-solid fa-gear"></i> Configuración</button></li>
        </ul>

        <div class="tab-content bg-white p-4 rounded-bottom" style="border:1px solid #dee2e6; border-top:none;">

            <!-- DAY -->
            <div class="tab-pane fade show active" id="tab-day">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <button class="btn btn-outline-secondary btn-sm" id="dayPrev"><i class="fa-solid fa-chevron-left"></i></button>
                        <input type="date" id="dayRef" class="form-control form-control-sm" style="width:auto">
                        <button class="btn btn-outline-secondary btn-sm" id="dayNext"><i class="fa-solid fa-chevron-right"></i></button>
                        <button class="btn btn-outline-secondary btn-sm" id="dayToday">Hoy</button>
                    </div>
                    <button class="btn btn-primary btn-sm" id="btnAddSession"><i class="fa-solid fa-plus"></i> Añadir entrada</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Tipo</th>
                                <th>Inicio</th>
                                <th>Fin</th>
                                <th>Duración</th>
                                <th>Notas</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="dayTable"></tbody>
                    </table>
                </div>
                <div class="text-end mt-3 small text-muted">Consejo: si te olvidaste de pausar, edita la sesión para poner el fin correcto y añade una nueva sesión desde que reanudaste.</div>
            </div>

            <!-- WEEK -->
            <div class="tab-pane fade" id="tab-week">
                <div class="mb-3 small text-muted" id="weekRange"></div>
                <div class="row g-2" id="weekGrid"></div>
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    <div><strong>Total semana:</strong> <span id="weekTotal">–</span></div>
                    <div><strong>Objetivo:</strong> 40h · <span id="weekDelta">–</span></div>
                </div>
            </div>

            <!-- MONTH -->
            <div class="tab-pane fade" id="tab-month">
                <div class="mb-3 small text-muted" id="monthRange"></div>
                <div id="monthWeeks"></div>
                <hr>
                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Ajuste manual del mes (backfill de horas previas)</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text" id="adjPeriodLabel">–</span>
                            <input type="number" id="adjHours" class="form-control" step="0.5" min="0" placeholder="p.ej. 56">
                            <span class="input-group-text">h</span>
                            <button class="btn btn-primary" id="btnAdjSave">Guardar</button>
                        </div>
                        <div class="small text-muted mt-1">Se suma al total del mes. Útil si empezaste a fichar a media semana.</div>
                    </div>
                    <div class="col-md-6 text-end">
                        <div>
                            <label class="small text-muted">Exportar CSV:</label>
                            <input type="date" id="expFrom" class="form-control form-control-sm d-inline-block" style="width:auto">
                            <input type="date" id="expTo"   class="form-control form-control-sm d-inline-block" style="width:auto">
                            <button class="btn btn-outline-primary btn-sm" id="btnExport"><i class="fa-solid fa-file-csv"></i> Descargar</button>
                        </div>
                        <div class="mt-2">
                            <button class="btn btn-primary btn-sm" id="btnReport"><i class="fa-solid fa-print"></i> Informe imprimible del mes</button>
                        </div>
                    </div>
                </div>
                <hr>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div><strong>Total mes (trabajado + vacaciones + ajuste):</strong> <span id="monthTotal">–</span></div>
                    <div><strong>Objetivo:</strong> <span id="monthTargetLbl">–</span> · <span id="monthDelta">–</span></div>
                </div>
            </div>

            <!-- HOLIDAYS -->
            <div class="tab-pane fade" id="tab-hol">
                <div class="row g-3">
                    <div class="col-md-5">
                        <div class="card p-3 mb-3">
                            <h6 class="mb-3"><i class="fa-solid fa-download"></i> Cargar calendario oficial</h6>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">Año</span>
                                <select id="holYear" class="form-select">
                                    <option value="2025">2025</option>
                                    <option value="2026" selected>2026</option>
                                    <option value="2027">2027</option>
                                </select>
                                <button class="btn btn-primary" id="btnHolSeed">Cargar</button>
                            </div>
                            <div class="small text-muted mt-2">
                                Añade los festivos nacionales + Comunidad de Madrid + Ayuntamiento de Madrid.
                                No sobrescribe los que ya existan; puedes editar o borrar cualquiera.
                            </div>
                            <div id="holSeedMsg" class="small mt-2"></div>
                        </div>
                        <div class="card p-3">
                            <h6 class="mb-3"><i class="fa-solid fa-plus"></i> Añadir festivo</h6>
                            <form id="holForm">
                                <div class="mb-2">
                                    <label class="form-label small">Fecha</label>
                                    <input type="date" name="date" class="form-control form-control-sm" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Nombre</label>
                                    <input type="text" name="name" class="form-control form-control-sm" placeholder="p.ej. Día de mi santo" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Ámbito</label>
                                    <select name="scope" class="form-select form-select-sm">
                                        <option value="custom">Personal</option>
                                        <option value="nacional">Nacional</option>
                                        <option value="madrid">Madrid (Comunidad)</option>
                                        <option value="madrid-capital">Madrid (Ayuntamiento)</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-plus"></i> Añadir festivo</button>
                                <div id="holErr" class="text-danger small mt-2"></div>
                            </form>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="card p-3">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="mb-0">Festivos registrados</h6>
                                <div class="input-group input-group-sm" style="width:auto">
                                    <span class="input-group-text">Filtro año</span>
                                    <select id="holFilterYear" class="form-select">
                                        <option value="">Todos</option>
                                        <option value="2025">2025</option>
                                        <option value="2026" selected>2026</option>
                                        <option value="2027">2027</option>
                                    </select>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light">
                                        <tr><th>Fecha</th><th>Día</th><th>Nombre</th><th>Ámbito</th><th></th></tr>
                                    </thead>
                                    <tbody id="holTable"></tbody>
                                </table>
                            </div>
                            <div class="small text-muted mt-2">
                                Los festivos en día laborable (Lun–Vie) se contabilizan automáticamente como 8h en día/semana/mes.
                                Los que caen en fin de semana no suman (pero puedes verlos aquí).
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CONFIG -->
            <div class="tab-pane fade" id="tab-cfg">
                <div class="card p-3 mb-3">
                    <h6><i class="fa-solid fa-user"></i> Tu nombre</h6>
                    <div class="small text-muted mb-2">Se usa en toda PANTools. Se sincroniza con el badge del hub y con PoV Radar.</div>
                    <input type="text" id="cfgUserName" class="form-control form-control-sm" placeholder="e.g. David de La Paz" style="max-width:360px">
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card p-3 mb-3">
                            <h6><i class="fa-solid fa-clock"></i> Horario semanal</h6>
                            <div class="small text-muted mb-2">Horas objetivo para cada día de la semana (Lun–Dom). El total semanal se calcula sumando estos valores.</div>
                            <div id="weeklyHoursGrid"></div>
                            <div class="small text-muted mt-2" id="weeklyHoursTotal">–</div>
                        </div>
                        <div class="card p-3">
                            <h6><i class="fa-solid fa-scale-balanced"></i> Banco de horas</h6>
                            <label class="form-label small">Contabilizar balance desde:</label>
                            <input type="date" id="cfgBalanceStart" class="form-control form-control-sm">
                            <div class="small text-muted mt-2">Vacío = no computar. Suma de (trabajado + vacaciones + festivos + ajustes) menos el objetivo, día a día, desde esa fecha hasta hoy.</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card p-3 mb-3">
                            <h6><i class="fa-solid fa-sun"></i> Periodos con jornada intensiva</h6>
                            <div class="small text-muted mb-2">Rangos anuales recurrentes (MM-DD) que sobrescriben el horario en Lun–Vie. Los findes no computan durante intensivos.</div>
                            <table class="table table-sm mb-2">
                                <thead class="table-light">
                                    <tr><th>Nombre</th><th>Desde</th><th>Hasta</th><th>h/día</th><th></th></tr>
                                </thead>
                                <tbody id="intensiveTable"></tbody>
                            </table>
                            <button class="btn btn-outline-primary btn-sm" id="btnAddIntensive"><i class="fa-solid fa-plus"></i> Añadir periodo</button>
                        </div>
                        <div class="card p-3">
                            <h6><i class="fa-solid fa-mug-hot"></i> Alerta de descanso</h6>
                            <label class="form-label small">Avisar tras trabajar continuamente:</label>
                            <div class="input-group input-group-sm">
                                <input type="number" id="cfgPauseAlert" class="form-control" min="0" max="720" step="15">
                                <span class="input-group-text">min</span>
                            </div>
                            <div class="small text-muted mt-2">0 = sin alerta. Recomendado: 240 (4h) según normativa laboral española.</div>
                        </div>
                    </div>
                </div>
                <hr>
                <div class="text-end">
                    <button class="btn btn-primary" id="btnSaveSettings"><i class="fa-solid fa-save"></i> Guardar configuración</button>
                    <span id="settingsMsg" class="ms-3 small"></span>
                </div>
            </div>

            <!-- VACATIONS -->
            <div class="tab-pane fade" id="tab-vac">
                <div class="row g-3">
                    <div class="col-md-5">
                        <div class="card p-3">
                            <h6 class="mb-3">Añadir vacaciones</h6>
                            <form id="vacForm">
                                <div class="mb-2">
                                    <label class="form-label small">Desde</label>
                                    <input type="date" name="start_date" class="form-control form-control-sm" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Hasta</label>
                                    <input type="date" name="end_date" class="form-control form-control-sm" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Notas (opcional)</label>
                                    <input type="text" name="notes" class="form-control form-control-sm" placeholder="p.ej. Verano">
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-plus"></i> Añadir periodo</button>
                                <div id="vacErr" class="text-danger small mt-2"></div>
                            </form>
                            <div class="small text-muted mt-3">
                                Cada día laborable (Lun–Vie) dentro del rango se contabiliza como una jornada completa (8h) en los totales de día, semana y mes.
                            </div>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="card p-3">
                            <h6 class="mb-3">Periodos registrados</h6>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light">
                                        <tr><th>Desde</th><th>Hasta</th><th>Días</th><th>Notas</th><th></th></tr>
                                    </thead>
                                    <tbody id="vacTable"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Session modal -->
    <div class="modal fade" id="sessionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="sessionForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="sessionModalTitle">Nueva entrada</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="sf_id">
                        <div class="mb-3">
                            <label class="form-label">Inicio</label>
                            <input type="datetime-local" name="start" id="sf_start" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Fin <span class="text-muted small">(vacío = sesión abierta)</span></label>
                            <input type="datetime-local" name="end" id="sf_end" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notas</label>
                            <textarea name="notes" id="sf_notes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="text-danger small" id="sf_err"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-danger me-auto" id="sf_delete" style="display:none">Borrar</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // ═══ Client ═══
    const DAY_NAMES = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];

    function fmtDur(sec) {
        const sign = sec < 0 ? '-' : '';
        sec = Math.abs(sec);
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        return `${sign}${h}h ${String(m).padStart(2,'0')}m`;
    }
    function fmtClock(sec) {
        sec = Math.max(0, sec|0);
        const h = Math.floor(sec/3600);
        const m = Math.floor((sec%3600)/60);
        const s = sec%60;
        return `${String(h).padStart(2,'0')}h ${String(m).padStart(2,'0')}m ${String(s).padStart(2,'0')}s`;
    }
    function toLocalInput(iso) {
        if (!iso) return '';
        return iso.replace(' ', 'T').substring(0,16);
    }
    function todayStr() {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    }

    let currentRef = todayStr();
    let liveTimer = null;
    let liveOpenStart = null;
    let liveDayBaseSec = 0;
    let pauseAlertMinutes = 0;
    let pauseAlertNotified = false;
    const DOW_NAMES_FULL = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];

    function checkPauseAlert() {
        const alertEl = document.getElementById('pauseAlert');
        if (!liveOpenStart || pauseAlertMinutes <= 0) {
            alertEl.style.display = 'none';
            pauseAlertNotified = false;
            return;
        }
        const elapsedMin = Math.floor((Date.now() - liveOpenStart.getTime()) / 60000);
        if (elapsedMin >= pauseAlertMinutes) {
            document.getElementById('pauseAlertMins').textContent = elapsedMin;
            alertEl.style.display = 'block';
            if (!pauseAlertNotified && 'Notification' in window && Notification.permission === 'granted') {
                new Notification('TimeTracker · Descanso recomendado', {
                    body: `Llevas ${elapsedMin} min sin pausar.`,
                    tag: 'tt-pause',
                });
                pauseAlertNotified = true;
            }
        } else {
            alertEl.style.display = 'none';
        }
    }
    document.getElementById('pauseAlertPause').addEventListener('click', async () => {
        const j = await api('pause', {body: new FormData()});
        if (j && !j.ok) alert(j.error || 'Error');
        lastFetchAt = Date.now(); refresh();
    });


    async function api(action, opts = {}) {
        const url = `?action=${action}` + (opts.qs ? '&' + opts.qs : '');
        const init = opts.body ? {method:'POST', body: opts.body} : {};
        const r = await fetch(url, init);
        if (r.status === 401) { location.reload(); return; }
        return r.json();
    }

    function renderProgress(barId, pctId, breakdownId, totalSec, targetSec, workedSec, vacSec, holSec, adjSec) {
        const pct = targetSec > 0 ? Math.min(100, Math.round(totalSec / targetSec * 100)) : 0;
        const bar = document.getElementById(barId);
        bar.style.width = pct + '%';
        bar.textContent = pct + '%';
        const over = totalSec > targetSec * 1.02;
        bar.className = 'progress-bar ' + (
            over ? 'bg-danger' :
            pct >= 100 ? 'bg-success' :
            pct >= 75  ? 'bg-primary' :
                         'bg-info'
        );
        document.getElementById(pctId).textContent = fmtDur(totalSec) + ' de ' + fmtDur(targetSec);
        const parts = [];
        parts.push(`Trabajado: <strong>${fmtDur(workedSec)}</strong>`);
        if (vacSec > 0) parts.push(`🏖 Vacaciones: <strong>${fmtDur(vacSec)}</strong>`);
        if (holSec > 0) parts.push(`🎉 Festivos: <strong>${fmtDur(holSec)}</strong>`);
        if (adjSec && adjSec !== 0) parts.push(`Ajuste: <strong>${fmtDur(adjSec)}</strong>`);
        document.getElementById(breakdownId).innerHTML = parts.join(' · ');
    }

    async function refresh() {
        const j = await api('summary', {qs: 'ref=' + currentRef});
        if (!j || !j.ok) return;

        // Auto-migración de identidad: si TimeTracker no tiene nombre pero localStorage sí, guarda
        if (!j.user_name) {
            const lsName = (localStorage.getItem('pantools_user_name') || '').trim();
            if (lsName) {
                await fetch('?action=settings_update', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({user_name: lsName}),
                });
            }
        } else {
            // Al revés: si TimeTracker tiene nombre y localStorage no, propaga al hub
            if (!localStorage.getItem('pantools_user_name')) {
                localStorage.setItem('pantools_user_name', j.user_name);
            }
        }

        // Live
        liveOpenStart = j.open ? new Date(j.open.start.replace(' ','T')) : null;
        liveDayBaseSec = j.day.worked_sec;
        updateLiveDisplay();

        document.getElementById('liveStatus').textContent = liveOpenStart
            ? 'Trabajando desde ' + j.open.start.substring(11,16)
            : (j.day.is_vacation ? 'Día de vacaciones' : 'En pausa / jornada no iniciada');
        document.getElementById('btnStart').disabled = !!liveOpenStart;
        document.getElementById('btnResume').disabled = !!liveOpenStart;
        document.getElementById('btnPause').disabled = !liveOpenStart;
        document.getElementById('btnStop').disabled = !liveOpenStart;

        // Stats
        document.getElementById('statDay').textContent = fmtDur(j.day.total_sec)
            + (j.day.is_vacation ? ' 🏖' : '');
        document.getElementById('statWeek').textContent = fmtDur(j.week.total_sec);
        const weekDelta = j.week.total_sec - j.week.target_sec;
        document.getElementById('statWeekDelta').textContent =
            (weekDelta >= 0 ? 'Superado en ' : 'Faltan ') + fmtDur(Math.abs(weekDelta));
        document.getElementById('tileWeek').classList.toggle('warn', weekDelta < 0 && j.week.total_sec > 30*3600);
        document.getElementById('tileWeek').classList.toggle('danger', weekDelta > 3600);
        document.getElementById('statMonth').textContent = fmtDur(j.month.total_sec);

        // Balance de horas
        if (j.balance) {
            const bs = j.balance.balance_sec;
            const sign = bs >= 0 ? '+' : '−';
            document.getElementById('statBalance').innerHTML = `<span class="${bs>=0?'text-success':'text-danger'}">${sign}${fmtDur(Math.abs(bs))}</span>`;
            document.getElementById('statBalanceSub').textContent = `Desde ${j.balance.start_date} (${j.balance.days} días)`;
            document.getElementById('tileBalance').classList.remove('warn','danger');
            if (bs < -8*3600) document.getElementById('tileBalance').classList.add('danger');
            else if (bs < 0) document.getElementById('tileBalance').classList.add('warn');
        } else {
            document.getElementById('statBalance').innerHTML = '<span class="text-muted">–</span>';
            document.getElementById('statBalanceSub').innerHTML = '<a href="#" onclick="document.querySelector(&quot;[data-bs-target=\\&quot;#tab-cfg\\&quot;]&quot;).click(); return false;">Configurar fecha</a>';
        }

        // Pause-alert threshold
        pauseAlertMinutes = j.pause_alert_minutes || 0;
        checkPauseAlert();

        // Week progress bar
        renderProgress('weekBar', 'weekPct', 'weekBreakdown',
            j.week.total_sec, j.week.target_sec,
            j.week.worked_sec, j.week.vacation_sec, j.week.holiday_sec, 0);

        // Month progress bar
        document.getElementById('monthProgLabel').textContent =
            `Progreso mensual (objetivo ${fmtDur(j.month.target_sec)} · ${j.month.working_days} días lab.)`;
        renderProgress('monthBar', 'monthPct', 'monthBreakdown',
            j.month.total_sec, j.month.target_sec,
            j.month.worked_sec, j.month.vacation_sec, j.month.holiday_sec, j.month.adjustment_sec);

        renderDayTable(j.day);
        renderWeek(j.week);
        renderMonth(j.month);
        loadVacations();
        loadHolidays();

        // Adjustment input state
        document.getElementById('adjPeriodLabel').textContent = j.month.period;
        document.getElementById('adjHours').value = j.month.adjustment_hours || '';

        // Export defaults
        document.getElementById('expFrom').value = j.month.from.substring(0,10);
        document.getElementById('expTo').value   = j.month.to.substring(0,10);
    }

    function updateLiveDisplay() {
        let sec = liveDayBaseSec;
        if (liveOpenStart) {
            sec = liveDayBaseSec + Math.floor((Date.now() - lastFetchAt) / 1000);
        }
        document.getElementById('liveClock').textContent = fmtClock(sec);
    }
    let lastFetchAt = Date.now();

    function renderDayTable(day) {
        const tbody = document.getElementById('dayTable');
        tbody.innerHTML = '';
        document.getElementById('dayRef').value = day.date;

        // Interleave sessions with pauses
        const rows = [];
        let prevEnd = null;
        for (const s of day.sessions) {
            if (prevEnd && s.start > prevEnd) {
                const pauseSec = (new Date(s.start.replace(' ','T')) - new Date(prevEnd.replace(' ','T'))) / 1000;
                rows.push({type: 'pause', start: prevEnd, end: s.start, sec: pauseSec});
            }
            rows.push({type: 'work', ...s});
            prevEnd = s.end;
        }
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Sin entradas este día. Pulsa <em>Añadir entrada</em> o inicia la jornada.</td></tr>';
            return;
        }
        for (const r of rows) {
            const tr = document.createElement('tr');
            tr.className = 'session-row' + (r.type === 'pause' ? ' pause-row' : '');
            if (r.type === 'pause') {
                tr.innerHTML = `
                    <td><span class="badge bg-warning text-dark"><i class="fa-solid fa-pause"></i> Pausa</span></td>
                    <td>${r.start.substring(11,16)}</td>
                    <td>${r.end.substring(11,16)}</td>
                    <td class="duration text-warning">${fmtDur(r.sec)}</td>
                    <td class="text-muted small">Hueco entre sesiones</td>
                    <td></td>`;
            } else {
                tr.innerHTML = `
                    <td><span class="badge bg-success"><i class="fa-solid fa-briefcase"></i> Trabajo</span></td>
                    <td>${r.start.substring(11,16)}</td>
                    <td>${r.end ? r.end.substring(11,16) : '<em class="text-success">en curso</em>'}</td>
                    <td class="duration">${fmtDur(r.sec)}</td>
                    <td>${r.notes ? r.notes.replace(/</g,'&lt;') : ''}</td>
                    <td class="text-end">
                        <button class="btn btn-outline-secondary btn-icon btn-sm" data-edit='${JSON.stringify(r)}'>
                            <i class="fa-solid fa-pen"></i>
                        </button>
                    </td>`;
                tr.querySelector('button').addEventListener('click', e => openModal(JSON.parse(e.currentTarget.dataset.edit)));
            }
            tbody.appendChild(tr);
        }
    }

    function renderWeek(week) {
        document.getElementById('weekRange').textContent = `Semana: ${week.from.substring(0,10)} → ${week.to.substring(0,10)}`;
        const grid = document.getElementById('weekGrid');
        grid.innerHTML = '';
        const today = todayStr();
        Object.entries(week.by_day).forEach(([d, info], i) => {
            const col = document.createElement('div');
            col.className = 'col';
            let badge = '';
            if (info.is_hol) badge = `<div class="small text-danger" title="${(info.hol_name||'').replace(/"/g,'&quot;')}">🎉 festivo</div>`;
            else if (info.is_vac) badge = '<div class="small text-warning">🏖 vacaciones</div>';
            const cellClass = info.is_hol ? 'hol-cell' : (info.is_vac ? 'vac-cell' : '');
            col.innerHTML = `
                <div class="day-cell ${d === today ? 'today' : ''} ${cellClass}">
                    <div class="dow">${DAY_NAMES[i]}</div>
                    <div class="small text-muted">${d.substring(8,10)}/${d.substring(5,7)}</div>
                    <div class="hrs">${fmtDur(info.total)}</div>
                    ${badge}
                </div>`;
            col.querySelector('.day-cell').addEventListener('click', () => {
                currentRef = d;
                document.querySelector('[data-bs-target="#tab-day"]').click();
                refresh();
            });
            grid.appendChild(col);
        });
        document.getElementById('weekTotal').textContent = fmtDur(week.total_sec);
        const delta = week.total_sec - week.target_sec;
        document.getElementById('weekDelta').innerHTML =
            (delta >= 0
                ? `<span class="text-success">+${fmtDur(delta)}</span>`
                : `<span class="text-danger">-${fmtDur(-delta)}</span>`);
    }

    function renderMonth(month) {
        document.getElementById('monthRange').textContent = `Mes: ${month.from.substring(0,10)} → ${month.to.substring(0,10)}`;
        const box = document.getElementById('monthWeeks');
        box.innerHTML = '';
        const wkTarget = 40 * 3600;
        for (const w of month.by_week) {
            const pct = Math.min(100, Math.round(w.total / wkTarget * 100));
            const extras = [];
            if (w.vacation > 0) extras.push(`🏖 ${fmtDur(w.vacation)}`);
            if (w.holiday  > 0) extras.push(`🎉 ${fmtDur(w.holiday)}`);
            const extra = extras.length ? ` <span class="text-muted small">(${extras.join(' · ')})</span>` : '';
            const row = document.createElement('div');
            row.className = 'month-week-row';
            row.innerHTML = `
                <div class="lbl">${w.label}${extra}</div>
                <div class="prog">
                    <div class="progress" style="height:14px">
                        <div class="progress-bar ${w.total >= wkTarget ? 'bg-success' : 'bg-info'}" style="width:${pct}%"></div>
                    </div>
                </div>
                <div class="val">${fmtDur(w.total)}</div>`;
            box.appendChild(row);
        }
        document.getElementById('monthTotal').textContent = fmtDur(month.total_sec);
        document.getElementById('monthTargetLbl').textContent = fmtDur(month.target_sec) + ` (${month.working_days} días lab.)`;
        const delta = month.total_sec - month.target_sec;
        document.getElementById('monthDelta').innerHTML =
            (delta >= 0
                ? `<span class="text-success">+${fmtDur(delta)}</span>`
                : `<span class="text-danger">-${fmtDur(-delta)}</span>`);
    }

    async function loadVacations() {
        const j = await api('vacation_list');
        if (!j || !j.ok) return;
        const tb = document.getElementById('vacTable');
        tb.innerHTML = '';
        if (!j.items.length) {
            tb.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Sin periodos de vacaciones.</td></tr>';
            return;
        }
        for (const v of j.items) {
            // Count weekdays
            let d = new Date(v.start_date), end = new Date(v.end_date), wd = 0;
            while (d <= end) {
                const day = d.getDay();
                if (day >= 1 && day <= 5) wd++;
                d.setDate(d.getDate() + 1);
            }
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${v.start_date}</td>
                <td>${v.end_date}</td>
                <td><span class="badge bg-warning text-dark">${wd} días lab.</span></td>
                <td>${(v.notes || '').replace(/</g,'&lt;')}</td>
                <td class="text-end"><button class="btn btn-sm btn-outline-danger" data-vid="${v.id}"><i class="fa-solid fa-trash"></i></button></td>`;
            tr.querySelector('button').addEventListener('click', async () => {
                if (!confirm('¿Borrar este periodo de vacaciones?')) return;
                const fd = new FormData(); fd.append('id', v.id);
                const r = await api('vacation_delete', {body: fd});
                if (r && r.ok) { lastFetchAt = Date.now(); refresh(); }
            });
            tb.appendChild(tr);
        }
    }

    const SCOPE_LABEL = {
        'nacional':       '<span class="badge bg-danger">Nacional</span>',
        'madrid':         '<span class="badge bg-primary">Madrid</span>',
        'madrid-capital': '<span class="badge bg-info text-dark">Ayto. Madrid</span>',
        'custom':         '<span class="badge bg-secondary">Personal</span>',
    };
    const DOW_ES = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];

    async function loadHolidays() {
        const j = await api('holiday_list');
        if (!j || !j.ok) return;
        const filter = document.getElementById('holFilterYear').value;
        const tb = document.getElementById('holTable');
        tb.innerHTML = '';
        const items = filter ? j.items.filter(h => h.date.startsWith(filter)) : j.items;
        if (!items.length) {
            tb.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Sin festivos. Usa <em>Cargar calendario oficial</em>.</td></tr>';
            return;
        }
        for (const h of items) {
            const dt = new Date(h.date + 'T00:00:00');
            const dow = DOW_ES[dt.getDay()];
            const isWeekend = dt.getDay() === 0 || dt.getDay() === 6;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${h.date}</td>
                <td><span class="${isWeekend ? 'text-muted' : ''}">${dow}</span></td>
                <td>${h.name.replace(/</g,'&lt;')} ${isWeekend ? '<span class="badge bg-light text-muted small">no computa</span>' : ''}</td>
                <td>${SCOPE_LABEL[h.scope] || SCOPE_LABEL.custom}</td>
                <td class="text-end"><button class="btn btn-sm btn-outline-danger" data-d="${h.date}"><i class="fa-solid fa-trash"></i></button></td>`;
            tr.querySelector('button').addEventListener('click', async () => {
                if (!confirm(`¿Borrar festivo del ${h.date}?`)) return;
                const fd = new FormData(); fd.append('date', h.date);
                const r = await api('holiday_delete', {body: fd});
                if (r && r.ok) { lastFetchAt = Date.now(); refresh(); }
            });
            tb.appendChild(tr);
        }
    }

    document.getElementById('holFilterYear').addEventListener('change', loadHolidays);

    document.getElementById('holForm').addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const j = await api('holiday_create', {body: fd});
        if (!j.ok) { document.getElementById('holErr').textContent = j.error || 'Error'; return; }
        document.getElementById('holErr').textContent = '';
        e.target.reset();
        lastFetchAt = Date.now(); refresh();
    });

    document.getElementById('btnHolSeed').addEventListener('click', async () => {
        const year = document.getElementById('holYear').value;
        const fd = new FormData(); fd.append('year', year);
        const j = await api('holiday_seed', {body: fd});
        const msg = document.getElementById('holSeedMsg');
        if (!j.ok) { msg.className = 'small mt-2 text-danger'; msg.textContent = j.error || 'Error'; return; }
        msg.className = 'small mt-2 text-success';
        msg.textContent = `Cargados ${j.added} de ${j.total} festivos para ${year} (los ya existentes se han conservado).`;
        lastFetchAt = Date.now(); refresh();
    });

    document.getElementById('vacForm').addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const j = await api('vacation_create', {body: fd});
        if (!j.ok) { document.getElementById('vacErr').textContent = j.error || 'Error'; return; }
        document.getElementById('vacErr').textContent = '';
        e.target.reset();
        lastFetchAt = Date.now(); refresh();
    });

    document.getElementById('btnAdjSave').addEventListener('click', async () => {
        const period = document.getElementById('adjPeriodLabel').textContent;
        const hours  = document.getElementById('adjHours').value || 0;
        const fd = new FormData();
        fd.append('period', period);
        fd.append('hours', hours);
        const j = await api('adjustment_set', {body: fd});
        if (!j.ok) { alert(j.error || 'Error'); return; }
        lastFetchAt = Date.now(); refresh();
    });

    // Modal
    const modal = new bootstrap.Modal(document.getElementById('sessionModal'));
    function openModal(row = null) {
        document.getElementById('sf_err').textContent = '';
        document.getElementById('sf_delete').style.display = row ? '' : 'none';
        document.getElementById('sessionModalTitle').textContent = row ? 'Editar entrada' : 'Nueva entrada';
        document.getElementById('sf_id').value = row ? row.id : '';
        document.getElementById('sf_start').value = row ? toLocalInput(row.start) : toLocalInput(currentRef + ' 09:00:00');
        document.getElementById('sf_end').value = row && row.end ? toLocalInput(row.end) : '';
        document.getElementById('sf_notes').value = row ? (row.notes || '') : '';
        modal.show();
    }
    document.getElementById('btnAddSession').addEventListener('click', () => openModal(null));
    document.getElementById('sessionForm').addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const id = fd.get('id');
        const j = await api(id ? 'update' : 'create', {body: fd});
        if (!j.ok) { document.getElementById('sf_err').textContent = j.error || 'Error'; return; }
        modal.hide();
        lastFetchAt = Date.now();
        refresh();
    });
    document.getElementById('sf_delete').addEventListener('click', async () => {
        if (!confirm('¿Borrar esta entrada?')) return;
        const fd = new FormData();
        fd.append('id', document.getElementById('sf_id').value);
        const j = await api('delete', {body: fd});
        if (!j.ok) { document.getElementById('sf_err').textContent = j.error || 'Error'; return; }
        modal.hide();
        lastFetchAt = Date.now();
        refresh();
    });

    // Live buttons
    for (const [id, action] of [['btnStart','start'],['btnPause','pause'],['btnResume','resume'],['btnStop','stop']]) {
        document.getElementById(id).addEventListener('click', async () => {
            const j = await api(action, {body: new FormData()});
            if (j && !j.ok) alert(j.error || 'Error');
            lastFetchAt = Date.now();
            refresh();
        });
    }

    // Day navigation
    document.getElementById('dayPrev').addEventListener('click', () => {
        const d = new Date(currentRef); d.setDate(d.getDate() - 1);
        currentRef = d.toISOString().substring(0,10); refresh();
    });
    document.getElementById('dayNext').addEventListener('click', () => {
        const d = new Date(currentRef); d.setDate(d.getDate() + 1);
        currentRef = d.toISOString().substring(0,10); refresh();
    });
    document.getElementById('dayToday').addEventListener('click', () => { currentRef = todayStr(); refresh(); });
    document.getElementById('dayRef').addEventListener('change', e => { currentRef = e.target.value; refresh(); });

    // Export
    document.getElementById('btnExport').addEventListener('click', () => {
        const from = document.getElementById('expFrom').value;
        const to   = document.getElementById('expTo').value;
        window.location = `?action=export&from=${from}&to=${to}`;
    });

    // Informe imprimible del mes
    document.getElementById('btnReport').addEventListener('click', () => {
        window.open(`?action=report&ref=${currentRef}`, '_blank');
    });

    // Import de backup (restaurar SQLite)
    document.getElementById('btnRestore').addEventListener('click', () => {
        document.getElementById('restoreFile').click();
    });
    document.getElementById('restoreFile').addEventListener('change', async e => {
        const f = e.target.files[0];
        if (!f) return;
        if (!confirm(`¿Restaurar la BD desde "${f.name}"?\n\nSe sobrescribirá TODO lo actual (sesiones, vacaciones, festivos, ajustes, configuración).\nSe guardará un backup automático de la BD actual por si acaso.`)) {
            e.target.value = '';
            return;
        }
        const fd = new FormData();
        fd.append('file', f);
        const r = await fetch('?action=restore', {method: 'POST', body: fd});
        const j = await r.json();
        if (!j.ok) { alert('Error: ' + (j.error || 'desconocido')); e.target.value = ''; return; }
        alert(`Restauración completada.\nBackup de seguridad guardado en data/${j.safety_backup}`);
        location.reload();
    });

    // ═══ Configuración ═══
    async function loadSettings() {
        const j = await api('settings_get');
        if (!j || !j.ok) return;
        const s = j.settings;
        // User name
        document.getElementById('cfgUserName').value = s.user_name || '';
        // Weekly hours grid
        const box = document.getElementById('weeklyHoursGrid');
        box.innerHTML = '';
        DOW_NAMES_FULL.forEach((name, idx) => {
            const dow = String(idx + 1);
            const row = document.createElement('div');
            row.className = 'input-group input-group-sm mb-1';
            row.innerHTML = `
                <span class="input-group-text" style="width:70px">${name}</span>
                <input type="number" class="form-control wh-input" data-dow="${dow}" value="${s.weekly_hours[dow] ?? 0}" step="0.25" min="0" max="24">
                <span class="input-group-text">h</span>`;
            box.appendChild(row);
        });
        box.addEventListener('input', updateWeeklyHoursTotal);
        updateWeeklyHoursTotal();

        // Balance start
        document.getElementById('cfgBalanceStart').value = s.balance_start_date || '';
        // Pause alert
        document.getElementById('cfgPauseAlert').value = s.pause_alert_minutes || 0;
        // Intensive periods
        renderIntensives(s.intensive_periods || []);
    }

    function updateWeeklyHoursTotal() {
        let total = 0;
        document.querySelectorAll('.wh-input').forEach(i => total += parseFloat(i.value || 0));
        document.getElementById('weeklyHoursTotal').textContent = `Total semanal: ${total.toFixed(2)} h`;
    }

    function renderIntensives(list) {
        const tb = document.getElementById('intensiveTable');
        tb.innerHTML = '';
        if (!list.length) {
            tb.innerHTML = '<tr><td colspan="5" class="text-center text-muted small">Sin periodos intensivos.</td></tr>';
            return;
        }
        list.forEach((p, i) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><input class="form-control form-control-sm ip-name" value="${(p.name||'').replace(/"/g,'&quot;')}"></td>
                <td><input class="form-control form-control-sm ip-from" value="${p.from||''}" placeholder="MM-DD" style="width:80px"></td>
                <td><input class="form-control form-control-sm ip-to" value="${p.to||''}" placeholder="MM-DD" style="width:80px"></td>
                <td><input type="number" class="form-control form-control-sm ip-hours" value="${p.hours_per_day||7}" step="0.5" min="0" max="24" style="width:70px"></td>
                <td><button class="btn btn-sm btn-outline-danger ip-del"><i class="fa-solid fa-trash"></i></button></td>`;
            tr.querySelector('.ip-del').addEventListener('click', () => { tr.remove(); });
            tb.appendChild(tr);
        });
    }
    function readIntensives() {
        return Array.from(document.querySelectorAll('#intensiveTable tr')).map(tr => {
            const name  = tr.querySelector('.ip-name')?.value;
            const from  = tr.querySelector('.ip-from')?.value;
            const to    = tr.querySelector('.ip-to')?.value;
            const hours = tr.querySelector('.ip-hours')?.value;
            if (!name) return null;
            return {name: name.trim(), from: (from||'').trim(), to: (to||'').trim(), hours_per_day: parseFloat(hours || 0)};
        }).filter(Boolean);
    }
    document.getElementById('btnAddIntensive').addEventListener('click', () => {
        const cur = readIntensives();
        cur.push({name: '', from: '', to: '', hours_per_day: 7});
        renderIntensives(cur);
    });

    document.getElementById('btnSaveSettings').addEventListener('click', async () => {
        const wh = {};
        document.querySelectorAll('.wh-input').forEach(i => wh[i.dataset.dow] = parseFloat(i.value || 0));
        const userName = (document.getElementById('cfgUserName').value || '').trim();
        const payload = {
            user_name: userName,
            weekly_hours: wh,
            balance_start_date: document.getElementById('cfgBalanceStart').value || null,
            pause_alert_minutes: parseInt(document.getElementById('cfgPauseAlert').value || 0, 10),
            intensive_periods: readIntensives(),
        };
        // Sync with PANTools shared identity
        if (userName) localStorage.setItem('pantools_user_name', userName);
        const r = await fetch('?action=settings_update', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
        });
        const j = await r.json();
        const msg = document.getElementById('settingsMsg');
        if (j.ok) {
            msg.textContent = '✔ Guardado';
            msg.className = 'ms-3 small text-success';
            lastFetchAt = Date.now(); refresh();
        } else {
            msg.textContent = j.error || 'Error';
            msg.className = 'ms-3 small text-danger';
        }
        setTimeout(() => { msg.textContent = ''; }, 3000);
    });

    // Request Notification permission (best-effort)
    if ('Notification' in window && Notification.permission === 'default') {
        document.addEventListener('click', () => { Notification.requestPermission(); }, {once: true});
    }

    // Load settings when config tab opens
    document.querySelector('[data-bs-target="#tab-cfg"]').addEventListener('click', loadSettings);

    // Boot
    lastFetchAt = Date.now();
    refresh();
    setInterval(() => { updateLiveDisplay(); checkPauseAlert(); }, 1000);
    setInterval(() => { lastFetchAt = Date.now(); refresh(); }, 60000);
    </script>
</body>
</html>
