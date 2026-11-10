#!/bin/bash
# Fix port 80 and update all configurations

echo "=== Fixing Port 80 Configuration ==="

# 1. Update nginx config to port 80
cd /var/www/html/mbc-sms
cp nginx.conf /etc/nginx/sites-available/mbc-sms.conf

# 2. Remove default site if blocking port 80
rm -f /etc/nginx/sites-enabled/default

# 3. Ensure symlink exists
ln -sf /etc/nginx/sites-available/mbc-sms.conf /etc/nginx/sites-enabled/

# 4. Check what's listening on port 80
echo "=== Checking port 80 ==="
netstat -tlnp | grep :80

# 5. Kill any process blocking port 80 (if needed)
if fuser 80/tcp 2>/dev/null; then
    echo "Killing process on port 80..."
    fuser -k 80/tcp
fi

# 6. Test nginx config
echo "=== Testing Nginx ==="
nginx -t

# 7. Restart nginx
echo "=== Restarting Nginx ==="
systemctl restart nginx
systemctl enable nginx

# 8. Verify
echo "=== Verifying ==="
sleep 2
curl -I http://localhost/ints/login 2>/dev/null | head -5

echo ""
echo "=== Done! Access at: http://185.255.94.89/ints/login ==="
