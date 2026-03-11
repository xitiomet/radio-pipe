## RMSCastRecorder

A tool for recording Ham Radio related shoutcast streams. This tool will listen to a shoutcast or icecast stream and only record when there is audio (rms activation)

To compile this project please run:
```bash
$ mvn package
```
from a terminal.

## Usage

Once the jar is built you can run the recorder against any Shoutcast/Icecast stream URL.  Recordings are broken
into WAV files whenever the stream goes silent and are placed in day‑based folders.

Example:
```bash
$ java -jar target/rms-cast-recorder-1.0.jar \
      -u http://example.com:8000/stream.mp3 \
  -o ./recordings \
  -r 8000 -c 1 -b 16
```

Options:

* `-u,--url <URL>` – stream to capture (required)
* `-o,--out <DIR>` – base directory for recordings (default `./recordings`)
* `-t,--threshold <DB>` – silence threshold in dB (default -50)
* `-s,--silence <SECONDS>` – how long the signal must stay below threshold to
  end a clip (default 2)
* `-r,--sample-rate <HZ>` – output sample rate in Hz (default `8000`)
* `-c,--channels <N>` – output channels (`1` mono, `2` stereo; default `1`)
* `-b,--bitrate <BITS>` – output PCM bit depth in bits (default `16`)
* `-?,--help` – display help and exit
