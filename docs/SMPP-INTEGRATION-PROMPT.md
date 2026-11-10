# SMPP Integration Prompt for MBC SMS Panel

## Use this prompt in a new chat to implement SMPP integration:

---

## PROMPT START

```
I need to integrate SMPP server (Jasmin SMS Gateway) into my MBC SMS Panel for IPRN earning model.

## Current Setup:
- VPS: Ubuntu 22.04
- IP: 185.255.94.89
- Database: MySQL (mbc_sms)
- Panel Structure:
  - Manager Panel: /var/www/html/mbc-sms/public/ints/manager/
  - Agent Panel: /var/www/html/mbc-sms/public/ints/agent/
  - Client Panel: /var/www/html/mbc-sms/public/ints/client/
  - API: /var/www/html/mbc-sms/public/api/

## Database Tables:
- users (id, username, password, role, parent_id, balance)
- sms_ranges (id, range_name, prefix, manager_id, payout_rate)
- sms_numbers (id, number, range_id, assigned_to, status)
- sms_cdr (id, message_id, source, destination, message, user_payout, date_time)

## Business Model:
1. Manager uploads IPRN numbers to ranges
2. Agent requests numbers from ranges
3. Agent assigns numbers to their clients
4. Mobile users send OTP SMS to IPRN numbers
5. Each SMS earns money (payout)
6. Manager sees ALL CDR
7. Agent sees CDR for their numbers + clients' numbers
8. Client sees CDR for their numbers only

## SMPP Requirements:

### 1. Install Jasmin SMS Gateway
- Install Jasmin on VPS
- Configure SMPP server on port 2775
- Configure HTTP API on port 1401

### 2. Inbound SMPP (Receive SMS)
- Supplier routes IPRN numbers to MY SMPP server
- SMPP credentials to give supplier:
  - Host: 185.255.94.89
  - Port: 2775
  - System ID: mbc_inbound
  - Password: [secure password]

### 3. CDR Processing Flow
When SMS arrives at Jasmin:
1. Parse incoming SMS (DELIVER_SM PDU)
2. Extract: source (sender), destination (IPRN number), message
3. Find which user owns the destination number
4. Calculate payout based on range rate
5. Insert into sms_cdr table
6. Update user balance
7. Display in panel CDR reports

### 4. Database Tables Needed
```sql
-- SMPP configuration
CREATE TABLE smpp_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    config_name VARCHAR(50),
    inbound_host VARCHAR(100) DEFAULT '0.0.0.0',
    inbound_port INT DEFAULT 2775,
    system_id VARCHAR(50),
    password VARCHAR(100),
    status ENUM('active', 'inactive') DEFAULT 'active'
);

-- SMPP CDR (linked to main CDR)
CREATE TABLE smpp_cdr (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    message_id VARCHAR(50),
    source_addr VARCHAR(20),
    destination_addr VARCHAR(20),
    short_message TEXT,
    message_status VARCHAR(20),
    submit_date DATETIME,
    done_date DATETIME,
    cdr_id INT,  -- Link to sms_cdr
    FOREIGN KEY (cdr_id) REFERENCES sms_cdr(id)
);
```

### 5. Jasmin Configuration
Configure Jasmin to:
- Listen on 0.0.0.0:2775 for incoming SMPP
- Log all messages to MySQL database
- Process DELIVER_SM PDUs (incoming SMS)
- Call PHP webhook for each SMS

### 6. PHP Webhook for SMS Processing
Create /api/cdr.php with action=receive:
- Receive SMS data from Jasmin
- Process and log to database
- Update balances
- Return acknowledgment

### 7. Manager Panel - SMPP Configuration Page
Add page to display:
- SMPP server status (running/stopped)
- SMPP credentials for supplier
- Connection statistics
- Recent incoming SMS

### 8. Agent/Client - API Access
- Agents get HTTP API key to fetch their CDR
- Clients get HTTP API key to fetch their CDR
- NO SMPP access for agents/clients (only manager controls SMPP)

## Routing Flow:
```
[Mobile User] sends SMS to IPRN number
        ↓
[Carrier/Network] routes to supplier
        ↓
[Supplier] routes to MY SMPP (185.255.94.89:2775)
        ↓
[Jasmin SMPP Server] receives SMS
        ↓
[PHP Webhook] processes SMS:
  - Find number owner
  - Calculate payout
  - Log to database
  - Update balance
        ↓
[Database] stores CDR
        ↓
[Panel] displays CDR:
  - Manager sees ALL
  - Agent sees their numbers + clients
  - Client sees their numbers only
```

## Please provide:
1. Jasmin installation script
2. Jasmin configuration for MySQL logging
3. PHP webhook code for SMS processing
4. Manager panel SMPP status page
5. CDR processing logic with balance updates
6. Test script to verify SMPP connection
```

---

## PROMPT END

---

