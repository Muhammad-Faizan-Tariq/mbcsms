<?php
/**
 * MBC SMS - Dashboard API
 * Returns dashboard statistics for all roles
 */

require_once __DIR__ . '/config.php';

session_name(SESSION_NAME);
session_start();

// Check authentication
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];
$db = Database::getInstance();

$action = $_GET['action'] ?? 'stats';

switch ($action) {
    case 'stats':
        getDashboardStats();
        break;
    case 'chart':
        getChartData();
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

function getDashboardStats() {
    global $db, $userId, $role;
    
    $stats = [];
    $today = date('Y-m-d');
    $thisMonth = date('Y-m');
    
    switch ($role) {
        case 'manager':
            // Total agents
            $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'agent' AND parent_id = ?", [$userId]);
            $stats['total_agents'] = (int)$stmt->fetch()['count'];
            
            // Total clients (all clients under all agents)
            $stmt = $db->query(
                "SELECT COUNT(*) as count FROM users WHERE role = 'client' AND parent_id IN 
                 (SELECT id FROM users WHERE role = 'agent' AND parent_id = ?)",
                [$userId]
            );
            $stats['total_clients'] = (int)$stmt->fetch()['count'];
            
            // Total ranges
            $stmt = $db->query("SELECT COUNT(*) as count FROM sms_ranges WHERE manager_id = ?", [$userId]);
            $stats['total_ranges'] = (int)$stmt->fetch()['count'];
            
            // Total numbers
            $stmt = $db->query(
                "SELECT COUNT(*) as count FROM sms_numbers WHERE range_id IN 
                 (SELECT id FROM sms_ranges WHERE manager_id = ?)",
                [$userId]
            );
            $stats['total_numbers'] = (int)$stmt->fetch()['count'];
            
            // Available numbers
            $stmt = $db->query(
                "SELECT COUNT(*) as count FROM sms_numbers WHERE status = 'available' AND range_id IN 
                 (SELECT id FROM sms_ranges WHERE manager_id = ?)",
                [$userId]
            );
            $stats['available_numbers'] = (int)$stmt->fetch()['count'];
            
            // Today's SMS
            $stmt = $db->query(
                "SELECT COUNT(*) as count, COALESCE(SUM(my_payout), 0) as payout 
                 FROM sms_cdr WHERE DATE(date_time) = ? AND range_id IN 
                 (SELECT id FROM sms_ranges WHERE manager_id = ?)",
                [$today, $userId]
            );
            $result = $stmt->fetch();
            $stats['today_sms'] = (int)$result['count'];
            $stats['today_payout'] = (float)$result['payout'];
            
            // Monthly SMS
            $stmt = $db->query(
                "SELECT COUNT(*) as count, COALESCE(SUM(my_payout), 0) as payout 
                 FROM sms_cdr WHERE DATE_FORMAT(date_time, '%Y-%m') = ? AND range_id IN 
                 (SELECT id FROM sms_ranges WHERE manager_id = ?)",
                [$thisMonth, $userId]
            );
            $result = $stmt->fetch();
            $stats['month_sms'] = (int)$result['count'];
            $stats['month_payout'] = (float)$result['payout'];
            
            // Balances
            $stmt = $db->query("SELECT currency, balance FROM user_balances WHERE user_id = ?", [$userId]);
            $stats['balances'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Pending requests
            $stmt = $db->query(
                "SELECT COUNT(*) as count FROM number_requests WHERE status = 'pending' AND range_id IN 
                 (SELECT id FROM sms_ranges WHERE manager_id = ?)",
                [$userId]
            );
            $stats['pending_requests'] = (int)$stmt->fetch()['count'];
            
            // Last 7 days SMS
            $stmt = $db->query(
                "SELECT COUNT(*) as count, COALESCE(SUM(my_payout), 0) as payout 
                 FROM sms_cdr WHERE date_time >= DATE_SUB(?, INTERVAL 7 DAY) AND range_id IN 
                 (SELECT id FROM sms_ranges WHERE manager_id = ?)",
                [$today, $userId]
            );
            $result = $stmt->fetch();
            $stats['last_7_days_sms'] = (int)$result['count'];
            $stats['last_7_days_payout'] = (float)$result['payout'];
            
            // Daily trend (last 7 days)
            $stmt = $db->query(
                "SELECT DATE(date_time) as date, COUNT(*) as count, SUM(my_payout) as payout 
                 FROM sms_cdr WHERE date_time >= DATE_SUB(?, INTERVAL 7 DAY) AND range_id IN 
                 (SELECT id FROM sms_ranges WHERE manager_id = ?)
                 GROUP BY DATE(date_time) ORDER BY date",
                [$today, $userId]
            );
            $dailyData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $stats['daily_trend'] = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $stats['daily_trend'][] = (int)($dailyData[$date] ?? 0);
            }
            
            // Top agents by SMS volume
            $stmt = $db->query(
                "SELECT u.username, COUNT(*) as sms_count, SUM(c.my_payout) as payout 
                 FROM sms_cdr c 
                 JOIN users u ON c.user_id = u.id 
                 WHERE DATE_FORMAT(c.date_time, '%Y-%m') = ? 
                 AND u.parent_id = ?
                 GROUP BY c.user_id 
                 ORDER BY sms_count DESC 
                 LIMIT 5",
                [$thisMonth, $userId]
            );
            $stats['top_agents'] = $stmt->fetchAll();
            
            // SMS by range
            $stmt = $db->query(
                "SELECT r.range_name, COUNT(*) as sms_count, SUM(c.my_payout) as payout 
                 FROM sms_cdr c 
                 JOIN sms_ranges r ON c.range_id = r.id 
                 WHERE DATE_FORMAT(c.date_time, '%Y-%m') = ? AND r.manager_id = ?
                 GROUP BY c.range_id 
                 ORDER BY sms_count DESC 
                 LIMIT 5",
                [$thisMonth, $userId]
            );
            $stats['top_ranges'] = $stmt->fetchAll();
            
            // Recent activity
            $stmt = $db->query(
                "SELECT al.*, u.username 
                 FROM activity_log al 
                 JOIN users u ON al.user_id = u.id 
                 WHERE al.user_id = ? OR u.parent_id = ? 
                 ORDER BY al.created_at DESC 
                 LIMIT 10",
                [$userId, $userId]
            );
            $stats['recent_activity'] = $stmt->fetchAll();
            
            // Yesterday SMS
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $stmt = $db->query(
                "SELECT COUNT(*) as count, COALESCE(SUM(my_payout), 0) as payout 
                 FROM sms_cdr WHERE DATE(date_time) = ? AND range_id IN 
                 (SELECT id FROM sms_ranges WHERE manager_id = ?)",
                [$yesterday, $userId]
            );
            $result = $stmt->fetch();
            $stats['yesterday_sms'] = (int)$result['count'];
            $stats['yesterday_payout'] = (float)$result['payout'];
            
            // New accounts this month (agents + clients)
            $stmt = $db->query(
                "SELECT COUNT(*) as count FROM users 
                 WHERE DATE_FORMAT(created_at, '%Y-%m') = ? 
                 AND (role = 'agent' AND parent_id = ? OR parent_id IN (SELECT id FROM users WHERE parent_id = ?))",
                [$thisMonth, $userId, $userId]
            );
            $stats['new_accounts'] = (int)$stmt->fetch()['count'];
            
            // New numbers this month
            $stmt = $db->query(
                "SELECT COUNT(*) as count FROM sms_numbers 
                 WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND range_id IN 
                 (SELECT id FROM sms_ranges WHERE manager_id = ?)",
                [$thisMonth, $userId]
            );
            $stats['new_numbers'] = (int)$stmt->fetch()['count'];
            
            // Recent clients (last 5)
            $stmt = $db->query(
                "SELECT u.id, u.username, u.email, u.status, u.created_at, up.skype 
                 FROM users u 
                 LEFT JOIN user_profiles up ON u.id = up.user_id 
                 WHERE (u.role = 'agent' AND u.parent_id = ?) OR u.parent_id IN (SELECT id FROM users WHERE parent_id = ?)
                 ORDER BY u.created_at DESC LIMIT 5",
                [$userId, $userId]
            );
            $stats['recent_clients'] = $stmt->fetchAll();
            
            // Hierarchy payouts - Agent earnings (from agent_payout column)
            $stmt = $db->query(
                "SELECT COALESCE(SUM(agent_payout), 0) as today_agent 
                 FROM sms_cdr WHERE DATE(date_time) = ? AND range_id IN 
                 (SELECT id FROM sms_ranges WHERE manager_id = ?)",
                [$today, $userId]
            );
            $stats['agent_payouts']['today'] = (float)$stmt->fetch()['today_agent'];
            
            $stmt = $db->query(
                "SELECT COALESCE(SUM(agent_payout), 0) as month_agent 
                 FROM sms_cdr WHERE DATE_FORMAT(date_time, '%Y-%m') = ? AND range_id IN 
                 (SELECT id FROM sms_ranges WHERE manager_id = ?)",
                [$thisMonth, $userId]
            );
            $stats['agent_payouts']['month'] = (float)$stmt->fetch()['month_agent'];
            
            // Hierarchy payouts - Client earnings (from user_payout column)
            $stmt = $db->query(
                "SELECT COALESCE(SUM(user_payout), 0) as today_client 
                 FROM sms_cdr WHERE DATE(date_time) = ? AND range_id IN 
                 (SELECT id FROM sms_ranges WHERE manager_id = ?)",
                [$today, $userId]
            );
            $stats['client_payouts']['today'] = (float)$stmt->fetch()['today_client'];
            
            $stmt = $db->query(
                "SELECT COALESCE(SUM(user_payout), 0) as month_client 
                 FROM sms_cdr WHERE DATE_FORMAT(date_time, '%Y-%m') = ? AND range_id IN 
                 (SELECT id FROM sms_ranges WHERE manager_id = ?)",
                [$thisMonth, $userId]
            );
            $stats['client_payouts']['month'] = (float)$stmt->fetch()['month_client'];
            
            // Total hierarchy earnings
            $stmt = $db->query(
                "SELECT 
                    COALESCE(SUM(my_payout), 0) as manager_total,
                    COALESCE(SUM(agent_payout), 0) as agents_total,
                    COALESCE(SUM(user_payout), 0) as clients_total
                 FROM sms_cdr WHERE range_id IN 
                 (SELECT id FROM sms_ranges WHERE manager_id = ?)",
                [$userId]
            );
            $totals = $stmt->fetch();
            $stats['hierarchy_totals'] = [
                'manager' => (float)$totals['manager_total'],
                'agents' => (float)$totals['agents_total'],
                'clients' => (float)$totals['clients_total'],
                'total' => (float)($totals['manager_total'] + $totals['agents_total'] + $totals['clients_total'])
            ];
            break;
            
        case 'agent':
            // Get agent's parent (manager)
            $stmt = $db->query("SELECT parent_id FROM users WHERE id = ?", [$userId]);
            $managerId = $stmt->fetch()['parent_id'];
            
            // Total clients
            $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'client' AND parent_id = ?", [$userId]);
            $stats['total_clients'] = (int)$stmt->fetch()['count'];
            
            // Active clients (with SMS in last 7 days)
            $stmt = $db->query(
                "SELECT COUNT(DISTINCT user_id) as count FROM sms_cdr 
                 WHERE date_time >= DATE_SUB(?, INTERVAL 7 DAY) 
                 AND user_id IN (SELECT id FROM users WHERE parent_id = ?)",
                [$today, $userId]
            );
            $stats['active_clients'] = (int)$stmt->fetch()['count'];
            
            // Assigned numbers (agent's own + clients')
            $stmt = $db->query(
                "SELECT COUNT(*) as count FROM sms_numbers 
                 WHERE assigned_to = ? OR assigned_to IN (SELECT id FROM users WHERE parent_id = ?)",
                [$userId, $userId]
            );
            $stats['assigned_numbers'] = (int)$stmt->fetch()['count'];
            
            // Today's SMS (agent's own)
            $stmt = $db->query(
                "SELECT COUNT(*) as count, COALESCE(SUM(user_payout), 0) as payout 
                 FROM sms_cdr WHERE DATE(date_time) = ? AND user_id = ?",
                [$today, $userId]
            );
            $result = $stmt->fetch();
            $stats['today_sms'] = (int)$result['count'];
            $stats['today_payout'] = (float)$result['payout'];
            
            // Today's SMS (including clients)
            $stmt = $db->query(
                "SELECT COUNT(*) as count, COALESCE(SUM(user_payout), 0) as payout 
                 FROM sms_cdr WHERE DATE(date_time) = ? 
                 AND (user_id = ? OR user_id IN (SELECT id FROM users WHERE parent_id = ?))",
                [$today, $userId, $userId]
            );
            $result = $stmt->fetch();
            $stats['today_sms_total'] = (int)$result['count'];
            $stats['today_payout_total'] = (float)$result['payout'];
            
            // Last 7 days SMS
            $stmt = $db->query(
                "SELECT COUNT(*) as count, COALESCE(SUM(user_payout), 0) as payout 
                 FROM sms_cdr WHERE date_time >= DATE_SUB(?, INTERVAL 7 DAY) 
                 AND (user_id = ? OR user_id IN (SELECT id FROM users WHERE parent_id = ?))",
                [$today, $userId, $userId]
            );
            $result = $stmt->fetch();
            $stats['last_7_days_sms'] = (int)$result['count'];
            $stats['last_7_days_payout'] = (float)$result['payout'];
            
            // Monthly SMS (agent's own)
            $stmt = $db->query(
                "SELECT COUNT(*) as count, COALESCE(SUM(user_payout), 0) as payout 
                 FROM sms_cdr WHERE DATE_FORMAT(date_time, '%Y-%m') = ? AND user_id = ?",
                [$thisMonth, $userId]
            );
            $result = $stmt->fetch();
            $stats['month_sms'] = (int)$result['count'];
            $stats['month_payout'] = (float)$result['payout'];
            
            // Monthly SMS (including clients)
            $stmt = $db->query(
                "SELECT COUNT(*) as count, COALESCE(SUM(user_payout), 0) as payout 
                 FROM sms_cdr WHERE DATE_FORMAT(date_time, '%Y-%m') = ? 
                 AND (user_id = ? OR user_id IN (SELECT id FROM users WHERE parent_id = ?))",
                [$thisMonth, $userId, $userId]
            );
            $result = $stmt->fetch();
            $stats['month_sms_total'] = (int)$result['count'];
            $stats['month_payout_total'] = (float)$result['payout'];
            
            // Balances
            $stmt = $db->query("SELECT currency, balance FROM user_balances WHERE user_id = ?", [$userId]);
            $stats['balances'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Available ranges (from manager)
            $stmt = $db->query(
                "SELECT COUNT(*) as count FROM sms_ranges 
                 WHERE status = 'active' AND available_numbers > 0 AND manager_id = ?",
                [$managerId]
            );
            $stats['available_ranges'] = (int)$stmt->fetch()['count'];
            
            // Pending number requests
            $stmt = $db->query(
                "SELECT COUNT(*) as count FROM number_requests WHERE user_id = ? AND status = 'pending'",
                [$userId]
            );
            $stats['pending_requests'] = (int)$stmt->fetch()['count'];
            
            // Daily trend (last 7 days - including clients)
            $stmt = $db->query(
                "SELECT DATE(date_time) as date, COUNT(*) as count 
                 FROM sms_cdr WHERE date_time >= DATE_SUB(?, INTERVAL 7 DAY) 
                 AND (user_id = ? OR user_id IN (SELECT id FROM users WHERE parent_id = ?))
                 GROUP BY DATE(date_time) ORDER BY date",
                [$today, $userId, $userId]
            );
            $dailyData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $stats['daily_trend'] = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $stats['daily_trend'][] = (int)($dailyData[$date] ?? 0);
            }
            
            // Top clients by SMS volume
            $stmt = $db->query(
                "SELECT u.username, COUNT(*) as sms_count, SUM(c.user_payout) as payout 
                 FROM sms_cdr c 
                 JOIN users u ON c.user_id = u.id 
                 WHERE DATE_FORMAT(c.date_time, '%Y-%m') = ? 
                 AND u.parent_id = ?
                 GROUP BY c.user_id 
                 ORDER BY sms_count DESC 
                 LIMIT 5",
                [$thisMonth, $userId]
            );
            $stats['top_clients'] = $stmt->fetchAll();
            
            // Yesterday SMS
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $stmt = $db->query(
                "SELECT COUNT(*) as count, COALESCE(SUM(user_payout), 0) as payout 
                 FROM sms_cdr WHERE DATE(date_time) = ? 
                 AND (user_id = ? OR user_id IN (SELECT id FROM users WHERE parent_id = ?))",
                [$yesterday, $userId, $userId]
            );
            $result = $stmt->fetch();
            $stats['yesterday_sms'] = (int)$result['count'];
            $stats['yesterday_payout'] = (float)$result['payout'];
            
            // Recent clients (last 5)
            $stmt = $db->query(
                "SELECT u.id, u.username, u.email, u.status, u.created_at, up.phone 
                 FROM users u 
                 LEFT JOIN user_profiles up ON u.id = up.user_id 
                 WHERE u.role = 'client' AND u.parent_id = ?
                 ORDER BY u.created_at DESC LIMIT 5",
                [$userId]
            );
            $stats['recent_clients'] = $stmt->fetchAll();
            break;
            
        case 'client':
            // Assigned numbers
            $stmt = $db->query("SELECT COUNT(*) as count FROM sms_numbers WHERE assigned_to = ?", [$userId]);
            $stats['assigned_numbers'] = (int)$stmt->fetch()['count'];
            
            // Today's SMS
            $stmt = $db->query(
                "SELECT COUNT(*) as count, COALESCE(SUM(user_payout), 0) as payout 
                 FROM sms_cdr WHERE DATE(date_time) = ? AND user_id = ?",
                [$today, $userId]
            );
            $result = $stmt->fetch();
            $stats['today_sms'] = (int)$result['count'];
            $stats['today_payout'] = (float)$result['payout'];
            
            // Last 7 days SMS
            $stmt = $db->query(
                "SELECT COUNT(*) as count, COALESCE(SUM(user_payout), 0) as payout 
                 FROM sms_cdr WHERE DATE(date_time) >= DATE_SUB(?, INTERVAL 7 DAY) AND user_id = ?",
                [$today, $userId]
            );
            $result = $stmt->fetch();
            $stats['last_7_days_sms'] = (int)$result['count'];
            $stats['last_7_days_payout'] = (float)$result['payout'];
            
            // Yesterday SMS
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $stmt = $db->query(
                "SELECT COUNT(*) as count, COALESCE(SUM(user_payout), 0) as payout 
                 FROM sms_cdr WHERE DATE(date_time) = ? AND user_id = ?",
                [$yesterday, $userId]
            );
            $result = $stmt->fetch();
            $stats['yesterday_sms'] = (int)$result['count'];
            $stats['yesterday_payout'] = (float)$result['payout'];
            
            // Monthly SMS
            $stmt = $db->query(
                "SELECT COUNT(*) as count, COALESCE(SUM(user_payout), 0) as payout 
                 FROM sms_cdr WHERE DATE_FORMAT(date_time, '%Y-%m') = ? AND user_id = ?",
                [$thisMonth, $userId]
            );
            $result = $stmt->fetch();
            $stats['month_sms'] = (int)$result['count'];
            $stats['month_payout'] = (float)$result['payout'];
            
            // Balances
            $stmt = $db->query("SELECT currency, balance FROM user_balances WHERE user_id = ?", [$userId]);
            $stats['balances'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Available ranges for request
            $stmt = $db->query(
                "SELECT COUNT(*) as count FROM sms_ranges WHERE status = 'active' AND available_numbers > 0"
            );
            $stats['available_ranges'] = (int)$stmt->fetch()['count'];
            
            // Hourly trend (last 6 hours)
            $stmt = $db->query(
                "SELECT HOUR(date_time) as hour, COUNT(*) as count 
                 FROM sms_cdr WHERE date_time >= DATE_SUB(NOW(), INTERVAL 6 HOUR) AND user_id = ?
                 GROUP BY HOUR(date_time) ORDER BY hour",
                [$userId]
            );
            $hourlyData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $stats['hourly_trend'] = [];
            for ($i = 5; $i >= 0; $i--) {
                $hour = (int)date('H', strtotime("-$i hours"));
                $stats['hourly_trend'][] = (int)($hourlyData[$hour] ?? 0);
            }
            
            // Daily trend (last 7 days)
            $stmt = $db->query(
                "SELECT DATE(date_time) as date, COUNT(*) as count 
                 FROM sms_cdr WHERE date_time >= DATE_SUB(?, INTERVAL 7 DAY) AND user_id = ?
                 GROUP BY DATE(date_time) ORDER BY date",
                [$today, $userId]
            );
            $dailyData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $stats['daily_trend'] = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $stats['daily_trend'][] = (int)($dailyData[$date] ?? 0);
            }
            
            // Monthly trend (last 6 months)
            $stmt = $db->query(
                "SELECT DATE_FORMAT(date_time, '%Y-%m') as month, COUNT(*) as count 
                 FROM sms_cdr WHERE date_time >= DATE_SUB(?, INTERVAL 6 MONTH) AND user_id = ?
                 GROUP BY DATE_FORMAT(date_time, '%Y-%m') ORDER BY month",
                [$today, $userId]
            );
            $monthlyData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $stats['monthly_trend'] = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = date('Y-m', strtotime("-$i months"));
                $stats['monthly_trend'][] = (int)($monthlyData[$month] ?? 0);
            }
            break;
    }
    
    // Recent notifications
    $stmt = $db->query(
        "SELECT id, title, message, type, is_read, created_at FROM notifications 
         WHERE user_id = ? ORDER BY created_at DESC LIMIT 5",
        [$userId]
    );
    $stats['notifications'] = $stmt->fetchAll();
    
    // Unread notification count
    $stmt = $db->query(
        "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0",
        [$userId]
    );
    $stats['unread_notifications'] = (int)$stmt->fetch()['count'];
    
    // Recent ranges (for client dashboard)
    if ($role === 'client') {
        $stmt = $db->query(
            "SELECT id, range_name, prefix, test_number, currency, payout_1_1 
             FROM sms_ranges 
             WHERE status = 'active' AND available_numbers > 0 
             ORDER BY created_at DESC LIMIT 20",
            []
        );
        $stats['recent_ranges'] = $stmt->fetchAll();
    }
    
    // News (for all roles)
    $stmt = $db->query(
        "SELECT id, title, content, created_at 
         FROM news 
         WHERE status = 'published' AND (target_role = 'all' OR target_role = ?)
         ORDER BY created_at DESC LIMIT 5",
        [$role]
    );
    $stats['news'] = $stmt->fetchAll();
    
    jsonResponse(['success' => true, 'stats' => $stats]);
}

