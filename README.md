## RadioPipe

RadioPipe is a lightweight command-line recorder for Ham, CB, and GMRS radio streams delivered over Shoutcast or Icecast. It monitors incoming audio and only records when signal is present using root-mean-square (RMS) activation, so you capture actual transmissions instead of long stretches of silence. It can also read audio from stdin, making it easy to pipe in soundcard input, a DigiRig feed, or any other audio source.

Built for unattended logging, RadioPipe stores WAV clips in date-based folders and names each file with the stream title and timestamp for quick browsing. You can run multiple instances to the same output directory without conflicts as long as each stream name is unique.

## Feature Overview

* Records only active audio (RMS gating) to avoid silence-heavy files
* Accepts stream URLs (`--url`), stdin audio (`--stdin`), or command-generated audio (`--pipe-input`)
* Supports optional DCS/CTCSS gating for tone/code-controlled recording
* Can emit gated audio to stdout as WAV clips or raw PCM (`--stdout` / `--stdout-raw`)
* Can play gated audio directly to a selected hardware output device (`--dev`, `--devs`)
* Can launch one or more pipe commands and stream output audio to each command stdin (`--pipe-output` / `--pipe-output-raw`)
* Can publish real-time recorder events over WebSocket (`--api-websocket host:port`)
* Writes date-organized clips with stream metadata and timestamped filenames
* Supports post-write automation hooks (`--on-write`) for conversion/upload workflows
* Includes PHP tools for browsing recordings and managing RTL-SDR pipelines

### Example output (`-o ./recordings`)

A recording path will look like:

`./recordings/2026-03-12/2026-03-12_024630_RadPi.wav`

