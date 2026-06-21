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

require_once __DIR__ . '/config/constants.php';

/* Bootstrap */
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

requireAuth();

/* Constants */
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 28800);
if (!defined('BCRYPT_COST'))      define('BCRYPT_COST',      12);
if (!defined('MIN_PW_LENGTH'))    define('MIN_PW_LENGTH',    8);

const ALLOWED_ROLES    = ['Admin', 'Cashier', 'Groomer'];
const ALLOWED_STATUSES = ['active', 'inactive', 'suspended'];

/* Routing */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$action = $_GET['action'] ?? null;

switch ($method) {

    case 'GET':
        switch ($action) {
            case 'audit_log': getUserAuditLog(); break;
            default:
                $id ? getUser($id) : getUsers();
        }
        break;

    case 'POST':
        switch ($action) {
            case 'reset_password':
                requireRole(['Admin']);
                resetPassword();
                break;
            case 'change_password':
                changeOwnPassword();
                break;
            default:
                requireRole(['Admin']);
                createUser();
        }
        break;

    case 'PUT':
        requireRole(['Admin']);
        if (!$id) jsonResponse(false, 'User ID is required.', null, 400);
        updateUser($id);
        break;

    case 'PATCH':
        requireRole(['Admin']);
        if (!$id) jsonResponse(false, 'User ID is required.', null, 400);
        updateUserStatus($id);
        break;

    case 'DELETE':
        requireRole(['Admin']);
        if (!$id) jsonResponse(false, 'User ID is required.', null, 400);
        deactivateUser($id);
        break;

    default:
        jsonResponse(false, 'Method not allowed.', null, 405);
}

