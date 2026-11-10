<?php
/**
 * MBC SMS - Main Configuration
 * Production-ready configuration for Ubuntu VPS
 */

// Error reporting (disable in production)
error_reporting(0);
ini_set('display_errors', 0);

// Load environment variables from config/.env
$envPaths = [
    __DIR__ . '/../../config/.env',      // New structure: public/api -> config/.env
    dirname(__DIR__) . '/.env',          // Old structure fallback
    __DIR__ . '/../.env'                 // Alternative path
];

foreach ($envPaths as $envFile) {
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                // Real environment (e.g. Docker) takes precedence over the file
                if (getenv(trim($parts[0])) === false) {
                    putenv(trim($parts[0]) . '=' . trim($parts[1]));
                }
            }
        }
        break;
    }
}

// Database Configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'mbc_sms');
define('DB_USER', getenv('DB_USER') ?: 'mbc_user');
define('DB_PASS', getenv('DB_PASS') ?: 'your_secure_password_here');

// Application Settings
define('APP_NAME', getenv('APP_NAME') ?: 'MBC SMS');
define('APP_URL', getenv('APP_URL') ?: 'http://localhost');
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_DEBUG', getenv('APP_DEBUG') === 'true');

// Security
define('HASH_ALGO', getenv('HASH_ALGO') ?: 'sha256');
define('SALT', getenv('SALT') ?: 'mbc_sms_secure_salt_2024');
define('SESSION_NAME', getenv('SESSION_NAME') ?: 'mbc_sms_session');
define('SESSION_LIFETIME', (int)(getenv('SESSION_LIFETIME') ?: 7200));

// Jasmin SMS Gateway
define('JASMIN_API_URL', getenv('JASMIN_API_URL') ?: 'http://localhost');
define('JASMIN_API_PORT', getenv('JASMIN_API_PORT') ?: '1401');
define('JASMIN_PANEL_PORT', getenv('JASMIN_PANEL_PORT') ?: '8080');

// Timezone
date_default_timezone_set('UTC');

// ============================================
// DDoS Protection - Rate Limiting
// ============================================

$rateLimit = [
    'enabled' => true,
    'max_requests' => 100,      // Max requests per minute
    'ban_duration' => 600,       // Ban duration in seconds (10 minutes)
    'whitelist' => ['127.0.0.1', '::1']  // Localhost whitelist
];

function checkRateLimit() {
    global $rateLimit;
    
    if (!$rateLimit['enabled']) return true;
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    // Whitelist check
    if (in_array($ip, $rateLimit['whitelist'])) return true;
    
    $cacheFile = sys_get_temp_dir() . '/mbc_rate_' . md5($ip);
    $now = time();
    
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        
        // Check if banned
        if (isset($data['banned_until']) && $data['banned_until'] > $now) {
            http_response_code(429);
            header('Retry-After: ' . ($data['banned_until'] - $now));
            jsonResponse(['error' => 'Too many requests. Please try again later.'], 429);
        }
        
        // Clean old requests
        $data['requests'] = array_filter($data['requests'] ?? [], function($t) use ($now) {
            return $t > $now - 60;
        });
        
        // Check rate
        if (count($data['requests']) >= $rateLimit['max_requests']) {
            // Ban the IP
            $data['banned_until'] = $now + $rateLimit['ban_duration'];
            file_put_contents($cacheFile, json_encode($data));
            
            http_response_code(429);
            header('Retry-After: ' . $rateLimit['ban_duration']);
            jsonResponse(['error' => 'Rate limit exceeded. Banned for ' . $rateLimit['ban_duration'] . ' seconds.'], 429);
        }
        
        // Add current request
        $data['requests'][] = $now;
        file_put_contents($cacheFile, json_encode($data));
    } else {
        // First request
        file_put_contents($cacheFile, json_encode(['requests' => [$now]]));
    }
    
    return true;
}

// Run rate limit check
checkRateLimit();

// ============================================
// Login Brute Force Protection
// ============================================

$loginRateLimit = [
    'enabled' => true,
    'max_attempts' => 5,         // Max failed login attempts
    'window' => 900,             // Time window in seconds (15 minutes)
    'ban_duration' => 1800,      // Ban duration after max attempts (30 minutes)
];

function checkLoginRateLimit($username = null) {
    global $loginRateLimit;
    
    if (!$loginRateLimit['enabled']) return true;
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $cacheFile = sys_get_temp_dir() . '/mbc_login_' . md5($ip . ($username ? '_' . $username : ''));
    $now = time();
    
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        
        // Check if banned
        if (isset($data['banned_until']) && $data['banned_until'] > $now) {
            return ['allowed' => false, 'remaining' => $data['banned_until'] - $now];
        }
        
        // Clean old attempts
        $data['attempts'] = array_filter($data['attempts'] ?? [], function($t) use ($now) {
            return $t > $now - $loginRateLimit['window'];
        });
        
        return ['allowed' => true, 'attempts' => count($data['attempts']), 'remaining' => $loginRateLimit['max_attempts'] - count($data['attempts'])];
    }
    
    return ['allowed' => true, 'attempts' => 0, 'remaining' => $loginRateLimit['max_attempts']];
}

