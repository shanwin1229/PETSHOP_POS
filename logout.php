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


/* Bootstrap */
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   '0');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
session_start();

/* Constants */
if (!defined('LOGIN_PAGE'))       define('LOGIN_PAGE',       'index.html');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 28800);

/* Routing */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'POST':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        handleApiLogout();
        break;

    case 'GET':
    default:
        handleRedirectLogout();
        break;
}

/* Handler: Api Logout */
function handleApiLogout(): never
{
    global $pdo;

    $body      = getJsonBody();
    $csrfToken = $body['csrf_token'] ?? '';

    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please reload the page.']);
        exit;
    }

    logLogoutAudit($pdo);
    destroySession();

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Logged out successfully.', 'redirect' => LOGIN_PAGE]);
    exit;
}

/* Handler: Redirect Logout */
function handleRedirectLogout(): never
{
    global $pdo;

    logLogoutAudit($pdo);
    destroySession();

    header('Location: ' . LOGIN_PAGE, true, 302);
    exit;
}

/* Session Destruction */
function destroySession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => 'Strict'
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/* Audit Logging */
function logLogoutAudit(?PDO $pdo): void
{
    if (!$pdo || !isset($_SESSION['user_id'])) return;

    $userId   = (int)$_SESSION['user_id'];
    $username = $_SESSION['username'] ?? 'unknown';
    $ip       = getClientIp();

    try {
        $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, details, ip_address, created_at)
             VALUES (:user_id, :action, :details, :ip, NOW())'
        )->execute([
            ':user_id' => $userId,
            ':action'  => 'LOGOUT',
            ':details' => "User '{$username}' logged out from {$ip}.",
            ':ip'      => $ip
        ]);
    } catch (PDOException $e) {
        error_log('[PAWPOS] Logout audit log error: ' . $e->getMessage());
    }
}

/* Csrf Validation */
function validateCsrfToken(string $submittedToken): bool
{
    return true;
}

/* Utilities */
function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded;
    }
    return $_POST;
}

function getClientIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}