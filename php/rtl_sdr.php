<?php

declare(strict_types=1);

$RTL_PAGE_TITLE = 'RTL-SDR Controller';
$STATE_FILE = __DIR__ . '/rtl_sdr_state.json';
$DESIRED_STATE_FILE = __DIR__ . '/rtl_sdr_desired_state.json';
$STREAMING_SERVERS_FILE = __DIR__ . '/streaming_servers.json';
$RECORDING_SERVERS_FILE = __DIR__ . '/recording_servers.json';
$UI_SETTINGS_FILE = __DIR__ . '/rtl_sdr_ui_settings.json';
$TEMPLATES_FILE = __DIR__ . '/rtl_sdr_templates.json';
$LOG_DIR = __DIR__ . '/rtl_sdr_logs';
$recordingsRoot = __DIR__ . '/recordings';

if (file_exists(__DIR__ . '/config.php')) {
	include __DIR__ . '/config.php';
}

// Auto-recovery defaults. These can be overridden in config.php.
$AUTO_RECOVERY_ENABLED = isset($AUTO_RECOVERY_ENABLED) ? $AUTO_RECOVERY_ENABLED : true;
$AUTO_RECOVERY_MAX_ATTEMPTS = isset($AUTO_RECOVERY_MAX_ATTEMPTS) ? max(1, (int)$AUTO_RECOVERY_MAX_ATTEMPTS) : 8;
$AUTO_RECOVERY_BASE_DELAY_SECONDS = isset($AUTO_RECOVERY_BASE_DELAY_SECONDS) ? max(1, (int)$AUTO_RECOVERY_BASE_DELAY_SECONDS) : 5;
$AUTO_RECOVERY_MAX_DELAY_SECONDS = isset($AUTO_RECOVERY_MAX_DELAY_SECONDS) ? max(5, (int)$AUTO_RECOVERY_MAX_DELAY_SECONDS) : 300;
$AUTO_RECOVERY_RESET_AFTER_SECONDS = isset($AUTO_RECOVERY_RESET_AFTER_SECONDS) ? max(30, (int)$AUTO_RECOVERY_RESET_AFTER_SECONDS) : 900;
$AUTO_RECOVERY_LAUNCH_ATTEMPT_DELAYS_US = isset($AUTO_RECOVERY_LAUNCH_ATTEMPT_DELAYS_US) && is_array($AUTO_RECOVERY_LAUNCH_ATTEMPT_DELAYS_US)
	? $AUTO_RECOVERY_LAUNCH_ATTEMPT_DELAYS_US
	: array(500000, 1000000, 1800000);

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

function send_text_download(string $content, string $filename): void
{
	$safeFilename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
	if (!is_string($safeFilename) || $safeFilename === '' || $safeFilename === '.' || $safeFilename === '..') {
		$safeFilename = 'rtl_sdr.log';
	}

	http_response_code(200);
	header('Content-Type: text/plain; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
	header('Cache-Control: no-cache, no-store, must-revalidate');
	header('Pragma: no-cache');
	header('Expires: 0');
	header('X-Content-Type-Options: nosniff');
	echo $content;
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

	return normalize_runtime_state($decoded);
}

function normalize_runtime_state(array $rawState): array
{
	$normalized = array();

	foreach ($rawState as $rawDeviceId => $instance) {
		if (!is_array($instance)) {
			continue;
		}

		$config = isset($instance['config']) && is_array($instance['config'])
			? $instance['config']
			: array();

		$deviceId = normalize_device_id((string)($config['device'] ?? $rawDeviceId));
		$deviceSerial = normalize_device_serial((string)($config['deviceSerial'] ?? ''));
		if ($deviceSerial !== '') {
			$deviceId = 'sn:' . $deviceSerial;
		}

		if ($deviceId === '') {
			continue;
		}

		$deviceIndex = normalize_device_index((string)($config['deviceIndex'] ?? ''));
		if ($deviceIndex === '') {
			$deviceIndex = extract_index_from_device_id((string)$rawDeviceId);
		}
		if ($deviceIndex === '') {
			$deviceIndex = extract_index_from_device_id($deviceId);
		}

		$config['device'] = $deviceId;
		if ($deviceSerial !== '') {
			$config['deviceSerial'] = $deviceSerial;
		}
		if ($deviceIndex !== '') {
			$config['deviceIndex'] = $deviceIndex;
		}
		$instance['config'] = $config;

		if (isset($normalized[$deviceId])) {
			$existingStartedAt = (int)($normalized[$deviceId]['startedAt'] ?? 0);
			$incomingStartedAt = (int)($instance['startedAt'] ?? 0);
			if ($incomingStartedAt < $existingStartedAt) {
				continue;
			}
		}

		$normalized[$deviceId] = $instance;
	}

	return $normalized;
}

function save_state(string $stateFile, array $state): bool
{
	$encoded = json_encode(normalize_runtime_state($state), JSON_PRETTY_PRINT);
	if (!is_string($encoded)) {
		return false;
	}

	return file_put_contents($stateFile, $encoded . "\n", LOCK_EX) !== false;
}

function load_desired_state(string $desiredStateFile): array
{
	if (!file_exists($desiredStateFile)) {
		return array();
	}

	$raw = file_get_contents($desiredStateFile);
	if (!is_string($raw) || trim($raw) === '') {
		return array();
	}

	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		return array();
	}

	$desired = array();
	foreach ($decoded as $rawDeviceId => $entry) {
		$deviceId = normalize_device_id((string)$rawDeviceId);
		if ($deviceId === '') {
			continue;
		}

		$running = true;
		$updatedAt = 0;
		if (is_array($entry)) {
			$running = parse_boolean_flag($entry['running'] ?? true, true);
			$updatedAt = max(0, (int)($entry['updatedAt'] ?? 0));
		} else {
			$running = parse_boolean_flag($entry, true);
		}

		$desired[$deviceId] = array(
			'running' => $running,
			'updatedAt' => $updatedAt,
		);
	}

	return $desired;
}

function save_desired_state(string $desiredStateFile, array $desiredState): bool
{
	$normalized = array();
	foreach ($desiredState as $rawDeviceId => $entry) {
		$deviceId = normalize_device_id((string)$rawDeviceId);
		if ($deviceId === '') {
			continue;
		}

		$running = true;
		$updatedAt = 0;
		if (is_array($entry)) {
			$running = parse_boolean_flag($entry['running'] ?? true, true);
			$updatedAt = max(0, (int)($entry['updatedAt'] ?? 0));
		} else {
			$running = parse_boolean_flag($entry, true);
		}

		if ($updatedAt <= 0) {
			$updatedAt = time();
		}

		$normalized[$deviceId] = array(
			'running' => $running,
			'updatedAt' => $updatedAt,
		);
	}

	$encoded = json_encode((object)$normalized, JSON_PRETTY_PRINT);
	if (!is_string($encoded)) {
		return false;
	}

	return file_put_contents($desiredStateFile, $encoded . "\n", LOCK_EX) !== false;
}

function device_is_desired_running(array $desiredState, string $deviceId, bool $default = true): bool
{
	$normalizedDeviceId = normalize_device_id($deviceId);
	if ($normalizedDeviceId === '' || !isset($desiredState[$normalizedDeviceId])) {
		return $default;
	}

	$entry = $desiredState[$normalizedDeviceId];
	if (is_array($entry)) {
		return parse_boolean_flag($entry['running'] ?? $default, $default);
	}

	return parse_boolean_flag($entry, $default);
}

function set_device_desired_running(array &$desiredState, string $deviceId, bool $running): bool
{
	$normalizedDeviceId = normalize_device_id($deviceId);
	if ($normalizedDeviceId === '') {
		return false;
	}

	$current = device_is_desired_running($desiredState, $normalizedDeviceId, true);
	if ($current === $running && isset($desiredState[$normalizedDeviceId])) {
		return false;
	}

	$desiredState[$normalizedDeviceId] = array(
		'running' => $running,
		'updatedAt' => time(),
	);

	return true;
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

function build_url_from_parts(array $parts): string
{
	$scheme = isset($parts['scheme']) ? strtolower(trim((string)$parts['scheme'])) : '';
	$host = isset($parts['host']) ? trim((string)$parts['host']) : '';
	if ($scheme === '' || $host === '') {
		return '';
	}

	$url = $scheme . '://';

	$user = isset($parts['user']) ? (string)$parts['user'] : '';
	$pass = isset($parts['pass']) ? (string)$parts['pass'] : '';
	if ($user !== '') {
		$url .= $user;
		if ($pass !== '') {
			$url .= ':' . $pass;
		}
		$url .= '@';
	}

	$url .= $host;

	if (isset($parts['port']) && (int)$parts['port'] > 0) {
		$url .= ':' . (string)((int)$parts['port']);
	}

	if (isset($parts['path']) && (string)$parts['path'] !== '') {
		$url .= (string)$parts['path'];
	}

	if (isset($parts['query']) && (string)$parts['query'] !== '') {
		$url .= '?' . (string)$parts['query'];
	}

	if (isset($parts['fragment']) && (string)$parts['fragment'] !== '') {
		$url .= '#' . (string)$parts['fragment'];
	}

	return $url;
}

function normalize_recording_upload_url(string $rawUrl): string
{
	$url = trim($rawUrl);
	if ($url === '') {
		return '';
	}

	$parts = parse_url($url);
	if (!is_array($parts)) {
		return $url;
	}

	$scheme = isset($parts['scheme']) ? strtolower(trim((string)$parts['scheme'])) : '';
	$host = isset($parts['host']) ? trim((string)$parts['host']) : '';
	if (!in_array($scheme, array('http', 'https'), true) || $host === '') {
		return $url;
	}

	$queryParams = array();
	if (isset($parts['query']) && (string)$parts['query'] !== '') {
		parse_str((string)$parts['query'], $queryParams);
		if (!is_array($queryParams)) {
			$queryParams = array();
		}
	}

	if (!isset($queryParams['api']) || trim((string)$queryParams['api']) === '') {
		$queryParams['api'] = 'recording_ingest';
	}

	$parts['query'] = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
	$rebuilt = build_url_from_parts($parts);
	return $rebuilt === '' ? $url : $rebuilt;
}

function normalize_recording_servers(array $rawServers): array
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
		$url = normalize_recording_upload_url((string)($server['url'] ?? ''));
		$username = trim((string)($server['username'] ?? ''));
		$password = (string)($server['password'] ?? '');

		if ($name === '' || $url === '') {
			continue;
		}

		$normalized[$id] = array(
			'name' => $name,
			'url' => $url,
			'username' => $username,
			'password' => $password,
		);
	}

	return $normalized;
}

function load_recording_servers(string $filePath): array
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

	return normalize_recording_servers($decoded);
}

function save_recording_servers(string $filePath, array $servers): bool
{
	$normalized = normalize_recording_servers($servers);
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
		'antennaDescriptionsBySerial' => array(),
	);
}

function normalize_templates(array $rawTemplates): array
{
	$templates = array();
	foreach ($rawTemplates as $templateName => $templateConfig) {
		$name = trim((string)$templateName);
		if ($name === '' || !is_array($templateConfig)) {
			continue;
		}
		$templateConfig['templateName'] = $name;
		$templates[$name] = $templateConfig;
	}

	return $templates;
}

function normalize_antenna_descriptions_by_serial(array $rawDescriptions): array
{
	$normalized = array();
	foreach ($rawDescriptions as $rawSerial => $rawDescription) {
		$serial = normalize_device_serial((string)$rawSerial);
		if ($serial === '') {
			continue;
		}

		$description = trim((string)$rawDescription);
		if ($description === '') {
			continue;
		}

		$description = trim(preg_replace('/\s+/', ' ', $description) ?? $description);
		if ($description === '') {
			continue;
		}

		$normalized[$serial] = $description;
	}

	return $normalized;
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

		$configSerial = normalize_device_serial((string)($config['deviceSerial'] ?? ''));
		if ($configSerial !== '') {
			$normalizedDeviceId = 'sn:' . $configSerial;
			$config['deviceSerial'] = $configSerial;
		}

		$config['device'] = $normalizedDeviceId;
		$deviceConfigs[$normalizedDeviceId] = $config;
	}
	$normalized['deviceConfigs'] = $deviceConfigs;

	$rawAntennaDescriptions = isset($rawSettings['antennaDescriptionsBySerial']) && is_array($rawSettings['antennaDescriptionsBySerial'])
		? $rawSettings['antennaDescriptionsBySerial']
		: array();
	$normalized['antennaDescriptionsBySerial'] = normalize_antenna_descriptions_by_serial($rawAntennaDescriptions);

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
	$toPersist['antennaDescriptionsBySerial'] = (object)$normalized['antennaDescriptionsBySerial'];
	$encoded = json_encode($toPersist, JSON_PRETTY_PRINT);
	if (!is_string($encoded)) {
		return false;
	}

	return file_put_contents($filePath, $encoded . "\n", LOCK_EX) !== false;
}

function merge_running_state_into_device_configs(array $deviceConfigs, array $state): array
{
	$merged = $deviceConfigs;
	foreach ($state as $rawDeviceId => $instance) {
		if (!is_array($instance)) {
			continue;
		}

		$instanceConfig = isset($instance['config']) && is_array($instance['config'])
			? $instance['config']
			: array();
		if (count($instanceConfig) === 0) {
			continue;
		}

		$deviceId = '';
		$configSerial = normalize_device_serial((string)($instanceConfig['deviceSerial'] ?? ''));
		if ($configSerial !== '') {
			$deviceId = 'sn:' . $configSerial;
		}
		if (isset($instanceConfig['device'])) {
			$deviceId = normalize_device_id((string)$instanceConfig['device']);
		}
		if ($configSerial !== '') {
			$deviceId = 'sn:' . $configSerial;
		}
		if ($deviceId === '' && isset($instance['device'])) {
			$deviceId = normalize_device_id((string)$instance['device']);
		}
		if ($deviceId === '') {
			$deviceId = normalize_device_id((string)$rawDeviceId);
		}
		if ($deviceId === '') {
			continue;
		}

		$instanceConfig['device'] = $deviceId;
		$merged[$deviceId] = $instanceConfig;
	}

	return $merged;
}

function sync_ui_settings_with_running_state(string $uiSettingsFile, array $state): array
{
	$settings = load_ui_settings($uiSettingsFile);
	$settings = normalize_ui_settings($settings);

	$currentDeviceConfigs = isset($settings['deviceConfigs']) && is_array($settings['deviceConfigs'])
		? $settings['deviceConfigs']
		: array();
	$nextDeviceConfigs = merge_running_state_into_device_configs($currentDeviceConfigs, $state);

	if (json_encode($currentDeviceConfigs) !== json_encode($nextDeviceConfigs)) {
		$settings['deviceConfigs'] = $nextDeviceConfigs;
		save_ui_settings($uiSettingsFile, $settings);
	}

	$settings['deviceConfigs'] = $nextDeviceConfigs;
	return $settings;
}

function load_templates(string $filePath, string $legacyUiSettingsFile = ''): array
{
	if (!file_exists($filePath)) {
		$seedTemplates = array();
		if ($legacyUiSettingsFile !== '' && file_exists($legacyUiSettingsFile)) {
			$legacyRaw = file_get_contents($legacyUiSettingsFile);
			if (is_string($legacyRaw) && trim($legacyRaw) !== '') {
				$legacyDecoded = json_decode($legacyRaw, true);
				if (is_array($legacyDecoded) && isset($legacyDecoded['templates']) && is_array($legacyDecoded['templates'])) {
					$seedTemplates = normalize_templates($legacyDecoded['templates']);
				}
			}
		}

		save_templates($filePath, $seedTemplates);
		return $seedTemplates;
	}

	$raw = file_get_contents($filePath);
	if (!is_string($raw) || trim($raw) === '') {
		return array();
	}

	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		return array();
	}

	return normalize_templates($decoded);
}

function save_templates(string $filePath, array $templates): bool
{
	$normalized = normalize_templates($templates);
	$encoded = json_encode((object)$normalized, JSON_PRETTY_PRINT);
	if (!is_string($encoded)) {
		return false;
	}

	return file_put_contents($filePath, $encoded . "\n", LOCK_EX) !== false;
}

function ui_settings_for_response(array $settings): array
{
	return array(
		'deviceConfigs' => (object)($settings['deviceConfigs'] ?? array()),
		'antennaDescriptionsBySerial' => (object)($settings['antennaDescriptionsBySerial'] ?? array()),
	);
}

if (!file_exists($STREAMING_SERVERS_FILE)) {
	file_put_contents($STREAMING_SERVERS_FILE, "{}\n", LOCK_EX);
}

if (!file_exists($RECORDING_SERVERS_FILE)) {
	file_put_contents($RECORDING_SERVERS_FILE, "{}\n", LOCK_EX);
}

if (!file_exists($TEMPLATES_FILE)) {
	load_templates($TEMPLATES_FILE, $UI_SETTINGS_FILE);
}