Regular builds can be found at [radio-pipe downloads](https://openstatic.org/projects/radio-pipe/#downloads) (scroll to the bottom)

### PHP Recordings Browser
![](https://openstatic.org/projects/radio-pipe/recordings_php.png)

`php/recordings.php` provides a web interface for browsing and reviewing recordings.
You only need a PHP-capable web server with these extensions:

* php-zip
* php-json

The page can be moved or renamed. Point it at your recordings directory by setting a config file.

Create `php/config.php` (recommended) with the following variables:
```php
<?php
// Settings
$recordingsRoot = '/mnt/Media/recordings';
$PAGE_TITLE = 'Icecast Stream Recordings';
// End Settings
```

If you are on Windows and want a quick setup, there is an experimental stand-alone package that sets up everything in `%APPDATA%`.

[recordings-browser Installer](https://openstatic.org/projects/radio-pipe/recordings-browser.exe)
You will likely see security warnings because it is a 7zip self-extracting archive.

There is no uninstall, but you can right-click the shortcut, open its location, and delete the folder.

### PHP RTL-SDR Manager
![](https://openstatic.org/projects/radio-pipe/rtlsdr_manager.png)

`php/rtl_sdr.php` is a single-page RTL-SDR control panel + JSON API. It starts and monitors `rtl_fm` pipelines, then routes audio into `radio-pipe` (recording), `ffmpeg` (live Icecast streaming), or both.

It is designed for unattended radio capture setups where you need to tune, monitor, and recover multiple RTL-SDR pipelines from one page.

#### What the page does

* Discovers RTL-SDR dongles (index + serial aware)
* Starts, stops, and retunes active device pipelines
* Supports FM/WBFM/AM/USB/LSB/RAW modes
* Supports optional DCS/CTCSS gating and bias-tee
* Saves streaming server presets, recording upload presets, and reusable templates
* Shows per-device logs in UI and supports log download
* Tracks desired/running state and can auto-restart crashed pipelines with backoff

#### Requirements for `rtl_sdr.php`

* `rtl_fm` (`rtl-sdr` package)
* `radio-pipe` in `PATH` (required for recording and stream conditioning)
* `ffmpeg` in `PATH` (required when stream output is enabled)
* `curl` in `PATH` (required only for After Record upload modes)

#### Quick start

1. Put `php/rtl_sdr.php` on a PHP-capable web server.
2. (Recommended) create `php/config.php` and set your recordings directory:

```php
<?php
$recordingsRoot = '/mnt/Media/recordings';
```

3. Open the page in your browser.

Local test from this repo:

```bash
$ cd php
$ ./server.sh
# then open http://localhost:8000/rtl_sdr.php
```

#### Runtime files created next to `rtl_sdr.php`

* `rtl_sdr_state.json` / `rtl_sdr_desired_state.json` - running and desired device state
* `rtl_sdr_logs/` - per-device launch/runtime logs
* `streaming_servers.json` - saved Icecast targets
* `recording_servers.json` - saved upload targets for after-record actions
* `rtl_sdr_templates.json` - saved templates/presets
* `rtl_sdr_ui_settings.json` - saved per-device UI settings

#### Watchdog and queued retunes (recommended)

Retune actions are queued and applied by watchdog ticks. For unattended operation, install the included systemd timer/service:

```bash
$ sudo ./scripts/install_rtl_sdr_watchdog.sh --endpoint http://127.0.0.1/rtl_sdr.php
```

The installer now embeds the tick script into the installed target path (`--tick-dest`) and runs a preflight endpoint check before creating systemd units.

By default, the watchdog posts `action=list` with `source=<service-name>` on each interval (override with `--action` and `--source`) to process queued retunes and periodic state cleanup.


## Usages
You can run the recorder against a Shoutcast/Icecast stream URL, stdin audio, or audio produced by a launched command.
Recordings are broken into WAV files whenever the stream goes silent and are placed in day‑based folders.

URL example:
```bash
$ ./radio-pipe \
      -u http://example.com:8000/stream.mp3 \
  -o ./recordings \
  -x /usr/local/bin/on-clip-written.sh \
  -r 8000 -c 1 -b 16
```
`on-clip-written.sh` runs after each clip is produced. The full WAV path is passed as argument 1.

stdin example (`arecord`):
```bash
$ arecord -f S16_LE -c 1 -r 8000 -t wav - \
  | ./radio-pipe --stdin -o ./recordings
```

stdin example (`sox`):
```bash
$ sox /path/to/input.mp3 -t wav - \
  | ./radio-pipe --stdin -o ./recordings
```

pipe input example (launch app and read its stdout as input):
```bash
$ ./radio-pipe --pipe-input "arecord -D pulse -f S16_LE -c 1 -r 8000 -t wav -" \
  -o ./recordings
```

raw PCM stdin example (`arecord`):
```bash
$ arecord -f S16_LE -c 1 -r 8000 -t raw - \
  | ./radio-pipe --stdin --stdin-raw \
    --stdin-rate 8000 --stdin-channels 1 --stdin-bits 16 \
    -o ./recordings
```

raw PCM pipe input example (explicit format):
```bash
$ ./radio-pipe --pipe-input "arecord -D pulse -f S16_LE -c 1 -r 8000 -t raw -" \
  --pipe-input-raw \
  --pipe-input-rate 8000 \
  --pipe-input-channels 1 \
  --pipe-input-bits 16 \
  -o ./recordings
```

raw PCM stdout example (gate audio for another program):
```bash
$ rtl_fm -f 462.550M -M fm -s 12000 -r 8000 -E deemp -l 25 \
  | ./radio-pipe --stdin --stdin-raw \
    --stdin-rate 8000 --stdin-channels 1 --stdin-bits 16 \
    --dcs 023 \
    --stdout --stdout-raw --stdout-pad \
    --stdout-rate 8000 --stdout-channels 1 --stdout-bits 16 \
  | your-program-that-reads-raw-pcm
```

raw PCM stdout example with post-gate gain:
```bash
$ rtl_fm -f 462.550M -M fm -s 12000 -r 8000 -E deemp -l 25 \
  | ./radio-pipe --stdin --stdin-raw \
    --stdin-rate 8000 --stdin-channels 1 --stdin-bits 16 \
    --dcs 023 \
    --gain 6 --auto-gain \
    --stdout --stdout-raw --stdout-pad \
    --stdout-rate 8000 --stdout-channels 1 --stdout-bits 16 \
  | your-program-that-reads-raw-pcm
```

list hardware output devices and play to one:
```bash
$ ./radio-pipe --devs

$ rtl_fm -f 462.550M -M fm -s 12000 -r 8000 -E deemp -l 25 \
  | ./radio-pipe --stdin --stdin-raw \
    --stdin-rate 8000 --stdin-channels 1 --stdin-bits 16 \
    --dcs 023 --dev 0 -o ./recordings
```

`--dev` accepts either a device index from `--devs` (example: `--dev 0`) or a case-insensitive name/description substring (example: `--dev USB`).

pipe output example (launch process and send WAV clip stream to stdin):
```bash
$ ./radio-pipe -u http://example.com:8000/stream.mp3 \
  -o ./recordings \
  --pipe-output "aplay -D pulse"
```

multiple pipe outputs (repeat `--pipe-output`):
```bash
$ ./radio-pipe -u http://example.com:8000/stream.mp3 \
  -o ./recordings \
  --pipe-output "aplay -D pulse" \
  --pipe-output "ffplay -autoexit -nodisp -i -"
```

By default, `--pipe-output` writes a WAV clip payload when a clip closes. If a pipe process exits, RadioPipe will try to restart it automatically.

raw pipe output example (explicit PCM format):
```bash
$ ./radio-pipe -u http://example.com:8000/stream.mp3 \
  -o ./recordings \
  --pipe-output "aplay -f S16_LE -r 8000 -c 1" \
  --pipe-output-raw \
  --pipe-output-rate 8000 \
  --pipe-output-channels 1 \
  --pipe-output-bits 16
```

`--pipe-output` is always WAV clip output. Use `--pipe-output-raw` to send raw PCM stream to the same pipe commands. Raw format flags are `--pipe-output-rate`, `--pipe-output-channels`, `--pipe-output-bits`, `--pipe-output-big-endian`, and `--pipe-output-unsigned`.

Note: Combining higher fixed `--gain` values with `--auto-gain` can increase clipping on loud signals; reduce `--gain` if output sounds distorted.

websocket API example (publish gate/hook events to clients):
```bash
$ ./radio-pipe \
      -u http://example.com:8000/stream.mp3 \
      -o ./recordings \
      --dcs 073 \
      --api-websocket 0.0.0.0:9000
```

See `websocket.MD` for event details and examples.

## Audio Pipeline And Gate Order

RadioPipe uses one internal processing path regardless of whether audio comes from a network stream or stdin.

### End-to-end audio path

1. Input arrives from one of these sources:
  * `--url`: Shoutcast/Icecast stream
  * `--stdin`: containerized audio from stdin
  * `--stdin --stdin-raw`: raw PCM from stdin using the `--stdin-rate`, `--stdin-channels`, and `--stdin-bits` settings
  * `--pipe-input`: containerized audio from a launched command's stdout
  * `--pipe-input --pipe-input-raw`: raw PCM from a launched command's stdout using `--pipe-input-rate`, `--pipe-input-channels`, and `--pipe-input-bits`
2. The input is decoded into signed 16-bit PCM for analysis.
3. An input de-jitter buffer smooths short read stalls before the gate logic runs.
4. Audio is processed in small chunks and evaluated by the gate stages described below.
5. If the gate is open, audio is counted as active sound and starts or continues a clip.
6. If a clip is already open and the gate closes, RadioPipe keeps appending the non-passing tail until the configured silence timeout expires, then closes the clip.
7. Post-gate gain is applied before output when configured:
  * `--gain <dB>` adds fixed gain (or attenuation)
  * `--auto-gain` adds automatic boost toward a target loudness
8. When a clip closes:
  * file output writes a WAV file under the recordings directory if `-o` is enabled
  * `--stdout` writes the finished clip to stdout as WAV
  * `--on-write` runs after a file clip is written
9. In `--stdout --stdout-raw` mode, gated audio is emitted immediately as PCM instead of waiting for clip close. With `--stdout-pad`, the raw stdout path stays continuous by outputting silence during stalls or closed-gate periods.
10. In `--dev` mode, gated audio is also emitted immediately to the selected hardware playback device (using Java Sound output mixers).
11. In `--pipe-output` mode, each configured command is launched by RadioPipe and receives WAV clip payloads through stdin (repeat `--pipe-output` to fan out to multiple consumers).
12. In `--pipe-output-raw` mode, those same pipe commands receive raw PCM stream data using the configured `--pipe-output-*` format flags.
13. In `--pipe-input` mode, RadioPipe launches the configured command and treats that command's stdout as input audio; if the command exits, RadioPipe retries with backoff.

### Gate stages in evaluation order

Each chunk is checked in this order:

1. DCS gate, if `--dcs` is configured
  * The chunk is checked for the required DCS code.
  * If the code was recently present and `--gate-hold` is greater than `0`, the DCS gate can stay open for the grace period.
  * If this stage fails, the blocking reason becomes `dcs`.
2. CTCSS gate, if `--ctcss` is configured
  * The chunk is checked for the required CTCSS tone.
  * Like DCS, it can remain open briefly using the same `--gate-hold` grace period.
  * If this stage fails, the blocking reason becomes `ctcss`.
3. RMS silence gate
  * The chunk RMS level is measured in dB.
  * If it is below the configured silence threshold, the chunk is blocked.
  * This stage runs after DCS/CTCSS and reports `silence` when those gates are already passing.

The gate only passes audio when all active checks are true:

```text
dcs match AND ctcss match AND not silent
```

If a gate is not configured, that stage is treated as already open.

### Gate reason precedence

When more than one condition could block audio, RadioPipe reports the first failing stage in this fixed order:

```text
dcs -> ctcss -> silence
```

That means DCS is reported first, then CTCSS, and `silence` is only reported when tone/code gates pass but RMS is still below threshold.

### Clip close behavior

A clip does not close the instant the gate fails. After a clip has started, RadioPipe keeps buffering trailing audio until the accumulated blocked period reaches the configured silence duration. At that point it closes the clip.

Only clips with more than 1 second of gated sound are kept. Short bursts below that threshold are discarded.
## Sample Scripts

The `scripts/` directory contains helper scripts for converting recorded WAV files to compressed formats. All scripts require `exiftool` (`sudo apt install -y libimage-exiftool-perl`) to preserve the stream title and comment metadata during conversion.

### `x-convert-mp3.sh` — Per-clip MP3 conversion (for `-x` / `--on-write`)

Converts a single WAV file to MP3 and removes the original on success. Designed to be passed directly to the `-x` option so each clip is compressed immediately after it is written.

Requires: `exiftool`, `lame` (`sudo apt install -y lame`)

```bash
$ ./radio-pipe -u http://example.com:8000/stream.mp3 \
      -o ./recordings \
      -x ./scripts/x-convert-mp3.sh
```

### `x-convert-ogg.sh` — Per-clip OGG conversion (for `-x` / `--on-write`)

Same as `x-convert-mp3.sh` but produces an Ogg Vorbis file instead of MP3.

Requires: `exiftool`, `oggenc` (`sudo apt install -y vorbis-tools`)

```bash
$ ./radio-pipe -u http://example.com:8000/stream.mp3 \
      -o ./recordings \
      -x ./scripts/x-convert-ogg.sh
```

### `bulk-compress-to-mp3.sh` — Batch WAV → MP3 conversion

Walks a directory tree (passed as the first argument) and converts every WAV file it finds to MP3, removing the original after a successful conversion. Useful as a scheduled cron job to compress an existing archive of recordings.

Requires: `exiftool`, `lame`

```bash
$ ./scripts/bulk-compress-to-mp3.sh ./recordings
```

### `bulk-compress-to-ogg.sh` — Batch WAV → OGG conversion

Same as `bulk-compress-to-mp3.sh` but converts to Ogg Vorbis. Can also be run as a cron job.

Requires: `exiftool`, `oggenc`

```bash
$ ./scripts/bulk-compress-to-ogg.sh ./recordings
```

### `rtl-fm-record.sh` — RTL-SDR capture helper

Runs `rtl_fm` and pipes raw PCM into `radio-pipe` using `--stdin --stdin-raw`.
The script sets stream names as `RTLSDR - (<frequency>)`, so each tuned frequency
is clearly labeled in output filenames and metadata.

Requires:

* `rtl_fm` (from `rtl-sdr`) (`sudo apt install -y rtl-sdr`)
* `radio-pipe` binary in `PATH` or pass `-e <path>`

Common examples:

```bash
$ ./scripts/rtl-fm-record.sh -f 146.520M -q 20 -o ./recordings
```

```bash
$ ./scripts/rtl-fm-record.sh -f 462.550M -q 25 -R 12000 -o ./recordings
```

```bash
$ ./scripts/rtl-fm-record.sh -f 462.550M -q 25 -D 023 -o ./recordings
```

```bash
$ ./scripts/rtl-fm-record.sh -f 462.550M -q 25 -C 100.0 -o ./recordings
```

Script options:

* `-f,--frequency <freq>` – required frequency to tune
* `-q,--squelch <level>` – rtl_fm squelch level (default 0)
* `-D,--dcs <code>` – optional DCS gate code (octal, example `023`)
* `-C,--ctcss <hz>` – optional CTCSS gate tone in Hz (example `100.0`)
* `-R,--sample-rate <hz>` – sample rate for both rtl_fm and recorder (default 8000)
* `-o,--out <dir>` – output recordings directory (default `./recordings`)
* `-e,--exec <path>` – path/name of radio-pipe executable
* `-m,--mode <mode>` – rtl_fm mode (default `fm`)
* `-d,--device <index>` – rtl_fm device index
* `-g,--gain <gain>` – rtl_fm gain value
* `-t,--threshold <db>` – recorder silence threshold dB (default `-50`)
* `-s,--silence <seconds>` – recorder silence duration in seconds (default `2`)
* `-x,--on-write <program>` – recorder on-write hook

## Metadata and Live Listen
Every output format (`.wav`, `.mp3`, `.ogg`) can carry a `Comment` metadata field.
For the Live Listen feature in the PHP recordings interface, this field must contain
`Source URL: http://xyz/abc.mp3`, pointing to the original stream.

When recording from `--url`, RadioPipe writes this source URL into WAV metadata.
If you convert files later, preserve this metadata so Live Listen continues to work.

## Options

Use this section as a full reference. If you are skimming, start with the quick map below.

### Option groups (quick map)

* **Input source**: `--url` or `--stdin` (exactly one is required)
* **Raw stdin format**: `--stdin-raw`, `--stdin-rate`, `--stdin-channels`, `--stdin-bits`, `--stdin-big-endian`, `--stdin-unsigned`, `--input-dejitter`
* **Output modes**: file recording with `-o`, clip/WAV stdout with `--stdout`, raw stdout with `--stdout-raw`, continuous padded raw stream with `--stdout-pad`
* **Audio/clip parameters**: `-t`, `-s`, `-r`, `-c`, `-b`
* **Tone/code gating**: `--dcs`, `--ctcss`, `--gate-hold`
* **Post-gate gain**: `--gain`, `--auto-gain`
* **Naming/automation**: `-n`, `-x`
* **WebSocket API**: `--api-websocket`

* -u,--url <URL> – stream URL to capture (mutually exclusive with --stdin)
* -i,--stdin – read audio from stdin (mutually exclusive with --url)
* --stdin-raw – treat stdin as raw PCM bytes (requires --stdin)
* --stdin-rate <HZ> – raw stdin sample rate (default matches --sample-rate)
* --stdin-channels <N> – raw stdin channels (default matches --channels)
* --stdin-bits <BITS> – raw stdin bit depth (default matches --bitrate)
* --stdin-big-endian – raw stdin byte order is big-endian (default little-endian)
* --stdin-unsigned – raw stdin encoding is unsigned PCM (default signed PCM)
* --input-dejitter <MS> – input de-jitter buffer depth for bursty piped input (default `250`)
* --stdout – write gated clips to stdout as WAV clip stream
* --stdout-raw – write gated audio to stdout as raw PCM bytes
* --stdout-pad – when stdout raw mode is enabled, output a continuous gapless stream (gated audio + silence padding); no halt at startup or mid-stream stall
* --stdout-pad-delay <MS> – depth of the output delay buffer in ms; audio is delayed by this amount so the output stream is always continuous (default `500`)
* --stdout-rate <HZ> – raw stdout sample rate (default matches --sample-rate)
* --stdout-channels <N> – raw stdout channels (default matches --channels)
* --stdout-bits <BITS> – raw stdout bit depth (default matches --bitrate)
* --stdout-big-endian – raw stdout byte order is big-endian (default little-endian)
* --stdout-unsigned – raw stdout encoding is unsigned PCM (default signed PCM)
* -o,--out [DIR] – base directory for recordings (default `$RADIOPIPE_RECORDINGS` or `./recordings`; if `--stdout` is used without `-o`, file recording is disabled)
* -t,--threshold <DB> – silence threshold in dB (default -50)
* -s,--silence <SECONDS> – how long the signal must stay below threshold to
  end a clip (default 2)
* -r,--sample-rate <HZ> – output sample rate in Hz (default 8000)
* -c,--channels <N> – output channels (1 mono, 2 stereo; default 1)
* -b,--bitrate <BITS> – output PCM bit depth in bits (default 16)
* --dcs <CODE> – optional DCS gate code (octal, example `023`); clip audio only while matching DCS is detected
* --ctcss <HZ> – optional CTCSS gate tone in Hz (example `100.0`); clip audio only while matching tone is detected
* --gate-hold <SECONDS> – additional grace time to keep DCS/CTCSS gates open after decode drops (default `0`)
* --gain <DB> – fixed post-gate gain in dB before recording/stdout (range `-60` to `+60`, default `0`)
* --auto-gain – enable automatic post-gate boost toward target level (applies after gates, before recording/stdout)
* --api-websocket <HOST:PORT> – start embedded websocket API server and publish recorder events (example `0.0.0.0:9000`)
* -n,--name <STREAM> – override stream name used in output filenames
* -x,--on-write <PROGRAM> – optional script/program to run each time a WAV is
  written; if {wav} is omitted, the full WAV path is passed as argument 1
* -?,--help – display help and exit

Exactly one input source is required: `--url` or `--stdin`.

When using --stdin without --stdin-raw, provide a Java Sound readable stream format (WAV is recommended).

Raw format flags (--stdin-rate, --stdin-channels, --stdin-bits, --stdin-big-endian, --stdin-unsigned) require --stdin-raw.

`--input-dejitter` adds an input-side jitter buffer so short producer timing gaps (for example, some RTL-SDR pipe bursts) do not immediately create choppy downstream output.

Raw stdout format flags (--stdout-rate, --stdout-channels, --stdout-bits, --stdout-big-endian, --stdout-unsigned) require --stdout-raw.

`--stdout-pad` requires raw stdout output (`--stdout-raw`) and emits a continuous gapless stream by maintaining a fixed-depth delay buffer (default 500 ms) pre-filled with silence.

`--stdout-pad-delay` sets the delay buffer depth in ms; the output stream is always delayed by this amount but never interrupted, even at startup or during input stalls.

When using --dcs, output PCM bit depth must be 16 (`--bitrate 16`).

When using --ctcss, output PCM bit depth must be 16 (`--bitrate 16`).

When using both --dcs and --ctcss, both gates must match for clips to open.

`--gate-hold` adds extra hold time after DCS/CTCSS detection loss to prevent brief weak/noisy decode dropouts from closing the gate immediately (default `0` second).

`--gain` applies fixed post-gate gain (or attenuation) after gate decisions and before recording/stdout output.

`--auto-gain` adds automatic post-gate boost on passing audio frames; it can be combined with `--gain`.

When using --stdout without -o, recordings are not written to disk (stdout-only mode).

If `RADIOPIPE_RECORDINGS` is set (and non-empty), it is used as the default recordings directory whenever file recording is enabled and `-o` does not provide a path (including bare `-o`).

On Linux/macOS shells, this value must be exported (for example, `export RADIOPIPE_RECORDINGS=/path`) so child processes like `radio-pipe` can read it.

Examples for `RADIOPIPE_RECORDINGS`:

Linux/macOS (bash):

```bash
export RADIOPIPE_RECORDINGS=/mnt/Media/recordings
./radio-pipe --url http://example.com/stream.mp3
./radio-pipe --url http://example.com/stream.mp3 --stdout -o
```

Windows PowerShell:

```powershell
$env:RADIOPIPE_RECORDINGS = 'D:\Recordings'
.\radio-pipe.exe --url http://example.com/stream.mp3
.\radio-pipe.exe --url http://example.com/stream.mp3 --stdout -o
```

Windows Command Prompt (cmd.exe):

```bat
set RADIOPIPE_RECORDINGS=D:\Recordings
radio-pipe.exe --url http://example.com/stream.mp3
radio-pipe.exe --url http://example.com/stream.mp3 --stdout -o
```

`--stdout` by itself remains stdout-only (no disk writes); adding bare `-o` enables file recording using `$RADIOPIPE_RECORDINGS` (or `./recordings` when unset).

`--on-write` requires file recording (`-o`) and is ignored in stdout-only mode.

When recording from --url, filenames default to stream metadata (icy-name/x-audiocast-name/ice-name) unless --name is provided.

WAV files include stream name metadata in the title field.

WAV files written from --url input also include a comment metadata field with the original source URL.

Examples for --on-write:

* -x /usr/local/bin/on-clip-written.sh
* -x "/usr/local/bin/on-clip-written.sh --tag repeater-a" (WAV path is still arg1)
* -x "/usr/bin/python3 /opt/hooks/process_clip.py {wav} --mode fast"

## Build

To compile this project, run:
```bash
$ mvn package
```
from a terminal.