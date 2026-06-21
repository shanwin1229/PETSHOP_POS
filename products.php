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

/* Routing */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$action = $_GET['action'] ?? null;

if ($action === 'categories') {
    getCategories();
    exit;
}

switch ($method) {
    case 'GET':
        $id ? getProduct($id) : getProducts();
        break;

    case 'POST':
        requireRole(['Admin']);
        createProduct();
        break;

    case 'PUT':
        requireRole(['Admin']);
        if (!$id) jsonResponse(false, 'Product ID is required.', null, 400);
        updateProduct($id);
        break;

    case 'DELETE':
        requireRole(['Admin']);
        if (!$id) jsonResponse(false, 'Product ID is required.', null, 400);
        deleteProduct($id);
        break;

    default:
        jsonResponse(false, 'Method not allowed.', null, 405);
}

/* Read: Get All Products */
function getProducts(): never
{
    global $pdo;

    $search   = sanitizeString($_GET['search']   ?? '');
    $category = sanitizeString($_GET['category'] ?? '');
    $status   = sanitizeString($_GET['status']   ?? '');
    $sortRaw  = sanitizeString($_GET['sort']      ?? 'name_asc');
    $page     = max(1, (int)($_GET['page']  ?? 1));
    $limit    = min(1000, max(1, (int)($_GET['limit'] ?? 10)));
    $offset   = ($page - 1) * $limit;

    $sortMap = [
        'name_asc'    => 'p.name ASC',
        'name_desc'   => 'p.name DESC',
        'price_asc'   => 'p.selling_price ASC',
        'price_desc'  => 'p.selling_price DESC',
        'stock_asc'   => 'p.stock_qty ASC',
        'stock_desc'  => 'p.stock_qty DESC',
        'created_desc'=> 'p.created_at DESC'
    ];
    $orderBy = $sortMap[$sortRaw] ?? 'p.name ASC';

    $where  = ["p.status != 'deleted'"];
    $params = [];

    if ($search !== '') {
        $where[]           = '(p.name LIKE :search OR p.sku LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    if ($category !== '') {
        $where[]             = 'p.category = :category';
        $params[':category'] = $category;
    }

    if ($status !== '') {
        $where[]           = 'p.status = :status';
        $params[':status'] = $status;
    }

    $whereSQL = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$whereSQL}");
    $countStmt->execute($params);
    $totalItems = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT
            p.id, p.name, p.sku, p.category,
            p.selling_price, p.cost_price, p.stock_qty,
            p.reorder_level, p.expiry_date, p.status,
            p.description, p.created_at, p.updated_at
        FROM   products p
        WHERE  {$whereSQL}
        ORDER  BY {$orderBy}
        LIMIT  :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $products   = array_map('enrichProduct', $stmt->fetchAll());
    $totalPages = (int)ceil($totalItems / $limit);

    jsonResponse(true, 'Products retrieved.', [
        'products'   => $products,
        'pagination' => [
            'total'       => $totalItems,
            'per_page'    => $limit,
            'page'        => $page,
            'total_pages' => $totalPages,
            'has_next'    => $page < $totalPages,
            'has_prev'    => $page > 1
        ]
    ]);
}

/* Read: Get Single Product */
function getProduct(int $id): never
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT id, name, sku, category, selling_price, cost_price,
                stock_qty, reorder_level, expiry_date, status, description,
                created_at, updated_at
         FROM   products
         WHERE  id = :id
         LIMIT  1'
    );
    $stmt->execute([':id' => $id]);
    $product = $stmt->fetch();

    if (!$product) {
        jsonResponse(false, "Product #{$id} not found.", null, 404);
    }

    jsonResponse(true, 'Product retrieved.', enrichProduct($product));
}

