<?php

declare(strict_types=1);

$RTL_PAGE_TITLE = 'RTL-SDR Controller';
$STATE_FILE = __DIR__ . '/rtl_sdr_state.json';
$STREAMING_SERVERS_FILE = __DIR__ . '/streaming_servers.json';
$UI_SETTINGS_FILE = __DIR__ . '/rtl_sdr_ui_settings.json';
$LOG_DIR = __DIR__ . '/rtl_sdr_logs';
$recordingsRoot = __DIR__ . '/recordings';

if (file_exists(__DIR__ . '/config.php')) {
	include __DIR__ . '/config.php';
}

function send_json(array $payload, int $statusCode = 200): void
{
	http_response_code($statusCode);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-cache, no-store, must-revalidate');
	$jsonOptions = 0;
	if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
		$jsonOptions |= JSON_INVALID_UTF8_SUBSTITUTE;
	}

	$encodedPayload = json_encode($payload, $jsonOptions);
	if (!is_string($encodedPayload)) {
		http_response_code(500);
		$encodedPayload = '{"ok":false,"error":"Failed to encode JSON response."}';
	}

	echo $encodedPayload;
	exit;
}

function load_state(string $stateFile): array
{
	if (!file_exists($stateFile)) {
		return array();
	}

	$raw = file_get_contents($stateFile);
	if (!is_string($raw) || trim($raw) === '') {
		return array();
	}

	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		return array();
	}

	return $decoded;
}

function save_state(string $stateFile, array $state): bool
{
	$encoded = json_encode($state, JSON_PRETTY_PRINT);
	if (!is_string($encoded)) {
		return false;
	}

	return file_put_contents($stateFile, $encoded . "\n", LOCK_EX) !== false;
}

function normalize_streaming_servers(array $rawServers): array
{
	$normalized = array();
	foreach ($rawServers as $serverId => $server) {
		if (!is_array($server)) {
			continue;
		}
		$id = trim((string)$serverId);
		if ($id === '') {
			continue;
		}

		$name = trim((string)($server['name'] ?? ''));
		$target = trim((string)($server['target'] ?? ''));
		$username = trim((string)($server['username'] ?? ''));
		$password = (string)($server['password'] ?? '');

		if ($name === '' || $target === '') {
			continue;
		}

		$normalized[$id] = array(
			'name' => $name,
			'target' => $target,
			'username' => $username,
			'password' => $password,
		);
	}

	return $normalized;
}

function load_streaming_servers(string $filePath): array
{
	if (!file_exists($filePath)) {
		file_put_contents($filePath, "{}\n", LOCK_EX);
		return array();
	}

	$raw = file_get_contents($filePath);
	if (!is_string($raw) || trim($raw) === '') {
		return array();
	}

	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		return array();
	}

	return normalize_streaming_servers($decoded);
}

function save_streaming_servers(string $filePath, array $servers): bool
{
	$normalized = normalize_streaming_servers($servers);
	$encoded = json_encode($normalized, JSON_PRETTY_PRINT);
	if (!is_string($encoded)) {
		return false;
	}

	return file_put_contents($filePath, $encoded . "\n", LOCK_EX) !== false;
}

function default_ui_settings(): array
{
	return array(
		'deviceConfigs' => array(),
		'templates' => array(),
	);
}

function normalize_ui_settings(array $rawSettings): array
{
	$defaults = default_ui_settings();
	$normalized = $defaults;

	$rawDeviceConfigs = isset($rawSettings['deviceConfigs']) && is_array($rawSettings['deviceConfigs'])
		? $rawSettings['deviceConfigs']
		: array();
	$deviceConfigs = array();
	foreach ($rawDeviceConfigs as $deviceId => $config) {
		$normalizedDeviceId = normalize_device_id($deviceId);
		if ($normalizedDeviceId === '' || !is_array($config)) {
			continue;
		}
		$config['device'] = $normalizedDeviceId;
		$deviceConfigs[$normalizedDeviceId] = $config;
	}
	$normalized['deviceConfigs'] = $deviceConfigs;

	$rawTemplates = isset($rawSettings['templates']) && is_array($rawSettings['templates'])
		? $rawSettings['templates']
		: array();
	$templates = array();
	foreach ($rawTemplates as $templateName => $templateConfig) {
		$name = trim((string)$templateName);
		if ($name === '' || !is_array($templateConfig)) {
			continue;
		}
		$templateConfig['templateName'] = $name;
		$templates[$name] = $templateConfig;
	}
	$normalized['templates'] = $templates;

	return $normalized;
}

function load_ui_settings(string $filePath): array
{
	if (!file_exists($filePath)) {
		$defaults = default_ui_settings();
		save_ui_settings($filePath, $defaults);
		return $defaults;
	}

	$raw = file_get_contents($filePath);
	if (!is_string($raw) || trim($raw) === '') {
		return default_ui_settings();
	}

	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		return default_ui_settings();
	}

	return normalize_ui_settings($decoded);
}

function save_ui_settings(string $filePath, array $settings): bool
{
	$normalized = normalize_ui_settings($settings);
	$toPersist = $normalized;
	$toPersist['deviceConfigs'] = (object)$normalized['deviceConfigs'];
	$toPersist['templates'] = (object)$normalized['templates'];
	$encoded = json_encode($toPersist, JSON_PRETTY_PRINT);
	if (!is_string($encoded)) {
		return false;
	}

	return file_put_contents($filePath, $encoded . "\n", LOCK_EX) !== false;
}

function ui_settings_for_response(array $settings): array
{
	$response = $settings;
	$response['deviceConfigs'] = (object)($settings['deviceConfigs'] ?? array());
	$response['templates'] = (object)($settings['templates'] ?? array());
	return $response;
}

if (!file_exists($STREAMING_SERVERS_FILE)) {
	file_put_contents($STREAMING_SERVERS_FILE, "{}\n", LOCK_EX);
}

function parse_json_request_body(): array
{
	$rawInput = file_get_contents('php://input');
	if (!is_string($rawInput) || trim($rawInput) === '') {
		return array();
	}

	$decodedInput = json_decode($rawInput, true);
	if (!is_array($decodedInput)) {
		return array();
	}

	return $decodedInput;
}

function is_process_running(int $pid): bool
{
	if ($pid <= 0) {
		return false;
	}

	$checkCommand = 'kill -0 ' . $pid . ' >/dev/null 2>&1; echo $?';
	$result = shell_exec('bash -lc ' . escapeshellarg($checkCommand));
	return trim((string)$result) === '0';
}

function cleanup_stale_instances(array &$state): void
{
	foreach ($state as $device => $instance) {
		$pid = isset($instance['pid']) ? (int)$instance['pid'] : 0;
		if (!is_process_running($pid)) {
			unset($state[$device]);
		}
	}
}

function normalize_device_id($rawValue): string
{
	$deviceId = trim((string)$rawValue);
	if (!preg_match('/^[0-9]+$/', $deviceId)) {
		return '';
	}

	$normalized = ltrim($deviceId, '0');
	return $normalized === '' ? '0' : $normalized;
}

function command_exists(string $command): bool
{
	$lookup = shell_exec('bash -lc ' . escapeshellarg('command -v ' . escapeshellarg($command) . ' 2>/dev/null'));
	return trim((string)$lookup) !== '';
}

function wrap_for_device_access(string $command): string
{
	if (command_exists('sg')) {
		return 'sg plugdev -c ' . escapeshellarg($command);
	}

	return $command;
}

function command_from_parts(array $parts): string
{
	// Build command for sh -c: each part needs to be quoted if it contains special chars
	// Use a simple approach: quote parts with spaces/special chars using double quotes
	$escaped = array();
	foreach ($parts as $part) {
		$part = (string)$part;
		// If contains spaces or shell special chars, wrap in double quotes and escape backslashes/dollars/quotes
		if (preg_match('/[ \t\n\r"\'$&|;()<>\\\\`]/', $part)) {
			$escaped[] = '"' . str_replace(array('\\', '"', '$', '`'),array('\\\\', '\\"', '\\$', '\\`'), $part) . '"';
		} else {
			// Safe to use unquoted
			$escaped[] = $part;
		}
	}
	return implode(' ', $escaped);
}

function sanitize_device_label(string $label, string $fallback): string
{
	$clean = trim($label);
	if (function_exists('iconv')) {
		$converted = @iconv('UTF-8', 'UTF-8//IGNORE', $clean);
		if (is_string($converted)) {
			$clean = $converted;
		}
	}

	$clean = preg_replace('/[[:cntrl:]]+/', ' ', $clean);
	if (!is_string($clean)) {
		$clean = '';
	}

	$clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
	if ($clean === '' || preg_match('/^[\x{fffd}\?\s,.-]+$/u', $clean)) {
		return $fallback;
	}

	return $clean;
}

function read_log_excerpt(string $logPath, int $maxLines = 12): string
{
	if (!file_exists($logPath)) {
		return '';
	}

	$contents = file_get_contents($logPath);
	if (!is_string($contents) || $contents === '') {
		return '';
	}

	$lines = preg_split('/\r\n|\r|\n/', trim($contents));
	if (!is_array($lines) || count($lines) === 0) {
		return '';
	}

	$excerpt = array_slice($lines, -1 * $maxLines);
	return trim(implode("\n", $excerpt));
}

function strip_ansi_sequences(string $text): string
{
	$text = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $text) ?? $text;
	$text = preg_replace('/\x1B[@-_][0-?]*[ -\/]*[@-~]/', '', $text) ?? $text;
	return $text;
}

function mask_sensitive_command_for_log(string $command): string
{
	$masked = preg_replace("/(icecast:\/\/[^:\\s\"'\/]+:)[^@\\s\"'\/]+@/", '$1***@', $command);
	return is_string($masked) ? $masked : $command;
}

function resolve_log_path_for_device(string $logDir, string $deviceId, array $state): string
{
	if (isset($state[$deviceId]['logFile'])) {
		$logFile = basename((string)$state[$deviceId]['logFile']);
		$logPath = $logDir . '/' . $logFile;
		if (file_exists($logPath)) {
			return $logPath;
		}
	}

	$matches = glob($logDir . '/rtl_sdr_device_' . $deviceId . '_*.log');
	if (!is_array($matches) || count($matches) === 0) {
		return '';
	}

	rsort($matches, SORT_STRING);
	return (string)$matches[0];
}

function read_log_lines(string $logPath, int $maxLines): array
{
	if ($logPath === '' || !file_exists($logPath)) {
		return array();
	}

	$contents = file_get_contents($logPath);
	if (!is_string($contents) || $contents === '') {
		return array();
	}

	$clean = strip_ansi_sequences($contents);
	$clean = preg_replace('/[[:cntrl:]&&[^\r\n\t]]+/', '', $clean) ?? $clean;
	$lines = preg_split('/\r\n|\r|\n/', trim($clean));
	if (!is_array($lines)) {
		return array();
	}

	$filtered = array_values(array_filter($lines, static function ($line): bool {
		return trim((string)$line) !== '';
	}));

	$entries = array();
	$current = array();
	$timestampPattern = '/^\[[0-9]{4}-[0-9]{2}-[0-9]{2}\s+[0-9]{2}:[0-9]{2}:[0-9]{2}\]/';

	foreach ($filtered as $line) {
		$text = (string)$line;
		$isTimestamped = preg_match($timestampPattern, $text) === 1;

		if ($isTimestamped) {
			if (count($current) > 0) {
				$entries[] = implode("\n", $current);
			}
			$current = array($text);
			continue;
		}

		if (count($current) === 0) {
			$current = array($text);
		} else {
			$current[] = $text;
		}
	}

	if (count($current) > 0) {
		$entries[] = implode("\n", $current);
	}

	$tail = array_slice($entries, -1 * $maxLines);
	return array_reverse($tail);
}

function force_release_device(string $deviceId): void
{
	$pattern = 'rtl_fm .* -d ' . preg_quote($deviceId, '/') . '([[:space:]]|$)';
	$termCommand = 'pkill -TERM -f ' . escapeshellarg($pattern) . ' >/dev/null 2>&1 || true';
	$killCommand = 'pkill -KILL -f ' . escapeshellarg($pattern) . ' >/dev/null 2>&1 || true';

	shell_exec('bash -lc ' . escapeshellarg($termCommand));
	usleep(500000);
	shell_exec('bash -lc ' . escapeshellarg($killCommand));
	usleep(1500000);
}

