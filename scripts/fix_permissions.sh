#!/bin/bash
# DNSManage - Correction des permissions
PLUGINS_DIR="${1:-/var/glpi/plugins}"
PLUGIN_DIR="$PLUGINS_DIR/dnsmanager"

if [ ! -d "$PLUGIN_DIR" ]; then echo "ERREUR: $PLUGIN_DIR introuvable."; exit 1; fi

echo "Correction permissions : $PLUGIN_DIR"
sudo chown -R www-data:www-data "$PLUGIN_DIR"
sudo find "$PLUGIN_DIR" -type d -exec chmod 755 {} \;
sudo find "$PLUGIN_DIR" -type f -exec chmod 644 {} \;
echo "OK"
ls -la "$PLUGIN_DIR/setup.php"
