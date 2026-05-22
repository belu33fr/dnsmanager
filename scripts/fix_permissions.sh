#!/bin/bash
# DNSManage - Correction des permissions
# Usage: bash fix_permissions.sh [chemin_plugins]

PLUGINS_DIR="${1:-/var/glpi/plugins}"
PLUGIN_DIR="$PLUGINS_DIR/dnsmanager"

if [ ! -d "$PLUGIN_DIR" ]; then
    echo "ERREUR: Dossier $PLUGIN_DIR introuvable."
    exit 1
fi

WEB_USER=$(ps aux | grep -E 'apache2|nginx|php-fpm|httpd' | grep -v grep | head -1 | awk '{print $1}')
WEB_USER="${WEB_USER:-www-data}"

echo "Dossier  : $PLUGIN_DIR"
echo "User web : $WEB_USER"

chown -R "$WEB_USER:$WEB_USER" "$PLUGIN_DIR"
find "$PLUGIN_DIR" -type d -exec chmod 755 {} \;
find "$PLUGIN_DIR" -type f -exec chmod 644 {} \;

echo ""
ls -la "$PLUGIN_DIR/setup.php"
echo "Permissions corrigées."
