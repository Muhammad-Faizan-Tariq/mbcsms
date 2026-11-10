<?php
/**
 * MBC SMS - Session Check for Frontend Pages
 * Include this at the top of any protected page
 */

require_once __DIR__ . '/api/config.php';

session_name(SESSION_NAME);
session_start();

// Check if logged in
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    // Not logged in - redirect to login
    $loginUrl = '/ints/login';
    header("Location: $loginUrl");
    exit;
}

// Check session expiry
if (time() - ($_SESSION['login_time'] ?? 0) > SESSION_LIFETIME) {
    // Session expired
    $_SESSION = [];
    session_destroy();
    header("Location: /ints/login?expired=1");
    exit;
}

// Update login time to extend session
$_SESSION['login_time'] = time();

// Get current user data
$currentUser = [
    'id' => $_SESSION['user_id'],
    'username' => $_SESSION['username'],
    'role' => $_SESSION['role'],
    'parent_id' => $_SESSION['parent_id'],
    'full_name' => $_SESSION['full_name'],
    'timezone' => $_SESSION['timezone'] ?? 'UTC'
];

// Get balances
$db = Database::getInstance();
$stmt = $db->query("SELECT currency, balance FROM user_balances WHERE user_id = ?", [$currentUser['id']]);
$currentUser['balances'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Function to check role
function requireRole($allowedRoles) {
    global $currentUser;
    $roles = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
    if (!in_array($currentUser['role'], $roles)) {
        header("HTTP/1.1 403 Forbidden");
        die('Access denied');
    }
}

// Function to get user data for JavaScript
function getUserDataJson() {
    global $currentUser;
    return json_encode($currentUser);
}
