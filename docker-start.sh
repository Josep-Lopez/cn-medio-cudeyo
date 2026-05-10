#!/usr/bin/env bash
# =============================================================================
#  CN Medio Cudeyo — Arranque Docker
#  Detiene contenedores obsoletos e inicia el entorno limpio
# =============================================================================
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

BLUE='\033[0;34m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RESET='\033[0m'

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
echo -e "${BLUE}  🏊  CN Medio Cudeyo — Docker Start${RESET}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
echo ""

# 1. Detiene y elimina contenedores del proyecto (incluye orphans)
echo -e "${YELLOW}→ Deteniendo y eliminando contenedores antiguos...${RESET}"
docker compose down --remove-orphans 2>/dev/null || true

# 2. Elimina imágenes de build anteriores del proyecto (capa app)
echo -e "${YELLOW}→ Eliminando imágenes obsoletas del proyecto...${RESET}"
docker image prune -f --filter "label=com.docker.compose.project=$(basename "$SCRIPT_DIR")" 2>/dev/null || true

# 3. Opcional: elimina imágenes dangling sin etiqueta
echo -e "${YELLOW}→ Limpiando imágenes sin etiqueta (dangling)...${RESET}"
docker image prune -f 2>/dev/null || true

# 4. Inicia los servicios
echo ""
echo -e "${YELLOW}→ Iniciando servicios (app + db + phpmyadmin)...${RESET}"
docker compose up -d --build

# 5. Muestra estado
echo ""
echo -e "${YELLOW}→ Estado de los contenedores:${RESET}"
docker compose ps

# 6. Espera a que MySQL esté listo (el healthcheck del compose ya lo hace, pero mostramos feedback)
echo ""
echo -e "${YELLOW}→ Esperando a que MySQL esté operativo...${RESET}"
RETRIES=20
INTERVAL=3
for i in $(seq 1 $RETRIES); do
  STATUS=$(docker compose ps --format json db 2>/dev/null | grep -o '"Health":"[^"]*"' | cut -d'"' -f4 || echo "")
  if [ "$STATUS" = "healthy" ]; then
    echo -e "${GREEN}   MySQL listo!${RESET}"
    break
  fi
  if [ "$i" -eq "$RETRIES" ]; then
    echo -e "${YELLOW}   Tiempo de espera agotado — comprueba los logs con: docker compose logs db${RESET}"
  else
    printf "   Esperando... (%d/%d)\r" "$i" "$RETRIES"
    sleep $INTERVAL
  fi
done

echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
echo -e "${GREEN}  ✓  Entorno listo!${RESET}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
echo ""
echo "  App         →  http://localhost:8080"
echo "  phpMyAdmin  →  http://localhost:8081"
echo "  Admin       →  admin@cnmediocudeyo.es / Admin1234!"
echo ""
echo "  Logs:   docker compose logs -f app"
echo "  Parar:  docker compose down"
echo ""
