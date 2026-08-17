#!/usr/bin/env bash

set -euo pipefail

if [[ "$#" -eq 0 ]]; then
    echo 'composer-retry.sh requires Composer arguments.' >&2
    exit 64
fi

max_attempts="${COMPOSER_RETRY_ATTEMPTS:-4}"
delay_seconds="${COMPOSER_RETRY_DELAY:-10}"

for attempt in $(seq 1 "$max_attempts"); do
    echo "Composer attempt ${attempt}/${max_attempts}: composer $*"

    if composer "$@"; then
        exit 0
    fi

    if [[ "$attempt" -eq "$max_attempts" ]]; then
        break
    fi

    echo "Composer failed; retrying after ${delay_seconds}s so transient registry/download failures can recover." >&2
    sleep "$delay_seconds"
    delay_seconds=$((delay_seconds * 2))
done

exit 1
