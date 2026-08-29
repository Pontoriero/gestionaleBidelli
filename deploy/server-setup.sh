#!/usr/bin/env bash
#
# Setup iniziale di gestionale-turni-bidelli sul VPS Hetzner (CX23, AIManage).
# Da lanciare UNA VOLTA sola, come root, via SSH:
#
#   scp deploy/server-setup.sh root@46.224.57.81:/root/
#   ssh root@46.224.57.81
#   chmod +x /root/server-setup.sh
#   /root/server-setup.sh
#
# Non tocca nulla di AIManage o casasenzacaos.it: crea solo risorse nuove,
# con nomi dedicati a questo progetto. Si ferma al primo errore (set -e).

set -euo pipefail

# ---------- Parametri (modifica qui se serve) ----------
PROJECT_NAME="gestionale-turni-bidelli"
PROJECT_DIR="/var/www/${PROJECT_NAME}"
DOMAIN="turni.aimanage.it"
GITHUB_OWNER="Pontoriero"
GITHUB_REPO="gestionaleBidelli"
DEPLOY_KEY_PATH="/root/.ssh/deploy_${PROJECT_NAME}"
SSH_HOST_ALIAS="github-${PROJECT_NAME}"

DB_NAME="turni_bidelli"
DB_USER="turni_bidelli_user"
DB_PASS="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 32)"

PHP_FPM_SOCK="/run/php/php8.4-fpm.sock"

echo "=== 1/7 — Chiave SSH deploy (sola lettura) per il repo ==="
if [ -f "${DEPLOY_KEY_PATH}" ]; then
    echo "Chiave già presente in ${DEPLOY_KEY_PATH}, la riuso."
else
    ssh-keygen -t ed25519 -C "${PROJECT_NAME}-deploy" -f "${DEPLOY_KEY_PATH}" -N ""
    echo
    echo ">>> AZIONE MANUALE RICHIESTA (fermati qui se non l'hai già fatto):"
    echo ">>> Aggiungi questa chiave PUBBLICA come Deploy Key (sola lettura, NO write access)"
    echo ">>> su https://github.com/${GITHUB_OWNER}/${GITHUB_REPO}/settings/keys :"
    echo
    cat "${DEPLOY_KEY_PATH}.pub"
    echo
    read -r -p "Premi INVIO quando l'hai aggiunta su GitHub per continuare... "
fi

# Alias SSH dedicato: non tocca eventuali chiavi/config già usate per AIManage.
if ! grep -q "Host ${SSH_HOST_ALIAS}" /root/.ssh/config 2>/dev/null; then
    mkdir -p /root/.ssh
    {
        echo ""
        echo "Host ${SSH_HOST_ALIAS}"
        echo "    HostName github.com"
        echo "    User git"
        echo "    IdentityFile ${DEPLOY_KEY_PATH}"
        echo "    IdentitiesOnly yes"
    } >> /root/.ssh/config
    chmod 600 /root/.ssh/config
fi
ssh-keyscan -H github.com >> /root/.ssh/known_hosts 2>/dev/null || true

echo "=== 2/7 — Clone del repository ==="
if [ -d "${PROJECT_DIR}" ]; then
    echo "ERRORE: ${PROJECT_DIR} esiste già. Interrompo per non sovrascrivere nulla."
    echo "Se è un tentativo precedente andato a metà, controllalo/rimuovilo a mano e rilancia."
    exit 1
fi
git clone "git@${SSH_HOST_ALIAS}:${GITHUB_OWNER}/${GITHUB_REPO}.git" "${PROJECT_DIR}"

echo "=== 3/7 — Database MySQL dedicato (${DB_NAME}) ==="
if mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
    MYSQL_ROOT=(mysql -u root)
else
    read -r -s -p "Password MySQL per root: " MYSQL_ROOT_PWD
    echo
    MYSQL_ROOT=(mysql -u root -p"${MYSQL_ROOT_PWD}")
fi

"${MYSQL_ROOT[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "Database '${DB_NAME}' e utente '${DB_USER}' creati (permessi solo su questo DB)."

echo "=== 4/7 — Schema database ==="
mysql -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${PROJECT_DIR}/sql/schema.sql"
echo "Schema applicato. NON carico sql/seed.sql: contiene dati di test/finti, non va in produzione."
echo "Dopo il deploy dovrai creare il primo utente DSGA reale a mano (vedi README post-setup)."

echo "=== 5/7 — File .env (creato solo sul server, mai committato) ==="
cat > "${PROJECT_DIR}/.env" <<ENV
DB_HOST=localhost
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
ENV
chmod 640 "${PROJECT_DIR}/.env"

echo "=== 6/7 — Permessi ==="
chown -R www-data:www-data "${PROJECT_DIR}"
find "${PROJECT_DIR}" -type d -exec chmod 755 {} \;
find "${PROJECT_DIR}" -type f -exec chmod 644 {} \;
chmod 640 "${PROJECT_DIR}/.env"

echo "=== 7/7 — Vhost Nginx ==="
if [ -f "/etc/nginx/sites-available/${DOMAIN}" ]; then
    echo "ERRORE: vhost /etc/nginx/sites-available/${DOMAIN} esiste già. Interrompo, controllalo a mano."
    exit 1
fi

# Prova a rilevare il socket PHP-FPM realmente in uso su questo server
# (per allinearsi al pattern già usato da AIManage), altrimenti usa il default.
DETECTED_SOCK="$(grep -rhoE 'fastcgi_pass\s+unix:[^;]+' /etc/nginx/sites-available/ 2>/dev/null | grep -o 'unix:[^;]*' | head -1 | sed 's/unix://')"
if [ -n "${DETECTED_SOCK}" ]; then
    PHP_FPM_SOCK="${DETECTED_SOCK}"
fi
echo "Uso socket PHP-FPM: ${PHP_FPM_SOCK}"

cat > "/etc/nginx/sites-available/${DOMAIN}" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${PROJECT_DIR}/public;
    index index.php;

    access_log /var/log/nginx/${DOMAIN}.access.log;
    error_log  /var/log/nginx/${DOMAIN}.error.log;

    gzip on;
    gzip_types text/css application/javascript application/json text/plain;

    # Blocco esplicito extra (difesa in profondità, oltre al root già isolato su public/)
    location ~ /\.(env|git|htaccess) { deny all; }
    location ~* \.(sql|md)\$ { deny all; }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }
}
NGINX

ln -sf "/etc/nginx/sites-available/${DOMAIN}" "/etc/nginx/sites-enabled/${DOMAIN}"
nginx -t
systemctl reload nginx
echo "Vhost HTTP attivo su ${DOMAIN} (senza SSL per ora)."

echo
echo "=========================================================="
echo "Setup base completato."
echo "Database: ${DB_NAME}  |  Utente: ${DB_USER}  |  Password: ${DB_PASS}"
echo "(salvate anche in ${PROJECT_DIR}/.env)"
echo
echo "PROSSIMO PASSO (solo dopo che il DNS di ${DOMAIN} punta a questo server):"
echo "  certbot --nginx -d ${DOMAIN}"
echo "=========================================================="
echo
read -r -p "Il DNS di ${DOMAIN} è già propagato e vuoi richiedere il certificato SSL ora? [s/N] " RISPOSTA
if [[ "${RISPOSTA}" =~ ^[sS]$ ]]; then
    certbot --nginx -d "${DOMAIN}"
else
    echo "Ok, salta. Lancia manualmente più tardi: certbot --nginx -d ${DOMAIN}"
fi
