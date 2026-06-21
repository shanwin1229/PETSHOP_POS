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
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

requireAuth();

/* Constants */
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 28800);

/* Routing */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$action = $_GET['action'] ?? null;

switch ($method) {
    case 'GET':
        if ($action === 'search') searchCustomers();
        $id ? getCustomer($id) : getCustomers();
        break;
    case 'POST':
        requireRole(['Admin', 'Cashier']);
        createCustomer();
        break;
    case 'PUT':
        requireRole(['Admin', 'Cashier']);
        if (!$id) jsonResponse(false, 'Customer ID is required.', null, 400);
        updateCustomer($id);
        break;
    case 'DELETE':
        requireRole(['Admin']);
        if (!$id) jsonResponse(false, 'Customer ID is required.', null, 400);
        deactivateCustomer($id);
        break;
    default:
        jsonResponse(false, 'Method not allowed.', null, 405);
}

/* Read */
function getCustomers(): never
{
    global $pdo;

    $search = sanitizeString($_GET['search'] ?? '');
    $status = sanitizeString($_GET['status'] ?? '');
    $sortRaw = sanitizeString($_GET['sort'] ?? 'name_asc');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $sortMap = [
        'name_asc' => 'c.first_name ASC, c.last_name ASC',
        'name_desc' => 'c.first_name DESC, c.last_name DESC',
        'spent_desc' => 'c.total_spent DESC',
        'recent' => 'c.updated_at DESC'
    ];
    $orderBy = $sortMap[$sortRaw] ?? $sortMap['name_asc'];

    $where = ["c.status != 'deleted'"];
    $params = [];

    if ($search !== '') {
        $where[] = "(CONCAT(c.first_name, ' ', c.last_name) LIKE :search OR c.contact LIKE :search OR c.email LIKE :search)";
        $params[':search'] = "%{$search}%";
    }
    if (in_array($status, ['active', 'inactive'], true)) {
        $where[] = 'c.status = :status';
        $params[':status'] = $status;
    }

    $whereSQL = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE {$whereSQL}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT c.id, c.first_name, c.last_name, c.contact, c.email, c.address, c.birthday,
               c.status, c.total_spent, c.joined, c.updated_at,
               COUNT(t.id) AS transaction_count
        FROM customers c
        LEFT JOIN transactions t ON t.customer_id = c.id AND t.status = 'completed'
        WHERE {$whereSQL}
        GROUP BY c.id
        ORDER BY {$orderBy}
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $key => $val) $stmt->bindValue($key, $val);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    jsonResponse(true, 'Customers retrieved.', [
        'customers' => array_map('enrichCustomer', $stmt->fetchAll()),
        'pagination' => buildPagination($total, $page, $limit)
    ]);
}

function getCustomer(int $id): never
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, contact, email, address, birthday, status, total_spent, joined, updated_at
        FROM customers
        WHERE id = :id AND status != 'deleted'
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $customer = $stmt->fetch();

    if (!$customer) jsonResponse(false, "Customer #{$id} not found.", null, 404);

    $txnStmt = $pdo->prepare("
        SELECT id, txn_no, total_amount, status, created_at
        FROM transactions
        WHERE customer_id = :id AND status = 'completed'
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $txnStmt->execute([':id' => $id]);
    $transactions = $txnStmt->fetchAll();

    foreach ($transactions as &$txn) $txn['total_amount'] = (float) $txn['total_amount'];
    unset($txn);

    $customer = enrichCustomer($customer);
    $customer['transactions'] = $transactions;
    jsonResponse(true, 'Customer retrieved.', $customer);
}

function searchCustomers(): never
{
    global $pdo;

    $query = sanitizeString($_GET['q'] ?? '');
    $limit = min(20, max(1, (int) ($_GET['limit'] ?? 10)));

    if (strlen($query) < 2) jsonResponse(true, 'Query too short.', ['customers' => []]);

    $stmt = $pdo->prepare("
        SELECT id, CONCAT(first_name, ' ', last_name) AS name, contact, email
        FROM customers
        WHERE status = 'active'
          AND (CONCAT(first_name, ' ', last_name) LIKE :q OR contact LIKE :q OR email LIKE :q)
        ORDER BY first_name ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':q', "%{$query}%");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $results = $stmt->fetchAll();
    jsonResponse(true, count($results) . ' customer(s) found.', ['customers' => $results]);
}