## How Routing Works:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        SMS ROUTING FLOW                                      │
│                                                                             │
│  STEP 1: Mobile User Sends SMS                                              │
│  ───────────────────────────────────                                        │
│  [App User] ──SMS──► [IPRN Number: 447123456789]                           │
│                          │                                                  │
│                          ▼                                                  │
│  STEP 2: Carrier Routes to Supplier                                         │
│  ─────────────────────────────────                                          │
│  [Mobile Carrier] recognizes IPRN number                                   │
│          │                                                                  │
│          │ Routes to supplier who owns this number block                    │
│          ▼                                                                  │
│  [SUPPLIER's System]                                                        │
│          │                                                                  │
│          │ Supplier has configured:                                         │
│          │ "Route 447123456789 to 185.255.94.89:2775"                      │
│          ▼                                                                  │
│  STEP 3: Supplier Routes to YOUR SMPP                                       │
│  ───────────────────────────────────                                        │
│  [Supplier SMPP] ──SMPP──► [YOUR SMPP: 185.255.94.89:2775]                 │
│                                   │                                         │
│                                   ▼                                         │
│  STEP 4: YOUR SMPP Receives SMS                                             │
│  ─────────────────────────────────                                          │
│  [Jasmin SMPP Server]                                                       │
│          │                                                                  │
│          │ Parses:                                                          │
│          │ - source: 447987654321 (sender)                                  │
│          │ - destination: 447123456789 (IPRN number)                        │
│          │ - message: "Your OTP is 123456"                                  │
│          ▼                                                                  │
│  STEP 5: PHP Processes SMS                                                  │
│  ─────────────────────────────                                              │
│  [PHP Webhook /api/cdr.php?action=receive]                                  │
│          │                                                                  │
│          │ 1. Find number owner:                                            │
│          │    SELECT assigned_to FROM sms_numbers                           │
│          │    WHERE number = '447123456789'                                 │
│          │    Result: user_id = 45 (client)                                 │
│          │                                                                  │
│          │ 2. Find agent:                                                   │
│          │    SELECT parent_id FROM users WHERE id = 45                     │
│          │    Result: agent_id = 12                                         │
│          │                                                                  │
│          │ 3. Find manager:                                                 │
│          │    SELECT parent_id FROM users WHERE id = 12                     │
│          │    Result: manager_id = 1                                        │
│          │                                                                  │
│          │ 4. Calculate payouts:                                            │
│          │    - Manager earns: $0.02                                        │
│          │    - Agent earns: $0.03                                          │
│          │    - Client earns: $0.05                                         │
│          │                                                                  │
│          │ 5. Insert CDR:                                                   │
│          │    INSERT INTO sms_cdr (source, destination, message,            │
│          │    user_id, user_payout, manager_payout, agent_payout)           │
│          │                                                                  │
│          │ 6. Update balances:                                              │
│          │    UPDATE user_balances SET balance = balance + 0.05             │
│          │    WHERE user_id = 45                                            │
│          ▼                                                                  │
│  STEP 6: Panel Displays CDR                                                 │
│  ─────────────────────────────                                              │
│  [Database] ──query──► [Panel CDR Page]                                     │
│                                                                             │
│  Manager sees: ALL CDR (all numbers)                                        │
│  Agent sees: CDR for their numbers + their clients' numbers                 │
│  Client sees: CDR for their numbers only                                    │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Key Points:

### 1. SMPP is ONLY for Manager
- Manager controls the SMPP server
- Supplier connects to manager's SMPP
- Agents and clients DO NOT connect to SMPP
- They use HTTP API instead

### 2. Number Ownership Chain
```
Manager (id=1)
    └── Agent (id=12, parent_id=1)
            └── Client (id=45, parent_id=12)
                    └── Number (447123456789, assigned_to=45)
```

### 3. Payout Distribution
```
SMS arrives at number 447123456789
    │
    ├── Client (owner) gets: $0.05
    ├── Agent (parent) gets: $0.03
    └── Manager (root) gets: $0.02
    ────────────────────────────────
    Total payout per SMS: $0.10
```

### 4. CDR Visibility
```sql
-- Manager sees ALL
SELECT * FROM sms_cdr 
WHERE range_id IN (SELECT id FROM sms_ranges WHERE manager_id = 1)

-- Agent sees their numbers + clients' numbers
SELECT * FROM sms_cdr 
WHERE user_id = 12  -- agent's own numbers
   OR user_id IN (SELECT id FROM users WHERE parent_id = 12)  -- clients' numbers

-- Client sees only their numbers
SELECT * FROM sms_cdr 
WHERE user_id = 45
```

---

## Files to Create:

| File | Purpose |
|------|---------|
| `/scripts/install-jasmin.sh` | Install Jasmin SMS Gateway |
| `/etc/jasmin/jasmin.cfg` | Jasmin configuration |
| `/public/api/smpp.php` | SMPP status API |
| `/public/api/cdr.php` | CDR processing webhook |
| `/public/ints/manager/SMPPConfig.html` | SMPP config page |
| `/app/Helpers/SMPP.php` | SMPP helper class |

---

## Test Commands:

```bash
# Test SMPP connection
telnet 185.255.94.89 2775

# Check Jasmin status
systemctl status jasmin

# View Jasmin logs
tail -f /var/log/jasmin/smpp-server.log

# Test HTTP API
curl "http://185.255.94.89:1401/send?username=test&password=test&to=447123456789&from=TEST&content=Hello"
```

---

*Copy the PROMPT section above to a new chat for SMPP implementation.*