function launch_pipeline_process(string $pipelineCommand, string $logPath): int
{
	$deviceCommand = wrap_for_device_access('nohup setsid sh -c ' . escapeshellarg($pipelineCommand) . ' >> ' . escapeshellarg($logPath) . ' 2>&1 < /dev/null & echo $!');
	$wrappedCommand = $deviceCommand;
	$pidOutput = shell_exec('bash -lc ' . escapeshellarg($wrappedCommand));
	return (int)trim((string)$pidOutput);
}

function normalize_config(array $input, string $defaultOutputDir): array
{
	$deviceId = normalize_device_id($input['device'] ?? '');
	if ($deviceId === '') {
		throw new RuntimeException('Device index must be a non-negative integer.');
	}

	$frequency = trim((string)($input['frequency'] ?? ''));
	if (!preg_match('/^[0-9]+(?:\.[0-9]+)?(?:[kKmMgG])?$/', $frequency)) {
		throw new RuntimeException('Frequency format is invalid. Example: 146.520M');
	}

	$mode = strtolower(trim((string)($input['mode'] ?? 'fm')));
	$allowedModes = array('fm', 'wbfm', 'am', 'usb', 'lsb', 'raw');
	if (!in_array($mode, $allowedModes, true)) {
		throw new RuntimeException('Mode must be one of: ' . implode(', ', $allowedModes));
	}

	$rtlBandwidth = (int)($input['rtlBandwidth'] ?? 12000);
	if ($rtlBandwidth <= 0 || $rtlBandwidth > 384000) {
		throw new RuntimeException('RTL bandwidth must be between 1 and 384000.');
	}

	$sampleRate = $rtlBandwidth;

	$squelch = trim((string)($input['squelch'] ?? '500'));
	if (!preg_match('/^-?[0-9]+$/', $squelch)) {
		throw new RuntimeException('Squelch must be an integer.');
	}
	if ((int)$squelch === 0) {
		throw new RuntimeException('Squelch must be a non-zero integer.');
	}

	$gain = trim((string)($input['gain'] ?? ''));
	if ($gain !== '' && !preg_match('/^(auto|-?[0-9]+(?:\.[0-9]+)?)$/i', $gain)) {
		throw new RuntimeException('Gain must be numeric or "auto".');
	}

	$threshold = trim((string)($input['threshold'] ?? '-40'));
	$thresholdValue = -40.0;
	if ($threshold !== '') {
		if (!preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $threshold)) {
			throw new RuntimeException('Threshold must be numeric.');
		}
		$thresholdValue = (float)$threshold;
	}
	if ($thresholdValue > 0 || $thresholdValue < -120) {
		throw new RuntimeException('Threshold must be between -120 and 0 dB.');
	}

	$silence = trim((string)($input['silence'] ?? '2'));
	$silenceValue = 2.0;
	if ($silence !== '') {
		if (!preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $silence)) {
			throw new RuntimeException('Silence duration must be numeric and >= 0.');
		}
		$silenceValue = (float)$silence;
	}

	$outputMode = strtolower(trim((string)($input['outputMode'] ?? 'recorder')));
	$allowedOutputModes = array('recorder', 'stream');
	if (!in_array($outputMode, $allowedOutputModes, true)) {
		throw new RuntimeException('Output mode must be one of: ' . implode(', ', $allowedOutputModes));
	}

	$streamFormat = strtolower(trim((string)($input['streamFormat'] ?? 'mp3')));
	$allowedStreamFormats = array('mp3', 'ogg');
	if (!in_array($streamFormat, $allowedStreamFormats, true)) {
		throw new RuntimeException('Stream format must be one of: ' . implode(', ', $allowedStreamFormats));
	}

	$streamBitrate = trim((string)($input['streamBitrate'] ?? '128'));
	if (!preg_match('/^[0-9]{2,4}$/', $streamBitrate)) {
		throw new RuntimeException('Stream bitrate must be an integer in kbps.');
	}
	$streamBitrateValue = (int)$streamBitrate;
	if ($streamBitrateValue < 16 || $streamBitrateValue > 320) {
		throw new RuntimeException('Stream bitrate must be between 16 and 320 kbps.');
	}

	$streamTarget = trim((string)($input['streamTarget'] ?? ''));
	$streamMount = trim((string)($input['streamMount'] ?? ''));
	$streamUsername = trim((string)($input['streamUsername'] ?? ''));
	$streamPassword = trim((string)($input['streamPassword'] ?? ''));

	$outputDir = trim((string)($input['outputDir'] ?? ''));
	if ($outputDir === '') {
		$outputDir = $defaultOutputDir;
	}

	$streamName = trim((string)($input['streamName'] ?? ''));
	if ($streamName === '') {
		$streamName = 'RTLSDR Device ' . $deviceId . ' (' . strtoupper($mode) . ' ' . $frequency . ')';
	}

	if ($outputMode === 'stream') {
		if ($streamTarget === '') {
			throw new RuntimeException('Target server:port is required in Stream output mode.');
		}
		if (!preg_match('/^[a-zA-Z0-9._-]+:[0-9]{1,5}$/', $streamTarget)) {
			throw new RuntimeException('Target server:port format is invalid. Example: 127.0.0.1:8000');
		}
		$targetParts = explode(':', $streamTarget);
		$targetPort = isset($targetParts[1]) ? (int)$targetParts[1] : 0;
		if ($targetPort < 1 || $targetPort > 65535) {
			throw new RuntimeException('Target server port must be between 1 and 65535.');
		}

		if ($streamMount === '') {
			$streamMount = '/rtl-sdr.' . $streamFormat;
		}
		if (strpos($streamMount, '/') !== 0) {
			$streamMount = '/' . $streamMount;
		}
		if (!preg_match('/^\/[a-zA-Z0-9._~\/-]+$/', $streamMount)) {
			throw new RuntimeException('Mount point contains invalid characters.');
		}

		if (($streamUsername === '' && $streamPassword !== '') || ($streamUsername !== '' && $streamPassword === '')) {
			throw new RuntimeException('Provide both stream username and password, or leave both empty.');
		}
		if ($streamUsername !== '' && !preg_match('/^[^\s:@\/]+$/', $streamUsername)) {
			throw new RuntimeException('Stream username contains invalid characters.');
		}
	}

	$postCommand = trim((string)($input['postCommand'] ?? ''));

	return array(
		'device' => $deviceId,
		'frequency' => $frequency,
		'mode' => $mode,
		'rtlBandwidth' => $rtlBandwidth,
		'sampleRate' => $sampleRate,
		'squelch' => (int)$squelch,
		'gain' => $gain,
		'threshold' => $thresholdValue,
		'silence' => $silenceValue,
		'outputMode' => $outputMode,
		'streamFormat' => $streamFormat,
		'streamBitrate' => $streamBitrateValue,
		'streamTarget' => $streamTarget,
		'streamMount' => $streamMount,
		'streamUsername' => $streamUsername,
		'streamPassword' => $streamPassword,
		'outputDir' => $outputDir,
		'streamName' => $streamName,
		'postCommand' => $postCommand,
	);
}

function build_silence_padder_command(int $sampleRate): string
{
	// Outputs silence (zero bytes) at the correct byte rate when the upstream pipe
	// stops writing (e.g. rtl_fm squelch mutes). This runs as a fixed-frame clock
	// with a tiny jitter buffer so downstream audio cadence stays smooth, and emits
	// [RX] ACTIVE / [RX] IDLE markers to stderr for UI state detection.
	$frameSeconds = 0.01; // 10 ms frame
	$chunkBytes = max(1, (int)round($sampleRate * 2 * $frameSeconds));
	$lines = array(
		'import sys,time,os,fcntl,array,math',
		'cb=' . $chunkBytes,
		'fs=' . sprintf('%.3f', $frameSeconds),
		's=b"\x00"*cb',
		'buf=bytearray()',
		'rx=0',
		'low=0',
		'high=0',
		'idlef=40',
		'activef=4',
		'nf=120.0',
		'min_on=650.0',
		'min_off=280.0',
		'i=sys.stdin.fileno()',
		'ofd=sys.stdout.fileno()',
		'fl=fcntl.fcntl(i,fcntl.F_GETFL)',
		'fcntl.fcntl(i,fcntl.F_SETFL,fl|os.O_NONBLOCK)',
		'while True:',
		' t=time.monotonic()+fs',
		' while True:',
		'  try:',
		'   d=os.read(i,cb*8)',
		'   if not d:break',
		'   buf.extend(d)',
		'  except BlockingIOError:',
		'   break',
		' if len(buf)>=cb:',
		'  out=bytes(buf[:cb])',
		'  del buf[:cb]',
		' else:',
		'  out=bytes(buf)+s[len(buf):]',
		'  buf.clear()',
		' a=array.array("h")',
		' a.frombytes(out)',
		' if not a:',
		'  rms=0.0',
		' else:',
		'  ss=sum((x*x) for x in a)',
		'  rms=math.sqrt(ss/len(a))',
		' if rx==0:',
		'  nf=(nf*0.985)+(rms*0.015)',
		'  on=max(min_on,nf*3.2)',
		'  if rms>=on:',
		'   high+=1',
		'   if high>=activef:',
		'    rx=1',
		'    high=0',
		'    low=0',
		'    os.write(2,b"[RX] ACTIVE\\n")',
		'  else:',
		'   high=0',
		' else:',
		'  off=max(min_off,nf*1.8)',
		'  if rms<=off:',
		'   low+=1',
		'   if low>=idlef:',
		'    rx=0',
		'    low=0',
		'    high=0',
		'    os.write(2,b"[RX] IDLE\\n")',
		'  else:',
		'   low=0',
		' os.write(ofd,out)',
		' r=t-time.monotonic()',
		' if r>0:time.sleep(r)',
	);
	return 'python3 -c ' . escapeshellarg(implode("\n", $lines));
}

function build_stream_command(array $config): string
{
	$streamFormat = strtolower((string)$config['streamFormat']) === 'ogg' ? 'ogg' : 'mp3';
	$codec = $streamFormat === 'ogg' ? 'libvorbis' : 'libmp3lame';
	$contentType = $streamFormat === 'ogg' ? 'audio/ogg' : 'audio/mpeg';
	$mount = ltrim((string)$config['streamMount'], '/');
	$target = (string)$config['streamTarget'];
	$streamUsername = (string)($config['streamUsername'] ?? '');
	$streamPassword = (string)($config['streamPassword'] ?? '');
	$authPrefix = '';
	if ($streamUsername !== '' && $streamPassword !== '') {
		$authPrefix = rawurlencode($streamUsername) . ':' . rawurlencode($streamPassword) . '@';
	}
	$description = (string)$config['frequency'] . ' BW ' . (string)$config['rtlBandwidth'] . ' ' . strtoupper((string)$config['mode']);
	$streamBitrate = max(16, min(320, (int)$config['streamBitrate']));
	$vorbisQuality = max(0, min(10, (int)round(($streamBitrate - 32) / 32)));

	$ffmpegCommand = array(
		'ffmpeg',
		'-hide_banner',
		'-loglevel',
		'warning',
		'-nostats',
		'-f',
		's16le',
		'-ar',
		(string)$config['rtlBandwidth'],
		'-ac',
		'1',
		'-i',
		'pipe:0',
		'-vn',
		'-c:a',
		$codec,
		'-content_type',
		$contentType,
		'-ice_name',
		(string)$config['streamName'] . ' - ' . $description,
		'-ice_description',
		$description,
		'-f',
		$streamFormat,
		'icecast://' . $authPrefix . $target . '/' . $mount,
	);

	if ($streamFormat === 'ogg') {
		array_splice($ffmpegCommand, 14, 0, array(
			'-q:a',
			(string)$vorbisQuality,
		));
	} else {
		array_splice($ffmpegCommand, 14, 0, array(
			'-b:a',
			(string)$streamBitrate . 'k',
		));
	}

	return command_from_parts($ffmpegCommand);
}

