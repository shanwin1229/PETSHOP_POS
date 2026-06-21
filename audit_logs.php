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

if (file_exists(__DIR__ . '/config/constants.php')) { require_once __DIR__ . '/config/constants.php'; }

/* Bootstrap */
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   '0');
ini_set('session.cookie_samesite', 'Lax');
session_start();

requireAuth();
requireRole(['Admin']);

if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 28800);
function normalizeRoleName(string $role): string {
    $v = strtolower(trim($role));
    return match ($v) {
        'admin', 'administrator' => 'Admin',
        'cashier' => 'Cashier',
        'groomer' => 'Groomer',
        default => $role
    };
}

/* Category Metadata (Consistent Badges Across Api + Frontend) */
const CATEGORY_META = [
    'login'              => ['label' => 'Login',              'icon' => 'ti-login',               'bg' => '#e1f5ee', 'fg' => '#0f6e56'],
    'logout'             => ['label' => 'Logout',              'icon' => 'ti-logout',              'bg' => '#faeeda', 'fg' => '#854f0b'],
    'user_activity'      => ['label' => 'User Activity',       'icon' => 'ti-user',                'bg' => '#e6f1fb', 'fg' => '#185fa5'],
    'product_update'     => ['label' => 'Product Update',      'icon' => 'ti-box',                 'bg' => '#f1ebfb', 'fg' => '#6c3fc5'],
    'inventory_change'   => ['label' => 'Inventory Change',    'icon' => 'ti-building-warehouse',  'bg' => '#fbe9ef', 'fg' => '#963d5a'],
    'sale'               => ['label' => 'Sale',                'icon' => 'ti-receipt',             'bg' => '#e8f0ec', 'fg' => '#1a3a2e'],
    'appointment_update' => ['label' => 'Appointment Update',  'icon' => 'ti-calendar',            'bg' => '#e3f7ef', 'fg' => '#2f8f6f'],
    'search_filter'      => ['label' => 'Search / Filter',     'icon' => 'ti-search',              'bg' => '#f1efe8', 'fg' => '#5f5e5a'],
];
const DEFAULT_CATEGORY_META = ['label' => 'Other', 'icon' => 'ti-dots', 'bg' => '#f1efe8', 'fg' => '#5f5e5a'];
const VALID_ROLES = ['Admin', 'Cashier', 'Groomer'];

/* Request Routing */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    sendJson(false, 'Method not allowed.', null, 405);
}

$action = sanitizeString($_GET['action'] ?? 'list');

// Export bypasses the JSON envelope (streams a CSV file directly)
if ($action === 'export') {
    exportCsv(getFilters());
    exit;
}

switch ($action) {
    case 'list':  sendJson(true, 'Audit logs retrieved.',   getList(getFilters()));  break;
    case 'stats': sendJson(true, 'Audit stats retrieved.',  getStats());             break;
    case 'meta':  sendJson(true, 'Audit meta retrieved.',   getMeta());              break;
    default:      sendJson(false, 'Unknown action.', null, 400);
}

/* Filter Parsing */

/**
 * Reads & validates all supported query-string filters.
 *
 * @return array{category:string,search:string,role:string,from:string,to:string,page:int,per_page:int}
 */
function getFilters(): array
{
    $category = sanitizeString($_GET['category'] ?? 'all');
    if ($category !== 'all' && !array_key_exists($category, CATEGORY_META)) {
        $category = 'all';
    }

    $role = sanitizeString($_GET['role'] ?? '');
    if ($role !== '' && !in_array($role, VALID_ROLES, true)) {
        $role = '';
    }

    $search = mb_substr(sanitizeString($_GET['search'] ?? ''), 0, 120);

    $from = sanitizeString($_GET['from'] ?? date('Y-m-d', strtotime('-30 days')));
    $to   = sanitizeString($_GET['to']   ?? date('Y-m-d'));
    if (!isValidDate($from)) $from = date('Y-m-d', strtotime('-30 days'));
    if (!isValidDate($to))   $to   = date('Y-m-d');
    if ($from > $to) { [$from, $to] = [$to, $from]; }

    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = min(100, max(5, (int)($_GET['per_page'] ?? 20)));

    return compact('category', 'search', 'role', 'from', 'to', 'page', 'perPage');
}

function isValidDate(string $d): bool
{
    $parts = explode('-', $d);
    if (count($parts) !== 3) return false;
    return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}

/**
 * Builds the shared WHERE clause + bound params for list/export/stats-by-filter.
 *
 * @param array $f Filters from getFilters()
 * @return array{0:string,1:array}
 */
function buildWhere(array $f): array
{
    $where  = ['DATE(created_at) BETWEEN :from AND :to'];
    $params = [':from' => $f['from'], ':to' => $f['to']];

    if ($f['category'] !== 'all') {
        $where[] = 'category = :category';
        $params[':category'] = $f['category'];
    }

    if ($f['role'] !== '') {
        $where[] = 'role = :role';
        $params[':role'] = $f['role'];
    }

    if ($f['search'] !== '') {
        $where[] = '(user_name LIKE :search OR description LIKE :search OR action LIKE :search)';
        $params[':search'] = '%' . $f['search'] . '%';
    }

    return [implode(' AND ', $where), $params];
}

/* 1. List (Paginated Table) */

