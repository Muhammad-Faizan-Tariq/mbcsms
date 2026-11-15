<?php
/**
 * MBC SMS - SMPP API
 *
 *  POST ?action=receive  (webhook, called by Jasmin for every inbound SMS; key protected)
 *  GET  ?action=status   (manager)  Jasmin reachable? counters
 *  GET  ?action=config   (manager)  credentials to give the supplier
 *  GET  ?action=stats    (any role) SMS counters scoped to the caller
 */

require_once __DIR__ . '/config.php';

// Payout split on every SMS (share of the number's rate)
const SMPP_AGENT_OVERRIDE = 0.10;    // agent earns 10% extra when a client of theirs gets the SMS
const SMPP_MANAGER_SHARE  = 0.05;    // manager earns 5%

$db = Database::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? 'receive';

if ($action === 'receive') {
    handleReceive();
    exit;
}

// everything else needs a logged-in session
session_name(SESSION_NAME);
session_start();
if (empty($_SESSION['logged_in'])) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];

switch ($action) {
    case 'status':
        requireManager();
        getSmppStatus();
        break;
    case 'config':
        requireManager();
        getSmppConfig();
        break;
    case 'stats':
        getSmppStats();
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

function requireManager() {
    global $role;
    if ($role !== 'manager') {
        jsonResponse(['error' => 'Manager access required'], 403);
    }
}

/* ------------------------------------------------------------------ */
/* Webhook                                                             */
/* ------------------------------------------------------------------ */

function webhookKey() {
    $key = getenv('SMPP_WEBHOOK_KEY');
    return ($key !== false && $key !== '') ? $key : null;
}

function handleReceive() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(['error' => 'Method not allowed'], 405);
    }

    $expected = webhookKey();
    $given = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? $_POST['api_key'] ?? '';
    if ($expected === null || !hash_equals($expected, (string)$given)) {
        jsonResponse(['error' => 'Invalid API key'], 401);
    }

    $data = getJsonInput();
    if (empty($data)) {
        $data = array_merge($_GET, $_POST);
    }

    // Jasmin posts: id, from, to, content, origin-connector ...
    $fromJasmin = isset($data['origin-connector']) || (isset($data['id']) && isset($data['content']));

    try {
        $result = receiveSms($data);
    } catch (Throwable $e) {
        error_log('SMPP receive failed: ' . $e->getMessage());
        // non-200 makes Jasmin retry the delivery
        http_response_code(500);
        header('Content-Type: text/plain');
        echo 'ERROR';
        exit;
    }

    if ($fromJasmin) {
        header('Content-Type: text/plain');
        echo 'ACK/Jasmin';
        exit;
    }
    jsonResponse($result);
}

/**
 * Store one inbound SMS: raw smpp_cdr row + billed sms_cdr row + balances.
 */
