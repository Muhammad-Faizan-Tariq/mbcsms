# SMPP & HTTP Configuration for MBC SMS

## Overview
The MBC SMS Panel supports both **SMPP** and **HTTP** protocols for SMS delivery through Jasmin SMS Gateway.

## Jasmin SMS Gateway Configuration

### SMPP Interface (Port 2775)
Used by SMPP clients to submit SMS messages.

```bash
# Check Jasmin SMPP port
netstat -tlnp | grep 2775

# Jasmin SMPP configuration file
/etc/jasmin/jasmin.cfg
```

### HTTP API Interface (Port 1401)
Used for HTTP-based SMS submission.

```bash
# Check Jasmin HTTP API port
netstat -tlnp | grep 1401

# Test HTTP API
curl "http://localhost:1401/send?username=USERNAME&password=PASSWORD&to=DESTINATION&from=SENDER&content=MESSAGE"
```

## Panel Integration

### Database Tables for SMPP/HTTP
```sql
-- SMPP/HTTP configuration per user
INSERT INTO api_config (manager_id, config_key, config_value) VALUES
(1, 'smpp_host', '185.255.94.89'),
(1, 'smpp_port', '2775'),
(1, 'smpp_username', 'manager_smpp'),
(1, 'smpp_password', 'smpp_password'),
(1, 'http_api_url', 'http://185.255.94.89:1401/send'),
(1, 'http_api_key', 'api_key_here');
```

### API Endpoint for SMPP/HTTP
```
POST /api/sms/send
{
    "protocol": "smpp",  // or "http"
    "to": "447123456789",
    "from": "MBCSMS",
    "message": "Test message"
}
```

## Firewall Configuration

```bash
# Allow SMPP port
ufw allow 2775/tcp

# Allow HTTP API port
ufw allow 1401/tcp

# Allow web panel (port 80)
ufw allow 80/tcp

# Reload firewall
ufw reload
```

## Jasmin Gateway Setup

### Install Jasmin
```bash
apt-get install jasmin-sms-gateway
systemctl start jasmin
systemctl enable jasmin
```

### Configure SMPP User
```bash
# Connect to Jasmin CLI
jasminctl

# Add SMPP user
user -j smpp_user -a smpp_pass

# Add HTTP API user
http -j http_user -a http_pass
```

### Jasmin Web Panel
Access Jasmin admin panel at: `http://185.255.94.89:8080`

## Testing

### Test SMPP Connection
```bash
# Using smppbox test tool
smppbox -h 185.255.94.89 -p 2775 -u smpp_user -a smpp_pass
```

### Test HTTP API
```bash
curl "http://185.255.94.89:1401/send?username=http_user&password=http_pass&to=447123456789&from=MBCSMS&content=Test"
```

## Integration with MBC Panel

The MBC SMS Panel will use:
- **SMPP**: For high-volume bulk SMS
- **HTTP**: For individual messages and API integrations

Both protocols connect to Jasmin Gateway which handles the actual SMS routing.
