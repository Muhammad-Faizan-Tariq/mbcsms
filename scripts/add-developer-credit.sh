#!/bin/bash
# Add developer credit watermark to all HTML pages

# Add to manager pages
cd /var/www/html/mbc-sms/Manager_frontend/ints/manager
for file in *.html; do
    if ! grep -q "RobertBotDev" "$file"; then
        # Insert credit before </body> using awk
        awk 'BEGIN{p=1} /<\/body>/{print "<div style=\"position: fixed; bottom: 0; left: 0; right: 0; text-align: center; padding: 8px; background: rgba(0,0,0,0.8); z-index: 9999;\"><p style=\"margin: 0; color: #888; font-size: 11px;\">Developed by <a href=\"https://t.me/RobertBotDev\" target=\"_blank\" style=\"color: #667eea; font-weight: 500;\">@RobertBotDev</a></p></div>"} {print} END{if(p) print}' "$file" > "$file.tmp" && mv "$file.tmp" "$file"
        echo "Added credit to $file"
    fi
done

# Add to agent pages
cd /var/www/html/mbc-sms/Agent_frontend/ints/agent
for file in *.html; do
    if ! grep -q "RobertBotDev" "$file"; then
        awk 'BEGIN{p=1} /<\/body>/{print "<div style=\"position: fixed; bottom: 0; left: 0; right: 0; text-align: center; padding: 8px; background: rgba(0,0,0,0.8); z-index: 9999;\"><p style=\"margin: 0; color: #888; font-size: 11px;\">Developed by <a href=\"https://t.me/RobertBotDev\" target=\"_blank\" style=\"color: #667eea; font-weight: 500;\">@RobertBotDev</a></p></div>"} {print} END{if(p) print}' "$file" > "$file.tmp" && mv "$file.tmp" "$file"
        echo "Added credit to $file"
    fi
done

# Add to client pages
cd /var/www/html/mbc-sms/client_frontend/ints/client
for file in *.html; do
    if ! grep -q "RobertBotDev" "$file"; then
        awk 'BEGIN{p=1} /<\/body>/{print "<div style=\"position: fixed; bottom: 0; left: 0; right: 0; text-align: center; padding: 8px; background: rgba(0,0,0,0.8); z-index: 9999;\"><p style=\"margin: 0; color: #888; font-size: 11px;\">Developed by <a href=\"https://t.me/RobertBotDev\" target=\"_blank\" style=\"color: #667eea; font-weight: 500;\">@RobertBotDev</a></p></div>"} {print} END{if(p) print}' "$file" > "$file.tmp" && mv "$file.tmp" "$file"
        echo "Added credit to $file"
    fi
done

echo "Developer credit added to all pages"
