#!/usr/bin/env bash
# Přechod běžící MyInvoice.cz Docker instalace na MyÚčto.cz.
#
# MyÚčto je nástupce MyInvoice od stejného autora, postavený na stejném
# základu. Jeho migrace 1000+ navazují na schéma MyInvoice, takže přechod je
# in-place: vymění se image a nad STÁVAJÍCÍ databází se dojedou zbylé migrace.
# Data se nikam nekopírují, druhá databáze se nezakládá a volume `/data`
# (uploady, PDF, cfg.local.php) zůstává beze změny — layout je u obou stejný.
#
#   1. zazálohuje databázi uvnitř kontejneru (bez zálohy skript nepokračuje)
#   2. zastaví app kontejner (db běží dál)
#   3. přepne image myinvoice → myucto v .env a compose souborech
#   4. stáhne nový image
#   5. nastartuje app; migrace dojede docker-entrypoint.sh sám
#   6. počká na /api/health a při selhání nabídne návrat na původní image
#
# POZOR: tohle NENÍ aktualizace, ale výměna produktu. Zpátky vede jedině
# obnova zálohy z kroku 1 — návrat image samotný nestačí, protože schéma už
# bude mít migrace MyÚčta.
#
# Použití:
#   bash cmd/docker-upgrade-to-myucto.sh            # s potvrzením
#   bash cmd/docker-upgrade-to-myucto.sh --yes      # bez ptaní
#   MYUCTO_TAG=5.15.0 bash cmd/docker-upgrade-to-myucto.sh   # pin na verzi
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

AUTO_YES=0
for arg in "$@"; do
  case "$arg" in
    --yes|-y) AUTO_YES=1 ;;
    *) echo "Neznámý přepínač: $arg" >&2; exit 2 ;;
  esac
done

if ! command -v docker >/dev/null 2>&1; then
  echo "CHYBA: docker není v PATH" >&2; exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
  echo "CHYBA: je potřeba plugin 'docker compose' (v2)" >&2; exit 1
fi
if [[ ! -f .env ]]; then
  echo "CHYBA: .env nenalezen — tohle není adresář s instalací" >&2; exit 1
fi

MYUCTO_TAG="${MYUCTO_TAG:-latest}"

# Preferuj production compose, pokud nad ním stack opravdu běží.
COMPOSE_ARGS=()
if [[ -f docker-compose.production.yml ]] \
   && [[ -n "$(docker compose -f docker-compose.production.yml ps --status running -q app 2>/dev/null)" ]]; then
  COMPOSE_ARGS=("-f" "docker-compose.production.yml")
fi
dc() { docker compose "${COMPOSE_ARGS[@]}" "$@"; }

# Které soubory drží jméno image. Měníme jen ty, co ho opravdu obsahují,
# ať se needitují nesouvisející compose soubory.
TARGET_FILES=()
for f in .env docker-compose.yml docker-compose.production.yml docker-compose.portainer.yml; do
  [[ -f "$f" ]] && grep -q 'radekhulan/myinvoice' "$f" && TARGET_FILES+=("$f")
done

if [[ ${#TARGET_FILES[@]} -eq 0 ]]; then
  echo "CHYBA: v .env ani compose souborech není image 'radekhulan/myinvoice'." >&2
  echo "       Buď už na MyÚčtu jsi, nebo stavíš image lokálně ze zdrojů" >&2
  echo "       (pak přejdi klonem repa myucto a 'docker compose up -d --build')." >&2
  exit 1
fi

echo "============================================================"
echo " Přechod MyInvoice.cz → MyÚčto.cz"
echo ""
echo " Změním image na ghcr.io/radekhulan/myucto:${MYUCTO_TAG} v:"
for f in "${TARGET_FILES[@]}"; do echo "   - $f"; done
echo ""
echo " Databáze SE NEMĚNÍ — dojedou nad ní migrace MyÚčta."
echo " Volume s daty zůstávají. Zpátky vede jen obnova zálohy."
echo "============================================================"
echo ""

if [[ $AUTO_YES -ne 1 ]]; then
  read -r -p "Pokračovat? [napiš ANO]: " answer
  if [[ "$answer" != "ANO" ]]; then
    echo "Zrušeno."; exit 0
  fi
fi

# --- 1. záloha databáze ---------------------------------------------------
# Fatální krok: nevratnou migraci schématu bez zálohy nepouštíme.
echo "==> Zálohuji databázi…"
if ! dc exec -T app php api/bin/cron-backup.php; then
  echo "" >&2
  echo "CHYBA: záloha databáze selhala — přechod NEspouštím." >&2
  echo "       Instalace zůstává beze změny. Zazálohuj ručně a spusť znovu." >&2
  exit 1
fi
echo "    Záloha hotova (storage/backup/ ve volume app-data)."

# --- 2. zastavit app ------------------------------------------------------
echo "==> Zastavuji app kontejner (db běží dál)…"
dc stop app

# --- 3. přepnout image ----------------------------------------------------
STAMP="$(date +%Y%m%d-%H%M%S)"
echo "==> Přepínám image na MyÚčto (zálohy souborů: *.bak-${STAMP})…"
for f in "${TARGET_FILES[@]}"; do
  cp "$f" "${f}.bak-${STAMP}"
  sed -i "s#radekhulan/myinvoice#radekhulan/myucto#g" "$f"
  if [[ "$MYUCTO_TAG" != "latest" ]]; then
    sed -i "s#\(ghcr.io/radekhulan/myucto\):[A-Za-z0-9._-]*#\1:${MYUCTO_TAG}#g" "$f"
  fi
  echo "    $f"
done

restore_files() {
  for f in "${TARGET_FILES[@]}"; do
    [[ -f "${f}.bak-${STAMP}" ]] && mv "${f}.bak-${STAMP}" "$f"
  done
}

# --- 4. stáhnout image ----------------------------------------------------
echo "==> Stahuji image MyÚčta…"
if ! dc pull app; then
  echo "CHYBA: image se nepodařilo stáhnout — vracím soubory zpět." >&2
  restore_files
  dc up -d app
  exit 1
fi

# --- 5. start (entrypoint dojede migrace) ---------------------------------
echo "==> Startuji MyÚčto; migrace dojede entrypoint…"
dc up -d app

# --- 6. health check ------------------------------------------------------
APP_PORT="$(grep -E '^APP_PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '\r' || true)"
APP_PORT="${APP_PORT:-8080}"

echo "==> Čekám, až aplikace odpoví (migrací je hodně, může to chvíli trvat)…"
ok=0
for i in $(seq 1 150); do
  if curl -fsS -o /dev/null "http://localhost:${APP_PORT}/api/health" 2>/dev/null; then
    ok=1; break
  fi
  sleep 2
done

if [[ $ok -ne 1 ]]; then
  echo "" >&2
  echo "CHYBA: aplikace neodpověděla do 5 minut." >&2
  echo "       Projdi 'docker compose logs app' — migrace mohly selhat." >&2
  echo "       Soubory s původním image jsou v *.bak-${STAMP}." >&2
  echo "       POZOR: návrat na starý image sám nestačí, pokud už migrace" >&2
  echo "       proběhly — obnov databázi ze zálohy z kroku 1." >&2
  exit 1
fi

echo ""
echo "============================================================"
echo " Hotovo — běží MyÚčto.cz na http://localhost:${APP_PORT}"
echo ""
echo " Logy:            docker compose logs -f app"
echo " Původní compose: *.bak-${STAMP} (po ověření smaž)"
echo "============================================================"
