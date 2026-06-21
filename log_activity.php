<?php


declare(strict_types=1);

// Block direct browser access — this file should only ever be included.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Make sure a $pdo connection exists even if this file is included
// before the caller has set one up.
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $__log_activity_db = __DIR__ . '/db_connection.php';
    if (is_file($__log_activity_db)) {
        require_once $__log_activity_db;
    }
}

/* Valid Categories (Must Match Category_Meta In Audit_Logs.Php) */
if (!defined('AUDIT_LOG_CATEGORIES')) {
    define('AUDIT_LOG_CATEGORIES', [
        'login', 'logout', 'user_activity', 'product_update',
        'inventory_change', 'sale', 'appointment_update', 'search_filter',
    ]);
}

/* Core Writer */

if (!function_exists('logActivity')) {
    /**
     * Inserts one row into audit_logs. Safe to call anywhere —
     * never throws; returns false (and logs to error_log) on failure.
     *
     * @param string      $category    One of AUDIT_LOG_CATEGORIES
     * @param string      $action      Short label, e.g. "Updated product"
     * @param string|null $description Longer human-readable detail
     * @param array       $opts {
     *   @type string|null $entity_type  e.g. 'product', 'appointment', 'transaction', 'user'
     *   @type int|null    $entity_id
     *   @type array|null  $meta         Extra structured context (old/new diffs etc.) — JSON-encoded
     *   @type int|null    $user_id      Override actor (needed for login/logout edge cases)
     *   @type string|null $user_name    Override actor name
     *   @type string|null $role         Override actor role
     *   @type PDO|null    $pdo          Override DB connection
     * }
     * @return bool
     */
    function logActivity(string $category, string $action, ?string $description = null, array $opts = []): bool
    {
        if (!in_array($category, AUDIT_LOG_CATEGORIES, true)) {
            error_log("log_activity.php: invalid category '{$category}' for action '{$action}'");
            return false;
        }

        $pdo = $opts['pdo'] ?? getActivityLogPdo();
        if (!($pdo instanceof PDO)) {
            error_log('log_activity.php: no PDO connection available — activity not logged.');
            return false;
        }

        [$userId, $userName, $role] = resolveActor($opts);

        $meta = null;
        if (isset($opts['meta']) && $opts['meta'] !== null) {
            $meta = json_encode($opts['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($meta === false) $meta = null; // don't fail the whole log over a bad meta payload
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs
                    (user_id, user_name, role, category, action, description,
                     entity_type, entity_id, ip_address, user_agent, meta, created_at)
                VALUES
                    (:user_id, :user_name, :role, :category, :action, :description,
                     :entity_type, :entity_id, :ip_address, :user_agent, :meta, NOW())
            ");
            $stmt->execute([
                ':user_id'     => $userId,
                ':user_name'   => $userName,
                ':role'        => $role,
                ':category'    => $category,
                ':action'      => $action,
                ':description' => $description,
                ':entity_type' => $opts['entity_type'] ?? null,
                ':entity_id'   => $opts['entity_id']   ?? null,
                ':ip_address'  => getClientIp(),
                ':user_agent'  => getUserAgent(),
                ':meta'        => $meta,
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('log_activity.php: insert failed — ' . $e->getMessage());
            return false;
        }
    }
}

/* 1 & 2. Login / Logout */

if (!function_exists('logLogin')) {
    /**
     * Call right after authentication succeeds or fails.
     * Actor is passed explicitly since $_SESSION may not be
     * populated yet (or, on failure, never will be).
     */
    function logLogin(int $userId, string $userName, string $role, bool $success = true, ?string $reason = null, ?PDO $pdo = null): bool
    {
        $action = $success ? 'Logged in' : 'Failed login attempt';
        $description = $success
            ? "{$userName} logged in successfully."
            : "Failed login attempt for {$userName}" . ($reason ? " — {$reason}." : '.');

        return logActivity('login', $action, $description, [
            'entity_type' => 'user',
            'entity_id'   => $userId,
            'meta'        => ['success' => $success, 'reason' => $reason],
            'user_id'     => $userId,
            'user_name'   => $userName,
            'role'        => $role,
            'pdo'         => $pdo,
        ]);
    }
}

if (!function_exists('logLogout')) {
    /**
     * Call BEFORE session_destroy() so actor info is still available
     * (or pass it explicitly if you've already cleared the session).
     */
    function logLogout(?int $userId = null, ?string $userName = null, ?string $role = null, ?PDO $pdo = null): bool
    {
        $userId   = $userId   ?? ($_SESSION['user_id']   ?? null);
        $userName = $userName ?? ($_SESSION['user_name'] ?? 'Unknown user');
        $role     = $role     ?? ($_SESSION['role']      ?? 'Unknown');

        return logActivity('logout', 'Logged out', "{$userName} logged out.", [
            'entity_type' => 'user',
            'entity_id'   => $userId,
            'user_id'     => $userId,
            'user_name'   => $userName,
            'role'        => $role,
            'pdo'         => $pdo,
        ]);
    }
}

/* 3-5. Product: Add / Edit / Delete */

if (!function_exists('logProductAdd')) {
    function logProductAdd(int $productId, string $productName, array $productData = [], ?PDO $pdo = null): bool
    {
        return logActivity('product_update', 'Added product', "Added new product \"{$productName}\".", [
            'entity_type' => 'product',
            'entity_id'   => $productId,
            'meta'        => ['new' => $productData],
            'pdo'         => $pdo,
        ]);
    }
}

if (!function_exists('logProductEdit')) {
    function logProductEdit(int $productId, string $productName, array $oldData, array $newData, ?PDO $pdo = null): bool
    {
        $changed = diffFields($oldData, $newData);
        if (empty($changed)) {
            return true; // nothing actually changed — skip noisy log entry
        }

        return logActivity('product_update', 'Updated product',
            "Updated \"{$productName}\" — " . summarizeChanges($changed), [
                'entity_type' => 'product',
                'entity_id'   => $productId,
                'meta'        => ['changed' => $changed],
                'pdo'         => $pdo,
            ]
        );
    }
}

if (!function_exists('logProductDelete')) {
    function logProductDelete(int $productId, string $productName, array $productData = [], ?PDO $pdo = null): bool
    {
        return logActivity('product_update', 'Deleted product', "Deleted product \"{$productName}\".", [
            'entity_type' => 'product',
            'entity_id'   => $productId,
            'meta'        => ['deleted' => $productData],
            'pdo'         => $pdo,
        ]);
    }
}

/* 6-7. Inventory: Stock In / Stock Out */

if (!function_exists('logStockIn')) {
    function logStockIn(int $productId, string $productName, int $quantity, ?string $reason = null, ?int $newStockLevel = null, ?PDO $pdo = null): bool
    {
        $desc = "Added {$quantity} unit" . ($quantity === 1 ? '' : 's') . " to \"{$productName}\"";
        $desc .= $reason ? " ({$reason})." : '.';

        return logActivity('inventory_change', 'Stock in', $desc, [
            'entity_type' => 'product',
            'entity_id'   => $productId,
            'meta'        => ['quantity_added' => $quantity, 'reason' => $reason, 'new_stock_level' => $newStockLevel],
            'pdo'         => $pdo,
        ]);
    }
}

if (!function_exists('logStockOut')) {
    function logStockOut(int $productId, string $productName, int $quantity, ?string $reason = null, ?int $newStockLevel = null, ?PDO $pdo = null): bool
    {
        $desc = "Removed {$quantity} unit" . ($quantity === 1 ? '' : 's') . " from \"{$productName}\"";
        $desc .= $reason ? " ({$reason})." : '.';

        return logActivity('inventory_change', 'Stock out', $desc, [
            'entity_type' => 'product',
            'entity_id'   => $productId,
            'meta'        => ['quantity_removed' => $quantity, 'reason' => $reason, 'new_stock_level' => $newStockLevel],
            'pdo'         => $pdo,
        ]);
    }
}

/* 8-9. Appointment: Create / Update */

if (!function_exists('logAppointmentCreate')) {
    function logAppointmentCreate(int $appointmentId, string $customerName, string $petName, string $appointmentDate, array $extra = [], ?PDO $pdo = null): bool
    {
        return logActivity('appointment_update', 'Created appointment',
            "Booked appointment for {$petName} ({$customerName}) on {$appointmentDate}.", [
                'entity_type' => 'appointment',
                'entity_id'   => $appointmentId,
                'meta'        => array_merge(['customer' => $customerName, 'pet' => $petName, 'date' => $appointmentDate], $extra),
                'pdo'         => $pdo,
            ]
        );
    }
}

if (!function_exists('logAppointmentUpdate')) {
    function logAppointmentUpdate(int $appointmentId, array $oldData, array $newData, ?PDO $pdo = null): bool
    {
        $changed = diffFields($oldData, $newData);
        if (empty($changed)) {
            return true;
        }

        $label = $newData['pet_name'] ?? $newData['customer_name'] ?? "#{$appointmentId}";

        return logActivity('appointment_update', 'Updated appointment',
            "Updated appointment for {$label} — " . summarizeChanges($changed), [
                'entity_type' => 'appointment',
                'entity_id'   => $appointmentId,
                'meta'        => ['changed' => $changed],
                'pdo'         => $pdo,
            ]
        );
    }
}

/* 10. Process Sale */

if (!function_exists('logSale')) {
    function logSale(int $transactionId, float $amount, int $itemCount, ?string $paymentMethod = null, ?string $customerName = null, ?PDO $pdo = null): bool
    {
        $desc = "Processed sale #{$transactionId} — ₱" . number_format($amount, 2) . " ({$itemCount} item" . ($itemCount === 1 ? '' : 's') . ")";
        $desc .= $customerName ? " for {$customerName}." : '.';

        return logActivity('sale', 'Processed sale', $desc, [
            'entity_type' => 'transaction',
            'entity_id'   => $transactionId,
            'meta'        => [
                'amount'         => round($amount, 2),
                'item_count'     => $itemCount,
                'payment_method' => $paymentMethod,
                'customer'       => $customerName,
            ],
            'pdo' => $pdo,
        ]);
    }
}

/* ============================================================
   11. User Management Activities
   (Create / Update / Delete / Activate / Deactivate / Role
    Change / Password Reset — All Staff-Account Admin Actions)
   ============================================================ */

if (!function_exists('logUserManagement')) {
    /**
     * Generic entry point for any user-account admin action.
     * Prefer the specific helpers below where they fit; fall back
     * to this for anything not explicitly covered.
     */
    function logUserManagement(string $action, int $targetUserId, string $targetUserName, ?string $description = null, array $meta = [], ?PDO $pdo = null): bool
    {
        return logActivity('user_activity', $action, $description ?? "{$action}: {$targetUserName}.", [
            'entity_type' => 'user',
            'entity_id'   => $targetUserId,
            'meta'        => $meta,
            'pdo'         => $pdo,
        ]);
    }
}

if (!function_exists('logUserCreate')) {
    function logUserCreate(int $newUserId, string $newUserName, string $role, array $extra = [], ?PDO $pdo = null): bool
    {
        return logUserManagement('Created user', $newUserId, $newUserName,
            "Created new {$role} account for \"{$newUserName}\".",
            array_merge(['role' => $role], $extra), $pdo
        );
    }
}

if (!function_exists('logUserUpdate')) {
    function logUserUpdate(int $targetUserId, string $targetUserName, array $oldData, array $newData, ?PDO $pdo = null): bool
    {
        $changed = diffFields($oldData, $newData);
        if (empty($changed)) {
            return true;
        }

        return logUserManagement('Updated user', $targetUserId, $targetUserName,
            "Updated account for \"{$targetUserName}\" — " . summarizeChanges($changed),
            ['changed' => $changed], $pdo
        );
    }
}

if (!function_exists('logUserDelete')) {
    function logUserDelete(int $targetUserId, string $targetUserName, ?PDO $pdo = null): bool
    {
        return logUserManagement('Deleted user', $targetUserId, $targetUserName,
            "Deleted account for \"{$targetUserName}\".", [], $pdo
        );
    }
}

if (!function_exists('logUserStatusChange')) {
    function logUserStatusChange(int $targetUserId, string $targetUserName, string $newStatus, ?PDO $pdo = null): bool
    {
        $action = $newStatus === 'active' ? 'Activated user' : 'Deactivated user';
        return logUserManagement($action, $targetUserId, $targetUserName,
            "{$action}: \"{$targetUserName}\".", ['new_status' => $newStatus], $pdo
        );
    }
}

if (!function_exists('logRoleChange')) {
    function logRoleChange(int $targetUserId, string $targetUserName, string $oldRole, string $newRole, ?PDO $pdo = null): bool
    {
        return logUserManagement('Changed role', $targetUserId, $targetUserName,
            "Changed role for \"{$targetUserName}\" from {$oldRole} to {$newRole}.",
            ['old_role' => $oldRole, 'new_role' => $newRole], $pdo
        );
    }
}

if (!function_exists('logPasswordReset')) {
    function logPasswordReset(int $targetUserId, string $targetUserName, bool $selfService = false, ?PDO $pdo = null): bool
    {
        $action = $selfService ? 'Reset own password' : 'Reset user password';
        return logUserManagement($action, $targetUserId, $targetUserName,
            $selfService ? "{$targetUserName} reset their own password." : "Reset password for \"{$targetUserName}\".",
            ['self_service' => $selfService], $pdo
        );
    }
}

/* ============================================================
   Bonus — Search / Filter Logging (Optional)
   Not In The Requested List, But The Audit_Logs Schema Already
   Reserves A Category For It If You Want To Wire It Up Later
   (E.G. From Products.Html Or Customers.Html Search Boxes).
   ============================================================ */

if (!function_exists('logSearchFilter')) {
    function logSearchFilter(string $context, string $query, array $filters = [], ?PDO $pdo = null): bool
    {
        return logActivity('search_filter', 'Searched/filtered ' . $context,
            "Searched \"{$context}\" for \"{$query}\".", [
                'meta' => ['context' => $context, 'query' => $query, 'filters' => $filters],
                'pdo'  => $pdo,
            ]
        );
    }
}

/* Internal Helpers */

if (!function_exists('resolveActor')) {
    /**
     * Resolves [user_id, user_name, role] for the current actor,
     * preferring explicit overrides in $opts, falling back to session.
     *
     * @return array{0:?int,1:string,2:string}
     */
    function resolveActor(array $opts): array
    {
        $userId = $opts['user_id'] ?? (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

        $userName = $opts['user_name']
            ?? ($_SESSION['user_name'] ?? null)
            ?? ($_SESSION['full_name'] ?? null)
            ?? ($_SESSION['username']  ?? null)
            ?? ($_SESSION['name']      ?? null)
            ?? 'Unknown user';

        $role = $opts['role'] ?? ($_SESSION['role'] ?? 'Unknown');

        return [$userId, $userName, $role];
    }
}

if (!function_exists('diffFields')) {
    /**
     * Returns only the fields that differ between $old and $new as
     * ['field' => ['old' => ..., 'new' => ...]].
     */
    function diffFields(array $old, array $new): array
    {
        $changed = [];
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($keys as $key) {
            $oldVal = $old[$key] ?? null;
            $newVal = $new[$key] ?? null;
            if ($oldVal !== $newVal) {
                $changed[$key] = ['old' => $oldVal, 'new' => $newVal];
            }
        }

        return $changed;
    }
}

if (!function_exists('summarizeChanges')) {
    function summarizeChanges(array $changed): string
    {
        if (empty($changed)) return 'no fields changed';
        return 'changed ' . implode(', ', array_keys($changed));
    }
}

if (!function_exists('getClientIp')) {
    function getClientIp(): ?string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }
}

if (!function_exists('getUserAgent')) {
    function getUserAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return $ua ? mb_substr($ua, 0, 255) : null;
    }
}

if (!function_exists('getActivityLogPdo')) {
    function getActivityLogPdo(): ?PDO
    {
        global $pdo;
        return ($pdo instanceof PDO) ? $pdo : null;
    }
}