function getList(array $f): array
{
    global $pdo;
    [$whereSql, $params] = buildWhere($f);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $offset = ($f['page'] - 1) * $f['perPage'];

    $stmt = $pdo->prepare("
        SELECT id, user_id, user_name, role, category, action, description,
               entity_type, entity_id, ip_address, user_agent, meta, created_at
        FROM audit_logs
        WHERE {$whereSql}
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $f['perPage'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $logs = array_map('decorateLogRow', $rows);

    $totalPages = $total > 0 ? (int)ceil($total / $f['perPage']) : 1;

    return [
        'logs' => $logs,
        'pagination' => [
            'page'        => $f['page'],
            'per_page'    => $f['perPage'],
            'total'       => $total,
            'total_pages' => $totalPages,
        ],
        'filters' => [
            'category' => $f['category'],
            'search'   => $f['search'],
            'role'     => $f['role'],
            'from'     => $f['from'],
            'to'       => $f['to'],
        ],
    ];
}

/**
 * Attaches category badge metadata and safely decodes `meta` JSON.
 */
function decorateLogRow(array $row): array
{
    $catMeta = CATEGORY_META[$row['category']] ?? DEFAULT_CATEGORY_META;

    $meta = null;
    if (!empty($row['meta'])) {
        $decoded = json_decode($row['meta'], true);
        $meta = (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
    }

    return [
        'id'           => (int)$row['id'],
        'user_id'      => $row['user_id'] !== null ? (int)$row['user_id'] : null,
        'user_name'    => $row['user_name'],
        'role'         => $row['role'],
        'category'     => $row['category'],
        'category_label'=> $catMeta['label'],
        'category_icon' => $catMeta['icon'],
        'category_bg'   => $catMeta['bg'],
        'category_fg'   => $catMeta['fg'],
        'action'       => $row['action'],
        'description'  => $row['description'],
        'entity_type'  => $row['entity_type'],
        'entity_id'    => $row['entity_id'] !== null ? (int)$row['entity_id'] : null,
        'ip_address'   => $row['ip_address'],
        'user_agent'   => $row['user_agent'],
        'meta'         => $meta,
        'created_at'   => $row['created_at'],
    ];
}

/* 2. Stats (Today'S Summary Cards) */

function getStats(): array
{
    global $pdo;

    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(category = 'login')  AS logins,
            SUM(category IN ('product_update','inventory_change')) AS changes,
            SUM(category = 'sale')   AS sales,
            COUNT(DISTINCT user_id)  AS active_users
        FROM audit_logs
        WHERE DATE(created_at) = CURDATE()
    ");
    $row = $stmt->fetch();

    return [
        'cards' => [
            [
                'id' => 'total', 'label' => 'Events today',
                'value' => (int)$row['total'], 'icon' => 'ti-activity', 'cls' => 'total'
            ],
            [
                'id' => 'logins', 'label' => 'Logins today',
                'value' => (int)$row['logins'], 'icon' => 'ti-login', 'cls' => 'logins'
            ],
            [
                'id' => 'changes', 'label' => 'Data changes today',
                'value' => (int)$row['changes'], 'icon' => 'ti-edit', 'cls' => 'changes'
            ],
            [
                'id' => 'sales', 'label' => 'Sales logged today',
                'value' => (int)$row['sales'], 'icon' => 'ti-receipt', 'cls' => 'sales'
            ],
        ],
        'active_users_today' => (int)$row['active_users'],
    ];
}

/* 3. Meta (Category List + Roles, For Building Filter Ui) */

function getMeta(): array
{
    $categories = [];
    foreach (CATEGORY_META as $key => $m) {
        $categories[] = [
            'key'   => $key,
            'label' => $m['label'],
            'icon'  => $m['icon'],
            'bg'    => $m['bg'],
            'fg'    => $m['fg'],
        ];
    }

    return [
        'categories' => $categories,
        'roles'      => VALID_ROLES,
    ];
}

/* 4. Export (Csv Download, Bypasses Sendjson Envelope) */

function exportCsv(array $f): void
{
    global $pdo;
    [$whereSql, $params] = buildWhere($f);

    // Safety cap so a huge unfiltered export can't exhaust memory
    $stmt = $pdo->prepare("
        SELECT created_at, user_name, role, category, action, description,
               entity_type, entity_id, ip_address
        FROM audit_logs
        WHERE {$whereSql}
        ORDER BY created_at DESC
        LIMIT 5000
    ");
    $stmt->execute($params);

    $filename = 'pawpos_audit_logs_' . date('Y-m-d_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp', 'User', 'Role', 'Category', 'Action', 'Description', 'Entity Type', 'Entity ID', 'IP Address']);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $catLabel = CATEGORY_META[$row['category']]['label'] ?? $row['category'];
        fputcsv($out, [
            $row['created_at'],
            $row['user_name'],
            $row['role'],
            $catLabel,
            $row['action'],
            $row['description'],
            $row['entity_type'],
            $row['entity_id'],
            $row['ip_address'],
        ]);
    }

    fclose($out);
}

/* Auth / Session / Helpers */

function requireAuth(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['login_time'])) {
        sendJson(false, 'Authentication required.', null, 401);
    }
    if ((time() - (int)$_SESSION['login_time']) > SESSION_LIFETIME) {
        session_destroy();
        sendJson(false, 'Session expired. Please log in again.', null, 401);
    }
}

function requireRole(array $allowedRoles): void
{
    if (!in_array(normalizeRoleName((string)($_SESSION['role'] ?? '')), $allowedRoles, true)) {
        sendJson(false, 'You do not have permission to view audit logs.', null, 403);
    }
}

function sanitizeString(string $value): string
{
    return trim(str_replace("\0", '', $value));
}

function sendJson(bool $success, string $message, mixed $data = null, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}