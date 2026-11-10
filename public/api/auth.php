<?php
/**
 * MBC SMS - Authentication Handler
 * Handles login, logout, session management
 */

require_once __DIR__ . '/config.php';

// Start session
session_name(SESSION_NAME);
session_start();

$action = $_GET['action'] ?? 'login';

switch ($action) {
    case 'login':
        handleLogin();
        break;
    case 'logout':
        handleLogout();
        break;
    case 'check':
        checkSession();
        break;
    case 'captcha':
        generateCaptcha();
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

/**
 * Handle Login
 */
function handleLogin() {
    $db = Database::getInstance();
    
    // Get input
    $input = getJsonInput();
    if (empty($input)) {
        $input = $_POST;
    }
    
    $username = sanitize($input['username'] ?? '');
    $password = sanitize($input['password'] ?? '');
    $captchaAnswer = (int)($input['captcha'] ?? 0);
    $sessionId = session_id();
    
    // Validate inputs
    if (empty($username) || empty($password)) {
        jsonResponse(['error' => 'Username and password are required'], 400);
    }
    
    // Verify captcha
    $stmt = $db->query(
        "SELECT captcha_answer FROM captcha_sessions WHERE session_id = ? AND expires_at > NOW()",
        [$sessionId]
    );
    $captcha = $stmt->fetch();
    
    if (!$captcha || $captchaAnswer !== (int)$captcha['captcha_answer']) {
        logActivity(null, 'login_failed', "Invalid captcha for username: $username");
        jsonResponse(['error' => 'Invalid captcha answer'], 400);
    }
    
    // Clean up used captcha
    $db->query("DELETE FROM captcha_sessions WHERE session_id = ?", [$sessionId]);
    
    // Find user
    $hashedPassword = generateHash($password);
    $stmt = $db->query(
        "SELECT u.*, up.full_name, up.phone, up.timezone, up.avatar 
         FROM users u 
         LEFT JOIN user_profiles up ON u.id = up.user_id 
         WHERE u.username = ? AND u.password = ? AND u.status = 'active'",
        [$username, $hashedPassword]
    );
    $user = $stmt->fetch();
    
    if (!$user) {
        logActivity(null, 'login_failed', "Invalid credentials for username: $username");
        jsonResponse(['error' => 'Invalid username or password'], 401);
    }
    
    // Update last login
    $db->query(
        "UPDATE users SET last_login = NOW(), login_ip = ? WHERE id = ?",
        [$_SERVER['REMOTE_ADDR'], $user['id']]
    );
    
    // Set session data
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['parent_id'] = $user['parent_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['timezone'] = $user['timezone'];
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
    
    // Generate new API token if needed
    if (empty($user['api_token'])) {
        $newToken = generateToken();
        $db->query("UPDATE users SET api_token = ? WHERE id = ?", [$newToken, $user['id']]);
        $user['api_token'] = $newToken;
    }
    
    // Log activity
    logActivity($user['id'], 'login', "User logged in successfully");
    
    // Determine redirect URL based on role
    $redirectUrl = match ($user['role']) {
        'manager' => '../ints/manager/SMSDashboard.html',
        'agent' => '../ints/agent/SMSDashboard.html',
        'client' => '../ints/client/SMSDashboard.html',
        default => '../ints/client/SMSDashboard.html'
    };
    
    // Get balances
    $stmt = $db->query(
        "SELECT currency, balance FROM user_balances WHERE user_id = ?",
        [$user['id']]
    );
    $balances = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    jsonResponse([
        'success' => true,
        'message' => 'Login successful',
        'redirect' => $redirectUrl,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'full_name' => $user['full_name'],
            'api_token' => $user['api_token'],
            'balances' => $balances
        ]
    ]);
}

/**
 * Handle Logout
 */
function handleLogout() {
    $userId = $_SESSION['user_id'] ?? null;
    
    if ($userId) {
        logActivity($userId, 'logout', 'User logged out');
    }
    
    // Destroy session
    $_SESSION = [];
    session_destroy();
    
    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    jsonResponse(['success' => true, 'message' => 'Logged out successfully', 'redirect' => '../ints/login.html']);
}

/**
 * Check Session Status
 */
function checkSession() {
    if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
        jsonResponse(['authenticated' => false], 401);
    }
    
    // Check session expiry
    if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
        handleLogout();
    }
    
    $db = Database::getInstance();
    $stmt = $db->query(
        "SELECT u.*, up.full_name, up.phone, up.timezone 
         FROM users u 
         LEFT JOIN user_profiles up ON u.id = up.user_id 
         WHERE u.id = ? AND u.status = 'active'",
        [$_SESSION['user_id']]
    );
    $user = $stmt->fetch();
    
    if (!$user) {
        handleLogout();
    }
    
    $stmt = $db->query(
        "SELECT currency, balance FROM user_balances WHERE user_id = ?",
        [$user['id']]
    );
    $balances = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    jsonResponse([
        'authenticated' => true,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'full_name' => $user['full_name'],
            'balances' => $balances
        ]
    ]);
}

/**
 * Generate Captcha
 */
function generateCaptcha() {
    $db = Database::getInstance();
    $sessionId = session_id();
    
    // Generate random math captcha
    $num1 = rand(0, 9);
    $num2 = rand(0, 9);
    $answer = $num1 + $num2;
    
    // Delete old captcha for this session
    $db->query("DELETE FROM captcha_sessions WHERE session_id = ?", [$sessionId]);
    
    // Store new captcha
    $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    $db->query(
        "INSERT INTO captcha_sessions (session_id, captcha_answer, expires_at) VALUES (?, ?, ?)",
        [$sessionId, $answer, $expiresAt]
    );
    
    jsonResponse([
        'success' => true,
        'captcha' => [
            'question' => "$num1 + $num2 = ?",
            'num1' => $num1,
            'num2' => $num2
        ]
    ]);
}
