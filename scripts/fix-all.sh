#!/bin/bash
# Complete fix script for MBC SMS Panel

echo "=========================================="
echo "  MBC SMS - Complete Fix Script"
echo "=========================================="

cd /var/www/html/mbc-sms

# 1. Fix Nginx to port 80
echo "[1/5] Fixing Nginx port 80..."
sed -i 's/listen 8081/listen 80/g' nginx.conf
cp nginx.conf /etc/nginx/sites-available/mbc-sms.conf
rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/mbc-sms.conf /etc/nginx/sites-enabled/

# 2. Fix .env
echo "[2/5] Fixing .env..."
sed -i 's|APP_URL=.*|APP_URL=http://185.255.94.89|' .env
sed -i 's/SALT=.*/SALT=mbc_sms_secure_salt_2024/' .env

# 3. Remove .html from links
echo "[3/5] Removing .html extensions..."
for dir in Manager_frontend/ints/manager Agent_frontend/ints/agent client_frontend/ints/client; do
    cd /var/www/html/mbc-sms/$dir
    for file in *.html; do
        sed -i 's/href="\([^"]*\)\.html"/href="\1"/g' "$file"
    done
done

# 4. Create login_attempts table
echo "[4/5] Creating login_attempts table..."
mysql -u mbc_user -pmbc_secure_pass_2024 mbc_sms -e "
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempts INT DEFAULT 1,
    locked_until DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_username (username),
    KEY idx_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
" 2>/dev/null

# 5. Restart services
echo "[5/5] Restarting services..."
nginx -t && systemctl restart nginx
systemctl restart php8.3-fpm

echo ""
echo "=========================================="
echo "  Done! Access: http://185.255.94.89/ints/login"
echo "  Credentials: MBCSMS / MBCSMS"
echo "=========================================="
