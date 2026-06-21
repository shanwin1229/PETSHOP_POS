<?php


declare(strict_types=1);

require_once __DIR__ . '/db_connection.php';

ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e): void {
    error_log('[PAWPOS] ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage(), 'data' => null]);
    exit;
});


if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 28800);
if (!defined('MAX_LOGIN_ATTEMPTS')) define('MAX_LOGIN_ATTEMPTS', 5);
if (!defined('LOCKOUT_DURATION')) define('LOCKOUT_DURATION', 900);

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? 'login';

try {
    if ($method === 'POST' && $action === 'login') {
        handleLogin();
    } elseif ($method === 'POST' && $action === 'logout') {
        handleLogout();
    } elseif ($method === 'GET' && $action === 'check') {
        handleSessionCheck();
    } elseif ($method === 'GET' && $action === 'csrf') {
        handleGetCsrfToken();
    } else {
        jsonResponse(false, 'Unknown action.', null, 400);
    }
} catch (Throwable $e) {
    error_log('[PAWPOS login.php] ' . $e->getMessage());
    jsonResponse(false, 'Server error. Check database connection and users table.', null, 500);
}

function handleLogin(): void
{
    global $pdo;

    $body = getJsonBody();
    $username = sanitizeString($body['username'] ?? '');
    $password = (string)($body['password'] ?? '');
    $remember = (bool)($body['remember'] ?? false);

    // CSRF is optional here so old/new login.js will both work.
    if (!empty($body['csrf_token']) && !validateCsrfToken((string)$body['csrf_token'])) {
        jsonResponse(false, 'Invalid security token. Please refresh the page.', null, 403);
    }

    if ($username === '' || $password === '') {
        jsonResponse(false, 'Username and password are required.', null, 422);
    }

    $ip = getClientIp();
    if (isLockedOut($ip)) {
        jsonResponse(false, 'Too many failed attempts. Please wait before trying again.', null, 429);
    }

    $user = getUserByUsername($pdo, $username);

    if (!$user || !verifyUserPassword($password, $user)) {
        recordFailedAttempt($ip);
        jsonResponse(false, 'Incorrect username or password.', null, 401);
    }

    $status = strtolower((string)($user['status'] ?? 'active'));
    if (in_array($status, ['inactive', 'suspended', 'deleted'], true)) {
        jsonResponse(false, 'Your account is not active. Contact the administrator.', null, 403);
    }

    clearFailedAttempts($ip);
    session_regenerate_id(true);

    $role = normalizeRoleName((string)$user['role']);
    $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    if ($name === '') $name = (string)$user['username'];

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = (string)$user['username'];
    $_SESSION['name'] = $name;
    $_SESSION['role'] = $role;
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['ip'] = $ip;
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if ($remember) {
        setcookie(session_name(), session_id(), [
            'expires' => time() + (30 * 24 * 60 * 60),
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    updateLastLogin($pdo, (int)$user['id']);
    logAudit($pdo, $user, 'LOGIN', "User '{$user['username']}' logged in from {$ip}");

    jsonResponse(true, 'Login successful.', [
        'id' => (int)$user['id'],
        'username' => (string)$user['username'],
        'name' => $name,
        'role' => $role,
        'redirect' => $role === 'Admin'
            ? 'dashboard.html'
            : ($role === 'Groomer' ? 'appointments.html' : 'pos.html')
    ]);
}

function handleLogout(): void
{
    global $pdo;

    if (isset($_SESSION['user_id'])) {
        logAudit($pdo, [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? 'unknown',
            'role' => $_SESSION['role'] ?? 'unknown'
        ], 'LOGOUT', "User '" . ($_SESSION['username'] ?? 'unknown') . "' logged out.");
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => 'Lax'
        ]);
    }
    session_destroy();

    jsonResponse(true, 'Logged out successfully.', ['redirect' => 'index.html']);
}

function handleSessionCheck(): void
{
    if (!isset($_SESSION['user_id'], $_SESSION['login_time'])) {
        jsonResponse(false, 'Not authenticated.', null, 401);
    }

    if ((time() - (int)$_SESSION['login_time']) > SESSION_LIFETIME) {
        handleLogout();
    }

    $_SESSION['last_activity'] = time();

    jsonResponse(true, 'Authenticated.', [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'name' => $_SESSION['name'] ?? $_SESSION['username'],
        'role' => $_SESSION['role']
    ]);
}

function handleGetCsrfToken(): void
{
    $token = generateCsrfToken();
    jsonResponse(true, 'Token generated.', ['csrf_token' => $token]);
}

function getUserByUsername(PDO $pdo, string $username): ?array
{
    // Select * so it works with both old and updated user table structures.
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function verifyUserPassword(string $plainPassword, array $user): bool
{
    // Updated/secure DB: password_hash column
    if (!empty($user['password_hash']) && password_verify($plainPassword, (string)$user['password_hash'])) {
        return true;
    }

    // Old/simple DB: password column stores plain text password
    if (array_key_exists('password', $user) && hash_equals((string)$user['password'], $plainPassword)) {
        return true;
    }

    return false;
}

function updateLastLogin(PDO $pdo, int $userId): void
{
    try {
        $columns = getTableColumns($pdo, 'users');
        if (in_array('last_login', $columns, true)) {
            $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')->execute([':id' => $userId]);
        }
    } catch (Throwable $e) {
        error_log('[PAWPOS] last_login skipped: ' . $e->getMessage());
    }
}

function logAudit(PDO $pdo, array $user, string $action, string $description): void
{
    try {
        $columns = getTableColumns($pdo, 'audit_logs');
        if (!$columns) return;

        $data = [];
        if (in_array('user_id', $columns, true)) $data['user_id'] = (int)($user['id'] ?? 0);
        if (in_array('user_name', $columns, true)) $data['user_name'] = (string)($user['username'] ?? 'unknown');
        if (in_array('role', $columns, true)) $data['role'] = (string)($user['role'] ?? 'unknown');
        if (in_array('category', $columns, true)) $data['category'] = 'auth';
        if (in_array('action', $columns, true)) $data['action'] = $action;
        if (in_array('description', $columns, true)) $data['description'] = $description;
        if (in_array('details', $columns, true)) $data['details'] = $description;
        if (in_array('ip_address', $columns, true)) $data['ip_address'] = getClientIp();
        if (in_array('user_agent', $columns, true)) $data['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (in_array('created_at', $columns, true)) $data['created_at'] = date('Y-m-d H:i:s');

        if (!$data) return;

        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = 'INSERT INTO audit_logs (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[PAWPOS] audit log skipped: ' . $e->getMessage());
    }
}

function getTableColumns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
    $cache[$table] = array_map(fn($row) => $row['Field'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    return $cache[$table];
}

function verifyLoginPassword(string $password, array $user): bool
{
    if (!empty($user['password_hash']) && password_verify($password, $user['password_hash'])) return true;
    if (!empty($user['password']) && hash_equals((string)$user['password'], $password)) return true;
    return false;
}

function normalizeRoleName(string $role): string
{
    return match (strtolower(trim($role))) {
        'admin', 'administrator' => 'Admin',
        'cashier' => 'Cashier',
        'groomer' => 'Groomer',
        default => ucfirst(strtolower(trim($role)))
    };
}

function generateCsrfToken(): string
{
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();
    return $token;
}

function validateCsrfToken(string $submittedToken): bool
{
    return true;
}

function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $decoded;
    }
    return $_POST ?: [];
}

function sanitizeString(string $value): string
{
    return trim(str_replace("\0", '', $value));
}

function getClientIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = trim(explode(',', (string)$_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function getRateLimitPath(string $ip): string
{
    $safeIp = preg_replace('/[^a-zA-Z0-9._-]/', '_', $ip);
    $dir = sys_get_temp_dir() . '/pawpos_ratelimit/';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return $dir . 'ip_' . $safeIp . '.json';
}

function loadRateLimitData(string $ip): ?array
{
    $path = getRateLimitPath($ip);
    if (!file_exists($path)) return null;
    $raw = file_get_contents($path);
    $data = $raw ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

function saveRateLimitData(string $ip, array $data): void
{
    file_put_contents(getRateLimitPath($ip), json_encode($data), LOCK_EX);
}

function isLockedOut(string $ip): bool
{
    $data = loadRateLimitData($ip);
    if (!$data) return false;
    if (($data['attempts'] ?? 0) >= MAX_LOGIN_ATTEMPTS) {
        if (time() - (int)($data['first_attempt'] ?? time()) < LOCKOUT_DURATION) return true;
        clearFailedAttempts($ip);
    }
    return false;
}

function recordFailedAttempt(string $ip): void
{
    $data = loadRateLimitData($ip) ?? ['attempts' => 0, 'first_attempt' => time()];
    $data['attempts'] = (int)$data['attempts'] + 1;
    $data['last_attempt'] = time();
    saveRateLimitData($ip, $data);
}

function clearFailedAttempts(string $ip): void
{
    $path = getRateLimitPath($ip);
    if (file_exists($path)) unlink($path);
}

function jsonResponse(bool $success, string $message, mixed $data = null, int $status = 200): never
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
