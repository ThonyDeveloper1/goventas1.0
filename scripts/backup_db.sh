#!/usr/bin/env bash
# ─────────────────────────────────────────────
#  GO Sistema — PostgreSQL backup + email
#  Corre cada 3 días a medianoche (cron)
# ─────────────────────────────────────────────
set -euo pipefail

# ── Leer config desde JSON ───────────────────
CONFIG_FILE="/var/www/gosistema/scripts/backup_config.json"
if [[ ! -f "$CONFIG_FILE" ]]; then
  echo "ERROR: No se encontró $CONFIG_FILE" >&2
  exit 1
fi

MAIL_FROM=$(python3 -c "import json,sys; d=json.load(open('$CONFIG_FILE')); print(d['mail_from'])")
MAIL_TO=$(python3 -c "import json,sys; d=json.load(open('$CONFIG_FILE')); print(d['mail_to'])")
MAIL_PASSWORD=$(python3 -c "import json,sys; d=json.load(open('$CONFIG_FILE')); print(d['mail_password'])")

# ── Config fija ──────────────────────────────
DB_NAME="gosistema"
DB_USER="gouser"
DB_HOST="127.0.0.1"
DB_PORT="5432"
BACKUP_DIR="/var/www/gosistema/scripts/backups"
MAX_BACKUPS=10

# Reescribir msmtprc con credenciales actuales
cat > /home/gouser/.msmtprc << MSMTP
# msmtp config — GO Sistema backup emails
defaults
auth           on
tls            on
tls_trust_file /etc/ssl/certs/ca-certificates.crt
logfile        /var/log/msmtp.log

account        gmail
host           smtp.gmail.com
port           587
from           ${MAIL_FROM}
user           ${MAIL_FROM}
password       ${MAIL_PASSWORD}

account default : gmail
MSMTP
chmod 600 /home/gouser/.msmtprc
mkdir -p "$BACKUP_DIR"
DATE=$(date '+%Y-%m-%d_%H-%M')
FILENAME="gosistema_backup_${DATE}.sql.gz"
FILEPATH="${BACKUP_DIR}/${FILENAME}"

# ── Dump + compresión ────────────────────────
PGPASSWORD="123456" pg_dump \
    -h "$DB_HOST" \
    -p "$DB_PORT" \
    -U "$DB_USER" \
    -d "$DB_NAME" \
    --no-password \
    | gzip > "$FILEPATH"

SIZE=$(du -sh "$FILEPATH" | cut -f1)

# ── Enviar email con el adjunto ──────────────
SUBJECT="[GO Sistema] Backup BD — ${DATE} (${SIZE})"
BOUNDARY="==backup_boundary_$$=="

{
  echo "From: GO Sistema Backup <${MAIL_FROM}>"
  echo "To: ${MAIL_TO}"
  echo "Subject: ${SUBJECT}"
  echo "MIME-Version: 1.0"
  echo "Content-Type: multipart/mixed; boundary=\"${BOUNDARY}\""
  echo ""
  echo "--${BOUNDARY}"
  echo "Content-Type: text/plain; charset=utf-8"
  echo ""
  echo "Backup automático de la base de datos GO Sistema."
  echo ""
  echo "Fecha:    ${DATE}"
  echo "Base:     ${DB_NAME}"
  echo "Tamaño:   ${SIZE}"
  echo "Archivo:  ${FILENAME}"
  echo ""
  echo "Este correo se genera automáticamente cada 3 días."
  echo ""
  echo "--${BOUNDARY}"
  echo "Content-Type: application/gzip"
  echo "Content-Transfer-Encoding: base64"
  echo "Content-Disposition: attachment; filename=\"${FILENAME}\""
  echo ""
  base64 "$FILEPATH"
  echo "--${BOUNDARY}--"
} | msmtp --file=/home/gouser/.msmtprc --account=gmail "$MAIL_TO"

# ── Limpiar backups viejos ───────────────────
ls -t "${BACKUP_DIR}"/gosistema_backup_*.sql.gz 2>/dev/null \
  | tail -n +$((MAX_BACKUPS + 1)) \
  | xargs -r rm --

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backup OK: ${FILENAME} (${SIZE}) → enviado a ${MAIL_TO}"