function build_pipeline_command(array $config): string
{
	$rtlCommand = array(
		'rtl_fm',
		'-f',
		$config['frequency'],
		'-M',
		$config['mode'],
		'-s',
		(string)$config['rtlBandwidth'],
		'-r',
		(string)$config['rtlBandwidth'],
		'-E',
		'dc',
		'-E',
		'deemp',
		'-l',
		(string)$config['squelch'],
		'-d',
		(string)$config['device'],
	);

	if ($config['gain'] !== '' && strtolower((string)$config['gain']) !== 'auto') {
		$rtlCommand[] = '-g';
		$rtlCommand[] = (string)$config['gain'];
	}

	$pipeline = command_from_parts($rtlCommand);
	$pipeline .= ' | ' . build_silence_padder_command((int)$config['rtlBandwidth']);

	if ((string)$config['outputMode'] === 'stream') {
		$pipeline .= ' | ' . build_stream_command($config);
		return $pipeline;
	}

	$recorderCommand = array(
		'rms-cast-recorder',
		'--stdin',
		'--stdin-raw',
		'--stdin-rate',
		(string)$config['rtlBandwidth'],
		'--stdin-channels',
		'1',
		'--stdin-bits',
		'16',
		'-r',
		(string)$config['rtlBandwidth'],
		'-t',
		(string)$config['threshold'],
		'-s',
		(string)$config['silence'],
		'-o',
		(string)$config['outputDir'],
		'-n',
		(string)$config['streamName'],
	);

	if ($config['postCommand'] !== '') {
		$recorderCommand[] = '-X';
		$recorderCommand[] = (string)$config['postCommand'];
	}

	$pipeline .= ' | ' . command_from_parts($recorderCommand);
	return $pipeline;
}

function stop_instance_by_pid(int $pid): void
{
	if ($pid <= 0) {
		return;
	}

	$termCommand = 'kill -TERM -- -' . $pid . ' >/dev/null 2>&1 || kill -TERM ' . $pid . ' >/dev/null 2>&1';
	shell_exec('bash -lc ' . escapeshellarg($termCommand));
	usleep(250000);

	if (is_process_running($pid)) {
		$killCommand = 'kill -KILL -- -' . $pid . ' >/dev/null 2>&1 || kill -KILL ' . $pid . ' >/dev/null 2>&1';
		shell_exec('bash -lc ' . escapeshellarg($killCommand));
		usleep(500000);
	}
	
	// Give hardware time to fully release (rtl_fm and USB device)
	// rtl_fm can take 1-2 seconds to fully release the device
	usleep(1500000);
}

function start_instance(array $config, string $logDir): array
{
	if (!command_exists('python3')) {
		return array('ok' => false, 'error' => 'python3 is required for silence padding but was not found in PATH. Install python3 to use this pipeline.');
	}

	if ((string)$config['outputMode'] === 'stream') {
		if (!command_exists('ffmpeg')) {
			return array('ok' => false, 'error' => 'ffmpeg is required for Stream output mode but was not found in PATH.');
		}
	} elseif (!command_exists('rms-cast-recorder')) {
		return array('ok' => false, 'error' => 'rms-cast-recorder is required for Recorder output mode but was not found in PATH.');
	}

	if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
		return array('ok' => false, 'error' => 'Failed to create log directory: ' . $logDir);
	}

	if ((string)$config['outputMode'] === 'recorder' && !is_dir((string)$config['outputDir']) && !mkdir((string)$config['outputDir'], 0775, true) && !is_dir((string)$config['outputDir'])) {
		return array('ok' => false, 'error' => 'Failed to create output directory: ' . $config['outputDir']);
	}

	$pipelineCommand = build_pipeline_command($config);
	$pipelineCommandForLog = mask_sensitive_command_for_log($pipelineCommand);
	$logFileName = 'rtl_sdr_device_' . $config['device'] . '_' . date('Ymd_His') . '.log';
	$logPath = $logDir . '/' . $logFileName;
	$launchEntry = '[' . date('Y-m-d H:i:s') . '] [LAUNCH] ' . $pipelineCommandForLog . "\n";
	file_put_contents($logPath, $launchEntry, FILE_APPEND | LOCK_EX);
	$attemptDelays = array(900000, 1800000, 3000000);
	$lastError = 'Failed to launch pipeline process.';
	$pid = 0;

	for ($attempt = 0; $attempt < count($attemptDelays); $attempt++) {
		force_release_device((string)$config['device']);
		$pid = launch_pipeline_process($pipelineCommand, $logPath);

		if ($pid <= 0) {
			$lastError = 'Failed to launch pipeline process.';
			usleep($attemptDelays[$attempt]);
			continue;
		}

		usleep(900000);
		if (is_process_running($pid)) {
			return array('ok' => true, 'pid' => $pid, 'logFile' => $logFileName, 'command' => $pipelineCommandForLog);
		}

		$excerpt = read_log_excerpt($logPath);
		$lastError = 'Pipeline exited immediately. Check log: ' . $logFileName;
		if ($excerpt !== '') {
			$lastError .= ' Last lines: ' . preg_replace('/\s+/', ' ', $excerpt);
		}

		usleep($attemptDelays[$attempt]);
	}

	return array('ok' => false, 'error' => $lastError);
}

function list_instances(array $state): array
{
	$instances = array();
	foreach ($state as $device => $instance) {
		$pid = isset($instance['pid']) ? (int)$instance['pid'] : 0;
		$config = isset($instance['config']) && is_array($instance['config']) ? $instance['config'] : array();
		$instances[] = array(
			'device' => (string)$device,
			'pid' => $pid,
			'running' => is_process_running($pid),
			'startedAt' => isset($instance['startedAt']) ? (int)$instance['startedAt'] : 0,
			'logFile' => isset($instance['logFile']) ? (string)$instance['logFile'] : '',
			'command' => isset($instance['command']) ? (string)$instance['command'] : '',
			'config' => $config,
		);
	}

	usort($instances, static function (array $a, array $b): int {
		return strcmp((string)$a['device'], (string)$b['device']);
	});

	return $instances;
}

function discover_rtl_devices(): array
{
	$devices = array();
	$warning = '';

	if (!command_exists('rtl_test')) {
		$warning = 'rtl_test was not found in PATH, so hardware auto-discovery is unavailable.';
		return array('devices' => $devices, 'warning' => $warning);
	}

	$scanCommand = 'LC_ALL=C timeout 8 rtl_test -t 2>&1';
	$scanOutput = shell_exec('bash -lc ' . escapeshellarg(wrap_for_device_access($scanCommand)));
	if (!is_string($scanOutput) || trim($scanOutput) === '') {
		$warning = 'rtl_test returned no output.';
		return array('devices' => $devices, 'warning' => $warning);
	}

	$lines = preg_split('/\r\n|\r|\n/', $scanOutput);
	if (!is_array($lines)) {
		$lines = array();
	}

	$expectedCount = null;
	foreach ($lines as $line) {
		$trimmed = trim((string)$line);
		if ($trimmed === '') {
			if (count($devices) > 0) {
				break;
			}
			continue;
		}

		if (preg_match('/^Found\s+([0-9]+)\s+device\(s\):/i', $trimmed, $matches)) {
			$expectedCount = (int)$matches[1];
			continue;
		}

		if ($expectedCount !== null && preg_match('/^Using device\s+/i', $trimmed)) {
			break;
		}

		if (preg_match('/^([0-9]+):\s*(.+)$/', $trimmed, $matches)) {
			$index = (string)((int)$matches[1]);
			$devices[] = array(
				'index' => $index,
				'label' => sanitize_device_label(trim((string)$matches[2]), 'RTL-SDR Device ' . $index),
			);
		}
	}

	if (count($devices) === 0 && $expectedCount !== null && $expectedCount > 0) {
		for ($index = 0; $index < $expectedCount; $index++) {
			$devices[] = array(
				'index' => (string)$index,
				'label' => 'RTL-SDR Device ' . $index,
			);
		}
	}

	if (count($devices) === 0 && stripos($scanOutput, 'No supported devices found') !== false) {
		$warning = 'No supported RTL-SDR devices were found.';
	}

	return array('devices' => $devices, 'warning' => $warning);
}

$jsonPayload = parse_json_request_body();
$action = '';
if (isset($_REQUEST['action'])) {
	$action = trim((string)$_REQUEST['action']);
} elseif (isset($jsonPayload['action'])) {
	$action = trim((string)$jsonPayload['action']);
}

if ($action !== '') {
	$state = load_state($STATE_FILE);
	cleanup_stale_instances($state);

	if ($action === 'stream_servers_get') {
		$servers = load_streaming_servers($STREAMING_SERVERS_FILE);
		send_json(array('ok' => true, 'servers' => $servers));
	}

	if ($action === 'stream_servers_set') {
		$payload = $_POST;
		if (is_array($jsonPayload) && count($jsonPayload) > 0) {
			$payload = array_merge($payload, $jsonPayload);
		}

		$rawServers = isset($payload['servers']) && is_array($payload['servers']) ? $payload['servers'] : array();
		$servers = normalize_streaming_servers($rawServers);
		if (!save_streaming_servers($STREAMING_SERVERS_FILE, $servers)) {
			send_json(array('ok' => false, 'error' => 'Failed to save streaming servers.'), 500);
		}

		send_json(array('ok' => true, 'servers' => $servers));
	}

	if ($action === 'settings_get') {
		$settings = load_ui_settings($UI_SETTINGS_FILE);
		send_json(array('ok' => true, 'settings' => ui_settings_for_response($settings)));
	}

	if ($action === 'settings_set') {
		$payload = $_POST;
		if (is_array($jsonPayload) && count($jsonPayload) > 0) {
			$payload = array_merge($payload, $jsonPayload);
		}

		$incoming = isset($payload['settings']) && is_array($payload['settings']) ? $payload['settings'] : array();
		$settings = normalize_ui_settings($incoming);
		if (!save_ui_settings($UI_SETTINGS_FILE, $settings)) {
			send_json(array('ok' => false, 'error' => 'Failed to save UI settings.'), 500);
		}

		send_json(array('ok' => true, 'settings' => ui_settings_for_response($settings)));
	}

	if ($action === 'list') {
		save_state($STATE_FILE, $state);
		send_json(array('ok' => true, 'instances' => list_instances($state)));
	}

	if ($action === 'stop') {
		$deviceId = normalize_device_id($_POST['device'] ?? $_GET['device'] ?? ($jsonPayload['device'] ?? ''));
		if ($deviceId === '') {
			send_json(array('ok' => false, 'error' => 'Device is required.'), 400);
		}

		if (!isset($state[$deviceId])) {
			send_json(array('ok' => true, 'message' => 'Device was not running.', 'instances' => list_instances($state)));
		}

		$pid = isset($state[$deviceId]['pid']) ? (int)$state[$deviceId]['pid'] : 0;
		stop_instance_by_pid($pid);
		unset($state[$deviceId]);
		save_state($STATE_FILE, $state);
		send_json(array('ok' => true, 'message' => 'Stopped device ' . $deviceId . '.', 'instances' => list_instances($state)));
	}

	if ($action === 'stop_all') {
		foreach ($state as $instance) {
			$pid = isset($instance['pid']) ? (int)$instance['pid'] : 0;
			stop_instance_by_pid($pid);
		}

		$state = array();
		save_state($STATE_FILE, $state);
		send_json(array('ok' => true, 'message' => 'Stopped all devices.', 'instances' => array()));
	}

	if ($action === 'devices') {
		$scan = discover_rtl_devices();
		send_json(array(
			'ok' => true,
			'devices' => $scan['devices'],
			'warning' => $scan['warning'],
		));
	}

	if ($action === 'logs') {
		$deviceId = normalize_device_id($_POST['device'] ?? $_GET['device'] ?? ($jsonPayload['device'] ?? ''));
		if ($deviceId === '') {
			send_json(array('ok' => false, 'error' => 'Device is required.'), 400);
		}

		$requestedLines = (int)($_POST['lines'] ?? $_GET['lines'] ?? ($jsonPayload['lines'] ?? 40));
		$maxLines = max(10, min(200, $requestedLines));
		$logPath = resolve_log_path_for_device($LOG_DIR, $deviceId, $state);
		send_json(array(
			'ok' => true,
			'device' => $deviceId,
			'running' => isset($state[$deviceId]) ? is_process_running((int)($state[$deviceId]['pid'] ?? 0)) : false,
			'logFile' => $logPath === '' ? '' : basename($logPath),
			'lines' => read_log_lines($logPath, $maxLines),
		));
	}

	if ($action === 'start' || $action === 'retune') {
		$payload = $_POST;
		if (is_array($jsonPayload) && count($jsonPayload) > 0) {
			$payload = array_merge($payload, $jsonPayload);
		}

		try {
			$config = normalize_config((array)$payload, $recordingsRoot);
		} catch (RuntimeException $error) {
			send_json(array('ok' => false, 'error' => $error->getMessage()), 400);
		}

		$deviceId = (string)$config['device'];
		if (isset($state[$deviceId])) {
			$existingPid = isset($state[$deviceId]['pid']) ? (int)$state[$deviceId]['pid'] : 0;
			stop_instance_by_pid($existingPid);
			unset($state[$deviceId]);
		}

		$startResult = start_instance($config, $LOG_DIR);
		if ($startResult['ok'] !== true) {
			send_json(array('ok' => false, 'error' => $startResult['error']), 500);
		}

		$state[$deviceId] = array(
			'pid' => (int)$startResult['pid'],
			'startedAt' => time(),
			'logFile' => (string)$startResult['logFile'],
			'command' => (string)$startResult['command'],
			'config' => $config,
		);

		save_state($STATE_FILE, $state);
		send_json(array(
			'ok' => true,
			'message' => strtoupper($action) . ' applied to device ' . $deviceId . '.',
			'instances' => list_instances($state),
		));
	}

	send_json(array('ok' => false, 'error' => 'Unknown action: ' . $action), 400);
}

