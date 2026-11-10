#!/bin/bash
# Remove .html extensions from all internal links in manager HTML files

cd /var/www/html/mbc-sms/Manager_frontend/ints/manager

# Remove .html from href links (internal links only)
for file in *.html; do
    sed -i 's/href="\([^"]*\)\.html"/href="\1"/g' "$file"
done

echo "Removed .html extensions from all internal links"
