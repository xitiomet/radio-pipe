#!/bin/bash

set -euo pipefail

usage() {
	cat <<'EOF'
Usage: ./scripts/rtl-fm-record.sh -f <frequency> [-q <squelch>] [-D <dcs>] [options]

Record from RTL-SDR via rtl_fm and pipe audio into rms-cast-recorder.

Required:
  -f, --frequency <freq>   Frequency tuned for rtl_fm (examples: 146.520M, 462.550M, 915000000)

Optional:
  -q, --squelch <level>    rtl_fm squelch level (default: 0)
	-D, --dcs <code>         DCS gate code for recorder (octal, example: 023)
  -R, --sample-rate <hz>   Sample rate for both rtl_fm and recorder stdin/output (default: 8000)
  -o, --out <dir>          Output directory for recordings (default: ./recordings)
  -e, --exec <path>        rms-cast-recorder executable (default: ./rms-cast-recorder)
  -m, --mode <mode>        rtl_fm demod mode (default: fm)
  -d, --device <index>     rtl_fm device index
  -g, --gain <gain>        rtl_fm gain value
  -t, --threshold <db>     rms-cast-recorder silence threshold dB (default: -50)
  -s, --silence <seconds>  rms-cast-recorder silence duration seconds (default: 2)
  -x, --on-write <program> rms-cast-recorder on-write hook
  -h, --help               Show this help

Example:
  ./scripts/rtl-fm-record.sh -f 146.520M -q 20 -o ./recordings
EOF
}

frequency=""
squelch="0"
dcs=""
out_dir="./recordings"
recorder_bin="rms-cast-recorder"
mode="fm"
device=""
gain=""
threshold="-50"
silence="2"
on_write=""
sample_rate="8000"

while [[ $# -gt 0 ]]; do
	case "$1" in
		-f|--frequency)
			frequency="${2:-}"
			shift 2
			;;
		-q|--squelch)
			squelch="${2:-}"
			shift 2
			;;
		-D|--dcs)
			dcs="${2:-}"
			shift 2
			;;
		-R|--sample-rate)
			sample_rate="${2:-}"
			shift 2
			;;
		-o|--out)
			out_dir="${2:-}"
			shift 2
			;;
		-e|--exec)
			recorder_bin="${2:-}"
			shift 2
			;;
		-m|--mode)
			mode="${2:-}"
			shift 2
			;;
		-d|--device)
			device="${2:-}"
			shift 2
			;;
		-g|--gain)
			gain="${2:-}"
			shift 2
			;;
		-t|--threshold)
			threshold="${2:-}"
			shift 2
			;;
		-s|--silence)
			silence="${2:-}"
			shift 2
			;;
		-x|--on-write)
			on_write="${2:-}"
			shift 2
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			echo "Unknown option: $1" >&2
			usage
			exit 1
			;;
	esac
done

if [[ -z "$frequency" ]]; then
	echo "Error: frequency is required." >&2
	usage
	exit 1
fi

if ! [[ "$sample_rate" =~ ^[0-9]+$ ]] || [[ "$sample_rate" -le 0 ]]; then
	echo "Error: sample rate must be a positive integer (Hz)." >&2
	exit 1
fi

if [[ -n "$dcs" ]]; then
	if ! [[ "$dcs" =~ ^[0-7]{1,3}$ ]]; then
		echo "Error: DCS code must be 1-3 octal digits (example: 023)." >&2
		exit 1
	fi
	dcs_dec=$((8#$dcs))
	printf -v dcs "%03o" "$dcs_dec"
fi

if ! command -v rtl_fm >/dev/null 2>&1; then
	echo "Error: rtl_fm is not installed or not in PATH." >&2
	exit 1
fi

if [[ "$recorder_bin" == */* ]]; then
	if [[ ! -x "$recorder_bin" ]]; then
		echo "Error: recorder executable not found or not executable: $recorder_bin" >&2
		exit 1
	fi
else
	if ! command -v "$recorder_bin" >/dev/null 2>&1; then
		echo "Error: recorder executable not found in PATH: $recorder_bin" >&2
		exit 1
	fi
fi

stream_name="RTLSDR - (${frequency})"

rtl_cmd=(
	rtl_fm
	-f "$frequency"
	-M "$mode"
	-s 12000
	-r "$sample_rate"
	-E deemp
	-l "$squelch"
)

if [[ -n "$device" ]]; then
	rtl_cmd+=( -d "$device" )
fi

if [[ -n "$gain" ]]; then
	rtl_cmd+=( -g "$gain" )
fi

rec_cmd=(
	"$recorder_bin"
	--stdin
	--stdin-raw
	--stdin-rate "$sample_rate"
	--stdin-channels 1
	--stdin-bits 16
	-r "$sample_rate"
	-t "$threshold"
	-s "$silence"
	-o "$out_dir"
	-n "$stream_name"
)

if [[ -n "$on_write" ]]; then
	rec_cmd+=( -x "$on_write" )
fi

if [[ -n "$dcs" ]]; then
	rec_cmd+=( --dcs "$dcs" )
fi

echo "Starting RTL-SDR recording"
echo "  Frequency: $frequency"
echo "  Squelch:   $squelch"
if [[ -n "$dcs" ]]; then
	echo "  DCS:       $dcs"
fi
echo "  Rate:      ${sample_rate} Hz"
echo "  Stream:    $stream_name"
echo "  Output:    $out_dir"

"${rtl_cmd[@]}" 2>/dev/null | "${rec_cmd[@]}"
