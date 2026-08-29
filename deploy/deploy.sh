#!/usr/bin/env bash
#
# Deploy di aggiornamento per gestionale-turni-bidelli.
# Da lanciare sul server ad ogni aggiornamento, dalla cartella del progetto:
#
#   cd /var/www/gestionale-turni-bidelli && ./deploy/deploy.sh
#
# Nessun composer/artisan: progetto PHP puro, "deploy" = aggiornare i file
# e riavviare PHP-FPM (per svuotare eventuale opcache).

set -euo pipefail

PROJECT_DIR="/var/www/gestionale-turni-bidelli"
PHP_FPM_SERVICE="php8.4-fpm"

cd "${PROJECT_DIR}"

echo "=== git pull ==="
git pull --ff-only

echo "=== permessi ==="
chown -R www-data:www-data "${PROJECT_DIR}"
find "${PROJECT_DIR}" -type d -exec chmod 755 {} \;
find "${PROJECT_DIR}" -type f -exec chmod 644 {} \;
chmod 640 "${PROJECT_DIR}/.env"

# Se sql/schema.sql è cambiato in questo aggiornamento, applica le nuove
# istruzioni a mano (mysql -u ... turni_bidelli < sql/schema.sql) — non è
# automatico qui apposta: uno script che rilancia lo schema ad ogni deploy
# rischia di eseguire ALTER/CREATE già applicati o inadatti a dati esistenti.

echo "=== reload PHP-FPM (svuota opcache) ==="
systemctl restart "${PHP_FPM_SERVICE}"

echo "Deploy completato."
