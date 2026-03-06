#!/usr/bin/env bash
# =============================================================================
#  CN Medio Cudeyo — Arrancada Docker
#  Atura contenidors obsolets i inicia l'entorn net
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

# 1. Atura i elimina contenidors del projecte (inclou orphans)
echo -e "${YELLOW}→ Aturant i eliminant contenidors antics...${RESET}"
docker compose down --remove-orphans 2>/dev/null || true

# 2. Elimina imatges de build anteriors del projecte (capa app)
echo -e "${YELLOW}→ Eliminant imatges obsoletes del projecte...${RESET}"
docker image prune -f --filter "label=com.docker.compose.project=$(basename "$SCRIPT_DIR")" 2>/dev/null || true

# 3. Opcional: elimina imatges dangling sense etiqueta
echo -e "${YELLOW}→ Netejant imatges sense etiqueta (dangling)...${RESET}"
docker image prune -f 2>/dev/null || true

# 4. Inicia els serveis
echo ""
echo -e "${YELLOW}→ Iniciant serveis (app + db + phpmyadmin)...${RESET}"
docker compose up -d --build

# 5. Mostra estat
echo ""
echo -e "${YELLOW}→ Estat dels contenidors:${RESET}"
docker compose ps

# 6. Espera que MySQL estigui llest (el healthcheck del compose ja ho fa, però mostrem feedback)
echo ""
echo -e "${YELLOW}→ Esperant que MySQL estigui operatiu...${RESET}"
RETRIES=20
INTERVAL=3
for i in $(seq 1 $RETRIES); do
  STATUS=$(docker compose ps --format json db 2>/dev/null | grep -o '"Health":"[^"]*"' | cut -d'"' -f4 || echo "")
  if [ "$STATUS" = "healthy" ]; then
    echo -e "${GREEN}   MySQL llest!${RESET}"
    break
  fi
  if [ "$i" -eq "$RETRIES" ]; then
    echo -e "${YELLOW}   Temps d'espera esgotat — comprova els logs amb: docker compose logs db${RESET}"
  else
    printf "   Esperant... (%d/%d)\r" "$i" "$RETRIES"
    sleep $INTERVAL
  fi
done

echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
echo -e "${GREEN}  ✓  Entorn llest!${RESET}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
echo ""
echo "  App         →  http://localhost:8080"
echo "  phpMyAdmin  →  http://localhost:8081"
echo "  Admin       →  admin@cnmediocudeyo.es / Admin1234!"
echo ""
echo "  Logs:   docker compose logs -f app"
echo "  Parar:  docker compose down"
echo ""
