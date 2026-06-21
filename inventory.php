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
if (!defined('LOW_STOCK_THRESHOLD')) define('LOW_STOCK_THRESHOLD', 10);
if (!defined('EXPIRY_WARN_DAYS'))    define('EXPIRY_WARN_DAYS',    60);

/* Routing */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$action = $_GET['action'] ?? null;

switch ($method) {

    case 'GET':
        switch ($action) {
            case 'low':      getLowStockItems();     break;
            case 'expiring': getExpiringItems();     break;
            case 'history':  getStockHistory();      break;
            case 'summary':  getInventorySummary();  break;
            default:
                $id ? getInventoryItem($id) : getAllInventory();
        }
        break;

    case 'POST':
        requireRole(['Admin', 'Cashier']);
        switch ($action) {
            case 'stock_in':  handleStockIn();  break;
            case 'stock_out': handleStockOut(); break;
            default: jsonResponse(false, 'Unknown POST action.', null, 400);
        }
        break;

    case 'PUT':
        requireRole(['Admin']);
        if (!$id) jsonResponse(false, 'Inventory item ID is required.', null, 400);
        updateInventoryItem($id);
        break;

    default:
        jsonResponse(false, 'Method not allowed.', null, 405);
}

/* Read: Get All Inventory */
function getAllInventory(): never
{
    global $pdo;

    $search   = sanitizeString($_GET['search']   ?? '');
    $category = sanitizeString($_GET['category'] ?? '');
    $status   = sanitizeString($_GET['status']   ?? '');
    $page     = max(1, (int)($_GET['page']  ?? 1));
    $limit    = min(1000, max(1, (int)($_GET['limit'] ?? 15)));
    $offset   = ($page - 1) * $limit;

    $where  = ['p.status != \'deleted\''];
    $params = [];

    if ($search !== '') {
        $where[]           = '(p.name LIKE :search OR p.sku LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    if ($category !== '') {
        $where[]              = 'p.category = :category';
        $params[':category'] = $category;
    }

    $whereSQL = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$whereSQL}");
    $countStmt->execute($params);
    $totalItems = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT
            p.id,
            p.name,
            p.sku,
            p.category,
            p.stock_qty,
            p.reorder_level,
            p.expiry_date,
            p.cost_price,
            p.selling_price,
            p.status,
            p.updated_at
        FROM products p
        WHERE {$whereSQL}
        ORDER BY p.name ASC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = $stmt->fetchAll();

    if ($status !== '') {
        $items = array_values(array_filter($items, function ($item) use ($status) {
            return computeStockStatus($item) === $status;
        }));
    }

    $items = array_map('enrichInventoryItem', $items);

    jsonResponse(true, 'Inventory retrieved.', [
        'items'      => $items,
        'pagination' => buildPagination($totalItems, $page, $limit)
    ]);
}

/* Read: Get Single Inventory Item */
function getInventoryItem(int $id): never
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.sku, p.category, p.stock_qty, p.reorder_level,
                p.expiry_date, p.cost_price, p.selling_price, p.status, p.updated_at
         FROM   products p
         WHERE  p.id = :id AND p.status != \'deleted\'
         LIMIT  1'
    );
    $stmt->execute([':id' => $id]);
    $item = $stmt->fetch();

    if (!$item) {
        jsonResponse(false, "Inventory item #{$id} not found.", null, 404);
    }

    jsonResponse(true, 'Item retrieved.', enrichInventoryItem($item));
}

/* Read: Low Stock Items */
function getLowStockItems(): never
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.sku, p.category, p.stock_qty,
                p.reorder_level, p.expiry_date, p.cost_price, p.selling_price, p.status
         FROM   products p
         WHERE  p.status != \'deleted\'
           AND  (p.stock_qty = 0
                 OR (p.reorder_level > 0 AND p.stock_qty <= p.reorder_level))
         ORDER  BY p.stock_qty ASC, p.name ASC'
    );
    $stmt->execute();
    $items = $stmt->fetchAll();
    $items = array_map('enrichInventoryItem', $items);

    jsonResponse(true, 'Low stock items retrieved.', [
        'items' => $items,
        'count' => count($items)
    ]);
}

/* Read: Expiring Items */
function getExpiringItems(): never
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.sku, p.category, p.stock_qty,
                p.reorder_level, p.expiry_date, p.cost_price, p.selling_price, p.status
         FROM   products p
         WHERE  p.status != \'deleted\'
           AND  p.expiry_date IS NOT NULL
           AND  p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL :warn_days DAY)
         ORDER  BY p.expiry_date ASC'
    );
    $stmt->execute([':warn_days' => EXPIRY_WARN_DAYS]);

    $items = $stmt->fetchAll();
    $items = array_map('enrichInventoryItem', $items);

    jsonResponse(true, 'Expiring items retrieved.', [
        'items' => $items,
        'count' => count($items)
    ]);
}