/* Read: Get Categories */
function getCategories(): never
{
    global $pdo;

    $stmt = $pdo->query(
        "SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND status != 'deleted' ORDER BY category ASC"
    );
    jsonResponse(true, 'Categories retrieved.', ['categories' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
}

/* Write: Create Product */
function createProduct(): never
{
    global $pdo;

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $data = validateProductInput($body);

    if ($data['sku'] !== '' && skuExists($pdo, $data['sku'])) {
        jsonResponse(false, "SKU '{$data['sku']}' is already in use.", null, 409);
    }

    $pdo->prepare(
        'INSERT INTO products
            (name, sku, category, selling_price, cost_price,
             stock_qty, reorder_level, expiry_date, status, description,
             created_by, created_at, updated_at)
         VALUES
            (:name, :sku, :category, :selling_price, :cost_price,
             :stock_qty, :reorder_level, :expiry_date, :status, :description,
             :created_by, NOW(), NOW())'
    )->execute([
        ':name'          => $data['name'],
        ':sku'           => $data['sku']         ?: null,
        ':category'      => $data['category'],
        ':selling_price' => $data['selling_price'],
        ':cost_price'    => $data['cost_price'],
        ':stock_qty'     => $data['stock_qty'],
        ':reorder_level' => $data['reorder_level'],
        ':expiry_date'   => $data['expiry_date'] ?: null,
        ':status'        => $data['status'],
        ':description'   => $data['description'] ?: null,
        ':created_by'    => currentUserId()
    ]);

    $newId   = (int)$pdo->lastInsertId();
    $product = fetchProductById($pdo, $newId);

    logAuditAction('PRODUCT_CREATE', "Created product '{$data['name']}' (ID: {$newId})");

    jsonResponse(true, 'Product created successfully.', $product, 201);
}

/* Write: Update Product */
function updateProduct(int $id): never
{
    global $pdo;

    $existing = fetchProductById($pdo, $id);
    if (!$existing) {
        jsonResponse(false, "Product #{$id} not found.", null, 404);
    }

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $data = validateProductInput($body, $id);

    if ($data['sku'] !== '' && skuExists($pdo, $data['sku'], $id)) {
        jsonResponse(false, "SKU '{$data['sku']}' is already used by another product.", null, 409);
    }

    $pdo->prepare(
        'UPDATE products
         SET    name          = :name,
                sku           = :sku,
                category      = :category,
                selling_price = :selling_price,
                cost_price    = :cost_price,
                stock_qty     = :stock_qty,
                reorder_level = :reorder_level,
                expiry_date   = :expiry_date,
                status        = :status,
                description   = :description,
                updated_at    = NOW()
         WHERE  id = :id'
    )->execute([
        ':name'          => $data['name'],
        ':sku'           => $data['sku']         ?: null,
        ':category'      => $data['category'],
        ':selling_price' => $data['selling_price'],
        ':cost_price'    => $data['cost_price'],
        ':stock_qty'     => $data['stock_qty'],
        ':reorder_level' => $data['reorder_level'],
        ':expiry_date'   => $data['expiry_date'] ?: null,
        ':status'        => $data['status'],
        ':description'   => $data['description'] ?: null,
        ':id'            => $id
    ]);

    $updated = fetchProductById($pdo, $id);

    logAuditAction('PRODUCT_UPDATE', "Updated product '{$data['name']}' (ID: {$id})");

    jsonResponse(true, 'Product updated successfully.', $updated);
}

/* Write: Delete Product */
function deleteProduct(int $id): never
{
    global $pdo;

    $product = fetchProductById($pdo, $id);
    if (!$product) {
        jsonResponse(false, "Product #{$id} not found.", null, 404);
    }

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $pdo->prepare(
        "UPDATE products SET status = 'deleted', updated_at = NOW() WHERE id = :id"
    )->execute([':id' => $id]);

    logAuditAction('PRODUCT_DELETE', "Deleted product '{$product['name']}' (ID: {$id})");

    jsonResponse(true, "Product '{$product['name']}' deleted successfully.");
}

/* Validation */
function validateProductInput(array $body, ?int $editId = null): array
{
    $errors = [];

    $name = sanitizeString($body['name'] ?? '');
    if ($name === '') {
        $errors[] = 'Product name is required.';
    } elseif (strlen($name) > 200) {
        $errors[] = 'Product name must be 200 characters or fewer.';
    }

    $category = sanitizeString($body['category'] ?? '');
    if ($category === '') $errors[] = 'Category is required.';

    $sellingPrice = round((float)($body['selling_price'] ?? 0), 2);
    $costPrice    = round((float)($body['cost_price']    ?? 0), 2);
    if ($sellingPrice < 0) $errors[] = 'Selling price cannot be negative.';
    if ($costPrice    < 0) $errors[] = 'Cost price cannot be negative.';

    $stockQty     = (int)($body['stock_qty']     ?? 0);
    $reorderLevel = (int)($body['reorder_level'] ?? 0);
    if ($stockQty     < 0) $errors[] = 'Stock quantity cannot be negative.';
    if ($reorderLevel < 0) $errors[] = 'Reorder level cannot be negative.';

    $sku = sanitizeString($body['sku'] ?? '');
    if (strlen($sku) > 100) $errors[] = 'SKU must be 100 characters or fewer.';

    $expiryDate = sanitizeString($body['expiry_date'] ?? '');
    if ($expiryDate !== '' && !isValidDate($expiryDate)) {
        $errors[] = 'Expiry date must be a valid date in YYYY-MM-DD format.';
    }

    $status = sanitizeString($body['status'] ?? 'active');
    if (!in_array($status, ['active', 'inactive'], true)) {
        $errors[] = 'Status must be "active" or "inactive".';
    }

    $description = sanitizeString($body['description'] ?? '');

    if ($errors) {
        jsonResponse(false, implode(' ', $errors), ['errors' => $errors], 422);
    }

    return [
        'name' => $name,
        'sku' => $sku,
        'category' => $category,
        'selling_price' => $sellingPrice,
        'cost_price' => $costPrice,
        'stock_qty' => $stockQty,
        'reorder_level' => $reorderLevel,
        'expiry_date' => $expiryDate,
        'status' => $status,
        'description' => $description
    ];
}

/* Database Helpers */
function fetchProductById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, sku, category, selling_price, cost_price,
                stock_qty, reorder_level, expiry_date, status, description,
                created_at, updated_at
         FROM   products
         WHERE  id = :id
         LIMIT  1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ? enrichProduct($row) : null;
}

function skuExists(PDO $pdo, string $sku, ?int $excludeId = null): bool
{
    $sql    = 'SELECT COUNT(*) FROM products WHERE sku = :sku';
    $params = [':sku' => $sku];

    if ($excludeId !== null) {
        $sql .= ' AND id != :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

function enrichProduct(array $product): array
{
    $stock   = (int)$product['stock_qty'];
    $reorder = (int)$product['reorder_level'];
    $status  = $product['status'];

    if ($status === 'inactive') {
        $computed = 'inactive';
    } elseif ($stock === 0) {
        $computed = 'out';
    } elseif ($reorder > 0 && $stock <= $reorder) {
        $computed = 'low';
    } else {
        $computed = 'active';
    }

    $product['computed_status'] = $computed;

    $expiry = $product['expiry_date'];
    if (!$expiry) {
        $product['expiry_status']  = 'none';
        $product['days_to_expiry'] = null;
    } else {
        $daysLeft = (int)ceil((strtotime($expiry) - time()) / 86400);
        $product['days_to_expiry'] = $daysLeft;
        $product['expiry_status']  = $daysLeft < 0 ? 'expired' : ($daysLeft <= 60 ? 'expiring' : 'ok');
    }

    $product['id']            = (int)$product['id'];
    $product['selling_price'] = (float)$product['selling_price'];
    $product['cost_price']    = (float)$product['cost_price'];
    $product['stock_qty']     = (int)$product['stock_qty'];
    $product['reorder_level'] = (int)$product['reorder_level'];

    return $product;
}

function isValidDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/* Auth / Session */
function requireAuth(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['login_time'])) {
        jsonResponse(false, 'Authentication required.', null, 401);
    }
    $age = time() - (int)$_SESSION['login_time'];
    if ($age > (SESSION_LIFETIME ?? 28800)) {
        session_destroy();
        jsonResponse(false, 'Your session has expired. Please log in again.', null, 401);
    }
}

function requireRole(array $allowedRoles): void
{
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, $allowedRoles, true)) {
        jsonResponse(false, 'You do not have permission to perform this action.', null, 403);
    }
}

function currentUserId(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

/* Csrf / Audit / Utility */
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
             VALUES (:user_id, :action, :details, :ip, NOW())'
        )->execute([
            ':user_id' => currentUserId(),
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
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
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