function recordFailedLogin($username = null) {
    global $loginRateLimit;
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $cacheFile = sys_get_temp_dir() . '/mbc_login_' . md5($ip . ($username ? '_' . $username : ''));
    $now = time();
    
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        $data['attempts'][] = $now;
        
        // Check if should ban
        if (count($data['attempts']) >= $loginRateLimit['max_attempts']) {
            $data['banned_until'] = $now + $loginRateLimit['ban_duration'];
        }
    } else {
        $data = ['attempts' => [$now]];
    }
    
    file_put_contents($cacheFile, json_encode($data));
}

function clearLoginRateLimit($username = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $cacheFile = sys_get_temp_dir() . '/mbc_login_' . md5($ip . ($username ? '_' . $username : ''));
    if (file_exists($cacheFile)) {
        unlink($cacheFile);
    }
}

// CORS Headers for API
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Token');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Database Connection Class
 */
class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            if (APP_DEBUG) {
                jsonResponse(['error' => 'Database connection failed'], 500);
            } else {
                jsonResponse(['error' => 'Service temporarily unavailable'], 503);
            }
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query error: " . $e->getMessage() . " SQL: " . $sql);
            throw $e;
        }
    }
    
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    public function commit() {
        return $this->pdo->commit();
    }
    
    public function rollBack() {
        return $this->pdo->rollBack();
    }
}

/**
 * Helper Functions
 */

// JSON Response
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Generate secure hash
function generateHash($password) {
    return hash(HASH_ALGO, SALT . $password);
}

// Generate random token
function generateToken($length = 64) {
    return bin2hex(random_bytes($length / 2));
}

// Sanitize input
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Get JSON input
function getJsonInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? [];
}

// Validate API Token
function validateApiToken($token) {
    $db = Database::getInstance();
    $stmt = $db->query("SELECT * FROM users WHERE api_token = ? AND status = 'active'", [$token]);
    return $stmt->fetch();
}

// Get Authorization header
function getAuthToken() {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        return str_replace('Bearer ', '', $headers['Authorization']);
    }
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
    }
    if (isset($_GET['token'])) {
        return $_GET['token'];
    }
    return null;
}

// Format currency
function formatCurrency($amount, $currency = 'USD') {
    $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'];
    return ($symbols[$currency] ?? '$') . number_format($amount, 4);
}

// Log activity
function logActivity($userId, $action, $description = null) {
    $db = Database::getInstance();
    $db->query(
        "INSERT INTO user_activity (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)",
        [$userId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]
    );
}

// Create notification
function createNotification($userId, $title, $message, $type = 'info') {
    $db = Database::getInstance();
    $db->query(
        "INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)",
        [$userId, $title, $message, $type]
    );
}

// Update balance
function updateBalance($userId, $currency, $amount, $type = 'credit') {
    $db = Database::getInstance();
    if ($type === 'credit') {
        $db->query(
            "INSERT INTO user_balances (user_id, currency, balance) VALUES (?, ?, ?) 
             ON DUPLICATE KEY UPDATE balance = balance + ?",
            [$userId, $currency, $amount, $amount]
        );
    } else {
        $db->query(
            "UPDATE user_balances SET balance = balance - ? WHERE user_id = ? AND currency = ? AND balance >= ?",
            [$amount, $userId, $currency, $amount]
        );
    }
}

// Get user balance
function getBalance($userId, $currency = 'USD') {
    $db = Database::getInstance();
    $stmt = $db->query(
        "SELECT balance FROM user_balances WHERE user_id = ? AND currency = ?",
        [$userId, $currency]
    );
    $result = $stmt->fetch();
    return $result ? (float)$result['balance'] : 0;
}

// Calculate payout based on term
function calculatePayout($rangeId, $payTerm) {
    $db = Database::getInstance();
    $stmt = $db->query("SELECT * FROM sms_ranges WHERE id = ?", [$rangeId]);
    $range = $stmt->fetch();
    
    if (!$range) return 0;
    
    $payoutField = 'payout_' . str_replace('/', '_', $payTerm);
    return isset($range[$payoutField]) ? (float)$range[$payoutField] : (float)$range['payout_1_1'];
}

// Check if number is test number
function isTestNumber($number, $rangeId = null) {
    $db = Database::getInstance();
    if ($rangeId) {
        $stmt = $db->query(
            "SELECT is_test FROM sms_numbers WHERE number = ? AND range_id = ?",
            [$number, $rangeId]
        );
    } else {
        $stmt = $db->query("SELECT is_test FROM sms_numbers WHERE number = ?", [$number]);
    }
    $result = $stmt->fetch();
    return $result && $result['is_test'] == 1;
}

// Get number type (general/test)
function getNumberType($number) {
    return isTestNumber($number) ? 'test' : 'general';
}
