<?php
/**
 * MBC SMS - Notifications API
 */

require_once __DIR__ . '/config.php';

session_name(SESSION_NAME);
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$userId = $_SESSION['user_id'];
$db = Database::getInstance();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list':
                listNotifications();
                break;
            case 'unread':
                getUnreadCount();
                break;
            default:
                listNotifications();
        }
        break;
    case 'POST':
        switch ($action) {
            case 'mark_read':
                markAsRead();
                break;
            case 'mark_all_read':
                markAllAsRead();
                break;
            case 'delete':
                deleteNotification();
                break;
            default:
                jsonResponse(['error' => 'Invalid action'], 400);
        }
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

function listNotifications() {
    global $db, $userId;
    
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = (int)($_GET['offset'] ?? 0);
    $unreadOnly = !empty($_GET['unread']);
    
    $where = "user_id = ?";
    $params = [$userId];
    
    if ($unreadOnly) {
        $where .= " AND is_read = 0";
    }
    
    $stmt = $db->query(
        "SELECT * FROM notifications WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );
    
    $notifications = $stmt->fetchAll();
    jsonResponse(['success' => true, 'data' => $notifications]);
}

function getUnreadCount() {
    global $db, $userId;
    
    $stmt = $db->query(
        "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0",
        [$userId]
    );
    
    $count = (int)$stmt->fetch()['count'];
    jsonResponse(['success' => true, 'count' => $count]);
}

function markAsRead() {
    global $db, $userId;
    
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['error' => 'Notification ID required'], 400);
    }
    
    $db->query(
        "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?",
        [$id, $userId]
    );
    
    jsonResponse(['success' => true, 'message' => 'Notification marked as read']);
}

function markAllAsRead() {
    global $db, $userId;
    
    $db->query("UPDATE notifications SET is_read = 1 WHERE user_id = ?", [$userId]);
    
    jsonResponse(['success' => true, 'message' => 'All notifications marked as read']);
}

function deleteNotification() {
    global $db, $userId;
    
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['error' => 'Notification ID required'], 400);
    }
    
    $db->query("DELETE FROM notifications WHERE id = ? AND user_id = ?", [$id, $userId]);
    
    jsonResponse(['success' => true, 'message' => 'Notification deleted']);
}
