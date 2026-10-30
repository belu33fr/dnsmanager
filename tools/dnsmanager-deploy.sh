#!/bin/bash
# Déploiement de DNSManage depuis GitHub — à lancer SUR LE SERVEUR GLPI :
#   sudo /var/glpi/plugins/dnsmanager/tools/dnsmanager-deploy.sh            # branche main
#   sudo /var/glpi/plugins/dnsmanager/tools/dnsmanager-deploy.sh v3.9.6     # un tag précis
#
# Ne modifiez jamais de fichiers directement sur le serveur : le script refuse de
# continuer si l'arbre de travail n'est pas propre (tout passe par GitHub).
set -euo pipefail

PLUGIN_DIR="/var/glpi/plugins/dnsmanager"
GLPI_DIR="/var/glpi"
WEB_USER="www-data"
REF="${1:-main}"

as_web() { sudo -u "$WEB_USER" "$@"; }

[ "$(id -u)" -eq 0 ] || { echo "À lancer avec sudo."; exit 1; }
[ -d "$PLUGIN_DIR/.git" ] || { echo "$PLUGIN_DIR n'est pas un dépôt git (bascule initiale non faite)."; exit 1; }
cd "$PLUGIN_DIR"

if [ -n "$(as_web git status --porcelain)" ]; then
  echo "Modifications locales détectées dans $PLUGIN_DIR — déploiement refusé :"
  as_web git status --short
  exit 1
fi

version() { grep -o "PLUGIN_DNSMANAGER_VERSION', '[^']*'" setup.php | sed "s/.*', '//; s/'//"; }

echo "Version actuelle : $(version) ($(as_web git rev-parse --short HEAD))"
as_web git fetch --tags --prune --quiet

if as_web git rev-parse -q --verify "refs/tags/$REF" >/dev/null; then
  TARGET="refs/tags/$REF"; KIND="tag"
elif as_web git rev-parse -q --verify "refs/remotes/origin/$REF" >/dev/null; then
  TARGET="refs/remotes/origin/$REF"; KIND="branche"
else
  echo "Référence inconnue : $REF"; exit 1
fi

echo "Cible : $KIND $REF ($(as_web git rev-parse --short "$TARGET"))"
echo "--- Commits qui seront appliqués ---"
as_web git log --oneline "HEAD..$TARGET" || true
echo "------------------------------------"
read -r -p "Déployer ? [o/N] " ans
[ "$ans" = "o" ] || [ "$ans" = "O" ] || { echo "Annulé."; exit 0; }

if [ "$KIND" = "branche" ]; then
  as_web git checkout -q "$REF"
  as_web git merge --ff-only -q "origin/$REF"
else
  as_web git checkout -q "$REF"
fi

echo "Nouvelle version : $(version)"
rm -rf "$GLPI_DIR"/files/_cache/*
as_web php "$GLPI_DIR/bin/console" glpi:plugin:install --force dnsmanager
as_web php "$GLPI_DIR/bin/console" glpi:plugin:activate dnsmanager
echo "Déploiement terminé."