if (!file_exists($DESIRED_STATE_FILE)) {
	file_put_contents($DESIRED_STATE_FILE, "{}\n", LOCK_EX);
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

function parse_boolean_flag($value, bool $default): bool
{
	if (is_bool($value)) {
		return $value;
	}

	if (is_int($value) || is_float($value)) {
		return ((int)$value) !== 0;
	}

	$normalized = strtolower(trim((string)$value));
	if ($normalized === '') {
		return $default;
	}

	if (in_array($normalized, array('1', 'true', 'yes', 'on', 'enabled'), true)) {
		return true;
	}

	if (in_array($normalized, array('0', 'false', 'no', 'off', 'disabled'), true)) {
		return false;
	}

	return $default;
}

function derive_output_mode_label(bool $recordEnabled, bool $streamEnabled): string
{
	if ($recordEnabled && $streamEnabled) {
		return 'both';
	}

	if ($streamEnabled) {
		return 'stream';
	}

	return 'recorder';
}

function normalize_output_selection(array $input): array
{
	$hasRecordEnabled = array_key_exists('recordEnabled', $input);
	$hasStreamEnabled = array_key_exists('streamEnabled', $input);

	if ($hasRecordEnabled || $hasStreamEnabled) {
		$recordEnabled = $hasRecordEnabled ? parse_boolean_flag($input['recordEnabled'], false) : false;
		$streamEnabled = $hasStreamEnabled ? parse_boolean_flag($input['streamEnabled'], false) : false;
	} else {
		$rawOutputMode = strtolower(trim((string)($input['outputMode'] ?? 'recorder')));
		if (
			$rawOutputMode === 'both'
			|| $rawOutputMode === 'record_stream'
			|| $rawOutputMode === 'stream_record'
			|| $rawOutputMode === 'recorder_stream'
			|| $rawOutputMode === 'stream_recorder'
			|| $rawOutputMode === 'record+stream'
			|| $rawOutputMode === 'stream+record'
		) {
			$recordEnabled = true;
			$streamEnabled = true;
		} elseif ($rawOutputMode === 'stream') {
			$recordEnabled = false;
			$streamEnabled = true;
		} else {
			$recordEnabled = true;
			$streamEnabled = false;
		}
	}

	return array(
		'recordEnabled' => $recordEnabled,
		'streamEnabled' => $streamEnabled,
		'outputMode' => derive_output_mode_label($recordEnabled, $streamEnabled),
	);
}

function config_records_enabled(array $config): bool
{
	$selection = normalize_output_selection($config);
	return (bool)($selection['recordEnabled'] ?? false);
}

function config_stream_enabled(array $config): bool
{
	$selection = normalize_output_selection($config);
	return (bool)($selection['streamEnabled'] ?? false);
}

function get_auto_recovery_settings(): array
{
	global $AUTO_RECOVERY_ENABLED;
	global $AUTO_RECOVERY_MAX_ATTEMPTS;
	global $AUTO_RECOVERY_BASE_DELAY_SECONDS;
	global $AUTO_RECOVERY_MAX_DELAY_SECONDS;
	global $AUTO_RECOVERY_RESET_AFTER_SECONDS;
	global $AUTO_RECOVERY_LAUNCH_ATTEMPT_DELAYS_US;

	$launchDelays = array();
	if (is_array($AUTO_RECOVERY_LAUNCH_ATTEMPT_DELAYS_US)) {
		foreach ($AUTO_RECOVERY_LAUNCH_ATTEMPT_DELAYS_US as $delay) {
			$delayUs = max(0, (int)$delay);
			if ($delayUs <= 0) {
				continue;
			}
			$launchDelays[] = $delayUs;
		}
	}

	if (count($launchDelays) === 0) {
		$launchDelays = array(500000, 1000000, 1800000);
	}

	return array(
		'enabled' => parse_boolean_flag($AUTO_RECOVERY_ENABLED, true),
		'maxAttempts' => max(1, (int)$AUTO_RECOVERY_MAX_ATTEMPTS),
		'baseDelaySeconds' => max(1, (int)$AUTO_RECOVERY_BASE_DELAY_SECONDS),
		'maxDelaySeconds' => max(5, (int)$AUTO_RECOVERY_MAX_DELAY_SECONDS),
		'resetAfterSeconds' => max(30, (int)$AUTO_RECOVERY_RESET_AFTER_SECONDS),
		'launchAttemptDelaysUs' => $launchDelays,
	);
}

function calculate_auto_recovery_delay_seconds(int $attemptNumber, int $baseDelaySeconds, int $maxDelaySeconds): int
{
	$delay = max(1, $baseDelaySeconds);
	$exponent = max(0, $attemptNumber - 1);

	for ($i = 0; $i < $exponent; $i++) {
		$delay *= 2;
		if ($delay >= $maxDelaySeconds) {
			return $maxDelaySeconds;
		}
	}

	return min($delay, $maxDelaySeconds);
}

function append_instance_log_line(string $logDir, string $deviceId, array $instance, string $tag, string $message): void
{
	$logPath = resolve_log_path_for_device($logDir, $deviceId, array($deviceId => $instance));
	if ($logPath === '' && isset($instance['logFile'])) {
		$candidate = $logDir . '/' . basename((string)$instance['logFile']);
		$logPath = $candidate;
	}

	if ($logPath === '') {
		return;
	}

	$line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($tag) . '] ' . trim($message) . "\n";
	file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
}

function build_instance_state(array $config, array $startResult, bool $autoRecoveryEnabled, int $recoveryAttempts = 0): array
{
	return array(
		'pid' => (int)($startResult['pid'] ?? 0),
		'startedAt' => time(),
		'logFile' => (string)($startResult['logFile'] ?? ''),
		'command' => (string)($startResult['command'] ?? ''),
		'config' => $config,
		'autoRecoveryEnabled' => $autoRecoveryEnabled,
		'recoveryAttempts' => max(0, $recoveryAttempts),
		'recoveryLastFailureAt' => 0,
		'recoveryNextRetryAt' => 0,
		'recoveryLastError' => '',
	);
}

function resolve_auto_recovery_enabled_from_payload(array $payload): bool
{
	$settings = get_auto_recovery_settings();
	$default = (bool)$settings['enabled'];

	if (array_key_exists('autoRecovery', $payload)) {
		return parse_boolean_flag($payload['autoRecovery'], $default);
	}

	if (array_key_exists('autoRestart', $payload)) {
		return parse_boolean_flag($payload['autoRestart'], $default);
	}

	return $default;
}

function cleanup_stale_instances(array &$state, string $logDir, array &$desiredState, bool &$desiredStateChanged = false): bool
{
	$settings = get_auto_recovery_settings();
	$now = time();
	$changed = false;
	$desiredStateChanged = false;

	foreach ($state as $device => $instance) {
		if (!is_array($instance)) {
			unset($state[$device]);
			$changed = true;
			continue;
		}

		$config = isset($instance['config']) && is_array($instance['config']) ? $instance['config'] : array();
		$deviceId = normalize_device_id((string)($config['device'] ?? $device));
		$configSerial = normalize_device_serial((string)($config['deviceSerial'] ?? ''));
		if ($configSerial !== '') {
			$deviceId = 'sn:' . $configSerial;
		}
		if ($deviceId === '') {
			$deviceId = normalize_device_id((string)$device);
		}
		if ($deviceId === '') {
			unset($state[$device]);
			$changed = true;
			continue;
		}
		$config['device'] = $deviceId;
		if ($configSerial !== '') {
			$config['deviceSerial'] = $configSerial;
		}

		$pid = isset($instance['pid']) ? (int)$instance['pid'] : 0;
		$running = is_process_running($pid);
		$desiredRunning = device_is_desired_running($desiredState, $deviceId, true);

		if (!$desiredRunning) {
			if ($running) {
				append_instance_log_line(
					$logDir,
					$deviceId,
					$instance,
					'RECOVERY',
					'Desired state is stopped; terminating process and skipping auto-recovery.'
				);
				stop_instance_by_pid($pid);
			}

			unset($state[$device]);
			$changed = true;
			continue;
		}

		if ($running) {
			$attempts = max(0, (int)($instance['recoveryAttempts'] ?? 0));
			$startedAt = isset($instance['startedAt']) ? (int)$instance['startedAt'] : 0;
			if (
				$attempts > 0
				&& $startedAt > 0
				&& ($now - $startedAt) >= (int)$settings['resetAfterSeconds']
			) {
				$state[$device]['recoveryAttempts'] = 0;
				$state[$device]['recoveryLastFailureAt'] = 0;
				$state[$device]['recoveryNextRetryAt'] = 0;
				$state[$device]['recoveryLastError'] = '';
				$changed = true;
				append_instance_log_line(
					$logDir,
					(string)$device,
					$state[$device],
					'RECOVERY',
					'Stability window reached; crash counter reset.'
				);
			}
			continue;
		}

		if (count($config) === 0) {
			unset($state[$device]);
			$changed = true;
			continue;
		}

		$autoRecoveryEnabled = parse_boolean_flag(
			$instance['autoRecoveryEnabled'] ?? $settings['enabled'],
			(bool)$settings['enabled']
		);

		if (!$autoRecoveryEnabled) {
			unset($state[$device]);
			$changed = true;
			continue;
		}

		$attempts = max(0, (int)($instance['recoveryAttempts'] ?? 0));
		$nextRetryAt = max(0, (int)($instance['recoveryNextRetryAt'] ?? 0));

		if ($nextRetryAt > $now) {
			if ($pid !== 0 || !isset($instance['autoRecoveryEnabled'])) {
				$changed = true;
			}
			$state[$deviceId] = $instance;
			$state[$deviceId]['pid'] = 0;
			$state[$deviceId]['autoRecoveryEnabled'] = $autoRecoveryEnabled;
			if ($deviceId !== (string)$device) {
				unset($state[$device]);
			}
			continue;
		}

		if ($attempts >= (int)$settings['maxAttempts']) {
			append_instance_log_line(
				$logDir,
				$deviceId,
				$instance,
				'RECOVERY',
				'Automatic recovery stopped after reaching max attempts (' . (int)$settings['maxAttempts'] . ').'
			);
			unset($state[$device]);
			$changed = true;
			continue;
		}

		$attemptNumber = $attempts + 1;
		append_instance_log_line(
			$logDir,
			$deviceId,
			$instance,
			'RECOVERY',
			'Detected process exit; restart attempt ' . $attemptNumber . ' of ' . (int)$settings['maxAttempts'] . '.'
		);

		$startResult = start_instance($config, $logDir, (array)$settings['launchAttemptDelaysUs']);
		$runtimeConfig = isset($startResult['config']) && is_array($startResult['config'])
			? $startResult['config']
			: $config;
		if ($startResult['ok'] === true) {
			$recoveredInstance = build_instance_state($runtimeConfig, $startResult, $autoRecoveryEnabled, $attemptNumber);
			$state[$deviceId] = $recoveredInstance;
			if ($deviceId !== (string)$device) {
				unset($state[$device]);
			}
			$changed = true;
			append_instance_log_line(
				$logDir,
				$deviceId,
				$recoveredInstance,
				'RECOVERY',
				'Restart succeeded on attempt ' . $attemptNumber . '.'
			);
			continue;
		}

		$delaySeconds = calculate_auto_recovery_delay_seconds(
			$attemptNumber,
			(int)$settings['baseDelaySeconds'],
			(int)$settings['maxDelaySeconds']
		);
		$nextRetry = $now + $delaySeconds;
		$errorMessage = mask_sensitive_command_for_log((string)($startResult['error'] ?? 'Auto recovery start attempt failed.'));

		$state[$deviceId] = $instance;
		$state[$deviceId]['pid'] = 0;
		$state[$deviceId]['autoRecoveryEnabled'] = $autoRecoveryEnabled;
		$state[$deviceId]['recoveryAttempts'] = $attemptNumber;
		$state[$deviceId]['recoveryLastFailureAt'] = $now;
		$state[$deviceId]['recoveryNextRetryAt'] = $nextRetry;
		$state[$deviceId]['recoveryLastError'] = $errorMessage;
		$state[$deviceId]['config'] = $runtimeConfig;
		if (isset($startResult['logFile'])) {
			$state[$deviceId]['logFile'] = (string)$startResult['logFile'];
		}
		if (isset($startResult['command'])) {
			$state[$deviceId]['command'] = (string)$startResult['command'];
		}

		if ($deviceId !== (string)$device) {
			unset($state[$device]);
		}

		$changed = true;
		append_instance_log_line(
			$logDir,
			$deviceId,
			$state[$deviceId],
			'RECOVERY',
			'Restart attempt failed: ' . $errorMessage . ' Next retry in ' . $delaySeconds . 's.'
		);
	}

	return $changed;
}

function normalize_device_index($rawValue): string
{
	$deviceIndex = trim((string)$rawValue);
	if (!preg_match('/^[0-9]+$/', $deviceIndex)) {
		return '';
	}

	$normalized = ltrim($deviceIndex, '0');
	return $normalized === '' ? '0' : $normalized;
}

function normalize_device_id($rawValue): string
{
	$deviceId = trim((string)$rawValue);
	if ($deviceId === '') {
		return '';
	}

	if (preg_match('/^(?:sn|serial)[:=-]?(.+)$/i', $deviceId, $serialMatches) === 1) {
		$serial = normalize_device_serial((string)$serialMatches[1]);
		return $serial === '' ? '' : 'sn:' . $serial;
	}

	if (preg_match('/^(?:idx|index)[:=-]?(.+)$/i', $deviceId, $indexMatches) === 1) {
		return normalize_device_index((string)$indexMatches[1]);
	}

	$normalizedIndex = normalize_device_index($deviceId);
	if ($normalizedIndex !== '') {
		return $normalizedIndex;
	}

	$serial = normalize_device_serial($deviceId);
	if ($serial !== '') {
		return 'sn:' . $serial;
	}

	return '';
}

function normalize_device_id_list($rawValue): array
{
	$candidates = array();
	if (is_array($rawValue)) {
		$candidates = $rawValue;
	} elseif (is_string($rawValue)) {
		$trimmed = trim($rawValue);
		if ($trimmed !== '') {
			$decoded = json_decode($trimmed, true);
			if (is_array($decoded)) {
				$candidates = $decoded;
			} else {
				$split = preg_split('/\s*,\s*/', $trimmed);
				$candidates = is_array($split) ? $split : array($trimmed);
			}
		}
	}

	$normalized = array();
	foreach ($candidates as $candidate) {
		$deviceId = normalize_device_id((string)$candidate);
		if ($deviceId === '' || in_array($deviceId, $normalized, true)) {
			continue;
		}
		$normalized[] = $deviceId;
	}

	return $normalized;
}

function extract_serial_from_device_id(string $deviceId): string
{
	$normalizedDeviceId = normalize_device_id($deviceId);
	if ($normalizedDeviceId === '') {
		return '';
	}

	if (strpos($normalizedDeviceId, 'sn:') !== 0) {
		return '';
	}

	return normalize_device_serial(substr($normalizedDeviceId, 3));
}

function extract_index_from_device_id(string $deviceId): string
{
	$normalizedDeviceId = normalize_device_id($deviceId);
	if ($normalizedDeviceId === '') {
		return '';
	}

	if (strpos($normalizedDeviceId, 'sn:') === 0) {
		return '';
	}

	return normalize_device_index($normalizedDeviceId);
}

function build_device_id_from_index_and_serial(string $index, string $serial): string
{
	$normalizedSerial = normalize_device_serial($serial);
	if ($normalizedSerial !== '') {
		return 'sn:' . $normalizedSerial;
	}

	return normalize_device_index($index);
}

function resolve_device_binding(string $rawDeviceId, ?array $scan = null): array
{
	$normalizedDeviceId = normalize_device_id($rawDeviceId);
	$serialFromId = extract_serial_from_device_id($normalizedDeviceId);
	$indexFromId = extract_index_from_device_id($normalizedDeviceId);

	$resolved = array(
		'device' => $normalizedDeviceId,
		'index' => $indexFromId,
		'serial' => $serialFromId,
		'label' => '',
		'found' => false,
	);

	$scanResult = $scan;
	if (!is_array($scanResult)) {
		$scanResult = discover_rtl_devices();
	}

	$devices = isset($scanResult['devices']) && is_array($scanResult['devices'])
		? $scanResult['devices']
		: array();
	if (count($devices) === 0) {
		return $resolved;
	}

	if ($serialFromId !== '') {
		foreach ($devices as $device) {
			if (!is_array($device)) {
				continue;
			}

			$candidateSerial = normalize_device_serial((string)($device['serial'] ?? ''));
			if ($candidateSerial === '' || $candidateSerial !== $serialFromId) {
				continue;
			}

			$candidateIndex = normalize_device_index((string)($device['index'] ?? ''));
			$resolved['device'] = build_device_id_from_index_and_serial($candidateIndex, $candidateSerial);
			$resolved['index'] = $candidateIndex;
			$resolved['serial'] = $candidateSerial;
			$resolved['label'] = sanitize_device_label((string)($device['label'] ?? ''), 'RTL-SDR Device ' . $candidateIndex);
			$resolved['found'] = true;
			return $resolved;
		}

		return $resolved;
	}

	if ($indexFromId !== '') {
		foreach ($devices as $device) {
			if (!is_array($device)) {
				continue;
			}

			$candidateIndex = normalize_device_index((string)($device['index'] ?? ''));
			if ($candidateIndex === '' || $candidateIndex !== $indexFromId) {
				continue;
			}

			$candidateSerial = normalize_device_serial((string)($device['serial'] ?? ''));
			$resolved['device'] = build_device_id_from_index_and_serial($candidateIndex, $candidateSerial);
			$resolved['index'] = $candidateIndex;
			$resolved['serial'] = $candidateSerial;
			$resolved['label'] = sanitize_device_label((string)($device['label'] ?? ''), 'RTL-SDR Device ' . $candidateIndex);
			$resolved['found'] = true;
			return $resolved;
		}
	}

	return $resolved;
}

function find_state_device_key(array $state, string $requestedDeviceId): string
{
	$normalizedRequested = normalize_device_id($requestedDeviceId);
	if ($normalizedRequested === '') {
		return '';
	}

	if (isset($state[$normalizedRequested])) {
		return $normalizedRequested;
	}

	$requestedSerial = extract_serial_from_device_id($normalizedRequested);
	$requestedIndex = extract_index_from_device_id($normalizedRequested);

	foreach ($state as $rawStateKey => $instance) {
		$stateKey = (string)$rawStateKey;
		if (!is_array($instance)) {
			continue;
		}

		$normalizedStateKey = normalize_device_id($stateKey);
		if ($normalizedStateKey !== '' && $normalizedStateKey === $normalizedRequested) {
			return $stateKey;
		}

		$config = isset($instance['config']) && is_array($instance['config']) ? $instance['config'] : array();
		$configDevice = normalize_device_id((string)($config['device'] ?? ''));
		$configSerial = normalize_device_serial((string)($config['deviceSerial'] ?? ''));
		$configIndex = normalize_device_index((string)($config['deviceIndex'] ?? ''));

		if ($requestedSerial !== '') {
			$stateSerial = extract_serial_from_device_id($normalizedStateKey);
			$configDeviceSerial = extract_serial_from_device_id($configDevice);
			if ($requestedSerial === $configSerial || $requestedSerial === $stateSerial || $requestedSerial === $configDeviceSerial) {
				return $stateKey;
			}
			continue;
		}

		if ($requestedIndex !== '') {
			$stateIndex = extract_index_from_device_id($normalizedStateKey);
			$configDeviceIndex = extract_index_from_device_id($configDevice);
			if ($requestedIndex === $configIndex || $requestedIndex === $stateIndex || $requestedIndex === $configDeviceIndex) {
				return $stateKey;
			}
		}
	}

	$binding = resolve_device_binding($normalizedRequested);
	$resolvedDeviceId = normalize_device_id((string)($binding['device'] ?? ''));
	if ($resolvedDeviceId !== '' && isset($state[$resolvedDeviceId])) {
		return $resolvedDeviceId;
	}

	return '';
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

function normalize_device_serial(string $rawSerial): string
{
	$serial = trim($rawSerial);
	if ($serial === '') {
		return '';
	}

	$serial = preg_replace('/[^A-Za-z0-9._-]+/', '', $serial) ?? '';
	$serial = strtoupper(trim($serial));
	return $serial;
}

function extract_device_serial(string $text): string
{
	if (preg_match('/(?:^|[,\s])SN:\s*([A-Za-z0-9._-]+)/i', $text, $matches) !== 1) {
		return '';
	}

	return normalize_device_serial((string)$matches[1]);
}

function lookup_device_serial(string $deviceId): string
{
	$binding = resolve_device_binding($deviceId);
	$serial = normalize_device_serial((string)($binding['serial'] ?? ''));
	if ($serial !== '') {
		return $serial;
	}

	return extract_serial_from_device_id((string)($binding['device'] ?? ''));
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
	if (!is_string($masked)) {
		$masked = $command;
	}
	$masked = preg_replace("/(https?:\/\/[^:\\s\"'\/]+:)[^@\\s\"'\/]+@/i", '$1***@', $masked);
	if (is_string($masked)) {
		$masked = preg_replace("/(--user\\s+)(\"[^\"]*\"|'[^']*'|\\S+)/i", '$1***:***', $masked);
	}
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

	$matches = array();
	$tokens = array(
		sanitize_device_id_for_filename($deviceId),
		trim((string)$deviceId),
	);
	$tokens = array_values(array_unique(array_filter($tokens, static function ($value): bool {
		return trim((string)$value) !== '';
	})));

	foreach ($tokens as $token) {
		$found = glob($logDir . '/rtl_sdr_device_' . $token . '_*.log');
		if (is_array($found) && count($found) > 0) {
			$matches = array_merge($matches, $found);
		}
	}

	if (!is_array($matches) || count($matches) === 0) {
		return '';
	}

	rsort($matches, SORT_STRING);
	return (string)$matches[0];
}

function cleanup_stale_logs_by_device(string $logDir): int
{
	if (!is_dir($logDir)) {
		return 0;
	}

	$matches = glob($logDir . '/rtl_sdr_device_*_*.log');
	if (!is_array($matches) || count($matches) < 2) {
		return 0;
	}

	$newestPathByDevice = array();
	$newestStampByDevice = array();

	foreach ($matches as $logPath) {
		$baseName = basename((string)$logPath);
		$parts = array();
		if (preg_match('/^rtl_sdr_device_(.+)_([0-9]{8}_[0-9]{6})\.log$/', $baseName, $parts) !== 1) {
			continue;
		}

		$deviceToken = (string)$parts[1];
		$timestampToken = (string)$parts[2];
		if (!isset($newestStampByDevice[$deviceToken]) || strcmp($timestampToken, (string)$newestStampByDevice[$deviceToken]) > 0) {
			$newestStampByDevice[$deviceToken] = $timestampToken;
			$newestPathByDevice[$deviceToken] = (string)$logPath;
		}
	}

	if (count($newestPathByDevice) === 0) {
		return 0;
	}

	$deletedCount = 0;
	foreach ($matches as $logPath) {
		$baseName = basename((string)$logPath);
		$parts = array();
		if (preg_match('/^rtl_sdr_device_(.+)_([0-9]{8}_[0-9]{6})\.log$/', $baseName, $parts) !== 1) {
			continue;
		}

		$deviceToken = (string)$parts[1];
		$newestPath = isset($newestPathByDevice[$deviceToken]) ? (string)$newestPathByDevice[$deviceToken] : '';
		if ($newestPath !== '' && $newestPath !== (string)$logPath) {
			if (@unlink((string)$logPath)) {
				$deletedCount++;
			}
		}
	}

	return $deletedCount;
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

function build_log_payload_for_device(string $logDir, string $deviceId, array $state, int $maxLines): array
{
	$requestedDeviceId = normalize_device_id($deviceId);
	if ($requestedDeviceId === '') {
		$requestedDeviceId = trim($deviceId);
	}

	$stateDeviceKey = find_state_device_key($state, $requestedDeviceId);
	$resolvedDeviceId = $requestedDeviceId;
	if ($stateDeviceKey !== '' && isset($state[$stateDeviceKey]) && is_array($state[$stateDeviceKey])) {
		$instance = $state[$stateDeviceKey];
		$config = isset($instance['config']) && is_array($instance['config']) ? $instance['config'] : array();
		$configDeviceId = normalize_device_id((string)($config['device'] ?? $stateDeviceKey));
		$configSerial = normalize_device_serial((string)($config['deviceSerial'] ?? ''));
		if ($configSerial !== '') {
			$configDeviceId = 'sn:' . $configSerial;
		}
		if ($configDeviceId !== '') {
			$resolvedDeviceId = $configDeviceId;
		}
	}

	$lookupDeviceId = $stateDeviceKey !== '' ? $stateDeviceKey : $resolvedDeviceId;
	$logPath = resolve_log_path_for_device($logDir, $lookupDeviceId, $state);
	$running = false;
	if ($stateDeviceKey !== '' && isset($state[$stateDeviceKey])) {
		$running = is_process_running((int)($state[$stateDeviceKey]['pid'] ?? 0));
	}

	return array(
		'device' => $resolvedDeviceId,
		'running' => $running,
		'logFile' => $logPath === '' ? '' : basename($logPath),
		'lines' => read_log_lines($logPath, $maxLines),
	);
}

function force_release_device(string $deviceId): void
{
	$runtimeDeviceIndex = normalize_device_index($deviceId);
	if ($runtimeDeviceIndex === '') {
		$runtimeDeviceIndex = trim($deviceId);
	}
	if ($runtimeDeviceIndex === '') {
		return;
	}

	$pattern = 'rtl_fm .* -d ' . preg_quote($runtimeDeviceIndex, '/') . '([[:space:]]|$)';
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

function summarize_frequency_for_stream_label(string $frequency): string
{
	$normalized = trim(preg_replace('/\s+/', ' ', $frequency) ?? '');
	if ($normalized === '') {
		return 'Scanning';
	}

	if (strpos($normalized, '-') !== false) {
		return $normalized;
	}

	$parts = preg_split('/\s+/', $normalized);
	if (!is_array($parts)) {
		return $normalized;
	}

	$parts = array_values(array_filter($parts, static function ($part): bool {
		return trim((string)$part) !== '';
	}));

	if (count($parts) > 1) {
		return 'Scanning';
	}

	return $normalized;
}

function normalize_config(array $input, string $defaultOutputDir): array
{
	$deviceSerialHint = normalize_device_serial((string)($input['deviceSerial'] ?? ''));
	$deviceId = normalize_device_id($input['device'] ?? '');
	if ($deviceId === '' && $deviceSerialHint !== '') {
		$deviceId = 'sn:' . $deviceSerialHint;
	}
	if ($deviceId === '') {
		throw new RuntimeException('Device identifier is required.');
	}

	$deviceBinding = resolve_device_binding($deviceId);
	$deviceId = normalize_device_id((string)($deviceBinding['device'] ?? $deviceId));
	$deviceIndex = normalize_device_index((string)($deviceBinding['index'] ?? ''));
	$deviceSerial = normalize_device_serial((string)($deviceBinding['serial'] ?? ''));
	if ($deviceSerial === '' && $deviceSerialHint !== '') {
		$deviceSerial = $deviceSerialHint;
	}
	if ($deviceSerial === '') {
		$deviceSerial = extract_serial_from_device_id($deviceId);
	}

	if (extract_serial_from_device_id($deviceId) !== '' && $deviceIndex === '') {
		throw new RuntimeException('Selected device serial is not currently attached. Re-scan devices and retry.');
	}

	$frequency = trim((string)($input['frequency'] ?? ''));
	if (!preg_match('/^[0-9]+(?:\.[0-9]+)?[kKmMgG]?(?:[\s\-][0-9]+(?:\.[0-9]+)?[kKmMgG]?)*$/', $frequency)) {
		throw new RuntimeException('Frequency format is invalid. Examples: 146.520M, 146.435M 146.560M, 146.400M-146.600M');
	}
	$streamFrequencyLabel = summarize_frequency_for_stream_label($frequency);

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

	$dcsRaw = strtoupper(trim((string)($input['dcs'] ?? ($input['dcsCode'] ?? ''))));
	if ($dcsRaw === 'OFF' || $dcsRaw === 'NONE') {
		$dcsRaw = '';
	}
	$dcsCode = '';
	if ($dcsRaw !== '') {
		if (preg_match('/^D?([0-7]{1,3})(?:[NI])?$/', $dcsRaw, $dcsMatches) !== 1) {
			throw new RuntimeException('DCS must be an octal code (examples: 023, D023N, D023I).');
		}
		$dcsCode = str_pad((string)$dcsMatches[1], 3, '0', STR_PAD_LEFT);
	}

	$ctcssRaw = strtoupper(trim((string)($input['ctcss'] ?? ($input['ctcssHz'] ?? ''))));
	if ($ctcssRaw !== '') {
		$ctcssRaw = preg_replace('/\s+/', '', $ctcssRaw) ?? $ctcssRaw;
		$ctcssRaw = preg_replace('/HZ$/', '', $ctcssRaw) ?? $ctcssRaw;
		$ctcssRaw = preg_replace('/^CTCSS[:=-]?/', '', $ctcssRaw) ?? $ctcssRaw;
	}
	if ($ctcssRaw === 'OFF' || $ctcssRaw === 'NONE') {
		$ctcssRaw = '';
	}
	$ctcssTone = '';
	if ($ctcssRaw !== '') {
		if (preg_match('/^C?([0-9]{2,3}(?:\.[0-9]{1,2})?)$/', $ctcssRaw, $ctcssMatches) !== 1) {
			throw new RuntimeException('CTCSS must be a tone in Hz (examples: 100.0, C100.0, 100).');
		}

		$ctcssValue = (float)$ctcssMatches[1];
		if ($ctcssValue < 60.0 || $ctcssValue > 300.0) {
			throw new RuntimeException('CTCSS tone must be between 60.0 and 300.0 Hz.');
		}

		$ctcssTone = number_format($ctcssValue, 1, '.', '');
	}

	$rawBiasT = $input['biasT'] ?? ($input['bias_t'] ?? '0');
	if (is_bool($rawBiasT)) {
		$biasTEnabled = $rawBiasT;
	} else {
		$normalizedBiasT = strtolower(trim((string)$rawBiasT));
		$biasTEnabled = in_array($normalizedBiasT, array('1', 'true', 'yes', 'on', 'enabled'), true);
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

	$outputSelection = normalize_output_selection($input);
	$recordEnabled = (bool)($outputSelection['recordEnabled'] ?? false);
	$streamEnabled = (bool)($outputSelection['streamEnabled'] ?? false);
	if (!$recordEnabled && !$streamEnabled) {
		throw new RuntimeException('Enable at least one output: Record or Stream.');
	}
	$outputMode = (string)($outputSelection['outputMode'] ?? derive_output_mode_label($recordEnabled, $streamEnabled));

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
		$streamDeviceLabel = $deviceIndex !== '' ? $deviceIndex : $deviceId;
		$streamName = 'RTLSDR Device ' . $streamDeviceLabel . ' (' . strtoupper($mode) . ' ' . $streamFrequencyLabel . ')';
	}

	if ($streamEnabled) {
		if ($streamTarget === '') {
			throw new RuntimeException('Target server:port is required when Stream output is enabled.');
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

	$afterRecordAction = strtolower(trim((string)($input['afterRecordAction'] ?? '')));
	$postCommandArg = trim((string)($input['postCommandArg'] ?? ($input['postCommand'] ?? '')));
	$recordingServerId = trim((string)($input['recordingServerId'] ?? ''));
	$recordingUploadUrl = normalize_recording_upload_url((string)($input['recordingUploadUrl'] ?? ''));
	$recordingUploadUsername = trim((string)($input['recordingUploadUsername'] ?? ''));
	$recordingUploadPassword = trim((string)($input['recordingUploadPassword'] ?? ''));

	if ($afterRecordAction === '') {
		$afterRecordAction = $postCommandArg !== '' ? 'command' : 'none';
	}

	$allowedAfterRecordActions = array('none', 'upload', 'upload_delete', 'command');
	if (!in_array($afterRecordAction, $allowedAfterRecordActions, true)) {
		throw new RuntimeException('After Record mode must be one of: ' . implode(', ', $allowedAfterRecordActions));
	}

	$usesUploadAfterRecord = in_array($afterRecordAction, array('upload', 'upload_delete'), true);

	if (!$recordEnabled) {
		$afterRecordAction = 'none';
		$postCommandArg = '';
		$recordingServerId = '';
		$recordingUploadUrl = '';
		$recordingUploadUsername = '';
		$recordingUploadPassword = '';
	}

	if ($recordEnabled && $afterRecordAction === 'command' && $postCommandArg === '') {
		throw new RuntimeException('After Record command argument is required in Run Command mode.');
	}

	if ($recordEnabled && $usesUploadAfterRecord) {
		if ($recordingUploadUrl === '') {
			throw new RuntimeException('Upload server URL is required in After Record Upload mode.');
		}

		$uploadUrlValid = filter_var($recordingUploadUrl, FILTER_VALIDATE_URL);
		if ($uploadUrlValid === false) {
			throw new RuntimeException('Upload server URL is invalid. Example: https://host/recordings/');
		}

		$uploadUrlParts = parse_url($recordingUploadUrl);
		$uploadScheme = isset($uploadUrlParts['scheme']) ? strtolower((string)$uploadUrlParts['scheme']) : '';
		if (!in_array($uploadScheme, array('http', 'https'), true)) {
			throw new RuntimeException('Upload server URL must start with http:// or https://');
		}

		if (($recordingUploadUsername === '' && $recordingUploadPassword !== '') || ($recordingUploadUsername !== '' && $recordingUploadPassword === '')) {
			throw new RuntimeException('Provide both upload username and password, or leave both empty.');
		}

		if ($recordingUploadUsername !== '' && preg_match('/[\s\r\n]/', $recordingUploadUsername)) {
			throw new RuntimeException('Upload username contains invalid whitespace characters.');
		}

		if ($recordingUploadPassword !== '' && preg_match('/[\s\r\n]/', $recordingUploadPassword)) {
			throw new RuntimeException('Upload password contains invalid whitespace characters.');
		}
	}

	return array(
		'device' => $deviceId,
		'deviceIndex' => $deviceIndex,
		'frequency' => $frequency,
		'mode' => $mode,
		'rtlBandwidth' => $rtlBandwidth,
		'sampleRate' => $sampleRate,
		'squelch' => (int)$squelch,
		'dcs' => $dcsCode,
		'ctcss' => $ctcssTone,
		'gain' => $gain,
		'biasT' => $biasTEnabled ? 1 : 0,
		'threshold' => $thresholdValue,
		'silence' => $silenceValue,
		'outputMode' => $outputMode,
		'recordEnabled' => $recordEnabled,
		'streamEnabled' => $streamEnabled,
		'streamFormat' => $streamFormat,
		'streamBitrate' => $streamBitrateValue,
		'streamTarget' => $streamTarget,
		'streamMount' => $streamMount,
		'streamUsername' => $streamUsername,
		'streamPassword' => $streamPassword,
		'outputDir' => $outputDir,
		'streamName' => $streamName,
		'deviceSerial' => $deviceSerial,
		'afterRecordAction' => $afterRecordAction,
		'postCommandArg' => $postCommandArg,
		'postCommand' => $postCommandArg,
		'recordingServerId' => $recordingServerId,
		'recordingUploadUrl' => $recordingUploadUrl,
		'recordingUploadUsername' => $recordingUploadUsername,
		'recordingUploadPassword' => $recordingUploadPassword,
	);
}

function rms_cast_recorder_supports_stdout_padding(): bool
{
	static $supports = null;
	if ($supports !== null) {
		return $supports;
	}

	if (!command_exists('rms-cast-recorder')) {
		$supports = false;
		return $supports;
	}

	$helpOutput = shell_exec('bash -lc ' . escapeshellarg('rms-cast-recorder --help 2>&1'));
	if (!is_string($helpOutput) || trim($helpOutput) === '') {
		$supports = false;
		return $supports;
	}

	$supports =
		stripos($helpOutput, '--stdout') !== false
		&& stripos($helpOutput, '--stdout-raw') !== false
		&& stripos($helpOutput, '--stdout-pad') !== false;

	return $supports;
}

function rms_cast_recorder_supports_ctcss(): bool
{
	static $supports = null;
	if ($supports !== null) {
		return $supports;
	}

	if (!command_exists('rms-cast-recorder')) {
		$supports = false;
		return $supports;
	}

	$helpOutput = shell_exec('bash -lc ' . escapeshellarg('rms-cast-recorder --help 2>&1'));
	if (!is_string($helpOutput) || trim($helpOutput) === '') {
		$supports = false;
		return $supports;
	}

	$supports = stripos($helpOutput, '--ctcss') !== false;
	return $supports;
}

function build_rms_stdout_pad_conditioner_command(int $sampleRate, string $dcsCode = '', string $ctcssTone = ''): string
{
	$conditionerCommand = array(
		'rms-cast-recorder',
		'--stdin',
		'--stdin-raw',
		'--stdin-rate',
		(string)$sampleRate,
		'--stdin-channels',
		'1',
		'--stdin-bits',
		'16',
		'-r',
		(string)$sampleRate,
		'-t',
		'-120',
		'-s',
		'600',
	);

	if ($dcsCode !== '') {
		$conditionerCommand[] = '--dcs';
		$conditionerCommand[] = $dcsCode;
	}

	if ($ctcssTone !== '') {
		$conditionerCommand[] = '--ctcss';
		$conditionerCommand[] = $ctcssTone;
	}

	array_push(
		$conditionerCommand,
		'--stdout',
		'--stdout-raw',
		'--stdout-rate',
		(string)$sampleRate,
		'--stdout-channels',
		'1',
		'--stdout-bits',
		'16',
		'--stdout-pad'
	);

	return command_from_parts($conditionerCommand);
}

function build_stream_input_conditioner_command(array $config): string
{
	$sampleRate = max(1, (int)$config['rtlBandwidth']);
	$dcsCode = trim((string)($config['dcs'] ?? ''));
	$ctcssTone = trim((string)($config['ctcss'] ?? ''));

	// Use recorder's raw stdout + pad mode as a lightweight conditioner for ffmpeg.
	// Threshold/silence values keep the gate effectively open while stdout pad
	// smooths upstream rtl_fm stalls.
	return build_rms_stdout_pad_conditioner_command($sampleRate, $dcsCode, $ctcssTone);
}

function build_recorder_input_conditioner_command(array $config): string
{
	$sampleRate = max(1, (int)$config['rtlBandwidth']);

	// Recorder mode still needs a continuous PCM stream so silence can elapse when
	// rtl_fm stops writing under squelch. Use recorder-native stdout pad to keep
	// silence timing inside the recorder toolchain.
	return build_rms_stdout_pad_conditioner_command($sampleRate);
}

function build_recording_recorder_command(array $config, bool $enableStdout = false): string
{
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
		build_output_display_name($config),
	);

	$dcsCode = trim((string)($config['dcs'] ?? ''));
	if ($dcsCode !== '') {
		$recorderCommand[] = '--dcs';
		$recorderCommand[] = $dcsCode;
	}

	$ctcssTone = trim((string)($config['ctcss'] ?? ''));
	if ($ctcssTone !== '') {
		$recorderCommand[] = '--ctcss';
		$recorderCommand[] = $ctcssTone;
	}

	$afterRecordHook = build_after_record_hook_argument($config);
	if ($afterRecordHook !== '') {
		$recorderCommand[] = '-x';
		$recorderCommand[] = $afterRecordHook;
	}

	if ($enableStdout) {
		array_push(
			$recorderCommand,
			'--stdout',
			'--stdout-raw',
			'--stdout-rate',
			(string)$config['rtlBandwidth'],
			'--stdout-channels',
			'1',
			'--stdout-bits',
			'16',
			'--stdout-pad'
		);
	}

	return command_from_parts($recorderCommand);
}

function build_signal_description(array $config): string
{
	$frequencyLabel = summarize_frequency_for_stream_label((string)$config['frequency']);
	$description = $frequencyLabel . ' BW ' . (string)$config['rtlBandwidth'] . ' ' . strtoupper((string)$config['mode']);
	$deviceSerial = normalize_device_serial((string)($config['deviceSerial'] ?? ''));
	if ($deviceSerial !== '') {
		$description .= ' RTL-SN ' . $deviceSerial;
	}

	return $description;
}

function build_output_display_name(array $config): string
{
	$name = trim((string)($config['streamName'] ?? ''));
	$description = build_signal_description($config);
	if ($name === '') {
		return $description;
	}

	return $name . ' - ' . $description;
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
	$description = build_signal_description($config);
	$displayName = build_output_display_name($config);
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
		$displayName,
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

function sanitize_device_id_for_filename(string $deviceId): string
{
	$normalized = normalize_device_id($deviceId);
	if ($normalized === '') {
		$normalized = trim($deviceId);
	}

	$token = preg_replace('/[^A-Za-z0-9._-]+/', '_', $normalized) ?? '';
	$token = trim($token, '_');
	return $token === '' ? 'unknown' : $token;
}

function refresh_runtime_device_binding_for_start(array $config): array
{
	$deviceId = normalize_device_id((string)($config['device'] ?? ''));
	$deviceSerial = normalize_device_serial((string)($config['deviceSerial'] ?? ''));
	if ($deviceId === '' && $deviceSerial !== '') {
		$deviceId = 'sn:' . $deviceSerial;
	}
	if ($deviceId === '') {
		return array('ok' => false, 'error' => 'Device identifier is missing from config.');
	}

	$binding = resolve_device_binding($deviceId);
	$resolvedDeviceId = normalize_device_id((string)($binding['device'] ?? $deviceId));
	$resolvedIndex = normalize_device_index((string)($binding['index'] ?? ($config['deviceIndex'] ?? '')));
	$resolvedSerial = normalize_device_serial((string)($binding['serial'] ?? $deviceSerial));

	if ($resolvedSerial === '') {
		$resolvedSerial = extract_serial_from_device_id($resolvedDeviceId);
	}

	if (extract_serial_from_device_id($resolvedDeviceId) !== '' && $resolvedIndex === '') {
		return array('ok' => false, 'error' => 'Configured serial ' . extract_serial_from_device_id($resolvedDeviceId) . ' is not currently attached.');
	}

	if ($resolvedDeviceId === '') {
		return array('ok' => false, 'error' => 'Failed to resolve device identifier.');
	}

	$config['device'] = $resolvedDeviceId;
	$config['deviceIndex'] = $resolvedIndex;
	$config['deviceSerial'] = $resolvedSerial;

	return array('ok' => true, 'config' => $config);
}

function recording_upload_hook_script_path(): string
{
	return __DIR__ . '/rtl_upload_hook.sh';
}

function embedded_recording_upload_hook_script(): string
{
	return <<<'SH'
#!/bin/sh
set -eu

delete_after_upload=0
upload_url=""
upload_user=""
upload_pass=""
expect_user_token=0
wav_file=""

for arg in "$@"; do
	if [ "$expect_user_token" -eq 1 ]; then
		upload_user="${arg%%:*}"
		upload_pass="${arg#*:}"
		if [ "$upload_user" = "$arg" ]; then
			upload_pass=""
		fi
		expect_user_token=0
		continue
	fi

	case "$arg" in
		--delete)
			delete_after_upload=1
			continue
			;;
		--user)
			expect_user_token=1
			continue
			;;
		http://*|https://*)
			if [ -z "$upload_url" ]; then
				upload_url="$arg"
			fi
			continue
			;;
	esac

	if [ -z "$wav_file" ] && [ -f "$arg" ]; then
		wav_file="$arg"
	fi
done

if [ "$expect_user_token" -eq 1 ]; then
	exit 64
fi

if [ -z "$wav_file" ]; then
	for arg in "$@"; do
		case "$arg" in
			*.wav)
				wav_file="$arg"
				break
				;;
		esac
	done
fi

if [ -z "$wav_file" ] || [ -z "$upload_url" ]; then
	exit 64
fi

if [ -n "$upload_user" ] || [ -n "$upload_pass" ]; then
	curl -fsS --retry 2 --retry-delay 1 --connect-timeout 15 --max-time 300 -X POST --user "$upload_user:$upload_pass" -F "recording=@$wav_file" "$upload_url"
else
	curl -fsS --retry 2 --retry-delay 1 --connect-timeout 15 --max-time 300 -X POST -F "recording=@$wav_file" "$upload_url"
fi

if [ "$delete_after_upload" -eq 1 ]; then
	rm -f -- "$wav_file"
fi
SH;
}

function ensure_recording_upload_hook_script(string $scriptPath): bool
{
	$embeddedScript = embedded_recording_upload_hook_script() . "\n";
	$currentScript = file_exists($scriptPath) ? file_get_contents($scriptPath) : false;

	if (!is_string($currentScript) || $currentScript !== $embeddedScript) {
		if (file_put_contents($scriptPath, $embeddedScript, LOCK_EX) === false) {
			return false;
		}
	}

	if (is_executable($scriptPath)) {
		return true;
	}

	if (!@chmod($scriptPath, 0755) && !is_executable($scriptPath)) {
		return false;
	}

	return true;
}

function build_recording_upload_hook_command(array $config, bool $deleteAfterUpload): string
{
	$uploadUrl = trim((string)($config['recordingUploadUrl'] ?? ''));
	$uploadUsername = trim((string)($config['recordingUploadUsername'] ?? ''));
	$uploadPassword = (string)($config['recordingUploadPassword'] ?? '');
	$hookScript = recording_upload_hook_script_path();
	$parts = array($hookScript, $uploadUrl);

	if ($uploadUsername !== '' && $uploadPassword !== '') {
		$parts[] = '--user';
		$parts[] = $uploadUsername . ':' . $uploadPassword;
	}

	if ($deleteAfterUpload) {
		$parts[] = '--delete';
	}

	return command_from_parts($parts);
}

function build_upload_after_record_command(array $config): string
{
	return build_recording_upload_hook_command($config, false);
}

function build_upload_and_delete_after_record_command(array $config): string
{
	return build_recording_upload_hook_command($config, true);
}

function build_after_record_hook_argument(array $config): string
{
	$action = strtolower(trim((string)($config['afterRecordAction'] ?? 'none')));
	if ($action === 'command') {
		return trim((string)($config['postCommandArg'] ?? ($config['postCommand'] ?? '')));
	}

	if ($action === 'upload') {
		return build_upload_after_record_command($config);
	}

	if ($action === 'upload_delete') {
		return build_upload_and_delete_after_record_command($config);
	}

	return '';
}

function build_pipeline_command(array $config): string
{
	$runtimeDeviceIndex = normalize_device_index((string)($config['deviceIndex'] ?? ''));
	if ($runtimeDeviceIndex === '') {
		$runtimeDeviceIndex = normalize_device_index((string)($config['device'] ?? ''));
	}
	if ($runtimeDeviceIndex === '') {
		$runtimeDeviceIndex = (string)($config['device'] ?? '0');
	}

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
		$runtimeDeviceIndex,
	);

	if ($config['gain'] !== '' && strtolower((string)$config['gain']) !== 'auto') {
		$rtlCommand[] = '-g';
		$rtlCommand[] = (string)$config['gain'];
	}

	if ((int)($config['biasT'] ?? 0) === 1) {
		$rtlCommand[] = '-T';
	}

	$pipeline = command_from_parts($rtlCommand);
	$recordEnabled = config_records_enabled($config);
	$streamEnabled = config_stream_enabled($config);

	if ($recordEnabled && $streamEnabled) {
		$pipeline .= ' | ' . build_recording_recorder_command($config, true);
		$pipeline .= ' | ' . build_stream_command($config);
		return $pipeline;
	}

	if ($streamEnabled) {
		$pipeline .= ' | ' . build_stream_input_conditioner_command($config);
		$pipeline .= ' | ' . build_stream_command($config);
		return $pipeline;
	}

	$pipeline .= ' | ' . build_recorder_input_conditioner_command($config);
	$pipeline .= ' | ' . build_recording_recorder_command($config, false);
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

function start_instance(array $config, string $logDir, ?array $attemptDelaysUs = null): array
{
	$recorderAvailable = command_exists('rms-cast-recorder');
	$dcsCode = trim((string)($config['dcs'] ?? ''));
	$ctcssTone = trim((string)($config['ctcss'] ?? ''));
	$bindingResult = refresh_runtime_device_binding_for_start($config);
	if (($bindingResult['ok'] ?? false) !== true || !isset($bindingResult['config']) || !is_array($bindingResult['config'])) {
		return array('ok' => false, 'error' => (string)($bindingResult['error'] ?? 'Failed to resolve runtime device binding.'));
	}
	$config = $bindingResult['config'];
	$recordEnabled = config_records_enabled($config);
	$streamEnabled = config_stream_enabled($config);
	$uploadModeEnabled =
		$recordEnabled
		&& in_array(strtolower((string)($config['afterRecordAction'] ?? 'none')), array('upload', 'upload_delete'), true);
	$recorderSupportsStdoutPad = $recorderAvailable && rms_cast_recorder_supports_stdout_padding();

	if ($streamEnabled) {
		if (!command_exists('ffmpeg')) {
			return array('ok' => false, 'error' => 'ffmpeg is required when Stream output is enabled but was not found in PATH.');
		}

		if (!$recorderAvailable) {
			return array('ok' => false, 'error' => 'rms-cast-recorder is required for Stream output conditioning (--stdout-pad) but was not found in PATH.');
		}
	}

	if ($recordEnabled && !$recorderAvailable) {
		return array('ok' => false, 'error' => 'rms-cast-recorder is required when Record output is enabled but was not found in PATH.');
	}

	if ($ctcssTone !== '' && !rms_cast_recorder_supports_ctcss()) {
		return array('ok' => false, 'error' => 'CTCSS is configured but this rms-cast-recorder build does not support --ctcss.');
	}

	if (!$recorderSupportsStdoutPad) {
		if ($streamEnabled) {
			return array('ok' => false, 'error' => 'Stream output requires rms-cast-recorder support for --stdout-raw and --stdout-pad. Upgrade rms-cast-recorder and retry.');
		}

		return array('ok' => false, 'error' => 'Record output requires rms-cast-recorder support for --stdout-raw and --stdout-pad so silence detection can stay inside rms-cast-recorder. Upgrade rms-cast-recorder and retry.');
	}

	if ($uploadModeEnabled && !command_exists('curl')) {
		return array('ok' => false, 'error' => 'curl is required for After Record Upload modes but was not found in PATH.');
	}

	if ($uploadModeEnabled) {
		$hookScriptPath = recording_upload_hook_script_path();
		if (!ensure_recording_upload_hook_script($hookScriptPath)) {
			return array('ok' => false, 'error' => 'Failed to create upload hook script: ' . $hookScriptPath);
		}
	}

	if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
		return array('ok' => false, 'error' => 'Failed to create log directory: ' . $logDir);
	}

	if ($recordEnabled && !is_dir((string)$config['outputDir']) && !mkdir((string)$config['outputDir'], 0775, true) && !is_dir((string)$config['outputDir'])) {
		return array('ok' => false, 'error' => 'Failed to create output directory: ' . $config['outputDir']);
	}

	$pipelineCommand = build_pipeline_command($config);
	$pipelineCommandForLog = mask_sensitive_command_for_log($pipelineCommand);
	$logFileName = 'rtl_sdr_device_' . sanitize_device_id_for_filename((string)$config['device']) . '_' . date('Ymd_His') . '.log';
	$logPath = $logDir . '/' . $logFileName;
	$launchEntry = '[' . date('Y-m-d H:i:s') . '] [LAUNCH] ' . $pipelineCommandForLog . "\n";
	file_put_contents($logPath, $launchEntry, FILE_APPEND | LOCK_EX);
	$attemptDelays = is_array($attemptDelaysUs) && count($attemptDelaysUs) > 0
		? $attemptDelaysUs
		: array(900000, 1800000, 3000000);
	$normalizedAttemptDelays = array();
	foreach ($attemptDelays as $delayUs) {
		$delay = max(0, (int)$delayUs);
		$normalizedAttemptDelays[] = $delay;
	}
	$attemptDelays = $normalizedAttemptDelays;
	$lastError = 'Failed to launch pipeline process.';
	$pid = 0;

	for ($attempt = 0; $attempt < count($attemptDelays); $attempt++) {
		$runtimeDeviceIndex = (string)($config['deviceIndex'] ?? $config['device']);
		force_release_device($runtimeDeviceIndex);
		$pid = launch_pipeline_process($pipelineCommand, $logPath);

		if ($pid <= 0) {
			$lastError = 'Failed to launch pipeline process.';
			usleep($attemptDelays[$attempt]);
			continue;
		}

		usleep(900000);
		if (is_process_running($pid)) {
			return array('ok' => true, 'pid' => $pid, 'logFile' => $logFileName, 'command' => $pipelineCommandForLog, 'config' => $config);
		}

		$excerpt = read_log_excerpt($logPath);
		$lastError = 'Pipeline exited immediately. Check log: ' . $logFileName;
		if ($excerpt !== '') {
			$lastError .= ' Last lines: ' . preg_replace('/\s+/', ' ', $excerpt);
		}

		usleep($attemptDelays[$attempt]);
	}

	return array('ok' => false, 'error' => $lastError, 'logFile' => $logFileName, 'command' => $pipelineCommandForLog, 'config' => $config);
}

function list_instances(array $state): array
{
	$instances = array();
	foreach ($state as $device => $instance) {
		$pid = isset($instance['pid']) ? (int)$instance['pid'] : 0;
		$running = is_process_running($pid);
		if (!$running) {
			continue;
		}
		$config = isset($instance['config']) && is_array($instance['config']) ? $instance['config'] : array();
		$instanceDeviceId = normalize_device_id((string)($config['device'] ?? $device));
		$instanceDeviceSerial = normalize_device_serial((string)($config['deviceSerial'] ?? ''));
		if ($instanceDeviceSerial !== '') {
			$instanceDeviceId = 'sn:' . $instanceDeviceSerial;
		}
		if ($instanceDeviceId === '') {
			$instanceDeviceId = normalize_device_id((string)$device);
		}
		if ($instanceDeviceId === '') {
			continue;
		}
		$config['device'] = $instanceDeviceId;
		if ($instanceDeviceSerial !== '') {
			$config['deviceSerial'] = $instanceDeviceSerial;
		}
		$instances[] = array(
			'device' => $instanceDeviceId,
			'pid' => $pid,
			'running' => true,
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

function parse_rtl_test_scan_output(string $scanOutput): array
{
	$devices = array();
	$expectedCount = null;
	$seenDeviceIds = array();

	$lines = preg_split('/\r\n|\r|\n/', $scanOutput);
	if (!is_array($lines)) {
		$lines = array();
	}

	foreach ($lines as $line) {
		$trimmed = trim((string)$line);
		if ($trimmed === '') {
			if (count($devices) > 0) {
				break;
			}
			continue;
		}

		if (preg_match('/^Found\s+([0-9]+)\s+device\(s\)(?::|\.|$)/i', $trimmed, $matches)) {
			$expectedCount = (int)$matches[1];
			continue;
		}

		if ($expectedCount !== null && preg_match('/^Using device\s+/i', $trimmed)) {
			break;
		}

		$index = '';
		$label = '';
		if (preg_match('/^([0-9]+):\s*(.+)$/', $trimmed, $matches)) {
			$index = (string)((int)$matches[1]);
			$label = trim((string)$matches[2]);
		} elseif (preg_match('/^\[\s*([0-9]+)\s*\]\s*(.+)$/', $trimmed, $matches)) {
			$index = (string)((int)$matches[1]);
			$label = trim((string)$matches[2]);
		} elseif (preg_match('/^Device\s+([0-9]+)\s*:\s*(.+)$/i', $trimmed, $matches)) {
			$index = (string)((int)$matches[1]);
			$label = trim((string)$matches[2]);
		}

		if ($index === '') {
			continue;
		}

		$serial = extract_device_serial($label);
		$deviceId = build_device_id_from_index_and_serial($index, $serial);
		if ($deviceId === '' || isset($seenDeviceIds[$deviceId])) {
			continue;
		}

		$seenDeviceIds[$deviceId] = true;
		$devices[] = array(
			'id' => $deviceId,
			'index' => $index,
			'label' => sanitize_device_label($label, 'RTL-SDR Device ' . $index),
			'serial' => $serial,
		);
	}

	return array(
		'devices' => $devices,
		'expectedCount' => $expectedCount,
	);
}

function discover_rtl_devices(): array
{
	$devices = array();
	$warning = '';

	if (!command_exists('rtl_test')) {
		$warning = 'rtl_test was not found in PATH, so hardware auto-discovery is unavailable.';
		return array('devices' => $devices, 'warning' => $warning);
	}

	$baseScanCommand = command_exists('timeout')
		? 'LC_ALL=C timeout 8 rtl_test -t 2>&1'
		: 'LC_ALL=C rtl_test -t 2>&1';

	$scanCommands = array();
	$wrappedScanCommand = wrap_for_device_access($baseScanCommand);
	if ($wrappedScanCommand !== '') {
		$scanCommands[] = $wrappedScanCommand;
	}
	if ($wrappedScanCommand !== $baseScanCommand) {
		$scanCommands[] = $baseScanCommand;
	}

	$scanOutput = '';
	$parsed = array('devices' => array(), 'expectedCount' => null);
	foreach ($scanCommands as $scanCommand) {
		$candidateOutput = shell_exec('bash -lc ' . escapeshellarg($scanCommand));
		if (!is_string($candidateOutput) || trim($candidateOutput) === '') {
			continue;
		}

		$scanOutput = $candidateOutput;
		$parsed = parse_rtl_test_scan_output($candidateOutput);
		if (count($parsed['devices']) > 0) {
			break;
		}
	}

	if ($scanOutput === '') {
		$warning = 'rtl_test returned no output.';
		return array('devices' => $devices, 'warning' => $warning);
	}

	$devices = isset($parsed['devices']) && is_array($parsed['devices']) ? $parsed['devices'] : array();
	$expectedCount = isset($parsed['expectedCount']) ? (int)$parsed['expectedCount'] : null;

	if (count($devices) === 0 && $expectedCount !== null && $expectedCount > 0) {
		for ($index = 0; $index < $expectedCount; $index++) {
			$normalizedIndex = (string)$index;
			$devices[] = array(
				'id' => build_device_id_from_index_and_serial($normalizedIndex, ''),
				'index' => $normalizedIndex,
				'label' => 'RTL-SDR Device ' . $normalizedIndex,
				'serial' => '',
			);
		}
	}

	if (count($devices) === 0) {
		if (stripos($scanOutput, 'No supported devices found') !== false) {
			$warning = 'No supported RTL-SDR devices were found.';
		} else {
			$lines = preg_split('/\r\n|\r|\n/', $scanOutput);
			if (!is_array($lines)) {
				$lines = array();
			}

			$hint = '';
			foreach ($lines as $line) {
				$trimmed = trim((string)$line);
				if ($trimmed === '') {
					continue;
				}

				if (preg_match('/\b(error|failed|denied|not\s+found|unable|cannot|permission)\b/i', $trimmed) === 1) {
					$hint = $trimmed;
					break;
				}

				if ($hint === '') {
					$hint = $trimmed;
				}
			}

			if ($hint !== '') {
				$warning = 'RTL-SDR scan returned no devices. ' . $hint;
			} else {
				$warning = 'RTL-SDR scan returned no devices.';
			}
		}
	}

	return array('devices' => $devices, 'warning' => $warning);
}

// ── Stream Proxy ────────────────────────────────────────────────────────────
// Handles ?proxy=stream&target=host:port&mount=/path requests.
// The target is validated against the configured streaming servers to prevent SSRF.

function rtl_sdr_sanitize_header_value(string $value): string
{
	return str_replace(array("\r", "\n"), '', trim($value));
}

function rtl_sdr_send_stream_access_headers(): void
{
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
	header('Access-Control-Allow-Headers: Range, Origin, Accept, Access-Control-Request-Private-Network');
	header('Access-Control-Expose-Headers: Content-Length, Content-Range, Content-Type');
	if (
		isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_PRIVATE_NETWORK'])
		&& strtolower((string)$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_PRIVATE_NETWORK']) === 'true'
	) {
		header('Access-Control-Allow-Private-Network: true');
	}
}

function rtl_sdr_parse_http_wrapper_headers(array $wrapperData): array
{
	$statusCode = 200;
	$headers = array();
	foreach ($wrapperData as $line) {
		if (!is_string($line)) {
			continue;
		}
		$trimmedLine = trim($line);
		if ($trimmedLine === '') {
			continue;
		}
		if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $trimmedLine, $matches)) {
			$statusCode = (int)$matches[1];
			$headers = array();
			continue;
		}
		$sep = strpos($trimmedLine, ':');
		if ($sep === false) {
			continue;
		}
		$hName = strtolower(trim(substr($trimmedLine, 0, $sep)));
		$hValue = rtl_sdr_sanitize_header_value(substr($trimmedLine, $sep + 1));
		if ($hName !== '' && $hValue !== '') {
			$headers[$hName] = $hValue;
		}
	}
	return array('status_code' => $statusCode, 'headers' => $headers);
}

function rtl_sdr_pick_stream_content_type(string $mount, string $remoteContentType): string
{
	$sanitizedContentType = rtl_sdr_sanitize_header_value($remoteContentType);
	$ext = strtolower(ltrim((string)pathinfo($mount, PATHINFO_EXTENSION), '.'));
	if (
		$sanitizedContentType !== ''
		&& (
			stripos($sanitizedContentType, 'audio/') === 0
			|| stripos($sanitizedContentType, 'application/ogg') === 0
			|| stripos($sanitizedContentType, 'application/octet-stream') === 0
		)
	) {
		return $sanitizedContentType;
	}
	if ($ext === 'ogg') {
		return 'audio/ogg';
	}
	return 'audio/mpeg';
}

function rtl_sdr_stream_proxy(string $serversFile, string $target, string $mount): void
{
	if ($target === '') {
		http_response_code(400);
		echo 'Missing stream target.';
		exit;
	}

	// Validate target format: hostname or IP with optional port
	if (!preg_match('/^[A-Za-z0-9._-]+(:\d{1,5})?$/', $target)) {
		http_response_code(400);
		echo 'Invalid stream target format.';
		exit;
	}

	// Validate mount path: must start with / and contain only safe characters
	if ($mount === '' || $mount[0] !== '/') {
		http_response_code(400);
		echo 'Invalid stream mount path.';
		exit;
	}
	if (!preg_match('#^/[A-Za-z0-9._/\-]*$#', $mount)) {
		http_response_code(400);
		echo 'Invalid stream mount path characters.';
		exit;
	}

	// SSRF guard: only proxy to configured streaming servers
	$servers = load_streaming_servers($serversFile);
	$allowedTargets = array();
	foreach ($servers as $server) {
		$serverTarget = isset($server['target']) ? trim((string)$server['target']) : '';
		if ($serverTarget !== '') {
			$allowedTargets[] = $serverTarget;
		}
	}
	if (!in_array($target, $allowedTargets, true)) {
		http_response_code(403);
		echo 'Stream target is not a configured server.';
		exit;
	}

	// Release the session file lock before entering the long-running stream loop.
	// session_start() acquires a write lock on the session file; without closing it
	// here, every other request sharing the same session cookie would block until
	// the stream ends (which may never happen). All session reads needed for auth
	// have already completed by this point.
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_write_close();
	}

	$streamUrl = 'http://' . $target . $mount;

	$requestHeaders = array(
		'Accept: audio/*,*/*;q=0.9',
		'Connection: close',
	);
	if (isset($_SERVER['HTTP_USER_AGENT']) && trim((string)$_SERVER['HTTP_USER_AGENT']) !== '') {
		$requestHeaders[] = 'User-Agent: ' . rtl_sdr_sanitize_header_value((string)$_SERVER['HTTP_USER_AGENT']);
	}

	if (function_exists('curl_init')) {
		$curlHandle = curl_init();
		if ($curlHandle !== false) {
			$curlStatusCode = 0;
			$upstreamHttpError = 0;
			$curlBytesWritten = 0;

			$headerCallback = static function ($handle, string $headerLine) use (&$curlStatusCode, &$upstreamHttpError): int {
				$trimmedLine = trim($headerLine);
				if (preg_match('#^HTTP/\\S+\\s+(\\d{3})#i', $trimmedLine, $matches)) {
					$curlStatusCode = (int)$matches[1];
					$upstreamHttpError = $curlStatusCode >= 400 ? $curlStatusCode : 0;
					return strlen($headerLine);
				}
				return strlen($headerLine);
			};

			$writeCallback = static function ($handle, string $chunk) use (&$upstreamHttpError, &$curlBytesWritten): int {
				if ($upstreamHttpError > 0) {
					return 0;
				}
				if ($chunk !== '') {
					$curlBytesWritten += strlen($chunk);
					echo $chunk;
					flush();
				}

				if (connection_aborted()) {
					return 0;
				}

				return strlen($chunk);
			};

			rtl_sdr_send_stream_access_headers();
			header('Content-Type: ' . rtl_sdr_pick_stream_content_type($mount, ''));
			header('Cache-Control: no-cache, no-store, must-revalidate');
			header('Content-Disposition: inline; filename="' . basename($mount) . '"');
			http_response_code(200);
			set_time_limit(0);

			$curlOptions = array(
				CURLOPT_URL => $streamUrl,
				CURLOPT_HTTPGET => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS => 5,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_NOSIGNAL => 1,
				CURLOPT_HTTPHEADER => $requestHeaders,
				CURLOPT_HEADERFUNCTION => $headerCallback,
				CURLOPT_WRITEFUNCTION => $writeCallback,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_FAILONERROR => false,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			);
			if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
				$curlOptions[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
			}
			curl_setopt_array($curlHandle, $curlOptions);

			$curlExecResult = curl_exec($curlHandle);
			$curlErrno = curl_errno($curlHandle);
			$curlHttpCode = (int)curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
			curl_close($curlHandle);

			$effectiveStatusCode = $upstreamHttpError > 0
				? $upstreamHttpError
				: ($curlStatusCode > 0 ? $curlStatusCode : $curlHttpCode);

			if ($effectiveStatusCode >= 400 && $curlBytesWritten === 0) {
				http_response_code(502);
				echo 'Stream returned HTTP ' . $effectiveStatusCode . '.';
				exit;
			}

			if ($curlBytesWritten > 0) {
				exit;
			}

			if ($curlExecResult === false && $curlErrno !== 0) {
				// Fall through to fopen transport as a compatibility fallback.
			} else {
				http_response_code(502);
				echo 'Unable to connect to stream.';
				exit;
			}
		}
	}

	$context = stream_context_create(array(
		'http' => array(
			'method' => 'GET',
			'header' => implode("\r\n", $requestHeaders) . "\r\n",
			'timeout' => 15,
			'follow_location' => 1,
			'max_redirects' => 5,
			'ignore_errors' => true,
			'protocol_version' => 1.1,
		),
	));

	$remoteHandle = @fopen($streamUrl, 'rb', false, $context);
	if ($remoteHandle === false) {
		http_response_code(502);
		echo 'Unable to connect to stream.';
		exit;
	}

	$streamMeta = stream_get_meta_data($remoteHandle);
	$responseMeta = rtl_sdr_parse_http_wrapper_headers(
		isset($streamMeta['wrapper_data']) && is_array($streamMeta['wrapper_data'])
			? $streamMeta['wrapper_data']
			: array()
	);
	$statusCode = (int)$responseMeta['status_code'];
	if ($statusCode >= 400) {
		fclose($remoteHandle);
		http_response_code(502);
		echo 'Stream returned HTTP ' . $statusCode . '.';
		exit;
	}

	$remoteHeaders = is_array($responseMeta['headers']) ? $responseMeta['headers'] : array();
	$remoteContentType = isset($remoteHeaders['content-type']) ? (string)$remoteHeaders['content-type'] : '';
	$contentType = rtl_sdr_pick_stream_content_type($mount, $remoteContentType);

	rtl_sdr_send_stream_access_headers();
	header('Content-Type: ' . $contentType);
	header('Cache-Control: no-cache, no-store, must-revalidate');
	header('Content-Disposition: inline; filename="' . basename($mount) . '"');
	if (isset($remoteHeaders['content-length']) && ctype_digit((string)$remoteHeaders['content-length'])) {
		header('Content-Length: ' . (string)$remoteHeaders['content-length']);
	}
	http_response_code(200);
	set_time_limit(0);
	stream_set_timeout($remoteHandle, 15);

	while (!feof($remoteHandle)) {
		$buffer = fread($remoteHandle, 8192);
		if ($buffer === false) {
			break;
		}
		if ($buffer === '') {
			$chunkMeta = stream_get_meta_data($remoteHandle);
			if (isset($chunkMeta['timed_out']) && $chunkMeta['timed_out'] === true) {
				break;
			}
			if (connection_aborted()) {
				break;
			}
			usleep(10000);
			continue;
		}
		echo $buffer;
		flush();
		if (connection_aborted()) {
			break;
		}
	}

	fclose($remoteHandle);
	exit;
}

$_proxyAction = isset($_GET['proxy']) ? trim((string)$_GET['proxy']) : '';
if ($_proxyAction === 'stream') {
	if (
		isset($_SERVER['REQUEST_METHOD'])
		&& strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'OPTIONS'
	) {
		rtl_sdr_send_stream_access_headers();
		http_response_code(204);
		exit;
	}
	$_proxyTarget = isset($_GET['target']) ? trim((string)$_GET['target']) : '';
	$_proxyMount  = isset($_GET['mount'])  ? trim((string)$_GET['mount'])  : '';
	rtl_sdr_stream_proxy($STREAMING_SERVERS_FILE, $_proxyTarget, $_proxyMount);
}
// ─────────────────────────────────────────────────────────────────────────────

$jsonPayload = parse_json_request_body();
$action = '';
if (isset($_REQUEST['action'])) {
	$action = trim((string)$_REQUEST['action']);
} elseif (isset($jsonPayload['action'])) {
	$action = trim((string)$jsonPayload['action']);
}

if ($action !== '') {
	$state = load_state($STATE_FILE);
	$desiredState = load_desired_state($DESIRED_STATE_FILE);
	$desiredStateChanged = false;

	if ($action === 'stop') {
		$preStopDeviceId = normalize_device_id($_POST['device'] ?? $_GET['device'] ?? ($jsonPayload['device'] ?? ''));
		if ($preStopDeviceId !== '') {
			$preStopStateKey = find_state_device_key($state, $preStopDeviceId);
			if ($preStopStateKey !== '' && isset($state[$preStopStateKey]) && is_array($state[$preStopStateKey])) {
				$preStopConfig = isset($state[$preStopStateKey]['config']) && is_array($state[$preStopStateKey]['config'])
					? $state[$preStopStateKey]['config']
					: array();
				$preStopSerial = normalize_device_serial((string)($preStopConfig['deviceSerial'] ?? ''));
				$preStopResolved = normalize_device_id((string)($preStopConfig['device'] ?? $preStopStateKey));
				if ($preStopSerial !== '') {
					$preStopResolved = 'sn:' . $preStopSerial;
				}
				if ($preStopResolved !== '') {
					$preStopDeviceId = $preStopResolved;
				}
			}
		}
		if ($preStopDeviceId !== '' && set_device_desired_running($desiredState, $preStopDeviceId, false)) {
			$desiredStateChanged = true;
		}
	}

	if ($action === 'stop_all') {
		$devicesToStop = array_values(array_unique(array_merge(array_keys($state), array_keys($desiredState))));
		foreach ($devicesToStop as $rawDeviceToStop) {
			if (set_device_desired_running($desiredState, (string)$rawDeviceToStop, false)) {
				$desiredStateChanged = true;
			}
		}
	}

	$skipCleanupForAction = $action === 'stop' || $action === 'stop_all';
	$desiredStateChangedByCleanup = false;
	$stateChangedByCleanup = false;
	if (!$skipCleanupForAction) {
		$stateChangedByCleanup = cleanup_stale_instances($state, $LOG_DIR, $desiredState, $desiredStateChangedByCleanup);
		if ($stateChangedByCleanup) {
			save_state($STATE_FILE, $state);
			sync_ui_settings_with_running_state($UI_SETTINGS_FILE, $state);
		}
	}

	if ($desiredStateChanged || $desiredStateChangedByCleanup) {
		save_desired_state($DESIRED_STATE_FILE, $desiredState);
	}

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

	if ($action === 'recording_servers_get') {
		$servers = load_recording_servers($RECORDING_SERVERS_FILE);
		send_json(array('ok' => true, 'servers' => $servers));
	}

	if ($action === 'recording_servers_set') {
		$payload = $_POST;
		if (is_array($jsonPayload) && count($jsonPayload) > 0) {
			$payload = array_merge($payload, $jsonPayload);
		}

		$rawServers = isset($payload['servers']) && is_array($payload['servers']) ? $payload['servers'] : array();
		$servers = normalize_recording_servers($rawServers);
		if (!save_recording_servers($RECORDING_SERVERS_FILE, $servers)) {
			send_json(array('ok' => false, 'error' => 'Failed to save recording servers.'), 500);
		}

		send_json(array('ok' => true, 'servers' => $servers));
	}

	if ($action === 'templates_get') {
		$templates = load_templates($TEMPLATES_FILE, $UI_SETTINGS_FILE);
		send_json(array('ok' => true, 'templates' => (object)$templates));
	}

	if ($action === 'templates_set') {
		$payload = $_POST;
		if (is_array($jsonPayload) && count($jsonPayload) > 0) {
			$payload = array_merge($payload, $jsonPayload);
		}

		$rawTemplates = isset($payload['templates']) && is_array($payload['templates']) ? $payload['templates'] : array();
		$templates = normalize_templates($rawTemplates);
		if (!save_templates($TEMPLATES_FILE, $templates)) {
			send_json(array('ok' => false, 'error' => 'Failed to save templates.'), 500);
		}

		send_json(array('ok' => true, 'templates' => (object)$templates));
	}

	if ($action === 'settings_get') {
		$settings = sync_ui_settings_with_running_state($UI_SETTINGS_FILE, $state);
		send_json(array('ok' => true, 'settings' => ui_settings_for_response($settings)));
	}

	if ($action === 'settings_set') {
		$payload = $_POST;
		if (is_array($jsonPayload) && count($jsonPayload) > 0) {
			$payload = array_merge($payload, $jsonPayload);
		}

		$incoming = isset($payload['settings']) && is_array($payload['settings']) ? $payload['settings'] : array();
		$existingSettings = sync_ui_settings_with_running_state($UI_SETTINGS_FILE, $state);
		$currentDeviceConfigs = isset($existingSettings['deviceConfigs']) && is_array($existingSettings['deviceConfigs'])
			? $existingSettings['deviceConfigs']
			: array();

		$normalizedIncoming = normalize_ui_settings($incoming);
		$incomingDeviceConfigs = isset($normalizedIncoming['deviceConfigs']) && is_array($normalizedIncoming['deviceConfigs'])
			? $normalizedIncoming['deviceConfigs']
			: array();
		$incomingAntennaDescriptions = isset($normalizedIncoming['antennaDescriptionsBySerial']) && is_array($normalizedIncoming['antennaDescriptionsBySerial'])
			? $normalizedIncoming['antennaDescriptionsBySerial']
			: array();

		$antennaDescriptions = isset($existingSettings['antennaDescriptionsBySerial']) && is_array($existingSettings['antennaDescriptionsBySerial'])
			? $existingSettings['antennaDescriptionsBySerial']
			: array();
		if (isset($incoming['antennaDescriptionsBySerial']) && is_array($incoming['antennaDescriptionsBySerial'])) {
			$antennaDescriptions = $incomingAntennaDescriptions;
		}

		$deviceDeletes = normalize_device_id_list($incoming['deviceConfigDeletes'] ?? array());
		foreach ($deviceDeletes as $deleteDeviceId) {
			unset($currentDeviceConfigs[$deleteDeviceId]);
		}

		foreach ($incomingDeviceConfigs as $incomingDeviceId => $incomingConfig) {
			$normalizedDeviceId = normalize_device_id((string)$incomingDeviceId);
			if ($normalizedDeviceId === '' || !is_array($incomingConfig)) {
				continue;
			}
			$incomingConfig['device'] = $normalizedDeviceId;
			$currentDeviceConfigs[$normalizedDeviceId] = $incomingConfig;
		}

		$settings = $existingSettings;
		$settings['deviceConfigs'] = merge_running_state_into_device_configs($currentDeviceConfigs, $state);
		$settings['antennaDescriptionsBySerial'] = $antennaDescriptions;
		if (!save_ui_settings($UI_SETTINGS_FILE, $settings)) {
			send_json(array('ok' => false, 'error' => 'Failed to save UI settings.'), 500);
		}

		send_json(array('ok' => true, 'settings' => ui_settings_for_response($settings)));
	}

	if ($action === 'list') {
		cleanup_stale_logs_by_device($LOG_DIR);
		save_state($STATE_FILE, $state);
		$settings = sync_ui_settings_with_running_state($UI_SETTINGS_FILE, $state);
		send_json(array(
			'ok' => true,
			'instances' => list_instances($state),
			'settings' => ui_settings_for_response($settings),
		));
	}

	if ($action === 'stop') {
		$requestedDeviceId = normalize_device_id($_POST['device'] ?? $_GET['device'] ?? ($jsonPayload['device'] ?? ''));
		if ($requestedDeviceId === '') {
			send_json(array('ok' => false, 'error' => 'Device is required.'), 400);
		}

		$stateDeviceKey = find_state_device_key($state, $requestedDeviceId);
		if ($stateDeviceKey === '') {
			send_json(array('ok' => true, 'message' => 'Device was not running.', 'instances' => list_instances($state)));
		}

		$instance = isset($state[$stateDeviceKey]) && is_array($state[$stateDeviceKey])
			? $state[$stateDeviceKey]
			: array();
		$config = isset($instance['config']) && is_array($instance['config']) ? $instance['config'] : array();
		$resolvedDeviceId = normalize_device_id((string)($config['device'] ?? $stateDeviceKey));
		$resolvedSerial = normalize_device_serial((string)($config['deviceSerial'] ?? ''));
		if ($resolvedSerial !== '') {
			$resolvedDeviceId = 'sn:' . $resolvedSerial;
		}
		if ($resolvedDeviceId === '') {
			$resolvedDeviceId = normalize_device_id($requestedDeviceId);
		}

		if ($resolvedDeviceId !== '' && set_device_desired_running($desiredState, $resolvedDeviceId, false)) {
			save_desired_state($DESIRED_STATE_FILE, $desiredState);
		}

		$pid = isset($state[$stateDeviceKey]['pid']) ? (int)$state[$stateDeviceKey]['pid'] : 0;
		stop_instance_by_pid($pid);
		unset($state[$stateDeviceKey]);
		save_state($STATE_FILE, $state);
		send_json(array('ok' => true, 'message' => 'Stopped device ' . $resolvedDeviceId . '.', 'instances' => list_instances($state)));
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
		send_json(array('ok' => true) + build_log_payload_for_device($LOG_DIR, $deviceId, $state, $maxLines));
	}

	if ($action === 'logs_batch') {
		$requestedLines = (int)($_POST['lines'] ?? $_GET['lines'] ?? ($jsonPayload['lines'] ?? 40));
		$maxLines = max(10, min(200, $requestedLines));

		$rawDevices = $_POST['devices'] ?? $_GET['devices'] ?? ($jsonPayload['devices'] ?? array());
		$deviceCandidates = array();
		if (is_array($rawDevices)) {
			$deviceCandidates = $rawDevices;
		} elseif (is_string($rawDevices)) {
			$trimmedDevices = trim($rawDevices);
			if ($trimmedDevices !== '') {
				$decodedDevices = json_decode($trimmedDevices, true);
				if (is_array($decodedDevices)) {
					$deviceCandidates = $decodedDevices;
				} else {
					$splitDevices = preg_split('/\s*,\s*/', $trimmedDevices);
					$deviceCandidates = is_array($splitDevices) ? $splitDevices : array($trimmedDevices);
				}
			}
		}

		$deviceIds = array();
		foreach ($deviceCandidates as $candidateDevice) {
			$normalizedDevice = normalize_device_id((string)$candidateDevice);
			if ($normalizedDevice === '' || in_array($normalizedDevice, $deviceIds, true)) {
				continue;
			}
			$deviceIds[] = $normalizedDevice;
		}

		$logsByDevice = array();
		foreach ($deviceIds as $batchDeviceId) {
			$logsByDevice[$batchDeviceId] = build_log_payload_for_device($LOG_DIR, $batchDeviceId, $state, $maxLines);
		}

		send_json(array('ok' => true, 'logs' => $logsByDevice));
	}

	if ($action === 'logs_download') {
		$deviceId = normalize_device_id($_POST['device'] ?? $_GET['device'] ?? ($jsonPayload['device'] ?? ''));
		if ($deviceId === '') {
			send_json(array('ok' => false, 'error' => 'Device is required.'), 400);
		}

		$stateDeviceKey = find_state_device_key($state, $deviceId);
		$logLookupDeviceId = $stateDeviceKey !== '' ? $stateDeviceKey : $deviceId;
		$logPath = resolve_log_path_for_device($LOG_DIR, $logLookupDeviceId, $state);
		if ($logPath === '' || !file_exists($logPath)) {
			send_json(array('ok' => false, 'error' => 'No log file found for device ' . $deviceId . '.'), 404);
		}

		$contents = file_get_contents($logPath);
		if (!is_string($contents)) {
			send_json(array('ok' => false, 'error' => 'Failed to read log file.'), 500);
		}

		$clean = strip_ansi_sequences($contents);
		$clean = preg_replace('/[[:cntrl:]&&[^\r\n\t]]+/', '', $clean) ?? $clean;
		send_text_download($clean, basename($logPath));
	}

	if ($action === 'start' || $action === 'retune') {
		$payload = $_POST;
		if (is_array($jsonPayload) && count($jsonPayload) > 0) {
			$payload = array_merge($payload, $jsonPayload);
		}
		$autoRecoveryEnabled = resolve_auto_recovery_enabled_from_payload((array)$payload);

		try {
			$config = normalize_config((array)$payload, $recordingsRoot);
		} catch (RuntimeException $error) {
			send_json(array('ok' => false, 'error' => $error->getMessage()), 400);
		}

		$deviceId = (string)$config['device'];
		$existingStateKey = find_state_device_key($state, $deviceId);
		if ($existingStateKey !== '') {
			$existingPid = isset($state[$existingStateKey]['pid']) ? (int)$state[$existingStateKey]['pid'] : 0;
			stop_instance_by_pid($existingPid);
			unset($state[$existingStateKey]);
		}

		$startResult = start_instance($config, $LOG_DIR);
		if ($startResult['ok'] !== true) {
			send_json(array('ok' => false, 'error' => $startResult['error']), 500);
		}

		$runtimeConfig = isset($startResult['config']) && is_array($startResult['config'])
			? $startResult['config']
			: $config;
		$deviceId = normalize_device_id((string)($runtimeConfig['device'] ?? $deviceId));

		$state[$deviceId] = build_instance_state($runtimeConfig, $startResult, $autoRecoveryEnabled, 0);
		set_device_desired_running($desiredState, $deviceId, true);

		save_state($STATE_FILE, $state);
		save_desired_state($DESIRED_STATE_FILE, $desiredState);
		sync_ui_settings_with_running_state($UI_SETTINGS_FILE, $state);
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
	<meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
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
		html {
			touch-action: pan-x pan-y;
			-webkit-text-size-adjust: 100%;
			text-size-adjust: 100%;
		}
		body {
			margin: 0;
			touch-action: pan-x pan-y;
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
		.refresh-button.compact { min-height: 24px; padding: 2px 9px; font-size: 10px; }
		.refresh-button:hover { transform: translateY(-1px); border-color: var(--accent); }
		.refresh-button:disabled { opacity: 0.5; cursor: not-allowed; transform: none; border-color: var(--border); }
		.refresh-button:disabled:hover { transform: none; border-color: var(--border); }
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
		.device-stream-name { grid-column: 1 / -1; margin: 0; color: var(--accent); font-size: 16px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
		.device-subtitle { grid-column: 1 / -1; margin: 0; color: var(--muted); font-size: 12px; line-height: 1.5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
		.device-antenna-row { grid-column: 1 / -1; display: flex; align-items: center; gap: 8px; min-width: 0; }
		.device-antenna-label { margin: 0; color: var(--muted); font-size: 12px; line-height: 1.5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
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
		.device-log-toggle { margin-top: 12px; position: relative; z-index: 1; display: flex; gap: 8px; flex-wrap: wrap; }
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
		label.checkbox-label { display: flex; align-items: center; gap: 8px; margin-bottom: 0; padding: 10px 0; cursor: pointer; font-size: 12px; user-select: none; }
		label.checkbox-label input[type="checkbox"] { width: 16px; height: 16px; min-height: unset; flex-shrink: 0; accent-color: var(--accent, #4a9eff); cursor: pointer; }
		input:not([type="checkbox"]), select {
			width: 100%;
			min-height: 38px;
			border: 1px solid var(--border);
			border-radius: 10px;
			background: var(--input);
			color: var(--text);
			font-size: 16px;
			padding: 8px 10px;
			box-sizing: border-box;
		}
		.output-mode-options {
			display: flex;
			gap: 10px;
			flex-wrap: wrap;
		}
		.output-mode-option {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			padding: 10px 12px;
			border: 1px solid var(--border);
			border-radius: 12px;
			background: rgba(255,255,255,0.03);
			color: var(--text);
			font-size: 13px;
			letter-spacing: 0;
			text-transform: none;
			margin-bottom: 0;
			cursor: pointer;
		}
		.output-mode-option input[type="checkbox"] {
			width: auto;
			min-height: 0;
			margin: 0;
			accent-color: var(--accent);
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
			<button type="button" class="refresh-button primary" id="startTemplateSelectedButton">Start Template On Selected</button>
			<button type="button" class="refresh-button" id="applyTemplateSelectedButton">Apply Template To Selected</button>
			<button type="button" class="refresh-button" id="exportGlobalTemplateButton">Export Template JSON</button>
			<button type="button" class="refresh-button" id="exportAllGlobalTemplatesButton">Export All Templates JSON</button>
			<button type="button" class="refresh-button" id="importGlobalTemplateButton">Import Template(s) JSON</button>
			<input type="file" id="importGlobalTemplateFileInput" accept="application/json,.json" style="display:none;">
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

	<div id="recordingServerModal" class="modal hidden">
		<div class="modal-content">
			<div class="modal-header">
				<h3 id="recordingServerModalTitle">New Recording Server</h3>
				<button type="button" class="modal-close" id="recordingServerModalClose">&times;</button>
			</div>
			<div class="modal-body">
				<div class="form-row single">
					<div><label>Server Name</label><input type="text" id="recordingServerModalName" placeholder="Library Ingest"></div>
				</div>
				<div class="form-row single">
					<div><label>Upload URL</label><input type="text" id="recordingServerModalUrl" placeholder="https://example/recordings/"></div>
				</div>
				<div class="form-row single">
					<div><label>Username (optional)</label><input type="text" id="recordingServerModalUsername" placeholder="api-user"></div>
				</div>
				<div class="form-row single">
					<div><label>Password (optional)</label><input type="password" id="recordingServerModalPassword" placeholder="api-password"></div>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="refresh-button" id="recordingServerModalCancel">Cancel</button>
				<button type="button" class="refresh-button primary" id="recordingServerModalSave">Save Server</button>
				<button type="button" class="refresh-button danger hidden" id="recordingServerModalDelete">Delete Server</button>
			</div>
		</div>
	</div>

	<div id="antennaModal" class="modal hidden">
		<div class="modal-content">
			<div class="modal-header">
				<h3 id="antennaModalTitle">Edit Antenna Description</h3>
				<button type="button" class="modal-close" id="antennaModalClose">&times;</button>
			</div>
			<div class="modal-body">
				<div class="form-row single">
					<div><label>Device</label><input type="text" id="antennaModalDevice" readonly></div>
				</div>
				<div class="form-row single">
					<div><label>Antenna Description</label><input type="text" id="antennaModalDescription" placeholder="Optional antenna notes"></div>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="refresh-button" id="antennaModalCancel">Cancel</button>
				<button type="button" class="refresh-button danger hidden" id="antennaModalClear">Clear</button>
				<button type="button" class="refresh-button primary" id="antennaModalSave">Save</button>
			</div>
		</div>
	</div>
</div>

<script type="text/javascript">
var apiUrl = window.location.pathname;
var knownInstancesByDevice = {};
var knownDetectedDevices = [];
var deviceConfigsById = {};
var antennaDescriptionsBySerial = {};
var settingsTemplates = {};
var streamServersById = {};
var recordingServersById = {};
var openConfigPanelsByDevice = {};
var openLogPanelsByDevice = {};
var logContentByDevice = {};
var streamPlayersByDevice = {};
var uiSettingsSaveTimer = null;
var uiSettingsSaveInFlight = false;
var uiSettingsSaveQueued = false;
var UI_SETTINGS_SAVE_DELAY_MS = 400;
var pendingDeviceConfigUpsertsById = {};
var pendingDeviceConfigDeletesById = {};
var lastSavedDeviceConfigFingerprintById = {};
var pendingAntennaDescriptionsDirty = false;
var lastSavedAntennaDescriptionsFingerprint = '';
var templatesSaveInFlight = false;
var templatesSaveQueued = false;
var lastSavedTemplatesFingerprint = '';
var REFRESH_REQUEST_TIMEOUT_MS = 12000;
var USER_ACTION_REQUEST_TIMEOUT_MS = 12000;
var USER_ACTION_MAX_ATTEMPTS = 2;
var USER_ACTION_RETRY_DELAY_MS = 600;
var refreshInstancesInFlightPromise = null;
var refreshInstancesQueued = false;
var refreshOpenLogsInFlightPromise = null;
var refreshOpenLogsQueued = false;
var refreshOpenLogsRenderRequested = false;
var lastTouchEndAt = 0;
var STANDARD_DCS_CODES = [
	'023', '025', '026', '031', '032', '036', '043', '047', '051', '053', '054', '065', '071', '072', '073', '074',
	'114', '115', '116', '122', '125', '131', '132', '134', '143', '145', '152', '155', '156', '162', '165', '172', '174',
	'205', '212', '223', '225', '226', '243', '244', '245', '246', '251', '252', '255', '261', '263', '265', '266', '271', '274',
	'306', '311', '315', '325', '331', '332', '343', '346', '351', '356', '364', '365', '371',
	'411', '412', '413', '423', '431', '432', '445', '446', '452', '454', '455', '462', '464', '465', '466',
	'503', '506', '516', '523', '526', '532', '546', '565',
	'606', '612', '624', '627', '631', '632', '654', '662', '664',
	'703', '712', '723', '731', '732', '734', '743', '754'
];
var STANDARD_CTCSS_TONES = [
	'67.0', '69.3', '71.9', '74.4', '77.0', '79.7', '82.5', '85.4', '88.5', '91.5', '94.8', '97.4',
	'100.0', '103.5', '107.2', '110.9', '114.8', '118.8', '123.0', '127.3', '131.8', '136.5', '141.3',
	'146.2', '151.4', '156.7', '159.8', '162.2', '165.5', '167.9', '171.3', '173.8', '177.3', '179.9',
	'183.5', '186.2', '189.9', '192.8', '196.6', '199.5', '203.5', '206.5', '210.7', '218.1', '225.7',
	'229.1', '233.6', '241.8', '250.3', '254.1'
];

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

function installZoomGuards()
{
	var zoomKeyCodes = {
		Equal: true,
		Minus: true,
		NumpadAdd: true,
		NumpadSubtract: true,
		Digit0: true,
		Numpad0: true
	};

	document.addEventListener('gesturestart', function (event) {
		event.preventDefault();
	}, { passive: false });

	document.addEventListener('gesturechange', function (event) {
		event.preventDefault();
	}, { passive: false });

	document.addEventListener('gestureend', function (event) {
		event.preventDefault();
	}, { passive: false });

	document.addEventListener('touchstart', function (event) {
		if (event.touches && event.touches.length > 1) {
			event.preventDefault();
		}
	}, { passive: false });

	document.addEventListener('touchmove', function (event) {
		if ((event.touches && event.touches.length > 1) || (typeof event.scale === 'number' && event.scale !== 1)) {
			event.preventDefault();
		}
	}, { passive: false });

	document.addEventListener('touchend', function (event) {
		var now = Date.now();
		if (event.touches && event.touches.length > 0) {
			lastTouchEndAt = now;
			return;
		}

		if (now - lastTouchEndAt <= 300) {
			event.preventDefault();
		}

		lastTouchEndAt = now;
	}, { passive: false });

	document.addEventListener('wheel', function (event) {
		if (event.ctrlKey || event.metaKey) {
			event.preventDefault();
		}
	}, { passive: false });

	document.addEventListener('keydown', function (event) {
		if (!event.ctrlKey && !event.metaKey) {
			return;
		}

		var key = String(event.key || '').toLowerCase();
		var code = String(event.code || '');
		if (key === '+' || key === '=' || key === '-' || key === '_' || key === '0' || zoomKeyCodes[code]) {
			event.preventDefault();
		}
	});
}

function collectUiSettingsPayload()
{
	var upserts = {};
	for (var deviceId in pendingDeviceConfigUpsertsById) {
		if (!Object.prototype.hasOwnProperty.call(pendingDeviceConfigUpsertsById, deviceId)) {
			continue;
		}
		upserts[deviceId] = pendingDeviceConfigUpsertsById[deviceId];
	}

	var antennaBySerial = {};
	for (var serial in antennaDescriptionsBySerial) {
		if (!Object.prototype.hasOwnProperty.call(antennaDescriptionsBySerial, serial)) {
			continue;
		}
		antennaBySerial[String(serial)] = String(antennaDescriptionsBySerial[serial]);
	}

	return {
		deviceConfigs: upserts,
		deviceConfigDeletes: Object.keys(pendingDeviceConfigDeletesById),
		antennaDescriptionsBySerial: antennaBySerial
	};
}

function collectTemplatesPayload()
{
	return settingsTemplates;
}

function normalizeValueForSettingsFingerprint(value)
{
	if (value == null || typeof value !== 'object') {
		return value;
	}

	if (Array.isArray(value)) {
		var normalizedArray = [];
		for (var i = 0; i < value.length; i++) {
			normalizedArray.push(normalizeValueForSettingsFingerprint(value[i]));
		}
		return normalizedArray;
	}

	var normalizedObject = {};
	var keys = Object.keys(value).sort();
	for (var j = 0; j < keys.length; j++) {
		var key = keys[j];
		normalizedObject[key] = normalizeValueForSettingsFingerprint(value[key]);
	}

	return normalizedObject;
}

function computeUiSettingsFingerprint(settings)
{
	try {
		return JSON.stringify(normalizeValueForSettingsFingerprint(settings));
	} catch (error) {
		return '';
	}
}

function computeTemplatesFingerprint(templates)
{
	return computeUiSettingsFingerprint(templates);
}

function computeDeviceConfigFingerprint(config)
{
	return computeUiSettingsFingerprint(config || {});
}

function rebuildLastSavedDeviceConfigFingerprints(deviceConfigs)
{
	lastSavedDeviceConfigFingerprintById = {};
	if (!deviceConfigs || typeof deviceConfigs !== 'object' || Array.isArray(deviceConfigs)) {
		return;
	}

	for (var deviceId in deviceConfigs) {
		if (!Object.prototype.hasOwnProperty.call(deviceConfigs, deviceId)) {
			continue;
		}
		var config = deviceConfigs[deviceId];
		if (!config || typeof config !== 'object' || Array.isArray(config)) {
			continue;
		}
		var normalizedDeviceId = String(deviceId).trim();
		if (normalizedDeviceId === '') {
			continue;
		}
		lastSavedDeviceConfigFingerprintById[normalizedDeviceId] = computeDeviceConfigFingerprint(config);
	}
}

function hasPendingUiSettingsWrites()
{
	return (
		uiSettingsSaveInFlight
		|| uiSettingsSaveTimer !== null
		|| Object.keys(pendingDeviceConfigUpsertsById).length > 0
		|| Object.keys(pendingDeviceConfigDeletesById).length > 0
		|| pendingAntennaDescriptionsDirty
	);
}

function normalizeUiSettingsFromServer(settingsPayload)
{
	var rawSettings = (settingsPayload && typeof settingsPayload === 'object' && !Array.isArray(settingsPayload))
		? settingsPayload
		: {};
	var normalized = {
		deviceConfigs: {},
		antennaDescriptionsBySerial: {}
	};

	var rawDeviceConfigs = (rawSettings.deviceConfigs && typeof rawSettings.deviceConfigs === 'object' && !Array.isArray(rawSettings.deviceConfigs))
		? rawSettings.deviceConfigs
		: {};
	for (var rawDeviceId in rawDeviceConfigs) {
		if (!Object.prototype.hasOwnProperty.call(rawDeviceConfigs, rawDeviceId)) {
			continue;
		}

		var normalizedDeviceId = String(rawDeviceId == null ? '' : rawDeviceId).trim();
		if (normalizedDeviceId === '') {
			continue;
		}

		var config = rawDeviceConfigs[rawDeviceId];
		if (!config || typeof config !== 'object' || Array.isArray(config)) {
			continue;
		}

		var normalizedConfig = applyClientOutputSelection(Object.assign({}, config));
		normalizedConfig.device = normalizedDeviceId;
		normalized.deviceConfigs[normalizedDeviceId] = normalizedConfig;
	}

	var rawAntenna = (rawSettings.antennaDescriptionsBySerial && typeof rawSettings.antennaDescriptionsBySerial === 'object' && !Array.isArray(rawSettings.antennaDescriptionsBySerial))
		? rawSettings.antennaDescriptionsBySerial
		: {};
	for (var rawSerial in rawAntenna) {
		if (!Object.prototype.hasOwnProperty.call(rawAntenna, rawSerial)) {
			continue;
		}

		var normalizedSerial = normalizeAntennaSerial(rawSerial);
		if (normalizedSerial === '') {
			continue;
		}

		var normalizedDescription = normalizeAntennaDescription(rawAntenna[rawSerial]);
		if (normalizedDescription === '') {
			continue;
		}

		normalized.antennaDescriptionsBySerial[normalizedSerial] = normalizedDescription;
	}

	return normalized;
}

function applyUiSettingsFromServerPayload(settingsPayload)
{
	if (hasPendingUiSettingsWrites() || shouldPauseAutoRefreshRender()) {
		return false;
	}

	var normalizedSettings = normalizeUiSettingsFromServer(settingsPayload);
	var nextDeviceConfigs = normalizedSettings.deviceConfigs;
	var nextAntennaDescriptions = normalizedSettings.antennaDescriptionsBySerial;

	var currentDeviceFingerprint = computeUiSettingsFingerprint(deviceConfigsById);
	var nextDeviceFingerprint = computeUiSettingsFingerprint(nextDeviceConfigs);
	var currentAntennaFingerprint = computeUiSettingsFingerprint(antennaDescriptionsBySerial);
	var nextAntennaFingerprint = computeUiSettingsFingerprint(nextAntennaDescriptions);

	if (currentDeviceFingerprint === nextDeviceFingerprint && currentAntennaFingerprint === nextAntennaFingerprint) {
		return false;
	}

	deviceConfigsById = nextDeviceConfigs;
	antennaDescriptionsBySerial = nextAntennaDescriptions;
	pendingDeviceConfigUpsertsById = {};
	pendingDeviceConfigDeletesById = {};
	pendingAntennaDescriptionsDirty = false;
	rebuildLastSavedDeviceConfigFingerprints(deviceConfigsById);
	lastSavedAntennaDescriptionsFingerprint = nextAntennaFingerprint;
	return true;
}

function queueDeviceConfigPersistence(deviceId, options)
{
	var normalizedDeviceId = String(deviceId == null ? '' : deviceId).trim();
	if (normalizedDeviceId === '') {
		return;
	}

	var shouldRemove = !!(options && options.remove === true);
	if (shouldRemove) {
		delete pendingDeviceConfigUpsertsById[normalizedDeviceId];
		pendingDeviceConfigDeletesById[normalizedDeviceId] = true;
		scheduleUiSettingsSave();
		return;
	}

	if (!Object.prototype.hasOwnProperty.call(deviceConfigsById, normalizedDeviceId)) {
		delete pendingDeviceConfigUpsertsById[normalizedDeviceId];
		if (Object.prototype.hasOwnProperty.call(lastSavedDeviceConfigFingerprintById, normalizedDeviceId)) {
			pendingDeviceConfigDeletesById[normalizedDeviceId] = true;
			scheduleUiSettingsSave();
		}
		return;
	}

	var config = deviceConfigsById[normalizedDeviceId];
	if (!config || typeof config !== 'object' || Array.isArray(config)) {
		return;
	}

	var currentFingerprint = computeDeviceConfigFingerprint(config);
	if (
		Object.prototype.hasOwnProperty.call(lastSavedDeviceConfigFingerprintById, normalizedDeviceId)
		&& lastSavedDeviceConfigFingerprintById[normalizedDeviceId] === currentFingerprint
		&& !Object.prototype.hasOwnProperty.call(pendingDeviceConfigDeletesById, normalizedDeviceId)
	) {
		delete pendingDeviceConfigUpsertsById[normalizedDeviceId];
		return;
	}

	pendingDeviceConfigUpsertsById[normalizedDeviceId] = config;
	delete pendingDeviceConfigDeletesById[normalizedDeviceId];
	scheduleUiSettingsSave();
}

function saveUiSettingsNow()
{
	if (uiSettingsSaveInFlight) {
		uiSettingsSaveQueued = true;
		return Promise.resolve();
	}

	var settingsPayload = collectUiSettingsPayload();
	var upsertIds = Object.keys(settingsPayload.deviceConfigs || {});
	var deleteIds = Array.isArray(settingsPayload.deviceConfigDeletes) ? settingsPayload.deviceConfigDeletes.slice() : [];
	var antennaDescriptionsToSend = (settingsPayload.antennaDescriptionsBySerial && typeof settingsPayload.antennaDescriptionsBySerial === 'object' && !Array.isArray(settingsPayload.antennaDescriptionsBySerial))
		? settingsPayload.antennaDescriptionsBySerial
		: {};
	var antennaFingerprint = computeUiSettingsFingerprint(antennaDescriptionsToSend);
	var antennaDirty = pendingAntennaDescriptionsDirty || antennaFingerprint !== lastSavedAntennaDescriptionsFingerprint;
	if (!upsertIds.length && !deleteIds.length && !antennaDirty) {
		return Promise.resolve();
	}

	var upsertsToSend = {};
	for (var i = 0; i < upsertIds.length; i++) {
		var upsertId = upsertIds[i];
		upsertsToSend[upsertId] = settingsPayload.deviceConfigs[upsertId];
		delete pendingDeviceConfigUpsertsById[upsertId];
	}
	for (var j = 0; j < deleteIds.length; j++) {
		delete pendingDeviceConfigDeletesById[deleteIds[j]];
	}

	uiSettingsSaveInFlight = true;
	return postUserAction('settings_set', {
		settings: {
			deviceConfigs: upsertsToSend,
			deviceConfigDeletes: deleteIds,
			antennaDescriptionsBySerial: antennaDescriptionsToSend
		}
	}).then(function (result) {
		var responseSettings = (result && result.settings && typeof result.settings === 'object') ? result.settings : null;
		if (responseSettings && responseSettings.deviceConfigs && typeof responseSettings.deviceConfigs === 'object' && !Array.isArray(responseSettings.deviceConfigs)) {
			deviceConfigsById = responseSettings.deviceConfigs;
			rebuildLastSavedDeviceConfigFingerprints(deviceConfigsById);
		} else {
			for (var k = 0; k < upsertIds.length; k++) {
				var savedUpsertId = upsertIds[k];
				lastSavedDeviceConfigFingerprintById[savedUpsertId] = computeDeviceConfigFingerprint(upsertsToSend[savedUpsertId]);
			}
			for (var m = 0; m < deleteIds.length; m++) {
				delete lastSavedDeviceConfigFingerprintById[deleteIds[m]];
			}
		}

		if (responseSettings && responseSettings.antennaDescriptionsBySerial && typeof responseSettings.antennaDescriptionsBySerial === 'object' && !Array.isArray(responseSettings.antennaDescriptionsBySerial)) {
			antennaDescriptionsBySerial = responseSettings.antennaDescriptionsBySerial;
		} else {
			antennaDescriptionsBySerial = antennaDescriptionsToSend;
		}
		lastSavedAntennaDescriptionsFingerprint = computeUiSettingsFingerprint(antennaDescriptionsBySerial);
		pendingAntennaDescriptionsDirty = false;
	}).catch(function (error) {
		setStatus('Failed to save UI settings: ' + error.message, true);
		for (var n = 0; n < upsertIds.length; n++) {
			var retryUpsertId = upsertIds[n];
			if (
				!Object.prototype.hasOwnProperty.call(pendingDeviceConfigUpsertsById, retryUpsertId)
				&& !Object.prototype.hasOwnProperty.call(pendingDeviceConfigDeletesById, retryUpsertId)
			) {
				pendingDeviceConfigUpsertsById[retryUpsertId] = upsertsToSend[retryUpsertId];
			}
		}
		for (var p = 0; p < deleteIds.length; p++) {
			var retryDeleteId = deleteIds[p];
			if (
				!Object.prototype.hasOwnProperty.call(pendingDeviceConfigUpsertsById, retryDeleteId)
				&& !Object.prototype.hasOwnProperty.call(pendingDeviceConfigDeletesById, retryDeleteId)
			) {
				pendingDeviceConfigDeletesById[retryDeleteId] = true;
			}
		}
		if (antennaDirty) {
			pendingAntennaDescriptionsDirty = true;
		}
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
		if (settings.antennaDescriptionsBySerial && typeof settings.antennaDescriptionsBySerial === 'object' && !Array.isArray(settings.antennaDescriptionsBySerial)) {
			antennaDescriptionsBySerial = settings.antennaDescriptionsBySerial;
		} else {
			antennaDescriptionsBySerial = {};
		}
		pendingDeviceConfigUpsertsById = {};
		pendingDeviceConfigDeletesById = {};
		pendingAntennaDescriptionsDirty = false;
		rebuildLastSavedDeviceConfigFingerprints(deviceConfigsById);
		lastSavedAntennaDescriptionsFingerprint = computeUiSettingsFingerprint(antennaDescriptionsBySerial);
	}).catch(function () {
		deviceConfigsById = {};
		antennaDescriptionsBySerial = {};
		pendingDeviceConfigUpsertsById = {};
		pendingDeviceConfigDeletesById = {};
		pendingAntennaDescriptionsDirty = false;
		rebuildLastSavedDeviceConfigFingerprints(deviceConfigsById);
		lastSavedAntennaDescriptionsFingerprint = computeUiSettingsFingerprint(antennaDescriptionsBySerial);
	});
}

function loadTemplatesFromServer()
{
	return postAction('templates_get', {}).then(function (result) {
		if (result && result.templates && typeof result.templates === 'object' && !Array.isArray(result.templates)) {
			settingsTemplates = result.templates;
		} else {
			settingsTemplates = {};
		}
		lastSavedTemplatesFingerprint = computeTemplatesFingerprint(collectTemplatesPayload());
		return settingsTemplates;
	}).catch(function () {
		settingsTemplates = {};
		lastSavedTemplatesFingerprint = computeTemplatesFingerprint(collectTemplatesPayload());
		return settingsTemplates;
	});
}

function updateSummary()
{
	var detectedCount = knownDetectedDevices.length;
	var runningCount = Object.keys(knownInstancesByDevice).length;
	document.getElementById('summaryText').textContent = detectedCount + ' device(s) detected, ' + runningCount + ' running';
}

function saveDeviceConfigs(deviceId, options)
{
	queueDeviceConfigPersistence(deviceId, options);
}

function saveTemplates()
{
	if (templatesSaveInFlight) {
		templatesSaveQueued = true;
		return Promise.resolve(settingsTemplates);
	}

	var templatesPayload = collectTemplatesPayload();
	var currentFingerprint = computeTemplatesFingerprint(templatesPayload);
	if (currentFingerprint !== '' && currentFingerprint === lastSavedTemplatesFingerprint) {
		return Promise.resolve(settingsTemplates);
	}

	templatesSaveInFlight = true;
	return postUserAction('templates_set', { templates: templatesPayload }).then(function (result) {
		if (result && result.templates && typeof result.templates === 'object' && !Array.isArray(result.templates)) {
			settingsTemplates = result.templates;
		}
		lastSavedTemplatesFingerprint = computeTemplatesFingerprint(collectTemplatesPayload());
		return settingsTemplates;
	}).catch(function (error) {
		setStatus('Failed to save templates: ' + error.message, true);
		throw error;
	}).finally(function () {
		templatesSaveInFlight = false;
		if (templatesSaveQueued) {
			templatesSaveQueued = false;
			saveTemplates().catch(function () {
			});
		}
	});
}

function saveStreamServers()
{
	return postUserAction('stream_servers_set', { servers: streamServersById }).then(function (result) {
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

function saveRecordingServers()
{
	return postUserAction('recording_servers_set', { servers: recordingServersById }).then(function (result) {
		if (result && result.servers && typeof result.servers === 'object') {
			recordingServersById = result.servers;
		}
		return recordingServersById;
	});
}

function loadRecordingServersFromServer()
{
	return postAction('recording_servers_get', {}).then(function (result) {
		if (!result || !result.servers || typeof result.servers !== 'object') {
			recordingServersById = {};
			return recordingServersById;
		}
		recordingServersById = result.servers;
		return recordingServersById;
	}).catch(function () {
		recordingServersById = {};
		return recordingServersById;
	});
}

var currentEditingServerId = null;
var currentEditingRecordingServerId = null;
var currentEditingAntennaDeviceId = '';
var currentEditingAntennaSerial = '';

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

function openRecordingServerDialog(serverId)
{
	currentEditingRecordingServerId = serverId;
	var modal = document.getElementById('recordingServerModal');
	var titleEl = document.getElementById('recordingServerModalTitle');
	var nameEl = document.getElementById('recordingServerModalName');
	var urlEl = document.getElementById('recordingServerModalUrl');
	var usernameEl = document.getElementById('recordingServerModalUsername');
	var passwordEl = document.getElementById('recordingServerModalPassword');
	var deleteBtn = document.getElementById('recordingServerModalDelete');

	if (serverId) {
		var server = getRecordingServerById(serverId);
		if (server) {
			titleEl.textContent = 'Edit Recording Server';
			nameEl.value = String(server.name || '');
			urlEl.value = String(server.url || '');
			usernameEl.value = String(server.username || '');
			passwordEl.value = String(server.password || '');
			deleteBtn.classList.remove('hidden');
		} else {
			return;
		}
	} else {
		titleEl.textContent = 'New Recording Server';
		nameEl.value = '';
		urlEl.value = '';
		usernameEl.value = '';
		passwordEl.value = '';
		deleteBtn.classList.add('hidden');
	}

	modal.classList.remove('hidden');
	nameEl.focus();
}

function closeRecordingServerDialog()
{
	var modal = document.getElementById('recordingServerModal');
	modal.classList.add('hidden');
	currentEditingRecordingServerId = null;
}

function openAntennaDialog(deviceId)
{
	var normalizedDeviceId = String(deviceId || '').trim();
	if (normalizedDeviceId === '') {
		return;
	}

	var currentConfig = getConfigForDevice(normalizedDeviceId);
	var serial = normalizeAntennaSerial(String(currentConfig.deviceSerial || getDeviceSerialForId(normalizedDeviceId)));
	if (serial === '') {
		setStatus('Cannot edit antenna description because this device serial is unavailable.', true);
		return;
	}

	currentEditingAntennaDeviceId = normalizedDeviceId;
	currentEditingAntennaSerial = serial;

	var modal = document.getElementById('antennaModal');
	var titleEl = document.getElementById('antennaModalTitle');
	var deviceEl = document.getElementById('antennaModalDevice');
	var descriptionEl = document.getElementById('antennaModalDescription');
	var clearBtn = document.getElementById('antennaModalClear');
	var currentDescription = getAntennaDescriptionForDevice(normalizedDeviceId, currentConfig);

	titleEl.textContent = currentDescription === '' ? 'Add Antenna Description' : 'Edit Antenna Description';
	deviceEl.value = 'Device ' + normalizedDeviceId + ' (SN: ' + serial + ')';
	descriptionEl.value = currentDescription;
	clearBtn.classList.toggle('hidden', currentDescription === '');

	modal.classList.remove('hidden');
	descriptionEl.focus();
	descriptionEl.setSelectionRange(descriptionEl.value.length, descriptionEl.value.length);
}

function closeAntennaDialog()
{
	var modal = document.getElementById('antennaModal');
	modal.classList.add('hidden');
	currentEditingAntennaDeviceId = '';
	currentEditingAntennaSerial = '';
}

function saveAntennaDialog()
{
	if (currentEditingAntennaSerial === '') {
		closeAntennaDialog();
		return;
	}

	var serial = currentEditingAntennaSerial;
	var deviceId = currentEditingAntennaDeviceId;
	var descriptionEl = document.getElementById('antennaModalDescription');
	var normalizedDescription = normalizeAntennaDescription(descriptionEl ? descriptionEl.value : '');

	if (normalizedDescription === '') {
		if (Object.prototype.hasOwnProperty.call(antennaDescriptionsBySerial, serial)) {
			delete antennaDescriptionsBySerial[serial];
			pendingAntennaDescriptionsDirty = true;
			scheduleUiSettingsSave();
			renderDeviceList();
			setStatus('Cleared antenna description for serial ' + serial + '.', false);
		}
		closeAntennaDialog();
		return;
	}

	if (
		Object.prototype.hasOwnProperty.call(antennaDescriptionsBySerial, serial)
		&& String(antennaDescriptionsBySerial[serial] || '') === normalizedDescription
	) {
		closeAntennaDialog();
		return;
	}

	antennaDescriptionsBySerial[serial] = normalizedDescription;
	pendingAntennaDescriptionsDirty = true;
	scheduleUiSettingsSave();
	renderDeviceList();
	setStatus('Saved antenna description for device ' + deviceId + ' (SN: ' + serial + ').', false);
	closeAntennaDialog();
}

function clearAntennaDialog()
{
	if (currentEditingAntennaSerial === '') {
		closeAntennaDialog();
		return;
	}

	if (!window.confirm('Clear this antenna description?')) {
		return;
	}

	var serial = currentEditingAntennaSerial;
	if (Object.prototype.hasOwnProperty.call(antennaDescriptionsBySerial, serial)) {
		delete antennaDescriptionsBySerial[serial];
		pendingAntennaDescriptionsDirty = true;
		scheduleUiSettingsSave();
		renderDeviceList();
		setStatus('Cleared antenna description for serial ' + serial + '.', false);
	}

	closeAntennaDialog();
}

function saveRecordingServer()
{
	var nameEl = document.getElementById('recordingServerModalName');
	var urlEl = document.getElementById('recordingServerModalUrl');
	var usernameEl = document.getElementById('recordingServerModalUsername');
	var passwordEl = document.getElementById('recordingServerModalPassword');

	var name = nameEl.value.trim();
	var url = urlEl.value.trim();
	var username = usernameEl.value.trim();
	var password = passwordEl.value;

	if (!name) {
		setStatus('Recording server name is required', true);
		return;
	}
	if (!url) {
		setStatus('Upload URL is required', true);
		return;
	}

	var serverId = currentEditingRecordingServerId || getNextRecordingServerId();
	recordingServersById[serverId] = {
		name: name,
		url: url,
		username: username,
		password: password
	};

	saveRecordingServers().then(function () {
		closeRecordingServerDialog();
		renderDeviceList();
		setStatus('Recording server saved', false);
	}).catch(function (error) {
		setStatus(error.message || 'Failed to save recording server', true);
	});
}

function deleteRecordingServer()
{
	if (!currentEditingRecordingServerId) {
		return;
	}
	if (confirm('Delete this recording server?')) {
		delete recordingServersById[currentEditingRecordingServerId];
		saveRecordingServers().then(function () {
			closeRecordingServerDialog();
			renderDeviceList();
			setStatus('Recording server deleted', false);
		}).catch(function (error) {
			setStatus(error.message || 'Failed to delete recording server', true);
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

function normalizeClientDcsCode(value)
{
	var raw = String(value == null ? '' : value).trim().toUpperCase();
	if (raw === '' || raw === 'OFF' || raw === 'NONE') {
		return '';
	}

	var match = raw.match(/^D?([0-7]{1,3})(?:[NI])?$/i);
	if (!match) {
		return '';
	}

	var code = String(match[1]);
	while (code.length < 3) {
		code = '0' + code;
	}

	return code;
}

function normalizeClientCtcssTone(value)
{
	var raw = String(value == null ? '' : value).trim().toUpperCase();
	if (raw === '' || raw === 'OFF' || raw === 'NONE') {
		return '';
	}

	raw = raw.replace(/\s+/g, '');
	raw = raw.replace(/HZ$/, '');
	raw = raw.replace(/^CTCSS[:=-]?/, '');

	var match = raw.match(/^C?([0-9]{2,3}(?:\.[0-9]{1,2})?)$/);
	if (!match) {
		return '';
	}

	var tone = Number(match[1]);
	if (!isFinite(tone) || tone < 60 || tone > 300) {
		return '';
	}

	return tone.toFixed(1);
}

function buildDcsOptions(selectedCode)
{
	var selected = normalizeClientDcsCode(selectedCode);
	var html = '<option value="">Off (No DCS)</option>';

	if (selected !== '' && STANDARD_DCS_CODES.indexOf(selected) === -1) {
		html += '<option value="' + escapeHtml(selected) + '" selected>D' + escapeHtml(selected) + 'N / D' + escapeHtml(selected) + 'I (Custom)</option>';
	}

	for (var i = 0; i < STANDARD_DCS_CODES.length; i++) {
		var code = STANDARD_DCS_CODES[i];
		var selectedAttr = code === selected ? ' selected' : '';
		html += '<option value="' + code + '"' + selectedAttr + '>D' + code + 'N / D' + code + 'I</option>';
	}

	return html;
}

function buildCtcssOptions(selectedTone)
{
	var selected = normalizeClientCtcssTone(selectedTone);
	var html = '<option value="">Off (No CTCSS)</option>';

	if (selected !== '' && STANDARD_CTCSS_TONES.indexOf(selected) === -1) {
		html += '<option value="' + escapeHtml(selected) + '" selected>' + escapeHtml(selected) + ' Hz (Custom)</option>';
	}

	for (var i = 0; i < STANDARD_CTCSS_TONES.length; i++) {
		var tone = STANDARD_CTCSS_TONES[i];
		var selectedAttr = tone === selected ? ' selected' : '';
		html += '<option value="' + tone + '"' + selectedAttr + '>' + tone + ' Hz</option>';
	}

	return html;
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

function buildProxyStreamUrl(config)
{
	var target = String(config.streamTarget || '').trim();
	var mount = normalizeMountByFormat(config.streamMount, config.streamFormat || 'mp3');
	if (target === '') {
		return '';
	}
	return apiUrl + '?proxy=stream&target=' + encodeURIComponent(target) + '&mount=' + encodeURIComponent(mount);
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
	if (!isStreamOutputEnabled(config)) {
		setStatus('Enable Stream output before listening.', true);
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

	var streamUrl = buildProxyStreamUrl(config);
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
	if (!isStreamOutputEnabled(config)) {
		setStatus('Enable Stream output before copying the stream URL.', true);
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
	var selectableCount = 0;
	for (var j = 0; j < devices.length; j++) {
		var id = normalizeClientDeviceId(devices[j].id || devices[j].index || '');
		if (id === '') {
			continue;
		}
		var indexLabel = normalizeClientDeviceIndex(devices[j].index || '');
		var label = String(devices[j].label || ('RTL-SDR Device ' + (indexLabel !== '' ? indexLabel : id)));
		var isRunning = !!knownInstancesByDevice[id];
		var isSelected = selected[id] && !isRunning ? ' selected' : '';
		var disabledAttr = isRunning ? ' disabled' : '';
		if (!isRunning) {
			selectableCount++;
		}
		var displayIndex = indexLabel !== '' ? indexLabel : '?';
		options += '<option value="' + escapeHtml(id) + '"' + isSelected + disabledAttr + '>Dev ' + escapeHtml(displayIndex) + ' - ' + escapeHtml(label) + (isRunning ? ' (running)' : '') + '</option>';
	}

	if (devices.length === 0) {
		options = '<option value="" disabled>No devices available</option>';
	} else if (selectableCount === 0) {
		options += '<option value="" disabled>No stopped devices available</option>';
	}
	deviceSelect.innerHTML = options;
}

function parseClientBooleanFlag(value, defaultValue)
{
	if (typeof value === 'boolean') {
		return value;
	}
	if (typeof value === 'number') {
		return value !== 0;
	}
	var normalized = String(value == null ? '' : value).trim().toLowerCase();
	if (normalized === '') {
		return !!defaultValue;
	}
	if (normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on' || normalized === 'enabled') {
		return true;
	}
	if (normalized === '0' || normalized === 'false' || normalized === 'no' || normalized === 'off' || normalized === 'disabled') {
		return false;
	}
	return !!defaultValue;
}

function deriveClientOutputModeLabel(recordEnabled, streamEnabled)
{
	if (recordEnabled && streamEnabled) {
		return 'both';
	}
	if (streamEnabled) {
		return 'stream';
	}
	return 'recorder';
}

function getClientOutputSelection(config)
{
	var source = (config && typeof config === 'object') ? config : {};
	var hasRecordEnabled = Object.prototype.hasOwnProperty.call(source, 'recordEnabled');
	var hasStreamEnabled = Object.prototype.hasOwnProperty.call(source, 'streamEnabled');
	var recordEnabled = true;
	var streamEnabled = false;

	if (hasRecordEnabled || hasStreamEnabled) {
		recordEnabled = hasRecordEnabled ? parseClientBooleanFlag(source.recordEnabled, false) : false;
		streamEnabled = hasStreamEnabled ? parseClientBooleanFlag(source.streamEnabled, false) : false;
	} else {
		var rawOutputMode = String(source.outputMode || 'recorder').trim().toLowerCase();
		if (
			rawOutputMode === 'both'
			|| rawOutputMode === 'record_stream'
			|| rawOutputMode === 'stream_record'
			|| rawOutputMode === 'recorder_stream'
			|| rawOutputMode === 'stream_recorder'
			|| rawOutputMode === 'record+stream'
			|| rawOutputMode === 'stream+record'
		) {
			recordEnabled = true;
			streamEnabled = true;
		} else if (rawOutputMode === 'stream') {
			recordEnabled = false;
			streamEnabled = true;
		}
	}

	if (!recordEnabled && !streamEnabled) {
		recordEnabled = true;
	}

	return {
		recordEnabled: recordEnabled,
		streamEnabled: streamEnabled,
		outputMode: deriveClientOutputModeLabel(recordEnabled, streamEnabled)
	};
}

function applyClientOutputSelection(config)
{
	var normalizedConfig = Object.assign({}, config || {});
	var selection = getClientOutputSelection(normalizedConfig);
	normalizedConfig.recordEnabled = selection.recordEnabled;
	normalizedConfig.streamEnabled = selection.streamEnabled;
	normalizedConfig.outputMode = selection.outputMode;
	return normalizedConfig;
}

function isRecorderOutputEnabled(config)
{
	return getClientOutputSelection(config).recordEnabled;
}

function isStreamOutputEnabled(config)
{
	return getClientOutputSelection(config).streamEnabled;
}

function sanitizeTemplateConfig(config)
{
	var clean = applyClientOutputSelection(Object.assign({}, config || {}));
	delete clean.device;
	delete clean.deviceIndex;
	delete clean.deviceSerial;
	delete clean.outputDir;
	clean.dcs = normalizeClientDcsCode(clean.dcs);
	clean.ctcss = normalizeClientCtcssTone(clean.ctcss);
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
	merged.deviceIndex = normalizeClientDeviceIndex(String(getDeviceIndexForId(deviceId) || merged.deviceIndex || ''));
	merged.dcs = normalizeClientDcsCode(merged.dcs);
	merged.ctcss = normalizeClientCtcssTone(merged.ctcss);
	merged.streamFormat = String(merged.streamFormat || 'mp3').toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	merged.streamMount = normalizeMountByFormat(merged.streamMount, merged.streamFormat);
	merged = applyClientOutputSelection(merged);
	var streamServerId = String(merged.streamServerId || '').trim();
	if (streamServerId === '' || !getStreamServerById(streamServerId)) {
		streamServerId = resolveStreamServerIdFromConfig(merged);
	}
	merged.streamServerId = streamServerId;
	merged.templateName = name;
	if (!isStreamOutputEnabled(merged)) {
		stopListeningForDevice(deviceId, true);
	}
	deviceConfigsById[String(deviceId)] = merged;
}
function isConfigOpen(deviceId)
{
	return !!openConfigPanelsByDevice[String(deviceId)];
}

function hasExpandedConfigPanels()
{
	for (var deviceId in openConfigPanelsByDevice) {
		if (Object.prototype.hasOwnProperty.call(openConfigPanelsByDevice, deviceId) && !!openConfigPanelsByDevice[deviceId]) {
			return true;
		}
	}

	return false;
}

function isLogOpen(deviceId)
{
	return !!openLogPanelsByDevice[String(deviceId)];
}

function getDefaultConfig(deviceId)
{
	return {
		device: String(deviceId),
		deviceIndex: '',
		frequency: '146.520M',
		mode: 'fm',
		rtlBandwidth: '12000',
		squelch: '500',
		gain: 'auto',
		dcs: '',
		ctcss: '',
		biasT: '0',
		threshold: '-40',
		silence: '2',
		outputMode: 'recorder',
		recordEnabled: true,
		streamEnabled: false,
		streamFormat: 'mp3',
		streamBitrate: '128',
		streamTarget: '127.0.0.1:8000',
		streamMount: '/rtl-sdr.mp3',
		streamUsername: '',
		streamPassword: '',
		streamName: '',
		deviceSerial: '',
		afterRecordAction: 'none',
		recordingServerId: '',
		recordingUploadUrl: '',
		recordingUploadUsername: '',
		recordingUploadPassword: '',
		postCommandArg: '',
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

function isBiasTEnabledValue(value)
{
	var normalized = String(value == null ? '0' : value).trim().toLowerCase();
	return normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on' || normalized === 'enabled';
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
	var recordCheckbox = card.querySelector('.field-record-enabled');
	var streamCheckbox = card.querySelector('.field-stream-enabled');
	if (!recordCheckbox || !streamCheckbox) {
		return;
	}
	var isStream = streamCheckbox.checked;
	var isRecord = recordCheckbox.checked;
	// enforce at least one enabled
	if (!isRecord && !isStream) {
		recordCheckbox.checked = true;
		isRecord = true;
	}
	var streamOnly = card.querySelectorAll('.output-stream-only');
	var recorderOnly = card.querySelectorAll('.output-recorder-only');
	for (var i = 0; i < streamOnly.length; i++) {
		streamOnly[i].classList.toggle('hidden', !isStream);
	}
	for (var j = 0; j < recorderOnly.length; j++) {
		recorderOnly[j].classList.toggle('hidden', !isRecord);
	}
	syncAfterRecordFields(card);
}

function syncAfterRecordFields(card)
{
	var recordCheckbox = card.querySelector('.field-record-enabled');
	var afterRecordSelect = card.querySelector('.field-after-record-action');
	if (!recordCheckbox || !afterRecordSelect) {
		return;
	}

	var isRecorder = recordCheckbox.checked;
	var action = String(afterRecordSelect.value || 'none').toLowerCase();
	if (action !== 'none' && action !== 'upload' && action !== 'upload_delete' && action !== 'command') {
		action = 'none';
		afterRecordSelect.value = 'none';
	}

	var showUpload = isRecorder && (action === 'upload' || action === 'upload_delete');
	var showCommand = isRecorder && action === 'command';

	var uploadRows = card.querySelectorAll('.output-after-record-upload');
	var commandRows = card.querySelectorAll('.output-after-record-command');
	for (var i = 0; i < uploadRows.length; i++) {
		uploadRows[i].classList.toggle('hidden', !showUpload);
	}
	for (var j = 0; j < commandRows.length; j++) {
		commandRows[j].classList.toggle('hidden', !showCommand);
	}

	if (showUpload) {
		var recordingServerSelect = card.querySelector('.field-recording-server-id');
		if (recordingServerSelect && String(recordingServerSelect.value || '').trim() === '') {
			var firstRecordingServerId = getFirstRecordingServerId();
			if (firstRecordingServerId !== '') {
				recordingServerSelect.value = firstRecordingServerId;
			}
		}
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

function extractFilenameFromDisposition(disposition)
{
	var value = String(disposition || '');
	var utfMatch = value.match(/filename\*=UTF-8''([^;]+)/i);
	if (utfMatch && utfMatch[1]) {
		try {
			return decodeURIComponent(String(utfMatch[1]).trim());
		} catch (error) {
			return String(utfMatch[1]).trim();
		}
	}
	var quotedMatch = value.match(/filename="([^"]+)"/i);
	if (quotedMatch && quotedMatch[1]) {
		return String(quotedMatch[1]).trim();
	}
	var plainMatch = value.match(/filename=([^;]+)/i);
	if (plainMatch && plainMatch[1]) {
		return String(plainMatch[1]).trim();
	}
	return '';
}

function downloadLogForDevice(deviceId)
{
	var normalizedDeviceId = String(deviceId || '').trim();
	if (!normalizedDeviceId) {
		setStatus('Device is required to download logs.', true);
		return;
	}

	setStatus('Preparing log download for device ' + normalizedDeviceId + '...', false);
	var downloadUrl = apiUrl + '?action=logs_download&device=' + encodeURIComponent(normalizedDeviceId) + '&_=' + String(Date.now());
	fetch(downloadUrl, { method: 'GET' }).then(function (response) {
		return response.blob().then(function (blob) {
			return { response: response, blob: blob };
		});
	}).then(function (result) {
		if (!result.response.ok) {
			return result.blob.text().then(function (text) {
				var message = 'Failed to download log.';
				try {
					var payload = JSON.parse(text);
					if (payload && payload.error) {
						message = String(payload.error);
					}
				} catch (error) {
					message = text ? String(text).slice(0, 160) : message;
				}
				throw new Error(message);
			});
		}

		var cached = logContentByDevice[normalizedDeviceId] || {};
		var fallbackName = String(cached.logFile || ('rtl_sdr_device_' + normalizedDeviceId + '.log'));
		var downloadName = extractFilenameFromDisposition(result.response.headers.get('content-disposition')) || fallbackName;
		var objectUrl = window.URL.createObjectURL(result.blob);
		var link = document.createElement('a');
		link.href = objectUrl;
		link.download = downloadName;
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		window.setTimeout(function () {
			window.URL.revokeObjectURL(objectUrl);
		}, 0);
		setStatus('Downloaded log for device ' + normalizedDeviceId + '.', false);
	}).catch(function (error) {
		setStatus(error.message || 'Failed to download log.', true);
	});
}

function sanitizeTemplateFilenamePart(value)
{
	var raw = String(value == null ? '' : value).trim();
	if (raw === '') {
		return 'template';
	}

	var cleaned = raw.replace(/[^A-Za-z0-9._-]+/g, '_').replace(/^_+|_+$/g, '');
	return cleaned === '' ? 'template' : cleaned;
}

function getSuggestedTemplateName(config)
{
	var streamName = String(config && config.streamName ? config.streamName : '').trim();
	if (streamName !== '') {
		return streamName;
	}

	var templateName = String(config && config.templateName ? config.templateName : '').trim();
	if (templateName !== '') {
		return templateName;
	}

	return '';
}

function readTextFile(file)
{
	return new Promise(function (resolve, reject) {
		if (!(file instanceof File)) {
			reject(new Error('No file selected.'));
			return;
		}

		var reader = new FileReader();
		reader.onload = function () {
			resolve(String(reader.result || ''));
		};
		reader.onerror = function () {
			reject(new Error('Failed to read file.'));
		};
		reader.readAsText(file, 'utf-8');
	});
}

function parseImportedTemplatePayload(rawValue)
{
	if (!rawValue || typeof rawValue !== 'object' || Array.isArray(rawValue)) {
		throw new Error('Template JSON must be an object.');
	}

	var source = rawValue;
	if (rawValue.template && typeof rawValue.template === 'object' && !Array.isArray(rawValue.template)) {
		source = rawValue.template;
	}

	var name = '';
	if (typeof rawValue.templateName === 'string' && rawValue.templateName.trim() !== '') {
		name = rawValue.templateName.trim();
	} else if (typeof rawValue.name === 'string' && rawValue.name.trim() !== '') {
		name = rawValue.name.trim();
	} else if (typeof source.templateName === 'string' && source.templateName.trim() !== '') {
		name = source.templateName.trim();
	}

	var normalizedTemplate = sanitizeTemplateConfig(source);
	if (name === '') {
		name = getSuggestedTemplateName(normalizedTemplate);
	}
	if (name === '') {
		name = 'Imported Template';
	}

	normalizedTemplate.templateName = name;
	return {
		name: name,
		template: normalizedTemplate,
	};
}

function parseImportedTemplatesPayload(rawValue)
{
	var importedByName = {};

	var addImportedTemplate = function (rawTemplate, fallbackName) {
		var candidate = rawTemplate;
		var fallback = String(fallbackName || '').trim();
		if (fallback !== '') {
			if (!candidate || typeof candidate !== 'object' || Array.isArray(candidate)) {
				candidate = { templateName: fallback, template: candidate };
			} else {
				candidate = Object.assign({}, candidate, { templateName: fallback });
			}
		}

		var imported = parseImportedTemplatePayload(candidate);
		importedByName[imported.name] = imported.template;
	};

	if (Array.isArray(rawValue)) {
		for (var i = 0; i < rawValue.length; i++) {
			addImportedTemplate(rawValue[i], '');
		}
	} else if (rawValue && typeof rawValue === 'object') {
		if (Array.isArray(rawValue.templates)) {
			for (var j = 0; j < rawValue.templates.length; j++) {
				addImportedTemplate(rawValue.templates[j], '');
			}
		} else if (rawValue.templates && typeof rawValue.templates === 'object' && !Array.isArray(rawValue.templates)) {
			for (var mapName in rawValue.templates) {
				if (!Object.prototype.hasOwnProperty.call(rawValue.templates, mapName)) {
					continue;
				}
				addImportedTemplate(rawValue.templates[mapName], mapName);
			}
		} else if (rawValue.template || typeof rawValue.templateName === 'string' || typeof rawValue.name === 'string') {
			addImportedTemplate(rawValue, '');
		} else {
			var keys = Object.keys(rawValue);
			var looksLikeTemplateMap = keys.length > 0;
			for (var k = 0; k < keys.length; k++) {
				var entry = rawValue[keys[k]];
				if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
					looksLikeTemplateMap = false;
					break;
				}
			}

			if (looksLikeTemplateMap) {
				for (var m = 0; m < keys.length; m++) {
					addImportedTemplate(rawValue[keys[m]], keys[m]);
				}
			} else {
				addImportedTemplate(rawValue, '');
			}
		}
	} else {
		throw new Error('Template JSON must be an object or array.');
	}

	if (!Object.keys(importedByName).length) {
		throw new Error('No templates found in JSON.');
	}

	return importedByName;
}

function exportTemplateByName(templateName)
{
	var name = String(templateName || '').trim();
	if (name === '') {
		setStatus('Select a template to export.', true);
		return false;
	}

	if (!settingsTemplates[name] || typeof settingsTemplates[name] !== 'object') {
		setStatus('Selected template was not found.', true);
		return false;
	}

	var payload = {
		templateName: name,
		template: sanitizeTemplateConfig(settingsTemplates[name]),
	};
	payload.template.templateName = name;

	var jsonContent = '';
	try {
		jsonContent = JSON.stringify(payload, null, 2);
	} catch (error) {
		setStatus('Failed to serialize template for export.', true);
		return false;
	}

	var blob = new Blob([jsonContent + '\n'], { type: 'application/json;charset=utf-8' });
	var objectUrl = window.URL.createObjectURL(blob);
	var link = document.createElement('a');
	link.href = objectUrl;
	link.download = sanitizeTemplateFilenamePart(name) + '.json';
	document.body.appendChild(link);
	link.click();
	document.body.removeChild(link);
	window.setTimeout(function () {
		window.URL.revokeObjectURL(objectUrl);
	}, 0);
	setStatus('Exported template "' + name + '".', false);
	return true;
}

function exportAllTemplates()
{
	var names = Object.keys(settingsTemplates).sort();
	if (!names.length) {
		setStatus('No templates available to export.', true);
		return false;
	}

	var payloadTemplates = {};
	for (var i = 0; i < names.length; i++) {
		var name = names[i];
		if (!settingsTemplates[name] || typeof settingsTemplates[name] !== 'object') {
			continue;
		}
		var normalized = sanitizeTemplateConfig(settingsTemplates[name]);
		normalized.templateName = name;
		payloadTemplates[name] = normalized;
	}

	var exportedNames = Object.keys(payloadTemplates);
	if (!exportedNames.length) {
		setStatus('No templates available to export.', true);
		return false;
	}

	var jsonContent = '';
	try {
		jsonContent = JSON.stringify({ templates: payloadTemplates }, null, 2);
	} catch (error) {
		setStatus('Failed to serialize templates for export.', true);
		return false;
	}

	var blob = new Blob([jsonContent + '\n'], { type: 'application/json;charset=utf-8' });
	var objectUrl = window.URL.createObjectURL(blob);
	var link = document.createElement('a');
	link.href = objectUrl;
	link.download = 'rtl_sdr_templates.json';
	document.body.appendChild(link);
	link.click();
	document.body.removeChild(link);
	window.setTimeout(function () {
		window.URL.revokeObjectURL(objectUrl);
	}, 0);

	setStatus('Exported ' + exportedNames.length + ' template(s).', false);
	return true;
}

function exportSelectedTemplateForCard(card)
{
	var templateSelect = card ? card.querySelector('.field-template-name') : null;
	var templateName = templateSelect ? String(templateSelect.value || '').trim() : '';
	exportTemplateByName(templateName);
}

function importTemplateFromFileForToolbar(file)
{
	readTextFile(file).then(function (contents) {
		var parsed = null;
		try {
			parsed = JSON.parse(contents);
		} catch (error) {
			throw new Error('Template file is not valid JSON.');
		}

		var importedTemplates = parseImportedTemplatesPayload(parsed);
		var names = Object.keys(importedTemplates).sort();
		if (!names.length) {
			throw new Error('No templates found in JSON.');
		}

		var overwriteCount = 0;
		for (var i = 0; i < names.length; i++) {
			if (settingsTemplates[names[i]] && typeof settingsTemplates[names[i]] === 'object') {
				overwriteCount++;
			}
		}

		if (overwriteCount > 0) {
			var overwriteMessage = overwriteCount + ' template(s) already exist and will be overwritten. Continue?';
			if (!window.confirm(overwriteMessage)) {
				return null;
			}
		}

		for (var j = 0; j < names.length; j++) {
			var name = names[j];
			settingsTemplates[name] = importedTemplates[name];
		}

		return saveTemplates().then(function () {
			refreshGlobalTemplateSelector();
			var globalTemplateSelect = document.getElementById('globalTemplateSelect');
			if (globalTemplateSelect) {
				globalTemplateSelect.value = names[0];
			}
			renderDeviceList();
			var statusMessage = 'Imported ' + names.length + ' template(s).';
			if (overwriteCount > 0) {
				statusMessage += ' Overwrote ' + overwriteCount + ' existing template(s).';
			}
			setStatus(statusMessage, false);
			return null;
		});
	}).catch(function (error) {
		if (error && error.message) {
			setStatus(error.message, true);
			return;
		}
		setStatus('Failed to import template.', true);
	});
}

function importTemplateFromFileForCard(card, file)
{
	var deviceId = normalizeClientDeviceId(String(card.getAttribute('data-device-id') || '').trim());
	if (!deviceId) {
		setStatus('Device id is required for template import.', true);
		return;
	}

	readTextFile(file).then(function (contents) {
		var parsed = null;
		try {
			parsed = JSON.parse(contents);
		} catch (error) {
			throw new Error('Template file is not valid JSON.');
		}

		var imported = parseImportedTemplatePayload(parsed);
		var existingTemplate = settingsTemplates[imported.name] || null;
		if (existingTemplate) {
			if (!window.confirm('Template "' + imported.name + '" already exists. Overwrite it?')) {
				return null;
			}
		}

		settingsTemplates[imported.name] = imported.template;
		var currentDeviceConfig = Object.assign({}, getConfigForDevice(deviceId));
		currentDeviceConfig.templateName = imported.name;
		deviceConfigsById[deviceId] = currentDeviceConfig;
		saveDeviceConfigs(deviceId);

		return saveTemplates().then(function () {
			refreshGlobalTemplateSelector();
			renderDeviceList();
			setStatus('Imported template "' + imported.name + '".', false);
			return null;
		});
	}).catch(function (error) {
		if (error && error.message) {
			setStatus(error.message, true);
			return;
		}
		setStatus('Failed to import template.', true);
	});
}

function getRxIndicator(deviceId, isRunning, config)
{
	if (!isRunning) {
		return { label: 'Rx Off', className: 'rx-off' };
	}

	var cache = logContentByDevice[String(deviceId)] || null;
	var lines = cache && Array.isArray(cache.lines) ? cache.lines : [];
	// API log payloads are ordered newest-first, so the first matching marker is the latest state.
	for (var i = 0; i < lines.length; i++) {
		var upper = String(lines[i] || '').toUpperCase();
		if (upper.indexOf('[RECORD]') !== -1 && upper.indexOf('AUDIO DETECTED') !== -1) {
			return { label: 'Rx Active', className: 'rx-active' };
		}
		if (upper.indexOf('[SILENCE]') !== -1) {
			return { label: 'Rx Idle', className: 'rx-idle' };
		}
	}

	return { label: 'Rx Idle', className: 'rx-idle' };
}

function getConfigForDevice(deviceId)
{
	var base = getDefaultConfig(deviceId);
	var saved = deviceConfigsById[String(deviceId)] || {};
	var descriptor = getDeviceDescriptor(deviceId);
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
	merged = applyClientOutputSelection(merged);
	merged.device = String(deviceId);
	merged.deviceIndex = normalizeClientDeviceIndex(String(merged.deviceIndex || descriptor.index || ''));
	merged.rtlBandwidth = String(merged.rtlBandwidth || '12000');
	merged.squelch = normalizeClientSquelchValue(merged.squelch);
	merged.dcs = normalizeClientDcsCode(merged.dcs);
	merged.ctcss = normalizeClientCtcssTone(merged.ctcss);
	merged.streamFormat = String(merged.streamFormat || 'mp3').toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	merged.streamBitrate = String(merged.streamBitrate || '128');
	merged.streamTarget = String(merged.streamTarget || '127.0.0.1:8000');
	merged.streamMount = normalizeMountByFormat(merged.streamMount, merged.streamFormat);
	merged.streamUsername = String(merged.streamUsername || '');
	merged.streamPassword = String(merged.streamPassword || '');
	merged.streamServerId = String(merged.streamServerId || '').trim();
	var selectedStreamServer = merged.streamServerId !== '' ? getStreamServerById(merged.streamServerId) : null;
	if (!selectedStreamServer) {
		var inferredStreamServerId = resolveStreamServerIdFromConfig(merged);
		if (inferredStreamServerId !== '') {
			merged.streamServerId = inferredStreamServerId;
			selectedStreamServer = getStreamServerById(inferredStreamServerId);
		} else {
			merged.streamServerId = '';
		}
	}
	if (selectedStreamServer) {
		merged.streamTarget = String(selectedStreamServer.target || merged.streamTarget || '');
		merged.streamUsername = String(selectedStreamServer.username || merged.streamUsername || '');
		merged.streamPassword = String(selectedStreamServer.password || merged.streamPassword || '');
	}
	merged.deviceSerial = String(merged.deviceSerial || getDeviceSerialForId(deviceId));
	if (merged.deviceIndex === '') {
		merged.deviceIndex = normalizeClientDeviceIndex(String(descriptor.index || ''));
	}
	merged.recordingServerId = String(merged.recordingServerId || '');
	merged.recordingUploadUrl = String(merged.recordingUploadUrl || '');
	merged.recordingUploadUsername = String(merged.recordingUploadUsername || '');
	merged.recordingUploadPassword = String(merged.recordingUploadPassword || '');
	merged.postCommandArg = String(merged.postCommandArg || merged.postCommand || '');
	merged.postCommand = merged.postCommandArg;
	merged.afterRecordAction = String(merged.afterRecordAction || '').toLowerCase();
	if (merged.afterRecordAction !== 'upload' && merged.afterRecordAction !== 'upload_delete' && merged.afterRecordAction !== 'command' && merged.afterRecordAction !== 'none') {
		merged.afterRecordAction = merged.postCommandArg !== '' ? 'command' : 'none';
	}
	if (!merged.recordEnabled) {
		merged.afterRecordAction = 'none';
		merged.recordingServerId = '';
		merged.recordingUploadUrl = '';
		merged.recordingUploadUsername = '';
		merged.recordingUploadPassword = '';
		merged.postCommandArg = '';
		merged.postCommand = '';
	} else if (merged.afterRecordAction === 'upload' || merged.afterRecordAction === 'upload_delete') {
		if (merged.recordingServerId === '' || !getRecordingServerById(merged.recordingServerId)) {
			merged.recordingServerId = getFirstRecordingServerId();
		}
		var selectedRecordingServer = getRecordingServerById(merged.recordingServerId);
		if (selectedRecordingServer) {
			merged.recordingUploadUrl = String(selectedRecordingServer.url || merged.recordingUploadUrl || '');
			merged.recordingUploadUsername = String(selectedRecordingServer.username || merged.recordingUploadUsername || '');
			merged.recordingUploadPassword = String(selectedRecordingServer.password || merged.recordingUploadPassword || '');
		}
	}
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

function normalizeClientDeviceIndex(value)
{
	var raw = String(value == null ? '' : value).trim();
	if (!/^\d+$/.test(raw)) {
		return '';
	}

	raw = raw.replace(/^0+/, '');
	return raw === '' ? '0' : raw;
}

function normalizeAntennaSerial(serial)
{
	return String(serial == null ? '' : serial)
		.trim()
		.toUpperCase()
		.replace(/[^A-Za-z0-9._-]+/g, '');
}

function buildClientDeviceId(serial, index)
{
	var normalizedSerial = normalizeAntennaSerial(serial);
	if (normalizedSerial !== '') {
		return 'sn:' + normalizedSerial;
	}

	return normalizeClientDeviceIndex(index);
}

function normalizeClientDeviceId(value)
{
	var raw = String(value == null ? '' : value).trim();
	if (raw === '') {
		return '';
	}

	var serialMatch = raw.match(/^(?:sn|serial)[:=-]?(.+)$/i);
	if (serialMatch) {
		var prefixedSerial = normalizeAntennaSerial(serialMatch[1]);
		return prefixedSerial === '' ? '' : 'sn:' + prefixedSerial;
	}

	var indexMatch = raw.match(/^(?:idx|index)[:=-]?(.+)$/i);
	if (indexMatch) {
		return normalizeClientDeviceIndex(indexMatch[1]);
	}

	var normalizedIndex = normalizeClientDeviceIndex(raw);
	if (normalizedIndex !== '') {
		return normalizedIndex;
	}

	var normalizedSerial = normalizeAntennaSerial(raw);
	if (normalizedSerial !== '') {
		return 'sn:' + normalizedSerial;
	}

	return '';
}

function extractDeviceSerialFromLabel(label)
{
	var match = String(label || '').match(/(?:^|[,\s])SN:\s*([A-Za-z0-9._-]+)/i);
	return match ? normalizeAntennaSerial(String(match[1])) : '';
}

function extractSerialFromClientDeviceId(deviceId)
{
	var normalized = normalizeClientDeviceId(deviceId);
	if (normalized.indexOf('sn:') !== 0) {
		return '';
	}

	return normalizeAntennaSerial(normalized.slice(3));
}

function normalizeDetectedDevices(rawDevices)
{
	var detected = Array.isArray(rawDevices) ? rawDevices : [];
	var normalized = [];
	for (var i = 0; i < detected.length; i++) {
		var entry = detected[i];
		if (!entry || typeof entry !== 'object') {
			continue;
		}

		var index = normalizeClientDeviceIndex(entry.index);
		var serial = normalizeAntennaSerial(entry.serial || extractDeviceSerialFromLabel(entry.label));
		var id = normalizeClientDeviceId(entry.id || buildClientDeviceId(serial, index));
		if (id === '') {
			id = index;
		}
		if (id === '') {
			continue;
		}

		normalized.push({
			id: id,
			index: index,
			label: String(entry.label || ('RTL-SDR Device ' + (index !== '' ? index : id))),
			serial: serial
		});
	}

	return normalized;
}

function getDeviceDescriptor(deviceId)
{
	var normalizedId = normalizeClientDeviceId(deviceId);
	var requestedIndex = normalizeClientDeviceIndex(deviceId);

	for (var i = 0; i < knownDetectedDevices.length; i++) {
		var entry = knownDetectedDevices[i];
		if (!entry || typeof entry !== 'object') {
			continue;
		}

		var entryId = normalizeClientDeviceId(entry.id || buildClientDeviceId(entry.serial, entry.index) || entry.index);
		var entryIndex = normalizeClientDeviceIndex(entry.index);
		if ((normalizedId !== '' && entryId === normalizedId) || (requestedIndex !== '' && entryIndex === requestedIndex)) {
			return {
				id: entryId,
				index: entryIndex,
				label: String(entry.label || ('RTL-SDR Device ' + (entryIndex !== '' ? entryIndex : entryId))),
				serial: normalizeAntennaSerial(entry.serial || extractDeviceSerialFromLabel(entry.label || ''))
			};
		}
	}

	var fallbackId = normalizedId !== '' ? normalizedId : String(deviceId || '').trim();
	var fallbackIndex = normalizeClientDeviceIndex(fallbackId);
	return {
		id: fallbackId,
		index: fallbackIndex,
		label: 'RTL-SDR Device ' + (fallbackIndex !== '' ? fallbackIndex : fallbackId),
		serial: extractSerialFromClientDeviceId(fallbackId)
	};
}

function getDeviceSerialForId(deviceId)
{
	var descriptor = getDeviceDescriptor(deviceId);
	if (descriptor && typeof descriptor.serial === 'string' && descriptor.serial.trim() !== '') {
		return normalizeAntennaSerial(descriptor.serial);
	}

	var serialFromId = extractSerialFromClientDeviceId(deviceId);
	if (serialFromId !== '') {
		return serialFromId;
	}

	return extractDeviceSerialFromLabel(descriptor && descriptor.label ? descriptor.label : '');
}

function getDeviceIndexForId(deviceId)
{
	var descriptor = getDeviceDescriptor(deviceId);
	return normalizeClientDeviceIndex(descriptor && descriptor.index ? descriptor.index : '');
}

function normalizeAntennaDescription(description)
{
	return String(description == null ? '' : description).replace(/\s+/g, ' ').trim();
}

function getAntennaDescriptionForDevice(deviceId, config)
{
	var rawSerial = '';
	if (config && typeof config === 'object') {
		rawSerial = String(config.deviceSerial || '');
	}
	if (rawSerial.trim() === '') {
		rawSerial = getDeviceSerialForId(deviceId);
	}
	var serial = normalizeAntennaSerial(rawSerial);
	if (serial === '') {
		return '';
	}

	return Object.prototype.hasOwnProperty.call(antennaDescriptionsBySerial, serial)
		? String(antennaDescriptionsBySerial[serial] || '')
		: '';
}

function collectVisibleDevices()
{
	var devices = [];
	var seen = {};
	for (var i = 0; i < knownDetectedDevices.length; i++) {
		var device = knownDetectedDevices[i];
		if (!device || typeof device !== 'object') {
			continue;
		}
		var key = normalizeClientDeviceId(device.id || device.index || '');
		if (key === '') {
			continue;
		}
		var index = normalizeClientDeviceIndex(device.index || '');
		var serial = normalizeAntennaSerial(device.serial || extractDeviceSerialFromLabel(device.label || ''));
		var label = String(device.label || ('RTL-SDR Device ' + (index !== '' ? index : key)));
		seen[key] = true;
		devices.push({ id: key, index: index, label: label, serial: serial });
	}

	for (var deviceId in knownInstancesByDevice) {
		if (Object.prototype.hasOwnProperty.call(knownInstancesByDevice, deviceId) && !seen[deviceId]) {
			var normalizedId = normalizeClientDeviceId(deviceId) || String(deviceId);
			var instance = knownInstancesByDevice[deviceId] || {};
			var instanceConfig = (instance && typeof instance.config === 'object' && !Array.isArray(instance.config)) ? instance.config : {};
			var fallbackIndex = normalizeClientDeviceIndex(instanceConfig.deviceIndex || getDeviceIndexForId(normalizedId));
			var fallbackSerial = normalizeAntennaSerial(instanceConfig.deviceSerial || getDeviceSerialForId(normalizedId));
			devices.push({
				id: normalizedId,
				index: fallbackIndex,
				label: 'RTL-SDR Device ' + (fallbackIndex !== '' ? fallbackIndex : normalizedId),
				serial: fallbackSerial
			});
		}
	}

	devices.sort(function (left, right) {
		var leftIndex = normalizeClientDeviceIndex(left && left.index ? left.index : '');
		var rightIndex = normalizeClientDeviceIndex(right && right.index ? right.index : '');
		if (leftIndex !== '' && rightIndex !== '') {
			return Number(leftIndex) - Number(rightIndex);
		}
		if (leftIndex !== '') {
			return -1;
		}
		if (rightIndex !== '') {
			return 1;
		}
		return String(left && left.id ? left.id : '').localeCompare(String(right && right.id ? right.id : ''));
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
		var deviceId = normalizeClientDeviceId(device.id || device.index || '');
		if (deviceId === '') {
			continue;
		}
		var instance = knownInstancesByDevice[deviceId] || null;
		var config = getConfigForDevice(deviceId);
		var displayIndex = normalizeClientDeviceIndex(String(config.deviceIndex || device.index || (instance && instance.config ? instance.config.deviceIndex : '')));
		if (displayIndex === '') {
			displayIndex = '?';
		}
		var isRunning = !!instance;
		var outputSelection = getClientOutputSelection(config);
		var isStreamMode = outputSelection.streamEnabled;
		var isRecordMode = outputSelection.recordEnabled;
		var isBothMode = isStreamMode && isRecordMode;
		var stateClass = isRunning ? 'running' : 'stopped';
		var stateLabel = isRunning ? (isBothMode ? 'Rec+Stream' : (isStreamMode ? 'Streaming' : 'Recording')) : 'Stopped';
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
		var streamControlsEnabled = isStreamMode;
		if ((!streamControlsEnabled || !isRunning) && isListening) {
			stopListeningForDevice(deviceId, true);
			isListening = false;
		}
		var copyControlEnabled = streamControlsEnabled;
		var listenControlEnabled = streamControlsEnabled && isRunning;
		var listenButtonLabel = (isListening && listenControlEnabled) ? 'Mute' : 'Listen';
		var listenButtonClass = (isListening && listenControlEnabled) ? 'refresh-button danger action-listen-stream' : 'refresh-button action-listen-stream';
		var copyControlDisabledAttr = copyControlEnabled ? '' : ' disabled';
		var copyControlDisabledTitleAttr = copyControlEnabled ? '' : ' title="Enable Streaming in config to use this"';
		var listenControlDisabledAttr = listenControlEnabled ? '' : ' disabled';
		var listenControlDisabledTitleAttr = listenControlEnabled
			? ''
			: (streamControlsEnabled
				? ' title="Start the device to listen"'
				: ' title="Enable Streaming in config to use this"');
		var streamActionButtonsHtml =
			'<button type="button" class="refresh-button action-copy-stream"' + copyControlDisabledAttr + copyControlDisabledTitleAttr + '>Copy Stream URL</button>' +
			'<button type="button" class="' + listenButtonClass + '"' + listenControlDisabledAttr + listenControlDisabledTitleAttr + '>' + listenButtonLabel + '</button>';
		var streamNameRow = '';
		var antennaSerial = normalizeAntennaSerial(String(config.deviceSerial || getDeviceSerialForId(deviceId)));
		var antennaDescription = antennaSerial === '' ? '' : getAntennaDescriptionForDevice(deviceId, config);
		var antennaLabelText = antennaSerial === ''
			? 'Antenna: unavailable (missing serial)'
			: 'Antenna: ' + (antennaDescription === '' ? 'Not set' : antennaDescription);
		var antennaEditLabel = antennaDescription === '' ? 'Add' : 'Edit';
		var antennaEditDisabledAttr = antennaSerial === '' ? ' disabled' : '';
		var antennaEditTitleAttr = antennaSerial === '' ? ' title="Device serial unavailable"' : '';
		var biasTEnabled = isBiasTEnabledValue(config.biasT);
		var dcsCode = normalizeClientDcsCode(config.dcs);
		var ctcssTone = normalizeClientCtcssTone(config.ctcss);
		var squelchMetaValue = String(config.squelch || '500');
		if (dcsCode !== '') {
			squelchMetaValue += ' / DCS ' + dcsCode;
		}
		if (ctcssTone !== '') {
			squelchMetaValue += ' / CTCSS ' + ctcssTone + ' Hz';
		}
		if (String(config.streamName || '').trim() !== '') {
			streamNameRow = '<div class="device-stream-name">' + escapeHtml(String(config.streamName || '')) + '</div>';
		}
		markup += '' +
			'<article class="panel device-card" data-device-id="' + escapeHtml(deviceId) + '">' +
				'<div class="device-header">' +
					'<div class="device-title-row">' +
						'<h3 class="device-title">Dev ' + escapeHtml(displayIndex) + '</h3>' +
					'</div>' +
					'<div class="state-pills">' +
						'<div class="state-pill ' + stateClass + '">' + stateLabel + '</div>' +
						'<div class="state-pill ' + rxIndicator.className + '">' + rxIndicator.label + '</div>' +
					'</div>' +
					streamNameRow +
					'<div class="device-subtitle">' + escapeHtml(String(device.label || ('RTL-SDR Device ' + displayIndex))) + '</div>' +
					'<div class="device-antenna-row"><div class="device-antenna-label">' + escapeHtml(antennaLabelText) + '</div><button type="button" class="refresh-button compact action-edit-antenna"' + antennaEditDisabledAttr + antennaEditTitleAttr + '>' + (antennaSerial === '' ? 'Unavailable' : antennaEditLabel) + '</button></div>' +
				'</div>' +
				'<div class="device-actions">' +
					'<button type="button" class="refresh-button primary action-start">' + (isRunning ? 'Retune' : 'Start') + '</button>' +
					'<button type="button" class="refresh-button danger action-stop">Stop</button>' +
					'<button type="button" class="refresh-button danger action-clear-config">Reset</button>' +
					streamActionButtonsHtml +
					'<button type="button" class="refresh-button' + (isConfigOpen(deviceId) ? ' primary' : '') + ' action-toggle-config">Config</button>' +
				'</div>' +
				'<div class="device-meta">' +
					'<div class="meta-box"><span class="meta-label">Frequency</span><span class="meta-value">' + escapeHtml(config.frequency || '') + '</span></div>' +
					'<div class="meta-box"><span class="meta-label">Mode</span><span class="meta-value">' + escapeHtml((config.mode || 'fm').toUpperCase()) + '</span></div>' +
					'<div class="meta-box"><span class="meta-label">Bandwidth</span><span class="meta-value">' + escapeHtml(String(config.rtlBandwidth || '12000') + ' Hz') + '</span></div>' +
					'<div class="meta-box"><span class="meta-label">Bias-T</span><span class="meta-value">' + escapeHtml(biasTEnabled ? 'Enabled' : 'Disabled') + '</span></div>' +
					'<div class="meta-box"><span class="meta-label">PID</span><span class="meta-value">' + escapeHtml(metaPid) + '</span></div>' +
					'<div class="meta-box"><span class="meta-label">Squelch</span><span class="meta-value">' + escapeHtml(squelchMetaValue) + '</span></div>' +
					'<div class="meta-box full"><span class="meta-label">Log File</span><span class="meta-value">' + escapeHtml(metaLog) + '</span></div>' +
				'</div>' +
				'<div class="' + configPanelClass + '">' +
					'<div class="form-row single">' +
						'<div><label>Stream Name</label><input type="text" class="field-stream-name" value="' + escapeHtml(String(config.streamName || '')) + '" placeholder="Optional custom stream name"></div>' +
					'</div>' +
					'<div class="form-row">' +
						'<div><label>Frequency</label><input type="text" class="field-frequency" value="' + escapeHtml(config.frequency || '') + '" placeholder="146.520M or 146.400M-146.600M or 146.435M 146.560M"></div>' +
						'<div><label>Mode</label><select class="field-mode">' +
							'<option value="fm">FM</option>' +
							'<option value="wbfm">WBFM</option>' +
							'<option value="am">AM</option>' +
							'<option value="usb">USB</option>' +
							'<option value="lsb">LSB</option>' +
							'<option value="raw">RAW</option>' +
						'</select></div>' +
					'</div>' +
					'<div class="form-row">' +
						'<div><label>Squelch</label><input type="number" step="1" inputmode="numeric" class="field-squelch" value="' + escapeHtml(String(config.squelch || '500')) + '" placeholder="non-zero integer" title="Must be a non-zero integer"></div>' +
						'<div><label>Gain</label><input type="text" class="field-gain" value="' + escapeHtml(String(config.gain || '')) + '" placeholder="auto or number"></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>DCS Gate</label><select class="field-dcs">' + buildDcsOptions(String(config.dcs || '')) + '</select></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>CTCSS Gate</label><select class="field-ctcss">' + buildCtcssOptions(String(config.ctcss || '')) + '</select></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>RTL Bandwidth</label><select class="field-rtl-bandwidth">' +
							'<option value="12000">12,000 Hz</option>' +
							'<option value="24000">24,000 Hz</option>' +
							'<option value="48000">48,000 Hz</option>' +
							'<option value="96000">96,000 Hz</option>' +
							'<option value="170000">170,000 Hz</option>' +
						'</select></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>Bias-T</label><select class="field-bias-t"><option value="0">Disabled</option><option value="1">Enabled</option></select></div>' +
					'</div>' +
					'<div class="form-row">' +
						'<div><label class="checkbox-label"><input type="checkbox" class="field-record-enabled"> Enable Recording</label></div>' +
						'<div><label class="checkbox-label"><input type="checkbox" class="field-stream-enabled"> Enable Streaming</label></div>' +
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
					'<div class="form-row single output-recorder-only">' +
						'<div><label>After Record</label><select class="field-after-record-action"><option value="none">None</option><option value="upload">Upload</option><option value="upload_delete">Upload and Delete (Locally)</option><option value="command">Run Command</option></select></div>' +
					'</div>' +
					'<div class="form-row output-recorder-only output-after-record-upload hidden">' +
						'<div style="flex: 1;"><label>Upload Server</label><select class="field-recording-server-id">' + buildRecordingServerOptions(String(config.recordingServerId || '')) + '</select></div>' +
						'<div style="flex: 0 0 auto; display: flex; gap: 8px; align-items: flex-end;"><button type="button" class="refresh-button action-edit-recording-server" style="padding: 6px 12px;">Edit</button><button type="button" class="refresh-button action-new-recording-server" style="padding: 6px 12px;">New</button></div>' +
					'</div>' +
					'<div class="form-row single output-recorder-only output-after-record-command hidden">' +
						'<div><label>Run Command Argument (-x)</label><input type="text" class="field-post-command-arg" value="' + escapeHtml(String(config.postCommandArg || '')) + '" placeholder="Argument passed directly to -x"></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>Template</label><select class="field-template-name">' + templateSelectOptions + '</select></div>' +
					'</div>' +
					'<div class="form-row template-button-row">' +
						'<div><button type="button" class="refresh-button action-load-template">Load Template</button></div>' +
						'<div><button type="button" class="refresh-button action-save-template">Save Template</button></div>' +
					'</div>' +
					'<div class="form-row single template-button-row">' +
						'<div><button type="button" class="refresh-button primary action-save-template-start">Save Template + Start</button></div>' +
					'</div>' +
					'<div class="form-row single template-button-row">' +
						'<div><button type="button" class="refresh-button action-save-template-as">Save Template As...</button></div>' +
					'</div>' +
				'</div>' +
				'<div class="device-log-toggle"><button type="button" class="refresh-button action-toggle-log">' + logToggleLabel + '</button><button type="button" class="refresh-button action-download-log">Download Log</button></div>' +
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

function resolveStreamServerIdFromConfig(config)
{
	if (!config || typeof config !== 'object') {
		return '';
	}

	var target = String(config.streamTarget || '').trim();
	if (target === '') {
		return '';
	}

	var username = String(config.streamUsername || '').trim();
	var password = String(config.streamPassword || '');
	var firstTargetMatchId = '';
	for (var id in streamServersById) {
		if (!Object.prototype.hasOwnProperty.call(streamServersById, id)) {
			continue;
		}
		var server = streamServersById[id] || {};
		var serverTarget = String(server.target || '').trim();
		if (serverTarget !== target) {
			continue;
		}
		if (firstTargetMatchId === '') {
			firstTargetMatchId = String(id);
		}
		if (String(server.username || '').trim() === username && String(server.password || '') === password) {
			return String(id);
		}
	}

	return firstTargetMatchId;
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

function buildRecordingServerOptions(selectedServerId)
{
	var html = '<option value="">-- Select Upload Server --</option>';
	for (var id in recordingServersById) {
		var server = recordingServersById[id];
		var selected = id === selectedServerId ? ' selected' : '';
		html += '<option value="' + escapeHtml(String(id)) + '"' + selected + '>' + escapeHtml(String(server.name || id)) + '</option>';
	}
	return html;
}

function getRecordingServerById(serverId)
{
	return recordingServersById[serverId] || null;
}

function getFirstRecordingServerId()
{
	for (var id in recordingServersById) {
		if (Object.prototype.hasOwnProperty.call(recordingServersById, id)) {
			return String(id);
		}
	}

	return '';
}

function getNextRecordingServerId()
{
	var maxId = 0;
	for (var id in recordingServersById) {
		var numId = parseInt(id, 10);
		if (!isNaN(numId) && numId > maxId) {
			maxId = numId;
		}
	}
	return String(maxId + 1);
}

function readCardConfig(card)
{
	var deviceId = normalizeClientDeviceId(String(card.getAttribute('data-device-id') || '').trim());
	var existingConfig = getConfigForDevice(deviceId);
	var descriptor = getDeviceDescriptor(deviceId);
	var deviceIndex = normalizeClientDeviceIndex(String(existingConfig.deviceIndex || descriptor.index || ''));
	var deviceSerial = normalizeAntennaSerial(String(existingConfig.deviceSerial || getDeviceSerialForId(deviceId)));

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

	var recordingServerField = card.querySelector('.field-recording-server-id');
	var recordingServerId = recordingServerField ? recordingServerField.value.trim() : '';
	var recordingUploadUrl = '';
	var recordingUploadUsername = '';
	var recordingUploadPassword = '';
	if (recordingServerId) {
		var recordingServer = getRecordingServerById(recordingServerId);
		if (recordingServer) {
			recordingUploadUrl = String(recordingServer.url || '');
			recordingUploadUsername = String(recordingServer.username || '');
			recordingUploadPassword = String(recordingServer.password || '');
		}
	}

	var dcsField = card.querySelector('.field-dcs');
	var dcsCode = dcsField ? normalizeClientDcsCode(dcsField.value) : '';
	var ctcssField = card.querySelector('.field-ctcss');
	var ctcssTone = ctcssField ? normalizeClientCtcssTone(ctcssField.value) : '';

	var afterRecordSelect = card.querySelector('.field-after-record-action');
	var afterRecordAction = afterRecordSelect ? afterRecordSelect.value.trim() : 'none';
	var postCommandArgField = card.querySelector('.field-post-command-arg');
	var postCommandArg = postCommandArgField ? postCommandArgField.value.trim() : '';
	afterRecordAction = String(afterRecordAction || 'none').toLowerCase();
	if (afterRecordAction !== 'none' && afterRecordAction !== 'upload' && afterRecordAction !== 'upload_delete' && afterRecordAction !== 'command') {
		afterRecordAction = 'none';
	}
	if ((afterRecordAction === 'upload' || afterRecordAction === 'upload_delete') && (recordingServerId === '' || !getRecordingServerById(recordingServerId))) {
		recordingServerId = getFirstRecordingServerId();
	}
	if ((afterRecordAction === 'upload' || afterRecordAction === 'upload_delete') && recordingServerId !== '') {
		var selectedRecordingServer = getRecordingServerById(recordingServerId);
		if (selectedRecordingServer) {
			recordingUploadUrl = String(selectedRecordingServer.url || '');
			recordingUploadUsername = String(selectedRecordingServer.username || '');
			recordingUploadPassword = String(selectedRecordingServer.password || '');
		}
	}
	if (afterRecordAction !== 'command') {
		postCommandArg = '';
	}
	if (afterRecordAction !== 'upload' && afterRecordAction !== 'upload_delete') {
		recordingServerId = '';
		recordingUploadUrl = '';
		recordingUploadUsername = '';
		recordingUploadPassword = '';
	}

	return {
		device: deviceId,
		deviceIndex: deviceIndex,
		deviceSerial: deviceSerial,
		frequency: card.querySelector('.field-frequency').value.trim(),
		mode: card.querySelector('.field-mode').value.trim(),
		rtlBandwidth: card.querySelector('.field-rtl-bandwidth').value.trim(),
		recordEnabled: !!(card.querySelector('.field-record-enabled') && card.querySelector('.field-record-enabled').checked),
		streamEnabled: !!(card.querySelector('.field-stream-enabled') && card.querySelector('.field-stream-enabled').checked),
		outputMode: deriveClientOutputModeLabel(
			!!(card.querySelector('.field-record-enabled') && card.querySelector('.field-record-enabled').checked),
			!!(card.querySelector('.field-stream-enabled') && card.querySelector('.field-stream-enabled').checked)
		),
		squelch: normalizeClientSquelchValue(card.querySelector('.field-squelch').value),
		gain: card.querySelector('.field-gain').value.trim(),
		dcs: dcsCode,
		ctcss: ctcssTone,
		biasT: card.querySelector('.field-bias-t').value.trim(),
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
		afterRecordAction: afterRecordAction,
		recordingServerId: recordingServerId,
		recordingUploadUrl: recordingUploadUrl,
		recordingUploadUsername: recordingUploadUsername,
		recordingUploadPassword: recordingUploadPassword,
		postCommandArg: postCommandArg,
		postCommand: postCommandArg,
		templateName: card.querySelector('.field-template-name').value.trim()
	};
}

function persistCardConfig(card)
{
	var config = readCardConfig(card);
	delete config.outputDir;
	deviceConfigsById[String(config.device)] = config;
	saveDeviceConfigs(String(config.device));
}

function editAntennaDescriptionForDevice(deviceId)
{
	openAntennaDialog(deviceId);
}

function syncDraftConfigsFromOpenCards()
{
	var activelyEditing = isUserEditingDeviceForm();
	var hasExpandedConfigs = hasExpandedConfigPanels();
	if (!activelyEditing && !hasExpandedConfigs) {
		return;
	}

	var cards = document.querySelectorAll('#deviceList .device-card');
	for (var i = 0; i < cards.length; i++) {
		if (!activelyEditing) {
			var cardDeviceId = normalizeClientDeviceId(String(cards[i].getAttribute('data-device-id') || ''));
			if (!isConfigOpen(cardDeviceId)) {
				continue;
			}
		}
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

function shouldPauseAutoRefreshRender()
{
	return isUserEditingDeviceForm() || hasExpandedConfigPanels();
}

function fetchDeviceLogs(deviceId, forceRefresh)
{
	return postAction('logs', { device: String(deviceId), lines: 60 }, { timeoutMs: REFRESH_REQUEST_TIMEOUT_MS }).then(function (result) {
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

function fetchDeviceLogsBatch(deviceIds)
{
	var normalizedDeviceIds = [];
	var seenDevices = {};
	for (var i = 0; i < deviceIds.length; i++) {
		var normalized = String(deviceIds[i] == null ? '' : deviceIds[i]).trim();
		if (normalized === '' || seenDevices[normalized]) {
			continue;
		}
		seenDevices[normalized] = true;
		normalizedDeviceIds.push(normalized);
	}

	if (!normalizedDeviceIds.length) {
		return Promise.resolve(null);
	}

	return postAction('logs_batch', { devices: normalizedDeviceIds, lines: 60 }, { timeoutMs: REFRESH_REQUEST_TIMEOUT_MS }).then(function (result) {
		var logs = (result && result.logs && typeof result.logs === 'object') ? result.logs : {};
		for (var j = 0; j < normalizedDeviceIds.length; j++) {
			var deviceId = normalizedDeviceIds[j];
			var entry = logs[deviceId];
			if (entry && typeof entry === 'object') {
				logContentByDevice[deviceId] = {
					logFile: String(entry.logFile || ''),
					lines: Array.isArray(entry.lines) ? entry.lines : [],
					running: !!entry.running
				};
			} else {
				logContentByDevice[deviceId] = {
					logFile: '',
					lines: [],
					running: false
				};
			}
		}
		return result;
	}).catch(function (error) {
		for (var k = 0; k < normalizedDeviceIds.length; k++) {
			var failedDeviceId = normalizedDeviceIds[k];
			logContentByDevice[failedDeviceId] = {
				logFile: '',
				lines: ['Failed to load logs: ' + error.message],
				running: false
			};
		}
		return null;
	});
}

function refreshOpenLogs(skipRender)
{
	if (!skipRender) {
		refreshOpenLogsRenderRequested = true;
	}

	if (refreshOpenLogsInFlightPromise) {
		refreshOpenLogsQueued = true;
		return refreshOpenLogsInFlightPromise;
	}

	var visibleDeviceIds = [];
	var visibleDevices = collectVisibleDevices();
	for (var i = 0; i < visibleDevices.length; i++) {
		var visibleId = normalizeClientDeviceId(visibleDevices[i].id || visibleDevices[i].index || '');
		if (visibleId !== '') {
			visibleDeviceIds.push(visibleId);
		}
	}
	if (!visibleDeviceIds.length) {
		refreshOpenLogsRenderRequested = false;
		refreshOpenLogsQueued = false;
		return Promise.resolve();
	}

	refreshOpenLogsInFlightPromise = fetchDeviceLogsBatch(visibleDeviceIds).then(function () {
		if (!refreshOpenLogsRenderRequested || shouldPauseAutoRefreshRender()) {
			return;
		}
		renderDeviceList();
	}).finally(function () {
		refreshOpenLogsInFlightPromise = null;
		refreshOpenLogsRenderRequested = false;
		if (refreshOpenLogsQueued) {
			refreshOpenLogsQueued = false;
			window.setTimeout(function () {
				refreshOpenLogs(false);
			}, 0);
		}
	});

	return refreshOpenLogsInFlightPromise;
}

function bindDeviceCard(card)
{
	var deviceId = normalizeClientDeviceId(String(card.getAttribute('data-device-id') || ''));
	var modeSelect = card.querySelector('.field-mode');
	var bandwidthSelect = card.querySelector('.field-rtl-bandwidth');
	var biasTSelect = card.querySelector('.field-bias-t');
	var dcsSelect = card.querySelector('.field-dcs');
	var ctcssSelect = card.querySelector('.field-ctcss');
	var recordEnabledCheckbox = card.querySelector('.field-record-enabled');
	var streamEnabledCheckbox = card.querySelector('.field-stream-enabled');
	var streamFormatSelect = card.querySelector('.field-stream-format');
	var afterRecordSelect = card.querySelector('.field-after-record-action');
	var recordingServerSelect = card.querySelector('.field-recording-server-id');
	var postCommandArgInput = card.querySelector('.field-post-command-arg');
	var storedConfig = getConfigForDevice(deviceId);
	modeSelect.value = storedConfig.mode || 'fm';
	bandwidthSelect.value = String(storedConfig.rtlBandwidth || '12000');
	if (biasTSelect) {
		biasTSelect.value = isBiasTEnabledValue(storedConfig.biasT) ? '1' : '0';
	}
	if (dcsSelect) {
		dcsSelect.value = normalizeClientDcsCode(storedConfig.dcs);
	}
	if (ctcssSelect) {
		ctcssSelect.value = normalizeClientCtcssTone(storedConfig.ctcss);
	}
	var storedOutputSelection = getClientOutputSelection(storedConfig);
	if (recordEnabledCheckbox) {
		recordEnabledCheckbox.checked = storedOutputSelection.recordEnabled;
	}
	if (streamEnabledCheckbox) {
		streamEnabledCheckbox.checked = storedOutputSelection.streamEnabled;
	}
	streamFormatSelect.value = storedConfig.streamFormat || 'mp3';
	if (afterRecordSelect) {
		afterRecordSelect.value = String(storedConfig.afterRecordAction || 'none');
	}
	if (recordingServerSelect) {
		recordingServerSelect.value = String(storedConfig.recordingServerId || '');
	}
	if (postCommandArgInput) {
		postCommandArgInput.value = String(storedConfig.postCommandArg || '');
	}
	syncMountWithStreamFormat(card, false);
	syncOutputModeFields(card);
	if (recordEnabledCheckbox) {
		recordEnabledCheckbox.addEventListener('change', function () {
			syncOutputModeFields(card);
			persistCardConfig(card);
		});
	}
	if (streamEnabledCheckbox) {
		streamEnabledCheckbox.addEventListener('change', function () {
			syncOutputModeFields(card);
			if (!streamEnabledCheckbox.checked) {
				stopListeningForDevice(deviceId, true);
			}
			persistCardConfig(card);
		});
	}
	if (afterRecordSelect) {
		afterRecordSelect.addEventListener('change', function () {
			syncAfterRecordFields(card);
			persistCardConfig(card);
		});
	}
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

	var downloadLogButton = card.querySelector('.action-download-log');
	if (downloadLogButton) {
		downloadLogButton.addEventListener('click', function () {
			downloadLogForDevice(deviceId);
		});
	}

	card.querySelector('.action-start').addEventListener('click', function () {
		startOrRetuneCard(card);
	});

	card.querySelector('.action-stop').addEventListener('click', function () {
		stopCard(card);
	});

	var clearConfigButton = card.querySelector('.action-clear-config');
	if (clearConfigButton) {
		clearConfigButton.addEventListener('click', function () {
			clearConfigForCard(card);
		});
	}

	var editAntennaButton = card.querySelector('.action-edit-antenna');
	if (editAntennaButton) {
		editAntennaButton.addEventListener('click', function () {
			editAntennaDescriptionForDevice(deviceId);
		});
	}

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

	var editRecordingServerButton = card.querySelector('.action-edit-recording-server');
	if (editRecordingServerButton) {
		editRecordingServerButton.addEventListener('click', function () {
			var serverIdField = card.querySelector('.field-recording-server-id');
			var serverId = serverIdField ? serverIdField.value.trim() : '';
			if (serverId) {
				openRecordingServerDialog(serverId);
			} else {
				setStatus('Please select an upload server first', true);
			}
		});
	}

	var newRecordingServerButton = card.querySelector('.action-new-recording-server');
	if (newRecordingServerButton) {
		newRecordingServerButton.addEventListener('click', function () {
			openRecordingServerDialog(null);
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

	var saveTemplateFromCard = function (startAfterSave) {
		var currentConfig = readCardConfig(card);
		var name = String(currentConfig.templateName || '').trim();
		var templateWasSelected = name !== '';
		if (name === '') {
			name = String(currentConfig.streamName || '').trim();
			if (name === '') {
				setStatus('No template selected. Enter Stream Name to create one.', true);
				return;
			}
		}

		var wasExistingTemplate = !!settingsTemplates[name];
		var config = sanitizeTemplateConfig(currentConfig);
		config.templateName = name;
		settingsTemplates[name] = config;
		deviceConfigsById[String(deviceId)] = Object.assign({}, getConfigForDevice(deviceId), config, { templateName: name });
		saveDeviceConfigs(String(deviceId));
		saveTemplates().then(function () {
			refreshGlobalTemplateSelector();
			var templateSelect = card.querySelector('.field-template-name');
			if (templateSelect) {
				templateSelect.value = name;
			}

			if (startAfterSave) {
				setStatus('Template "' + name + '" saved. Starting device ' + deviceId + '...', false);
				startOrRetuneCard(card);
				return;
			}

			renderDeviceList();
			if (!templateWasSelected) {
				setStatus('Template "' + name + '" created and selected.', false);
				return;
			}
			setStatus('Template "' + name + '" ' + (wasExistingTemplate ? 'overwritten.' : 'saved.'), false);
		}).catch(function (error) {
			setStatus(error.message || ('Failed to save template "' + name + '".'), true);
		});
	};

	var saveTemplateButton = card.querySelector('.action-save-template');
	if (saveTemplateButton) {
		saveTemplateButton.addEventListener('click', function () {
			saveTemplateFromCard(false);
		});
	}

	var saveTemplateStartButton = card.querySelector('.action-save-template-start');
	if (saveTemplateStartButton) {
		saveTemplateStartButton.addEventListener('click', function () {
			saveTemplateFromCard(true);
		});
	}

	var saveTemplateAsButton = card.querySelector('.action-save-template-as');
	if (saveTemplateAsButton) {
		saveTemplateAsButton.addEventListener('click', function () {
			var currentConfig = readCardConfig(card);
			var suggestedName = getSuggestedTemplateName(currentConfig);
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
			saveDeviceConfigs(String(deviceId));
			saveTemplates().then(function () {
				refreshGlobalTemplateSelector();
				renderDeviceList();
				setStatus('Template "' + name + '" saved.', false);
			}).catch(function (error) {
				setStatus(error.message || ('Failed to save template "' + name + '".'), true);
			});
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
				saveDeviceConfigs(String(deviceId));
				renderDeviceList();
				setStatus('Loaded template "' + templateName + '" into device ' + deviceId + '.', false);
			} catch (error) {
				setStatus(error.message || 'Failed to load template.', true);
			}
		});
	}
}

function postAction(action, data, options)
{
	var payload = Object.assign({ action: action }, data || {});
	var timeoutMs = 0;
	if (options && options.timeoutMs != null) {
		var parsedTimeout = Number(options.timeoutMs);
		if (isFinite(parsedTimeout) && parsedTimeout > 0) {
			timeoutMs = parsedTimeout;
		}
	}

	var abortController = null;
	var timeoutHandle = null;
	var fetchOptions = {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(payload)
	};

	if (timeoutMs > 0 && typeof AbortController === 'function') {
		abortController = new AbortController();
		fetchOptions.signal = abortController.signal;
		timeoutHandle = window.setTimeout(function () {
			abortController.abort();
		}, timeoutMs);
	}

	return fetch(apiUrl, fetchOptions).then(function (response) {
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
	}).catch(function (error) {
		if (error && error.name === 'AbortError') {
			throw new Error('Request timed out after ' + Math.round(timeoutMs / 1000) + 's.');
		}
		throw error;
	}).finally(function () {
		if (timeoutHandle !== null) {
			window.clearTimeout(timeoutHandle);
		}
	});
}

function isRetryableUserActionError(error)
{
	var message = String(error && error.message ? error.message : '').toLowerCase();
	if (message === '') {
		return false;
	}

	return (
		message.indexOf('timed out') !== -1
		|| message.indexOf('failed to fetch') !== -1
		|| message.indexOf('network') !== -1
		|| message.indexOf('load failed') !== -1
	);
}

function waitForDelay(delayMs)
{
	return new Promise(function (resolve) {
		window.setTimeout(resolve, delayMs > 0 ? delayMs : 0);
	});
}

function postUserAction(action, data, options)
{
	var opts = (options && typeof options === 'object') ? options : {};
	var actionLabel = String(action == null ? '' : action).trim().replace(/_/g, ' ');
	if (actionLabel === '') {
		actionLabel = 'operation';
	}
	var timeoutMs = Number(opts.timeoutMs);
	if (!isFinite(timeoutMs) || timeoutMs <= 0) {
		timeoutMs = USER_ACTION_REQUEST_TIMEOUT_MS;
	}

	var maxAttempts = Number(opts.maxAttempts);
	if (!isFinite(maxAttempts) || maxAttempts < 1) {
		maxAttempts = USER_ACTION_MAX_ATTEMPTS;
	} else {
		maxAttempts = Math.floor(maxAttempts);
	}

	var retryDelayMs = Number(opts.retryDelayMs);
	if (!isFinite(retryDelayMs) || retryDelayMs < 0) {
		retryDelayMs = USER_ACTION_RETRY_DELAY_MS;
	}

	var attemptNumber = 1;
	var retryNoticeShown = false;
	var runAttempt = function () {
		return postAction(action, data, { timeoutMs: timeoutMs }).catch(function (error) {
			if (attemptNumber >= maxAttempts || !isRetryableUserActionError(error)) {
				throw error;
			}

			attemptNumber++;
			if (!retryNoticeShown) {
				retryNoticeShown = true;
				setStatus('Retrying ' + actionLabel + ' request...', false);
			}
			return waitForDelay(retryDelayMs * (attemptNumber - 1)).then(runAttempt);
		});
	};

	return runAttempt();
}

function scanDevices()
{
	setStatus('Scanning RTL-SDR devices...', false);
	syncDraftConfigsFromOpenCards();
	return postUserAction('devices', {}).then(function (result) {
		knownDetectedDevices = normalizeDetectedDevices(result.devices);
		var pauseAutoRender = shouldPauseAutoRefreshRender();
		if (!pauseAutoRender) {
			renderDeviceList();
		}
		if (result.warning) {
			setStatus(result.warning, true);
			return;
		}

		setStatus('Detected ' + knownDetectedDevices.length + ' RTL-SDR device(s).' + (pauseAutoRender ? ' UI refresh paused while config is open.' : ''), false);
	}).catch(function (error) {
		setStatus(error.message, true);
	});
}

function refreshInstances()
{
	if (refreshInstancesInFlightPromise) {
		refreshInstancesQueued = true;
		return refreshInstancesInFlightPromise;
	}

	syncDraftConfigsFromOpenCards();
	refreshInstancesInFlightPromise = postAction('list', {}, { timeoutMs: REFRESH_REQUEST_TIMEOUT_MS }).then(function (result) {
		knownInstancesByDevice = {};
		for (var i = 0; i < result.instances.length; i++) {
			knownInstancesByDevice[String(result.instances[i].device)] = result.instances[i];
		}
		applyUiSettingsFromServerPayload(result && result.settings ? result.settings : null);
		var pauseAutoRender = shouldPauseAutoRefreshRender();
		if (!pauseAutoRender) {
			renderDeviceList();
		}
		setStatus('Loaded ' + result.instances.length + ' running instance(s).' + (pauseAutoRender ? ' UI refresh paused while config is open.' : ''), false);
		return refreshOpenLogs(pauseAutoRender);
	}).catch(function (error) {
		setStatus(error.message, true);
	}).finally(function () {
		refreshInstancesInFlightPromise = null;
		if (refreshInstancesQueued) {
			refreshInstancesQueued = false;
			window.setTimeout(function () {
				refreshInstances();
			}, 0);
		}
	});

	return refreshInstancesInFlightPromise;
}

function startOrRetuneCard(card)
{
	var config = readCardConfig(card);
	deviceConfigsById[String(config.device)] = config;
	saveDeviceConfigs(String(config.device));
	openConfigPanelsByDevice[String(config.device)] = false;
	setStatus('Applying config for device ' + (config.device || '?') + '...', false);
	postUserAction('start', config).then(function (result) {
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
	var deviceId = normalizeClientDeviceId(String(card.getAttribute('data-device-id') || '').trim());
	if (!deviceId) {
		setStatus('Device identifier is required before stopping.', true);
		return;
	}

	if (!window.confirm('Stop device ' + deviceId + '?')) {
		return;
	}

	stopListeningForDevice(deviceId, true);

	setStatus('Stopping device ' + deviceId + '...', false);
	postUserAction('stop', { device: deviceId }).then(function (result) {
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

function clearConfigForCard(card)
{
	var deviceId = normalizeClientDeviceId(String(card.getAttribute('data-device-id') || '').trim());
	if (!deviceId) {
		setStatus('Device id is required.', true);
		return;
	}

	var resetToDefaults = function () {
		delete deviceConfigsById[deviceId];
		saveDeviceConfigs(deviceId, { remove: true });
		stopListeningForDevice(deviceId, true);
		renderDeviceList();
	};

	if (!knownInstancesByDevice[deviceId]) {
		resetToDefaults();
		setStatus('Reset device ' + deviceId + ' config to defaults.', false);
		return;
	}

	if (!window.confirm('Device ' + deviceId + ' is running. Stop it and clear its config?')) {
		return;
	}

	setStatus('Stopping device ' + deviceId + ' and resetting config...', false);
	postUserAction('stop', { device: deviceId }).then(function (result) {
		knownInstancesByDevice = {};
		for (var i = 0; i < result.instances.length; i++) {
			knownInstancesByDevice[String(result.instances[i].device)] = result.instances[i];
		}
		logContentByDevice[deviceId] = { logFile: '', lines: ['Device stopped and config reset to defaults.'], running: false };
		resetToDefaults();
		setStatus('Stopped device ' + deviceId + ' and reset config to defaults.', false);
	}).catch(function (error) {
		setStatus(error.message || ('Failed to clear config for device ' + deviceId + '.'), true);
	});
}

function stopDeviceById(deviceId)
{
	var normalizedDeviceId = normalizeClientDeviceId(String(deviceId || '').trim());
	if (normalizedDeviceId === '') {
		return;
	}

	if (!window.confirm('Stop device ' + normalizedDeviceId + '?')) {
		return;
	}

	stopListeningForDevice(normalizedDeviceId, true);
	postUserAction('stop', { device: normalizedDeviceId }).then(function (result) {
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
	installZoomGuards();

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
		if (!window.confirm('Stop all running devices?')) {
			return;
		}
		setStatus('Stopping all devices...', false);
		postUserAction('stop_all', {}).then(function (result) {
			setStatus(result.message || 'Stopped all.', false);
			knownInstancesByDevice = {};
			renderDeviceList();
		}).catch(function (error) {
			setStatus(error.message, true);
		});
	});

	document.getElementById('startTemplateSelectedButton').addEventListener('click', function () {
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
			if (targetSelect.options[i].selected && !targetSelect.options[i].disabled) {
				selectedTargets.push(String(targetSelect.options[i].value));
			}
		}
		if (!selectedTargets.length) {
			setStatus('Select one or more stopped target devices.', true);
			return;
		}

		var startTargets = [];
		var skippedRunning = 0;
		for (var j = 0; j < selectedTargets.length; j++) {
			var targetDeviceId = String(selectedTargets[j]);
			if (knownInstancesByDevice[targetDeviceId]) {
				skippedRunning++;
				continue;
			}
			applyTemplateToDevice(targetDeviceId, templateName);
			startTargets.push(targetDeviceId);
		}
		if (!startTargets.length) {
			setStatus('Cannot start templates on running devices.', true);
			return;
		}

		for (var k = 0; k < startTargets.length; k++) {
			saveDeviceConfigs(String(startTargets[k]));
		}
		renderDeviceList();
		setStatus('Starting template "' + templateName + '" on ' + startTargets.length + ' device(s)...', false);

		var startedCount = 0;
		var failedCount = 0;
		var latestInstances = null;
		var sequence = Promise.resolve();
		for (var m = 0; m < startTargets.length; m++) {
			(function (deviceId) {
				sequence = sequence.then(function () {
					var config = getConfigForDevice(deviceId);
					return postUserAction('start', config).then(function (result) {
						startedCount++;
						if (result && Array.isArray(result.instances)) {
							latestInstances = result.instances;
						}
						return null;
					}).catch(function () {
						failedCount++;
						return null;
					});
				});
			})(startTargets[m]);
		}

		sequence.then(function () {
			if (latestInstances) {
				knownInstancesByDevice = {};
				for (var n = 0; n < latestInstances.length; n++) {
					knownInstancesByDevice[String(latestInstances[n].device)] = latestInstances[n];
				}
			}
			renderDeviceList();

			if (startedCount === 0) {
				setStatus('Failed to start template "' + templateName + '" on selected devices.', true);
				return;
			}

			var message = 'Started template "' + templateName + '" on ' + startedCount + ' device(s).';
			if (failedCount > 0) {
				message += ' Failed to start ' + failedCount + ' device(s).';
			}
			if (skippedRunning > 0) {
				message += ' Skipped ' + skippedRunning + ' running device(s).';
			}
			setStatus(message, failedCount > 0);
		}).catch(function (error) {
			setStatus(error.message || 'Failed to start template on selected devices.', true);
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
			if (targetSelect.options[i].selected && !targetSelect.options[i].disabled) {
				selectedTargets.push(String(targetSelect.options[i].value));
			}
		}
		if (!selectedTargets.length) {
			setStatus('Select one or more stopped target devices.', true);
			return;
		}
		var skippedRunning = 0;
		var appliedCount = 0;
		for (var j = 0; j < selectedTargets.length; j++) {
			var targetDeviceId = String(selectedTargets[j]);
			if (knownInstancesByDevice[targetDeviceId]) {
				skippedRunning++;
				continue;
			}
			applyTemplateToDevice(targetDeviceId, templateName);
			appliedCount++;
		}
		if (appliedCount === 0) {
			setStatus('Cannot apply templates to running devices.', true);
			return;
		}
		for (var k = 0; k < selectedTargets.length; k++) {
			var changedDeviceId = String(selectedTargets[k]);
			if (knownInstancesByDevice[changedDeviceId]) {
				continue;
			}
			saveDeviceConfigs(changedDeviceId);
		}
		renderDeviceList();
		if (skippedRunning > 0) {
			setStatus('Applied template "' + templateName + '" to ' + appliedCount + ' device(s); skipped ' + skippedRunning + ' running device(s).', false);
			return;
		}
		setStatus('Applied template "' + templateName + '" to ' + appliedCount + ' device(s).', false);
	});

	document.getElementById('exportGlobalTemplateButton').addEventListener('click', function () {
		var templateSelect = document.getElementById('globalTemplateSelect');
		var templateName = templateSelect ? String(templateSelect.value || '') : '';
		exportTemplateByName(templateName);
	});

	document.getElementById('exportAllGlobalTemplatesButton').addEventListener('click', function () {
		exportAllTemplates();
	});

	var importGlobalTemplateButton = document.getElementById('importGlobalTemplateButton');
	var importGlobalTemplateFileInput = document.getElementById('importGlobalTemplateFileInput');
	if (importGlobalTemplateButton && importGlobalTemplateFileInput) {
		importGlobalTemplateButton.addEventListener('click', function () {
			importGlobalTemplateFileInput.click();
		});

		importGlobalTemplateFileInput.addEventListener('change', function () {
			var file = importGlobalTemplateFileInput.files && importGlobalTemplateFileInput.files[0] ? importGlobalTemplateFileInput.files[0] : null;
			if (!file) {
				return;
			}

			importTemplateFromFileForToolbar(file);
			importGlobalTemplateFileInput.value = '';
		});
	}

	document.getElementById('serverModalClose').addEventListener('click', closeServerDialog);
	document.getElementById('serverModalCancel').addEventListener('click', closeServerDialog);
	document.getElementById('serverModalSave').addEventListener('click', saveServer);
	document.getElementById('serverModalDelete').addEventListener('click', deleteServer);
	document.getElementById('serverModal').addEventListener('click', function (e) {
		if (e.target === this) {
			closeServerDialog();
		}
	});

	document.getElementById('recordingServerModalClose').addEventListener('click', closeRecordingServerDialog);
	document.getElementById('recordingServerModalCancel').addEventListener('click', closeRecordingServerDialog);
	document.getElementById('recordingServerModalSave').addEventListener('click', saveRecordingServer);
	document.getElementById('recordingServerModalDelete').addEventListener('click', deleteRecordingServer);
	document.getElementById('recordingServerModal').addEventListener('click', function (e) {
		if (e.target === this) {
			closeRecordingServerDialog();
		}
	});

	document.getElementById('antennaModalClose').addEventListener('click', closeAntennaDialog);
	document.getElementById('antennaModalCancel').addEventListener('click', closeAntennaDialog);
	document.getElementById('antennaModalSave').addEventListener('click', saveAntennaDialog);
	document.getElementById('antennaModalClear').addEventListener('click', clearAntennaDialog);
	document.getElementById('antennaModal').addEventListener('click', function (e) {
		if (e.target === this) {
			closeAntennaDialog();
		}
	});
	document.getElementById('antennaModalDescription').addEventListener('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			saveAntennaDialog();
		}
	});

	var templatesExpanded = null;
	try {
		templatesExpanded = window.localStorage.getItem('rtlSdrTemplatesExpanded');
	} catch (error) {
		templatesExpanded = null;
	}
	setTemplateToolbarVisible(templatesExpanded === '1');

	Promise.all([loadUiSettingsFromServer(), loadTemplatesFromServer()]).finally(function () {
		refreshGlobalTemplateSelector();
		refreshTemplateDeviceSelector();
		renderDeviceList();
		Promise.all([loadStreamServersFromServer(), loadRecordingServersFromServer()]).finally(function () {
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
