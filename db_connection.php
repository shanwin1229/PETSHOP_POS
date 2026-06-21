<?php
declare(strict_types=1);

/* Database Configuration */
if (file_exists(__DIR__ . '/../.env') && class_exists('\Dotenv\Dotenv')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->safeLoad();
}

define('DB_HOST',     $_ENV['DB_HOST']     ?? getenv('DB_HOST')     ?: 'localhost');
define('DB_PORT',     $_ENV['DB_PORT']     ?? getenv('DB_PORT')     ?: '3306');
define('DB_NAME',     $_ENV['DB_NAME']     ?? getenv('DB_NAME')     ?: 'petshop_pos');
define('DB_USER',     $_ENV['DB_USER']     ?? getenv('DB_USER')     ?: 'root');
define('DB_PASS',     $_ENV['DB_PASS']     ?? getenv('DB_PASS')     ?: '');
define('DB_CHARSET',  $_ENV['DB_CHARSET']  ?? getenv('DB_CHARSET')  ?: 'utf8mb4');
define('DB_TIMEZONE', $_ENV['DB_TIMEZONE'] ?? getenv('DB_TIMEZONE') ?: '+08:00');

define('DB_PERSISTENT', (bool)($_ENV['DB_PERSISTENT'] ?? false));
define('DB_LOG_ERRORS', (bool)($_ENV['APP_DEBUG']     ?? false));

/* Pdo Options */
const PDO_OPTIONS = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_STRINGIFY_FETCHES  => false,
    PDO::ATTR_CASE               => PDO::CASE_NATURAL,
    PDO::ATTR_TIMEOUT            => 5,
    PDO::ATTR_PERSISTENT         => DB_PERSISTENT,
    PDO::MYSQL_ATTR_INIT_COMMAND =>
        "SET NAMES " . DB_CHARSET . " COLLATE " . DB_CHARSET . "_unicode_ci; " .
        "SET time_zone = '" . DB_TIMEZONE . "'; " .
        "SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
    PDO::MYSQL_ATTR_DIRECT_QUERY => false,
];

/* Singleton Connection Class */
class Database
{
    private static ?PDO $instance = null;
    private static int  $connectionAttempts = 0;
    private const       MAX_RETRIES = 3;

    private function __construct()
    {
    }

    public static function getConnection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::$instance = self::createConnection();
        return self::$instance;
    }

    private static function createConnection(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                self::$connectionAttempts++;
                $pdo = new PDO($dsn, DB_USER, DB_PASS, PDO_OPTIONS);

                if (DB_LOG_ERRORS) {
                    error_log(sprintf(
                        '[PAWPOS DB] Connected to %s@%s/%s (attempt %d)',
                        DB_USER,
                        DB_HOST,
                        DB_NAME,
                        $attempt
                    ));
                }

                return $pdo;

            } catch (PDOException $e) {
                $lastException = $e;

                if (DB_LOG_ERRORS) {
                    error_log(sprintf(
                        '[PAWPOS DB] Connection attempt %d/%d failed: %s',
                        $attempt,
                        self::MAX_RETRIES,
                        $e->getMessage()
                    ));
                }

                if ($attempt < self::MAX_RETRIES) {
                    usleep($attempt * 50_000);
                }
            }
        }

        self::handleConnectionFailure($lastException);
    }

    private static function handleConnectionFailure(?PDOException $e): never
    {
        error_log('[PAWPOS DB] FATAL: Could not connect after ' . self::MAX_RETRIES . ' attempts. ' . ($e?->getMessage() ?? ''));

        throw new RuntimeException(
            'The system is temporarily unavailable. Please try again in a few moments.',
            500,
            $e
        );
    }

    public static function close(): void
    {
        self::$instance = null;
    }

    public static function isConnected(): bool
    {
        try {
            if (self::$instance === null)
                return false;
            self::$instance->query('SELECT 1');
            return true;
        } catch (PDOException) {
            self::$instance = null;
            return false;
        }
    }

    public static function reconnect(): PDO
    {
        self::close();
        return self::getConnection();
    }

    public static function getConnectionAttempts(): int
    {
        return self::$connectionAttempts;
    }
}

/* Procedural Helpers */
function getPDO(): PDO
{
    try {
        return Database::getConnection();
    } catch (RuntimeException $e) {
        if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'data'    => null
            ]);
            exit;
        }

        http_response_code(503);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Service Unavailable</title></head>'
            . '<body style="font-family:sans-serif;text-align:center;padding:4rem">'
            . '<h2>&#x26A0;&#xFE0F; Service Temporarily Unavailable</h2>'
            . '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><a href="index.html">Return to login</a></p>'
            . '</body></html>';
        exit;
    }
}

/* Transaction Helpers */
function db_transaction(callable $callback): mixed
{
    $pdo = getPDO();
    $pdo->beginTransaction();

    try {
        $result = $callback($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function db_select(string $sql, array $params = []): array
{
    $stmt = getPDO()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_find(string $sql, array $params = []): ?array
{
    $stmt = getPDO()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function db_execute(string $sql, array $params = []): int
{
    $stmt = getPDO()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function db_insert(string $sql, array $params = []): string
{
    $pdo = getPDO();
    $pdo->prepare($sql)->execute($params);
    return $pdo->lastInsertId();
}

/* Bootstrap */
try {
    $pdo = getPDO();
} catch (Throwable $e) {
    exit;
}