/* Write */
function createCustomer(): never
{
    global $pdo;

    $body = getJsonBody();
    validateCsrfFromBody($body);
    $data = validateCustomerInput($body);

    if (contactExists($pdo, $data['contact'])) {
        jsonResponse(false, "Contact number '{$data['contact']}' is already registered.", null, 409);
    }
    if ($data['email'] !== '' && emailExists($pdo, $data['email'])) {
        jsonResponse(false, "Email '{$data['email']}' is already registered.", null, 409);
    }

    $pdo->prepare("
        INSERT INTO customers (first_name, last_name, contact, email, address, birthday, status, total_spent, joined, created_at, updated_at)
        VALUES (:first_name, :last_name, :contact, :email, :address, :birthday, 'active', 0.00, CURDATE(), NOW(), NOW())
    ")->execute([
        ':first_name' => $data['first_name'],
        ':last_name' => $data['last_name'],
        ':contact' => $data['contact'],
        ':email' => $data['email'] ?: null,
        ':address' => $data['address'] ?: null,
        ':birthday' => $data['birthday'] ?: null
    ]);

    $newId = (int) $pdo->lastInsertId();
    logAuditAction('CUSTOMER_CREATE', "Registered customer '{$data['first_name']} {$data['last_name']}' (ID: {$newId}).");
    jsonResponse(true, 'Customer registered successfully.', fetchCustomerById($pdo, $newId), 201);
}

function updateCustomer(int $id): never
{
    global $pdo;

    $existing = fetchCustomerById($pdo, $id);
    if (!$existing) jsonResponse(false, "Customer #{$id} not found.", null, 404);

    $body = getJsonBody();
    validateCsrfFromBody($body);
    $data = validateCustomerInput($body, $id);

    if (contactExists($pdo, $data['contact'], $id)) {
        jsonResponse(false, "Contact number '{$data['contact']}' is already registered to another customer.", null, 409);
    }
    if ($data['email'] !== '' && emailExists($pdo, $data['email'], $id)) {
        jsonResponse(false, "Email '{$data['email']}' is already registered to another customer.", null, 409);
    }

    $pdo->prepare("
        UPDATE customers
        SET first_name = :first_name, last_name = :last_name, contact = :contact, email = :email,
            address = :address, birthday = :birthday, status = :status, updated_at = NOW()
        WHERE id = :id
    ")->execute([
        ':first_name' => $data['first_name'],
        ':last_name' => $data['last_name'],
        ':contact' => $data['contact'],
        ':email' => $data['email'] ?: null,
        ':address' => $data['address'] ?: null,
        ':birthday' => $data['birthday'] ?: null,
        ':status' => $data['status'],
        ':id' => $id
    ]);

    logAuditAction('CUSTOMER_UPDATE', "Updated customer '{$data['first_name']} {$data['last_name']}' (ID: {$id}).");
    jsonResponse(true, 'Customer updated successfully.', fetchCustomerById($pdo, $id));
}

function deactivateCustomer(int $id): never
{
    global $pdo;

    $customer = fetchCustomerById($pdo, $id);
    if (!$customer) jsonResponse(false, "Customer #{$id} not found.", null, 404);

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $pdo->prepare("UPDATE customers SET status = 'deleted', updated_at = NOW() WHERE id = :id")->execute([':id' => $id]);
    logAuditAction('CUSTOMER_DELETE', "Soft-deleted customer '{$customer['first_name']} {$customer['last_name']}' (ID: {$id}).");
    jsonResponse(true, "Customer '{$customer['first_name']} {$customer['last_name']}' archived.");
}

