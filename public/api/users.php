<?php
/**
 * MBC SMS - Users API
 * Manage agents, clients, profile updates
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
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list':
                listUsers();
                break;
            case 'get':
                getUser();
                break;
            case 'dropdown':
                getUsersDropdown();
                break;
            case 'profile':
                getProfile();
                break;
            case 'activity':
                getActivity();
                break;
            case 'datatable':
                getUsersDatatable();
                break;
            default:
                listUsers();
        }
        break;
    case 'POST':
        switch ($action) {
            case 'create':
                createUser();
                break;
            case 'update':
                updateUser();
                break;
            case 'delete':
                deleteUser();
                break;
            case 'profile':
                updateProfile();
                break;
            case 'password':
                changePassword();
                break;
            case 'toggle':
                toggleUserStatus();
                break;
            default:
                jsonResponse(['error' => 'Invalid action'], 400);
        }
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

/**
 * List users based on role
 */
function listUsers() {
    global $db, $userId, $role;
    
    if ($role === 'manager') {
        // Manager sees their agents
        $stmt = $db->query(
            "SELECT u.*, p.full_name, p.phone, 
                    (SELECT COUNT(*) FROM users WHERE parent_id = u.id) as client_count,
                    (SELECT COUNT(*) FROM sms_numbers WHERE assigned_to = u.id) as number_count
             FROM users u 
             LEFT JOIN user_profiles p ON u.id = p.user_id 
             WHERE u.role = 'agent' AND u.parent_id = ?
             ORDER BY u.created_at DESC",
            [$userId]
        );
    } elseif ($role === 'agent') {
        // Agent sees their clients
        $stmt = $db->query(
            "SELECT u.*, p.full_name, p.phone,
                    (SELECT COUNT(*) FROM sms_numbers WHERE assigned_to = u.id) as number_count
             FROM users u 
             LEFT JOIN user_profiles p ON u.id = p.user_id 
             WHERE u.role = 'client' AND u.parent_id = ?
             ORDER BY u.created_at DESC",
            [$userId]
        );
    } else {
        // Client sees only themselves
        $stmt = $db->query(
            "SELECT u.*, p.full_name, p.phone 
             FROM users u 
             LEFT JOIN user_profiles p ON u.id = p.user_id 
             WHERE u.id = ?",
            [$userId]
        );
    }
    
    $users = $stmt->fetchAll();
    jsonResponse(['success' => true, 'data' => $users]);
}

/**
 * Get single user
 */
