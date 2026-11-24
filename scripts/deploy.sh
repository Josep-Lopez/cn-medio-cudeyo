#!/usr/bin/env bash
# Deploy a DonDominio por FTPS (FTP sobre TLS).
#
# Uso:
#   scripts/deploy.sh          → simulación: lista lo que se subiría, no toca nada
#   scripts/deploy.sh --go     → sube de verdad
#
# Requisitos (una sola vez):
#   sudo apt install lftp
#   echo "machine ftp.dondominio.com login ftp.mediocudeyonatacion.es password TU_PASSWORD" >> ~/.netrc
#   chmod 600 ~/.netrc
#
# Solo sube ficheros nuevos o modificados. NUNCA borra nada en el servidor,
# así que los ficheros subidos por usuarios (public/uploads/) están a salvo.

set -euo pipefail

HOST="ftp.dondominio.com"
REMOTE_DIR="/"
LOCAL_DIR="$(cd "$(dirname "$0")/.." && pwd)"

if ! command -v lftp >/dev/null; then
    echo "Falta lftp: sudo apt install lftp" >&2
    exit 1
fi
if ! grep -q "machine $HOST" ~/.netrc 2>/dev/null; then
    echo "Falta la contraseña en ~/.netrc (ver cabecera del script)" >&2
    exit 1
fi

# En GitHub Actions las fechas son las del último commit de cada fichero
# (git restore-mtime): ahí se sube lo que difiera, no solo lo más nuevo.
ONLY_NEWER="${DEPLOY_ONLY_NEWER---only-newer}"

DRY="--dry-run"
if [[ "${1:-}" == "--go" ]]; then
    DRY=""
else
    echo ">> SIMULACIÓN (usa --go para subir de verdad)"
fi

# Se sube la copia de trabajo tal cual: avisar si hay cambios sin commitear
if [[ -z "$DRY" ]] && [[ -n "$(git -C "$LOCAL_DIR" status --porcelain)" ]]; then
    echo ">> Hay cambios sin commitear que también se subirán:"
    git -C "$LOCAL_DIR" status --short
    read -r -p ">> ¿Subir igualmente? [s/N] " resp
    [[ "$resp" == "s" || "$resp" == "S" ]] || { echo "Cancelado."; exit 1; }
fi

# Nada de esto se sube: entorno local, secretos, docs, datos de usuarios.
EXCLUDES=(
    --exclude-glob .git/
    --exclude-glob .vscode/
    --exclude-glob .github/
    --exclude-glob .claude/
    --exclude-glob .codex
    --exclude-glob .agents/
    --exclude-glob .superpowers/
    --exclude-glob .worktrees/
    --exclude-glob memory/
    --exclude-glob docs/
    --exclude-glob migrations/
    --exclude-glob logs/
    --exclude-glob storage/
    --exclude-glob scripts/
    --exclude-glob .env
    --exclude-glob .env.example
    --exclude-glob .gitignore
    --exclude-glob config/env.php
    --exclude-glob docker-compose.yml
    --exclude-glob Dockerfile
    --exclude-glob schema.sql
    --exclude-glob skills-lock.json
    --exclude-glob composer.json
    --exclude-glob composer.lock
    --exclude-glob '*.md'
    --exclude-glob '*.ini'
    --exclude-glob '*.log'
    --exclude-glob '*.sh'
    --exclude-glob '*.sql'
    --exclude-glob '*.bak'
    --exclude-glob '*.orig'
    --exclude-glob '*.swp'
    --exclude-glob '*~'
    --exclude-glob .DS_Store
    --exclude-glob Thumbs.db
    # Contenido subido por usuarios: solo se suben los .htaccess de protección
    # (lftp no re-incluye con --include-glob tras un exclude: se excluye todo salvo .h*)
    --exclude-glob 'public/uploads/*/[!.]*'
    --exclude-glob 'public/uploads/*/.[!h]*'
)

# Usuario y contraseña los toma lftp de ~/.netrc
lftp <<EOF
# FTPS obligatorio: contraseña y ficheros cifrados, certificado verificado
set ftp:ssl-force yes
set ftp:ssl-protect-data yes
set ssl:verify-certificate yes
set ftp:passive-mode yes
set net:max-retries 2
set net:timeout 20
set net:connection-limit 1
# Host sin "ftp://": con URL lftp ignora ~/.netrc y entra como anonymous
open $HOST
# Si no conecta, abortar: si no, la simulación da todo por nuevo
set cmd:fail-exit yes
cd /public
cd /
mirror --reverse $ONLY_NEWER --no-perms --verbose $DRY \
    ${EXCLUDES[*]} \
    "$LOCAL_DIR" "$REMOTE_DIR"
bye
EOF

echo ">> Hecho."
