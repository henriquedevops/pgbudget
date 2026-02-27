#!/bin/bash
# Run as root: bash /var/www/html/pgbudget/scripts/setup-telegram-ssl.sh

set -e

SERVER_IP="191.252.195.118"
CERT="/etc/ssl/telegram-bot.pem"
KEY="/etc/ssl/telegram-bot.key"
VHOST="/etc/apache2/sites-available/pgbudget-ssl.conf"

echo "=== 1. Generating self-signed certificate for ${SERVER_IP} ==="
openssl req -newkey rsa:2048 -sha256 -nodes \
  -keyout "$KEY" \
  -x509 -days 3650 \
  -out "$CERT" \
  -subj "/CN=${SERVER_IP}"
chmod 600 "$KEY"
echo "    Certificate: $CERT"
echo "    Key:         $KEY"

echo ""
echo "=== 2. Enabling Apache SSL module ==="
a2enmod ssl

echo ""
echo "=== 3. Creating HTTPS virtual host ==="
cat > "$VHOST" << 'EOF'
<VirtualHost *:443>
    ServerName 191.252.195.118
    DocumentRoot /var/www/html

    SSLEngine on
    SSLCertificateFile    /etc/ssl/telegram-bot.pem
    SSLCertificateKeyFile /etc/ssl/telegram-bot.key

    Alias /pgbudget /var/www/html/pgbudget/public

    <Directory /var/www/html/pgbudget/public>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/pgbudget-ssl-error.log
    CustomLog ${APACHE_LOG_DIR}/pgbudget-ssl-access.log combined
</VirtualHost>
EOF
echo "    Written: $VHOST"

echo ""
echo "=== 4. Enabling site and restarting Apache ==="
a2ensite pgbudget-ssl.conf
systemctl restart apache2

echo ""
echo "=== Done! ==="
echo "Test: curl -k https://${SERVER_IP}/pgbudget/public/telegram/webhook.php"
echo ""
echo "Next: run the webhook registration command from Claude."
