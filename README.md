## RMSCastRecorder

A tool for recording Ham Radio related shoutcast or icecast streams. This tool will listen to a shoutcast or icecast stream and only record when there is audio (root-mean-square activation) I found myself wishing i had an easy straightfoward way to record a stream without the long silences between transmissions. RMSCastRecorder will organize the produced wav files into folders by date, and the filename will include the stream title and timestamp.

Example output with -o ./recordings

A recording will be created as follows:

`./recordings/2026-03-12/2026-03-12_024630_RadPi.wav`

Regular builds can be found at [rms-cast-recorder downloads](https://openstatic.org/projects/rms-cast-recorder/#downloads) (scroll to the bottom)

### Optional PHP Interface
![](https://openstatic.org/projects/rms-cast-recorder/recordings_php.png)
In the subdirectory php is a web interface for browsing and reviewing the recordings. All you need is a php capable web server.

you can edit the index.php or create a config.php file with the following variables:
```php
// Settings
$recordingsRoot = '/mnt/Media/recordings';
$PAGE_TITLE = 'Icecast Stream Recordings';
// End Settings
```

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
* -o,--out <DIR> – base directory for recordings (default ./recordings)
* -t,--threshold <DB> – silence threshold in dB (default -50)
* -s,--silence <SECONDS> – how long the signal must stay below threshold to
  end a clip (default 2)
* -r,--sample-rate <HZ> – output sample rate in Hz (default 8000)
* -c,--channels <N> – output channels (1 mono, 2 stereo; default 1)
* -b,--bitrate <BITS> – output PCM bit depth in bits (default 16)
* -n,--name <STREAM> – override stream name used in output filenames
* -x,--on-write <PROGRAM> – optional script/program to run each time a WAV is
  written; if {wav} is omitted, the full WAV path is passed as argument 1
* -?,--help – display help and exit

Exactly one input source is required: `--url` or `--stdin`.

When using --stdin without --stdin-raw, provide a Java Sound readable stream format (WAV is recommended).

Raw format flags (--stdin-rate, --stdin-channels, --stdin-bits, --stdin-big-endian, --stdin-unsigned) require --stdin-raw.

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