# Radio Pipe WebSocket API

When `--api-websocket host:port` is provided, Radio Pipe starts an embedded WebSocket server and publishes real-time JSON event messages.

## Enable

```bash
radio-pipe --stdin --api-websocket 0.0.0.0:9000
```

- WebSocket URL format: `ws://host:port`
- Example: `ws://127.0.0.1:9000`

## Event Messages

All messages are JSON objects.

Common fields in every packet:
- `event`: event type
- `pid`: process ID of the running `radio-pipe` instance
- `apiPort`: websocket API port
- `timestamp`: Unix epoch timestamp in milliseconds when the packet is emitted

### Event Type Summary

- `dcsDetected`: configured DCS gate opened
- `dcsGone`: configured DCS gate closed
- `ctcssDetected`: configured CTCSS gate opened
- `ctcssGone`: configured CTCSS gate closed
- `gateOpen`: overall audio gate opened
- `gateClosed`: overall audio gate closed (or blocker changed)
- `audioDetected`: gated audio clip started
- `silenceDetected`: active clip ended due to silence
- `savedRecording`: clip file written to disk (`filename` included)
- `hookExecuted`: `--on-write` hook process started
- `hookResult`: output line and/or completion from hook process
- `status`: periodic snapshot event (every 250ms)

### DCS detected

```json
{"event":"dcsDetected","pid":12345,"apiPort":4040,"timestamp":1710938400001,"dcs":"073","polarity":"normal"}
```

Sent when a configured DCS gate opens.

Fields:
- `event`: always `dcsDetected`
- `dcs`: configured DCS code (octal, zero-padded)
- `polarity`: `normal`, `inverted`, or `unknown`

### DCS gone

```json
{"event":"dcsGone","pid":12345,"apiPort":4040,"timestamp":1710938401001,"dcs":"073","polarity":"normal"}
```

Sent when a configured DCS gate closes.

### CTCSS detected

```json
{"event":"ctcssDetected","pid":12345,"apiPort":4040,"timestamp":1710938400002,"ctcss":"100.0"}
```

Sent when a configured CTCSS gate opens.

### CTCSS gone

```json
{"event":"ctcssGone","pid":12345,"apiPort":4040,"timestamp":1710938401002,"ctcss":"100.0"}
```

Sent when a configured CTCSS gate closes.

### Gate closed

```json
{"event":"gateClosed","pid":12345,"apiPort":4040,"timestamp":1710938401003,"reason":"dcs"}
```

Sent when audio flow becomes blocked, or when the blocking reason changes.

Reason values:
- `dcs`
- `ctcss`
- `silence`

### Gate open

```json
{"event":"gateOpen","pid":12345,"apiPort":4040,"timestamp":1710938400003,"reason":"dcs"}
```

Sent when audio flow is no longer blocked.

Field:
- `reason`: the blocker that was just cleared (`dcs`, `ctcss`, or `silence`)

### Audio detected

```json
{"event":"audioDetected","pid":12345,"apiPort":4040,"timestamp":1710938400004}
```

Sent when a new gated audio clip starts.

### Silence detected

```json
{"event":"silenceDetected","pid":12345,"apiPort":4040,"timestamp":1710938401004}
```

Sent when silence duration threshold is reached and the active clip is closed.

### Hook executed

```json
{"event":"hookExecuted","pid":12345,"apiPort":4040,"timestamp":1710938401005,"command":"/path/to/hook /path/to/file.wav"}
```

Sent when an `--on-write` hook command is launched.

Fields:
- `event`: always `hookExecuted`
- `command`: full command line that is executed

### Saved recording

```json
{"event":"savedRecording","pid":12345,"apiPort":4040,"timestamp":1710938400123,"filename":"/home/user/recordings/2026-03-20/2026-03-20_120000_dev0.wav"}
```

Sent when a clip is successfully written to disk.

Fields:
- `event`: always `savedRecording`
- `filename`: full absolute path to the saved recording file

### Hook result

```json
{"event":"hookResult","pid":12345,"apiPort":4040,"timestamp":1710938401006,"result":"exitCode=0"}
```

Sent for hook output lines and final status.

Examples:
- `{"event":"hookResult","pid":12345,"apiPort":4040,"timestamp":1710938401006,"result":"processing file..."}`
- `{"event":"hookResult","pid":12345,"apiPort":4040,"timestamp":1710938401007,"result":"exitCode=0"}`
- `{"event":"hookResult","pid":12345,"apiPort":4040,"timestamp":1710938401008,"result":"error: ..."}`

### Status (every 250ms)

```json
{"event":"status","streamName":"dev0","source":"stdin:44100,s16le,2","recordingEnabled":true,"recordingsPath":"/home/user/recordings","pid":12345,"apiPort":4040,"timestamp":1710938400123,"dcsGateEnabled":false,"dcs":"073","dcsPolarity":"normal","dcsGate":"open","ctcssGateEnabled":true,"ctcss":"100.0","ctcssGate":"open","rmsGate":"closed","rmsDb":-15.3,"gate":"closed","gateReason":"silence","gateSeconds":1.002,"audioDetected":false,"audioDetectedSeconds":1.002,"audioReason":[]}
```