/* Validation */
function validateCustomerInput(array $body, ?int $editId = null): array
{
    $errors = [];
    $firstName = sanitizeString($body['first_name'] ?? '');
    $lastName = sanitizeString($body['last_name'] ?? '');
    $contact = sanitizeString($body['contact'] ?? '');
    $email = sanitizeString($body['email'] ?? '');
    $address = sanitizeString($body['address'] ?? '');
    $birthday = sanitizeString($body['birthday'] ?? '');
    $status = sanitizeString($body['status'] ?? 'active');

    if ($firstName === '') $errors[] = 'First name is required.';
    if ($lastName === '') $errors[] = 'Last name is required.';
    if ($contact === '') $errors[] = 'Contact number is required.';
    if (strlen($firstName) > 100) $errors[] = 'First name must be 100 characters or fewer.';
    if (strlen($lastName) > 100) $errors[] = 'Last name must be 100 characters or fewer.';
    if (strlen($contact) > 20) $errors[] = 'Contact number must be 20 characters or fewer.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($birthday !== '' && !isValidDate($birthday)) $errors[] = 'Birthday must be a valid date in YYYY-MM-DD format.';
    if (!in_array($status, ['active', 'inactive'], true)) $errors[] = 'Status must be active or inactive.';

    if ($errors) jsonResponse(false, implode(' ', $errors), ['errors' => $errors], 422);

    return compact('firstName', 'lastName') + [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'contact' => $contact,
        'email' => $email,
        'address' => $address,
        'birthday' => $birthday,
        'status' => $status
    ];
}

/* Database */
function fetchCustomerById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, contact, email, address, birthday, status, total_spent, joined, updated_at
        FROM customers
        WHERE id = :id AND status != 'deleted'
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ? enrichCustomer($row) : null;
}

function contactExists(PDO $pdo, string $contact, ?int $excludeId = null): bool
{
    $sql = "SELECT COUNT(*) FROM customers WHERE contact = :contact AND status != 'deleted'";
    $params = [':contact' => $contact];
    if ($excludeId !== null) {
        $sql .= ' AND id != :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function emailExists(PDO $pdo, string $email, ?int $excludeId = null): bool
{
    $sql = "SELECT COUNT(*) FROM customers WHERE email = :email AND status != 'deleted'";
    $params = [':email' => $email];
    if ($excludeId !== null) {
        $sql .= ' AND id != :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function enrichCustomer(array $customer): array
{
    $customer['id'] = (int) $customer['id'];
    $customer['total_spent'] = (float) ($customer['total_spent'] ?? 0);
    $customer['transaction_count'] = (int) ($customer['transaction_count'] ?? 0);
    $customer['full_name'] = trim($customer['first_name'] . ' ' . $customer['last_name']);
    return $customer;
}

/* Helpers */
function isValidDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function buildPagination(int $total, int $page, int $limit): array
{
    $totalPages = (int) ceil($total / max(1, $limit));
    return [
        'total' => $total,
        'per_page' => $limit,
        'page' => $page,
        'total_pages' => $totalPages,
        'has_next' => $page < $totalPages,
        'has_prev' => $page > 1
    ];
}

function requireAuth(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['login_time'])) {
        jsonResponse(false, 'Authentication required.', null, 401);
    }
    if ((time() - (int) $_SESSION['login_time']) > SESSION_LIFETIME) {
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

function validateCsrfFromBody(array $body): void
{
    return;
}

function logAuditAction(string $action, string $description): void
{
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, user_name, role, category, action, description, ip_address, user_agent, created_at)
            VALUES (:user_id, :user_name, :role, 'customers', :action, :description, :ip, :agent, NOW())
        ");
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'] ?? null,
            ':user_name' => $_SESSION['username'] ?? 'System',
            ':role' => $_SESSION['role'] ?? 'Unknown',
            ':action' => $action,
            ':description' => $description,
            ':ip' => getClientIp(),
            ':agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Throwable $e) {
        error_log('[PAWPOS] Audit log failed: ' . $e->getMessage());
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
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
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
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
