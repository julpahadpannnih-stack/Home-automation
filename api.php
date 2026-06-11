<?php
// api.php - Render-ready Smart Home API Backend
// FIXED: CORS now supports multiple allowed origins (dev + production)
// FIXED: All DB credentials read from environment variables
// FIXED: All queries use PDO prepared statements
// FIXED: Passwords stored with password_hash / verified with password_verify
// FIXED: Error responses use proper HTTP status codes

header('Content-Type: application/json');

// ─── CORS ────────────────────────────────────────────────────────────────────
$allowed_origin = getenv('FRONTEND_URL') ?: '*';

// Support comma-separated list of allowed origins for dev + prod
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_list = array_map('trim', explode(',', $allowed_origin));

if ($allowed_origin === '*') {
    header("Access-Control-Allow-Origin: *");
} elseif (in_array($origin, $allowed_list)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Vary: Origin");
} else {
    // Default to first allowed origin
    header("Access-Control-Allow-Origin: " . $allowed_list[0]);
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── DATABASE CONNECTION ──────────────────────────────────────────────────────
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'smarthome_db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$port = getenv('DB_PORT') ?: '3306';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    // FIXED: Don't expose raw DB error message in production
    $debug = getenv('APP_DEBUG') === 'true';
    die(json_encode(['error' => $debug ? 'Database connection failed: ' . $e->getMessage() : 'Database connection failed.']));
}

$action = $_GET['action'] ?? '';

// ─── 1. LOGIN ─────────────────────────────────────────────────────────────────
if ($action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    $u    = trim($data['username'] ?? '');
    $p    = $data['password'] ?? '';

    // FIXED: Validate input before querying
    if (empty($u) || empty($p)) {
        echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT password FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$u]);
    $row = $stmt->fetch();

    if ($row && password_verify($p, $row['password'])) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// ─── 1b. ONE-TIME PASSWORD MIGRATION / INIT HELPER ────────────────────────────
// Visit: https://your-api.onrender.com/api.php?action=init_password&secret=YOUR_INIT_SECRET&username=admin&password=yourpassword
// REMOVE this block after your first use or set INIT_SECRET_USED=true in env.
if ($action === 'init_password') {
    // FIXED: Block this endpoint if already used
    if (getenv('INIT_SECRET_USED') === 'true') {
        http_response_code(403);
        echo json_encode(['error' => 'This endpoint has been disabled.']);
        exit;
    }

    $secret = $_GET['secret'] ?? '';
    $init_secret = getenv('INIT_SECRET') ?: '';

    if (empty($init_secret) || $secret !== $init_secret) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    $u    = trim($_GET['username'] ?? '');
    $p    = $_GET['password'] ?? '';

    if (empty($u) || empty($p)) {
        echo json_encode(['error' => 'Username and password are required.']);
        exit;
    }

    // FIXED: Check if user exists first
    $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $check->execute([$u]);
    if ($check->fetchColumn() == 0) {
        echo json_encode(['error' => "User '$u' not found in database."]);
        exit;
    }

    $hash = password_hash($p, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE username = ?');
    $stmt->execute([$hash, $u]);
    echo json_encode(['success' => true, 'note' => 'Password hashed and saved. Set INIT_SECRET_USED=true in your Render environment to disable this endpoint.']);
    exit;
}

// ─── 2. UPDATE CREDENTIALS ───────────────────────────────────────────────────
if ($action === 'update_credentials') {
    $data  = json_decode(file_get_contents('php://input'), true);
    $old_u = trim($data['old_username'] ?? '');
    $new_u = trim($data['new_username'] ?? '');
    $new_p = $data['new_password'] ?? '';

    // FIXED: Validate all fields
    if (empty($old_u) || empty($new_u) || empty($new_p)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'All fields are required.']);
        exit;
    }

    // FIXED: Check old user exists
    $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $check->execute([$old_u]);
    if ($check->fetchColumn() == 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Original user not found.']);
        exit;
    }

    $hash = password_hash($new_p, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET username = ?, password = ? WHERE username = ?');
    $stmt->execute([$new_u, $hash, $old_u]);
    echo json_encode(['success' => true]);
    exit;
}

