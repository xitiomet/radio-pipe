## RadioPipe

RadioPipe is a lightweight command-line recorder for Ham, CB, and GMRS radio streams delivered over Shoutcast or Icecast. It monitors incoming audio and only records when signal is present using root-mean-square (RMS) activation, so you capture actual transmissions instead of long stretches of silence. It can also read audio from stdin, making it easy to pipe in soundcard input, a DigiRig feed, or any other audio source.

Built for unattended logging, RadioPipe stores WAV clips in date-based folders and names each file with the stream title and timestamp for quick browsing. You can run multiple instances to the same output directory without conflicts as long as each stream name is unique.

## Feature Overview

* Records only active audio (RMS gating) to avoid silence-heavy files
* Accepts stream URLs (`--url`) or piped audio (`--stdin`, including raw PCM)
* Supports optional DCS/CTCSS gating for tone/code-controlled recording
* Can emit gated audio to stdout as WAV clips or raw PCM (`--stdout` / `--stdout-raw`)
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
![](https://openstatic.org/projects/radio-pipe/rtl_manage.png)

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

The watchdog posts `action=list&source=watchdog` on an interval to process queued retunes and periodic state cleanup.


## Usage
Basic usage example:
![](https://openstatic.org/projects/radio-pipe/rms_screenshot.png)

You can run the recorder against either a Shoutcast/Icecast stream URL or audio from stdin.
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

raw PCM stdin example (`arecord`):
```bash
$ arecord -f S16_LE -c 1 -r 8000 -t raw - \
  | ./radio-pipe --stdin --stdin-raw \
    --stdin-rate 8000 --stdin-channels 1 --stdin-bits 16 \
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
* **Naming/automation**: `-n`, `-x`

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
* --gate-hold <SECONDS> – additional grace time to keep DCS/CTCSS gates open after decode drops (default `1`)
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

`--gate-hold` adds extra hold time after DCS/CTCSS detection loss to prevent brief weak/noisy decode dropouts from closing the gate immediately (default `1` second).

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