function getChartData() {
    global $db, $userId, $role;
    
    $period = $_GET['period'] ?? '7days';
    $chartType = $_GET['type'] ?? 'sms';
    
    // Determine date range
    switch ($period) {
        case '7days':
            $startDate = date('Y-m-d', strtotime('-7 days'));
            break;
        case '30days':
            $startDate = date('Y-m-d', strtotime('-30 days'));
            break;
        case '90days':
            $startDate = date('Y-m-d', strtotime('-90 days'));
            break;
        default:
            $startDate = date('Y-m-d', strtotime('-7 days'));
    }
    
    $endDate = date('Y-m-d');
    
    $data = [];
    
    switch ($chartType) {
        case 'sms':
            if ($role === 'manager') {
                $stmt = $db->query(
                    "SELECT DATE(date_time) as date, COUNT(*) as count, SUM(my_payout) as payout 
                     FROM sms_cdr WHERE DATE(date_time) >= ? AND DATE(date_time) <= ? 
                     AND range_id IN (SELECT id FROM sms_ranges WHERE manager_id = ?)
                     GROUP BY DATE(date_time) ORDER BY date",
                    [$startDate, $endDate, $userId]
                );
            } else {
                $stmt = $db->query(
                    "SELECT DATE(date_time) as date, COUNT(*) as count, SUM(user_payout) as payout 
                     FROM sms_cdr WHERE DATE(date_time) >= ? AND DATE(date_time) <= ? AND user_id = ?
                     GROUP BY DATE(date_time) ORDER BY date",
                    [$startDate, $endDate, $userId]
                );
            }
            $data = $stmt->fetchAll();
            break;
            
        case 'revenue':
            if ($role === 'manager') {
                $stmt = $db->query(
                    "SELECT currency, SUM(my_payout) as revenue 
                     FROM sms_cdr WHERE DATE(date_time) >= ? AND DATE(date_time) <= ? 
                     AND range_id IN (SELECT id FROM sms_ranges WHERE manager_id = ?)
                     GROUP BY currency",
                    [$startDate, $endDate, $userId]
                );
            } else {
                $stmt = $db->query(
                    "SELECT currency, SUM(user_payout) as revenue 
                     FROM sms_cdr WHERE DATE(date_time) >= ? AND DATE(date_time) <= ? AND user_id = ?
                     GROUP BY currency",
                    [$startDate, $endDate, $userId]
                );
            }
            $data = $stmt->fetchAll();
            break;
            
        case 'types':
            if ($role === 'manager') {
                $stmt = $db->query(
                    "SELECT sms_type, COUNT(*) as count 
                     FROM sms_cdr WHERE DATE(date_time) >= ? AND DATE(date_time) <= ? 
                     AND range_id IN (SELECT id FROM sms_ranges WHERE manager_id = ?)
                     GROUP BY sms_type",
                    [$startDate, $endDate, $userId]
                );
            } else {
                $stmt = $db->query(
                    "SELECT sms_type, COUNT(*) as count 
                     FROM sms_cdr WHERE DATE(date_time) >= ? AND DATE(date_time) <= ? AND user_id = ?
                     GROUP BY sms_type",
                    [$startDate, $endDate, $userId]
                );
            }
            $data = $stmt->fetchAll();
            break;
    }
    
    jsonResponse(['success' => true, 'data' => $data, 'period' => $period, 'type' => $chartType]);
}