// ─── 3. EMULATED BLYNK API ───────────────────────────────────────────────────
if (isset($_GET['blynk_path'])) {
    $path  = $_GET['blynk_path'];
    // FIXED: Parse blynk_path more robustly
    $qpos    = strpos($path, '?');
    $command = $qpos !== false ? substr($path, 0, $qpos) : $path;
    $qs      = $qpos !== false ? substr($path, $qpos + 1) : '';
    parse_str($qs, $query_params);

    $pin_raw = $query_params['pin'] ?? '';
    $pin     = str_replace('V', '', $pin_raw); // 'V2' → '2'

    // FIXED: Validate pin is numeric
    if (!is_numeric($pin) && $pin !== '') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid pin.']);
        exit;
    }

    if ($command === 'update') {
        $val = $query_params['value'] ?? '0';

        // Fetch current value to detect real state change
        $stmt = $pdo->prepare('SELECT value FROM devices WHERE pin = ?');
        $stmt->execute([$pin]);
        $row = $stmt->fetch();
        $current_val = $row ? $row['value'] : null;

        // Update live device value (only if row exists)
        if ($row !== false) {
            $stmt = $pdo->prepare('UPDATE devices SET value = ? WHERE pin = ?');
            $stmt->execute([$val, $pin]);
        }

        // For virtual-only pins (like Master Toggle pin 10), check history
        if ($current_val === null) {
            $stmt = $pdo->prepare('SELECT value FROM device_history WHERE pin = ? ORDER BY timestamp DESC LIMIT 1');
            $stmt->execute([$pin]);
            $row2 = $stmt->fetch();
            if ($row2) $current_val = $row2['value'];
        }

        // Log only if state actually changed
        if ((string)$current_val !== (string)$val) {
            $stmt = $pdo->prepare('INSERT INTO device_history (pin, value) VALUES (?, ?)');
            $stmt->execute([$pin, $val]);
        }

        echo 'OK';

    } elseif ($command === 'get') {
        $stmt = $pdo->prepare('SELECT value FROM devices WHERE pin = ?');
        $stmt->execute([$pin]);
        $row = $stmt->fetch();
        echo $row ? $row['value'] : '0';
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown blynk command.']);
    }
    exit;
}

// ─── 4. GET DEVICE HISTORY ───────────────────────────────────────────────────
if ($action === 'get_history') {
    $stmt = $pdo->query(
        "SELECT h.timestamp,
                IF(h.pin = '10', 'Master Toggle', IFNULL(d.name, CONCAT('Device V', h.pin))) AS name,
                h.value
         FROM device_history h
         LEFT JOIN devices d ON h.pin = d.pin
         ORDER BY h.timestamp DESC
         LIMIT 100"
    );
    echo json_encode($stmt->fetchAll());
    exit;
}

// ─── 5. LOG POWER CONSUMPTION ────────────────────────────────────────────────
if ($action === 'log_power') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (isset($data['kwh'])) {
        $kwh = (float)$data['kwh'];
        // FIXED: Sanity check - reject negative or impossibly large values
        if ($kwh < 0 || $kwh > 1000) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid kWh value.']);
            exit;
        }
        $stmt = $pdo->prepare('INSERT INTO energy_log (watts_consumed) VALUES (?)');
        $stmt->execute([$kwh]);
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing kwh field.']);
    }
    exit;
}

// ─── 6. GET POWER STATS ──────────────────────────────────────────────────────
if ($action === 'get_power_stats') {
    $stats = ['today' => 0, 'week' => 0, 'month' => 0, 'overall' => 0];

    $queries = [
        'today'   => "SELECT SUM(watts_consumed) AS total FROM energy_log WHERE DATE(timestamp) = CURDATE()",
        'week'    => "SELECT SUM(watts_consumed) AS total FROM energy_log WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
        'month'   => "SELECT SUM(watts_consumed) AS total FROM energy_log WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
        'overall' => "SELECT SUM(watts_consumed) AS total FROM energy_log",
    ];

    foreach ($queries as $key => $sql) {
        $row = $pdo->query($sql)->fetch();
        $stats[$key] = (float)($row['total'] ?? 0);
    }

    echo json_encode($stats);
    exit;
}

// ─── 7. GET DAILY POWER HISTORY ──────────────────────────────────────────────
if ($action === 'get_power_history') {
    $stmt = $pdo->query(
        "SELECT DATE(timestamp) AS log_date, SUM(watts_consumed) AS total_kwh
         FROM energy_log
         GROUP BY DATE(timestamp)
         ORDER BY log_date DESC
         LIMIT 30"
    );
    echo json_encode($stmt->fetchAll());
    exit;
}

// ─── 8. HEALTH CHECK ─────────────────────────────────────────────────────────
// Visit: https://your-api.onrender.com/api.php?action=ping
// Use this to confirm the API is alive before debugging other issues.
if ($action === 'ping') {
    echo json_encode(['status' => 'ok', 'time' => date('c')]);
    exit;
}

// ─── Fallback ─────────────────────────────────────────────────────────────────
http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
