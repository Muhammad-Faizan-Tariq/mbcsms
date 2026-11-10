<?php
/**
 * MBC SMS - SMPP Test Script
 * Run this to test SMPP connection and webhook
 * 
 * Usage: php scripts/test-smpp.php
 */

// Load configuration
require_once __DIR__ . '/../public/api/config.php';

echo "\n========================================\n";
echo "MBC SMS - SMPP Integration Test\n";
echo "========================================\n\n";

// Test 1: Database Connection
echo "[1/5] Testing Database Connection...\n";
try {
    $db = Database::getInstance();
    $stmt = $db->query("SELECT COUNT(*) as count FROM sms_numbers");
    $result = $stmt->fetch();
    echo "   ✓ Database connected\n";
    echo "   - Total numbers: " . $result['count'] . "\n";
} catch (Exception $e) {
    echo "   ✗ Database error: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: SMPP Tables Exist
echo "\n[2/5] Checking SMPP Tables...\n";
$tables = ['smpp_config', 'smpp_cdr', 'smpp_users'];
foreach ($tables as $table) {
    try {
        $stmt = $db->query("SELECT 1 FROM $table LIMIT 1");
        echo "   ✓ Table '$table' exists\n";
    } catch (Exception $e) {
        echo "   ✗ Table '$table' missing - run migration\n";
    }
}

// Test 3: SMPP Configuration
echo "\n[3/5] Checking SMPP Configuration...\n";
$stmt = $db->query("SELECT * FROM smpp_config WHERE status = 'active' LIMIT 1");
$config = $stmt->fetch();
if ($config) {
    echo "   ✓ Active configuration found\n";
    echo "   - Config name: " . $config['config_name'] . "\n";
    echo "   - Host: " . $config['inbound_host'] . ":" . $config['inbound_port'] . "\n";
    echo "   - System ID: " . $config['inbound_system_id'] . "\n";
    echo "   - Total received: " . $config['total_received'] . "\n";
} else {
    echo "   ✗ No active SMPP configuration\n";
}

// Test 4: Jasmin Service Status
echo "\n[4/5] Checking Jasmin Service...\n";
$pid = exec('pgrep -f jasmind');
if ($pid) {
    echo "   ✓ Jasmin is running (PID: $pid)\n";
} else {
    echo "   ✗ Jasmin is not running\n";
    echo "   - Start with: systemctl start jasmin\n";
    echo "   - Or: supervisorctl start jasmin\n";
}

// Test 5: Test Webhook Call
echo "\n[5/5] Testing Webhook...\n";
$testData = [
    'action' => 'receive',
    'message_id' => 'TEST_' . time(),
    'source_addr' => '447987654321',
    'destination_addr' => '447123456789', // Change to a valid number in your DB
    'short_message' => 'Test SMS from SMPP test script',
    'submit_date' => date('Y-m-d H:i:s'),
    'connector_id' => 'test_connector'
];

// Get a valid number from database
$stmt = $db->query("SELECT number FROM sms_numbers WHERE status = 'assigned' LIMIT 1");
$validNumber = $stmt->fetch();
if ($validNumber) {
    $testData['destination_addr'] = $validNumber['number'];
    echo "   - Using test number: " . $validNumber['number'] . "\n";
}

// Simulate webhook call
$webhookUrl = 'http://localhost/api/smpp.php?action=receive&api_key=MBC_WEBHOOK_SECURE_2026';
$ch = curl_init($webhookUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-API-Key: MBC_WEBHOOK_SECURE_2026'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    echo "   ✓ Webhook responded successfully\n";
    $responseData = json_decode($response, true);
    if ($responseData && $responseData['success']) {
        echo "   - CDR ID: " . ($responseData['data']['cdr_id'] ?? 'N/A') . "\n";
        echo "   - Payouts: " . json_encode($responseData['data']['payouts'] ?? []) . "\n";
    }
} else {
    echo "   ✗ Webhook failed (HTTP $httpCode)\n";
    echo "   - Response: $response\n";
}

// Summary
echo "\n========================================\n";
echo "Test Complete\n";
echo "========================================\n\n";

echo "Next Steps:\n";
echo "1. Ensure Jasmin is installed: bash scripts/install-jasmin.sh\n";
echo "2. Run database migration: mysql -u root -p mbc_sms < database/migrations/smpp_tables.sql\n";
echo "3. Start Jasmin: systemctl start jasmin\n";
echo "4. Configure supplier with SMPP credentials\n";
echo "5. Monitor logs: tail -f /var/log/jasmin/jasmin.log\n\n";

echo "SMPP Credentials for Supplier:\n";
echo "  Host: 185.255.94.89\n";
echo "  Port: 2775\n";
echo "  System ID: mbc_supplier\n";
echo "  Password: Supplier\$Pass2026!\n";
echo "  Bind Type: TR (Transceive)\n\n";