/* Read: Stock Movement History */
function getStockHistory(): never
{
    global $pdo;

    $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
    $type      = sanitizeString($_GET['type'] ?? '');
    $page      = max(1, (int)($_GET['page']  ?? 1));
    $limit     = min(1000, max(1, (int)($_GET['limit'] ?? 20)));
    $offset    = ($page - 1) * $limit;

    $where  = ['1 = 1'];
    $params = [];

    if ($productId !== null) {
        $where[]              = 'sh.product_id = :product_id';
        $params[':product_id'] = $productId;
    }

    if (in_array($type, ['in', 'out', 'adj'], true)) {
        $where[]       = 'sh.type = :type';
        $params[':type'] = $type;
    }

    $whereSQL = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_history sh WHERE {$whereSQL}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT
            sh.id,
            sh.product_id,
            p.name        AS product_name,
            p.sku         AS product_sku,
            sh.type,
            sh.quantity,
            sh.supplier,
            sh.reason,
            sh.remarks,
            sh.stock_before,
            sh.stock_after,
            sh.created_by,
            u.first_name  AS user_first,
            u.last_name   AS user_last,
            sh.created_at
        FROM stock_history sh
        JOIN products p ON p.id = sh.product_id
        LEFT JOIN users u ON u.id = sh.created_by
        WHERE {$whereSQL}
        ORDER BY sh.created_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $history = $stmt->fetchAll();

    foreach ($history as &$row) {
        $row['user_name']    = trim(($row['user_first'] ?? '') . ' ' . ($row['user_last'] ?? ''));
        $row['quantity']     = (int)$row['quantity'];
        $row['stock_before'] = (int)$row['stock_before'];
        $row['stock_after']  = (int)$row['stock_after'];
        unset($row['user_first'], $row['user_last']);
    }
    unset($row);

    jsonResponse(true, 'Stock history retrieved.', [
        'history'    => $history,
        'pagination' => buildPagination($total, $page, $limit)
    ]);
}

/* Read: Inventory Summary */
function getInventorySummary(): never
{
    global $pdo;

    $stmt = $pdo->query(
        "SELECT
            COUNT(*)                                                        AS total_skus,
            SUM(CASE WHEN stock_qty = 0 THEN 1 ELSE 0 END)                AS out_of_stock,
            SUM(CASE WHEN reorder_level > 0
                      AND stock_qty > 0
                      AND stock_qty <= reorder_level THEN 1 ELSE 0 END)   AS low_stock,
            SUM(CASE WHEN expiry_date IS NOT NULL
                      AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL " . (int)EXPIRY_WARN_DAYS . " DAY)
                      THEN 1 ELSE 0 END)                                  AS expiring_soon
         FROM products
         WHERE status != 'deleted'"
    );

    $summary = $stmt->fetch();

    jsonResponse(true, 'Inventory summary retrieved.', [
        'total_skus'    => (int)$summary['total_skus'],
        'out_of_stock'  => (int)$summary['out_of_stock'],
        'low_stock'     => (int)$summary['low_stock'],
        'expiring_soon' => (int)$summary['expiring_soon']
    ]);
}