?><!DOCTYPE html>
<html>
<head>
	<title><?=htmlspecialchars($RTL_PAGE_TITLE, ENT_QUOTES, 'UTF-8')?></title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="apple-touch-fullscreen" content="yes">
	<meta name="mobile-web-app-capable" content="yes">
	<style type="text/css">
		:root {
			--bg: #0f1115;
			--panel: #171b22;
			--panel-strong: #1d2430;
			--border: #2c3543;
			--text: #eef2f7;
			--muted: #a5b1c2;
			--accent: #d9a441;
			--accent-soft: rgba(217, 164, 65, 0.16);
			--success: #87d37c;
			--danger: #f18f8f;
			--input: #0f141c;
		}
		body.theme-light {
			--bg: #f2efe7;
			--panel: #fffdf8;
			--panel-strong: #f7f1e2;
			--border: #d5cab2;
			--text: #2b2620;
			--muted: #6f6659;
			--accent: #a86d12;
			--accent-soft: rgba(168, 109, 18, 0.12);
			--success: #2e7d32;
			--danger: #b53a3a;
			--input: #fffaf0;
		}
		body {
			margin: 0;
			background:
				radial-gradient(circle at top left, rgba(217, 164, 65, 0.12), transparent 28%),
				linear-gradient(180deg, rgba(255,255,255,0.02), transparent 24%),
				var(--bg);
			color: var(--text);
			font-family: "Trebuchet MS", "Segoe UI", sans-serif;
		}
		a { color: var(--accent); }
		.page-wrap { max-width: 1180px; margin: 0 auto; padding: 18px; }
		.hero {
			display: flex;
			justify-content: space-between;
			gap: 18px;
			align-items: flex-end;
			margin-bottom: 16px;
		}
		.hero h2 { margin: 0; font-size: 30px; letter-spacing: 0.04em; text-transform: uppercase; }
		.hero p { margin: 6px 0 0; color: var(--muted); font-size: 13px; }
		.summary-chip {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			padding: 10px 14px;
			border-radius: 999px;
			background: var(--accent-soft);
			border: 1px solid rgba(217, 164, 65, 0.24);
			font-size: 12px;
		}
		.panel {
			border: 1px solid var(--border);
			border-radius: 16px;
			background: linear-gradient(180deg, rgba(255,255,255,0.02), transparent 22%), var(--panel);
			box-shadow: 0 20px 60px rgba(0, 0, 0, 0.18);
		}
		.toolbar {
			display: flex;
			align-items: center;
			gap: 10px;
			flex-wrap: wrap;
			padding: 14px;
			margin-bottom: 14px;
		}
		.toolbar-spacer { flex: 1 1 auto; }
		.shared-setting {
			display: flex;
			align-items: center;
			gap: 10px;
			padding: 10px 14px 14px;
			margin-bottom: 14px;
		}
		.shared-setting label { margin: 0; min-width: 150px; }
		.shared-setting input { flex: 1 1 auto; }
		.status-text { font-size: 12px; color: var(--muted); }
		.refresh-button {
			min-height: 38px;
			padding: 8px 14px;
			white-space: nowrap;
			border: 1px solid var(--border);
			border-radius: 999px;
			background: var(--panel-strong);
			color: var(--text);
			cursor: pointer;
			transition: transform 120ms ease, background-color 120ms ease, border-color 120ms ease;
		}
		.refresh-button:hover { transform: translateY(-1px); border-color: var(--accent); }
		.refresh-button.primary { background: var(--accent); color: #17120a; border-color: var(--accent); font-weight: 700; }
		.refresh-button.danger { border-color: rgba(241, 143, 143, 0.45); }
		.template-toolbar-group { display: inline-flex; align-items: center; gap: 10px; flex-wrap: wrap; }
		#templateDeviceSelect { min-width: 210px; }
		.device-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; }
		.device-card { padding: 16px; position: relative; overflow: hidden; }
		.device-card::after {
			content: "";
			position: absolute;
			inset: auto -20% -40% auto;
			width: 180px;
			height: 180px;
			background: radial-gradient(circle, rgba(217, 164, 65, 0.16), transparent 70%);
			pointer-events: none;
		}
		.device-header { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 6px 12px; align-items: start; position: relative; z-index: 1; }
		.device-title-row { min-width: 0; }
		.state-pills {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			flex-wrap: nowrap;
			justify-content: flex-end;
			flex: 0 0 auto;
			white-space: nowrap;
		}
		.device-title { margin: 0; font-size: 20px; }
		.device-subtitle { grid-column: 1 / -1; margin: 0; color: var(--muted); font-size: 12px; line-height: 1.5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
		.state-pill {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			padding: 6px 10px;
			border-radius: 999px;
			font-size: 11px;
			text-transform: uppercase;
			letter-spacing: 0.08em;
			background: rgba(255,255,255,0.04);
			border: 1px solid var(--border);
		}
		.state-pill.running { color: #b7f09a; border-color: rgba(135, 211, 124, 0.48); background: rgba(135, 211, 124, 0.16); }
		.state-pill.stopped { color: #ffb3b3; border-color: rgba(241, 143, 143, 0.45); background: rgba(241, 143, 143, 0.14); }
		.state-pill.rx-active { color: #8df7d4; border-color: rgba(99, 215, 176, 0.5); background: rgba(99, 215, 176, 0.16); }
		.state-pill.rx-idle { color: #ffd783; border-color: rgba(217, 164, 65, 0.5); background: rgba(217, 164, 65, 0.16); }
		.state-pill.rx-off { color: #98a7bb; border-color: rgba(127, 145, 168, 0.42); background: rgba(127, 145, 168, 0.14); }
		.state-pills .action-copy-stream,
		.state-pills .action-listen-stream { display: inline-flex; align-items: center; padding: 6px 10px; font-size: 11px; border-radius: 999px; border: 1px solid var(--border); background: rgba(255,255,255,0.04); color: var(--text); cursor: pointer; text-transform: uppercase; letter-spacing: 0.08em; transition: all 0.2s; font-family: inherit; font-weight: 500; white-space: nowrap; }
		.state-pills .action-copy-stream:hover { background: rgba(255,255,255,0.08); }
		.state-pills .action-listen-stream:hover { background: rgba(255,255,255,0.08); }
		.state-pills .action-listen-stream.danger { color: #ffb3b3; border-color: rgba(241, 143, 143, 0.45); background: rgba(241, 143, 143, 0.14); }
		.device-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; position: relative; z-index: 1; }
		.device-log-toggle { margin-top: 12px; position: relative; z-index: 1; }
		.device-meta { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-top: 14px; position: relative; z-index: 1; align-items: start; }
		.meta-box { display: flex; flex-direction: column; justify-content: flex-start; min-height: 50px; padding: 10px; border-radius: 12px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); }
		.meta-box.full { grid-column: span 3; min-height: auto; }
		.meta-label { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); margin-bottom: 4px; }
		.meta-value { font-size: 12px; word-break: break-word; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.meta-box.full .meta-value { overflow: visible; text-overflow: clip; white-space: normal; }
		.device-config { margin-top: 14px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.06); position: relative; z-index: 1; }
		.device-config.collapsed { display: none; }
		.device-log-panel { margin-top: 14px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.06); position: relative; z-index: 1; }
		.device-log-panel.collapsed { display: none; }
		.log-shell { border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; background: rgba(4, 8, 14, 0.72); padding: 12px; }
		body.theme-light .log-shell { background: rgba(43, 38, 32, 0.06); }
		.log-header { display: flex; justify-content: space-between; gap: 10px; align-items: center; margin-bottom: 8px; }
		.log-meta { font-size: 11px; color: var(--muted); }
		.log-lines { margin: 0; min-height: 140px; max-height: 280px; overflow: auto; white-space: pre-wrap; font: 12px/1.45 "Cascadia Mono", "Consolas", monospace; color: var(--text); }
		.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
		.form-row.single { grid-template-columns: 1fr; }
		.form-row.template-button-row .refresh-button { width: 100%; }
		label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); margin-bottom: 5px; }
		input, select {
			width: 100%;
			min-height: 38px;
			border: 1px solid var(--border);
			border-radius: 10px;
			background: var(--input);
			color: var(--text);
			padding: 8px 10px;
			box-sizing: border-box;
		}
		.empty-state { padding: 30px 18px; text-align: center; color: var(--muted); }
		.small { font-size: 11px; }
		.hidden { display: none; }
		.modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 9999; }
		.modal.hidden { display: none; }
		.modal-content { background: var(--bg-secondary); border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); width: 90%; max-width: 500px; }
		.modal-header { padding: 16px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
		.modal-header h3 { margin: 0; color: var(--text); }
		.modal-close { background: none; border: none; color: var(--muted); font-size: 24px; cursor: pointer; padding: 0; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; }
		.modal-close:hover { color: var(--text); }
		.modal-body { padding: 20px; }
		.modal-footer { padding: 16px; border-top: 1px solid var(--border); display: flex; gap: 8px; justify-content: flex-end; }
		@media (max-width: 760px) {
			.page-wrap { padding: 12px; }
			.hero { flex-direction: column; align-items: flex-start; }
			.form-row, .device-meta { grid-template-columns: 1fr; }
			.shared-setting { flex-direction: column; align-items: stretch; }
			.shared-setting label { min-width: 0; }
		}
	</style>
</head>
<body class="theme-dark">
<div class="page-wrap">
	<div class="hero">
		<div>
			<h2><?=htmlspecialchars($RTL_PAGE_TITLE, ENT_QUOTES, 'UTF-8')?></h2>
			<p>Detect radios, open a device card, tune it, and start or stop capture in one place.</p>
		</div>
		<div class="summary-chip"><span id="summaryText">0 devices detected</span></div>
	</div>

	<div class="panel toolbar">
		<button type="button" class="refresh-button primary" id="scanDevicesButton">Scan Devices</button>
		<button type="button" class="refresh-button" id="refreshButton">Refresh State</button>
		<button type="button" class="refresh-button danger" id="stopAllButton">Stop All</button>
		<button type="button" class="refresh-button" id="toggleTemplateToolbarButton">Show Templates</button>
		<span id="templateToolbarGroup" class="template-toolbar-group hidden">
			<select id="globalTemplateSelect" aria-label="Global template selector"></select>
			<select id="templateDeviceSelect" aria-label="Template target devices" multiple size="3"></select>
			<button type="button" class="refresh-button" id="applyTemplateSelectedButton">Apply Template To Selected</button>
		</span>
		<span class="toolbar-spacer"></span>
		<button type="button" class="refresh-button" id="themeToggleButton">Toggle Theme</button>
		<span id="statusText" class="status-text">Ready.</span>
	</div>

	<div class="panel" style="padding: 14px;">
		<div id="deviceList" class="device-list"></div>
	</div>

	<div id="serverModal" class="modal hidden">
		<div class="modal-content">
			<div class="modal-header">
				<h3 id="serverModalTitle">New Server</h3>
				<button type="button" class="modal-close" id="serverModalClose">&times;</button>
			</div>
			<div class="modal-body">
				<div class="form-row single">
					<div><label>Server Name</label><input type="text" id="serverModalName" placeholder="My Icecast Server"></div>
				</div>
				<div class="form-row single">
					<div><label>Target (host:port)</label><input type="text" id="serverModalTarget" placeholder="127.0.0.1:8000"></div>
				</div>
				<div class="form-row single">
					<div><label>Username (optional)</label><input type="text" id="serverModalUsername" placeholder="source"></div>
				</div>
				<div class="form-row single">
					<div><label>Password (optional)</label><input type="password" id="serverModalPassword" placeholder="password"></div>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="refresh-button" id="serverModalCancel">Cancel</button>
				<button type="button" class="refresh-button primary" id="serverModalSave">Save Server</button>
				<button type="button" class="refresh-button danger hidden" id="serverModalDelete">Delete Server</button>
			</div>
		</div>
	</div>
</div>

<script type="text/javascript">
var apiUrl = window.location.pathname;
var knownInstancesByDevice = {};
var knownDetectedDevices = [];
var deviceConfigsById = {};
var settingsTemplates = {};
var streamServersById = {};
var openConfigPanelsByDevice = {};
var openLogPanelsByDevice = {};
var logContentByDevice = {};
var streamPlayersByDevice = {};
var uiSettingsSaveTimer = null;
var uiSettingsSaveInFlight = false;
var uiSettingsSaveQueued = false;
var UI_SETTINGS_SAVE_DELAY_MS = 400;

function setStatus(message, isError)
{
	var statusText = document.getElementById('statusText');
	statusText.textContent = message;
	statusText.style.color = isError ? 'var(--danger)' : 'var(--muted)';
}

function setTemplateToolbarVisible(visible)
{
	var group = document.getElementById('templateToolbarGroup');
	var toggleButton = document.getElementById('toggleTemplateToolbarButton');
	if (!group || !toggleButton) {
		return;
	}
	var isVisible = !!visible;
	group.classList.toggle('hidden', !isVisible);
	toggleButton.textContent = isVisible ? 'Hide Templates' : 'Show Templates';
	try {
		window.localStorage.setItem('rtlSdrTemplatesExpanded', isVisible ? '1' : '0');
	} catch (error) {
	}
}

function applyTheme(themeName)
{
	var theme = (themeName === 'theme-light') ? 'theme-light' : 'theme-dark';
	document.body.classList.remove('theme-dark');
	document.body.classList.remove('theme-light');
	document.body.classList.add(theme);
}

function initTheme()
{
	var savedTheme = null;
	try { savedTheme = window.localStorage.getItem('rtlSdrTheme'); } catch (error) {}
	applyTheme(savedTheme === 'theme-light' ? 'theme-light' : 'theme-dark');
}

function collectUiSettingsPayload()
{
	return {
		deviceConfigs: deviceConfigsById,
		templates: settingsTemplates
	};
}

function saveUiSettingsNow()
{
	if (uiSettingsSaveInFlight) {
		uiSettingsSaveQueued = true;
		return Promise.resolve();
	}

	uiSettingsSaveInFlight = true;
	return postAction('settings_set', { settings: collectUiSettingsPayload() }).then(function (result) {
		if (result && result.settings && typeof result.settings === 'object') {
			if (result.settings.deviceConfigs && typeof result.settings.deviceConfigs === 'object' && !Array.isArray(result.settings.deviceConfigs)) {
				deviceConfigsById = result.settings.deviceConfigs;
			}
			if (result.settings.templates && typeof result.settings.templates === 'object' && !Array.isArray(result.settings.templates)) {
				settingsTemplates = result.settings.templates;
			}
		}
	}).catch(function (error) {
		setStatus('Failed to save UI settings: ' + error.message, true);
	}).finally(function () {
		uiSettingsSaveInFlight = false;
		if (uiSettingsSaveQueued) {
			uiSettingsSaveQueued = false;
			saveUiSettingsNow();
		}
	});
}

function scheduleUiSettingsSave()
{
	if (uiSettingsSaveTimer !== null) {
		window.clearTimeout(uiSettingsSaveTimer);
	}
	uiSettingsSaveTimer = window.setTimeout(function () {
		uiSettingsSaveTimer = null;
		saveUiSettingsNow();
	}, UI_SETTINGS_SAVE_DELAY_MS);
}

function loadUiSettingsFromServer()
{
	return postAction('settings_get', {}).then(function (result) {
		var settings = (result && result.settings && typeof result.settings === 'object') ? result.settings : {};

		if (settings.deviceConfigs && typeof settings.deviceConfigs === 'object' && !Array.isArray(settings.deviceConfigs)) {
			deviceConfigsById = settings.deviceConfigs;
		} else {
			deviceConfigsById = {};
		}

		if (settings.templates && typeof settings.templates === 'object' && !Array.isArray(settings.templates)) {
			settingsTemplates = settings.templates;
		} else {
			settingsTemplates = {};
		}
	}).catch(function () {
		deviceConfigsById = {};
		settingsTemplates = {};
	});
}

function updateSummary()
{
	var detectedCount = knownDetectedDevices.length;
	var runningCount = Object.keys(knownInstancesByDevice).length;
	document.getElementById('summaryText').textContent = detectedCount + ' device(s) detected, ' + runningCount + ' running';
}

function saveDeviceConfigs()
{
	scheduleUiSettingsSave();
}

function saveTemplates()
{
	scheduleUiSettingsSave();
}

function saveStreamServers()
{
	return postAction('stream_servers_set', { servers: streamServersById }).then(function (result) {
		if (result && result.servers && typeof result.servers === 'object') {
			streamServersById = result.servers;
		}
		return streamServersById;
	});
}

function loadStreamServersFromServer()
{
	return postAction('stream_servers_get', {}).then(function (result) {
		if (!result || !result.servers || typeof result.servers !== 'object') {
			streamServersById = {};
			return streamServersById;
		}
		streamServersById = result.servers;
		return streamServersById;
	}).catch(function () {
		streamServersById = {};
		return streamServersById;
	});
}

var currentEditingServerId = null;

function openServerDialog(serverId)
{
	currentEditingServerId = serverId;
	var modal = document.getElementById('serverModal');
	var titleEl = document.getElementById('serverModalTitle');
	var nameEl = document.getElementById('serverModalName');
	var targetEl = document.getElementById('serverModalTarget');
	var usernameEl = document.getElementById('serverModalUsername');
	var passwordEl = document.getElementById('serverModalPassword');
	var deleteBtn = document.getElementById('serverModalDelete');

	if (serverId) {
		var server = getStreamServerById(serverId);
		if (server) {
			titleEl.textContent = 'Edit Server';
			nameEl.value = String(server.name || '');
			targetEl.value = String(server.target || '');
			usernameEl.value = String(server.username || '');
			passwordEl.value = String(server.password || '');
			deleteBtn.classList.remove('hidden');
		} else {
			return;
		}
	} else {
		titleEl.textContent = 'New Server';
		nameEl.value = '';
		targetEl.value = '';
		usernameEl.value = '';
		passwordEl.value = '';
		deleteBtn.classList.add('hidden');
	}

	modal.classList.remove('hidden');
	nameEl.focus();
}

function closeServerDialog()
{
	var modal = document.getElementById('serverModal');
	modal.classList.add('hidden');
	currentEditingServerId = null;
}

function saveServer()
{
	var nameEl = document.getElementById('serverModalName');
	var targetEl = document.getElementById('serverModalTarget');
	var usernameEl = document.getElementById('serverModalUsername');
	var passwordEl = document.getElementById('serverModalPassword');

	var name = nameEl.value.trim();
	var target = targetEl.value.trim();
	var username = usernameEl.value.trim();
	var password = passwordEl.value;

	if (!name) {
		setStatus('Server name is required', true);
		return;
	}
	if (!target) {
		setStatus('Target server is required', true);
		return;
	}

	var serverId = currentEditingServerId || getNextServerId();
	streamServersById[serverId] = {
		name: name,
		target: target,
		username: username,
		password: password
	};

	saveStreamServers().then(function () {
		closeServerDialog();
		renderDeviceList();
		setStatus('Server saved', false);
	}).catch(function (error) {
		setStatus(error.message || 'Failed to save server', true);
	});
}

function deleteServer()
{
	if (!currentEditingServerId) {
		return;
	}
	if (confirm('Delete this server?')) {
		delete streamServersById[currentEditingServerId];
		saveStreamServers().then(function () {
			closeServerDialog();
			renderDeviceList();
			setStatus('Server deleted', false);
		}).catch(function (error) {
			setStatus(error.message || 'Failed to delete server', true);
		});
	}
}

function getDefaultMountForFormat(format)
{
	return '/rtl-sdr.' + (String(format).toLowerCase() === 'ogg' ? 'ogg' : 'mp3');
}

function normalizeMountByFormat(mount, format)
{
	var raw = String(mount == null ? '' : mount).trim();
	if (raw === '') {
		return getDefaultMountForFormat(format);
	}
	if (raw.charAt(0) !== '/') {
		raw = '/' + raw;
	}
	return raw;
}

function buildStreamPlaybackUrl(config)
{
	var target = String(config.streamTarget || '').trim();
	var mount = normalizeMountByFormat(config.streamMount, config.streamFormat || 'mp3');
	if (target === '') {
		return '';
	}
	return 'http://' + target + mount;
}

function stopListeningForDevice(deviceId, silent)
{
	var key = String(deviceId);
	var player = streamPlayersByDevice[key] || null;
	if (player && player.audio) {
		try {
			player.audio.pause();
			player.audio.src = '';
		} catch (error) {
		}
	}
	delete streamPlayersByDevice[key];
	if (!silent) {
		setStatus('Stopped listening on device ' + key + '.', false);
	}
}

function listenToStreamForCard(card)
{
	var config = readCardConfig(card);
	if (String(config.outputMode || '') !== 'stream') {
		setStatus('Set Output Mode to Stream before listening.', true);
		return;
	}

	var deviceId = String(config.device || '');
	if (!deviceId) {
		setStatus('Missing device id.', true);
		return;
	}

	if (streamPlayersByDevice[deviceId]) {
		stopListeningForDevice(deviceId, true);
		renderDeviceList();
		setStatus('Stopped listening on device ' + deviceId + '.', false);
		return;
	}

	var streamUrl = buildStreamPlaybackUrl(config);
	if (!streamUrl) {
		setStatus('Missing stream target/mount.', true);
		return;
	}

	var audio = new Audio(streamUrl);
	audio.preload = 'none';
	audio.addEventListener('error', function () {
		stopListeningForDevice(deviceId, true);
		renderDeviceList();
		setStatus('Stream playback failed for device ' + deviceId + '.', true);
	});

	streamPlayersByDevice[deviceId] = { audio: audio, url: streamUrl };
	var playPromise = audio.play();
	if (playPromise && typeof playPromise.then === 'function') {
		playPromise.then(function () {
			renderDeviceList();
			setStatus('Listening on ' + streamUrl, false);
		}).catch(function (error) {
			stopListeningForDevice(deviceId, true);
			renderDeviceList();
			setStatus(error && error.message ? error.message : 'Browser blocked playback.', true);
		});
		return;
	}

	renderDeviceList();
	setStatus('Listening on ' + streamUrl, false);
}

function copyStreamUrlForCard(card)
{
	var config = readCardConfig(card);
	if (String(config.outputMode || '') !== 'stream') {
		setStatus('Set Output Mode to Stream before copying stream URL.', true);
		return;
	}
	var streamUrl = buildStreamPlaybackUrl(config);
	if (!streamUrl) {
		setStatus('Missing stream target/mount.', true);
		return;
	}

	if (navigator.clipboard && navigator.clipboard.writeText) {
		navigator.clipboard.writeText(streamUrl).then(function () {
			setStatus('Copied stream URL: ' + streamUrl, false);
		}).catch(function () {
			setStatus('Unable to copy stream URL.', true);
		});
		return;
	}

	var temp = document.createElement('textarea');
	temp.value = streamUrl;
	document.body.appendChild(temp);
	temp.select();
	try {
		document.execCommand('copy');
		setStatus('Copied stream URL: ' + streamUrl, false);
	} catch (error) {
		setStatus('Unable to copy stream URL.', true);
	}
	document.body.removeChild(temp);
}

function templateOptionsMarkup(selectedName)
{
	var selected = String(selectedName || '');
	var names = Object.keys(settingsTemplates).sort();
	var options = '<option value="">Select template</option>';
	for (var i = 0; i < names.length; i++) {
		var name = names[i];
		var isSelected = name === selected ? ' selected' : '';
		options += '<option value="' + escapeHtml(name) + '"' + isSelected + '>' + escapeHtml(name) + '</option>';
	}
	return options;
}

function refreshGlobalTemplateSelector()
{
	var globalSelect = document.getElementById('globalTemplateSelect');
	if (!globalSelect) {
		return;
	}
	var existing = String(globalSelect.value || '');
	globalSelect.innerHTML = templateOptionsMarkup(existing);
	if (!globalSelect.value && existing !== '') {
		globalSelect.value = '';
	}
}

function refreshTemplateDeviceSelector()
{
	var deviceSelect = document.getElementById('templateDeviceSelect');
	if (!deviceSelect) {
		return;
	}
	var selected = {};
	for (var i = 0; i < deviceSelect.options.length; i++) {
		if (deviceSelect.options[i].selected) {
			selected[String(deviceSelect.options[i].value)] = true;
		}
	}

	var devices = collectVisibleDevices();
	var options = '';
	for (var j = 0; j < devices.length; j++) {
		var id = String(devices[j].index);
		var label = String(devices[j].label || ('RTL-SDR Device ' + id));
		var isSelected = selected[id] ? ' selected' : '';
		options += '<option value="' + escapeHtml(id) + '"' + isSelected + '>Device ' + escapeHtml(id) + ' - ' + escapeHtml(label) + '</option>';
	}
	deviceSelect.innerHTML = options;
}

function sanitizeTemplateConfig(config)
{
	var clean = Object.assign({}, config || {});
	delete clean.device;
	delete clean.outputDir;
	clean.streamFormat = String(clean.streamFormat || 'mp3').toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	clean.streamMount = normalizeMountByFormat(clean.streamMount, clean.streamFormat);
	return clean;
}

function applyTemplateToDevice(deviceId, templateName)
{
	var name = String(templateName || '');
	if (!name || !settingsTemplates[name]) {
		throw new Error('Template not found.');
	}
	var current = getConfigForDevice(deviceId);
	var merged = Object.assign({}, current, settingsTemplates[name]);
	merged.device = String(deviceId);
	merged.streamFormat = String(merged.streamFormat || 'mp3').toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	merged.streamMount = normalizeMountByFormat(merged.streamMount, merged.streamFormat);
	merged.templateName = name;
	if (String(merged.outputMode || 'recorder') !== 'stream') {
		stopListeningForDevice(deviceId, true);
	}
	deviceConfigsById[String(deviceId)] = merged;
}
function isConfigOpen(deviceId)
{
	return !!openConfigPanelsByDevice[String(deviceId)];
}

function isLogOpen(deviceId)
{
	return !!openLogPanelsByDevice[String(deviceId)];
}

function getDefaultConfig(deviceId)
{
	return {
		device: String(deviceId),
		frequency: '146.520M',
		mode: 'fm',
		rtlBandwidth: '12000',
		squelch: '500',
		gain: 'auto',
		threshold: '-40',
		silence: '2',
		outputMode: 'recorder',
		streamFormat: 'mp3',
		streamBitrate: '128',
		streamTarget: '127.0.0.1:8000',
		streamMount: '/rtl-sdr.mp3',
		streamUsername: '',
		streamPassword: '',
		streamName: '',
		postCommand: '',
		templateName: ''
	};
}

function normalizeClientSquelchValue(value)
{
	var raw = String(value == null ? '' : value).trim();
	if (raw === '' || raw === '0') {
		return '1';
	}
	if (!/^-?\d+$/.test(raw)) {
		return '1';
	}
	return raw;
}

function syncSquelchValidity(input)
{
	var raw = String(input.value == null ? '' : input.value).trim();
	if (raw === '0') {
		input.setCustomValidity('Squelch must be non-zero.');
		return;
	}
	input.setCustomValidity('');
}

function syncOutputModeFields(card)
{
	var modeSelect = card.querySelector('.field-output-mode');
	if (!modeSelect) {
		return;
	}
	var isStream = modeSelect.value === 'stream';
	var streamOnly = card.querySelectorAll('.output-stream-only');
	var recorderOnly = card.querySelectorAll('.output-recorder-only');
	for (var i = 0; i < streamOnly.length; i++) {
		streamOnly[i].classList.toggle('hidden', !isStream);
	}
	for (var j = 0; j < recorderOnly.length; j++) {
		recorderOnly[j].classList.toggle('hidden', isStream);
	}
}

function syncMountWithStreamFormat(card, force)
{
	var formatSelect = card.querySelector('.field-stream-format');
	var mountInput = card.querySelector('.field-stream-mount');
	if (!formatSelect || !mountInput) {
		return;
	}
	var current = String(mountInput.value || '').trim();
	var format = String(formatSelect.value || 'mp3').toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	var preferred = getDefaultMountForFormat(format);
	var alternative = getDefaultMountForFormat(format === 'ogg' ? 'mp3' : 'ogg');
	if (force) {
		var next = current;
		if (next === '') {
			next = preferred;
		} else if (/\.(mp3|ogg)$/i.test(next)) {
			next = next.replace(/\.(mp3|ogg)$/i, '.' + format);
		} else if (/\.[A-Za-z0-9]+$/.test(next)) {
			next = next.replace(/\.[A-Za-z0-9]+$/, '.' + format);
		} else {
			next = next + '.' + format;
		}
		if (next.charAt(0) !== '/') {
			next = '/' + next;
		}
		mountInput.value = next;
	} else if (current === '' || current === alternative) {
		mountInput.value = preferred;
	}
	mountInput.placeholder = preferred;
}

function formatLogText(deviceId)
{
	var cache = logContentByDevice[String(deviceId)] || null;
	if (!cache) {
		return 'Log stream idle. Logs auto-refresh while this page is open.';
	}
	if (!cache.lines || !cache.lines.length) {
		return 'No log lines available yet.';
	}
	return cache.lines.join('\n');
}

function getRxIndicator(deviceId, isRunning, config)
{
	if (!isRunning) {
		return { label: 'Rx Off', className: 'rx-off' };
	}

	if (config && String(config.outputMode || '').toLowerCase() === 'stream') {
		return { label: 'Rx Idle', className: 'rx-idle' };
	}

	var cache = logContentByDevice[String(deviceId)] || null;
	var lines = cache && Array.isArray(cache.lines) ? cache.lines : [];
	for (var i = 0; i < lines.length; i++) {
		var upper = String(lines[i] || '').toUpperCase();
		if (upper.indexOf('[RX] ACTIVE') !== -1) {
			return { label: 'Rx Active', className: 'rx-active' };
		}
		if (upper.indexOf('[RX] IDLE') !== -1) {
			return { label: 'Rx Idle', className: 'rx-idle' };
		}
		if (upper.indexOf('[RECORD]') !== -1 || upper.indexOf('AUDIO DETECTED') !== -1) {
			return { label: 'Rx Active', className: 'rx-active' };
		}
		if (upper.indexOf('[SILENCE]') !== -1 || upper.indexOf('SILENCE REACHED') !== -1 || upper.indexOf('[READY]') !== -1) {
			return { label: 'Rx Idle', className: 'rx-idle' };
		}
	}

	return { label: 'Rx Idle', className: 'rx-idle' };
}

function getConfigForDevice(deviceId)
{
	var base = getDefaultConfig(deviceId);
	var saved = deviceConfigsById[String(deviceId)] || {};
	var merged = {};
	for (var key in base) {
		if (Object.prototype.hasOwnProperty.call(base, key)) {
			merged[key] = base[key];
		}
	}
	for (var extraKey in saved) {
		if (Object.prototype.hasOwnProperty.call(saved, extraKey)) {
			merged[extraKey] = saved[extraKey];
		}
	}
	merged.device = String(deviceId);
	merged.rtlBandwidth = String(merged.rtlBandwidth || '12000');
	merged.squelch = normalizeClientSquelchValue(merged.squelch);
	merged.outputMode = String(merged.outputMode || 'recorder').toLowerCase() === 'stream' ? 'stream' : 'recorder';
	merged.streamFormat = String(merged.streamFormat || 'mp3').toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	merged.streamBitrate = String(merged.streamBitrate || '128');
	merged.streamTarget = String(merged.streamTarget || '127.0.0.1:8000');
	merged.streamMount = normalizeMountByFormat(merged.streamMount, merged.streamFormat);
	merged.streamUsername = String(merged.streamUsername || '');
	merged.streamPassword = String(merged.streamPassword || '');
	merged.templateName = String(merged.templateName || '');
	return merged;
}

function escapeHtml(value)
{
	return String(value)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#039;');
}

function getDeviceDescriptor(deviceId)
{
	for (var i = 0; i < knownDetectedDevices.length; i++) {
		if (String(knownDetectedDevices[i].index) === String(deviceId)) {
			return knownDetectedDevices[i];
		}
	}
	return { index: String(deviceId), label: 'RTL-SDR Device ' + deviceId };
}

function collectVisibleDevices()
{
	var devices = [];
	var seen = {};
	for (var i = 0; i < knownDetectedDevices.length; i++) {
		var device = knownDetectedDevices[i];
		var key = String(device.index);
		seen[key] = true;
		devices.push(device);
	}

	for (var deviceId in knownInstancesByDevice) {
		if (Object.prototype.hasOwnProperty.call(knownInstancesByDevice, deviceId) && !seen[deviceId]) {
			devices.push({ index: deviceId, label: 'RTL-SDR Device ' + deviceId });
		}
	}

	devices.sort(function (left, right) {
		return Number(left.index) - Number(right.index);
	});

	return devices;
}

function renderDeviceList()
{
	var listEl = document.getElementById('deviceList');
	var devices = collectVisibleDevices();
	if (!devices.length) {
		listEl.innerHTML = '<div class="empty-state">No RTL-SDR devices detected yet. Run <b>Scan Devices</b> to build the control surface.</div>';
		updateSummary();
		return;
	}

	var markup = '';
	for (var i = 0; i < devices.length; i++) {
		var device = devices[i];
		var deviceId = String(device.index);
		var instance = knownInstancesByDevice[deviceId] || null;
		var config = getConfigForDevice(deviceId);
		var isRunning = !!instance;
		var stateClass = isRunning ? 'running' : 'stopped';
		var stateLabel = isRunning ? 'Running' : 'Stopped';
		var rxIndicator = getRxIndicator(deviceId, isRunning, config);
		var metaPid = isRunning ? String(instance.pid || '') : 'Idle';
		var metaLog = isRunning ? String(instance.logFile || '') : 'No active log';
		var configPanelClass = isConfigOpen(deviceId) ? 'device-config' : 'device-config collapsed';
		var logPanelClass = isLogOpen(deviceId) ? 'device-log-panel' : 'device-log-panel collapsed';
		var logToggleLabel = isLogOpen(deviceId) ? 'Collapse Logs' : 'Expand Logs';
		var logCache = logContentByDevice[deviceId] || {};
		var logMeta = logCache.logFile ? logCache.logFile : 'No log loaded';
		var templateSelectOptions = templateOptionsMarkup(String(config.templateName || ''));
		var isListening = !!streamPlayersByDevice[deviceId];
		var listenButtonLabel = isListening ? 'Stop Listening' : 'Listen';
		var listenButtonClass = isListening ? 'refresh-button danger action-listen-stream' : 'refresh-button action-listen-stream';
		var listenButtonClassPills = isListening ? 'danger action-listen-stream' : 'action-listen-stream';
		var isStreamMode = String(config.outputMode || 'recorder') === 'stream';
		var streamActionButtonsHtml = '';
		var streamActionButtonsForPills = '';
		if (isStreamMode) {
			streamActionButtonsHtml =
				'<button type="button" class="refresh-button action-copy-stream">Copy Stream URL</button>' +
				'<button type="button" class="' + listenButtonClass + '">' + listenButtonLabel + '</button>';
			streamActionButtonsForPills =
				'<button type="button" class="action-copy-stream">Copy URL</button>' +
				'<button type="button" class="' + listenButtonClassPills + '">' + listenButtonLabel + '</button>';
		}
		markup += '' +
			'<article class="panel device-card" data-device-id="' + escapeHtml(deviceId) + '">' +
				'<div class="device-header">' +
					'<div class="device-title-row">' +
						'<h3 class="device-title">Device ' + escapeHtml(deviceId) + '</h3>' +
					'</div>' +
					'<div class="state-pills">' +
						'<div class="state-pill ' + stateClass + '">' + stateLabel + '</div>' +
						(isStreamMode ? streamActionButtonsForPills : '<div class="state-pill ' + rxIndicator.className + '">' + rxIndicator.label + '</div>') +
					'</div>' +
					'<div class="device-subtitle">' + escapeHtml(String(device.label || ('RTL-SDR Device ' + deviceId))) + '</div>' +
				'</div>' +
				'<div class="device-actions">' +
					'<button type="button" class="refresh-button primary action-start">' + (isRunning ? 'Retune' : 'Start') + '</button>' +
					'<button type="button" class="refresh-button danger action-stop">Stop</button>' +
					(isStreamMode ? '' : streamActionButtonsHtml) +
					'<button type="button" class="refresh-button action-toggle-config">' + (isConfigOpen(deviceId) ? 'Hide Config' : (isRunning ? 'Adjust Config' : 'Show Config')) + '</button>' +
				'</div>' +
				'<div class="device-meta">' +
					'<div class="meta-box"><span class="meta-label">Frequency</span><span class="meta-value">' + escapeHtml(config.frequency || '') + '</span></div>' +
					'<div class="meta-box"><span class="meta-label">Mode / Bandwidth</span><span class="meta-value">' + escapeHtml((config.mode || 'fm').toUpperCase() + ' / BW ' + String(config.rtlBandwidth || '12000')) + '</span></div>' +
					'<div class="meta-box"><span class="meta-label">Output Mode</span><span class="meta-value">' + escapeHtml(String(config.outputMode || 'recorder').toUpperCase()) + '</span></div>' +
					'<div class="meta-box"><span class="meta-label">PID</span><span class="meta-value">' + escapeHtml(metaPid) + '</span></div>' +
					'<div class="meta-box"><span class="meta-label">Squelch / Silence</span><span class="meta-value">' + escapeHtml(String(config.squelch || '500') + ' / ' + String(config.silence || '2') + 's') + '</span></div>' +
					'<div class="meta-box full"><span class="meta-label">Log File</span><span class="meta-value">' + escapeHtml(metaLog) + '</span></div>' +
				'</div>' +
				'<div class="' + configPanelClass + '">' +
					'<div class="form-row">' +
						'<div><label>Frequency</label><input type="text" class="field-frequency" value="' + escapeHtml(config.frequency || '') + '" placeholder="146.520M"></div>' +
						'<div><label>Mode</label><select class="field-mode">' +
							'<option value="fm">FM</option>' +
							'<option value="wbfm">WBFM</option>' +
							'<option value="am">AM</option>' +
							'<option value="usb">USB</option>' +
							'<option value="lsb">LSB</option>' +
							'<option value="raw">RAW</option>' +
						'</select></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>RTL Bandwidth</label><select class="field-rtl-bandwidth">' +
							'<option value="12000">12000</option>' +
							'<option value="24000">24000</option>' +
							'<option value="48000">48000</option>' +
							'<option value="96000">96000</option>' +
							'<option value="170000">170000</option>' +
						'</select></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>Output Mode</label><select class="field-output-mode">' +
							'<option value="recorder">Recorder</option>' +
							'<option value="stream">Stream</option>' +
						'</select></div>' +
					'</div>' +
					'<div class="form-row">' +
						'<div><label>Squelch</label><input type="number" step="1" inputmode="numeric" class="field-squelch" value="' + escapeHtml(String(config.squelch || '500')) + '" placeholder="non-zero integer" title="Must be a non-zero integer"></div>' +
						'<div><label>Gain</label><input type="text" class="field-gain" value="' + escapeHtml(String(config.gain || '')) + '" placeholder="auto or number"></div>' +
					'</div>' +
					'<div class="form-row output-recorder-only">' +
						'<div><label>Threshold (dB)</label><input type="text" class="field-threshold" value="' + escapeHtml(String(config.threshold || '-40')) + '" placeholder="-40 recommended start"></div>' +
						'<div><label>Silence (sec)</label><input type="text" class="field-silence" value="' + escapeHtml(String(config.silence || '2')) + '"></div>' +
					'</div>' +
					'<div class="form-row output-stream-only hidden">' +
						'<div><label>Stream Format</label><select class="field-stream-format"><option value="mp3">mp3</option><option value="ogg">ogg</option></select></div>' +
						'<div><label>Bitrate (kbps)</label><input type="number" min="16" max="320" step="1" class="field-stream-bitrate" value="' + escapeHtml(String(config.streamBitrate || '128')) + '"></div>' +
					'</div>' +
					'<div class="form-row output-stream-only hidden">' +
						'<div style="flex: 1;"><label>Target Server</label><select class="field-stream-server-id">' + buildStreamServerOptions(String(config.streamServerId || '')) + '</select></div>' +
						'<div style="flex: 0 0 auto; display: flex; gap: 8px; align-items: flex-end;"><button type="button" class="refresh-button action-edit-server" style="padding: 6px 12px;">Edit</button><button type="button" class="refresh-button action-new-server" style="padding: 6px 12px;">New</button></div>' +
					'</div>' +
					'<div class="form-row output-stream-only hidden">' +
						'<div><label>Mount Point</label><input type="text" class="field-stream-mount" value="' + escapeHtml(String(config.streamMount || '')) + '" placeholder="/rtl-sdr.' + escapeHtml(String(config.streamFormat || 'mp3')) + '"></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>Template</label><select class="field-template-name">' + templateSelectOptions + '</select></div>' +
					'</div>' +
					'<div class="form-row template-button-row">' +
						'<div><button type="button" class="refresh-button action-load-template">Load Template</button></div>' +
						'<div><button type="button" class="refresh-button action-save-template">Save Template</button></div>' +
					'</div>' +
					'<div class="form-row template-button-row">' +
						'<div><button type="button" class="refresh-button action-save-template-as">Save Template As...</button></div>' +
						'<div></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>Stream Name</label><input type="text" class="field-stream-name" value="' + escapeHtml(String(config.streamName || '')) + '" placeholder="Optional custom stream name"></div>' +
					'</div>' +
					'<div class="form-row single output-recorder-only">' +
						'<div><label>Post-Record Command (-X)</label><input type="text" class="field-post-command" value="' + escapeHtml(String(config.postCommand || '')) + '" placeholder="Shell command to run after each recording"></div>' +
					'</div>' +
				'</div>' +
				'<div class="device-log-toggle"><button type="button" class="refresh-button action-toggle-log">' + logToggleLabel + '</button></div>' +
				'<div class="' + logPanelClass + '">' +
					'<div class="log-shell">' +
						'<div class="log-header"><div class="log-meta">' + escapeHtml(logMeta) + '</div><span class="log-meta">Auto-refreshing</span></div>' +
						'<pre class="log-lines">' + escapeHtml(formatLogText(deviceId)) + '</pre>' +
					'</div>' +
				'</div>' +
			'</article>';
	}

	listEl.innerHTML = markup;

	var cards = listEl.querySelectorAll('.device-card');
	for (var cardIndex = 0; cardIndex < cards.length; cardIndex++) {
		bindDeviceCard(cards[cardIndex]);
	}

	refreshTemplateDeviceSelector();
	updateSummary();
}

function buildStreamServerOptions(selectedServerId)
{
	var html = '<option value="">-- Select Server --</option>';
	for (var id in streamServersById) {
		var server = streamServersById[id];
		var selected = id === selectedServerId ? ' selected' : '';
		html += '<option value="' + escapeHtml(String(id)) + '"' + selected + '>' + escapeHtml(String(server.name || id)) + '</option>';
	}
	return html;
}

function getStreamServerById(serverId)
{
	return streamServersById[serverId] || null;
}

function getNextServerId()
{
	var maxId = 0;
	for (var id in streamServersById) {
		var numId = parseInt(id, 10);
		if (!isNaN(numId) && numId > maxId) {
			maxId = numId;
		}
	}
	return String(maxId + 1);
}
function readCardConfig(card)
{
	var streamServerId = card.querySelector('.field-stream-server-id').value.trim();
	var streamTarget = '';
	var streamUsername = '';
	var streamPassword = '';
	if (streamServerId) {
		var server = getStreamServerById(streamServerId);
		if (server) {
			streamTarget = String(server.target || '');
			streamUsername = String(server.username || '');
			streamPassword = String(server.password || '');
		}
	}
	return {
		device: card.getAttribute('data-device-id'),
		frequency: card.querySelector('.field-frequency').value.trim(),
		mode: card.querySelector('.field-mode').value.trim(),
		rtlBandwidth: card.querySelector('.field-rtl-bandwidth').value.trim(),
		outputMode: card.querySelector('.field-output-mode').value.trim(),
		squelch: normalizeClientSquelchValue(card.querySelector('.field-squelch').value),
		gain: card.querySelector('.field-gain').value.trim(),
		threshold: card.querySelector('.field-threshold').value.trim(),
		silence: card.querySelector('.field-silence').value.trim(),
		streamFormat: card.querySelector('.field-stream-format').value.trim(),
		streamBitrate: card.querySelector('.field-stream-bitrate').value.trim(),
		streamTarget: streamTarget,
		streamMount: card.querySelector('.field-stream-mount').value.trim(),
		streamUsername: streamUsername,
		streamPassword: streamPassword,
		streamServerId: streamServerId,
		streamName: card.querySelector('.field-stream-name').value.trim(),
		postCommand: card.querySelector('.field-post-command').value.trim(),
		templateName: card.querySelector('.field-template-name').value.trim()
	};
}

function persistCardConfig(card)
{
	var config = readCardConfig(card);
	delete config.outputDir;
	deviceConfigsById[String(config.device)] = config;
	saveDeviceConfigs();
}

function syncDraftConfigsFromOpenCards()
{
	var cards = document.querySelectorAll('#deviceList .device-card');
	for (var i = 0; i < cards.length; i++) {
		persistCardConfig(cards[i]);
	}
}

function isUserEditingDeviceForm()
{
	var active = document.activeElement;
	if (!active) {
		return false;
	}
	var listEl = document.getElementById('deviceList');
	if (!listEl || !listEl.contains(active)) {
		return false;
	}
	var tagName = String(active.tagName || '').toUpperCase();
	return tagName === 'INPUT' || tagName === 'SELECT' || tagName === 'TEXTAREA';
}

function fetchDeviceLogs(deviceId, forceRefresh)
{
	return postAction('logs', { device: String(deviceId), lines: 60 }).then(function (result) {
		logContentByDevice[String(deviceId)] = {
			logFile: String(result.logFile || ''),
			lines: Array.isArray(result.lines) ? result.lines : [],
			running: !!result.running
		};
		return result;
	}).catch(function (error) {
		logContentByDevice[String(deviceId)] = {
			logFile: '',
			lines: ['Failed to load logs: ' + error.message],
			running: false
		};
		return null;
	});
}

function refreshOpenLogs(skipRender)
{
	var requests = [];
	var visibleDevices = collectVisibleDevices();
	for (var i = 0; i < visibleDevices.length; i++) {
		requests.push(fetchDeviceLogs(String(visibleDevices[i].index), false));
	}
	if (!requests.length) {
		return Promise.resolve();
	}
	return Promise.all(requests).then(function () {
		if (skipRender || isUserEditingDeviceForm()) {
			return;
		}
		renderDeviceList();
	});
}

function bindDeviceCard(card)
{
	var deviceId = String(card.getAttribute('data-device-id') || '');
	var modeSelect = card.querySelector('.field-mode');
	var bandwidthSelect = card.querySelector('.field-rtl-bandwidth');
	var outputModeSelect = card.querySelector('.field-output-mode');
	var streamFormatSelect = card.querySelector('.field-stream-format');
	var storedConfig = getConfigForDevice(deviceId);
	modeSelect.value = storedConfig.mode || 'fm';
	bandwidthSelect.value = String(storedConfig.rtlBandwidth || '12000');
	outputModeSelect.value = storedConfig.outputMode || 'recorder';
	streamFormatSelect.value = storedConfig.streamFormat || 'mp3';
	syncMountWithStreamFormat(card, false);
	syncOutputModeFields(card);
	outputModeSelect.addEventListener('change', function () {
		syncOutputModeFields(card);
		if (String(outputModeSelect.value || '') !== 'stream') {
			stopListeningForDevice(deviceId, true);
		}
	});
	streamFormatSelect.addEventListener('change', function () {
		syncMountWithStreamFormat(card, true);
	});
	var squelchInput = card.querySelector('.field-squelch');
	squelchInput.value = normalizeClientSquelchValue(squelchInput.value);
	syncSquelchValidity(squelchInput);
	squelchInput.addEventListener('input', function () {
		syncSquelchValidity(squelchInput);
	});

	var inputs = card.querySelectorAll('input, select');
	for (var i = 0; i < inputs.length; i++) {
		inputs[i].addEventListener('input', function () {
			if (this.classList.contains('field-squelch')) {
				syncSquelchValidity(this);
			}
			persistCardConfig(card);
		});

		inputs[i].addEventListener('change', function () {
			if (this.classList.contains('field-squelch')) {
				this.value = normalizeClientSquelchValue(this.value);
				syncSquelchValidity(this);
			}
			persistCardConfig(card);
		});
	}

	card.querySelector('.action-toggle-config').addEventListener('click', function () {
		openConfigPanelsByDevice[deviceId] = !isConfigOpen(deviceId);
		renderDeviceList();
	});

	card.querySelector('.action-toggle-log').addEventListener('click', function () {
		openLogPanelsByDevice[deviceId] = !isLogOpen(deviceId);
		renderDeviceList();
	});

	card.querySelector('.action-start').addEventListener('click', function () {
		startOrRetuneCard(card);
	});

	card.querySelector('.action-stop').addEventListener('click', function () {
		stopCard(card);
	});

	var editServerButton = card.querySelector('.action-edit-server');
	if (editServerButton) {
		editServerButton.addEventListener('click', function () {
			var serverIdField = card.querySelector('.field-stream-server-id');
			var serverId = serverIdField.value.trim();
			if (serverId) {
				openServerDialog(serverId);
			} else {
				setStatus('Please select a server first', true);
			}
		});
	}

	var newServerButton = card.querySelector('.action-new-server');
	if (newServerButton) {
		newServerButton.addEventListener('click', function () {
			openServerDialog(null);
		});
	}

	var copyStreamButton = card.querySelector('.action-copy-stream');
	if (copyStreamButton) {
		copyStreamButton.addEventListener('click', function () {
			copyStreamUrlForCard(card);
		});
	}

	var listenStreamButton = card.querySelector('.action-listen-stream');
	if (listenStreamButton) {
		listenStreamButton.addEventListener('click', function () {
			listenToStreamForCard(card);
		});
	}

	var saveTemplateButton = card.querySelector('.action-save-template');
	if (saveTemplateButton) {
		saveTemplateButton.addEventListener('click', function () {
			var currentConfig = readCardConfig(card);
			var name = String(currentConfig.templateName || '').trim();
			if (name === '') {
				setStatus('No template loaded for this device. Use Save Template As...', true);
				return;
			}
			if (!settingsTemplates[name]) {
				setStatus('Loaded template no longer exists. Use Save Template As...', true);
				return;
			}
			var config = sanitizeTemplateConfig(currentConfig);
			config.templateName = name;
			settingsTemplates[name] = config;
			saveTemplates();
			refreshGlobalTemplateSelector();
			renderDeviceList();
			setStatus('Template "' + name + '" overwritten.', false);
		});
	}

	var saveTemplateAsButton = card.querySelector('.action-save-template-as');
	if (saveTemplateAsButton) {
		saveTemplateAsButton.addEventListener('click', function () {
			var currentConfig = readCardConfig(card);
			var suggestedName = String(currentConfig.templateName || '').trim();
			var templateName = window.prompt('Template name:', suggestedName);
			if (!templateName) {
				return;
			}
			var name = String(templateName).trim();
			if (name === '') {
				setStatus('Template name cannot be empty.', true);
				return;
			}
			var config = sanitizeTemplateConfig(currentConfig);
			config.templateName = name;
			settingsTemplates[name] = config;
			deviceConfigsById[String(deviceId)] = Object.assign({}, getConfigForDevice(deviceId), config);
			saveTemplates();
			saveDeviceConfigs();
			refreshGlobalTemplateSelector();
			renderDeviceList();
			setStatus('Template "' + name + '" saved.', false);
		});
	}

	var loadTemplateButton = card.querySelector('.action-load-template');
	if (loadTemplateButton) {
		loadTemplateButton.addEventListener('click', function () {
			var templateSelect = card.querySelector('.field-template-name');
			var templateName = templateSelect ? String(templateSelect.value || '') : '';
			if (!templateName) {
				setStatus('Select a template to load.', true);
				return;
			}
			try {
				applyTemplateToDevice(deviceId, templateName);
				saveDeviceConfigs();
				renderDeviceList();
				setStatus('Loaded template "' + templateName + '" into device ' + deviceId + '.', false);
			} catch (error) {
				setStatus(error.message || 'Failed to load template.', true);
			}
		});
	}
}

function postAction(action, data)
{
	var payload = Object.assign({ action: action }, data || {});
	return fetch(apiUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(payload)
	}).then(function (response) {
		return response.text().then(function (responseText) {
			var json = null;
			try {
				json = JSON.parse(responseText);
			} catch (error) {
				throw new Error('Server did not return JSON. Response begins with: ' + responseText.slice(0, 80));
			}

			if (!response.ok || json.ok !== true) {
				throw new Error(json.error || 'Request failed');
			}

			return json;
		});
	});
}

