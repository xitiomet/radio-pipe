## RMSCastRecorder

A tool for recording Ham/CB/GRMS Radio related shoutcast or icecast internet streams. This tool will listen to a stream and only record when there is audio (root-mean-square activation) it will also accept audio from stdin so you can pipe from your soundcard, maybe a digirig connected to a radio or another source.

I found myself wishing i had an easy straightfoward way to record a stream without the long silences between transmissions. RMSCastRecorder will organize the produced wav files into folders by date, and the filename will include the stream title and timestamp.
You can even run multiple instances pointed to the same output directory and as long as the stream names are different there should be
no conflicts.

Example output with -o ./recordings

A recording will be created as follows:

`./recordings/2026-03-12/2026-03-12_024630_RadPi.wav`

Regular builds can be found at [rms-cast-recorder downloads](https://openstatic.org/projects/rms-cast-recorder/#downloads) (scroll to the bottom)

### Optional PHP Interface
![](https://openstatic.org/projects/rms-cast-recorder/recordings_php.png)
In the subdirectory php of this project is a web interface for browsing and reviewing the recordings. 
All you need is a php capable web server. The php requirements are:

* php-zip
* php-json

The php page can be dropped anywhere or renamed, all you need to do is tell it where the recordings are.

you can edit the index.php or create a config.php file with the following variables:
```php
// Settings
$recordingsRoot = '/mnt/Media/recordings';
$PAGE_TITLE = 'Icecast Stream Recordings';
// End Settings
```
If you are on windows and don't want to go through the hassle, ive put together an experimental stand-alone
version for windows that uses a bunch of scripts to setup everything you need in "%APPDATA%"

[recordings-browser Installer](https://openstatic.org/projects/rms-cast-recorder/recordings-browser.exe)
You will probably get a lot of security warnings because its a 7zip sfx.

There is no uninstall, but you can right-click on the shortcut to open its location, its all one folder, easy to delete.

## Usage
Basic Usage example:
![](https://openstatic.org/projects/rms-cast-recorder/rms_screenshot.png)

You can run the recorder against either a Shoutcast/Icecast stream URL or audio from stdin.
Recordings are broken into WAV files whenever the stream goes silent and are placed in day‑based folders.

URL example:
```bash
$ ./rms-cast-recorder \
      -u http://example.com:8000/stream.mp3 \
  -o ./recordings \
  -x /usr/local/bin/on-clip-written.sh \
  -r 8000 -c 1 -b 16
```
on-clip-written.sh will run after the clip is produced, which will be passed (full path) as the first argument

stdin example (`arecord`):
```bash
$ arecord -f S16_LE -c 1 -r 8000 -t wav - \
  | ./rms-cast-recorder --stdin -o ./recordings
```

stdin example (`sox`):
```bash
$ sox /path/to/input.mp3 -t wav - \
  | ./rms-cast-recorder --stdin -o ./recordings
```

raw PCM stdin example (`arecord`):
```bash
$ arecord -f S16_LE -c 1 -r 8000 -t raw - \
  | ./rms-cast-recorder --stdin --stdin-raw \
    --stdin-rate 8000 --stdin-channels 1 --stdin-bits 16 \
    -o ./recordings
```

raw PCM stdout example (gate audio for another program):
```bash
$ rtl_fm -f 462.550M -M fm -s 12000 -r 8000 -E deemp -l 25 \
  | ./rms-cast-recorder --stdin --stdin-raw \
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
$ ./rms-cast-recorder -u http://example.com:8000/stream.mp3 \
      -o ./recordings \
      -x ./scripts/x-convert-mp3.sh
```

### `x-convert-ogg.sh` — Per-clip OGG conversion (for `-x` / `--on-write`)

Same as `x-convert-mp3.sh` but produces an Ogg Vorbis file instead of MP3.

Requires: `exiftool`, `oggenc` (`sudo apt install -y vorbis-tools`)

```bash
$ ./rms-cast-recorder -u http://example.com:8000/stream.mp3 \
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

Runs `rtl_fm` and pipes raw PCM into `rms-cast-recorder` using `--stdin --stdin-raw`.
The script sets stream names as `RTLSDR - (<frequency>)`, so each tuned frequency
is clearly labeled in output filenames and metadata.

Requires:

* `rtl_fm` (from `rtl-sdr`) (`sudo apt install -y rtl-sdr`)
* `rms-cast-recorder` binary in `PATH` or pass `-e <path>`

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

Script options:

* `-f,--frequency <freq>` – required frequency to tune
* `-q,--squelch <level>` – rtl_fm squelch level (default 0)
* `-D,--dcs <code>` – optional DCS gate code (octal, example `023`)
* `-R,--sample-rate <hz>` – sample rate for both rtl_fm and recorder (default 8000)
* `-o,--out <dir>` – output recordings directory (default `./recordings`)
* `-e,--exec <path>` – path/name of rms-cast-recorder executable
* `-m,--mode <mode>` – rtl_fm mode (default `fm`)
* `-d,--device <index>` – rtl_fm device index
* `-g,--gain <gain>` – rtl_fm gain value
* `-t,--threshold <db>` – recorder silence threshold dB (default `-50`)
* `-s,--silence <seconds>` – recorder silence duration in seconds (default `2`)
* `-x,--on-write <program>` – recorder on-write hook

## Metadata and Live Listen
Inside the metadata of every file produced (ogg,wav,mp3) is a Comment field.
In order for the Live Listen feature to work in the php interface, this field
must contain "Source URL: http://xyz/abc.mp3" which will point to the original stream.

## Options

* -u,--url <URL> – stream URL to capture (mutually exclusive with --stdin)
* -i,--stdin – read audio from stdin (mutually exclusive with --url)
* --stdin-raw – treat stdin as raw PCM bytes (requires --stdin)
* --stdin-rate <HZ> – raw stdin sample rate (default matches --sample-rate)
* --stdin-channels <N> – raw stdin channels (default matches --channels)
* --stdin-bits <BITS> – raw stdin bit depth (default matches --bitrate)
* --stdin-big-endian – raw stdin byte order is big-endian (default little-endian)
* --stdin-unsigned – raw stdin encoding is unsigned PCM (default signed PCM)
* --stdout – write gated clips to stdout as WAV clip stream
* --stdout-raw – write gated audio to stdout as raw PCM bytes
* --stdout-pad – when stdout raw mode is enabled, emit silence while input stream stalls
* --stdout-rate <HZ> – raw stdout sample rate (default matches --sample-rate)
* --stdout-channels <N> – raw stdout channels (default matches --channels)
* --stdout-bits <BITS> – raw stdout bit depth (default matches --bitrate)
* --stdout-big-endian – raw stdout byte order is big-endian (default little-endian)
* --stdout-unsigned – raw stdout encoding is unsigned PCM (default signed PCM)
* -o,--out <DIR> – base directory for recordings (default ./recordings; if --stdout is used without -o, file recording is disabled)
* -t,--threshold <DB> – silence threshold in dB (default -50)
* -s,--silence <SECONDS> – how long the signal must stay below threshold to
  end a clip (default 2)
* -r,--sample-rate <HZ> – output sample rate in Hz (default 8000)
* -c,--channels <N> – output channels (1 mono, 2 stereo; default 1)
* -b,--bitrate <BITS> – output PCM bit depth in bits (default 16)
* --dcs <CODE> – optional DCS gate code (octal, example `023`); clip audio only while matching DCS is detected
* -n,--name <STREAM> – override stream name used in output filenames
* -x,--on-write <PROGRAM> – optional script/program to run each time a WAV is
  written; if {wav} is omitted, the full WAV path is passed as argument 1
* -?,--help – display help and exit

Exactly one input source is required: `--url` or `--stdin`.

When using --stdin without --stdin-raw, provide a Java Sound readable stream format (WAV is recommended).

Raw format flags (--stdin-rate, --stdin-channels, --stdin-bits, --stdin-big-endian, --stdin-unsigned) require --stdin-raw.

Raw stdout format flags (--stdout-rate, --stdout-channels, --stdout-bits, --stdout-big-endian, --stdout-unsigned) require --stdout-raw.

`--stdout-pad` requires raw stdout output (`--stdout-raw`) and emits timed silence while the input stream read is stalled.

When using --dcs, output PCM bit depth must be 16 (`--bitrate 16`).

When using --stdout without -o, recordings are not written to disk (stdout-only mode).

`--on-write` requires file recording (`-o`) and is ignored in stdout-only mode.

When recording from --url, filenames default to stream metadata (icy-name/x-audiocast-name/ice-name) unless --name is provided.

WAV files include stream name metadata in the title field.

WAV files written from --url input also include a comment metadata field with the original source URL.

Examples for --on-write:

* -x /usr/local/bin/on-clip-written.sh
* -x "/usr/local/bin/on-clip-written.sh --tag repeater-a" (WAV path is still arg1)
* -x "/usr/bin/python3 /opt/hooks/process_clip.py {wav} --mode fast"


To compile this project please run:
```bash
$ mvn package
```
from a terminal.