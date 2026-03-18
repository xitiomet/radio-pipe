#!/usr/bin/env bash
set -euo pipefail

SERVICE_NAME="rtl-sdr-watchdog"
UNIT_DIR="/etc/systemd/system"
TICK_DEST="/usr/local/bin/rtl_sdr_watchdog_tick.sh"
ENDPOINT="http://127.0.0.1/rtl_sdr.php"
INTERVAL_SEC=10
USER_NAME=""
GROUP_NAME=""
UNINSTALL=0

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
TICK_SOURCE="${SCRIPT_DIR}/rtl_sdr_watchdog_tick.sh"

usage() {
	cat <<'USAGE'
Usage:
  sudo ./install_rtl_sdr_watchdog.sh [options]

Options:
  --endpoint URL         Endpoint to tick (default: http://127.0.0.1/rtl_sdr.php)
  --interval-sec N       Timer interval in seconds (default: 10)
  --user NAME            Run service as this user (default: auto-detect web user, fallback root)
  --group NAME           Run service as this group (default: same as --user)
  --tick-dest PATH       Install path for watchdog tick script (default: /usr/local/bin/rtl_sdr_watchdog_tick.sh)
  --service-name NAME    Systemd unit base name (default: rtl-sdr-watchdog)
  --uninstall            Disable and remove the service + timer
  -h, --help             Show this help

Examples:
  sudo ./install_rtl_sdr_watchdog.sh
  sudo ./install_rtl_sdr_watchdog.sh --endpoint http://127.0.0.1/rtl_sdr.php --interval-sec 6
  sudo ./install_rtl_sdr_watchdog.sh --uninstall
USAGE
}

die() {
	echo "ERROR: $*" >&2
	exit 1
}

has_command() {
	command -v "$1" >/dev/null 2>&1
}

detect_default_user() {
	local candidates=(www-data nginx apache http)
	local candidate
	for candidate in "${candidates[@]}"; do
		if id -u "${candidate}" >/dev/null 2>&1; then
			echo "${candidate}"
			return 0
		fi
	done
	echo "root"
}

parse_args() {
	while [[ $# -gt 0 ]]; do
		case "$1" in
			--endpoint)
				[[ $# -ge 2 ]] || die "--endpoint requires a value"
				ENDPOINT="$2"
				shift 2
				;;
			--interval-sec)
				[[ $# -ge 2 ]] || die "--interval-sec requires a value"
				INTERVAL_SEC="$2"
				shift 2
				;;
			--user)
				[[ $# -ge 2 ]] || die "--user requires a value"
				USER_NAME="$2"
				shift 2
				;;
			--group)
				[[ $# -ge 2 ]] || die "--group requires a value"
				GROUP_NAME="$2"
				shift 2
				;;
			--tick-dest)
				[[ $# -ge 2 ]] || die "--tick-dest requires a value"
				TICK_DEST="$2"
				shift 2
				;;
			--service-name)
				[[ $# -ge 2 ]] || die "--service-name requires a value"
				SERVICE_NAME="$2"
				shift 2
				;;
			--uninstall)
				UNINSTALL=1
				shift
				;;
			-h|--help)
				usage
				exit 0
				;;
			*)
				die "Unknown option: $1"
				;;
		esac
	done
}

validate_inputs() {
	[[ "${EUID}" -eq 0 ]] || die "Run as root (sudo)."
	has_command systemctl || die "systemctl not found."
	has_command install || die "install command not found."
	has_command curl || die "curl not found."

	[[ -f "${TICK_SOURCE}" ]] || die "Tick source script not found at ${TICK_SOURCE}"

	[[ -n "${SERVICE_NAME}" ]] || die "Service name cannot be empty."
	[[ "${SERVICE_NAME}" =~ ^[A-Za-z0-9_.@-]+$ ]] || die "Service name has invalid characters: ${SERVICE_NAME}"

	[[ -n "${ENDPOINT}" ]] || die "Endpoint cannot be empty."
	[[ "${ENDPOINT}" =~ ^https?://[^[:space:]]+$ ]] || die "Endpoint must be an http(s) URL with no spaces."

	[[ "${INTERVAL_SEC}" =~ ^[0-9]+$ ]] || die "--interval-sec must be a positive integer."
	(( INTERVAL_SEC >= 2 )) || die "--interval-sec must be >= 2 seconds."

	if [[ -z "${USER_NAME}" ]]; then
		USER_NAME="$(detect_default_user)"
	fi
	if [[ -z "${GROUP_NAME}" ]]; then
		GROUP_NAME="${USER_NAME}"
	fi

	id -u "${USER_NAME}" >/dev/null 2>&1 || die "User not found: ${USER_NAME}"
	getent group "${GROUP_NAME}" >/dev/null 2>&1 || die "Group not found: ${GROUP_NAME}"
}

service_unit_path() {
	echo "${UNIT_DIR}/${SERVICE_NAME}.service"
}

timer_unit_path() {
	echo "${UNIT_DIR}/${SERVICE_NAME}.timer"
}

write_service_unit() {
	local service_path
	service_path="$(service_unit_path)"

	cat > "${service_path}" <<EOF
[Unit]
Description=RTL-SDR auto-recovery watchdog tick
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=${USER_NAME}
Group=${GROUP_NAME}
ExecStart=${TICK_DEST} ${ENDPOINT} list
Nice=10
IOSchedulingClass=idle
EOF
}

write_timer_unit() {
	local timer_path
	timer_path="$(timer_unit_path)"

	cat > "${timer_path}" <<EOF
[Unit]
Description=Run RTL-SDR watchdog tick periodically

[Timer]
OnBootSec=20s
OnUnitActiveSec=${INTERVAL_SEC}s
AccuracySec=1s
Unit=${SERVICE_NAME}.service
Persistent=true

[Install]
WantedBy=timers.target
EOF
}

install_tick_script() {
	local tick_dir
	tick_dir="$(dirname "${TICK_DEST}")"
	mkdir -p "${tick_dir}"
	install -m 0755 "${TICK_SOURCE}" "${TICK_DEST}"
}

enable_timer() {
	systemctl daemon-reload
	systemctl enable --now "${SERVICE_NAME}.timer"
	# Trigger an immediate run so behavior is validated now, not only after timer delay.
	systemctl start "${SERVICE_NAME}.service"
}

disable_and_remove_units() {
	if systemctl list-unit-files | grep -Fq "${SERVICE_NAME}.timer"; then
		systemctl disable --now "${SERVICE_NAME}.timer" || true
	fi

	if systemctl list-unit-files | grep -Fq "${SERVICE_NAME}.service"; then
		systemctl stop "${SERVICE_NAME}.service" || true
	fi

	rm -f "$(service_unit_path)" "$(timer_unit_path)"
	systemctl daemon-reload
	systemctl reset-failed || true
}

print_summary() {
	echo
	echo "Watchdog installed successfully."
	echo "Service: ${SERVICE_NAME}.service"
	echo "Timer:   ${SERVICE_NAME}.timer"
	echo "Endpoint: ${ENDPOINT}"
	echo "Interval: ${INTERVAL_SEC}s"
	echo "Run as: ${USER_NAME}:${GROUP_NAME}"
	echo
	echo "Useful commands:"
	echo "  systemctl status ${SERVICE_NAME}.timer --no-pager"
	echo "  systemctl status ${SERVICE_NAME}.service --no-pager"
	echo "  journalctl -u ${SERVICE_NAME}.service -n 100 --no-pager"
}

main() {
	parse_args "$@"
	validate_inputs

	if [[ "${UNINSTALL}" -eq 1 ]]; then
		disable_and_remove_units
		echo "Removed ${SERVICE_NAME}.service and ${SERVICE_NAME}.timer"
		exit 0
	fi

	install_tick_script
	write_service_unit
	write_timer_unit
	enable_timer
	print_summary
}

main "$@"