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

const PAYMENT_TERMS = [
    'COD',
    'Net 7',
    'Net 15',
    'Net 30',
    'Net 60',
    'Prepaid',
    'Consignment'
];

/* Routing */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$action = $_GET['action'] ?? null;

switch ($method) {

    case 'GET':
        switch ($action) {
            case 'search': searchSuppliers(); break;
            default:
                $id ? getSupplier($id) : getSuppliers();
        }
        break;

    case 'POST':
        requireRole(['Admin']);
        switch ($action) {
            case 'link_products': linkProducts();   break;
            default:              createSupplier(); break;
        }
        break;

    case 'PUT':
        requireRole(['Admin']);
        if (!$id) jsonResponse(false, 'Supplier ID is required.', null, 400);
        updateSupplier($id);
        break;

    case 'DELETE':
        requireRole(['Admin']);
        switch ($action) {
            case 'unlink_product': unlinkProduct(); break;
            default:
                if (!$id) jsonResponse(false, 'Supplier ID is required.', null, 400);
                deactivateSupplier($id);
        }
        break;

    default:
        jsonResponse(false, 'Method not allowed.', null, 405);
}

/* Read: Get All Suppliers */
function getSuppliers(): never
{
    global $pdo;

    $search       = sanitizeString($_GET['search']        ?? '');
    $status       = sanitizeString($_GET['status']        ?? '');
    $paymentTerms = sanitizeString($_GET['payment_terms'] ?? '');
    $sortRaw      = sanitizeString($_GET['sort']          ?? 'name_asc');
    $page         = max(1, (int)($_GET['page']  ?? 1));
    $limit        = min(1000, max(1, (int)($_GET['limit'] ?? 10)));
    $offset       = ($page - 1) * $limit;

    $sortMap = [
        'name_asc'  => 's.name ASC',
        'name_desc' => 's.name DESC',
        'recent'    => 's.created_at DESC'
    ];
    $orderBy = $sortMap[$sortRaw] ?? 's.name ASC';

    $where  = ["s.status != 'deleted'"];
    $params = [];

    if ($search !== '') {
        $where[]           = "(s.name LIKE :search
                                OR s.contact_person LIKE :search
                                OR s.email LIKE :search
                                OR s.phone LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if (in_array($status, ['active', 'inactive'], true)) {
        $where[]          = 's.status = :status';
        $params[':status'] = $status;
    }

    if ($paymentTerms !== '') {
        $where[]                  = 's.payment_terms = :payment_terms';
        $params[':payment_terms'] = $paymentTerms;
    }

    $whereSQL = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM suppliers s WHERE {$whereSQL}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT
            s.id,
            s.name,
            s.contact_person,
            s.phone,
            s.email,
            s.address,
            s.website,
            s.payment_terms,
            s.notes,
            s.status,
            s.created_at,
            s.updated_at,
            COUNT(sp.product_id) AS product_count
        FROM suppliers s
        LEFT JOIN supplier_products sp ON sp.supplier_id = s.id
        WHERE {$whereSQL}
        GROUP BY s.id
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

    $suppliers = $stmt->fetchAll();
    $suppliers = array_map('enrichSupplier', $suppliers);

    jsonResponse(true, 'Suppliers retrieved.', [
        'suppliers'  => $suppliers,
        'pagination' => buildPagination($total, $page, $limit)
    ]);
}

/* Read: Get Single Supplier */
function getSupplier(int $id): never
{
    global $pdo;

    $stmt = $pdo->prepare(
        "SELECT s.id, s.name, s.contact_person, s.phone, s.email,
                s.address, s.website, s.payment_terms, s.notes,
                s.status, s.created_at, s.updated_at
         FROM   suppliers s
         WHERE  s.id = :id AND s.status != 'deleted'
         LIMIT  1"
    );
    $stmt->execute([':id' => $id]);
    $supplier = $stmt->fetch();

    if (!$supplier) {
        jsonResponse(false, "Supplier #{$id} not found.", null, 404);
    }

    $prodStmt = $pdo->prepare(
        "SELECT
            p.id,
            p.name,
            p.sku,
            p.category,
            p.selling_price,
            p.cost_price,
            p.stock_qty,
            sp.is_primary,
            sp.linked_at
         FROM supplier_products sp
         JOIN products p ON p.id = sp.product_id
         WHERE sp.supplier_id = :sid
           AND p.status != 'inactive'
         ORDER BY sp.is_primary DESC, p.name ASC"
    );
    $prodStmt->execute([':sid' => $id]);
    $products = $prodStmt->fetchAll();

    foreach ($products as &$prod) {
        $prod['id']            = (int)$prod['id'];
        $prod['selling_price'] = (float)$prod['selling_price'];
        $prod['cost_price']    = (float)$prod['cost_price'];
        $prod['stock_qty']     = (int)$prod['stock_qty'];
        $prod['is_primary']    = (bool)$prod['is_primary'];
    }
    unset($prod);

    $histStmt = $pdo->prepare(
        "SELECT
            sh.id,
            sh.product_id,
            p.name AS product_name,
            sh.quantity,
            sh.stock_before,
            sh.stock_after,
            sh.remarks,
            sh.created_at
         FROM stock_history sh
         JOIN products p ON p.id = sh.product_id
         WHERE sh.supplier = :supplier_name
           AND sh.type = 'in'
         ORDER BY sh.created_at DESC
         LIMIT 5"
    );
    $histStmt->execute([':supplier_name' => $supplier['name']]);
    $recentDeliveries = $histStmt->fetchAll();

    $supplier = enrichSupplier($supplier);
    $supplier['products']          = $products;
    $supplier['product_count']     = count($products);
    $supplier['recent_deliveries'] = $recentDeliveries;

    jsonResponse(true, 'Supplier retrieved.', $supplier);
}

/* Read: Quick Search */
function searchSuppliers(): never
{
    global $pdo;

    $query = sanitizeString($_GET['q']     ?? '');
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));

    if (strlen($query) < 2) {
        jsonResponse(true, 'Query too short.', ['suppliers' => []]);
    }

    $stmt = $pdo->prepare(
        "SELECT id, name, contact_person, phone, email, payment_terms
         FROM   suppliers
         WHERE  status = 'active'
           AND (name   LIKE :q
                OR contact_person LIKE :q
                OR email LIKE :q)
         ORDER  BY name ASC
         LIMIT  :limit"
    );
    $stmt->bindValue(':q',    '%' . $query . '%');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $results = $stmt->fetchAll();
    foreach ($results as &$r) {
        $r['id'] = (int)$r['id'];
    }
    unset($r);

    jsonResponse(true, count($results) . ' supplier(s) found.', ['suppliers' => $results]);
}

