<?php
/**
 * MBC SMS - News API
 * Manage news/announcements
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
                listNews();
                break;
            case 'get':
                getNews();
                break;
            case 'datatable':
                getNewsDatatable();
                break;
            default:
                listNews();
        }
        break;
    case 'POST':
        switch ($action) {
            case 'create':
                createNews();
                break;
            case 'update':
                updateNews();
                break;
            case 'delete':
                deleteNews();
                break;
            default:
                jsonResponse(['error' => 'Invalid action'], 400);
        }
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

function listNews() {
    global $db, $userId, $role;
    
    $limit = (int)($_GET['limit'] ?? 10);
    
    // Filter by target role
    $where = "status = 'published' AND (target_role = 'all' OR target_role = ?)";
    $params = [$role];
    
    $stmt = $db->query(
        "SELECT n.*, u.username as author_name 
         FROM news n 
         JOIN users u ON n.author_id = u.id 
         WHERE $where 
         ORDER BY n.published_at DESC 
         LIMIT ?",
        array_merge($params, [$limit])
    );
    
    $news = $stmt->fetchAll();
    jsonResponse(['success' => true, 'data' => $news]);
}

function getNews() {
    global $db;
    
    $id = (int)($_GET['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['error' => 'News ID required'], 400);
    }
    
    $stmt = $db->query(
        "SELECT n.*, u.username as author_name 
         FROM news n 
         JOIN users u ON n.author_id = u.id 
         WHERE n.id = ?",
        [$id]
    );
    
    $news = $stmt->fetch();
    if (!$news) {
        jsonResponse(['error' => 'News not found'], 404);
    }
    
    jsonResponse(['success' => true, 'data' => $news]);
}

function getNewsDatatable() {
    global $db, $userId, $role;
    
    if ($role !== 'manager') {
        jsonResponse(['error' => 'Only managers can manage news'], 403);
    }
    
    $draw = (int)($_GET['draw'] ?? 1);
    $start = (int)($_GET['start'] ?? 0);
    $length = (int)($_GET['length'] ?? 25);
    $search = $_GET['search']['value'] ?? '';
    
    $where = "author_id = ?";
    $params = [$userId];
    
    if ($search) {
        $where .= " AND (title LIKE ? OR content LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM news WHERE $where", $params);
    $recordsTotal = (int)$stmt->fetch()['count'];
    
    $stmt = $db->query(
        "SELECT * FROM news WHERE $where ORDER BY created_at DESC LIMIT $start, $length",
        $params
    );
    $data = $stmt->fetchAll();
    
    $result = [];
    foreach ($data as $row) {
        $statusBadge = $row['status'] === 'published' ? 
            '<span class="label label-success">Published</span>' : 
            '<span class="label label-warning">Draft</span>';
        
        $targetBadge = '<span class="label label-info">' . ucfirst($row['target_role']) . '</span>';
        
        $result[] = [
            $row['title'],
            substr(strip_tags($row['content']), 0, 100) . '...',
            $targetBadge,
            $statusBadge,
            $row['published_at'],
            '<a href="#" class="btn btn-mini btn-info" onclick="viewNews(' . $row['id'] . ')"><i class="icon-eye-open"></i></a> ' .
            '<a href="#" class="btn btn-mini btn-warning" onclick="editNews(' . $row['id'] . ')"><i class="icon-edit"></i></a> ' .
            '<a href="#" class="btn btn-mini btn-danger" onclick="deleteNews(' . $row['id'] . ')"><i class="icon-trash"></i></a>'
        ];
    }
    
    jsonResponse([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsTotal,
        'data' => $result
    ]);
}

function createNews() {
    global $db, $userId, $role;
    
    if ($role !== 'manager') {
        jsonResponse(['error' => 'Only managers can create news'], 403);
    }
    
    $input = getJsonInput();
    if (empty($input)) {
        $input = $_POST;
    }
    
    $title = sanitize($input['title'] ?? '');
    $content = $input['content'] ?? '';
    $targetRole = sanitize($input['target_role'] ?? 'all');
    $status = sanitize($input['status'] ?? 'published');
    
    if (empty($title) || empty($content)) {
        jsonResponse(['error' => 'Title and content are required'], 400);
    }
    
    $db->query(
        "INSERT INTO news (author_id, title, content, target_role, status, published_at) VALUES (?, ?, ?, ?, ?, NOW())",
        [$userId, $title, $content, $targetRole, $status]
    );
    
    $newsId = $db->lastInsertId();
    
    // Notify relevant users
    $targetWhere = $targetRole === 'all' ? "status = 'active'" : "role = ? AND status = 'active'";
    $params = $targetRole === 'all' ? [] : [$targetRole];
    
    $stmt = $db->query("SELECT id FROM users WHERE $targetWhere", $params);
    $users = $stmt->fetchAll();
    
    foreach ($users as $u) {
        createNotification($u['id'], 'New Announcement', $title, 'info');
    }
    
    logActivity($userId, 'news_created', "Created news: $title");
    
    jsonResponse(['success' => true, 'message' => 'News created successfully', 'id' => $newsId]);
}

function updateNews() {
    global $db, $userId, $role;
    
    if ($role !== 'manager') {
        jsonResponse(['error' => 'Only managers can update news'], 403);
    }
    
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['error' => 'News ID required'], 400);
    }
    
    $stmt = $db->query("SELECT * FROM news WHERE id = ? AND author_id = ?", [$id, $userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'News not found'], 404);
    }
    
    $fields = [];
    $params = [];
    
    foreach (['title', 'content', 'target_role', 'status'] as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = ?";
            $params[] = sanitize($input[$field]);
        }
    }
    
    if (!empty($fields)) {
        $params[] = $id;
        $params[] = $userId;
        $db->query("UPDATE news SET " . implode(', ', $fields) . " WHERE id = ? AND author_id = ?", $params);
    }
    
    logActivity($userId, 'news_updated', "Updated news ID: $id");
    
    jsonResponse(['success' => true, 'message' => 'News updated successfully']);
}

function deleteNews() {
    global $db, $userId, $role;
    
    if ($role !== 'manager') {
        jsonResponse(['error' => 'Only managers can delete news'], 403);
    }
    
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['error' => 'News ID required'], 400);
    }
    
    $db->query("DELETE FROM news WHERE id = ? AND author_id = ?", [$id, $userId]);
    
    logActivity($userId, 'news_deleted', "Deleted news ID: $id");
    
    jsonResponse(['success' => true, 'message' => 'News deleted successfully']);
}