function getUser() {
    global $db, $userId, $role;
    
    $id = (int)($_GET['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['error' => 'User ID required'], 400);
    }
    
    // Check access
    if ($role !== 'manager' && $id !== $userId) {
        jsonResponse(['error' => 'Access denied'], 403);
    }
    
    $stmt = $db->query(
        "SELECT u.*, p.full_name, p.phone, p.address, p.company, p.timezone, p.avatar,
                b.currency, b.balance
         FROM users u 
         LEFT JOIN user_profiles p ON u.id = p.user_id 
         LEFT JOIN user_balances b ON u.id = b.user_id 
         WHERE u.id = ?",
        [$id]
    );
    
    $user = $stmt->fetch();
    if (!$user) {
        jsonResponse(['error' => 'User not found'], 404);
    }
    
    // Remove sensitive data
    unset($user['password']);
    
    jsonResponse(['success' => true, 'data' => $user]);
}

/**
 * Get users for dropdown
 */
function getUsersDropdown() {
    global $db, $userId, $role;
    
    $search = sanitize($_GET['q'] ?? '');
    $userRole = sanitize($_GET['role'] ?? '');
    $page = (int)($_GET['page'] ?? 1);
    $max = (int)($_GET['max'] ?? 25);
    $offset = ($page - 1) * $max;
    
    $where = "status = 'active'";
    $params = [];
    
    if ($role === 'manager') {
        $where .= " AND parent_id = ? AND role = ?";
        $params[] = $userId;
        $params[] = $userRole ?: 'agent';
    } elseif ($role === 'agent') {
        $where .= " AND parent_id = ? AND role = 'client'";
        $params[] = $userId;
    } else {
        jsonResponse(['results' => [], 'pagination' => ['more' => false]]);
    }
    
    if ($search) {
        $where .= " AND (username LIKE ? OR (SELECT full_name FROM user_profiles WHERE user_id = users.id) LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $stmt = $db->query(
        "SELECT id, username as title FROM users WHERE $where ORDER BY username LIMIT $max OFFSET $offset",
        $params
    );
    $results = $stmt->fetchAll();
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE $where", $params);
    $total = (int)$stmt->fetch()['count'];
    
    jsonResponse([
        'results' => array_map(function($r) {
            return ['id' => $r['id'], 'title' => $r['title']];
        }, $results),
        'pagination' => ['more' => ($offset + $max) < $total]
    ]);
}

/**
 * Get current user profile
 */
function getProfile() {
    global $db, $userId;
    
    $stmt = $db->query(
        "SELECT u.*, p.full_name, p.phone, p.address, p.company, p.timezone, p.avatar
         FROM users u 
         LEFT JOIN user_profiles p ON u.id = p.user_id 
         WHERE u.id = ?",
        [$userId]
    );
    
    $user = $stmt->fetch();
    unset($user['password']);
    
    // Get balances
    $stmt = $db->query("SELECT currency, balance FROM user_balances WHERE user_id = ?", [$userId]);
    $user['balances'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    jsonResponse(['success' => true, 'data' => $user]);
}

/**
 * Get user activity
 */
function getActivity() {
    global $db, $userId, $role;
    
    $targetUserId = (int)($_GET['user_id'] ?? $userId);
    $limit = (int)($_GET['limit'] ?? 50);
    
    // Check access
    if ($role !== 'manager' && $targetUserId !== $userId) {
        jsonResponse(['error' => 'Access denied'], 403);
    }
    
    $stmt = $db->query(
        "SELECT * FROM user_activity WHERE user_id = ? ORDER BY created_at DESC LIMIT ?",
        [$targetUserId, $limit]
    );
    
    $activities = $stmt->fetchAll();
    jsonResponse(['success' => true, 'data' => $activities]);
}

/**
 * Get users for DataTables
 */
function getUsersDatatable() {
    global $db, $userId, $role;
    
    $draw = (int)($_GET['draw'] ?? 1);
    $start = (int)($_GET['start'] ?? 0);
    $length = (int)($_GET['length'] ?? 25);
    $search = $_GET['search']['value'] ?? '';
    $userRole = sanitize($_GET['role'] ?? 'agent');
    
    $where = "u.role = ?";
    $params = [$userRole];
    
    if ($role === 'manager') {
        $where .= " AND u.parent_id = ?";
        $params[] = $userId;
    } elseif ($role === 'agent') {
        $where .= " AND u.parent_id = ? AND u.role = 'client'";
        $params[] = $userId;
    } else {
        jsonResponse(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
    }
    
    if ($search) {
        $where .= " AND (u.username LIKE ? OR p.full_name LIKE ? OR u.email LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    // Total
    $stmt = $db->query(
        "SELECT COUNT(*) as count FROM users u LEFT JOIN user_profiles p ON u.id = p.user_id WHERE $where",
        $params
    );
    $recordsTotal = (int)$stmt->fetch()['count'];
    
    // Data
    $stmt = $db->query(
        "SELECT u.*, p.full_name, p.phone,
                (SELECT COUNT(*) FROM sms_numbers WHERE assigned_to = u.id) as number_count,
                (SELECT GROUP_CONCAT(CONCAT(currency, ':', balance) SEPARATOR ',') FROM user_balances WHERE user_id = u.id) as balances
         FROM users u 
         LEFT JOIN user_profiles p ON u.id = p.user_id 
         WHERE $where 
         ORDER BY u.created_at DESC 
         LIMIT $start, $length",
        $params
    );
    $data = $stmt->fetchAll();
    
    $result = [];
    foreach ($data as $row) {
        $statusBadge = $row['status'] === 'active' ? 
            '<span class="label label-success">Active</span>' : 
            '<span class="label label-important">Inactive</span>';
        
        $lastLogin = $row['last_login'] ? date('Y-m-d H:i', strtotime($row['last_login'])) : 'Never';
        
        $balances = [];
        if ($row['balances']) {
            foreach (explode(',', $row['balances']) as $b) {
                list($cur, $bal) = explode(':', $b);
                $balances[] = "$cur: " . number_format($bal, 2);
            }
        }
        
        $result[] = [
            $row['username'],
            $row['full_name'] ?? '-',
            $row['email'] ?? '-',
            $row['number_count'],
            implode(', ', $balances) ?: '-',
            $statusBadge,
            $lastLogin,
            '<a href="#" class="btn btn-mini btn-info" onclick="viewUser(' . $row['id'] . ')"><i class="icon-eye-open"></i></a> ' .
            '<a href="#" class="btn btn-mini btn-warning" onclick="editUser(' . $row['id'] . ')"><i class="icon-edit"></i></a> ' .
            ($row['status'] === 'active' ? 
                '<a href="#" class="btn btn-mini btn-danger" onclick="toggleUser(' . $row['id'] . ', \'inactive\')"><i class="icon-ban-circle"></i></a>' : 
                '<a href="#" class="btn btn-mini btn-success" onclick="toggleUser(' . $row['id'] . ', \'active\')"><i class="icon-ok"></i></a>')
        ];
    }
    
    jsonResponse([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsTotal,
        'data' => $result
    ]);
}

/**
 * Create new user (agent/client)
 */
function createUser() {
    global $db, $userId, $role;
    
    $input = getJsonInput();
    if (empty($input)) {
        $input = $_POST;
    }
    
    $username = sanitize($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $email = sanitize($input['email'] ?? '');
    $userRole = sanitize($input['role'] ?? 'client');
    $fullName = sanitize($input['full_name'] ?? '');
    $phone = sanitize($input['phone'] ?? '');
    
    if (empty($username) || empty($password)) {
        jsonResponse(['error' => 'Username and password are required'], 400);
    }
    
    // Check permissions
    if ($role === 'manager' && !in_array($userRole, ['agent', 'client'])) {
        jsonResponse(['error' => 'Managers can only create agents or clients'], 403);
    }
    
    if ($role === 'agent' && $userRole !== 'client') {
        jsonResponse(['error' => 'Agents can only create clients'], 403);
    }
    
    // Check if username exists
    $stmt = $db->query("SELECT id FROM users WHERE username = ?", [$username]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Username already exists'], 400);
    }
    
    $hashedPassword = generateHash($password);
    $apiToken = generateToken();
    
    // Determine parent
    $parentId = $userId;
    if ($role === 'agent') {
        // Agent creates client, parent is agent
        $parentId = $userId;
    } elseif ($role === 'manager' && $userRole === 'client') {
        // Manager creates client directly - need to assign to agent
        $parentId = (int)($input['agent_id'] ?? $userId);
    }
    
    $db->beginTransaction();
    
    try {
        $db->query(
            "INSERT INTO users (username, password, email, role, parent_id, status, api_token) VALUES (?, ?, ?, ?, ?, 'active', ?)",
            [$username, $hashedPassword, $email, $userRole, $parentId, $apiToken]
        );
        
        $newUserId = $db->lastInsertId();
        
        $db->query(
            "INSERT INTO user_profiles (user_id, full_name, phone) VALUES (?, ?, ?)",
            [$newUserId, $fullName, $phone]
        );
        
        // Create default balances
        $db->query(
            "INSERT INTO user_balances (user_id, currency, balance) VALUES (?, 'USD', 0), (?, 'EUR', 0), (?, 'GBP', 0)",
            [$newUserId, $newUserId, $newUserId]
        );
        
        $db->commit();
        
        logActivity($userId, 'user_created', "Created $userRole: $username");
        createNotification($newUserId, 'Account Created', "Your account has been created. Welcome to MBC SMS!", 'success');
        
        jsonResponse(['success' => true, 'message' => ucfirst($userRole) . ' created successfully', 'id' => $newUserId]);
        
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['error' => 'Failed to create user: ' . $e->getMessage()], 500);
    }
}

/**
 * Update user
 */
function updateUser() {
    global $db, $userId, $role;
    
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['error' => 'User ID required'], 400);
    }
    
    // Check access
    $stmt = $db->query("SELECT * FROM users WHERE id = ?", [$id]);
    $targetUser = $stmt->fetch();
    
    if (!$targetUser) {
        jsonResponse(['error' => 'User not found'], 404);
    }
    
    if ($role !== 'manager' && $id !== $userId) {
        jsonResponse(['error' => 'Access denied'], 403);
    }
    
    $userFields = [];
    $userParams = [];
    $profileFields = [];
    $profileParams = [];
    
    if (isset($input['email'])) {
        $userFields[] = "email = ?";
        $userParams[] = sanitize($input['email']);
    }
    
    if (isset($input['status']) && $role === 'manager') {
        $userFields[] = "status = ?";
        $userParams[] = sanitize($input['status']);
    }
    
    foreach (['full_name', 'phone', 'address', 'company', 'timezone'] as $field) {
        if (isset($input[$field])) {
            $profileFields[] = "$field = ?";
            $profileParams[] = sanitize($input[$field]);
        }
    }
    
    if (!empty($userFields)) {
        $userParams[] = $id;
        $db->query("UPDATE users SET " . implode(', ', $userFields) . " WHERE id = ?", $userParams);
    }
    
    if (!empty($profileFields)) {
        $profileParams[] = $id;
        $db->query("UPDATE user_profiles SET " . implode(', ', $profileFields) . " WHERE user_id = ?", $profileParams);
    }
    
    logActivity($userId, 'user_updated', "Updated user ID: $id");
    
    jsonResponse(['success' => true, 'message' => 'User updated successfully']);
}

/**
 * Delete user
 */
function deleteUser() {
    global $db, $userId, $role;
    
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['error' => 'User ID required'], 400);
    }
    
    if ($role !== 'manager') {
        jsonResponse(['error' => 'Only managers can delete users'], 403);
    }
    
    $stmt = $db->query(
        "SELECT * FROM users WHERE id = ? AND parent_id = ?",
        [$id, $userId]
    );
    $targetUser = $stmt->fetch();
    
    if (!$targetUser) {
        jsonResponse(['error' => 'User not found'], 404);
    }
    
    // Check for assigned numbers
    $stmt = $db->query("SELECT COUNT(*) as count FROM sms_numbers WHERE assigned_to = ?", [$id]);
    if ((int)$stmt->fetch()['count'] > 0) {
        jsonResponse(['error' => 'Cannot delete user with assigned numbers'], 400);
    }
    
    $db->query("DELETE FROM users WHERE id = ?", [$id]);
    
    logActivity($userId, 'user_deleted', "Deleted user: " . $targetUser['username']);
    
    jsonResponse(['success' => true, 'message' => 'User deleted successfully']);
}