/* Write: Create Supplier */
function createSupplier(): never
{
    global $pdo;

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $data = validateSupplierInput($body);

    if (supplierNameExists($pdo, $data['name'])) {
        jsonResponse(false, "Supplier '{$data['name']}' already exists.", null, 409);
    }

    if ($data['email'] !== '' && supplierEmailExists($pdo, $data['email'])) {
        jsonResponse(false, "Email '{$data['email']}' is already registered to another supplier.", null, 409);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO suppliers
            (name, contact_person, phone, email, address,
             website, payment_terms, notes, status, created_at, updated_at)
         VALUES
            (:name, :contact_person, :phone, :email, :address,
             :website, :payment_terms, :notes, 'active', NOW(), NOW())"
    );

    $stmt->execute([
        ':name'           => $data['name'],
        ':contact_person' => $data['contact_person'] ?: null,
        ':phone'          => $data['phone']          ?: null,
        ':email'          => $data['email']          ?: null,
        ':address'        => $data['address']        ?: null,
        ':website'        => $data['website']        ?: null,
        ':payment_terms'  => $data['payment_terms']  ?: null,
        ':notes'          => $data['notes']          ?: null
    ]);

    $newId    = (int)$pdo->lastInsertId();
    $supplier = fetchSupplierById($pdo, $newId);

    logAuditAction(
        'SUPPLIER_CREATE',
        "Created supplier '{$data['name']}' (ID: {$newId})."
    );

    jsonResponse(true, "Supplier '{$data['name']}' created successfully.", $supplier, 201);
}