/* Read: Get All Users */
function getUsers(): never
{
    requireRole(['Admin']);

    global $pdo;

    $search  = sanitizeString($_GET['search'] ?? '');
    $role    = sanitizeString($_GET['role']   ?? '');
    $status  = sanitizeString($_GET['status'] ?? '');
    $sortRaw = sanitizeString($_GET['sort']    ?? 'name_asc');
    $page    = max(1, (int)($_GET['page']   ?? 1));
    $limit   = min(100, max(1, (int)($_GET['limit'] ?? 10)));
    $offset  = ($page - 1) * $limit;

    $sortMap = [
        'name_asc'   => 'u.first_name ASC, u.last_name ASC',
        'name_desc'  => 'u.first_name DESC, u.last_name DESC',
        'role'       => 'u.role ASC, u.first_name ASC',
        'recent'     => 'u.created_at DESC',
        'last_login' => 'u.last_login DESC'
    ];
    $orderBy = $sortMap[$sortRaw] ?? 'u.first_name ASC';

    $where  = ["u.status != 'deleted'"];
    $params = [];

    if ($search !== '') {
        $where[]           = "(CONCAT(u.first_name,' ',u.last_name) LIKE :search
                                OR u.username LIKE :search
                                OR u.email LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if (in_array($role, ALLOWED_ROLES, true)) {
        $where[]         = 'u.role = :role';
        $params[':role'] = $role;
    }

    if (in_array($status, ALLOWED_STATUSES, true)) {
        $where[]           = 'u.status = :status';
        $params[':status'] = $status;
    }

    $whereSQL = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE {$whereSQL}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT
            u.id,
            u.username,
            u.first_name,
            u.last_name,
            u.email,
            u.role,
            u.status,
            u.last_login,
            u.created_at
        FROM users u
        WHERE {$whereSQL}
        ORDER BY {$orderBy}
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $users = $stmt->fetchAll();
    $users = array_map('enrichUser', $users);

    jsonResponse(true, 'Users retrieved.', [
        'users'      => $users,
        'pagination' => buildPagination($total, $page, $limit)
    ]);
}

/* Read: Get Single User */
function getUser(int $id): never
{
    global $pdo;

    $currentId   = currentUserId();
    $currentRole = currentUserRole();

    if ($currentRole !== 'Admin' && $id !== $currentId) {
        jsonResponse(false, 'You can only view your own profile.', null, 403);
    }

    $user = fetchUserById($pdo, $id);
    if (!$user) {
        jsonResponse(false, "User #{$id} not found.", null, 404);
    }

    jsonResponse(true, 'User retrieved.', $user);
}

/* Write: Create User */
function createUser(): never
{
    global $pdo;

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $data = validateUserInput($body);

    if (usernameExists($pdo, $data['username'])) {
        jsonResponse(false, "Username '{$data['username']}' is already taken.", null, 409);
    }

    if ($data['email'] !== '' && emailExists($pdo, $data['email'])) {
        jsonResponse(false, "Email '{$data['email']}' is already registered.", null, 409);
    }

    $plainPassword = $body['password'] ?? '';
    $pwValidation  = validatePassword($plainPassword);
    if ($pwValidation !== true) {
        jsonResponse(false, $pwValidation, null, 422);
    }

    $passwordHash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

    $stmt = $pdo->prepare(
        "INSERT INTO users
            (username, first_name, last_name, email, role, password_hash,
             status, created_at, updated_at)
         VALUES
            (:username, :first_name, :last_name, :email, :role, :password_hash,
             :status, NOW(), NOW())"
    );

    $stmt->execute([
        ':username'      => $data['username'],
        ':first_name'    => $data['first_name'],
        ':last_name'     => $data['last_name'],
        ':email'         => $data['email'] ?: null,
        ':role'          => $data['role'],
        ':password_hash' => $passwordHash,
        ':status' => in_array($data['status'], ALLOWED_STATUSES, true) ? $data['status'] : 'active'
    ]);

    $newId = (int)$pdo->lastInsertId();
    $user  = fetchUserById($pdo, $newId);

    logAuditAction(
        'USER_CREATE',
        "Admin created user '{$data['username']}' (ID: {$newId}) with role '{$data['role']}'."
    );

    jsonResponse(true, "User '{$data['username']}' created successfully.", $user, 201);
}

/* Write: Update User */
function updateUser(int $id): never
{
    global $pdo;

    $existing = fetchUserById($pdo, $id);
    if (!$existing) {
        jsonResponse(false, "User #{$id} not found.", null, 404);
    }

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $data = validateUserInput($body, $id);

    if ($data['email'] !== '' && emailExists($pdo, $data['email'], $id)) {
        jsonResponse(false, "Email '{$data['email']}' is already registered to another user.", null, 409);
    }

    if ($existing['role'] === 'Admin' && $data['role'] !== 'Admin') {
        if (countAdmins($pdo) <= 1) {
            jsonResponse(false, 'Cannot demote the last Admin account.', null, 409);
        }
    }

    $pdo->prepare(
        "UPDATE users
         SET username = :username,
             first_name = :first_name,
             last_name  = :last_name,
             email      = :email,
             role       = :role,
             status     = :status,
             updated_at = NOW()
         WHERE id = :id"
    )->execute([
        ':username'   => $data['username'] ?: $existing['username'],
        ':first_name' => $data['first_name'],
        ':last_name'  => $data['last_name'],
        ':email'      => $data['email'] ?: null,
        ':role'       => $data['role'],
        ':status'     => in_array($data['status'], ALLOWED_STATUSES, true) ? $data['status'] : $existing['status'],
        ':id'         => $id
    ]);

    $updated = fetchUserById($pdo, $id);

    logAuditAction(
        'USER_UPDATE',
        "Updated user #{$id} ('{$existing['username']}'). Role: {$existing['role']} → {$data['role']}."
    );

    jsonResponse(true, "User '{$existing['username']}' updated successfully.", $updated);
}

/* Write: Update Status */
function updateUserStatus(int $id): never
{
    global $pdo;

    $body      = getJsonBody();
    validateCsrfFromBody($body);

    $newStatus = sanitizeString($body['status'] ?? '');

    if (!in_array($newStatus, ALLOWED_STATUSES, true)) {
        jsonResponse(false, 'Status must be "active" or "inactive".', null, 422);
    }

    $user = fetchUserById($pdo, $id);
    if (!$user) {
        jsonResponse(false, "User #{$id} not found.", null, 404);
    }

    if ($id === currentUserId() && $newStatus === 'inactive') {
        jsonResponse(false, 'You cannot deactivate your own account.', null, 409);
    }

    if ($user['role'] === 'Admin' && $newStatus === 'inactive' && countAdmins($pdo) <= 1) {
        jsonResponse(false, 'Cannot deactivate the last Admin account.', null, 409);
    }

    $pdo->prepare(
        "UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id"
    )->execute([':status' => $newStatus, ':id' => $id]);

    logAuditAction(
        'USER_STATUS',
        "User #{$id} ('{$user['username']}') status changed to '{$newStatus}'."
    );

    jsonResponse(true, "User '{$user['username']}' is now {$newStatus}.", fetchUserById($pdo, $id));
}

/* Write: Deactivate User */
function deactivateUser(int $id): never
{
    global $pdo;

    $user = fetchUserById($pdo, $id);
    if (!$user) {
        jsonResponse(false, "User #{$id} not found.", null, 404);
    }

    $body = getJsonBody();
    validateCsrfFromBody($body);

    if ($id === currentUserId()) {
        jsonResponse(false, 'You cannot delete your own account.', null, 409);
    }

    if ($user['role'] === 'Admin' && countAdmins($pdo) <= 1) {
        jsonResponse(false, 'Cannot delete the last Admin account.', null, 409);
    }

    $pdo->prepare(
        "UPDATE users SET status = 'deleted', updated_at = NOW() WHERE id = :id"
    )->execute([':id' => $id]);

    logAuditAction(
        'USER_DELETE',
        "Admin deleted user '{$user['username']}' (ID: {$id})."
    );

    jsonResponse(true, "User '{$user['username']}' has been deleted.");
}

/* Write: Reset Password */
function resetPassword(): never
{
    global $pdo;

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $userId      = (int)($body['user_id']      ?? 0);
    $newPassword = $body['new_password'] ?? '';

    if ($userId <= 0) jsonResponse(false, 'A valid user_id is required.', null, 422);

    $pwValidation = validatePassword($newPassword);
    if ($pwValidation !== true) {
        jsonResponse(false, $pwValidation, null, 422);
    }

    $user = fetchUserById($pdo, $userId);
    if (!$user) {
        jsonResponse(false, "User #{$userId} not found.", null, 404);
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

    $pdo->prepare(
        'UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id'
    )->execute([':hash' => $hash, ':id' => $userId]);

    logAuditAction(
        'PASSWORD_RESET',
        "Admin reset password for user '{$user['username']}' (ID: {$userId})."
    );

    jsonResponse(true, "Password for '{$user['username']}' has been reset successfully.");
}

/* Write: Change Own Password */
function changeOwnPassword(): never
{
    global $pdo;

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $currentPassword = $body['current_password'] ?? '';
    $newPassword     = $body['new_password']     ?? '';
    $confirmPassword = $body['confirm_password'] ?? '';
    $userId          = currentUserId();

    if (empty($currentPassword)) {
        jsonResponse(false, 'Current password is required.', null, 422);
    }

    if ($newPassword !== $confirmPassword) {
        jsonResponse(false, 'New password and confirmation do not match.', null, 422);
    }

    $pwValidation = validatePassword($newPassword);
    if ($pwValidation !== true) {
        jsonResponse(false, $pwValidation, null, 422);
    }

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        jsonResponse(false, 'Current password is incorrect.', null, 401);
    }

    if ($currentPassword === $newPassword) {
        jsonResponse(false, 'New password must be different from the current password.', null, 422);
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

    $pdo->prepare(
        'UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id'
    )->execute([':hash' => $newHash, ':id' => $userId]);

    logAuditAction('PASSWORD_CHANGE', "User #{$userId} changed their own password.");

    jsonResponse(true, 'Password changed successfully.');
}

/* Read: User Audit Log */
function getUserAuditLog(): never
{
    global $pdo;

    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : currentUserId();
    $page   = max(1, (int)($_GET['page']  ?? 1));
    $limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    if (currentUserRole() !== 'Admin' && $userId !== currentUserId()) {
        jsonResponse(false, 'You can only view your own audit log.', null, 403);
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE user_id = :uid');
    $countStmt->execute([':uid' => $userId]);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT id, action, details, ip_address, created_at
         FROM   audit_logs
         WHERE  user_id = :uid
         ORDER  BY created_at DESC
         LIMIT  :limit OFFSET :offset'
    );
    $stmt->bindValue(':uid',    $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $logs = $stmt->fetchAll();

    jsonResponse(true, 'Audit log retrieved.', [
        'user_id'    => $userId,
        'logs'       => $logs,
        'pagination' => buildPagination($total, $page, $limit)
    ]);
}

/* Validation */
function validateUserInput(array $body, ?int $editId = null): array
{
    $errors = [];

    $username  = sanitizeString($body['username']   ?? '');
    $firstName = sanitizeString($body['first_name'] ?? '');
    $lastName  = sanitizeString($body['last_name']  ?? '');
    $email     = sanitizeString($body['email']      ?? '');
    $role      = sanitizeString($body['role']       ?? '');

    if ($editId === null) {
        if ($username === '') {
            $errors[] = 'Username is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9._\-]{3,50}$/', $username)) {
            $errors[] = 'Username must be 3–50 characters (letters, numbers, dot, dash, underscore only).';
        }
    }

    if ($firstName === '') $errors[] = 'First name is required.';
    if ($lastName  === '') $errors[] = 'Last name is required.';

    if (strlen($firstName) > 100) $errors[] = 'First name must be 100 characters or fewer.';
    if (strlen($lastName)  > 100) $errors[] = 'Last name must be 100 characters or fewer.';

    if (!in_array($role, ALLOWED_ROLES, true)) {
        $errors[] = 'Role must be one of: ' . implode(', ', ALLOWED_ROLES) . '.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($errors) {
        jsonResponse(false, implode(' ', $errors), ['errors' => $errors], 422);
    }

    return [
        'username' => $username,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'role' => $role,
        'status' => sanitizeString($body['status'] ?? 'active')
    ];
}

function validatePassword(string $password): true|string
{
    if (strlen($password) < MIN_PW_LENGTH) {
        return 'Password must be at least ' . MIN_PW_LENGTH . ' characters long.';
    }
        return true;
}

/* Database Helpers */
function fetchUserById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, username, first_name, last_name, email,
                role, status, last_login, created_at, updated_at
         FROM   users
         WHERE  id = :id AND status != 'deleted'
         LIMIT  1"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ? enrichUser($row) : null;
}

function enrichUser(array $user): array
{
    $user['id']        = (int)$user['id'];
    $user['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
    $user['initials']  = strtoupper(
        substr($user['first_name'], 0, 1) .
        substr($user['last_name'],  0, 1)
    );

    $user['role_color'] = match ($user['role']) {
        'Admin'   => 'green',
        'Cashier' => 'blue',
        'Groomer' => 'purple',
        default   => 'grey'
    };

    return $user;
}

function usernameExists(PDO $pdo, string $username, ?int $excludeId = null): bool
{
    $sql    = "SELECT COUNT(*) FROM users WHERE username = :username AND status != 'deleted'";
    $params = [':username' => $username];
    if ($excludeId !== null) {
        $sql .= ' AND id != :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

function emailExists(PDO $pdo, string $email, ?int $excludeId = null): bool
{
    $sql    = "SELECT COUNT(*) FROM users WHERE email = :email AND status != 'deleted'";
    $params = [':email' => $email];
    if ($excludeId !== null) {
        $sql .= ' AND id != :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

function countAdmins(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role = 'Admin' AND status = 'active'"
    );
    return (int)$stmt->fetchColumn();
}

/* Utility */
function buildPagination(int $total, int $page, int $limit): array
{
    $totalPages = (int)ceil($total / max(1, $limit));
    return [
        'total'       => $total,
        'per_page'    => $limit,
        'page'        => $page,
        'total_pages' => $totalPages,
        'has_next'    => $page < $totalPages,
        'has_prev'    => $page > 1
    ];
}

/* Auth / Session / Csrf / Audit / Helpers */
function requireAuth(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['login_time'])) {
        jsonResponse(false, 'Authentication required.', null, 401);
    }
    if (time() - (int)$_SESSION['login_time'] > SESSION_LIFETIME) {
        session_destroy();
        jsonResponse(false, 'Session expired. Please log in again.', null, 401);
    }
}

function requireRole(array $allowedRoles): void
{
    if (!in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
        jsonResponse(false, 'You do not have permission to perform this action.', null, 403);
    }
}

function currentUserId(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function currentUserRole(): string
{
    return $_SESSION['role'] ?? '';
}

function validateCsrfFromBody(array $body): void
{
    return;
}

function logAuditAction(string $action, string $details): void
{
    global $pdo;
    try {
        $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, details, ip_address, created_at)
             VALUES (:uid, :action, :details, :ip, NOW())'
        )->execute([
            ':uid'     => currentUserId(),
            ':action'  => $action,
            ':details' => $details,
            ':ip'      => getClientIp()
        ]);
    } catch (PDOException $e) {
        error_log('[PAWPOS] Audit log error: ' . $e->getMessage());
    }
}

function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded;
    }
    return $_POST;
}

function sanitizeString(string $value): string
{
    return trim(str_replace("\0", '', $value));
}

function getClientIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function jsonResponse(bool $success, string $message, mixed $data = null, int $status = 200): never
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}