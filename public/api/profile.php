<?php
/**
 * MBC SMS - User Profile API
 * View and update user profile, change password, manage API token
 */

require_once __DIR__ . '/config.php';

session_name(SESSION_NAME);
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];
$db = Database::getInstance();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'get';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'get':
                getProfile();
                break;
            case 'token':
                getApiToken();
                break;
            case 'activity':
                getActivity();
                break;
            default:
                getProfile();
        }
        break;
    case 'POST':
        switch ($action) {
            case 'update':
                updateProfile();
                break;
            case 'password':
                changePassword();
                break;
            case 'token':
                regenerateToken();
                break;
            default:
                jsonResponse(['error' => 'Invalid action'], 400);
        }
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

/**
 * Get current user profile
 */
function getProfile() {
    global $db, $userId;
    
    $stmt = $db->query(
        "SELECT u.id, u.username, u.email, u.role, u.status, u.api_token, u.created_at, u.last_login,
                up.full_name, up.phone, up.timezone, up.avatar, up.skype, up.telegram, up.notes
         FROM users u 
         LEFT JOIN user_profiles up ON u.id = up.user_id 
         WHERE u.id = ?",
        [$userId]
    );
    
    $profile = $stmt->fetch();
    
    if (!$profile) {
        jsonResponse(['error' => 'Profile not found'], 404);
    }
    
    // Get balances
    $stmt = $db->query("SELECT currency, balance FROM user_balances WHERE user_id = ?", [$userId]);
    $profile['balances'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Get parent info (for agents and clients)
    $stmt = $db->query(
        "SELECT u.id, u.username, u.email 
         FROM users u 
         JOIN users me ON me.parent_id = u.id 
         WHERE me.id = ?",
        [$userId]
    );
    $parent = $stmt->fetch();
    if ($parent) {
        $profile['parent'] = $parent;
    }
    
    jsonResponse(['success' => true, 'profile' => $profile]);
}

/**
 * Update user profile
 */
function updateProfile() {
    global $db, $userId;
    
    $input = getJsonInput();
    
    $fullName = sanitize($input['full_name'] ?? '');
    $phone = sanitize($input['phone'] ?? '');
    $timezone = sanitize($input['timezone'] ?? 'UTC');
    $skype = sanitize($input['skype'] ?? '');
    $telegram = sanitize($input['telegram'] ?? '');
    $notes = sanitize($input['notes'] ?? '');
    
    // Check if profile exists
    $stmt = $db->query("SELECT id FROM user_profiles WHERE user_id = ?", [$userId]);
    $exists = $stmt->fetch();
    
    if ($exists) {
        $db->query(
            "UPDATE user_profiles SET full_name = ?, phone = ?, timezone = ?, skype = ?, telegram = ?, notes = ?, updated_at = NOW() WHERE user_id = ?",
            [$fullName, $phone, $timezone, $skype, $telegram, $notes, $userId]
        );
    } else {
        $db->query(
            "INSERT INTO user_profiles (user_id, full_name, phone, timezone, skype, telegram, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [$userId, $fullName, $phone, $timezone, $skype, $telegram, $notes]
        );
    }
    
    // Update email if provided
    if (!empty($input['email'])) {
        $email = sanitize($input['email']);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Check if email is already used by another user
            $stmt = $db->query("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $userId]);
            if (!$stmt->fetch()) {
                $db->query("UPDATE users SET email = ? WHERE id = ?", [$email, $userId]);
            }
        }
    }
    
    logActivity($userId, 'profile_update', 'Updated profile information');
    
    jsonResponse(['success' => true, 'message' => 'Profile updated successfully']);
}

/**
 * Change password
 */
function changePassword() {
    global $db, $userId;
    
    $input = getJsonInput();
    
    $currentPassword = $input['current_password'] ?? '';
    $newPassword = $input['new_password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';
    
    if (strlen($newPassword) < 6) {
        jsonResponse(['error' => 'Password must be at least 6 characters'], 400);
    }
    
    if ($newPassword !== $confirmPassword) {
        jsonResponse(['error' => 'Passwords do not match'], 400);
    }
    
    // Verify current password
    $stmt = $db->query("SELECT password FROM users WHERE id = ?", [$userId]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($currentPassword, $user['password'])) {
        jsonResponse(['error' => 'Current password is incorrect'], 400);
    }
    
    // Update password
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $db->query("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?", [$hashedPassword, $userId]);
    
    logActivity($userId, 'password_change', 'Changed password');
    
    jsonResponse(['success' => true, 'message' => 'Password changed successfully']);
}

/**
 * Get API token
 */
function getApiToken() {
    global $db, $userId;
    
    $stmt = $db->query("SELECT api_token FROM users WHERE id = ?", [$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        jsonResponse(['error' => 'User not found'], 404);
    }
    
    jsonResponse(['success' => true, 'api_token' => $user['api_token']]);
}

/**
 * Regenerate API token
 */
function regenerateToken() {
    global $db, $userId;
    
    $newToken = bin2hex(random_bytes(32));
    
    $db->query("UPDATE users SET api_token = ?, updated_at = NOW() WHERE id = ?", [$newToken, $userId]);
    
    logActivity($userId, 'token_regenerate', 'Regenerated API token');
    
    jsonResponse(['success' => true, 'api_token' => $newToken, 'message' => 'API token regenerated successfully']);
}

/**
 * Get user activity log
 */
function getActivity() {
    global $db, $userId;
    
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = (int)($_GET['start'] ?? 0);
    
    $stmt = $db->query(
        "SELECT * FROM activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?",
        [$userId, $limit, $offset]
    );
    
    $activities = $stmt->fetchAll();
    
    // Get total count
    $stmt = $db->query("SELECT COUNT(*) as count FROM activity_log WHERE user_id = ?", [$userId]);
    $total = (int)$stmt->fetch()['count'];
    
    jsonResponse(['success' => true, 'data' => $activities, 'total' => $total]);
}
