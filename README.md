## RMSCastRecorder

A tool for recording Ham Radio related shoutcast streams. This tool will listen to a shoutcast or icecast stream and only record when there is audio (rms activation)

To compile this project please run:
```bash
$ mvn package
```
from a terminal.

## Usage

Once the jar is built you can run the recorder against either a Shoutcast/Icecast stream URL or audio from stdin.
Recordings are broken into WAV files whenever the stream goes silent and are placed in day‑based folders.

URL example:
```bash
$ ./rms-cast-recorder \
      -u http://example.com:8000/stream.mp3 \
  -o ./recordings \
  -x /usr/local/bin/on-clip-written.sh \
  -r 8000 -c 1 -b 16
```

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

Options:

* `-u,--url <URL>` – stream URL to capture (mutually exclusive with `--stdin`)
* `-i,--stdin` – read audio from stdin (mutually exclusive with `--url`)
* `--stdin-raw` – treat stdin as raw PCM bytes (requires `--stdin`)
* `--stdin-rate <HZ>` – raw stdin sample rate (default matches `--sample-rate`)
* `--stdin-channels <N>` – raw stdin channels (default matches `--channels`)
* `--stdin-bits <BITS>` – raw stdin bit depth (default matches `--bitrate`)
* `--stdin-big-endian` – raw stdin byte order is big-endian (default little-endian)
* `--stdin-unsigned` – raw stdin encoding is unsigned PCM (default signed PCM)
* `-o,--out <DIR>` – base directory for recordings (default `./recordings`)
* `-t,--threshold <DB>` – silence threshold in dB (default -50)
* `-s,--silence <SECONDS>` – how long the signal must stay below threshold to
  end a clip (default 2)
* `-r,--sample-rate <HZ>` – output sample rate in Hz (default `8000`)
* `-c,--channels <N>` – output channels (`1` mono, `2` stereo; default `1`)
* `-b,--bitrate <BITS>` – output PCM bit depth in bits (default `16`)
* `-x,--on-write <PROGRAM>` – optional script/program to run each time a WAV is
  written; if `{wav}` is omitted, the full WAV path is passed as argument 1
* `-?,--help` – display help and exit

Exactly one input source is required: `--url` or `--stdin`.

When using `--stdin` without `--stdin-raw`, provide a Java Sound readable stream format (WAV is recommended).

Raw format flags (`--stdin-rate`, `--stdin-channels`, `--stdin-bits`, `--stdin-big-endian`, `--stdin-unsigned`) require `--stdin-raw`.

Examples for `--on-write`:

* `-x /usr/local/bin/on-clip-written.sh`
* `-x "/usr/local/bin/on-clip-written.sh --tag repeater-a"` (WAV path is still arg1)
* `-x "/usr/bin/python3 /opt/hooks/process_clip.py {wav} --mode fast"`