/**
 * Update profile
 */
function updateProfile() {
    global $db, $userId;
    
    $input = getJsonInput();
    if (empty($input)) {
        $input = $_POST;
    }
    
    $fullName = sanitize($input['full_name'] ?? '');
    $phone = sanitize($input['phone'] ?? '');
    $address = sanitize($input['address'] ?? '');
    $company = sanitize($input['company'] ?? '');
    $timezone = sanitize($input['timezone'] ?? 'UTC');
    $email = sanitize($input['email'] ?? '');
    
    // Update profile
    $db->query(
        "INSERT INTO user_profiles (user_id, full_name, phone, address, company, timezone) 
         VALUES (?, ?, ?, ?, ?, ?) 
         ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone), address = VALUES(address), company = VALUES(company), timezone = VALUES(timezone)",
        [$userId, $fullName, $phone, $address, $company, $timezone]
    );
    
    // Update email
    if ($email) {
        $db->query("UPDATE users SET email = ? WHERE id = ?", [$email, $userId]);
    }
    
    logActivity($userId, 'profile_updated', 'Profile updated');
    
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
    
    if (empty($currentPassword) || empty($newPassword)) {
        jsonResponse(['error' => 'Current and new password are required'], 400);
    }
    
    if ($newPassword !== $confirmPassword) {
        jsonResponse(['error' => 'Passwords do not match'], 400);
    }
    
    if (strlen($newPassword) < 6) {
        jsonResponse(['error' => 'Password must be at least 6 characters'], 400);
    }
    
    // Verify current password
    $stmt = $db->query("SELECT password FROM users WHERE id = ?", [$userId]);
    $user = $stmt->fetch();
    
    if ($user['password'] !== generateHash($currentPassword)) {
        jsonResponse(['error' => 'Current password is incorrect'], 400);
    }
    
    $hashedPassword = generateHash($newPassword);
    $db->query("UPDATE users SET password = ? WHERE id = ?", [$hashedPassword, $userId]);
    
    logActivity($userId, 'password_changed', 'Password changed');
    
    jsonResponse(['success' => true, 'message' => 'Password changed successfully']);
}

/**
 * Toggle user status
 */
function toggleUserStatus() {
    global $db, $userId, $role;
    
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    $status = sanitize($input['status'] ?? 'active');
    
    if (!$id || !in_array($status, ['active', 'inactive', 'suspended'])) {
        jsonResponse(['error' => 'Invalid parameters'], 400);
    }
    
    if ($role !== 'manager') {
        jsonResponse(['error' => 'Only managers can change user status'], 403);
    }
    
    $db->query("UPDATE users SET status = ? WHERE id = ? AND parent_id = ?", [$status, $id, $userId]);
    
    logActivity($userId, 'user_status_changed', "Changed user ID $id status to $status");
    
    jsonResponse(['success' => true, 'message' => 'User status updated']);
}
