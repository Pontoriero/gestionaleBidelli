#!/usr/bin/env bash
#
# Deploy di aggiornamento per gestionale-turni-bidelli.
#
# Sul server di produzione il progetto gira in un pool PHP-FPM isolato
# (utente php-turni, gruppo turni): i permessi NON vanno più impostati a
# www-data, altrimenti l'isolamento dagli altri siti si perde.
# Il deploy vero è /usr/local/bin/deploy-turni.sh (git pull, permessi
# root:turni, reload PHP-FPM, verifica): questo script si limita a chiamarlo.
#
# Uso (da root): ./deploy/deploy.sh   oppure direttamente   deploy-turni.sh

set -euo pipefail

DEPLOY_SERVER="/usr/local/bin/deploy-turni.sh"

if [[ -x "${DEPLOY_SERVER}" ]]; then
    exec "${DEPLOY_SERVER}" "$@"
fi

echo "ERRORE: ${DEPLOY_SERVER} non trovato su questa macchina." >&2
echo "Non eseguo chown/chmod: su questo server i permessi vanno gestiti" >&2
echo "dallo script di deploy del server (pool PHP-FPM isolato)." >&2
exit 1