/* Write: Stock In */
function handleStockIn(): never
{
    global $pdo;

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $productId  = (int)($body['product_id'] ?? 0);
    $quantity   = (int)($body['quantity']   ?? 0);
    $supplier   = sanitizeString($body['supplier']    ?? '');
    $expiryDate = sanitizeString($body['expiry_date'] ?? '');
    $remarks    = sanitizeString($body['remarks']     ?? '');

    if ($productId <= 0) jsonResponse(false, 'A valid product_id is required.', null, 422);
    if ($quantity  <= 0) jsonResponse(false, 'Quantity must be a positive integer.', null, 422);
    if ($expiryDate !== '' && !isValidDate($expiryDate)) {
        jsonResponse(false, 'expiry_date must be in YYYY-MM-DD format.', null, 422);
    }

    try {
        $result = db_transaction(function (PDO $pdo) use (
            $productId, $quantity, $supplier, $expiryDate, $remarks
        ) {
            $stmt = $pdo->prepare(
                'SELECT id, name, stock_qty FROM products WHERE id = :id FOR UPDATE'
            );
            $stmt->execute([':id' => $productId]);
            $product = $stmt->fetch();

            if (!$product) {
                throw new InvalidArgumentException("Product #{$productId} not found.");
            }

            $stockBefore = (int)$product['stock_qty'];
            $stockAfter  = $stockBefore + $quantity;

            $updateParams = [':stock' => $stockAfter, ':id' => $productId];
            $updateSQL    = 'UPDATE products SET stock_qty = :stock, updated_at = NOW()';

            if ($expiryDate !== '') {
                $updateSQL                .= ', expiry_date = :expiry';
                $updateParams[':expiry']  = $expiryDate;
            }

            $updateSQL .= ' WHERE id = :id';
            $pdo->prepare($updateSQL)->execute($updateParams);

            $pdo->prepare(
                'INSERT INTO stock_history
                    (product_id, type, quantity, stock_before, stock_after,
                     supplier, reason, remarks, created_by, created_at)
                 VALUES
                    (:product_id, \'in\', :qty, :before, :after,
                     :supplier, \'\', :remarks, :user_id, NOW())'
            )->execute([
                ':product_id' => $productId,
                ':qty'        => $quantity,
                ':before'     => $stockBefore,
                ':after'      => $stockAfter,
                ':supplier'   => $supplier ?: null,
                ':remarks'    => $remarks  ?: null,
                ':user_id'    => currentUserId()
            ]);

            return [
                'product_id'   => $productId,
                'product_name' => $product['name'],
                'quantity_in'  => $quantity,
                'stock_before' => $stockBefore,
                'stock_after'  => $stockAfter
            ];
        });

        logAuditAction(
            'STOCK_IN',
            "Stock in: +{$quantity} units for product #{$productId}. New stock: {$result['stock_after']}."
        );

        jsonResponse(true, "Added {$quantity} unit(s) to '{$result['product_name']}'.", $result, 201);

    } catch (InvalidArgumentException $e) {
        jsonResponse(false, $e->getMessage(), null, 404);
    } catch (Throwable $e) {
        error_log('[PAWPOS] Stock in error: ' . $e->getMessage());
        jsonResponse(false, 'Stock in failed. Please try again.', null, 500);
    }
}

/* Write: Stock Out */
function handleStockOut(): never
{
    global $pdo;

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $productId = (int)($body['product_id'] ?? 0);
    $quantity  = (int)($body['quantity']   ?? 0);
    $reason    = sanitizeString($body['reason']  ?? '');
    $remarks   = sanitizeString($body['remarks'] ?? '');

    $allowedReasons = [
        'Damaged / expired',
        'Internal use',
        'Return to supplier',
        'Theft / loss',
        'Manual adjustment'
    ];

    if ($productId <= 0) jsonResponse(false, 'A valid product_id is required.', null, 422);
    if ($quantity  <= 0) jsonResponse(false, 'Quantity must be a positive integer.', null, 422);
    if (!in_array($reason, $allowedReasons, true)) {
        jsonResponse(false, 'A valid reason for stock out is required.', ['allowed_reasons' => $allowedReasons], 422);
    }

    try {
        $result = db_transaction(function (PDO $pdo) use ($productId, $quantity, $reason, $remarks) {
            $stmt = $pdo->prepare(
                'SELECT id, name, stock_qty FROM products WHERE id = :id FOR UPDATE'
            );
            $stmt->execute([':id' => $productId]);
            $product = $stmt->fetch();

            if (!$product) {
                throw new InvalidArgumentException("Product #{$productId} not found.");
            }

            $stockBefore = (int)$product['stock_qty'];

            if ($quantity > $stockBefore) {
                throw new RangeException(
                    "Cannot deduct {$quantity} units — only {$stockBefore} in stock."
                );
            }

            $stockAfter = $stockBefore - $quantity;

            $pdo->prepare(
                'UPDATE products SET stock_qty = :stock, updated_at = NOW() WHERE id = :id'
            )->execute([':stock' => $stockAfter, ':id' => $productId]);

            $pdo->prepare(
                'INSERT INTO stock_history
                    (product_id, type, quantity, stock_before, stock_after,
                     supplier, reason, remarks, created_by, created_at)
                 VALUES
                    (:product_id, \'out\', :qty, :before, :after,
                     NULL, :reason, :remarks, :user_id, NOW())'
            )->execute([
                ':product_id' => $productId,
                ':qty'        => $quantity,
                ':before'     => $stockBefore,
                ':after'      => $stockAfter,
                ':reason'     => $reason,
                ':remarks'    => $remarks ?: null,
                ':user_id'    => currentUserId()
            ]);

            return [
                'product_id'   => $productId,
                'product_name' => $product['name'],
                'quantity_out' => $quantity,
                'stock_before' => $stockBefore,
                'stock_after'  => $stockAfter
            ];
        });

        logAuditAction(
            'STOCK_OUT',
            "Stock out: -{$quantity} units for product #{$productId}. Reason: {$reason}. New stock: {$result['stock_after']}."
        );

        jsonResponse(true, "Deducted {$quantity} unit(s) from '{$result['product_name']}'.", $result, 201);

    } catch (InvalidArgumentException $e) {
        jsonResponse(false, $e->getMessage(), null, 404);
    } catch (RangeException $e) {
        jsonResponse(false, $e->getMessage(), null, 422);
    } catch (Throwable $e) {
        error_log('[PAWPOS] Stock out error: ' . $e->getMessage());
        jsonResponse(false, 'Stock out failed. Please try again.', null, 500);
    }
}

/* Write: Update Inventory Item */
function updateInventoryItem(int $id): never
{
    global $pdo;

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $checkStmt = $pdo->prepare(
        "SELECT id FROM products WHERE id = :id AND status != 'deleted' LIMIT 1"
    );
    $checkStmt->execute([':id' => $id]);
    if (!$checkStmt->fetch()) {
        jsonResponse(false, "Inventory item #{$id} not found.", null, 404);
    }

    $errors = [];

    $reorderLevel = isset($body['reorder_level']) ? (int)$body['reorder_level'] : null;
    $expiryDate   = isset($body['expiry_date'])   ? sanitizeString($body['expiry_date']) : null;

    if ($reorderLevel !== null && $reorderLevel < 0) {
        $errors[] = 'Reorder level cannot be negative.';
    }

    if ($expiryDate !== null && $expiryDate !== '' && !isValidDate($expiryDate)) {
        $errors[] = 'expiry_date must be in YYYY-MM-DD format.';
    }

    if ($errors) {
        jsonResponse(false, implode(' ', $errors), ['errors' => $errors], 422);
    }

    $setClauses = ['updated_at = NOW()'];
    $params     = [':id' => $id];

    if ($reorderLevel !== null) {
        $setClauses[]             = 'reorder_level = :reorder_level';
        $params[':reorder_level'] = $reorderLevel;
    }

    if ($expiryDate !== null) {
        $setClauses[]           = 'expiry_date = :expiry_date';
        $params[':expiry_date'] = $expiryDate !== '' ? $expiryDate : null;
    }

    $setSQL = implode(', ', $setClauses);
    $pdo->prepare("UPDATE products SET {$setSQL} WHERE id = :id")->execute($params);

    $stmt = $pdo->prepare(
        'SELECT id, name, sku, category, stock_qty, reorder_level, expiry_date,
                cost_price, selling_price, status, updated_at
         FROM   products
         WHERE  id = :id'
    );
    $stmt->execute([':id' => $id]);
    $updated = $stmt->fetch();

    logAuditAction('INVENTORY_UPDATE', "Updated inventory item #{$id} (reorder/expiry).");

    jsonResponse(true, 'Inventory item updated.', enrichInventoryItem($updated));
}

/* Stock Computation Helpers */
function computeStockStatus(array $item): string
{
    $stock   = (int)$item['stock_qty'];
    $reorder = (int)$item['reorder_level'];

    if ($stock === 0)                        return 'out';
    if ($reorder > 0 && $stock <= $reorder) return 'low';
    return 'ok';
}

function computeExpiryStatus(array $item): string
{
    $expiry = $item['expiry_date'] ?? null;
    if (!$expiry) return 'none';

    $daysLeft = (int)ceil((strtotime($expiry) - time()) / 86400);
    if ($daysLeft < 0)                 return 'expired';
    if ($daysLeft <= EXPIRY_WARN_DAYS) return 'expiring';
    return 'ok';
}

function enrichInventoryItem(array $item): array
{
    $item['stock_status']  = computeStockStatus($item);
    $item['expiry_status'] = computeExpiryStatus($item);

    $expiry = $item['expiry_date'] ?? null;
    $item['days_to_expiry'] = $expiry
        ? (int)ceil((strtotime($expiry) - time()) / 86400)
        : null;

    $item['id']            = (int)$item['id'];
    $item['stock_qty']     = (int)$item['stock_qty'];
    $item['reorder_level'] = (int)$item['reorder_level'];
    $item['cost_price']    = (float)($item['cost_price']    ?? 0);
    $item['selling_price'] = (float)($item['selling_price'] ?? 0);

    return $item;
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
    if ($age > (defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 28800)) {
        session_destroy();
        jsonResponse(false, 'Session expired. Please log in again.', null, 401);
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