/* Write: Update Supplier */
function updateSupplier(int $id): never
{
    global $pdo;

    $existing = fetchSupplierById($pdo, $id);
    if (!$existing) {
        jsonResponse(false, "Supplier #{$id} not found.", null, 404);
    }

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $data = validateSupplierInput($body, $id);

    if (supplierNameExists($pdo, $data['name'], $id)) {
        jsonResponse(false, "Supplier name '{$data['name']}' is already used by another supplier.", null, 409);
    }

    if ($data['email'] !== '' && supplierEmailExists($pdo, $data['email'], $id)) {
        jsonResponse(false, "Email '{$data['email']}' is already registered to another supplier.", null, 409);
    }

    $pdo->prepare(
        "UPDATE suppliers
         SET name           = :name,
             contact_person = :contact_person,
             phone          = :phone,
             email          = :email,
             address        = :address,
             website        = :website,
             payment_terms  = :payment_terms,
             notes          = :notes,
             status         = :status,
             updated_at     = NOW()
         WHERE id = :id"
    )->execute([
        ':name'           => $data['name'],
        ':contact_person' => $data['contact_person'] ?: null,
        ':phone'          => $data['phone']          ?: null,
        ':email'          => $data['email']          ?: null,
        ':address'        => $data['address']        ?: null,
        ':website'        => $data['website']        ?: null,
        ':payment_terms'  => $data['payment_terms']  ?: null,
        ':notes'          => $data['notes']          ?: null,
        ':status'         => $data['status'],
        ':id'             => $id
    ]);

    $updated = fetchSupplierById($pdo, $id);

    logAuditAction(
        'SUPPLIER_UPDATE',
        "Updated supplier '{$data['name']}' (ID: {$id})."
    );

    jsonResponse(true, "Supplier '{$data['name']}' updated successfully.", $updated);
}

/* Write: Deactivate Supplier */
function deactivateSupplier(int $id): never
{
    global $pdo;

    $supplier = fetchSupplierById($pdo, $id);
    if (!$supplier) {
        jsonResponse(false, "Supplier #{$id} not found.", null, 404);
    }

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $pdo->prepare(
        "UPDATE suppliers SET status = 'deleted', updated_at = NOW() WHERE id = :id"
    )->execute([':id' => $id]);

    logAuditAction(
        'SUPPLIER_DELETE',
        "Soft-deleted supplier '{$supplier['name']}' (ID: {$id})."
    );

    jsonResponse(true, "Supplier '{$supplier['name']}' archived.");
}

/* Write: Link Products */
function linkProducts(): never
{
    global $pdo;

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $supplierId = (int)($body['supplier_id'] ?? 0);
    $productIds = $body['product_ids']        ?? [];
    $isPrimary  = (bool)($body['is_primary']  ?? false);
    $replace    = (bool)($body['replace']     ?? false);

    if ($supplierId <= 0) {
        jsonResponse(false, 'A valid supplier_id is required.', null, 422);
    }
    if (empty($productIds) || !is_array($productIds)) {
        jsonResponse(false, 'product_ids must be a non-empty array.', null, 422);
    }

    $suppCheck = $pdo->prepare(
        "SELECT id, name FROM suppliers WHERE id = :id AND status != 'deleted' LIMIT 1"
    );
    $suppCheck->execute([':id' => $supplierId]);
    $supplier = $suppCheck->fetch();
    if (!$supplier) {
        jsonResponse(false, "Supplier #{$supplierId} not found.", null, 404);
    }

    $validIds = [];
    foreach ($productIds as $pid) {
        $pid = (int)$pid;
        if ($pid <= 0) continue;

        $check = $pdo->prepare(
            "SELECT id FROM products WHERE id = :id AND status != 'deleted' LIMIT 1"
        );
        $check->execute([':id' => $pid]);
        if ($check->fetch()) {
            $validIds[] = $pid;
        }
    }

    if (empty($validIds)) {
        jsonResponse(false, 'No valid product IDs provided.', null, 422);
    }

    try {
        db_transaction(function (PDO $pdo) use ($supplierId, $validIds, $isPrimary, $replace) {
            if ($replace) {
                $pdo->prepare('DELETE FROM supplier_products WHERE supplier_id = :sid')
                    ->execute([':sid' => $supplierId]);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO supplier_products (supplier_id, product_id, is_primary, linked_at)
                 VALUES (:sid, :pid, :primary, NOW())
                 ON DUPLICATE KEY UPDATE is_primary = :primary, linked_at = NOW()"
            );

            foreach ($validIds as $pid) {
                $stmt->execute([
                    ':sid'     => $supplierId,
                    ':pid'     => $pid,
                    ':primary' => (int)$isPrimary
                ]);
            }
        });
    } catch (Throwable $e) {
        error_log('[PAWPOS] Link products error: ' . $e->getMessage());
        jsonResponse(false, 'Failed to link products. Please try again.', null, 500);
    }

    logAuditAction(
        'SUPPLIER_LINK_PRODUCTS',
        "Linked " . count($validIds) . " product(s) to supplier '{$supplier['name']}' (ID: {$supplierId})."
    );

    jsonResponse(true, count($validIds) . " product(s) linked to '{$supplier['name']}'.", [
        'supplier_id'  => $supplierId,
        'linked_count' => count($validIds),
        'product_ids'  => $validIds
    ]);
}