function scanDevices()
{
	setStatus('Scanning RTL-SDR devices...', false);
	syncDraftConfigsFromOpenCards();
	return postAction('devices', {}).then(function (result) {
		knownDetectedDevices = Array.isArray(result.devices) ? result.devices : [];
		renderDeviceList();
		if (result.warning) {
			setStatus(result.warning, true);
			return;
		}

		setStatus('Detected ' + knownDetectedDevices.length + ' RTL-SDR device(s).', false);
	}).catch(function (error) {
		setStatus(error.message, true);
	});
}

function refreshInstances()
{
	syncDraftConfigsFromOpenCards();
	return postAction('list', {}).then(function (result) {
		knownInstancesByDevice = {};
		for (var i = 0; i < result.instances.length; i++) {
			knownInstancesByDevice[String(result.instances[i].device)] = result.instances[i];
		}
		var editing = isUserEditingDeviceForm();
		if (!editing) {
			renderDeviceList();
		}
		setStatus('Loaded ' + result.instances.length + ' running instance(s).' + (editing ? ' UI refresh paused while editing.' : ''), false);
		return refreshOpenLogs(editing);
	}).catch(function (error) {
		setStatus(error.message, true);
	});
}

function startOrRetuneCard(card)
{
	var config = readCardConfig(card);
	deviceConfigsById[String(config.device)] = config;
	saveDeviceConfigs();
	setStatus('Applying config for device ' + (config.device || '?') + '...', false);
	postAction('start', config).then(function (result) {
		setStatus(result.message || 'Started.', false);
		knownInstancesByDevice = {};
		for (var i = 0; i < result.instances.length; i++) {
			knownInstancesByDevice[String(result.instances[i].device)] = result.instances[i];
		}
		fetchDeviceLogs(String(config.device), true).then(function () {
			renderDeviceList();
		});
	}).catch(function (error) {
		setStatus(error.message, true);
	});
}

