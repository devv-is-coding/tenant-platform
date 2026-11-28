#!/usr/bin/env bash
# Wait until the stack's proxy answers /up through an app node (CI only:
# .github/workflows/stack.yml). Gives up after 5 minutes and prints the
# containers' state, so a stack that never comes up fails with the reason.
set -euo pipefail

url="http://localhost:${APP_PORT:-8000}/up"

for _ in $(seq 1 150); do
    if [ "$(curl -s -o /dev/null -w '%{http_code}' "$url")" = 200 ]; then
        echo "The app answers at $url."
        exit 0
    fi
    sleep 2
done

echo "The app did not answer at $url within 5 minutes." >&2
docker compose ps -a >&2
docker compose logs --no-color --tail=100 >&2
exit 1
