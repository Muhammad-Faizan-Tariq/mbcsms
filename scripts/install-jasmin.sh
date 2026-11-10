#!/bin/bash
# MBC SMS - Jasmin SMS Gateway Installation Script
# Run as root on Ubuntu 22.04

set -e

echo "=========================================="
echo "MBC SMS - Jasmin SMPP Gateway Installation"
echo "=========================================="

# Update system
echo "[1/8] Updating system..."
apt-get update && apt-get upgrade -y

# Install dependencies
echo "[2/8] Installing dependencies..."
apt-get install -y python3 python3-pip python3-venv mysql-server redis-server supervisor

# Create jasmin user
echo "[3/8] Creating jasmin user..."
useradd -r -s /bin/false jasmin 2>/dev/null || true

# Install Jasmin
echo "[4/8] Installing Jasmin SMS Gateway..."
pip3 install jasmin

# Create directories
echo "[5/8] Creating directories..."
mkdir -p /etc/jasmin
mkdir -p /var/log/jasmin
mkdir -p /var/lib/jasmin
chown -R jasmin:jasmin /var/log/jasmin /var/lib/jasmin

# Create Jasmin configuration
echo "[6/8] Creating Jasmin configuration..."
cat > /etc/jasmin/jasmin.cfg << 'JASMIN_CONFIG'
# Jasmin SMS Gateway Configuration
# MBC SMS Panel Integration

[smpp-server]
# SMPP Server Configuration
host = 0.0.0.0
port = 2775

# Timeout settings
timeout_connection = 30
timeout_bind = 30

[http-api]
# HTTP API Configuration
host = 127.0.0.1
port = 1401

[database]
# MySQL Database for CDR logging
type = mysql
host = localhost
port = 3306
database = mbc_sms
username = jasmin_user
password = CHANGE_THIS_PASSWORD

[logging]
# Logging configuration
log_level = INFO
log_file = /var/log/jasmin/jasmin.log
log_format = %(asctime)s - %(name)s - %(levelname)s - %(message)s

[store]
# Data store configuration
type = redis
host = localhost
port = 6379
db = 0
JASMIN_CONFIG

# Create supervisor configuration
echo "[7/8] Creating supervisor configuration..."
cat > /etc/supervisor/conf.d/jasmin.conf << 'SUPERVISOR_CONFIG'
[program:jasmin]
command=/usr/local/bin/jasmind.py -c /etc/jasmin/jasmin.cfg
user=jasmin
autostart=true
autorestart=true
startsecs=10
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=/var/log/jasmin/supervisor.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
environment=HOME="/var/lib/jasmin"
SUPERVISOR_CONFIG

# Create systemd service (alternative to supervisor)
cat > /etc/systemd/system/jasmin.service << 'SYSTEMD_CONFIG'
[Unit]
Description=Jasmin SMS Gateway
After=network.target mysql.service redis.service

[Service]
Type=simple
User=jasmin
Group=jasmin
ExecStart=/usr/local/bin/jasmind.py -c /etc/jasmin/jasmin.cfg
Restart=on-failure
RestartSec=10
StandardOutput=syslog
StandardError=syslog
SyslogIdentifier=jasmin

[Install]
WantedBy=multi-user.target
SYSTEMD_CONFIG

# Create MySQL user for Jasmin
echo "[8/8] Creating MySQL user..."
echo "Enter MySQL root password:"
read -s MYSQL_ROOT_PASS

mysql -u root -p"$MYSQL_ROOT_PASS" << 'MYSQL_SCRIPT'
CREATE USER IF NOT EXISTS 'jasmin_user'@'localhost' IDENTIFIED BY 'JasminSecurePass2026!';
GRANT INSERT, SELECT ON mbc_sms.* TO 'jasmin_user'@'localhost';
GRANT INSERT, SELECT, UPDATE ON mbc_sms.sms_cdr TO 'jasmin_user'@'localhost';
GRANT INSERT, SELECT ON mbc_sms.smpp_cdr TO 'jasmin_user'@'localhost';
FLUSH PRIVILEGES;
MYSQL_SCRIPT

# Reload supervisor
echo "Reloading supervisor..."
supervisorctl reread
supervisorctl update

# Or enable systemd
systemctl daemon-reload
systemctl enable jasmin

echo ""
echo "=========================================="
echo "Jasmin Installation Complete!"
echo "=========================================="
echo ""
echo "Configuration file: /etc/jasmin/jasmin.cfg"
echo "Log directory: /var/log/jasmin/"
echo ""
echo "SMPP Server: 0.0.0.0:2775"
echo "HTTP API: 127.0.0.1:1401"
echo ""
echo "IMPORTANT: Change the database password in /etc/jasmin/jasmin.cfg"
echo ""
echo "Start Jasmin:"
echo "  systemctl start jasmin"
echo "  OR"
echo "  supervisorctl start jasmin"
echo ""
echo "Check status:"
echo "  systemctl status jasmin"
echo ""