function stopCard(card)
{
	var deviceId = String(card.getAttribute('data-device-id') || '').trim();
	if (!deviceId) {
		setStatus('Enter a device index before stopping.', true);
		return;
	}

	stopListeningForDevice(deviceId, true);

	setStatus('Stopping device ' + deviceId + '...', false);
	postAction('stop', { device: deviceId }).then(function (result) {
		setStatus(result.message || 'Stopped.', false);
		knownInstancesByDevice = {};
		for (var i = 0; i < result.instances.length; i++) {
			knownInstancesByDevice[String(result.instances[i].device)] = result.instances[i];
		}
		logContentByDevice[deviceId] = { logFile: '', lines: ['Device stopped.'], running: false };
		renderDeviceList();
	}).catch(function (error) {
		setStatus(error.message, true);
	});
}

function stopDeviceById(deviceId)
{
	stopListeningForDevice(deviceId, true);
	postAction('stop', { device: deviceId }).then(function (result) {
		setStatus(result.message || 'Stopped.', false);
		knownInstancesByDevice = {};
		for (var i = 0; i < result.instances.length; i++) {
			knownInstancesByDevice[String(result.instances[i].device)] = result.instances[i];
		}
		renderDeviceList();
	}).catch(function (error) {
		setStatus(error.message, true);
	});
}

