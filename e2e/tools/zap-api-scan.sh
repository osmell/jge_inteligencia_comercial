#!/bin/sh
# Escaneo dinámico (OWASP ZAP) de la API con sesión de administración, contra una instalación DESECHABLE (nunca contra datos reales:
# el escaneo activo envía entradas hostiles a todas las operaciones). Requiere Docker y la API en marcha con los usuarios de desarrollo.
# Uso: e2e/tools/zap-api-scan.sh http://localhost:8001 http://localhost:5174
#   $1 = dirección de la API (sin /api/v1)   $2 = origen de la interfaz (debe estar en SANCTUM_STATEFUL_DOMAINS y FRONTEND_URL)
# Sugerencia: arrancar la API con API_RATE_PER_MINUTE y LOGIN_RATE_PER_IP muy altos (si no, los propios límites frenan el escaneo).
set -eu
API="${1:?dirección de la API}"; ORIGIN="${2:?origen de la interfaz}"
WORK="$(mktemp -d)"; chmod 777 "$WORK"
JAR="$WORK/jar"
curl -s -c "$JAR" -o /dev/null "$API/sanctum/csrf-cookie" -H "Origin: $ORIGIN" -H "Referer: $ORIGIN/"
xsrf() { grep XSRF-TOKEN "$JAR" | awk '{print $7}' | python3 -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()),end='')"; }
curl -s -b "$JAR" -c "$JAR" -X POST "$API/api/v1/auth/login" -H "Origin: $ORIGIN" -H "X-XSRF-TOKEN: $(xsrf)" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d "{\"username\":\"admin\",\"password\":\"${E2E_PASSWORD:-Desarrollo-123}\"}" -o /dev/null -w "inicio de sesión: %{http_code}\n"
COOKIE="$(awk -F'\t' 'NF==7 {gsub(/^#HttpOnly_/,"",$1); printf "%s=%s;", $6, $7}' "$JAR" | sed 's/;$//')"
XS="$(xsrf)"
cp "$(dirname "$0")/../../backend/openapi.json" "$WORK/openapi.json"
H() { echo "-config replacer.full_list($1).description=h$1 -config replacer.full_list($1).enabled=true -config replacer.full_list($1).matchtype=REQ_HEADER -config replacer.full_list($1).matchstr=$2 -config replacer.full_list($1).regex=false -config replacer.full_list($1).replacement=$3"; }
docker run --rm --network host -v "$WORK:/zap/wrk:rw" ghcr.io/zaproxy/zaproxy:stable zap-api-scan.py \
  -t /zap/wrk/openapi.json -f openapi -O "$API/api/v1" -r informe.html -J informe.json -w informe.md -T 30 -I \
  -z "$(H 0 Cookie "$COOKIE") $(H 1 X-XSRF-TOKEN "$XS") $(H 2 Origin "$ORIGIN") $(H 3 Accept application/json)"
echo "Informe en $WORK/informe.html"
