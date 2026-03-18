#!/usr/bin/env bash
set -euo pipefail

ENDPOINT="${1:-http://127.0.0.1/rtl_sdr.php}"
ACTION="${2:-list}"

if [[ -z "${ENDPOINT}" ]]; then
	echo "Missing endpoint URL." >&2
	exit 2
fi

if [[ -z "${ACTION}" ]]; then
	echo "Missing action value." >&2
	exit 2
fi

response="$(
	curl \
		--silent \
		--show-error \
		--fail \
		--connect-timeout 3 \
		--max-time 12 \
		--retry 1 \
		--retry-delay 1 \
		--data-urlencode "action=${ACTION}" \
		"${ENDPOINT}"
)"

if ! printf '%s' "${response}" | grep -Eq '"ok"[[:space:]]*:[[:space:]]*true'; then
	echo "Watchdog endpoint returned an unexpected response." >&2
	printf '%s\n' "${response}" >&2
	exit 1
fi

exit 0