function receiveSms($data) {
    global $db;

    $messageId = substr((string)($data['message_id'] ?? $data['id'] ?? uniqid('sms_', true)), 0, 50);
    $source = substr(trim((string)($data['source_addr'] ?? $data['from'] ?? $data['cli'] ?? '')), 0, 20);
    $dest = substr(preg_replace('/[^0-9A-Za-z]/', '', (string)($data['destination_addr'] ?? $data['to'] ?? '')), 0, 20);
    $text = (string)($data['short_message'] ?? $data['content'] ?? $data['message'] ?? '');
    $connector = substr((string)($data['connector_id'] ?? $data['origin-connector'] ?? ''), 0, 50);
    $submitDate = date('Y-m-d H:i:s');

    if ($source === '' || $dest === '') {
        return ['success' => false, 'error' => 'Missing source or destination'];
    }

    // duplicate delivery (Jasmin retry) -> acknowledge, don't bill twice
    $dup = $db->query("SELECT id FROM smpp_cdr WHERE message_id = ? LIMIT 1", [$messageId])->fetch();
    if ($dup) {
        return ['success' => true, 'message' => 'Duplicate ignored', 'smpp_cdr_id' => (int)$dup['id']];
    }

    $db->query(
        "INSERT INTO smpp_cdr (message_id, source_addr, destination_addr, short_message, message_status, submit_date, connector_id)
         VALUES (?, ?, ?, ?, 'delivered', ?, ?)",
        [$messageId, $source, $dest, $text, $submitDate, $connector]
    );
    $smppCdrId = (int)$db->lastInsertId();

    $number = $db->query(
        "SELECT n.id, n.assigned_to, n.is_test, n.pay_term, n.payout_rate,
                r.id AS range_id, r.range_name, r.manager_id, r.currency,
                r.payout_1_1, r.payout_7_1, r.payout_7_7, r.payout_30_45
         FROM sms_numbers n
         JOIN sms_ranges r ON n.range_id = r.id
         WHERE n.number = ? AND n.status = 'assigned' AND n.assigned_to IS NOT NULL",
        [$dest]
    )->fetch();

    if (!$number) {
        logActivity(null, 'sms_unassigned_number', "SMS from $source to unassigned/unknown number $dest");
        return ['success' => true, 'message' => 'Logged, number not assigned', 'smpp_cdr_id' => $smppCdrId];
    }

    // rate: the rate stored on the number wins, else the range rate for its pay term
    $termColumn = ['7/1' => 'payout_7_1', '7/7' => 'payout_7_7', '30/45' => 'payout_30_45'][$number['pay_term'] ?? ''] ?? 'payout_1_1';
    $rate = (float)($number['payout_rate'] ?: ($number[$termColumn] ?: $number['payout_1_1']));
    $isTest = (int)$number['is_test'] === 1;
    if ($isTest) {
        $rate = 0.0;
    }

    $ownerId = (int)$number['assigned_to'];
    $owner = $db->query("SELECT id, role, parent_id FROM users WHERE id = ?", [$ownerId])->fetch();
    if (!$owner) {
        return ['success' => true, 'message' => 'Logged, owner missing', 'smpp_cdr_id' => $smppCdrId];
    }

    // agent override only applies when the owner is a client of an agent
    $agentId = null;
    if ($owner['role'] === 'client' && $owner['parent_id']) {
        $parent = $db->query("SELECT id, role FROM users WHERE id = ?", [$owner['parent_id']])->fetch();
        if ($parent && $parent['role'] === 'agent') {
            $agentId = (int)$parent['id'];
        }
    }

    $ownerPayout   = $rate;
    $agentPayout   = $agentId ? round($rate * SMPP_AGENT_OVERRIDE, 6) : 0.0;
    $managerPayout = round($rate * SMPP_MANAGER_SHARE, 6);
    $managerId = (int)$number['manager_id'];
    $currency = $number['currency'];

    $db->beginTransaction();
    try {
        $db->query(
            "INSERT INTO sms_cdr
               (date_time, range_id, number, cli, user_id, sms_count, sms_type, currency,
                my_payout, user_payout, agent_payout, profit, smpp_message_id, smpp_status, connector_id, processed_at)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, 'delivered', ?, NOW())",
            [
                $submitDate, $number['range_id'], $dest, $source, $ownerId,
                $isTest ? 'test' : 'general', $currency,
                $managerPayout, $ownerPayout, $agentPayout, $managerPayout,
                $messageId, $connector
            ]
        );
        $cdrId = (int)$db->lastInsertId();
        $db->query("UPDATE smpp_cdr SET cdr_id = ? WHERE id = ?", [$cdrId, $smppCdrId]);

        if ($ownerPayout > 0) updateBalance($ownerId, $currency, $ownerPayout, 'credit');
        if ($agentId && $agentPayout > 0) updateBalance($agentId, $currency, $agentPayout, 'credit');
        if ($managerPayout > 0) updateBalance($managerId, $currency, $managerPayout, 'credit');

        $db->query("UPDATE smpp_config SET total_received = total_received + 1, last_received_at = NOW() WHERE status = 'active'");
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    createNotification($ownerId, 'SMS Received', "New SMS on $dest from $source", 'info');
    if ($agentId) {
        createNotification($agentId, 'Client SMS', "Client received SMS on $dest", 'info');
    }

    return [
        'success' => true,
        'message' => 'SMS processed',
        'cdr_id' => $cdrId,
        'smpp_cdr_id' => $smppCdrId,
        'number' => $dest,
        'range' => $number['range_name'],
        'payouts' => ['owner' => $ownerPayout, 'agent' => $agentPayout, 'manager' => $managerPayout, 'currency' => $currency]
    ];
}