/* Write: Unlink Product */
function unlinkProduct(): never
{
    global $pdo;

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $supplierId = (int)($body['supplier_id'] ?? 0);
    $productId  = (int)($body['product_id']  ?? 0);

    if ($supplierId <= 0) jsonResponse(false, 'supplier_id is required.', null, 422);
    if ($productId  <= 0) jsonResponse(false, 'product_id is required.',  null, 422);

    $stmt = $pdo->prepare(
        'DELETE FROM supplier_products WHERE supplier_id = :sid AND product_id = :pid'
    );
    $stmt->execute([':sid' => $supplierId, ':pid' => $productId]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(false, 'Link not found between this supplier and product.', null, 404);
    }

    logAuditAction(
        'SUPPLIER_UNLINK_PRODUCT',
        "Unlinked product #{$productId} from supplier #{$supplierId}."
    );

    jsonResponse(true, "Product #{$productId} unlinked from supplier #{$supplierId}.");
}

/* Validation */
function validateSupplierInput(array $body, ?int $editId = null): array
{
    $errors = [];

    $name          = sanitizeString($body['name']           ?? '');
    $contactPerson = sanitizeString($body['contact_person'] ?? '');
    $phone         = sanitizeString($body['phone']          ?? '');
    $email         = sanitizeString($body['email']          ?? '');
    $address       = sanitizeString($body['address']        ?? '');
    $website       = sanitizeString($body['website']        ?? '');
    $paymentTerms  = sanitizeString($body['payment_terms']  ?? '');
    $notes         = sanitizeString($body['notes']          ?? '');
    $status        = sanitizeString($body['status']         ?? 'active');

    if ($name === '') {
        $errors[] = 'Supplier name is required.';
    } elseif (strlen($name) > 200) {
        $errors[] = 'Supplier name must be 200 characters or fewer.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
        $errors[] = 'Please enter a valid website URL (e.g. https://example.com).';
    }

    if ($paymentTerms !== '' && !in_array($paymentTerms, PAYMENT_TERMS, true)) {
        $errors[] = 'Invalid payment terms. Allowed: ' . implode(', ', PAYMENT_TERMS) . '.';
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        $errors[] = 'Status must be "active" or "inactive".';
    }

    if ($errors) {
        jsonResponse(false, implode(' ', $errors), ['errors' => $errors], 422);
    }

    return [
        'name' => $name,
        'contact_person' => $contactPerson,
        'phone' => $phone,
        'email' => $email,
        'address' => $address,
        'website' => $website,
        'payment_terms' => $paymentTerms,
        'notes' => $notes,
        'status' => $status
    ];
}

/* Database Helpers */
function fetchSupplierById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT s.id, s.name, s.contact_person, s.phone, s.email,
                s.address, s.website, s.payment_terms, s.notes,
                s.status, s.created_at, s.updated_at,
                COUNT(sp.product_id) AS product_count
         FROM   suppliers s
         LEFT   JOIN supplier_products sp ON sp.supplier_id = s.id
         WHERE  s.id = :id AND s.status != 'deleted'
         GROUP  BY s.id
         LIMIT  1"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ? enrichSupplier($row) : null;
}

function supplierNameExists(PDO $pdo, string $name, ?int $excludeId = null): bool
{
    $sql    = "SELECT COUNT(*) FROM suppliers WHERE name = :name AND status != 'deleted'";
    $params = [':name' => $name];

    if ($excludeId !== null) {
        $sql .= ' AND id != :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

function supplierEmailExists(PDO $pdo, string $email, ?int $excludeId = null): bool
{
    $sql    = "SELECT COUNT(*) FROM suppliers WHERE email = :email AND status != 'deleted'";
    $params = [':email' => $email];

    if ($excludeId !== null) {
        $sql .= ' AND id != :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

function enrichSupplier(array $supplier): array
{
    $supplier['id']            = (int)$supplier['id'];
    $supplier['product_count'] = (int)($supplier['product_count'] ?? 0);

    $parts = array_filter([
        $supplier['contact_person'] ?? '',
        $supplier['phone'] ?? ''
    ]);
    $supplier['display_contact'] = implode(' · ', $parts);

    return $supplier;
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
