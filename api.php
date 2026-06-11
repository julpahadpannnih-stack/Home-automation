<?php
// api.php - Render-ready MySQL API Backend
// Changes from original:
//   1. DB credentials now read from environment variables (set in Render dashboard)
//   2. CORS headers added so the Static Site can call this Web Service
//   3. All queries converted to PDO prepared statements (prevents SQL injection)
//   4. Passwords stored and verified using password_hash / password_verify
//   5. login action no longer accepts plaintext password comparison

header('Content-Type: application/json');

// ─── CORS ────────────────────────────────────────────────────────────────────
// Replace the value below with your actual Render Static Site URL after deploy.
// Example: 'https://smarthome-frontend.onrender.com'
// During local testing you can set this to '*' temporarily.
$allowed_origin = getenv('FRONTEND_URL') ?: '*';
header("Access-Control-Allow-Origin: $allowed_origin");
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight – browsers send OPTIONS before the real request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── DATABASE CONNECTION (env vars set in Render dashboard) ──────────────────
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
    die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
}

$action = $_GET['action'] ?? '';

// ─── 1. LOGIN ─────────────────────────────────────────────────────────────────
// Passwords in the DB must be hashed with password_hash().
// Run the one-time migration query in your DB console:
//   UPDATE users SET password = '$2y$10$...' WHERE username = 'admin';
// Or use the /init_password helper endpoint below (remove after first use).
if ($action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    $u    = $data['username'] ?? '';
    $p    = $data['password'] ?? '';

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

// ─── 1b. ONE-TIME PASSWORD MIGRATION HELPER ───────────────────────────────────
// Visit: https://your-api.onrender.com/api.php?action=init_password&secret=CHANGE_ME&username=admin&password=yourpassword
// This hashes the password and saves it. DELETE this block after first use.
if ($action === 'init_password') {
    $secret = $_GET['secret'] ?? '';
    if ($secret !== (getenv('INIT_SECRET') ?: 'CHANGE_ME')) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $u    = $_GET['username'] ?? '';
    $p    = $_GET['password'] ?? '';
    $hash = password_hash($p, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE username = ?');
    $stmt->execute([$hash, $u]);
    echo json_encode(['success' => true, 'note' => 'Password hashed and saved. Remove this endpoint.']);
    exit;
}

// ─── 2. UPDATE CREDENTIALS ───────────────────────────────────────────────────
if ($action === 'update_credentials') {
    $data  = json_decode(file_get_contents('php://input'), true);
    $old_u = $data['old_username'] ?? '';
    $new_u = $data['new_username'] ?? '';
    $new_p = $data['new_password'] ?? '';

    $hash = password_hash($new_p, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET username = ?, password = ? WHERE username = ?');
    $stmt->execute([$new_u, $hash, $old_u]);
    echo json_encode(['success' => true]);
    exit;
}

// ─── 3. EMULATED BLYNK API ───────────────────────────────────────────────────
if (isset($_GET['blynk_path'])) {
    $path  = $_GET['blynk_path'];
    $parts = parse_url($path);
    $command = $parts['path'] ?? '';
    parse_str($parts['query'] ?? '', $query_params);

    $pin_raw = $query_params['pin'] ?? '';
    $pin     = str_replace('V', '', $pin_raw); // 'V2' → '2'

    if ($command === 'update') {
        $val = $query_params['value'] ?? '0';

        // Fetch current value to detect real state change
        $stmt = $pdo->prepare('SELECT value FROM devices WHERE pin = ?');
        $stmt->execute([$pin]);
        $row = $stmt->fetch();
        $current_val = $row ? $row['value'] : null;

        // Update live device value
        $stmt = $pdo->prepare('UPDATE devices SET value = ? WHERE pin = ?');
        $stmt->execute([$val, $pin]);

        // For virtual-only pins (like Master Toggle pin 10), check history
        if ($current_val === null) {
            $stmt = $pdo->prepare('SELECT value FROM device_history WHERE pin = ? ORDER BY timestamp DESC LIMIT 1');
            $stmt->execute([$pin]);
            $row = $stmt->fetch();
            if ($row) $current_val = $row['value'];
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
        $kwh  = (float)$data['kwh'];
        $stmt = $pdo->prepare('INSERT INTO energy_log (watts_consumed) VALUES (?)');
        $stmt->execute([$kwh]);
        echo json_encode(['success' => true]);
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

// ─── Fallback ─────────────────────────────────────────────────────────────────
http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