function initializePage()
{
	initTheme();

	document.getElementById('themeToggleButton').addEventListener('click', function () {
		var nextTheme = document.body.classList.contains('theme-light') ? 'theme-dark' : 'theme-light';
		applyTheme(nextTheme);
		try { window.localStorage.setItem('rtlSdrTheme', nextTheme); } catch (error) {}
	});

	document.getElementById('toggleTemplateToolbarButton').addEventListener('click', function () {
		var group = document.getElementById('templateToolbarGroup');
		setTemplateToolbarVisible(group ? group.classList.contains('hidden') : false);
	});

	document.getElementById('scanDevicesButton').addEventListener('click', function () {
		scanDevices();
	});

	document.getElementById('refreshButton').addEventListener('click', function () {
		refreshInstances();
	});

	document.getElementById('stopAllButton').addEventListener('click', function () {
		setStatus('Stopping all devices...', false);
		postAction('stop_all', {}).then(function (result) {
			setStatus(result.message || 'Stopped all.', false);
			knownInstancesByDevice = {};
			renderDeviceList();
		}).catch(function (error) {
			setStatus(error.message, true);
		});
	});

	document.getElementById('applyTemplateSelectedButton').addEventListener('click', function () {
		var templateSelect = document.getElementById('globalTemplateSelect');
		var templateName = templateSelect ? String(templateSelect.value || '') : '';
		if (!templateName) {
			setStatus('Select a global template first.', true);
			return;
		}
		var targetSelect = document.getElementById('templateDeviceSelect');
		if (!targetSelect) {
			setStatus('Device target selector is unavailable.', true);
			return;
		}
		var selectedTargets = [];
		for (var i = 0; i < targetSelect.options.length; i++) {
			if (targetSelect.options[i].selected) {
				selectedTargets.push(String(targetSelect.options[i].value));
			}
		}
		if (!selectedTargets.length) {
			setStatus('Select one or more target devices.', true);
			return;
		}
		for (var j = 0; j < selectedTargets.length; j++) {
			applyTemplateToDevice(selectedTargets[j], templateName);
		}
		saveDeviceConfigs();
		renderDeviceList();
		setStatus('Applied template "' + templateName + '" to ' + selectedTargets.length + ' device(s).', false);
	});

	document.getElementById('serverModalClose').addEventListener('click', closeServerDialog);
	document.getElementById('serverModalCancel').addEventListener('click', closeServerDialog);
	document.getElementById('serverModalSave').addEventListener('click', saveServer);
	document.getElementById('serverModalDelete').addEventListener('click', deleteServer);
	document.getElementById('serverModal').addEventListener('click', function (e) {
		if (e.target === this) {
			closeServerDialog();
		}
	});

	var templatesExpanded = null;
	try {
		templatesExpanded = window.localStorage.getItem('rtlSdrTemplatesExpanded');
	} catch (error) {
		templatesExpanded = null;
	}
	setTemplateToolbarVisible(templatesExpanded === '1');

	loadUiSettingsFromServer().finally(function () {
		refreshGlobalTemplateSelector();
		refreshTemplateDeviceSelector();
		renderDeviceList();
		loadStreamServersFromServer().finally(function () {
			refreshGlobalTemplateSelector();
			refreshTemplateDeviceSelector();
			renderDeviceList();
			scanDevices().finally(function () {
			refreshInstances();
			});
		});
	});
	window.setInterval(refreshInstances, 6000);
	window.setInterval(refreshOpenLogs, 2500);
}

initializePage();
</script>
</body>
</html>
