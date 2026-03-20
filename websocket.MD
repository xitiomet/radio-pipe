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

### DCS detected

```json
{"event":"dcs_detected","dcs":"073","polarity":"normal"}
```

Sent when a configured DCS gate opens.

Fields:
- `event`: always `dcs_detected`
- `dcs`: configured DCS code (octal, zero-padded)
- `polarity`: `normal`, `inverted`, or `unknown`

### DCS gone

```json
{"event":"dcs_gone","dcs":"073","polarity":"normal"}
```

Sent when a configured DCS gate closes.

### CTCSS detected

```json
{"event":"ctcss_detected","ctcss":"100.0"}
```

Sent when a configured CTCSS gate opens.

### CTCSS gone

```json
{"event":"ctcss_gone","ctcss":"100.0"}
```

Sent when a configured CTCSS gate closes.

### Gate closed

```json
{"event":"gate_closed","reason":"dcs"}
```

Sent when audio flow becomes blocked, or when the blocking reason changes.

Reason values:
- `dcs`
- `ctcss`
- `silence`

### Gate open

```json
{"event":"gate_open","reason":"dcs"}
```

Sent when audio flow is no longer blocked.

Field:
- `reason`: the blocker that was just cleared (`dcs`, `ctcss`, or `silence`)

### Audio detected

```json
{"event":"audio_detected"}
```

Sent when a new gated audio clip starts.

### Silence detected

```json
{"event":"silence_detected"}
```

Sent when silence duration threshold is reached and the active clip is closed.

### Hook executed

```json
{"event":"hook_executed","command":"/path/to/hook /path/to/file.wav"}
```

Sent when an `--on-write` hook command is launched.

Fields:
- `event`: always `hook_executed`
- `command`: full command line that is executed

### Hook result

```json
{"event":"hook_result","result":"exit_code=0"}
```

Sent for hook output lines and final status.

Examples:
- `{"event":"hook_result","result":"processing file..."}`
- `{"event":"hook_result","result":"exit_code=0"}`
- `{"event":"hook_result","result":"error: ..."}`

### Status (every 250ms)

```json
{"event":"status","stream_name":"dev0","source":"stdin:44100,s16le,2","recordingsPath":"/home/user/recordings","pid":12345,"api_port":4040,"dcs_gate_enabled":"false","dcs":"073","dcs_polarity":"normal","dcs_gate":"open","ctcss_gate_enabled":"true","ctcss":"100.0","ctcss_gate":"open","rms_gate":"closed","rms_db":-15.3,"gate":"closed","gate_reason":"silence","gate_seconds":1.002,"audio_detected":"false","audio_detected_seconds":1.002}
```

Sent every 250 milliseconds while the recorder is running.

Fields:
- `event`: always `status`
- `stream_name`: stream title (from ICY headers or `--name` override)
- `source`: source URL or `stdin:RATE,ENCODING,CHANNELS` (e.g., `stdin:44100,s16le,2` or `http://icecast.example.com:8000/stream.mp3`)
- `recordingsPath`: absolute path to the recordings directory, or `null` if not recording to disk
- `pid`: process ID of the radio-pipe instance
- `api_port`: WebSocket server port
- `dcs_gate_enabled`: `true` when `--dcs` is configured
- `dcs`: currently detected DCS code, or `null` if none is currently detected. This can be populated even when the DCS gate is disabled.
- `dcs_polarity`: currently detected DCS polarity (`normal`, `inverted`, or `unknown`), or `null` if no DCS is currently detected
- `dcs_gate`: `open` or `closed`
- `ctcss_gate_enabled`: `true` when `--ctcss` is configured
- `ctcss`: currently detected CTCSS tone, or `null` if none is currently detected. This can be populated even when the CTCSS gate is disabled.
- `ctcss_gate`: `open` or `closed`
- `rms_gate`: RMS/silence gate (`open` when audio is loud enough, `closed` when silent)
- `rms_db`: current RMS level in decibels (dB)
- `gate`: overall gate state (open only if all gates open)
- `gate_reason`: blocker for overall gate (`dcs`, `ctcss`, `silence`, or `none` when open)
- `gate_seconds`: seconds since the overall gate entered its current state
- `audio_detected`: `true` when current audio frames pass all gate conditions
- `audio_detected_seconds`: seconds since `audio_detected` last changed

## Example sequence

Typical gated receive cycle (DCS gate configured):

```json
{"event":"dcs_detected","dcs":"073","polarity":"normal"}
{"event":"gate_open","reason":"dcs"}
{"event":"audio_detected"}
{"event":"silence_detected"}
{"event":"gate_closed","reason":"silence"}
{"event":"dcs_gone","dcs":"073","polarity":"normal"}
{"event":"gate_closed","reason":"dcs"}
```

Notes:
- `gate_closed` can occur multiple times in one receive cycle if the blocking reason changes.
- `gate_open` includes the previous blocker in `reason`.

## Ungated Detection Examples

Live detection continues to populate status packets even when DCS or CTCSS gating is not enabled.

Ungated DCS decode:

```json
{"event":"status","stream_name":"dev0","source":"stdin:44100,s16le,2","recordingsPath":"/home/user/recordings","pid":12345,"api_port":4040,"dcs_gate_enabled":"false","dcs":"073","dcs_polarity":"inverted","dcs_gate":"open","ctcss_gate_enabled":"false","ctcss":null,"ctcss_gate":"open","rms_gate":"open","rms_db":-14.8,"gate":"open","gate_reason":"none","gate_seconds":0.250,"audio_detected":"true","audio_detected_seconds":0.250}
```

Ungated CTCSS decode:

```json
{"event":"status","stream_name":"dev0","source":"http://icecast.example.com:8000/stream.mp3","recordingsPath":"/home/user/recordings","pid":12345,"api_port":4040,"dcs_gate_enabled":"false","dcs":null,"dcs_polarity":null,"dcs_gate":"open","ctcss_gate_enabled":"false","ctcss":"100.0","ctcss_gate":"open","rms_gate":"open","rms_db":-17.1,"gate":"open","gate_reason":"none","gate_seconds":0.500,"audio_detected":"true","audio_detected_seconds":0.500}
```

## Notes

- Multiple clients can connect and all receive the same event stream.
- The server accepts incoming messages but currently ignores them.
- If `--api-websocket` is not set, no WebSocket server is started and no API events are published.
