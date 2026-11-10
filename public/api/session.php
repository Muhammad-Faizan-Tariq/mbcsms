<?php
/**
 * MBC SMS - Frontend Session API
 * Returns current user session data for frontend JavaScript
 */

require_once __DIR__ . '/config.php';

session_name(SESSION_NAME);
session_start();

header('Content-Type: application/json');

// Check if logged in
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    jsonResponse(['authenticated' => false, 'redirect' => '/ints/login'], 401);
}

// Check session expiry
if (time() - ($_SESSION['login_time'] ?? 0) > SESSION_LIFETIME) {
    $_SESSION = [];
    session_destroy();
    jsonResponse(['authenticated' => false, 'redirect' => '/ints/login', 'expired' => true], 401);
}

// Update login time
$_SESSION['login_time'] = time();

// Get user data
$db = Database::getInstance();
$stmt = $db->query(
    "SELECT u.*, up.full_name, up.phone, up.timezone, up.avatar 
     FROM users u 
     LEFT JOIN user_profiles up ON u.id = up.user_id 
     WHERE u.id = ? AND u.status = 'active'",
    [$_SESSION['user_id']]
);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    jsonResponse(['authenticated' => false, 'redirect' => '/ints/login'], 401);
}

// Get balances
$stmt = $db->query("SELECT currency, balance FROM user_balances WHERE user_id = ?", [$user['id']]);
$balances = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get unread notifications count
$stmt = $db->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0", [$user['id']]);
$unreadNotifications = (int)$stmt->fetch()['count'];

// Determine dashboard URL based on role
$dashboardUrl = '/ints/login';
switch ($user['role']) {
    case 'manager':
        $dashboardUrl = '/ints/manager/SMSDashboard';
        break;
    case 'agent':
        $dashboardUrl = '/ints/agent/SMSDashboard';
        break;
    case 'client':
        $dashboardUrl = '/ints/client/SMSDashboard';
        break;
}

jsonResponse([
    'success' => true,
    'logged_in' => true,
    'authenticated' => true,
    'dashboard_url' => $dashboardUrl,
    'user' => [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role'],
        'full_name' => $user['full_name'],
        'phone' => $user['phone'],
        'timezone' => $user['timezone'],
        'avatar' => $user['avatar'],
        'api_token' => $user['api_token'],
        'balances' => $balances,
        'unread_notifications' => $unreadNotifications,
        'last_login' => $user['last_login']
    ]
]);