Sent every 250 milliseconds while the recorder is running.

Fields:
- `event`: always `status`
- `streamName`: stream title (from ICY headers or `--name` override)
- `source`: source URL or `stdin:RATE,ENCODING,CHANNELS` (e.g., `stdin:44100,s16le,2` or `http://icecast.example.com:8000/stream.mp3`)
- `recordingEnabled`: `true` when clip files are being written to a recordings directory, `false` in stdout-only mode without disk recording
- `recordingsPath`: absolute path to the recordings directory, or `null` if not recording to disk
- `pid`: process ID of the radio-pipe instance
- `apiPort`: WebSocket server port
- `dcsGateEnabled`: `true` when `--dcs` is configured
- `dcs`: currently detected DCS code, or `null` if none is currently detected. This can be populated even when the DCS gate is disabled.
- `dcsPolarity`: currently detected DCS polarity (`normal`, `inverted`, or `unknown`), or `null` if no DCS is currently detected
- `dcsGate`: `open` or `closed`
- `ctcssGateEnabled`: `true` when `--ctcss` is configured
- `ctcss`: currently detected CTCSS tone, or `null` if none is currently detected. This can be populated even when the CTCSS gate is disabled.
- `ctcssGate`: `open` or `closed`
- `rmsGate`: RMS/silence gate (`open` when audio is loud enough, `closed` when silent)
- `rmsDb`: current RMS level in decibels (dB)
- `gate`: overall gate state (open only if all gates open)
- `gateReason`: blocker for overall gate (`dcs`, `ctcss`, `silence`, or `none` when open)
- `gateSeconds`: seconds since the overall gate entered its current state
- `audioDetected`: `true` when current audio frames pass all gate conditions
- `audioDetectedSeconds`: seconds since `audioDetected` last changed
- `audioReason`: array of open-audio reasons. When the overall gate is open this contains `"dcs"`, `"ctcss"`, and `"rms"`; when the overall gate is closed this is an empty array

## Example sequence

Typical gated receive cycle (DCS gate configured):

```json
{"event":"dcsDetected","pid":12345,"apiPort":4040,"timestamp":1710938400001,"dcs":"073","polarity":"normal"}
{"event":"gateOpen","pid":12345,"apiPort":4040,"timestamp":1710938400003,"reason":"dcs"}
{"event":"audioDetected","pid":12345,"apiPort":4040,"timestamp":1710938400004}
{"event":"silenceDetected","pid":12345,"apiPort":4040,"timestamp":1710938401004}
{"event":"savedRecording","pid":12345,"apiPort":4040,"timestamp":1710938401005,"filename":"/home/user/recordings/2026-03-20/2026-03-20_120000_dev0.wav"}
{"event":"gateClosed","pid":12345,"apiPort":4040,"timestamp":1710938401006,"reason":"silence"}
{"event":"dcsGone","pid":12345,"apiPort":4040,"timestamp":1710938401007,"dcs":"073","polarity":"normal"}
{"event":"gateClosed","pid":12345,"apiPort":4040,"timestamp":1710938401008,"reason":"dcs"}
```

Notes:
- `gateClosed` can occur multiple times in one receive cycle if the blocking reason changes.
- `gateOpen` includes the previous blocker in `reason`.

## Ungated Detection Examples

Live detection continues to populate status packets even when DCS or CTCSS gating is not enabled.

Ungated DCS decode:

```json
{"event":"status","streamName":"dev0","source":"stdin:44100,s16le,2","recordingEnabled":true,"recordingsPath":"/home/user/recordings","pid":12345,"apiPort":4040,"timestamp":1710938400456,"dcsGateEnabled":false,"dcs":"073","dcsPolarity":"inverted","dcsGate":"open","ctcssGateEnabled":false,"ctcss":null,"ctcssGate":"open","rmsGate":"open","rmsDb":-14.8,"gate":"open","gateReason":"none","gateSeconds":0.250,"audioDetected":true,"audioDetectedSeconds":0.250,"audioReason":["dcs","ctcss","rms"]}
```

Ungated CTCSS decode:

```json
{"event":"status","streamName":"dev0","source":"http://icecast.example.com:8000/stream.mp3","recordingEnabled":true,"recordingsPath":"/home/user/recordings","pid":12345,"apiPort":4040,"timestamp":1710938400789,"dcsGateEnabled":false,"dcs":null,"dcsPolarity":null,"dcsGate":"open","ctcssGateEnabled":false,"ctcss":"100.0","ctcssGate":"open","rmsGate":"open","rmsDb":-17.1,"gate":"open","gateReason":"none","gateSeconds":0.500,"audioDetected":true,"audioDetectedSeconds":0.500,"audioReason":["dcs","ctcss","rms"]}
```

## Notes

- Multiple clients can connect and all receive the same event stream.
- The server accepts incoming messages but currently ignores them.
- If `--api-websocket` is not set, no WebSocket server is started and no API events are published.