/* ------------------------------------------------------------------ */
/* Manager endpoints                                                   */
/* ------------------------------------------------------------------ */

function jasminHost() {
    return getenv('JASMIN_HOST') ?: 'jasmin';
}

function jasminReachable() {
    $errno = 0; $errstr = '';
    $sock = @fsockopen(jasminHost(), 2775, $errno, $errstr, 2);
    if ($sock) {
        fclose($sock);
        return true;
    }
    return false;
}

function getSmppStatus() {
    global $db;

    $config = $db->query("SELECT * FROM smpp_config WHERE status = 'active' LIMIT 1")->fetch();
    $hourly = (int)$db->query("SELECT COUNT(*) c FROM smpp_cdr WHERE submit_date >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetch()['c'];
    $daily = (int)$db->query("SELECT COUNT(*) c FROM smpp_cdr WHERE submit_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch()['c'];

    jsonResponse([
        'success' => true,
        'status' => [
            'jasmin_running' => jasminReachable(),
            'smpp_port' => (int)(getenv('SMPP_PUBLIC_PORT') ?: 2775),
            'config' => $config ? [
                'name' => $config['config_name'],
                'total_received' => (int)$config['total_received'],
                'last_received' => $config['last_received_at']
            ] : null,
            'stats' => ['hourly' => $hourly, 'daily' => $daily]
        ]
    ]);
}

function getSmppConfig() {
    $host = getenv('SMPP_PUBLIC_HOST') ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/:\d+$/', '', $host);
    $key = webhookKey();

    jsonResponse([
        'success' => true,
        'config' => [
            'host' => $host,
            'port' => (int)(getenv('SMPP_PUBLIC_PORT') ?: 2775),
            'system_id' => getenv('SMPP_SUPPLIER_USER') ?: '',
            'password' => getenv('SMPP_SUPPLIER_PASS') ?: '',
            'webhook_key_set' => $key !== null
        ]
    ]);
}

function getSmppStats() {
    global $db, $userId, $role;

    $today = date('Y-m-d');
    $month = date('Y-m');
    $stats = [];

    if ($role === 'manager') {
        $stats['today'] = (int)$db->query("SELECT COUNT(*) c FROM smpp_cdr WHERE DATE(submit_date) = ?", [$today])->fetch()['c'];
        $stats['month'] = (int)$db->query("SELECT COUNT(*) c FROM smpp_cdr WHERE DATE_FORMAT(submit_date, '%Y-%m') = ?", [$month])->fetch()['c'];
        $stats['by_range'] = $db->query(
            "SELECT r.range_name, COUNT(*) AS count
             FROM smpp_cdr s
             JOIN sms_cdr c ON s.cdr_id = c.id
             JOIN sms_ranges r ON c.range_id = r.id
             WHERE DATE_FORMAT(s.submit_date, '%Y-%m') = ?
             GROUP BY r.id ORDER BY count DESC LIMIT 5",
            [$month]
        )->fetchAll();
    } elseif ($role === 'agent') {
        $stats['today'] = (int)$db->query(
            "SELECT COUNT(*) c FROM smpp_cdr s JOIN sms_cdr c ON s.cdr_id = c.id
             WHERE DATE(s.submit_date) = ? AND (c.user_id = ? OR c.user_id IN (SELECT id FROM users WHERE parent_id = ?))",
            [$today, $userId, $userId]
        )->fetch()['c'];
    } else {
        $stats['today'] = (int)$db->query(
            "SELECT COUNT(*) c FROM smpp_cdr s JOIN sms_cdr c ON s.cdr_id = c.id
             WHERE DATE(s.submit_date) = ? AND c.user_id = ?",
            [$today, $userId]
        )->fetch()['c'];
    }

    jsonResponse(['success' => true, 'stats' => $stats]);
}
