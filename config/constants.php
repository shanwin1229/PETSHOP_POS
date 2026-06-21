<?php
declare(strict_types=1);

// Shared app constants. Endpoint files may define their own domain-specific constants too.
if (!defined('APP_NAME')) define('APP_NAME', 'PAWPOS');
if (!defined('APP_DEBUG')) define('APP_DEBUG', false);
if (!defined('LOGIN_PAGE')) define('LOGIN_PAGE', 'index.html');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 28800);
if (!defined('MAX_LOGIN_ATTEMPTS')) define('MAX_LOGIN_ATTEMPTS', 5);
if (!defined('LOCKOUT_DURATION')) define('LOCKOUT_DURATION', 900);
if (!defined('BCRYPT_COST')) define('BCRYPT_COST', 12);
if (!defined('MIN_PW_LENGTH')) define('MIN_PW_LENGTH', 8);
if (!defined('TAX_RATE')) define('TAX_RATE', 0.00);
if (!defined('TXN_PREFIX')) define('TXN_PREFIX', 'TXN');
if (!defined('LOW_STOCK_THRESHOLD')) define('LOW_STOCK_THRESHOLD', 5);
if (!defined('EXPIRY_WARN_DAYS')) define('EXPIRY_WARN_DAYS', 30);
if (!defined('VACC_WARN_DAYS')) define('VACC_WARN_DAYS', 30);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
}