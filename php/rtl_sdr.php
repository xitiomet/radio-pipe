<?php

declare(strict_types=1);

$RTL_PAGE_TITLE = 'RTL-SDR Controller';
$STATE_FILE = __DIR__ . '/rtl_sdr_state.json';
$DESIRED_STATE_FILE = __DIR__ . '/rtl_sdr_desired_state.json';
$STREAMING_SERVERS_FILE = __DIR__ . '/streaming_servers.json';
$RECORDING_SERVERS_FILE = __DIR__ . '/recording_servers.json';
$UI_SETTINGS_FILE = __DIR__ . '/rtl_sdr_ui_settings.json';
$PIPE_OUTPUTS_FILE = __DIR__ . '/pipe_outputs.json';
$TEMPLATES_FILE = __DIR__ . '/rtl_sdr_templates.json';
$TEMPLATES_DIR = __DIR__ . '/templates';
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
$DEVICE_SCAN_MIN_INTERVAL_SECONDS = isset($DEVICE_SCAN_MIN_INTERVAL_SECONDS) ? max(10, (int)$DEVICE_SCAN_MIN_INTERVAL_SECONDS) : 60;
$DEVICE_SCAN_CACHE_FILE = isset($DEVICE_SCAN_CACHE_FILE) && trim((string)$DEVICE_SCAN_CACHE_FILE) !== ''
	? trim((string)$DEVICE_SCAN_CACHE_FILE)
	: (__DIR__ . '/rtl_sdr_device_scan_cache.json');
$ACTION_QUEUE_FILE = isset($ACTION_QUEUE_FILE) && trim((string)$ACTION_QUEUE_FILE) !== ''
	? trim((string)$ACTION_QUEUE_FILE)
	: (__DIR__ . '/rtl_sdr_action_queue.json');
$ACTION_QUEUE_MIN_SPACING_SECONDS = isset($ACTION_QUEUE_MIN_SPACING_SECONDS)
	? max(1, (int)$ACTION_QUEUE_MIN_SPACING_SECONDS)
	: 2;
$ACTION_QUEUE_MAX_ACTIONS_PER_TICK = isset($ACTION_QUEUE_MAX_ACTIONS_PER_TICK)
	? max(1, min(5, (int)$ACTION_QUEUE_MAX_ACTIONS_PER_TICK))
	: 1;
$ACTION_QUEUE_STOP_START_DELAY_MS = isset($ACTION_QUEUE_STOP_START_DELAY_MS)
	? max(0, (int)$ACTION_QUEUE_STOP_START_DELAY_MS)
	: 1200;
$ACTION_QUEUE_MAX_PENDING = isset($ACTION_QUEUE_MAX_PENDING)
	? max(10, (int)$ACTION_QUEUE_MAX_PENDING)
	: 200;
$STREAM_FFMPEG_RETRY_ENABLED = isset($STREAM_FFMPEG_RETRY_ENABLED)
	? parse_boolean_flag($STREAM_FFMPEG_RETRY_ENABLED, true)
	: true;
$STREAM_FFMPEG_RETRY_DELAY_SECONDS = isset($STREAM_FFMPEG_RETRY_DELAY_SECONDS)
	? max(1, min(30, (int)$STREAM_FFMPEG_RETRY_DELAY_SECONDS))
	: 2;
$STREAM_FFMPEG_RETRY_MAX_ATTEMPTS = isset($STREAM_FFMPEG_RETRY_MAX_ATTEMPTS)
	? max(0, (int)$STREAM_FFMPEG_RETRY_MAX_ATTEMPTS)
	: 0;
$STREAM_FFMPEG_TCP_TIMEOUT_US = isset($STREAM_FFMPEG_TCP_TIMEOUT_US)
	? max(1000000, (int)$STREAM_FFMPEG_TCP_TIMEOUT_US)
	: 15000000;
$STREAM_FFMPEG_HTTP_MULTIPLE_REQUESTS = isset($STREAM_FFMPEG_HTTP_MULTIPLE_REQUESTS)
	? parse_boolean_flag($STREAM_FFMPEG_HTTP_MULTIPLE_REQUESTS, true)
	: true;
$STREAM_FFMPEG_TCP_NODELAY = isset($STREAM_FFMPEG_TCP_NODELAY)
	? parse_boolean_flag($STREAM_FFMPEG_TCP_NODELAY, true)
	: true;
$RMS_STDOUT_PAD_DELAY_MS = isset($RMS_STDOUT_PAD_DELAY_MS)
	? max(0, min(5000, (int)$RMS_STDOUT_PAD_DELAY_MS))
	: 150;
$RMS_INPUT_DEJITTER_MS = isset($RMS_INPUT_DEJITTER_MS)
	? max(0, (int)$RMS_INPUT_DEJITTER_MS)
	: 0;
$RADIO_PIPE_API_WEBSOCKET_HOST = isset($RADIO_PIPE_API_WEBSOCKET_HOST) && trim((string)$RADIO_PIPE_API_WEBSOCKET_HOST) !== ''
	? trim((string)$RADIO_PIPE_API_WEBSOCKET_HOST)
	: '0.0.0.0';
$RADIO_PIPE_API_WEBSOCKET_BASE_PORT = isset($RADIO_PIPE_API_WEBSOCKET_BASE_PORT)
	? max(1024, min(65000, (int)$RADIO_PIPE_API_WEBSOCKET_BASE_PORT))
	: 9400;

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
		$instance['pid'] = max(0, (int)($instance['pid'] ?? 0));
		$instance['pgid'] = max(0, (int)($instance['pgid'] ?? 0));
		if ((int)$instance['pgid'] <= 0 && (int)$instance['pid'] > 0) {
			$instance['pgid'] = (int)$instance['pid'];
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

function acquire_runtime_state_lock(string $stateFile)
{
	$lockFile = $stateFile . '.lock';
	$lockDir = dirname($lockFile);
	if ($lockDir !== '' && $lockDir !== '.' && !is_dir($lockDir)) {
		if (!@mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
			return null;
		}
	}

	$lockHandle = @fopen($lockFile, 'c+');
	if ($lockHandle === false) {
		return null;
	}

	if (!flock($lockHandle, LOCK_EX)) {
		fclose($lockHandle);
		return null;
	}

	return $lockHandle;
}

function release_runtime_state_lock($lockHandle): void
{
	if (!is_resource($lockHandle)) {
		return;
	}

	flock($lockHandle, LOCK_UN);
	fclose($lockHandle);
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

function get_action_queue_settings(): array
{
	global $ACTION_QUEUE_FILE;
	global $ACTION_QUEUE_MIN_SPACING_SECONDS;
	global $ACTION_QUEUE_MAX_ACTIONS_PER_TICK;
	global $ACTION_QUEUE_STOP_START_DELAY_MS;
	global $ACTION_QUEUE_MAX_PENDING;

	$queueFile = trim((string)$ACTION_QUEUE_FILE);
	if ($queueFile === '') {
		$queueFile = __DIR__ . '/rtl_sdr_action_queue.json';
	}

	return array(
		'queueFile' => $queueFile,
		'minSpacingSeconds' => max(1, (int)$ACTION_QUEUE_MIN_SPACING_SECONDS),
		'maxActionsPerTick' => max(1, min(5, (int)$ACTION_QUEUE_MAX_ACTIONS_PER_TICK)),
		'stopStartDelayUs' => max(0, (int)$ACTION_QUEUE_STOP_START_DELAY_MS) * 1000,
		'maxPending' => max(10, (int)$ACTION_QUEUE_MAX_PENDING),
	);
}

function normalize_action_queue_item(array $item): ?array
{
	$action = strtolower(trim((string)($item['action'] ?? '')));
	if ($action !== 'start' && $action !== 'retune') {
		return null;
	}

	$config = isset($item['config']) && is_array($item['config'])
		? $item['config']
		: array();
	$device = normalize_device_id((string)($item['device'] ?? ($config['device'] ?? '')));
	if ($device !== '') {
		$config['device'] = $device;
	}

	$id = trim((string)($item['id'] ?? ''));
	if ($id === '') {
		$id = uniqid('queue_', true);
	}

	return array(
		'id' => $id,
		'action' => $action,
		'device' => $device,
		'config' => $config,
		'createdAt' => max(0, (int)($item['createdAt'] ?? time())),
	);
}

function load_action_queue_data(string $queueFile): array
{
	$empty = array(
		'items' => array(),
		'lastProcessedAt' => 0,
		'lastResult' => array(),
	);

	if ($queueFile === '' || !file_exists($queueFile)) {
		return $empty;
	}

	$raw = @file_get_contents($queueFile);
	if (!is_string($raw) || trim($raw) === '') {
		return $empty;
	}

	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		return $empty;
	}

	$itemsRaw = isset($decoded['items']) && is_array($decoded['items'])
		? $decoded['items']
		: array();
	$items = array();
	foreach ($itemsRaw as $rawItem) {
		if (!is_array($rawItem)) {
			continue;
		}
		$normalized = normalize_action_queue_item($rawItem);
		if (is_array($normalized)) {
			$items[] = $normalized;
		}
	}

	$lastResult = isset($decoded['lastResult']) && is_array($decoded['lastResult'])
		? $decoded['lastResult']
		: array();

	return array(
		'items' => $items,
		'lastProcessedAt' => max(0, (int)($decoded['lastProcessedAt'] ?? 0)),
		'lastResult' => $lastResult,
	);
}

function save_action_queue_data(string $queueFile, array $queue): bool
{
	if ($queueFile === '') {
		return false;
	}

	$dir = dirname($queueFile);
	if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
		if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
			return false;
		}
	}

	$itemsRaw = isset($queue['items']) && is_array($queue['items']) ? $queue['items'] : array();
	$items = array();
	foreach ($itemsRaw as $rawItem) {
		if (!is_array($rawItem)) {
			continue;
		}
		$normalized = normalize_action_queue_item($rawItem);
		if (is_array($normalized)) {
			$items[] = $normalized;
		}
	}

	$payload = array(
		'items' => $items,
		'lastProcessedAt' => max(0, (int)($queue['lastProcessedAt'] ?? 0)),
		'lastResult' => isset($queue['lastResult']) && is_array($queue['lastResult'])
			? $queue['lastResult']
			: array(),
	);

	$encoded = json_encode($payload, JSON_PRETTY_PRINT);
	if (!is_string($encoded)) {
		return false;
	}

	return @file_put_contents($queueFile, $encoded . "\n", LOCK_EX) !== false;
}

function is_watchdog_queue_worker_request(string $requestSource): bool
{
	if (strtolower(trim($requestSource)) !== 'watchdog') {
		return false;
	}

	$remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
	return $remoteAddr === '127.0.0.1' || $remoteAddr === '::1' || $remoteAddr === '::ffff:127.0.0.1';
}

function enqueue_stream_action_request(string $action, array $config): array
{
	$normalized = normalize_action_queue_item(array(
		'action' => $action,
		'device' => (string)($config['device'] ?? ''),
		'config' => $config,
		'createdAt' => time(),
	));
	if (!is_array($normalized)) {
		return array('ok' => false, 'error' => 'Unsupported queued action: ' . $action);
	}

	$settings = get_action_queue_settings();
	$queueFile = (string)$settings['queueFile'];
	$lockFile = $queueFile . '.lock';
	$lockHandle = @fopen($lockFile, 'c+');
	if ($lockHandle === false) {
		return array('ok' => false, 'error' => 'Failed to open action queue lock file.');
	}

	if (!flock($lockHandle, LOCK_EX)) {
		fclose($lockHandle);
		return array('ok' => false, 'error' => 'Failed to lock action queue file.');
	}

	$queue = load_action_queue_data($queueFile);
	$existingItems = isset($queue['items']) && is_array($queue['items']) ? $queue['items'] : array();
	$queue['items'] = array();
	$targetDevice = normalize_device_id((string)($normalized['device'] ?? ''));

	foreach ($existingItems as $existingItem) {
		if (!is_array($existingItem)) {
			continue;
		}
		$existingNormalized = normalize_action_queue_item($existingItem);
		if (!is_array($existingNormalized)) {
			continue;
		}

		$existingDevice = normalize_device_id((string)($existingNormalized['device'] ?? ''));
		if ($targetDevice !== '' && $existingDevice !== '' && $existingDevice === $targetDevice) {
			continue;
		}

		$queue['items'][] = $existingNormalized;
	}

	$queue['items'][] = $normalized;
	$maxPending = max(10, (int)($settings['maxPending'] ?? 200));
	while (count($queue['items']) > $maxPending) {
		array_shift($queue['items']);
	}

	$saveOk = save_action_queue_data($queueFile, $queue);
	flock($lockHandle, LOCK_UN);
	fclose($lockHandle);

	if (!$saveOk) {
		return array('ok' => false, 'error' => 'Failed to save action queue file.');
	}

	return array(
		'ok' => true,
		'id' => (string)$normalized['id'],
		'position' => count($queue['items']),
		'device' => (string)($normalized['device'] ?? ''),
	);
}

function execute_queued_stream_action_item(array $item, array &$state, array &$desiredState, string $logDir, string $defaultOutputDir, int $stopStartDelayUs): array
{
	$action = strtolower(trim((string)($item['action'] ?? 'retune')));
	$payload = isset($item['config']) && is_array($item['config']) ? $item['config'] : array();
	$requestedDevice = normalize_device_id((string)($payload['device'] ?? ($item['device'] ?? '')));
	$autoRecoveryEnabled = resolve_auto_recovery_enabled_from_payload($payload);

	try {
		$config = normalize_config($payload, $defaultOutputDir);
	} catch (RuntimeException $error) {
		return array(
			'ok' => false,
			'device' => $requestedDevice,
			'message' => 'Queued ' . strtoupper($action) . ' rejected: ' . $error->getMessage(),
			'stateChanged' => false,
			'desiredStateChanged' => false,
		);
	}

	$deviceId = normalize_device_id((string)($config['device'] ?? $requestedDevice));
	$stateChanged = false;
	$desiredStateChanged = false;

	$existingStateKey = find_state_device_key($state, $deviceId);
	if ($existingStateKey !== '') {
		$existingPid = isset($state[$existingStateKey]['pid']) ? (int)$state[$existingStateKey]['pid'] : 0;
		$existingProcessGroupId = get_instance_process_group_id((array)$state[$existingStateKey]);
		stop_instance_by_pid($existingPid, $existingProcessGroupId);
		unset($state[$existingStateKey]);
		$stateChanged = true;
		if ($stopStartDelayUs > 0) {
			usleep($stopStartDelayUs);
		}
	} elseif ($action === 'retune') {
		// For retune, use force release as fallback if device not found in state
		// This ensures the device is stopped even if state is out of sync
		$deviceIndex = normalize_device_index((string)($config['deviceIndex'] ?? $deviceId));
		if ($deviceIndex !== '') {
			force_release_device($deviceIndex);
			usleep(500000);
		}
		if ($stopStartDelayUs > 0) {
			usleep($stopStartDelayUs);
		}
	}

	$startResult = start_instance($config, $logDir);
	if (($startResult['ok'] ?? false) !== true) {
		$errorMessage = (string)($startResult['error'] ?? 'Failed to start queued action.');
		return array(
			'ok' => false,
			'device' => $deviceId,
			'message' => 'Queued ' . strtoupper($action) . ' failed: ' . $errorMessage,
			'stateChanged' => $stateChanged,
			'desiredStateChanged' => false,
		);
	}

	$runtimeConfig = isset($startResult['config']) && is_array($startResult['config'])
		? $startResult['config']
		: $config;
	$runtimeDeviceId = normalize_device_id((string)($runtimeConfig['device'] ?? $deviceId));

	$state[$runtimeDeviceId] = build_instance_state($runtimeConfig, $startResult, $autoRecoveryEnabled, 0);
	$stateChanged = true;
	if (set_device_desired_running($desiredState, $runtimeDeviceId, true)) {
		$desiredStateChanged = true;
	}

	return array(
		'ok' => true,
		'device' => $runtimeDeviceId,
		'message' => 'Queued ' . strtoupper($action) . ' applied to device ' . $runtimeDeviceId . '.',
		'stateChanged' => $stateChanged,
		'desiredStateChanged' => $desiredStateChanged,
	);
}

function process_queued_stream_actions(array &$state, array &$desiredState, string $logDir, string $defaultOutputDir): array
{
	$settings = get_action_queue_settings();
	$queueFile = (string)$settings['queueFile'];
	$lockFile = $queueFile . '.lock';
	$lockHandle = @fopen($lockFile, 'c+');
	if ($lockHandle === false) {
		return array('processed' => 0, 'pending' => 0, 'busy' => false, 'stateChanged' => false, 'desiredStateChanged' => false);
	}

	if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
		fclose($lockHandle);
		return array('processed' => 0, 'pending' => 0, 'busy' => true, 'stateChanged' => false, 'desiredStateChanged' => false);
	}

	$queue = load_action_queue_data($queueFile);
	$processed = array();
	$stateChanged = false;
	$desiredStateChanged = false;
	$maxActionsPerTick = max(1, (int)($settings['maxActionsPerTick'] ?? 1));
	$minSpacingSeconds = max(1, (int)($settings['minSpacingSeconds'] ?? 2));
	$stopStartDelayUs = max(0, (int)($settings['stopStartDelayUs'] ?? 0));

	for ($i = 0; $i < $maxActionsPerTick; $i++) {
		$items = isset($queue['items']) && is_array($queue['items']) ? $queue['items'] : array();
		if (count($items) === 0) {
			break;
		}

		$now = time();
		$lastProcessedAt = max(0, (int)($queue['lastProcessedAt'] ?? 0));
		if ($lastProcessedAt > 0 && ($now - $lastProcessedAt) < $minSpacingSeconds) {
			break;
		}

		$current = normalize_action_queue_item((array)$items[0]);
		array_shift($queue['items']);
		if (!is_array($current)) {
			$queue['lastProcessedAt'] = $now;
			continue;
		}

		$result = execute_queued_stream_action_item($current, $state, $desiredState, $logDir, $defaultOutputDir, $stopStartDelayUs);
		$stateChanged = $stateChanged || (bool)($result['stateChanged'] ?? false);
		$desiredStateChanged = $desiredStateChanged || (bool)($result['desiredStateChanged'] ?? false);

		$queue['lastProcessedAt'] = time();
		$summary = array(
			'id' => (string)($current['id'] ?? ''),
			'action' => (string)($current['action'] ?? ''),
			'device' => (string)($result['device'] ?? ($current['device'] ?? '')),
			'ok' => (bool)($result['ok'] ?? false),
			'message' => (string)($result['message'] ?? ''),
			'processedAt' => (int)$queue['lastProcessedAt'],
		);
		$queue['lastResult'] = $summary;
		$processed[] = $summary;
	}

	$pending = isset($queue['items']) && is_array($queue['items']) ? count($queue['items']) : 0;
	$saveOk = save_action_queue_data($queueFile, $queue);

	flock($lockHandle, LOCK_UN);
	fclose($lockHandle);

	if (!$saveOk) {
		return array('processed' => 0, 'pending' => $pending, 'busy' => false, 'stateChanged' => $stateChanged, 'desiredStateChanged' => $desiredStateChanged);
	}

	return array(
		'processed' => count($processed),
		'pending' => $pending,
		'busy' => false,
		'results' => $processed,
		'stateChanged' => $stateChanged,
		'desiredStateChanged' => $desiredStateChanged,
	);
}

function get_action_queue_snapshot(): array
{
	$settings = get_action_queue_settings();
	$queueFile = (string)($settings['queueFile'] ?? '');
	$queue = load_action_queue_data($queueFile);

	$items = isset($queue['items']) && is_array($queue['items']) ? $queue['items'] : array();
	$pending = count($items);
	$lastProcessedAt = max(0, (int)($queue['lastProcessedAt'] ?? 0));

	$lastResultRaw = isset($queue['lastResult']) && is_array($queue['lastResult'])
		? $queue['lastResult']
		: array();
	$lastResult = array(
		'id' => trim((string)($lastResultRaw['id'] ?? '')),
		'action' => strtolower(trim((string)($lastResultRaw['action'] ?? ''))),
		'device' => normalize_device_id((string)($lastResultRaw['device'] ?? '')),
		'ok' => parse_boolean_flag($lastResultRaw['ok'] ?? false, false),
		'message' => trim((string)($lastResultRaw['message'] ?? '')),
		'processedAt' => max(0, (int)($lastResultRaw['processedAt'] ?? 0)),
	);

	if (
		$lastResult['id'] === ''
		&& $lastResult['action'] === ''
		&& $lastResult['device'] === ''
		&& $lastResult['message'] === ''
		&& (int)$lastResult['processedAt'] <= 0
	) {
		$lastResult = array();
	}

	return array(
		'pending' => $pending,
		'lastProcessedAt' => $lastProcessedAt,
		'lastResult' => $lastResult,
	);
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

function normalize_pipe_output_presets(array $rawPresets): array
{
	$normalized = array();
	foreach ($rawPresets as $rawKey => $rawPreset) {
		$key = trim((string)$rawKey);
		if ($key === '' || !is_array($rawPreset)) {
			continue;
		}

		// New format: name is the key. Old format: numeric key + 'name' field inside.
		$hasNameField = isset($rawPreset['name']) && trim((string)$rawPreset['name']) !== '';
		if (ctype_digit($key) && $hasNameField) {
			$name = trim((string)$rawPreset['name']);
		} else {
			$name = $key;
		}
		if ($name === '') {
			continue;
		}

		$entries = normalize_pipe_output_entries($rawPreset['pipeOutputs'] ?? ($rawPreset['entries'] ?? array()));
		$rawSettings = normalize_pipe_output_raw_settings($rawPreset['raw'] ?? $rawPreset);
		$normalized[$name] = array(
			'pipeOutputs' => $entries,
			'raw' => $rawSettings,
		);
	}

	return $normalized;
}

function build_preset_id_to_name_map(array $rawPresets): array
{
	$map = array();
	foreach ($rawPresets as $key => $value) {
		$keyStr = trim((string)$key);
		if (ctype_digit($keyStr) && is_array($value) && isset($value['name']) && trim((string)$value['name']) !== '') {
			$map[$keyStr] = trim((string)$value['name']);
		}
	}
	return $map;
}

function migrate_pipe_output_preset_ids_in_settings(string $uiSettingsFile, array $idToNameMap): void
{
	if (!file_exists($uiSettingsFile) || empty($idToNameMap)) {
		return;
	}

	$raw = file_get_contents($uiSettingsFile);
	if (!is_string($raw) || trim($raw) === '') {
		return;
	}

	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		return;
	}

	$deviceConfigs = isset($decoded['deviceConfigs']) && is_array($decoded['deviceConfigs'])
		? $decoded['deviceConfigs']
		: array();

	$changed = false;
	foreach ($deviceConfigs as $deviceId => &$config) {
		if (!is_array($config)) {
			continue;
		}
		$presetIds = isset($config['pipeOutputPresetIds']) && is_array($config['pipeOutputPresetIds'])
			? $config['pipeOutputPresetIds']
			: array();
		$newIds = array();
		foreach ($presetIds as $pid) {
			$pidStr = trim((string)$pid);
			if (isset($idToNameMap[$pidStr])) {
				$newIds[] = $idToNameMap[$pidStr];
				$changed = true;
			} else {
				$newIds[] = $pidStr;
			}
		}
		$config['pipeOutputPresetIds'] = $newIds;

		if (isset($config['pipeOutputPresetId'])) {
			$legacyId = trim((string)$config['pipeOutputPresetId']);
			if (isset($idToNameMap[$legacyId])) {
				$config['pipeOutputPresetId'] = $idToNameMap[$legacyId];
				$changed = true;
			}
		}
	}
	unset($config);

	if ($changed) {
		$decoded['deviceConfigs'] = $deviceConfigs;
		$reencoded = json_encode($decoded, JSON_PRETTY_PRINT);
		if (is_string($reencoded)) {
			file_put_contents($uiSettingsFile, $reencoded . "\n", LOCK_EX);
		}
	}
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
		$existingConfig = isset($merged[$deviceId]) && is_array($merged[$deviceId])
			? $merged[$deviceId]
			: array();

		if (count($existingConfig) === 0) {
			$merged[$deviceId] = $instanceConfig;
			continue;
		}

		$existingConfig['device'] = $deviceId;
		$merged[$deviceId] = array_merge($instanceConfig, $existingConfig);
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

function discover_managed_pipeline_groups(): array
{
	$output = shell_exec('bash -lc ' . escapeshellarg('ps -eo pid=,pgid=,etimes=,args= 2>/dev/null'));
	if (!is_string($output) || trim($output) === '') {
		return array();
	}

	$groupedMembers = array();
	$lines = preg_split('/\r\n|\r|\n/', $output);
	if (!is_array($lines)) {
		return array();
	}

	foreach ($lines as $line) {
		$matches = array();
		if (preg_match('/^\s*([0-9]+)\s+([0-9]+)\s+([0-9]+)\s+(.*)$/', (string)$line, $matches) !== 1) {
			continue;
		}

		$pid = max(0, (int)$matches[1]);
		$processGroupId = max(0, (int)$matches[2]);
		$elapsedSeconds = max(0, (int)$matches[3]);
		$args = trim((string)$matches[4]);
		if ($pid <= 0 || $processGroupId <= 0 || $args === '') {
			continue;
		}

		$containsRtlFm = stripos($args, 'rtl_fm') !== false;
		$containsRecorder = stripos($args, 'radio-pipe') !== false;
		$containsFfmpeg = stripos($args, 'ffmpeg') !== false;
		$containsShellPipeline = stripos($args, 'sh -c rtl_fm') !== false;
		if (!$containsRtlFm && !$containsRecorder && !$containsFfmpeg && !$containsShellPipeline) {
			continue;
		}

		if (!isset($groupedMembers[$processGroupId])) {
			$groupedMembers[$processGroupId] = array(
				'members' => array(),
				'hasRtlFm' => false,
				'hasRecorder' => false,
				'hasFfmpeg' => false,
			);
		}

		$groupedMembers[$processGroupId]['members'][] = array(
			'pid' => $pid,
			'elapsedSeconds' => $elapsedSeconds,
			'args' => $args,
		);
		$groupedMembers[$processGroupId]['hasRtlFm'] = $groupedMembers[$processGroupId]['hasRtlFm'] || $containsRtlFm;
		$groupedMembers[$processGroupId]['hasRecorder'] = $groupedMembers[$processGroupId]['hasRecorder'] || $containsRecorder;
		$groupedMembers[$processGroupId]['hasFfmpeg'] = $groupedMembers[$processGroupId]['hasFfmpeg'] || $containsFfmpeg;
	}

	$managedGroups = array();
	$now = time();
	foreach ($groupedMembers as $processGroupId => $group) {
		if (!(bool)($group['hasRtlFm'] ?? false)) {
			continue;
		}
		if (!(bool)($group['hasRecorder'] ?? false) && !(bool)($group['hasFfmpeg'] ?? false)) {
			continue;
		}

		$members = isset($group['members']) && is_array($group['members']) ? $group['members'] : array();
		$representative = array('pid' => 0, 'elapsedSeconds' => 0, 'args' => '');
		$rtlMember = array('pid' => 0, 'elapsedSeconds' => 0, 'args' => '');
		$maxElapsedSeconds = 0;
		$leaderPid = 0;

		foreach ($members as $member) {
			if (!is_array($member)) {
				continue;
			}

			$memberArgs = trim((string)($member['args'] ?? ''));
			if ($memberArgs === '') {
				continue;
			}

			$memberElapsedSeconds = max(0, (int)($member['elapsedSeconds'] ?? 0));
			$memberPid = max(0, (int)($member['pid'] ?? 0));
			$maxElapsedSeconds = max($maxElapsedSeconds, $memberElapsedSeconds);
			if ($leaderPid === 0 && $memberPid === (int)$processGroupId) {
				$leaderPid = $memberPid;
			}

			if ($rtlMember['args'] === '' && stripos($memberArgs, 'rtl_fm') !== false) {
				$rtlMember = $member;
			}

			if (stripos($memberArgs, 'sh -c rtl_fm') !== false) {
				$representative = $member;
				break;
			}

			if (strlen($memberArgs) > strlen((string)($representative['args'] ?? ''))) {
				$representative = $member;
			}
		}

		$representativeArgs = trim((string)($representative['args'] ?? ''));
		$rtlMemberArgs = trim((string)($rtlMember['args'] ?? ''));
		$command = $representativeArgs !== '' ? $representativeArgs : $rtlMemberArgs;
		if ($command === '') {
			continue;
		}

		$deviceIndex = '';
		$indexCandidates = array($command);
		if ($rtlMemberArgs !== '' && $rtlMemberArgs !== $command) {
			$indexCandidates[] = $rtlMemberArgs;
		}
		foreach ($indexCandidates as $indexCandidate) {
			$indexMatches = array();
			if (preg_match('/(?:^|\s)-d\s+([0-9]+)(?:\s|$)/', (string)$indexCandidate, $indexMatches) === 1) {
				$deviceIndex = normalize_device_index((string)$indexMatches[1]);
				if ($deviceIndex !== '') {
					break;
				}
			}
		}
		if ($deviceIndex === '') {
			continue;
		}

		$deviceSerial = '';
		$serialMatches = array();
		if (preg_match('/RTL-SN\s+([A-Za-z0-9._-]+)/', $command, $serialMatches) === 1) {
			$deviceSerial = normalize_device_serial((string)$serialMatches[1]);
		}

		if ($leaderPid === 0) {
			$leaderPid = max(0, (int)($representative['pid'] ?? 0));
		}
		if ($leaderPid === 0) {
			$leaderPid = max(0, (int)($rtlMember['pid'] ?? 0));
		}
		if ($leaderPid === 0) {
			$leaderPid = (int)$processGroupId;
		}

		$managedGroups[] = array(
			'pid' => $leaderPid,
			'pgid' => (int)$processGroupId,
			'startedAt' => max(0, $now - $maxElapsedSeconds),
			'command' => $command,
			'deviceIndex' => $deviceIndex,
			'deviceSerial' => $deviceSerial,
		);
	}

	return $managedGroups;
}

function find_matching_device_config_for_live_group(array $deviceConfigs, array $liveGroup): array
{
	$deviceSerial = normalize_device_serial((string)($liveGroup['deviceSerial'] ?? ''));
	if ($deviceSerial !== '') {
		$serialDeviceId = 'sn:' . $deviceSerial;
		if (isset($deviceConfigs[$serialDeviceId]) && is_array($deviceConfigs[$serialDeviceId])) {
			return $deviceConfigs[$serialDeviceId];
		}
	}

	$deviceIndex = normalize_device_index((string)($liveGroup['deviceIndex'] ?? ''));
	if ($deviceIndex === '') {
		return array();
	}

	foreach ($deviceConfigs as $config) {
		if (!is_array($config)) {
			continue;
		}

		$configIndex = normalize_device_index((string)($config['deviceIndex'] ?? ''));
		if ($configIndex === '' || $configIndex !== $deviceIndex) {
			continue;
		}

		$configSerial = normalize_device_serial((string)($config['deviceSerial'] ?? ''));
		if ($deviceSerial !== '' && $configSerial !== '' && $configSerial !== $deviceSerial) {
			continue;
		}

		return $config;
	}

	return array();
}

function reconcile_runtime_state_with_live_process_groups(array &$state, string $logDir, string $uiSettingsFile): bool
{
	$settings = normalize_ui_settings(load_ui_settings($uiSettingsFile));
	$deviceConfigs = isset($settings['deviceConfigs']) && is_array($settings['deviceConfigs'])
		? $settings['deviceConfigs']
		: array();
	$liveGroups = discover_managed_pipeline_groups();
	if (count($liveGroups) === 0) {
		return false;
	}

	$changed = false;
	foreach ($liveGroups as $liveGroup) {
		if (!is_array($liveGroup)) {
			continue;
		}

		$deviceIndex = normalize_device_index((string)($liveGroup['deviceIndex'] ?? ''));
		$deviceSerial = normalize_device_serial((string)($liveGroup['deviceSerial'] ?? ''));
		$fallbackDeviceId = build_device_id_from_index_and_serial($deviceIndex, $deviceSerial);
		$config = find_matching_device_config_for_live_group($deviceConfigs, $liveGroup);
		if (count($config) === 0) {
			if ($fallbackDeviceId === '') {
				continue;
			}

			$config = array(
				'device' => $fallbackDeviceId,
				'deviceIndex' => $deviceIndex,
				'deviceSerial' => $deviceSerial,
			);
		}

		$configDeviceId = normalize_device_id((string)($config['device'] ?? $fallbackDeviceId));
		$configSerial = normalize_device_serial((string)($config['deviceSerial'] ?? $deviceSerial));
		if ($configSerial === '' && $deviceSerial !== '') {
			$configSerial = $deviceSerial;
		}
		if ($configSerial !== '') {
			$configDeviceId = 'sn:' . $configSerial;
			$config['deviceSerial'] = $configSerial;
		}
		if ($configDeviceId === '') {
			$configDeviceId = $fallbackDeviceId;
		}
		if ($configDeviceId === '') {
			continue;
		}

		$config['device'] = $configDeviceId;
		if ($deviceIndex !== '') {
			$config['deviceIndex'] = $deviceIndex;
		}

		$existingStateKey = find_state_device_key($state, $configDeviceId);
		$logFile = '';
		$logPath = resolve_log_path_for_device($logDir, $configDeviceId, $state);
		if ($logPath !== '') {
			$logFile = basename($logPath);
		}

		$autoRecoveryEnabled = count($config) > 3
			? resolve_auto_recovery_enabled_from_payload($config)
			: false;
		$startResult = array(
			'pid' => (int)($liveGroup['pid'] ?? 0),
			'pgid' => (int)($liveGroup['pgid'] ?? 0),
			'logFile' => $logFile,
			'command' => mask_sensitive_command_for_log((string)($liveGroup['command'] ?? '')),
		);
		$rebuiltInstance = build_instance_state($config, $startResult, $autoRecoveryEnabled, 0);
		$rebuiltInstance['startedAt'] = max(0, (int)($liveGroup['startedAt'] ?? time()));

		if ($existingStateKey === '') {
			$state[$configDeviceId] = $rebuiltInstance;
			$changed = true;
			continue;
		}

		$existingInstance = isset($state[$existingStateKey]) && is_array($state[$existingStateKey])
			? $state[$existingStateKey]
			: array();
		$needsUpdate =
			!is_instance_running($existingInstance)
			|| (int)($existingInstance['pid'] ?? 0) !== (int)($rebuiltInstance['pid'] ?? 0)
			|| (int)($existingInstance['pgid'] ?? 0) !== (int)($rebuiltInstance['pgid'] ?? 0)
			|| trim((string)($existingInstance['logFile'] ?? '')) === ''
			|| trim((string)($existingInstance['command'] ?? '')) === '';
		if (!$needsUpdate) {
			continue;
		}

		if ($existingStateKey !== $configDeviceId) {
			unset($state[$existingStateKey]);
		}
		$state[$configDeviceId] = $rebuiltInstance;
		$changed = true;
	}

	return $changed;
}

function load_templates(string $dir, string $legacyUiSettingsFile = '', string $legacyTemplatesFile = ''): array
{
	ensure_templates_directory($dir);

	// One-time migration of the legacy aggregated rtl_sdr_templates.json file.
	if ($legacyTemplatesFile !== '' && file_exists($legacyTemplatesFile)) {
		$rawLegacy = file_get_contents($legacyTemplatesFile);
		if (is_string($rawLegacy) && trim($rawLegacy) !== '') {
			$decodedLegacy = json_decode($rawLegacy, true);
			if (is_array($decodedLegacy)) {
				foreach (normalize_templates($decodedLegacy) as $name => $config) {
					save_template_to_dir($dir, $name, $config, false);
				}
			}
		}
		@rename($legacyTemplatesFile, $legacyTemplatesFile . '.migrated');
	}

	// One-time migration of templates that previously lived inside rtl_sdr_ui_settings.json.
	if ($legacyUiSettingsFile !== '' && file_exists($legacyUiSettingsFile)) {
		$rawSettings = file_get_contents($legacyUiSettingsFile);
		if (is_string($rawSettings) && trim($rawSettings) !== '') {
			$decodedSettings = json_decode($rawSettings, true);
			if (is_array($decodedSettings) && isset($decodedSettings['templates']) && is_array($decodedSettings['templates'])) {
				foreach (normalize_templates($decodedSettings['templates']) as $name => $config) {
					save_template_to_dir($dir, $name, $config, false);
				}
			}
		}
	}

	return read_templates_from_dir($dir);
}

function ensure_templates_directory(string $dir): void
{
	if ($dir === '') {
		return;
	}
	if (!is_dir($dir)) {
		@mkdir($dir, 0775, true);
	}
}

function template_filename_for(string $name): string
{
	$name = trim($name);
	if ($name === '') {
		return '';
	}
	$slug = strtolower((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $name));
	$slug = trim($slug, '._-');
	if ($slug === '') {
		$slug = 'template';
	}
	if (strlen($slug) > 64) {
		$slug = substr($slug, 0, 64);
	}
	$hash = substr(sha1($name), 0, 8);
	return $slug . '__' . $hash . '.json';
}

function read_templates_from_dir(string $dir): array
{
	$templates = array();
	if ($dir === '' || !is_dir($dir)) {
		return $templates;
	}
	$files = glob(rtrim($dir, '/') . '/*.json');
	if (!is_array($files)) {
		return $templates;
	}
	foreach ($files as $file) {
		$raw = @file_get_contents($file);
		if (!is_string($raw) || trim($raw) === '') {
			continue;
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			continue;
		}
		$name = isset($decoded['templateName']) ? trim((string)$decoded['templateName']) : '';
		if ($name === '') {
			continue;
		}
		$decoded['templateName'] = $name;
		$templates[$name] = $decoded;
	}
	ksort($templates, SORT_NATURAL | SORT_FLAG_CASE);
	return $templates;
}

function save_template_to_dir(string $dir, string $name, array $config, bool $overwriteExisting = true): bool
{
	ensure_templates_directory($dir);
	$name = trim($name);
	if ($name === '' || $dir === '' || !is_dir($dir)) {
		return false;
	}
	$config['templateName'] = $name;
	$path = rtrim($dir, '/') . '/' . template_filename_for($name);
	if (!$overwriteExisting && file_exists($path)) {
		return true;
	}
	$encoded = json_encode($config, JSON_PRETTY_PRINT);
	if (!is_string($encoded)) {
		return false;
	}
	return file_put_contents($path, $encoded . "\n", LOCK_EX) !== false;
}

function delete_template_from_dir(string $dir, string $name): bool
{
	$name = trim($name);
	if ($name === '' || $dir === '' || !is_dir($dir)) {
		return false;
	}
	$path = rtrim($dir, '/') . '/' . template_filename_for($name);
	if (!file_exists($path)) {
		return true;
	}
	return @unlink($path);
}

// Merge-style bulk save: writes/updates each provided template but NEVER removes
// templates not present in the payload. This avoids two concurrent clients
// overwriting each other's templates. Use delete_template_from_dir() for explicit removal.
function save_templates(string $dir, array $templates): bool
{
	ensure_templates_directory($dir);
	$normalized = normalize_templates($templates);
	$ok = true;
	foreach ($normalized as $name => $config) {
		if (!save_template_to_dir($dir, $name, $config, true)) {
			$ok = false;
		}
	}
	return $ok;
}

function ui_settings_for_response(array $settings, array $pipeOutputs = array()): array
{
	return array(
		'deviceConfigs' => (object)($settings['deviceConfigs'] ?? array()),
		'antennaDescriptionsBySerial' => (object)($settings['antennaDescriptionsBySerial'] ?? array()),
		'pipeOutputPresets' => (object)$pipeOutputs,
	);
}

function load_pipe_outputs(string $filePath, string $migrateFromSettingsFile = ''): array
{
	if (!file_exists($filePath)) {
		if ($migrateFromSettingsFile !== '' && file_exists($migrateFromSettingsFile)) {
			$rawSettings = json_decode((string)file_get_contents($migrateFromSettingsFile), true);
			if (is_array($rawSettings) && isset($rawSettings['pipeOutputPresets']) && is_array($rawSettings['pipeOutputPresets'])) {
				$idToNameMap = build_preset_id_to_name_map($rawSettings['pipeOutputPresets']);
				$presets = normalize_pipe_output_presets($rawSettings['pipeOutputPresets']);
				if (!empty($presets)) {
					save_pipe_outputs($filePath, $presets);
					if (!empty($idToNameMap)) {
						migrate_pipe_output_preset_ids_in_settings($migrateFromSettingsFile, $idToNameMap);
					}
					return $presets;
				}
			}
		}
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

	// Detect and migrate old numeric-keyed format
	$idToNameMap = build_preset_id_to_name_map($decoded);
	$normalized = normalize_pipe_output_presets($decoded);
	if (!empty($idToNameMap)) {
		save_pipe_outputs($filePath, $normalized);
		if ($migrateFromSettingsFile !== '') {
			migrate_pipe_output_preset_ids_in_settings($migrateFromSettingsFile, $idToNameMap);
		}
	}

	return $normalized;
}

function save_pipe_outputs(string $filePath, array $presets): bool
{
	$normalized = normalize_pipe_output_presets($presets);
	$encoded = json_encode((object)$normalized, JSON_PRETTY_PRINT);
	if (!is_string($encoded)) {
		return false;
	}

	return file_put_contents($filePath, $encoded . "\n", LOCK_EX) !== false;
}

if (!file_exists($STREAMING_SERVERS_FILE)) {
	file_put_contents($STREAMING_SERVERS_FILE, "{}\n", LOCK_EX);
}

if (!file_exists($RECORDING_SERVERS_FILE)) {
	file_put_contents($RECORDING_SERVERS_FILE, "{}\n", LOCK_EX);
}

if (!is_dir($TEMPLATES_DIR) || file_exists($TEMPLATES_FILE)) {
	load_templates($TEMPLATES_DIR, $UI_SETTINGS_FILE, $TEMPLATES_FILE);
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

function is_process_group_running(int $processGroupId): bool
{
	if ($processGroupId <= 0) {
		return false;
	}

	$checkCommand = 'kill -0 -- -' . $processGroupId . ' >/dev/null 2>&1; echo $?';
	$result = shell_exec('bash -lc ' . escapeshellarg($checkCommand));
	return trim((string)$result) === '0';
}

function lookup_process_group_id(int $pid): int
{
	if ($pid <= 0) {
		return 0;
	}

	$lookupCommand = 'ps -o pgid= -p ' . $pid . ' 2>/dev/null';
	$result = shell_exec('bash -lc ' . escapeshellarg($lookupCommand));
	$processGroupId = (int)trim((string)$result);
	return $processGroupId > 0 ? $processGroupId : 0;
}

function get_instance_process_group_id(array $instance): int
{
	$processGroupId = max(0, (int)($instance['pgid'] ?? 0));
	if ($processGroupId > 0) {
		return $processGroupId;
	}

	$pid = max(0, (int)($instance['pid'] ?? 0));
	if ($pid <= 0) {
		return 0;
	}

	$resolvedProcessGroupId = lookup_process_group_id($pid);
	return $resolvedProcessGroupId > 0 ? $resolvedProcessGroupId : $pid;
}

function is_instance_running(array $instance): bool
{
	$processGroupId = get_instance_process_group_id($instance);
	if ($processGroupId > 0 && is_process_group_running($processGroupId)) {
		return true;
	}

	$pid = max(0, (int)($instance['pid'] ?? 0));
	return $pid > 0 && is_process_running($pid);
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

function normalize_pipe_output_entries($value): array
{
	$entries = array();

	if (is_string($value)) {
		$split = preg_split('/\r\n|\r|\n/', $value);
		$value = is_array($split) ? $split : array($value);
	}

	if (!is_array($value)) {
		return $entries;
	}

	foreach ($value as $entry) {
		$enabled = true;
		$rawOptions = '';

		if (is_array($entry)) {
			if (array_key_exists('enabled', $entry)) {
				$enabled = parse_boolean_flag($entry['enabled'], true);
			}
			$rawOptions = trim((string)($entry['options'] ?? ($entry['raw'] ?? ($entry['value'] ?? ''))));
		} else {
			$rawOptions = trim((string)$entry);
		}

		if (!$enabled || $rawOptions === '') {
			continue;
		}

		if (preg_match('/[\x00\r\n]/', $rawOptions) === 1) {
			continue;
		}

		$entries[] = $rawOptions;
		if (count($entries) >= 16) {
			break;
		}
	}

	return $entries;
}

function normalize_pipe_output_raw_settings($raw): array
{
	$source = is_array($raw) ? $raw : array();
	$enabled = parse_boolean_flag($source['enabled'] ?? ($source['pipeOutputRaw'] ?? 0), false);

	$bits = trim((string)($source['bits'] ?? ($source['pipeOutputBits'] ?? '')));
	if ($bits !== '' && preg_match('/^[0-9]{1,2}$/', $bits) !== 1) {
		$bits = '';
	}

	$channels = trim((string)($source['channels'] ?? ($source['pipeOutputChannels'] ?? '')));
	if ($channels !== '' && preg_match('/^[0-9]{1,2}$/', $channels) !== 1) {
		$channels = '';
	}

	$rate = trim((string)($source['rate'] ?? ($source['pipeOutputRate'] ?? '')));
	if ($rate !== '' && preg_match('/^[0-9]{2,6}$/', $rate) !== 1) {
		$rate = '';
	}

	$unsigned = parse_boolean_flag($source['unsigned'] ?? ($source['pipeOutputUnsigned'] ?? 0), false);
	$bigEndian = parse_boolean_flag($source['bigEndian'] ?? ($source['pipeOutputBigEndian'] ?? 0), false);

	$hasPad = array_key_exists('pad', $source) || array_key_exists('pipeOutputPad', $source);
	$pad = $hasPad
		? parse_boolean_flag($source['pad'] ?? ($source['pipeOutputPad'] ?? 0), false)
		: $enabled;

	$padDelay = trim((string)($source['padDelay'] ?? ($source['pipeOutputPadDelay'] ?? '')));
	if ($padDelay !== '' && preg_match('/^[0-9]{1,6}$/', $padDelay) !== 1) {
		$padDelay = '';
	}

	return array(
		'enabled' => $enabled,
		'bits' => $bits,
		'channels' => $channels,
		'rate' => $rate,
		'unsigned' => $unsigned,
		'bigEndian' => $bigEndian,
		'pad' => $pad,
		'padDelay' => $padDelay,
	);
}

function config_pipe_output_enabled(array $config): bool
{
	$outputs = config_pipe_outputs($config);
	return count($outputs) > 0;
}

function config_pipe_outputs(array $config): array
{
	return normalize_pipe_output_entries($config['pipeOutputs'] ?? array());
}

function append_pipe_output_args(array &$command, array $config): void
{
	$pipeOutputs = config_pipe_outputs($config);
	if (count($pipeOutputs) === 0) {
		return;
	}

	$formatArgs = array();
	$rawEnabled = parse_boolean_flag($config['pipeOutputRaw'] ?? 0, false);
	if ($rawEnabled) {
		$formatArgs[] = '--pipe-output-raw';

		$rawBits = trim((string)($config['pipeOutputBits'] ?? ''));
		if ($rawBits !== '') {
			$formatArgs[] = '--pipe-output-bits';
			$formatArgs[] = $rawBits;
		}

		$rawChannels = trim((string)($config['pipeOutputChannels'] ?? ''));
		if ($rawChannels !== '') {
			$formatArgs[] = '--pipe-output-channels';
			$formatArgs[] = $rawChannels;
		}

		$rawRate = trim((string)($config['pipeOutputRate'] ?? ''));
		if ($rawRate !== '') {
			$formatArgs[] = '--pipe-output-rate';
			$formatArgs[] = $rawRate;
		}

		if (parse_boolean_flag($config['pipeOutputUnsigned'] ?? 0, false)) {
			$formatArgs[] = '--pipe-output-unsigned';
		}

		if (parse_boolean_flag($config['pipeOutputBigEndian'] ?? 0, false)) {
			$formatArgs[] = '--pipe-output-endian';
			$formatArgs[] = 'big';
		}

		$padEnabled = parse_boolean_flag($config['pipeOutputPad'] ?? 0, false);
		if ($padEnabled) {
			$formatArgs[] = '--pipe-output-pad';
			$padDelay = trim((string)($config['pipeOutputPadDelay'] ?? ''));
			if ($padDelay !== '') {
				$formatArgs[] = '--pipe-output-pad-delay';
				$formatArgs[] = $padDelay;
			}
		}
	}

	foreach ($pipeOutputs as $pipeOutputOptions) {
		foreach ($formatArgs as $formatArg) {
			$command[] = $formatArg;
		}
		$command[] = '--pipe-output';
		$command[] = $pipeOutputOptions;
	}
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
		'pgid' => max(0, (int)($startResult['pgid'] ?? ($startResult['pid'] ?? 0))),
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
		$processGroupId = get_instance_process_group_id($instance);
		$running = is_instance_running($instance);
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
				stop_instance_by_pid($pid, $processGroupId);
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
			if ($pid !== 0 || $processGroupId !== 0 || !isset($instance['autoRecoveryEnabled'])) {
				$changed = true;
			}
			$state[$deviceId] = $instance;
			$state[$deviceId]['pid'] = 0;
			$state[$deviceId]['pgid'] = 0;
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
		$state[$deviceId]['pgid'] = 0;
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

function current_process_in_group(string $groupName): bool
{
	if (!function_exists('posix_getgrnam') || !function_exists('posix_getgroups')) {
		return false;
	}
	$grpInfo = posix_getgrnam($groupName);
	if (!is_array($grpInfo) || !isset($grpInfo['gid'])) {
		return false;
	}
	return in_array((int)$grpInfo['gid'], posix_getgroups(), true);
}

function wrap_for_device_access(string $command): string
{
	if (command_exists('sg') && !current_process_in_group('plugdev')) {
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
		$running = is_instance_running((array)$state[$stateDeviceKey]);
	}

	return array(
		'device' => $resolvedDeviceId,
		'running' => $running,
		'logFile' => $logPath === '' ? '' : basename($logPath),
		'lines' => read_log_lines($logPath, $maxLines),
	);
}

function parse_radio_pipe_api_websocket_address(string $rawAddress): array
{
	$address = trim($rawAddress);
	if ($address === '') {
		return array('ok' => false, 'host' => '', 'port' => 0, 'error' => 'WebSocket address is empty.');
	}

	$host = '';
	$portText = '';
	$bracketMatches = array();
	if (preg_match('/^\[([^\]]+)\]:(\d{1,5})$/', $address, $bracketMatches) === 1) {
		$host = trim((string)$bracketMatches[1]);
		$portText = trim((string)$bracketMatches[2]);
	} else {
		$separatorIndex = strrpos($address, ':');
		if ($separatorIndex === false || $separatorIndex <= 0) {
			return array('ok' => false, 'host' => '', 'port' => 0, 'error' => 'WebSocket address must be host:port.');
		}

		$host = trim(substr($address, 0, $separatorIndex));
		$portText = trim(substr($address, $separatorIndex + 1));
	}

	$port = (int)$portText;
	if ($host === '' || $port < 1 || $port > 65535) {
		return array('ok' => false, 'host' => '', 'port' => 0, 'error' => 'WebSocket host or port is invalid.');
	}

	return array('ok' => true, 'host' => $host, 'port' => $port, 'error' => '');
}

function normalize_radio_pipe_api_connect_host(string $host): string
{
	$normalized = trim($host);
	$lower = strtolower($normalized);
	if ($lower === '' || $lower === '0.0.0.0' || $lower === '::' || $lower === '[::]' || $lower === 'localhost') {
		return '127.0.0.1';
	}

	if (strpos($normalized, ':') !== false && strpos($normalized, '[') !== 0) {
		return '[' . $normalized . ']';
	}

	return $normalized;
}

function extract_radio_pipe_api_websocket_address_from_command(string $command): string
{
	$trimmedCommand = trim($command);
	if ($trimmedCommand === '') {
		return '';
	}

	$matches = array();
	if (preg_match("~--api-websocket\\s+(?:\"([^\"]+)\"|'([^']+)'|([^\\s|]+))~i", $trimmedCommand, $matches) !== 1) {
		return '';
	}

	for ($i = 1; $i <= 3; $i++) {
		if (isset($matches[$i]) && trim((string)$matches[$i]) !== '') {
			return trim((string)$matches[$i]);
		}
	}

	return '';
}

function resolve_radio_pipe_api_websocket_address_for_instance(array $instance): string
{
	$config = isset($instance['config']) && is_array($instance['config'])
		? $instance['config']
		: array();

	$directAddress = trim((string)($config['apiWebsocketAddress'] ?? ''));
	if ($directAddress !== '') {
		return $directAddress;
	}

	$host = trim((string)($config['apiWebsocketHost'] ?? ''));
	$port = (int)($config['apiWebsocketPort'] ?? 0);
	if ($host !== '' && $port >= 1 && $port <= 65535) {
		return $host . ':' . (string)$port;
	}

	$commandAddress = extract_radio_pipe_api_websocket_address_from_command((string)($instance['command'] ?? ''));
	if ($commandAddress !== '') {
		return $commandAddress;
	}

	return '';
}

function radio_pipe_ws_read_exact($socket, int $length, int $deadlineUs): ?string
{
	if (!is_resource($socket) || $length < 0) {
		return null;
	}

	if ($length === 0) {
		return '';
	}

	$buffer = '';
	while (strlen($buffer) < $length) {
		$remainingUs = $deadlineUs - (int)floor(microtime(true) * 1000000);
		if ($remainingUs <= 0) {
			return null;
		}

		$read = array($socket);
		$write = null;
		$except = null;
		$seconds = (int)floor($remainingUs / 1000000);
		$microseconds = (int)($remainingUs % 1000000);
		$ready = @stream_select($read, $write, $except, $seconds, $microseconds);
		if ($ready === false || $ready === 0) {
			return null;
		}

		$chunk = fread($socket, $length - strlen($buffer));
		if (!is_string($chunk) || $chunk === '') {
			return null;
		}

		$buffer .= $chunk;
	}

	return $buffer;
}

function radio_pipe_ws_read_frame($socket, int $deadlineUs): ?array
{
	$header = radio_pipe_ws_read_exact($socket, 2, $deadlineUs);
	if (!is_string($header) || strlen($header) !== 2) {
		return null;
	}

	$byte1 = ord($header[0]);
	$byte2 = ord($header[1]);
	$opcode = $byte1 & 0x0F;
	$masked = ($byte2 & 0x80) === 0x80;
	$payloadLength = $byte2 & 0x7F;

	if ($payloadLength === 126) {
		$extended = radio_pipe_ws_read_exact($socket, 2, $deadlineUs);
		if (!is_string($extended) || strlen($extended) !== 2) {
			return null;
		}
		$decoded = unpack('nlength', $extended);
		$payloadLength = (int)($decoded['length'] ?? 0);
	} elseif ($payloadLength === 127) {
		$extended = radio_pipe_ws_read_exact($socket, 8, $deadlineUs);
		if (!is_string($extended) || strlen($extended) !== 8) {
			return null;
		}
		$decoded = unpack('Nhigh/Nlow', $extended);
		$high = (int)($decoded['high'] ?? 0);
		$low = (int)($decoded['low'] ?? 0);
		if ($high !== 0) {
			return null;
		}
		$payloadLength = $low;
	}

	$maskingKey = '';
	if ($masked) {
		$maskingKey = radio_pipe_ws_read_exact($socket, 4, $deadlineUs);
		if (!is_string($maskingKey) || strlen($maskingKey) !== 4) {
			return null;
		}
	}

	$payload = radio_pipe_ws_read_exact($socket, $payloadLength, $deadlineUs);
	if (!is_string($payload) || strlen($payload) !== $payloadLength) {
		return null;
	}

	if ($masked) {
		$unmasked = '';
		for ($i = 0; $i < $payloadLength; $i++) {
			$unmasked .= $payload[$i] ^ $maskingKey[$i % 4];
		}
		$payload = $unmasked;
	}

	return array(
		'opcode' => $opcode,
		'payload' => $payload,
	);
}

function radio_pipe_ws_write_frame($socket, int $opcode, string $payload = ''): bool
{
	if (!is_resource($socket)) {
		return false;
	}

	$payloadLength = strlen($payload);
	$firstByte = chr(0x80 | ($opcode & 0x0F));

	$maskingKey = '';
	if (function_exists('random_bytes')) {
		try {
			$maskingKey = random_bytes(4);
		} catch (Throwable $exception) {
			$maskingKey = '';
		}
	}
	if (!is_string($maskingKey) || strlen($maskingKey) !== 4) {
		$fallback = hash('sha256', uniqid('ws-mask-', true), true);
		$maskingKey = is_string($fallback) ? substr($fallback, 0, 4) : "\x00\x00\x00\x00";
	}

	$maskedPayload = '';
	for ($i = 0; $i < $payloadLength; $i++) {
		$maskedPayload .= $payload[$i] ^ $maskingKey[$i % 4];
	}

	if ($payloadLength < 126) {
		$header = $firstByte . chr(0x80 | $payloadLength);
	} elseif ($payloadLength <= 65535) {
		$header = $firstByte . chr(0x80 | 126) . pack('n', $payloadLength);
	} else {
		$header = $firstByte . chr(0x80 | 127) . pack('NN', 0, $payloadLength);
	}

	$frame = $header . $maskingKey . $maskedPayload;
	$written = @fwrite($socket, $frame);
	return is_int($written) && $written === strlen($frame);
}

function radio_pipe_status_from_api_websocket(string $apiAddress, int $timeoutMs = 1200): array
{
	$parsedAddress = parse_radio_pipe_api_websocket_address($apiAddress);
	if (($parsedAddress['ok'] ?? false) !== true) {
		return array('ok' => false, 'status' => null, 'error' => (string)($parsedAddress['error'] ?? 'Invalid websocket address.'));
	}

	$host = (string)($parsedAddress['host'] ?? '');
	$port = (int)($parsedAddress['port'] ?? 0);
	$connectHost = normalize_radio_pipe_api_connect_host($host);
	$connectTarget = 'tcp://' . $connectHost . ':' . (string)$port;
	$timeoutMs = max(200, $timeoutMs);
	$connectTimeoutSeconds = max(1, min(5, (int)ceil($timeoutMs / 1000)));

	$errno = 0;
	$errstr = '';
	$socket = @stream_socket_client($connectTarget, $errno, $errstr, $connectTimeoutSeconds, STREAM_CLIENT_CONNECT);
	if ($socket === false) {
		return array('ok' => false, 'status' => null, 'error' => 'Failed to connect to websocket endpoint (' . $connectTarget . ').');
	}

	$deadlineUs = (int)floor(microtime(true) * 1000000) + ($timeoutMs * 1000);
	$wsKey = '';
	if (function_exists('random_bytes')) {
		try {
			$wsKey = base64_encode(random_bytes(16));
		} catch (Throwable $exception) {
			$wsKey = '';
		}
	}
	if ($wsKey === '') {
		$wsKey = base64_encode(hash('sha256', uniqid('ws-key-', true), true));
	}

	$requestLines = array(
		'GET / HTTP/1.1',
		'Host: ' . $host . ':' . (string)$port,
		'Upgrade: websocket',
		'Connection: Upgrade',
		'Sec-WebSocket-Key: ' . $wsKey,
		'Sec-WebSocket-Version: 13',
		'',
		'',
	);
	$request = implode("\r\n", $requestLines);
	$writeResult = @fwrite($socket, $request);
	if (!is_int($writeResult) || $writeResult <= 0) {
		fclose($socket);
		return array('ok' => false, 'status' => null, 'error' => 'Failed websocket handshake write.');
	}

	$responseHeaders = '';
	while (strpos($responseHeaders, "\r\n\r\n") === false) {
		$byte = radio_pipe_ws_read_exact($socket, 1, $deadlineUs);
		if (!is_string($byte) || $byte === '') {
			fclose($socket);
			return array('ok' => false, 'status' => null, 'error' => 'Timed out waiting for websocket handshake response.');
		}
		$responseHeaders .= $byte;
		if (strlen($responseHeaders) > 8192) {
			fclose($socket);
			return array('ok' => false, 'status' => null, 'error' => 'Websocket handshake response too large.');
		}
	}

	if (preg_match('/^HTTP\/\S+\s+101\b/i', $responseHeaders) !== 1) {
		fclose($socket);
		return array('ok' => false, 'status' => null, 'error' => 'Websocket handshake failed.');
	}

	$statusPayload = null;
	while ((int)floor(microtime(true) * 1000000) < $deadlineUs) {
		$frame = radio_pipe_ws_read_frame($socket, $deadlineUs);
		if (!is_array($frame)) {
			break;
		}

		$opcode = (int)($frame['opcode'] ?? -1);
		$payload = (string)($frame['payload'] ?? '');
		if ($opcode === 0x9) {
			radio_pipe_ws_write_frame($socket, 0xA, $payload);
			continue;
		}
		if ($opcode === 0x8) {
			break;
		}
		if ($opcode !== 0x1) {
			continue;
		}

		$decoded = json_decode($payload, true);
		if (!is_array($decoded)) {
			continue;
		}

		$eventName = strtolower(trim((string)($decoded['event'] ?? '')));
		if ($eventName === 'status') {
			$statusPayload = $decoded;
			break;
		}
	}

	fclose($socket);

	if (!is_array($statusPayload)) {
		return array('ok' => false, 'status' => null, 'error' => 'No status packet received from websocket endpoint.');
	}

	return array('ok' => true, 'status' => $statusPayload, 'error' => '');
}

function build_radio_pipe_status_payload_for_device(string $deviceId, array $state): array
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

	if ($stateDeviceKey === '' || !isset($state[$stateDeviceKey]) || !is_array($state[$stateDeviceKey])) {
		return array(
			'device' => $resolvedDeviceId,
			'running' => false,
			'apiWebsocketAddress' => '',
			'status' => null,
			'error' => 'Device is not running.',
		);
	}

	$instance = (array)$state[$stateDeviceKey];
	$running = is_instance_running($instance);
	$apiAddress = resolve_radio_pipe_api_websocket_address_for_instance($instance);
	if (!$running) {
		return array(
			'device' => $resolvedDeviceId,
			'running' => false,
			'apiWebsocketAddress' => $apiAddress,
			'status' => null,
			'error' => 'Device is not running.',
		);
	}

	if ($apiAddress === '') {
		return array(
			'device' => $resolvedDeviceId,
			'running' => true,
			'apiWebsocketAddress' => '',
			'status' => null,
			'error' => 'Missing websocket endpoint for device.',
		);
	}

	$statusResult = radio_pipe_status_from_api_websocket($apiAddress, 1200);
	if (($statusResult['ok'] ?? false) !== true) {
		return array(
			'device' => $resolvedDeviceId,
			'running' => true,
			'apiWebsocketAddress' => $apiAddress,
			'status' => null,
			'error' => (string)($statusResult['error'] ?? 'Failed to read websocket status.'),
		);
	}

	return array(
		'device' => $resolvedDeviceId,
		'running' => true,
		'apiWebsocketAddress' => $apiAddress,
		'status' => isset($statusResult['status']) && is_array($statusResult['status'])
			? $statusResult['status']
			: null,
		'error' => '',
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

function launch_pipeline_process(string $pipelineCommand, string $logPath): array
{
	$deviceCommand = wrap_for_device_access('nohup setsid sh -c ' . escapeshellarg($pipelineCommand) . ' >> ' . escapeshellarg($logPath) . ' 2>&1 < /dev/null & echo $!');
	$wrappedCommand = $deviceCommand;
	$pidOutput = shell_exec('bash -lc ' . escapeshellarg($wrappedCommand));
	$pid = (int)trim((string)$pidOutput);
	$processGroupId = $pid > 0 ? lookup_process_group_id($pid) : 0;
	if ($processGroupId <= 0 && $pid > 0) {
		$processGroupId = $pid;
	}

	return array(
		'pid' => $pid,
		'pgid' => $processGroupId,
	);
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

function normalize_stream_mount_name_segment(string $streamName): string
{
	$raw = strtolower(trim($streamName));
	if ($raw === '') {
		return 'rtl-sdr';
	}

	if (function_exists('iconv')) {
		$converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $raw);
		if (is_string($converted) && trim($converted) !== '') {
			$raw = strtolower($converted);
		}
	}

	$segment = preg_replace('/[^a-z0-9._-]+/', '-', $raw) ?? '';
	$segment = preg_replace('/-+/', '-', $segment) ?? $segment;
	$segment = trim($segment, '-._');
	if ($segment === '') {
		return 'rtl-sdr';
	}

	return $segment;
}

function build_default_stream_mount_from_name(string $streamName, string $streamFormat): string
{
	$format = strtolower(trim($streamFormat)) === 'ogg' ? 'ogg' : 'mp3';
	$segment = normalize_stream_mount_name_segment($streamName);
	return '/' . $segment . '.' . $format;
}

function build_default_stream_mount_from_device_index(string $deviceIndex, string $streamFormat): string
{
	$format = strtolower(trim($streamFormat)) === 'ogg' ? 'ogg' : 'mp3';
	$normalizedIndex = normalize_device_index($deviceIndex);
	if ($normalizedIndex === '') {
		$normalizedIndex = '0';
	}

	return '/dev' . $normalizedIndex . '.' . $format;
}

function normalize_stream_mount_link_mode($value): string
{
	$mode = strtolower(trim((string)$value));
	if (in_array($mode, array('device', 'dev', 'link-device', 'device-index', 'index'), true)) {
		return 'device';
	}

	if (in_array($mode, array('name', 'stream', 'stream-name', 'linked', 'auto'), true)) {
		return 'name';
	}

	if (in_array($mode, array('manual', 'none', 'off', 'custom'), true)) {
		return 'manual';
	}

	return '';
}

function infer_stream_mount_follow_device(array $input, string $streamMount, string $deviceIndex, string $streamFormat): bool
{
	if (array_key_exists('streamMountLinkMode', $input)) {
		$linkMode = normalize_stream_mount_link_mode($input['streamMountLinkMode']);
		if ($linkMode !== '') {
			return $linkMode === 'device';
		}
	}

	if (array_key_exists('streamMountFollowDevice', $input)) {
		return parse_boolean_flag($input['streamMountFollowDevice'], false);
	}

	if (array_key_exists('streamMountLinkDevice', $input)) {
		return parse_boolean_flag($input['streamMountLinkDevice'], false);
	}

	if (array_key_exists('streamMountLinkedToDevice', $input)) {
		return parse_boolean_flag($input['streamMountLinkedToDevice'], false);
	}

	$normalizedMount = trim($streamMount);
	if ($normalizedMount === '') {
		return false;
	}
	if (strpos($normalizedMount, '/') !== 0) {
		$normalizedMount = '/' . $normalizedMount;
	}

	$format = strtolower(trim($streamFormat)) === 'ogg' ? 'ogg' : 'mp3';
	$deviceDefault = build_default_stream_mount_from_device_index($deviceIndex, $format);
	return strcasecmp($normalizedMount, $deviceDefault) === 0;
}

function infer_stream_mount_follow_name(array $input, string $streamMount, string $streamName, string $streamFormat): bool
{
	if (array_key_exists('streamMountLinkMode', $input)) {
		$linkMode = normalize_stream_mount_link_mode($input['streamMountLinkMode']);
		if ($linkMode !== '') {
			return $linkMode === 'name';
		}
	}

	if (array_key_exists('streamMountFollowName', $input)) {
		return parse_boolean_flag($input['streamMountFollowName'], true);
	}

	if (array_key_exists('streamMountLinked', $input)) {
		return parse_boolean_flag($input['streamMountLinked'], true);
	}

	if (array_key_exists('streamMountAuto', $input)) {
		return parse_boolean_flag($input['streamMountAuto'], true);
	}

	$normalizedMount = trim($streamMount);
	if ($normalizedMount === '') {
		return true;
	}
	if (strpos($normalizedMount, '/') !== 0) {
		$normalizedMount = '/' . $normalizedMount;
	}

	$format = strtolower(trim($streamFormat)) === 'ogg' ? 'ogg' : 'mp3';
	$legacyDefault = '/rtl-sdr.' . $format;
	$nameDefault = build_default_stream_mount_from_name($streamName, $format);
 
	return strcasecmp($normalizedMount, $legacyDefault) === 0 || strcasecmp($normalizedMount, $nameDefault) === 0;
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
	if (!preg_match('/^[0-9]+(?:\.[0-9]+)?[kKmMgG]?(?::[0-9]+(?:\.[0-9]+)?[kKmMgG]?){0,2}(?:[\s\-][0-9]+(?:\.[0-9]+)?[kKmMgG]?(?::[0-9]+(?:\.[0-9]+)?[kKmMgG]?){0,2})*$/', $frequency)) {
		throw new RuntimeException('Frequency format is invalid. Examples: 146.520M, 146.435M 146.560M, 146.400M-146.600M, 118M:137M:25k');
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

	$resampleRateRaw = trim((string)($input['resampleRate'] ?? ''));
	$resampleRate = 0;
	$resampleRateRawLower = strtolower($resampleRateRaw);
	if ($resampleRateRawLower === '0' || $resampleRateRawLower === 'off' || $resampleRateRawLower === 'none' || $resampleRateRawLower === 'false' || $resampleRateRawLower === 'disabled') {
		$resampleRateRaw = '';
	}
	if ($resampleRateRaw !== '') {
		if (!preg_match('/^\d+$/', $resampleRateRaw)) {
			throw new RuntimeException('RTL resample rate must be a positive integer or Off.');
		}
		$resampleRate = (int)$resampleRateRaw;
		if ($resampleRate <= 0 || $resampleRate > 384000) {
			throw new RuntimeException('RTL resample rate must be between 1 and 384000.');
		}
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

	$postGain = trim((string)($input['postGain'] ?? ($input['radioPipeGain'] ?? '')));
	if ($postGain !== '') {
		if (!preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $postGain)) {
			throw new RuntimeException('radio-pipe gain must be numeric.');
		}
		$postGainValue = (float)$postGain;
		if ($postGainValue < -60.0 || $postGainValue > 60.0) {
			throw new RuntimeException('radio-pipe gain must be between -60 and 60 dB.');
		}
	}

	$inputDejitter = trim((string)($input['inputDejitter'] ?? ''));
	if ($inputDejitter !== '' && preg_match('/^[0-9]{1,6}$/', $inputDejitter) !== 1) {
		throw new RuntimeException('radio-pipe input dejitter must be an integer milliseconds value.');
	}

	$autoGainEnabled = parse_boolean_flag($input['autoGain'] ?? ($input['radioPipeAutoGain'] ?? '0'), false);
	$voiceFilterEnabled = parse_boolean_flag($input['voiceFilter'] ?? ($input['radioPipeVoiceFilter'] ?? '0'), false);
	$deemphasisEnabled = parse_boolean_flag($input['deemphasis'] ?? '0', false);
	$deemphasisTau = trim((string)($input['deemphasisTau'] ?? '75'));
	if ($deemphasisTau !== '' && !preg_match('/^[0-9]{1,4}$/', $deemphasisTau)) {
		throw new RuntimeException('De-emphasis TAU must be an integer between 0 and 1000 µs.');
	}
	if ($deemphasisTau !== '') {
		$deemphasisTauValue = (int)$deemphasisTau;
		if ($deemphasisTauValue < 0 || $deemphasisTauValue > 1000) {
			throw new RuntimeException('De-emphasis TAU must be between 0 and 1000 µs.');
		}
	}
	$pipeOutputEnabled = parse_boolean_flag($input['pipeOutputEnabled'] ?? ($input['enablePipeOutput'] ?? '1'), false);
	$pipeOutputs = normalize_pipe_output_entries($input['pipeOutputs'] ?? ($input['pipeOutputEntries'] ?? ($input['pipeOutput'] ?? array())));
	$pipeOutputRawEnabled = parse_boolean_flag($input['pipeOutputRaw'] ?? 0, false);
	$pipeOutputRawBits = trim((string)($input['pipeOutputBits'] ?? ''));
	if ($pipeOutputRawBits !== '' && preg_match('/^[0-9]{1,2}$/', $pipeOutputRawBits) !== 1) {
		throw new RuntimeException('Pipe Output Raw bits must be an integer value (for example: 8, 16, 24).');
	}
	$pipeOutputRawChannels = trim((string)($input['pipeOutputChannels'] ?? ''));
	if ($pipeOutputRawChannels !== '' && preg_match('/^[0-9]{1,2}$/', $pipeOutputRawChannels) !== 1) {
		throw new RuntimeException('Pipe Output Raw channels must be an integer value.');
	}
	$pipeOutputRawRate = trim((string)($input['pipeOutputRate'] ?? ''));
	if ($pipeOutputRawRate !== '' && preg_match('/^[0-9]{2,6}$/', $pipeOutputRawRate) !== 1) {
		throw new RuntimeException('Pipe Output Raw rate must be a valid sample rate in Hz.');
	}
	$pipeOutputRawUnsigned = parse_boolean_flag($input['pipeOutputUnsigned'] ?? 0, false);
	$pipeOutputRawBigEndian = parse_boolean_flag($input['pipeOutputBigEndian'] ?? 0, false);
	$pipeOutputPad = parse_boolean_flag($input['pipeOutputPad'] ?? ($pipeOutputRawEnabled ? 1 : 0), false);
	$pipeOutputPadDelay = trim((string)($input['pipeOutputPadDelay'] ?? ''));
	if ($pipeOutputPadDelay !== '' && preg_match('/^[0-9]{1,6}$/', $pipeOutputPadDelay) !== 1) {
		throw new RuntimeException('Pipe Output pad delay must be an integer milliseconds value.');
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
	
	// Check if pipe outputs are configured
	$pipeOutputPresetIds = isset($input['pipeOutputPresetIds']) && is_array($input['pipeOutputPresetIds'])
		? $input['pipeOutputPresetIds']
		: array();
	$hasPipeOutputPresets = count($pipeOutputPresetIds) > 0;
	$hasPipeOutputEntries = count($pipeOutputs) > 0;
	$hasPipeOutputsConfigured = $hasPipeOutputPresets || $hasPipeOutputEntries;
	
	// If pipe outputs are configured, allow both record and stream to be disabled
	// Otherwise, require at least one output to be enabled
	if (!$recordEnabled && !$streamEnabled && !$hasPipeOutputsConfigured) {
		throw new RuntimeException('Enable at least one output: Record, Stream, or Pipe Outputs.');
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

	$streamSampleRateValue = (int)($input['streamSampleRate'] ?? 44100);
	$allowedStreamSampleRates = array(22050, 44100, 48000, 96000);
	if (!in_array($streamSampleRateValue, $allowedStreamSampleRates, true)) {
		$streamSampleRateValue = 44100;
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

	$requestedMountLinkMode = normalize_stream_mount_link_mode($input['streamMountLinkMode'] ?? '');
	if ($requestedMountLinkMode !== '') {
		$streamMountFollowDevice = $requestedMountLinkMode === 'device';
		if ($requestedMountLinkMode === 'name') {
			$streamMountFollowName = true;
		} elseif ($requestedMountLinkMode === 'manual') {
			$streamMountFollowName = false;
		} else {
			$streamMountFollowName = array_key_exists('streamMountFollowName', $input)
				? parse_boolean_flag($input['streamMountFollowName'], true)
				: true;
		}
	} else {
		$streamMountFollowDevice = infer_stream_mount_follow_device($input, $streamMount, $deviceIndex, $streamFormat);
		$streamMountFollowName = infer_stream_mount_follow_name($input, $streamMount, $streamName, $streamFormat);
	}

	if ($streamMountFollowDevice) {
		$streamMount = build_default_stream_mount_from_device_index($deviceIndex, $streamFormat);
	} elseif ($streamMountFollowName || $streamMount === '') {
		$streamMount = build_default_stream_mount_from_name($streamName, $streamFormat);
	}
	$streamMountLinkMode = $streamMountFollowDevice
		? 'device'
		: ($streamMountFollowName ? 'name' : 'manual');

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
			$streamMount = $streamMountFollowDevice
				? build_default_stream_mount_from_device_index($deviceIndex, $streamFormat)
				: build_default_stream_mount_from_name($streamName, $streamFormat);
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

	$apiWebsocketOptions = get_radio_pipe_api_websocket_options(array(
		'device' => $deviceId,
		'deviceIndex' => $deviceIndex,
	));

	return array(
		'device' => $deviceId,
		'deviceIndex' => $deviceIndex,
		'frequency' => $frequency,
		'mode' => $mode,
		'rtlBandwidth' => $rtlBandwidth,
		'resampleRate' => $resampleRate,
		'sampleRate' => $sampleRate,
		'squelch' => (int)$squelch,
		'dcs' => $dcsCode,
		'ctcss' => $ctcssTone,
		'gain' => $gain,
		'biasT' => $biasTEnabled ? 1 : 0,
		'threshold' => $thresholdValue,
		'postGain' => $postGain,
		'inputDejitter' => $inputDejitter,
		'autoGain' => $autoGainEnabled ? 1 : 0,
		'voiceFilter' => $voiceFilterEnabled ? 1 : 0,
		'deemphasis' => $deemphasisEnabled ? 1 : 0,
		'deemphasisTau' => $deemphasisTau,
		'pipeOutputEnabled' => $pipeOutputEnabled ? 1 : 0,
		'pipeOutputs' => $pipeOutputs,
		'pipeOutputRaw' => $pipeOutputRawEnabled ? 1 : 0,
		'pipeOutputBits' => $pipeOutputRawBits,
		'pipeOutputChannels' => $pipeOutputRawChannels,
		'pipeOutputRate' => $pipeOutputRawRate,
		'pipeOutputUnsigned' => $pipeOutputRawUnsigned ? 1 : 0,
		'pipeOutputBigEndian' => $pipeOutputRawBigEndian ? 1 : 0,
		'pipeOutputPad' => $pipeOutputPad ? 1 : 0,
		'pipeOutputPadDelay' => $pipeOutputPadDelay,
		'silence' => $silenceValue,
		'outputMode' => $outputMode,
		'recordEnabled' => $recordEnabled,
		'streamEnabled' => $streamEnabled,
		'streamFormat' => $streamFormat,
		'streamBitrate' => $streamBitrateValue,
		'streamSampleRate' => $streamSampleRateValue,
		'streamTarget' => $streamTarget,
		'streamMount' => $streamMount,
		'streamMountLinkMode' => $streamMountLinkMode,
		'streamMountFollowName' => $streamMountFollowName,
		'streamMountFollowDevice' => $streamMountFollowDevice,
		'streamUsername' => $streamUsername,
		'streamPassword' => $streamPassword,
		'outputDir' => $outputDir,
		'streamName' => $streamName,
		'deviceSerial' => $deviceSerial,
		'apiWebsocketHost' => (string)($apiWebsocketOptions['host'] ?? ''),
		'apiWebsocketPort' => (int)($apiWebsocketOptions['port'] ?? 0),
		'apiWebsocketAddress' => (string)($apiWebsocketOptions['bindAddress'] ?? ''),
		'afterRecordAction' => $afterRecordAction,
		'postCommandArg' => $postCommandArg,
		'postCommand' => $postCommandArg,
		'recordingServerId' => $recordingServerId,
		'recordingUploadUrl' => $recordingUploadUrl,
		'recordingUploadUsername' => $recordingUploadUsername,
		'recordingUploadPassword' => $recordingUploadPassword,
	);
}

function radio_pipe_supports_stdout_padding(): bool
{
	static $supports = null;
	if ($supports !== null) {
		return $supports;
	}

	if (!command_exists('radio-pipe')) {
		$supports = false;
		return $supports;
	}

	$helpOutput = shell_exec('bash -lc ' . escapeshellarg('radio-pipe --help 2>&1'));
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

function radio_pipe_supports_stdout_pad_delay(): bool
{
	static $supports = null;
	if ($supports !== null) {
		return $supports;
	}

	if (!command_exists('radio-pipe')) {
		$supports = false;
		return $supports;
	}

	$helpOutput = shell_exec('bash -lc ' . escapeshellarg('radio-pipe --help 2>&1'));
	if (!is_string($helpOutput) || trim($helpOutput) === '') {
		$supports = false;
		return $supports;
	}

	$supports = stripos($helpOutput, '--stdout-pad-delay') !== false;
	return $supports;
}

function radio_pipe_supports_input_dejitter(): bool
{
	static $supports = null;
	if ($supports !== null) {
		return $supports;
	}

	if (!command_exists('radio-pipe')) {
		$supports = false;
		return $supports;
	}

	$helpOutput = shell_exec('bash -lc ' . escapeshellarg('radio-pipe --help 2>&1'));
	if (!is_string($helpOutput) || trim($helpOutput) === '') {
		$supports = false;
		return $supports;
	}

	$supports = stripos($helpOutput, '--input-dejitter') !== false;
	return $supports;
}

function get_rms_stdout_pad_delay_ms(): int
{
	global $RMS_STDOUT_PAD_DELAY_MS;
	return max(0, min(5000, (int)$RMS_STDOUT_PAD_DELAY_MS));
}

function get_rms_input_dejitter_ms(): int
{
	global $RMS_INPUT_DEJITTER_MS;
	return max(0, (int)$RMS_INPUT_DEJITTER_MS);
}

function radio_pipe_supports_ctcss(): bool
{
	static $supports = null;
	if ($supports !== null) {
		return $supports;
	}

	if (!command_exists('radio-pipe')) {
		$supports = false;
		return $supports;
	}

	$helpOutput = shell_exec('bash -lc ' . escapeshellarg('radio-pipe --help 2>&1'));
	if (!is_string($helpOutput) || trim($helpOutput) === '') {
		$supports = false;
		return $supports;
	}

	$supports = stripos($helpOutput, '--ctcss') !== false;
	return $supports;
}

function radio_pipe_supports_post_gain(): bool
{
	static $supports = null;
	if ($supports !== null) {
		return $supports;
	}

	if (!command_exists('radio-pipe')) {
		$supports = false;
		return $supports;
	}

	$helpOutput = shell_exec('bash -lc ' . escapeshellarg('radio-pipe --help 2>&1'));
	if (!is_string($helpOutput) || trim($helpOutput) === '') {
		$supports = false;
		return $supports;
	}

	$supports = stripos($helpOutput, '--gain') !== false;
	return $supports;
}

function radio_pipe_supports_auto_gain(): bool
{
	static $supports = null;
	if ($supports !== null) {
		return $supports;
	}

	if (!command_exists('radio-pipe')) {
		$supports = false;
		return $supports;
	}

	$helpOutput = shell_exec('bash -lc ' . escapeshellarg('radio-pipe --help 2>&1'));
	if (!is_string($helpOutput) || trim($helpOutput) === '') {
		$supports = false;
		return $supports;
	}

	$supports = stripos($helpOutput, '--auto-gain') !== false;
	return $supports;
}

function radio_pipe_supports_pipe_output(): bool
{
	static $supports = null;
	if ($supports !== null) {
		return $supports;
	}

	if (!command_exists('radio-pipe')) {
		$supports = false;
		return $supports;
	}

	$helpOutput = shell_exec('bash -lc ' . escapeshellarg('radio-pipe --help 2>&1'));
	if (!is_string($helpOutput) || trim($helpOutput) === '') {
		$supports = false;
		return $supports;
	}

	$supports = stripos($helpOutput, '--pipe-output') !== false;
	return $supports;
}

function radio_pipe_supports_api_websocket(): bool
{
	static $supports = null;
	if ($supports !== null) {
		return $supports;
	}

	if (!command_exists('radio-pipe')) {
		$supports = false;
		return $supports;
	}

	$helpOutput = shell_exec('bash -lc ' . escapeshellarg('radio-pipe --help 2>&1'));
	if (!is_string($helpOutput) || trim($helpOutput) === '') {
		$supports = false;
		return $supports;
	}

	$supports = stripos($helpOutput, '--api-websocket') !== false;
	return $supports;
}

function normalize_radio_pipe_api_websocket_host(string $host): string
{
	$trimmedHost = trim($host);
	if ($trimmedHost === '') {
		return '0.0.0.0';
	}

	if (preg_match('/^[A-Za-z0-9.:-]+$/', $trimmedHost) !== 1) {
		return '0.0.0.0';
	}

	if (strcasecmp($trimmedHost, 'localhost') === 0) {
		return '127.0.0.1';
	}

	return $trimmedHost;
}

function get_radio_pipe_api_websocket_base_port(): int
{
	global $RADIO_PIPE_API_WEBSOCKET_BASE_PORT;
	return max(1024, min(65000, (int)$RADIO_PIPE_API_WEBSOCKET_BASE_PORT));
}

function derive_radio_pipe_api_websocket_port(array $config): int
{
	$basePort = get_radio_pipe_api_websocket_base_port();
	$deviceIndex = normalize_device_index((string)($config['deviceIndex'] ?? ''));
	if ($deviceIndex !== '') {
		$offset = max(0, (int)$deviceIndex);
		$offset = min($offset, 65535 - $basePort);
		return $basePort + $offset;
	}

	$deviceId = normalize_device_id((string)($config['device'] ?? ''));
	if ($deviceId === '') {
		return $basePort;
	}

	$span = max(1, 65535 - $basePort);
	$hashValue = (int)sprintf('%u', crc32($deviceId));
	return $basePort + ($hashValue % $span);
}

function get_radio_pipe_api_websocket_options(array $config): array
{
	global $RADIO_PIPE_API_WEBSOCKET_HOST;

	if (!radio_pipe_supports_api_websocket()) {
		return array(
			'enabled' => false,
			'host' => '',
			'port' => 0,
			'bindAddress' => '',
		);
	}

	$host = normalize_radio_pipe_api_websocket_host((string)$RADIO_PIPE_API_WEBSOCKET_HOST);
	$port = derive_radio_pipe_api_websocket_port($config);
	if ($port < 1 || $port > 65535) {
		return array(
			'enabled' => false,
			'host' => '',
			'port' => 0,
			'bindAddress' => '',
		);
	}

	return array(
		'enabled' => true,
		'host' => $host,
		'port' => $port,
		'bindAddress' => $host . ':' . (string)$port,
	);
}

function build_rms_stdout_pad_conditioner_command(int $sampleRate, string $dcsCode = '', string $ctcssTone = '', int $stdoutPadDelayMs = 0, int $inputDejitterMs = 0, int $streamSampleRate = 0, string $apiWebsocketAddress = '', array $config = array()): string
{
	$conditionerCommand = array(
		'radio-pipe',
		'--stdin',
	);

	$trimmedApiWebsocketAddress = trim($apiWebsocketAddress);
	if ($trimmedApiWebsocketAddress !== '') {
		$conditionerCommand[] = '--api-websocket';
		$conditionerCommand[] = $trimmedApiWebsocketAddress;
	}

	if ($inputDejitterMs > 0 && radio_pipe_supports_input_dejitter()) {
		$conditionerCommand[] = '--input-dejitter';
		$conditionerCommand[] = (string)$inputDejitterMs;
	}

	array_push(
		$conditionerCommand,
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
		'600'
	);

	if ($dcsCode !== '') {
		$conditionerCommand[] = '--dcs';
		$conditionerCommand[] = $dcsCode;
	}

	if ($ctcssTone !== '') {
		$conditionerCommand[] = '--ctcss';
		$conditionerCommand[] = $ctcssTone;
	}

	$postGain = trim((string)($config['postGain'] ?? ''));
	if ($postGain !== '') {
		$conditionerCommand[] = '--gain';
		$conditionerCommand[] = $postGain;
	}

	if (parse_boolean_flag($config['autoGain'] ?? 0, false)) {
		$conditionerCommand[] = '--auto-gain';
	}

	if (parse_boolean_flag($config['voiceFilter'] ?? 0, false)) {
		$conditionerCommand[] = '--voice-filter';
	}

	if (parse_boolean_flag($config['deemphasis'] ?? 0, false)) {
		$conditionerCommand[] = '--deemphasis';
		$deTau = trim((string)($config['deemphasisTau'] ?? '75'));
		if ($deTau !== '' && $deTau !== '75') {
			$conditionerCommand[] = $deTau;
		}
	}

	append_pipe_output_args($conditionerCommand, $config);

	array_push(
		$conditionerCommand,
		'--stdout',
		'--stdout-raw',
		'--stdout-rate',
		(string)($streamSampleRate > 0 ? $streamSampleRate : $sampleRate),
		'--stdout-channels',
		'1',
		'--stdout-bits',
		'16',
		'--stdout-pad'
	);

	if ($stdoutPadDelayMs > 0 && radio_pipe_supports_stdout_pad_delay()) {
		$conditionerCommand[] = '--stdout-pad-delay';
		$conditionerCommand[] = (string)$stdoutPadDelayMs;
	}

	return command_from_parts($conditionerCommand);
}

function build_stream_input_conditioner_command(array $config): string
{
	$effectiveRate = ((int)($config['resampleRate'] ?? 0)) > 0 ? (int)$config['resampleRate'] : (int)$config['rtlBandwidth'];
	$sampleRate = max(1, $effectiveRate);
	$dcsCode = trim((string)($config['dcs'] ?? ''));
	$ctcssTone = trim((string)($config['ctcss'] ?? ''));
	$stdoutPadDelayMs = get_rms_stdout_pad_delay_ms();
	$inputDejitterMs = max(0, (int)($config['inputDejitter'] ?? 0));
	if ($inputDejitterMs <= 0) {
		$inputDejitterMs = get_rms_input_dejitter_ms();
	}
	$streamSampleRate = max(0, (int)($config['streamSampleRate'] ?? 0));
	$apiWebsocketOptions = get_radio_pipe_api_websocket_options($config);
	$apiWebsocketAddress = (string)($apiWebsocketOptions['bindAddress'] ?? '');

	// Use recorder's raw stdout + pad mode as a lightweight conditioner for ffmpeg.
	// Threshold/silence values keep the gate effectively open while stdout pad
	// smooths upstream rtl_fm stalls.
	return build_rms_stdout_pad_conditioner_command($sampleRate, $dcsCode, $ctcssTone, $stdoutPadDelayMs, $inputDejitterMs, $streamSampleRate, $apiWebsocketAddress, $config);
}

function build_recording_recorder_command(array $config, bool $enableStdout = false): string
{
	$recordEnabled = config_records_enabled($config);
	$inputDejitterMs = max(0, (int)($config['inputDejitter'] ?? 0));
	if ($inputDejitterMs <= 0 && $enableStdout) {
		$inputDejitterMs = get_rms_input_dejitter_ms();
	}

	$recorderCommand = array(
		'radio-pipe',
		'--stdin',
	);

	$apiWebsocketOptions = get_radio_pipe_api_websocket_options($config);
	$apiWebsocketAddress = trim((string)($apiWebsocketOptions['bindAddress'] ?? ''));
	if ($apiWebsocketAddress !== '') {
		$recorderCommand[] = '--api-websocket';
		$recorderCommand[] = $apiWebsocketAddress;
	}

	if ($inputDejitterMs > 0 && radio_pipe_supports_input_dejitter()) {
		$recorderCommand[] = '--input-dejitter';
		$recorderCommand[] = (string)$inputDejitterMs;
	}

	$effectiveStdinRate = ((int)($config['resampleRate'] ?? 0)) > 0 ? (int)$config['resampleRate'] : (int)$config['rtlBandwidth'];
	array_push(
		$recorderCommand,
		'--stdin-raw',
		'--stdin-rate',
		(string)$effectiveStdinRate,
		'--stdin-channels',
		'1',
		'--stdin-bits',
		'16',
		'-r',
		(string)$effectiveStdinRate,
		'-t',
		(string)$config['threshold'],
		'-s',
		(string)$config['silence'],
		'-n',
		build_output_display_name($config)
	);

	if ($recordEnabled) {
		$recorderCommand[] = '-o';
		$recorderCommand[] = (string)$config['outputDir'];
	}

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

	$postGain = trim((string)($config['postGain'] ?? ''));
	if ($postGain !== '') {
		$recorderCommand[] = '--gain';
		$recorderCommand[] = $postGain;
	}

	if (parse_boolean_flag($config['autoGain'] ?? 0, false)) {
		$recorderCommand[] = '--auto-gain';
	}

	if (parse_boolean_flag($config['voiceFilter'] ?? 0, false)) {
		$recorderCommand[] = '--voice-filter';
	}

	if (parse_boolean_flag($config['deemphasis'] ?? 0, false)) {
		$recorderCommand[] = '--deemphasis';
		$deTau = trim((string)($config['deemphasisTau'] ?? '75'));
		if ($deTau !== '' && $deTau !== '75') {
			$recorderCommand[] = $deTau;
		}
	}

	append_pipe_output_args($recorderCommand, $config);

	$afterRecordHook = build_after_record_hook_argument($config);
	if ($afterRecordHook !== '') {
		$recorderCommand[] = '-x';
		$recorderCommand[] = $afterRecordHook;
	}

	if ($enableStdout) {
		$stdoutPadDelayMs = get_rms_stdout_pad_delay_ms();
		$stdoutSampleRate = max(0, (int)($config['streamSampleRate'] ?? 0));
		if ($stdoutSampleRate <= 0) {
			$stdoutSampleRate = (int)$config['rtlBandwidth'];
		}

		array_push(
			$recorderCommand,
			'--stdout',
			'--stdout-raw',
			'--stdout-rate',
			(string)$stdoutSampleRate,
			'--stdout-channels',
			'1',
			'--stdout-bits',
			'16',
			'--stdout-pad'
		);

		if ($stdoutPadDelayMs > 0 && radio_pipe_supports_stdout_pad_delay()) {
			$recorderCommand[] = '--stdout-pad-delay';
			$recorderCommand[] = (string)$stdoutPadDelayMs;
		}
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

function get_stream_ffmpeg_settings(): array
{
	global $STREAM_FFMPEG_RETRY_ENABLED;
	global $STREAM_FFMPEG_RETRY_DELAY_SECONDS;
	global $STREAM_FFMPEG_RETRY_MAX_ATTEMPTS;
	global $STREAM_FFMPEG_TCP_TIMEOUT_US;
	global $STREAM_FFMPEG_HTTP_MULTIPLE_REQUESTS;
	global $STREAM_FFMPEG_TCP_NODELAY;

	return array(
		'retryEnabled' => parse_boolean_flag($STREAM_FFMPEG_RETRY_ENABLED, true),
		'retryDelaySeconds' => max(1, min(30, (int)$STREAM_FFMPEG_RETRY_DELAY_SECONDS)),
		'retryMaxAttempts' => max(0, (int)$STREAM_FFMPEG_RETRY_MAX_ATTEMPTS),
		'tcpTimeoutUs' => max(1000000, (int)$STREAM_FFMPEG_TCP_TIMEOUT_US),
		'httpMultipleRequests' => parse_boolean_flag($STREAM_FFMPEG_HTTP_MULTIPLE_REQUESTS, true),
		'tcpNodelay' => parse_boolean_flag($STREAM_FFMPEG_TCP_NODELAY, true),
	);
}

function build_stream_output_url(array $config, array $streamFfmpegSettings): string
{
	$mount = ltrim((string)$config['streamMount'], '/');
	$target = (string)$config['streamTarget'];
	$streamUsername = (string)($config['streamUsername'] ?? '');
	$streamPassword = (string)($config['streamPassword'] ?? '');
	$authPrefix = '';
	if ($streamUsername !== '' && $streamPassword !== '') {
		$authPrefix = rawurlencode($streamUsername) . ':' . rawurlencode($streamPassword) . '@';
	}

	$baseUrl = 'icecast://' . $authPrefix . $target . '/' . $mount;
	$protocolOptions = array(
		'timeout' => (string)max(1000000, (int)($streamFfmpegSettings['tcpTimeoutUs'] ?? 15000000)),
		'multiple_requests' => parse_boolean_flag($streamFfmpegSettings['httpMultipleRequests'] ?? true, true) ? '1' : '0',
		'tcp_nodelay' => parse_boolean_flag($streamFfmpegSettings['tcpNodelay'] ?? true, true) ? '1' : '0',
	);

	$queryString = http_build_query($protocolOptions, '', '&', PHP_QUERY_RFC3986);
	if (!is_string($queryString) || $queryString === '') {
		return $baseUrl;
	}

	return $baseUrl . '?' . $queryString;
}

function wrap_ffmpeg_stream_command_for_retry(string $ffmpegCommand, array $streamFfmpegSettings): string
{
	return $ffmpegCommand;
}

function build_stream_command(array $config): string
{
	$streamFfmpegSettings = get_stream_ffmpeg_settings();
	$streamFormat = strtolower((string)$config['streamFormat']) === 'ogg' ? 'ogg' : 'mp3';
	$codec = $streamFormat === 'ogg' ? 'libvorbis' : 'libmp3lame';
	$contentType = $streamFormat === 'ogg' ? 'audio/ogg' : 'audio/mpeg';
	$description = build_signal_description($config);
	$displayName = build_output_display_name($config);
	$streamBitrate = max(16, min(320, (int)$config['streamBitrate']));
	$vorbisQuality = max(0, min(10, (int)round(($streamBitrate - 32) / 32)));
	$streamOutputUrl = build_stream_output_url($config, $streamFfmpegSettings);
	$streamSampleRate = max(0, (int)($config['streamSampleRate'] ?? 0));
	if ($streamSampleRate <= 0) {
		$streamSampleRate = (int)$config['rtlBandwidth'];
	}

	$ffmpegCommand = array(
		'ffmpeg',
		'-hide_banner',
		'-loglevel',
		'warning',
		'-nostats',
		'-f',
		's16le',
		'-ar',
		(string)$streamSampleRate,
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
		$streamOutputUrl,
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

	$ffmpegCommandString = command_from_parts($ffmpegCommand);
	return wrap_ffmpeg_stream_command_for_retry($ffmpegCommandString, $streamFfmpegSettings);
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
		(string)(((int)($config['resampleRate'] ?? 0)) > 0 ? $config['resampleRate'] : $config['rtlBandwidth']),
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

	$pipeline .= ' | ' . build_recording_recorder_command($config, false);
	return $pipeline;
}

function stop_instance_by_pid(int $pid, int $processGroupId = 0): void
{
	$pid = max(0, $pid);
	$processGroupId = max(0, $processGroupId);
	if ($processGroupId <= 0 && $pid > 0) {
		$processGroupId = $pid;
	}

	if ($pid <= 0 && $processGroupId <= 0) {
		return;
	}

	$termCommand = 'true';
	if ($processGroupId > 0) {
		$termCommand = 'kill -TERM -- -' . $processGroupId . ' >/dev/null 2>&1 || true';
	}
	if ($pid > 0) {
		$termCommand .= '; kill -TERM ' . $pid . ' >/dev/null 2>&1 || true';
	}
	shell_exec('bash -lc ' . escapeshellarg($termCommand));
	usleep(250000);

	if (($processGroupId > 0 && is_process_group_running($processGroupId)) || ($pid > 0 && is_process_running($pid))) {
		$killCommand = 'true';
		if ($processGroupId > 0) {
			$killCommand = 'kill -KILL -- -' . $processGroupId . ' >/dev/null 2>&1 || true';
		}
		if ($pid > 0) {
			$killCommand .= '; kill -KILL ' . $pid . ' >/dev/null 2>&1 || true';
		}
		shell_exec('bash -lc ' . escapeshellarg($killCommand));
		usleep(500000);
	}
	
	// Give hardware time to fully release (rtl_fm and USB device)
	// rtl_fm can take 1-2 seconds to fully release the device
	usleep(1500000);
}

function start_instance(array $config, string $logDir, ?array $attemptDelaysUs = null): array
{
	$recorderAvailable = command_exists('radio-pipe');
	$dcsCode = trim((string)($config['dcs'] ?? ''));
	$ctcssTone = trim((string)($config['ctcss'] ?? ''));
	$bindingResult = refresh_runtime_device_binding_for_start($config);
	if (($bindingResult['ok'] ?? false) !== true || !isset($bindingResult['config']) || !is_array($bindingResult['config'])) {
		return array('ok' => false, 'error' => (string)($bindingResult['error'] ?? 'Failed to resolve runtime device binding.'));
	}
	$config = $bindingResult['config'];
	$postGain = trim((string)($config['postGain'] ?? ''));
	$autoGainEnabled = parse_boolean_flag($config['autoGain'] ?? 0, false);
	$pipeOutputEnabled = config_pipe_output_enabled($config);
	$pipeOutputs = config_pipe_outputs($config);
	$recordEnabled = config_records_enabled($config);
	$streamEnabled = config_stream_enabled($config);
	$uploadModeEnabled =
		$recordEnabled
		&& in_array(strtolower((string)($config['afterRecordAction'] ?? 'none')), array('upload', 'upload_delete'), true);
	$recorderSupportsStdoutPad = $recorderAvailable && radio_pipe_supports_stdout_padding();
	$configuredInputDejitterMs = max(0, (int)($config['inputDejitter'] ?? 0));
	$rmsInputDejitterMs = $streamEnabled ? get_rms_input_dejitter_ms() : 0;
	$effectiveInputDejitterMs = $configuredInputDejitterMs > 0 ? $configuredInputDejitterMs : $rmsInputDejitterMs;
	$recorderSupportsInputDejitter = $recorderAvailable && radio_pipe_supports_input_dejitter();
	$recorderSupportsApiWebsocket = $recorderAvailable && radio_pipe_supports_api_websocket();

	if ($streamEnabled) {
		if (!command_exists('ffmpeg')) {
			return array('ok' => false, 'error' => 'ffmpeg is required when Stream output is enabled but was not found in PATH.');
		}

		if (!$recorderAvailable) {
			return array('ok' => false, 'error' => 'radio-pipe is required for Stream output conditioning (--stdout-pad) but was not found in PATH.');
		}
	}

	if ($recordEnabled && !$recorderAvailable) {
		return array('ok' => false, 'error' => 'radio-pipe is required when Record output is enabled but was not found in PATH.');
	}

	if (!$recorderSupportsApiWebsocket) {
		return array('ok' => false, 'error' => 'radio-pipe must support --api-websocket. Upgrade radio-pipe and retry.');
	}

	if ($ctcssTone !== '' && !radio_pipe_supports_ctcss()) {
		return array('ok' => false, 'error' => 'CTCSS is configured but this radio-pipe build does not support --ctcss.');
	}

	if ($postGain !== '' && !radio_pipe_supports_post_gain()) {
		return array('ok' => false, 'error' => 'radio-pipe gain is configured but this radio-pipe build does not support --gain.');
	}

	if ($autoGainEnabled && !radio_pipe_supports_auto_gain()) {
		return array('ok' => false, 'error' => 'radio-pipe auto gain is configured but this radio-pipe build does not support --auto-gain.');
	}

	if (count($pipeOutputs) > 0 && !radio_pipe_supports_pipe_output()) {
		return array('ok' => false, 'error' => 'radio-pipe pipe-output mode is configured but this radio-pipe build does not support --pipe-output.');
	}

	if ($streamEnabled && !$recorderSupportsStdoutPad) {
		return array('ok' => false, 'error' => 'Stream output requires radio-pipe support for --stdout-raw and --stdout-pad. Upgrade radio-pipe and retry.');
	}

	if ($effectiveInputDejitterMs > 0 && !$recorderSupportsInputDejitter) {
		if ($configuredInputDejitterMs > 0) {
			return array('ok' => false, 'error' => 'radio-pipe input dejitter is configured for this device, but this radio-pipe build does not support --input-dejitter. Upgrade radio-pipe or set Input Dejitter to 0.');
		}
		return array('ok' => false, 'error' => 'Stream output is configured with RMS_INPUT_DEJITTER_MS, but this radio-pipe build does not support --input-dejitter. Upgrade radio-pipe or set RMS_INPUT_DEJITTER_MS to 0.');
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

	$apiWebsocketOptions = get_radio_pipe_api_websocket_options($config);
	$apiWebsocketAddress = trim((string)($apiWebsocketOptions['bindAddress'] ?? ''));
	if (!(bool)($apiWebsocketOptions['enabled'] ?? false) || $apiWebsocketAddress === '') {
		return array('ok' => false, 'error' => 'Failed to resolve radio-pipe websocket endpoint for this device.');
	}
	$config['apiWebsocketHost'] = (string)($apiWebsocketOptions['host'] ?? '');
	$config['apiWebsocketPort'] = (int)($apiWebsocketOptions['port'] ?? 0);
	$config['apiWebsocketAddress'] = $apiWebsocketAddress;

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
	$processGroupId = 0;

	for ($attempt = 0; $attempt < count($attemptDelays); $attempt++) {
		$runtimeDeviceIndex = (string)($config['deviceIndex'] ?? $config['device']);
		force_release_device($runtimeDeviceIndex);
		$launchTracking = launch_pipeline_process($pipelineCommand, $logPath);
		$pid = (int)($launchTracking['pid'] ?? 0);
		$processGroupId = max(0, (int)($launchTracking['pgid'] ?? 0));
		if ($processGroupId <= 0 && $pid > 0) {
			$processGroupId = $pid;
		}

		if ($pid <= 0) {
			$lastError = 'Failed to launch pipeline process.';
			usleep($attemptDelays[$attempt]);
			continue;
		}

		usleep(900000);
		if (($processGroupId > 0 && is_process_group_running($processGroupId)) || is_process_running($pid)) {
			return array(
				'ok' => true,
				'pid' => $pid,
				'pgid' => $processGroupId,
				'logFile' => $logFileName,
				'command' => $pipelineCommandForLog,
				'config' => $config,
			);
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
		$processGroupId = get_instance_process_group_id((array)$instance);
		$running = is_instance_running((array)$instance);
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
			'pgid' => $processGroupId,
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

function get_device_scan_settings(): array
{
	global $DEVICE_SCAN_MIN_INTERVAL_SECONDS;
	global $DEVICE_SCAN_CACHE_FILE;

	$cacheFile = trim((string)$DEVICE_SCAN_CACHE_FILE);
	if ($cacheFile === '') {
		$cacheFile = __DIR__ . '/rtl_sdr_device_scan_cache.json';
	}

	return array(
		'minIntervalSeconds' => max(10, (int)$DEVICE_SCAN_MIN_INTERVAL_SECONDS),
		'cacheFile' => $cacheFile,
	);
}

function merge_discovered_devices_by_index(array &$devicesByIndex, array $devices): void
{
	foreach ($devices as $device) {
		if (!is_array($device)) {
			continue;
		}

		$index = normalize_device_index((string)($device['index'] ?? ''));
		if ($index === '') {
			continue;
		}

		$fallbackLabel = 'RTL-SDR Device ' . $index;
		$current = isset($devicesByIndex[$index]) && is_array($devicesByIndex[$index])
			? $devicesByIndex[$index]
			: array(
				'id' => build_device_id_from_index_and_serial($index, ''),
				'index' => $index,
				'label' => $fallbackLabel,
				'serial' => '',
			);

		$currentLabel = sanitize_device_label((string)($current['label'] ?? ''), $fallbackLabel);
		$incomingLabel = trim((string)($device['label'] ?? ''));
		$label = $incomingLabel === ''
			? $currentLabel
			: sanitize_device_label($incomingLabel, $currentLabel);

		$currentSerial = normalize_device_serial((string)($current['serial'] ?? ''));
		$incomingSerial = normalize_device_serial((string)($device['serial'] ?? extract_device_serial($incomingLabel)));
		$serial = $incomingSerial !== '' ? $incomingSerial : $currentSerial;

		$devicesByIndex[$index] = array(
			'id' => build_device_id_from_index_and_serial($index, $serial),
			'index' => $index,
			'label' => $label,
			'serial' => $serial,
		);
	}
}

function read_device_scan_cache(string $cacheFile): array
{
	$empty = array(
		'scannedAt' => 0,
		'devices' => array(),
		'warning' => '',
	);

	if ($cacheFile === '' || !file_exists($cacheFile)) {
		return $empty;
	}

	$raw = @file_get_contents($cacheFile);
	if (!is_string($raw) || trim($raw) === '') {
		return $empty;
	}

	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		return $empty;
	}

	$devicesRaw = isset($decoded['devices']) && is_array($decoded['devices'])
		? $decoded['devices']
		: array();
	$devicesByIndex = array();
	merge_discovered_devices_by_index($devicesByIndex, $devicesRaw);
	ksort($devicesByIndex, SORT_NATURAL);

	return array(
		'scannedAt' => max(0, (int)($decoded['scannedAt'] ?? 0)),
		'devices' => array_values($devicesByIndex),
		'warning' => trim((string)($decoded['warning'] ?? '')),
	);
}

function write_device_scan_cache(string $cacheFile, int $scannedAt, array $devices, string $warning): void
{
	if ($cacheFile === '') {
		return;
	}

	$cacheDir = dirname($cacheFile);
	if ($cacheDir !== '' && $cacheDir !== '.' && !is_dir($cacheDir)) {
		@mkdir($cacheDir, 0775, true);
	}

	$devicesByIndex = array();
	merge_discovered_devices_by_index($devicesByIndex, $devices);
	ksort($devicesByIndex, SORT_NATURAL);

	$payload = array(
		'scannedAt' => max(0, $scannedAt),
		'devices' => array_values($devicesByIndex),
		'warning' => trim($warning),
	);

	$encoded = json_encode($payload, JSON_PRETTY_PRINT);
	if (!is_string($encoded)) {
		return;
	}

	@file_put_contents($cacheFile, $encoded . "\n", LOCK_EX);
}

function execute_device_scan_command(string $baseCommand): string
{
	$commands = array();
	$wrappedCommand = wrap_for_device_access($baseCommand);
	if ($wrappedCommand !== '') {
		$commands[] = $wrappedCommand;
	}
	if ($wrappedCommand !== $baseCommand) {
		$commands[] = $baseCommand;
	}

	$fallbackOutput = '';
	$usingDeviceOutput = '';

	foreach ($commands as $command) {
		$output = shell_exec('bash -lc ' . escapeshellarg($command));
		if (!is_string($output) || trim($output) === '') {
			continue;
		}

		if ($fallbackOutput === '') {
			$fallbackOutput = $output;
		}

		if (preg_match('/Found\s+[0-9]+\s+device\(s\)/i', $output) === 1) {
			return $output;
		}

		if ($usingDeviceOutput === '' && preg_match('/^Using device\s+[0-9]+\s*:/mi', $output) === 1) {
			$usingDeviceOutput = $output;
		}
	}

	if ($usingDeviceOutput !== '') {
		return $usingDeviceOutput;
	}

	return $fallbackOutput;
}

function parse_rtl_fm_scan_output(string $scanOutput): array
{
	$expectedCount = null;
	$parsedDevices = array();
	$listedIndexes = array();

	$lines = preg_split('/\r\n|\r|\n/', $scanOutput);
	if (!is_array($lines)) {
		$lines = array();
	}

	foreach ($lines as $line) {
		$trimmed = trim((string)$line);
		if ($trimmed === '') {
			continue;
		}

		if (preg_match('/^Found\s+([0-9]+)\s+device\(s\)(?::|\.|$)/i', $trimmed, $matches) === 1) {
			$expectedCount = (int)$matches[1];
			continue;
		}

		$index = '';
		$label = '';
		if (preg_match('/^([0-9]+):\s*(.+)$/', $trimmed, $matches) === 1) {
			$index = normalize_device_index((string)$matches[1]);
			$label = trim((string)$matches[2]);
		} elseif (preg_match('/^\[\s*([0-9]+)\s*\]\s*(.+)$/', $trimmed, $matches) === 1) {
			$index = normalize_device_index((string)$matches[1]);
			$label = trim((string)$matches[2]);
		} elseif (preg_match('/^Device\s+([0-9]+)\s*:\s*(.+)$/i', $trimmed, $matches) === 1) {
			$index = normalize_device_index((string)$matches[1]);
			$label = trim((string)$matches[2]);
		} elseif (preg_match('/^Using device\s+([0-9]+)\s*:\s*(.+)$/i', $trimmed, $matches) === 1) {
			$index = normalize_device_index((string)$matches[1]);
			$label = trim((string)$matches[2]);
		}

		if ($index !== '') {
			if (preg_match('/^Using device\s+/i', $trimmed) === 1 && isset($listedIndexes[$index])) {
				continue;
			}

			$parsedDevices[] = array(
				'index' => $index,
				'label' => sanitize_device_label($label, 'RTL-SDR Device ' . $index),
				'serial' => extract_device_serial($label),
			);

			if (preg_match('/^Using device\s+/i', $trimmed) !== 1) {
				$listedIndexes[$index] = true;
			}
			continue;
		}
	}

	$devicesByIndex = array();
	merge_discovered_devices_by_index($devicesByIndex, $parsedDevices);
	ksort($devicesByIndex, SORT_NATURAL);

	return array(
		'devices' => array_values($devicesByIndex),
		'expectedCount' => $expectedCount,
	);
}

function discover_rtl_devices(): array
{
	$settings = get_device_scan_settings();
	$cacheFile = (string)($settings['cacheFile'] ?? '');
	$minIntervalSeconds = max(10, (int)($settings['minIntervalSeconds'] ?? 60));
	$now = time();

	$cache = read_device_scan_cache($cacheFile);
	$cachedAt = max(0, (int)($cache['scannedAt'] ?? 0));
	if ($cachedAt > 0 && ($now - $cachedAt) < $minIntervalSeconds) {
		return array(
			'devices' => isset($cache['devices']) && is_array($cache['devices']) ? $cache['devices'] : array(),
			'warning' => (string)($cache['warning'] ?? ''),
		);
	}

	$cachedDevices = isset($cache['devices']) && is_array($cache['devices']) ? $cache['devices'] : array();

	if (!command_exists('rtl_fm')) {
		$warning = 'rtl_fm was not found in PATH, so hardware auto-discovery is unavailable.';
		write_device_scan_cache($cacheFile, $now, $cachedDevices, $warning);
		return array('devices' => $cachedDevices, 'warning' => $warning);
	}

	$baseScanCommand = command_exists('timeout')
		? 'LC_ALL=C timeout 10 rtl_fm -d 0 2>&1'
		: 'LC_ALL=C rtl_fm -d 0 2>&1';

	$scanOutput = execute_device_scan_command($baseScanCommand);
	if ($scanOutput === '') {
		$warning = 'rtl_fm returned no output.';
		write_device_scan_cache($cacheFile, $now, $cachedDevices, $warning);
		return array('devices' => $cachedDevices, 'warning' => $warning);
	}

	$parsed = parse_rtl_fm_scan_output($scanOutput);
	$devicesByIndex = array();
	$parsedDevices = isset($parsed['devices']) && is_array($parsed['devices']) ? $parsed['devices'] : array();
	merge_discovered_devices_by_index($devicesByIndex, $parsedDevices);

	$expectedCount = isset($parsed['expectedCount']) ? (int)$parsed['expectedCount'] : null;
	if ($expectedCount !== null && $expectedCount > 0) {
		for ($index = 0; $index < $expectedCount; $index++) {
			$normalizedIndex = (string)$index;
			if (!isset($devicesByIndex[$normalizedIndex])) {
				$devicesByIndex[$normalizedIndex] = array(
					'id' => build_device_id_from_index_and_serial($normalizedIndex, ''),
					'index' => $normalizedIndex,
					'label' => 'RTL-SDR Device ' . $normalizedIndex,
					'serial' => '',
				);
			}
		}
	}

	ksort($devicesByIndex, SORT_NATURAL);
	$devices = array_values($devicesByIndex);
	$warning = '';

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

	write_device_scan_cache($cacheFile, $now, $devices, $warning);
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

$requestSource = '';
if (isset($_REQUEST['source'])) {
	$requestSource = trim((string)$_REQUEST['source']);
} elseif (isset($jsonPayload['source'])) {
	$requestSource = trim((string)$jsonPayload['source']);
}

if ($action !== '') {
	$stateLockHandle = acquire_runtime_state_lock($STATE_FILE);
	if ($stateLockHandle === null) {
		send_json(array('ok' => false, 'error' => 'Failed to lock runtime state.'), 500);
	}
	register_shutdown_function(static function () use ($stateLockHandle): void {
		release_runtime_state_lock($stateLockHandle);
	});

	$state = load_state($STATE_FILE);
	$desiredState = load_desired_state($DESIRED_STATE_FILE);
	$desiredStateChanged = false;
	$shouldReconcileRuntimeState = count($state) === 0;
	if (!$shouldReconcileRuntimeState) {
		foreach ($desiredState as $desiredDeviceId => $entry) {
			if (!device_is_desired_running($desiredState, (string)$desiredDeviceId, true)) {
				continue;
			}
			if (find_state_device_key($state, (string)$desiredDeviceId) === '') {
				$shouldReconcileRuntimeState = true;
				break;
			}
		}
	}
	if ($shouldReconcileRuntimeState && reconcile_runtime_state_with_live_process_groups($state, $LOG_DIR, $UI_SETTINGS_FILE)) {
		save_state($STATE_FILE, $state);
		sync_ui_settings_with_running_state($UI_SETTINGS_FILE, $state);
	}

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
		$templates = load_templates($TEMPLATES_DIR, $UI_SETTINGS_FILE, $TEMPLATES_FILE);
		send_json(array('ok' => true, 'templates' => (object)$templates));
	}

	if ($action === 'templates_set') {
		$payload = $_POST;
		if (is_array($jsonPayload) && count($jsonPayload) > 0) {
			$payload = array_merge($payload, $jsonPayload);
		}

		$rawTemplates = isset($payload['templates']) && is_array($payload['templates']) ? $payload['templates'] : array();
		$templates = normalize_templates($rawTemplates);
		// Merge semantics: write/update each provided template without removing
		// any others that exist on disk. This prevents one client from clobbering
		// another client's templates when both POST a stale snapshot.
		if (!save_templates($TEMPLATES_DIR, $templates)) {
			send_json(array('ok' => false, 'error' => 'Failed to save templates.'), 500);
		}

		$allTemplates = read_templates_from_dir($TEMPLATES_DIR);
		send_json(array('ok' => true, 'templates' => (object)$allTemplates));
	}

	if ($action === 'template_delete') {
		$payload = $_POST;
		if (is_array($jsonPayload) && count($jsonPayload) > 0) {
			$payload = array_merge($payload, $jsonPayload);
		}

		$name = isset($payload['name']) ? trim((string)$payload['name']) : '';
		if ($name === '') {
			send_json(array('ok' => false, 'error' => 'Template name is required.'), 400);
		}

		if (!delete_template_from_dir($TEMPLATES_DIR, $name)) {
			send_json(array('ok' => false, 'error' => 'Failed to delete template.'), 500);
		}

		$allTemplates = read_templates_from_dir($TEMPLATES_DIR);
		send_json(array('ok' => true, 'templates' => (object)$allTemplates));
	}

	if ($action === 'settings_get') {
		$settings = sync_ui_settings_with_running_state($UI_SETTINGS_FILE, $state);
		$pipeOutputs = load_pipe_outputs($PIPE_OUTPUTS_FILE, $UI_SETTINGS_FILE);
		send_json(array('ok' => true, 'settings' => ui_settings_for_response($settings, $pipeOutputs)));
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

		$pipeOutputs = load_pipe_outputs($PIPE_OUTPUTS_FILE, $UI_SETTINGS_FILE);
		send_json(array('ok' => true, 'settings' => ui_settings_for_response($settings, $pipeOutputs)));
	}

	if ($action === 'pipe_outputs_set') {
		$payload = $_POST;
		if (is_array($jsonPayload) && count($jsonPayload) > 0) {
			$payload = array_merge($payload, $jsonPayload);
		}

		$rawPresets = isset($payload['presets']) && is_array($payload['presets']) ? $payload['presets'] : array();
		$presets = normalize_pipe_output_presets($rawPresets);
		if (!save_pipe_outputs($PIPE_OUTPUTS_FILE, $presets)) {
			send_json(array('ok' => false, 'error' => 'Failed to save pipe outputs.'), 500);
		}

		send_json(array('ok' => true, 'presets' => (object)$presets));
	}

	if ($action === 'list') {
		$queueSnapshot = get_action_queue_snapshot();
		$queueResult = array(
			'processed' => 0,
			'pending' => 0,
			'busy' => false,
			'stateChanged' => false,
			'desiredStateChanged' => false,
		);
		$queueWorkerEnabled = is_watchdog_queue_worker_request($requestSource);
		if ($queueWorkerEnabled) {
			$queueResult = process_queued_stream_actions($state, $desiredState, $LOG_DIR, $recordingsRoot);
			if ((bool)($queueResult['desiredStateChanged'] ?? false)) {
				save_desired_state($DESIRED_STATE_FILE, $desiredState);
			}
			$queueSnapshot = get_action_queue_snapshot();
		}

		cleanup_stale_logs_by_device($LOG_DIR);
		save_state($STATE_FILE, $state);
		$settings = sync_ui_settings_with_running_state($UI_SETTINGS_FILE, $state);
		$pipeOutputs = load_pipe_outputs($PIPE_OUTPUTS_FILE, $UI_SETTINGS_FILE);
		$response = array(
			'ok' => true,
			'instances' => list_instances($state),
			'settings' => ui_settings_for_response($settings, $pipeOutputs),
		);
		$response['queue'] = array(
			'processed' => $queueWorkerEnabled ? (int)($queueResult['processed'] ?? 0) : 0,
			'pending' => (int)($queueSnapshot['pending'] ?? 0),
			'busy' => $queueWorkerEnabled ? (bool)($queueResult['busy'] ?? false) : false,
			'worker' => $queueWorkerEnabled,
			'lastProcessedAt' => (int)($queueSnapshot['lastProcessedAt'] ?? 0),
			'lastResult' => isset($queueSnapshot['lastResult']) && is_array($queueSnapshot['lastResult'])
				? $queueSnapshot['lastResult']
				: array(),
			'results' => $queueWorkerEnabled && isset($queueResult['results']) && is_array($queueResult['results'])
				? $queueResult['results']
				: array(),
		);

		send_json($response);
	}

	if ($action === 'radio_pipe_status_batch') {
		$payload = $_POST;
		if (is_array($jsonPayload) && count($jsonPayload) > 0) {
			$payload = array_merge($payload, $jsonPayload);
		}

		$rawDevices = isset($payload['devices']) ? $payload['devices'] : array();
		$deviceIds = normalize_device_id_list($rawDevices);
		if (count($deviceIds) === 0) {
			$runningInstances = list_instances($state);
			$detectedDeviceIds = array();
			foreach ($runningInstances as $runningInstance) {
				$instanceDeviceId = normalize_device_id((string)($runningInstance['device'] ?? ''));
				if ($instanceDeviceId === '') {
					continue;
				}
				$detectedDeviceIds[$instanceDeviceId] = true;
			}
			$deviceIds = array_keys($detectedDeviceIds);
		}

		$statusesByDevice = array();
		foreach ($deviceIds as $batchDeviceId) {
			$statusesByDevice[$batchDeviceId] = build_radio_pipe_status_payload_for_device((string)$batchDeviceId, $state);
		}

		send_json(array('ok' => true, 'statuses' => $statusesByDevice));
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
		$processGroupId = get_instance_process_group_id((array)$state[$stateDeviceKey]);
		stop_instance_by_pid($pid, $processGroupId);
		unset($state[$stateDeviceKey]);
		save_state($STATE_FILE, $state);
		send_json(array('ok' => true, 'message' => 'Stopped device ' . $resolvedDeviceId . '.', 'instances' => list_instances($state)));
	}

	if ($action === 'stop_all') {
		foreach ($state as $instance) {
			$pid = isset($instance['pid']) ? (int)$instance['pid'] : 0;
			$processGroupId = get_instance_process_group_id((array)$instance);
			stop_instance_by_pid($pid, $processGroupId);
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

		if ($action === 'retune') {
			$queueDeviceId = normalize_device_id((string)($config['device'] ?? ''));
			$enqueued = enqueue_stream_action_request('retune', $config);
			if (($enqueued['ok'] ?? false) !== true) {
				send_json(array('ok' => false, 'error' => (string)($enqueued['error'] ?? 'Failed to queue retune action.')), 500);
			}

			if ($queueDeviceId !== '' && set_device_desired_running($desiredState, $queueDeviceId, true)) {
				save_desired_state($DESIRED_STATE_FILE, $desiredState);
			}

			send_json(array(
				'ok' => true,
				'queued' => true,
				'queueId' => (string)($enqueued['id'] ?? ''),
				'queuePosition' => (int)($enqueued['position'] ?? 0),
				'message' => 'RETUNE queued for device ' . $queueDeviceId . '. It will be applied on the next watchdog tick.',
				'instances' => list_instances($state),
			));
		}

		$deviceId = (string)$config['device'];
		$existingStateKey = find_state_device_key($state, $deviceId);
		if ($existingStateKey !== '') {
			$existingPid = isset($state[$existingStateKey]['pid']) ? (int)$state[$existingStateKey]['pid'] : 0;
			$existingProcessGroupId = get_instance_process_group_id((array)$state[$existingStateKey]);
			stop_instance_by_pid($existingPid, $existingProcessGroupId);
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
		.hero-right {
			display: inline-flex;
			flex-direction: column;
			gap: 8px;
			align-items: flex-end;
		}
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
		.queue-chip {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			padding: 8px 12px;
			border-radius: 999px;
			background: rgba(255,255,255,0.03);
			border: 1px solid var(--border);
			font-size: 11px;
			color: var(--muted);
			max-width: 420px;
		}
		.queue-chip-label {
			text-transform: uppercase;
			letter-spacing: 0.08em;
			font-size: 10px;
			color: var(--muted);
			flex: 0 0 auto;
		}
		#queueStatusText {
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
			max-width: 300px;
		}
		.queue-chip.pending { color: #ffd783; border-color: rgba(217, 164, 65, 0.5); background: rgba(217, 164, 65, 0.16); }
		.queue-chip.success { color: #b7f09a; border-color: rgba(135, 211, 124, 0.48); background: rgba(135, 211, 124, 0.16); }
		.queue-chip.error { color: #ffb3b3; border-color: rgba(241, 143, 143, 0.45); background: rgba(241, 143, 143, 0.14); }
		.queue-chip.busy { color: #8df7d4; border-color: rgba(99, 215, 176, 0.5); background: rgba(99, 215, 176, 0.16); }
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
		.template-modal-content { max-width: 960px; }
		.template-modal-actions { display: flex; flex-wrap: wrap; gap: 8px; }
		.template-modal-actions .refresh-button { flex: 1 1 220px; }
		#templateDeviceSelect { min-width: 210px; }
		.device-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; }
		.device-card { padding: 14px; position: relative; overflow: hidden; }
		.device-card::after {
			content: "";
			position: absolute;
			inset: auto -20% -40% auto;
			width: 180px;
			height: 180px;
			background: radial-gradient(circle, rgba(217, 164, 65, 0.16), transparent 70%);
			pointer-events: none;
		}
		.device-header { display: grid; grid-template-columns: minmax(0, 1fr); gap: 4px; align-items: start; position: relative; z-index: 1; }
		.device-title-row { min-width: 0; }
		.device-title-line { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; min-width: 0; }
		.device-rms-stack { margin-top: 6px; display: flex; flex-direction: column; gap: 5px; min-width: 0; width: 100%; }
		.device-rms-meter { display: flex; flex-direction: column; align-items: stretch; gap: 2px; min-width: 0; width: 100%; }
		.device-rms-track {
			position: relative;
			flex: 1 1 auto;
			width: 100%;
			min-width: 0;
			height: 9px;
			border-radius: 999px;
			overflow: hidden;
			border: 1px solid var(--border);
			background: linear-gradient(to bottom, var(--panel-strong), var(--input));
		}
		.device-rms-track::after {
			content: "";
			position: absolute;
			inset: 0;
			pointer-events: none;
			background: repeating-linear-gradient(
				90deg,
				transparent 0,
				transparent calc(10% - 1px),
				var(--border) calc(10% - 1px),
				var(--border) 10%
			);
			opacity: 0.85;
		}
		.device-rms-fill {
			position: relative;
			width: 0%;
			height: 100%;
			transition: width 0.18s ease, background-color 0.18s ease, opacity 0.18s ease;
			background: var(--muted);
		}
		.device-rms-fill::before {
			content: "";
			position: absolute;
			inset: 0;
			background: linear-gradient(to bottom, var(--panel-strong), transparent);
			opacity: 0.25;
			pointer-events: none;
		}
		.device-rms-fill::after {
			content: "";
			position: absolute;
			right: 0;
			top: 0;
			bottom: 0;
			width: 2px;
			background: var(--text);
			opacity: 0.28;
			pointer-events: none;
		}
		.device-rms-meter.rms-off .device-rms-fill { background: var(--muted); opacity: 0.45; }
		.device-rms-meter.rms-idle .device-rms-fill { background: var(--muted); opacity: 0.7; }
		.device-rms-meter.rms-low .device-rms-fill { background: var(--danger); opacity: 0.95; }
		.device-rms-meter.rms-mid .device-rms-fill { background: var(--accent); opacity: 0.95; }
		.device-rms-meter.rms-high .device-rms-fill { background: var(--success); opacity: 0.95; }
		.device-rms-label { flex: 0 0 auto; min-width: 0; text-align: left; font-size: 10px; line-height: 1.1; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); }
		.state-pills {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			flex-wrap: nowrap;
			justify-content: flex-end;
			flex: 0 0 auto;
			white-space: nowrap;
		}
		.device-title { margin: 0; font-size: 20px; line-height: 1.2; min-width: 0; }
		.device-stream-name { grid-column: 1 / -1; margin: 0; color: var(--accent); font-size: 16px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
		.device-subtitle { grid-column: 1 / -1; margin: 2px 0 0; color: var(--muted); font-size: 12px; line-height: 1.35; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
		.device-antenna-row { grid-column: 1 / -1; display: flex; align-items: center; gap: 8px; min-width: 0; margin-top: 1px; }
		.device-antenna-label { margin: 0; color: var(--muted); font-size: 12px; line-height: 1.5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
		.state-pill {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			padding: 5px 10px;
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
		.state-pills .action-listen-stream { display: inline-flex; align-items: center; padding: 5px 10px; font-size: 11px; border-radius: 999px; border: 1px solid var(--border); background: rgba(255,255,255,0.04); color: var(--text); cursor: pointer; text-transform: uppercase; letter-spacing: 0.08em; transition: all 0.2s; font-family: inherit; font-weight: 500; white-space: nowrap; }
		.state-pills .action-copy-stream:hover { background: rgba(255,255,255,0.08); }
		.state-pills .action-listen-stream:hover { background: rgba(255,255,255,0.08); }
		.state-pills .action-listen-stream.danger { color: #ffb3b3; border-color: rgba(241, 143, 143, 0.45); background: rgba(241, 143, 143, 0.14); }
		.device-actions { display: flex; gap: 8px; row-gap: 8px; flex-wrap: wrap; margin-top: 12px; position: relative; z-index: 1; }
		.device-log-toggle { margin-top: 10px; position: relative; z-index: 1; display: flex; gap: 8px; flex-wrap: wrap; }
		.device-meta { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px 8px; margin-top: 10px; position: relative; z-index: 1; align-items: start; }
		.meta-box { display: flex; flex-direction: column; justify-content: flex-start; gap: 1px; min-height: 0; padding: 6px 9px 7px; border-radius: 12px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); }
		.meta-box.full { grid-column: span 3; min-height: auto; }
		.meta-label { display: block; font-size: 10px; line-height: 1.05; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); margin-bottom: 1px; }
		.meta-label-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 1px; }
		.meta-label-row .meta-label { margin-bottom: 0; }
		.meta-label-row .refresh-button.compact { padding: 4px 8px; font-size: 10px; letter-spacing: 0.06em; }
		.meta-value { font-size: 12px; line-height: 1.15; word-break: break-word; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.meta-box.full .meta-value { overflow: visible; text-overflow: clip; white-space: normal; }
		.meta-value.pipeline-lines { display: flex; flex-direction: column; gap: 3px; font: 12px/1.4 "Cascadia Mono", "Consolas", monospace; }
		.meta-value.pipeline-lines .pipeline-line { display: block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.device-config { margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,0.06); position: relative; z-index: 1; }
		.device-config.collapsed { display: none; }
		.device-log-panel { margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,0.06); position: relative; z-index: 1; }
		.device-log-panel.collapsed { display: none; }
		.log-shell { border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; background: rgba(4, 8, 14, 0.72); padding: 12px; }
		body.theme-light .log-shell { background: rgba(43, 38, 32, 0.06); }
		.log-header { display: flex; justify-content: space-between; gap: 10px; align-items: center; margin-bottom: 8px; }
		.log-meta { font-size: 11px; color: var(--muted); }
		.log-lines { margin: 0; min-height: 140px; max-height: 280px; overflow: auto; white-space: pre-wrap; font: 12px/1.45 "Cascadia Mono", "Consolas", monospace; color: var(--text); }
		.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; margin-bottom: 9px; }
		.form-row.single { grid-template-columns: 1fr; }
		.form-row.template-button-row .refresh-button { width: 100%; }
		.pipe-output-list { display: flex; flex-direction: column; gap: 8px; }
		.pipe-output-presets-list { display: flex; flex-direction: column; gap: 4px; }
		.pipe-output-entry-row { display: flex; align-items: center; gap: 8px; }
		.pipe-output-entry-row .checkbox-label { flex: 1; min-width: 0; }
		.pipe-output-entry-row .action-edit-pipe-output-preset { flex: 0 0 auto; padding: 4px 8px; font-size: 14px; }
		.pipe-output-row { display: flex; gap: 8px; align-items: center; }
		.pipe-output-row .field-pipe-output-entry { flex: 1; }
		.pipe-output-row .action-remove-pipe-output { flex: 0 0 auto; }
		label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); margin-bottom: 5px; }
		label.checkbox-label { display: flex; align-items: center; gap: 8px; margin-bottom: 0; padding: 10px 0; cursor: pointer; font-size: 12px; user-select: none; }
		label.checkbox-label input[type="checkbox"] { width: 16px; height: 16px; min-height: unset; flex-shrink: 0; accent-color: var(--accent, #4a9eff); cursor: pointer; }
		input:not([type="checkbox"]), select, textarea {
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
			.hero-right { align-items: flex-start; }
			.form-row, .device-meta { grid-template-columns: 1fr; }
			.device-title-line { flex-direction: column; align-items: flex-start; }
			.state-pills { flex-wrap: wrap; justify-content: flex-start; white-space: normal; }
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
		<div class="hero-right">
			<div class="summary-chip"><span id="summaryText">0 devices detected</span></div>
			<div class="queue-chip hidden" id="queueStatusChip" title="No queued backend actions.">
				<span class="queue-chip-label">Queue</span>
				<span id="queueStatusText">Idle</span>
			</div>
		</div>
	</div>

	<div class="panel toolbar">
		<button type="button" class="refresh-button primary" id="scanDevicesButton">Scan Devices</button>
		<button type="button" class="refresh-button" id="refreshButton">Refresh State</button>
		<button type="button" class="refresh-button danger" id="stopAllButton">Stop All</button>
		<button type="button" class="refresh-button" id="toggleTemplateToolbarButton">Templates</button>
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

	<div id="pipeOutputPresetModal" class="modal hidden">
		<div class="modal-content">
			<div class="modal-header">
				<h3 id="pipeOutputPresetModalTitle">New Pipe Output</h3>
				<button type="button" class="modal-close" id="pipeOutputPresetModalClose">&times;</button>
			</div>
			<div class="modal-body">
				<div class="form-row single">
					<div><label>Name</label><input type="text" id="pipeOutputPresetModalName" placeholder="My Pipe Output"></div>
				</div>
				<div class="form-row single">
					<div><label class="checkbox-label"><input type="checkbox" id="pipeOutputPresetModalRawEnabled"> Enable <code>--pipe-output-raw</code></label></div>
				</div>
				<div class="form-row">
					<div><label>Raw Bits (optional)</label><input type="number" min="1" max="32" step="1" id="pipeOutputPresetModalRawBits" placeholder="16"></div>
					<div><label>Raw Channels (optional)</label><input type="number" min="1" max="8" step="1" id="pipeOutputPresetModalRawChannels" placeholder="1"></div>
				</div>
				<div class="form-row">
					<div><label>Raw Rate Hz (optional)</label><input type="number" min="1" step="1" id="pipeOutputPresetModalRawRate" placeholder="22050"></div>
					<div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
						<label class="checkbox-label" style="padding:0;"><input type="checkbox" id="pipeOutputPresetModalRawUnsigned"> Unsigned PCM</label>
						<label class="checkbox-label" style="padding:0;"><input type="checkbox" id="pipeOutputPresetModalRawBigEndian"> Big Endian</label>
					</div>
				</div>
				<div class="form-row">
					<div><label class="checkbox-label"><input type="checkbox" id="pipeOutputPresetModalRawPad"> Enable <code>--pipe-output-pad</code></label></div>
					<div><label>Pad Delay ms (optional)</label><input type="number" min="0" step="1" id="pipeOutputPresetModalRawPadDelay" placeholder="500"></div>
				</div>
				<div class="form-row single">
					<div><label>Commands (one per line)</label><textarea id="pipeOutputPresetModalEntries" rows="6" placeholder="/usr/local/bin/my-consumer --stdin"></textarea></div>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="refresh-button" id="pipeOutputPresetModalCancel">Cancel</button>
				<button type="button" class="refresh-button primary" id="pipeOutputPresetModalSave">Save</button>
				<button type="button" class="refresh-button danger hidden" id="pipeOutputPresetModalDelete">Delete</button>
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

	<div id="templateModal" class="modal hidden">
		<div class="modal-content template-modal-content">
			<div class="modal-header">
				<h3>Templates</h3>
				<button type="button" class="modal-close" id="templateModalClose">&times;</button>
			</div>
			<div class="modal-body">
				<div class="form-row single">
					<div><label>Template</label><select id="globalTemplateSelect" aria-label="Global template selector"></select></div>
				</div>
				<div class="form-row single">
					<div><label>Target Devices</label><select id="templateDeviceSelect" aria-label="Template target devices" multiple size="5"></select></div>
				</div>
				<div class="template-modal-actions">
					<button type="button" class="refresh-button primary" id="startTemplateSelectedButton">Start Template On Selected</button>
					<button type="button" class="refresh-button" id="applyTemplateSelectedButton">Apply Template To Selected</button>
					<button type="button" class="refresh-button danger" id="deleteGlobalTemplateButton">Delete Template</button>
					<button type="button" class="refresh-button" id="exportGlobalTemplateButton">Export Template JSON</button>
					<button type="button" class="refresh-button" id="exportAllGlobalTemplatesButton">Export All Templates JSON</button>
					<button type="button" class="refresh-button" id="importGlobalTemplateButton">Import Template(s) JSON</button>
				</div>
				<input type="file" id="importGlobalTemplateFileInput" accept="application/json,.json" style="display:none;">
			</div>
			<div class="modal-footer">
				<button type="button" class="refresh-button" id="templateModalCancel">Close</button>
			</div>
		</div>
	</div>
</div>

<script type="text/javascript">
var apiUrl = window.location.pathname;
var rmsInputDejitterMs = <?php echo json_encode(get_rms_input_dejitter_ms()); ?>;
var knownInstancesByDevice = {};
var knownDetectedDevices = [];
var deviceConfigsById = {};
var antennaDescriptionsBySerial = {};
var pipeOutputPresetsById = {};
var settingsTemplates = {};
var streamServersById = {};
var recordingServersById = {};
var openConfigPanelsByDevice = {};
var openLogPanelsByDevice = {};
var logContentByDevice = {};
var radioPipeStatusByDevice = {};
var radioPipeStatusProxyInFlightPromise = null;
var radioPipeStatusProxyQueued = false;
var RADIO_PIPE_STATUS_PROXY_POLL_MS = 1500;
var queueStatusState = {
	pending: 0,
	busy: false,
	processed: 0,
	lastProcessedAt: 0,
	lastResult: null
};
var queueStatusHideTimer = null;
var queueStatusLastSignature = '';
var QUEUE_STATUS_VISIBLE_MS = 20000;
var streamPlayersByDevice = {};
var STREAM_LISTEN_RETRY_DELAY_MS = 2000;
var uiSettingsSaveTimer = null;
var uiSettingsSaveInFlight = false;
var uiSettingsSaveQueued = false;
var UI_SETTINGS_SAVE_DELAY_MS = 400;
var pendingDeviceConfigUpsertsById = {};
var pendingDeviceConfigDeletesById = {};
var lastSavedDeviceConfigFingerprintById = {};
var pendingAntennaDescriptionsDirty = false;
var lastSavedAntennaDescriptionsFingerprint = '';
var pendingPipeOutputPresetsDirty = false;
var lastSavedPipeOutputPresetsFingerprint = '';
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

function normalizeClientRmsDbValue(value)
{
	if (value === null || typeof value === 'undefined' || String(value).trim() === '') {
		return null;
	}

	var numeric = Number(value);
	if (!isFinite(numeric)) {
		return null;
	}

	if (numeric < -200 || numeric > 30) {
		return null;
	}

	return numeric;
}

function inferRxOpenReason(snapshot)
{
	var state = (snapshot && typeof snapshot === 'object') ? snapshot : {};
	var gateReason = String(state.gateReason || '').trim().toLowerCase();
	if (gateReason === 'dcs') {
		return 'dcs';
	}
	if (gateReason === 'ctcss') {
		return 'ctcss';
	}
	if (gateReason === 'silence') {
		return 'audio';
	}

	var dcsGateEnabled = parseClientBooleanFlag(state.dcsGateEnabled, false);
	var dcsGateState = String(state.dcsGate || '').trim().toLowerCase();
	var dcsValue = String(state.dcs || '').trim();
	if (dcsGateEnabled && dcsGateState === 'open' && dcsValue !== '') {
		return 'dcs';
	}

	var ctcssGateEnabled = parseClientBooleanFlag(state.ctcssGateEnabled, false);
	var ctcssGateState = String(state.ctcssGate || '').trim().toLowerCase();
	var ctcssValue = String(state.ctcss || '').trim();
	if (ctcssGateEnabled && ctcssGateState === 'open' && ctcssValue !== '') {
		return 'ctcss';
	}

	if (parseClientBooleanFlag(state.audioDetected, false) || String(state.rmsGate || '').trim().toLowerCase() === 'open') {
		return 'audio';
	}

	var previousReason = String(state.lastOpenReason || '').trim().toLowerCase();
	if (previousReason === 'dcs' || previousReason === 'ctcss' || previousReason === 'audio') {
		return previousReason;
	}

	return '';
}

function normalizeRadioPipeStatusSnapshot(payload, previousSnapshot)
{
	var previous = (previousSnapshot && typeof previousSnapshot === 'object') ? previousSnapshot : {};
	var previousAudioReason = Array.isArray(previous.audioReason) ? previous.audioReason : [];
	var rawAudioReason = Array.isArray(payload.audioReason) ? payload.audioReason : previousAudioReason;
	var normalizedAudioReason = [];
	for (var reasonIndex = 0; reasonIndex < rawAudioReason.length; reasonIndex++) {
		var reasonValue = String(rawAudioReason[reasonIndex] == null ? '' : rawAudioReason[reasonIndex]).trim().toLowerCase();
		if (reasonValue === '') {
			continue;
		}
		if (normalizedAudioReason.indexOf(reasonValue) === -1) {
			normalizedAudioReason.push(reasonValue);
		}
	}
	var payloadHasOutputDb = Object.prototype.hasOwnProperty.call(payload, 'outputDb');
	var payloadHasRmsDb = Object.prototype.hasOwnProperty.call(payload, 'rmsDb');
	var payloadHasRms = Object.prototype.hasOwnProperty.call(payload, 'rms');
	var rawOutputDb = payloadHasOutputDb
		? payload.outputDb
		: (payloadHasRmsDb
			? payload.rmsDb
			: (payloadHasRms
				? payload.rms
				: (typeof previous.outputDb !== 'undefined' ? previous.outputDb : previous.rmsDb)));
	var rawRmsDb = payloadHasRmsDb
		? payload.rmsDb
		: (payloadHasRms ? payload.rms : previous.rmsDb);
	var snapshot = {
		hasStatus: true,
		gate: String(payload.gate || previous.gate || '').trim().toLowerCase(),
		gateReason: String(payload.gateReason || previous.gateReason || '').trim().toLowerCase(),
		dcsGateEnabled: parseClientBooleanFlag(payload.dcsGateEnabled, parseClientBooleanFlag(previous.dcsGateEnabled, false)),
		dcsGate: String(payload.dcsGate || previous.dcsGate || '').trim().toLowerCase(),
		dcs: String(payload.dcs == null ? (previous.dcs || '') : payload.dcs).trim(),
		ctcssGateEnabled: parseClientBooleanFlag(payload.ctcssGateEnabled, parseClientBooleanFlag(previous.ctcssGateEnabled, false)),
		ctcssGate: String(payload.ctcssGate || previous.ctcssGate || '').trim().toLowerCase(),
		ctcss: String(payload.ctcss == null ? (previous.ctcss || '') : payload.ctcss).trim(),
		rmsDb: normalizeClientRmsDbValue(rawRmsDb),
		outputDb: normalizeClientRmsDbValue(rawOutputDb),
		rmsGate: String(payload.rmsGate || previous.rmsGate || '').trim().toLowerCase(),
		audioReason: normalizedAudioReason,
		audioDetected: parseClientBooleanFlag(payload.audioDetected, parseClientBooleanFlag(previous.audioDetected, false)),
		lastOpenReason: String(previous.lastOpenReason || '').trim().toLowerCase(),
		updatedAt: Date.now()
	};

	if (snapshot.gate === 'open') {
		var openReason = inferRxOpenReason(snapshot);
		if (openReason !== '') {
			snapshot.lastOpenReason = openReason;
		}
	}

	return snapshot;
}

function findDeviceCardById(deviceId)
{
	var normalizedDeviceId = normalizeClientDeviceId(String(deviceId || '').trim());
	if (normalizedDeviceId === '') {
		return null;
	}

	var cards = document.querySelectorAll('#deviceList .device-card');
	for (var i = 0; i < cards.length; i++) {
		var cardDeviceId = normalizeClientDeviceId(String(cards[i].getAttribute('data-device-id') || '').trim());
		if (cardDeviceId === normalizedDeviceId) {
			return cards[i];
		}
	}

	return null;
}

function applyRxPillIndicator(deviceId)
{
	var normalizedDeviceId = normalizeClientDeviceId(String(deviceId || '').trim());
	if (normalizedDeviceId === '') {
		return;
	}

	var card = findDeviceCardById(normalizedDeviceId);
	if (!card) {
		return;
	}

	var pill = card.querySelector('.state-pill-rx');

	var isRunning = !!knownInstancesByDevice[normalizedDeviceId];
	if (pill) {
		var indicator = getRxIndicator(normalizedDeviceId, isRunning, null);
		pill.textContent = indicator.label;
		pill.classList.remove('rx-active');
		pill.classList.remove('rx-idle');
		pill.classList.remove('rx-off');
		pill.classList.add(indicator.className);
	}

	applyRmsBarIndicator(normalizedDeviceId, isRunning);
}

function applyRmsBarIndicator(deviceId, runningOverride)
{
	var normalizedDeviceId = normalizeClientDeviceId(String(deviceId || '').trim());
	if (normalizedDeviceId === '') {
		return;
	}

	var card = findDeviceCardById(normalizedDeviceId);
	if (!card) {
		return;
	}

	var isRunning = typeof runningOverride === 'boolean'
		? runningOverride
		: !!knownInstancesByDevice[normalizedDeviceId];
	var rmsDbIndicator = getRmsDbIndicator(normalizedDeviceId, isRunning);
	var outputIndicator = getRmsIndicator(normalizedDeviceId, isRunning);

	var updateMeter = function (meterSelector, indicator) {
		var meter = card.querySelector(meterSelector);
		if (!meter) {
			return;
		}

		var fill = meter.querySelector('.device-rms-fill');
		var label = meter.querySelector('.device-rms-label');
		if (!fill || !label) {
			return;
		}

		var nextPercent = isFinite(indicator.percent) ? Math.max(0, Math.min(100, indicator.percent)) : 0;
		var lastPercent = Number(fill.getAttribute('data-last-percent'));
		if (!isFinite(lastPercent)) {
			var currentWidth = parseFloat(String(fill.style.width || '0'));
			lastPercent = isFinite(currentWidth) ? currentWidth : nextPercent;
		}

		var isDecay = nextPercent < (lastPercent - 0.05);
		var isOutputMeter = meterSelector === '.device-rms-meter-output';
		var decayDurationMs = isOutputMeter ? 120 : 170;
		fill.style.transitionDuration = isDecay ? String(decayDurationMs) + 'ms' : '180ms';
		fill.style.transitionTimingFunction = isDecay ? 'linear' : 'ease-out';

		label.textContent = indicator.label;
		fill.style.width = String(nextPercent.toFixed(1)) + '%';
		fill.setAttribute('data-last-percent', String(nextPercent.toFixed(3)));
		meter.classList.remove('rms-off');
		meter.classList.remove('rms-idle');
		meter.classList.remove('rms-low');
		meter.classList.remove('rms-mid');
		meter.classList.remove('rms-high');
		meter.classList.add(indicator.className);
		meter.setAttribute('aria-label', indicator.label);
	};

	updateMeter('.device-rms-meter-rms', rmsDbIndicator);
	updateMeter('.device-rms-meter-output', outputIndicator);
}

function pollRadioPipeStatusViaProxy()
{
	if (radioPipeStatusProxyInFlightPromise) {
		radioPipeStatusProxyQueued = true;
		return radioPipeStatusProxyInFlightPromise;
	}

	var runningDeviceIds = [];
	var runningDeviceMap = {};
	for (var deviceKey in knownInstancesByDevice) {
		if (!Object.prototype.hasOwnProperty.call(knownInstancesByDevice, deviceKey)) {
			continue;
		}

		var normalizedDeviceId = normalizeClientDeviceId(String(deviceKey || '').trim());
		if (normalizedDeviceId === '' || runningDeviceMap[normalizedDeviceId]) {
			continue;
		}

		runningDeviceMap[normalizedDeviceId] = true;
		runningDeviceIds.push(normalizedDeviceId);
	}

	if (!runningDeviceIds.length) {
		for (var staleDeviceId in radioPipeStatusByDevice) {
			if (!Object.prototype.hasOwnProperty.call(radioPipeStatusByDevice, staleDeviceId)) {
				continue;
			}
			delete radioPipeStatusByDevice[staleDeviceId];
			applyRxPillIndicator(staleDeviceId);
		}
		return Promise.resolve();
	}

	radioPipeStatusProxyInFlightPromise = postAction(
		'radio_pipe_status_batch',
		{ devices: runningDeviceIds },
		{ timeoutMs: Math.max(1200, Math.min(4000, REFRESH_REQUEST_TIMEOUT_MS)) }
	).then(function (result) {
		var statusesByDevice = (result && result.statuses && typeof result.statuses === 'object' && !Array.isArray(result.statuses))
			? result.statuses
			: {};

		for (var i = 0; i < runningDeviceIds.length; i++) {
			var runningDeviceId = runningDeviceIds[i];
			var statusEntry = statusesByDevice[runningDeviceId];
			var statusPayload = statusEntry && statusEntry.status && typeof statusEntry.status === 'object' && !Array.isArray(statusEntry.status)
				? statusEntry.status
				: null;

			if (statusPayload) {
				var previousSnapshot = radioPipeStatusByDevice[runningDeviceId] || null;
				radioPipeStatusByDevice[runningDeviceId] = normalizeRadioPipeStatusSnapshot(statusPayload, previousSnapshot);
			} else {
				delete radioPipeStatusByDevice[runningDeviceId];
			}

			applyRxPillIndicator(runningDeviceId);
		}

		for (var trackedStatusDeviceId in radioPipeStatusByDevice) {
			if (!Object.prototype.hasOwnProperty.call(radioPipeStatusByDevice, trackedStatusDeviceId)) {
				continue;
			}
			if (!runningDeviceMap[trackedStatusDeviceId]) {
				delete radioPipeStatusByDevice[trackedStatusDeviceId];
				applyRxPillIndicator(trackedStatusDeviceId);
			}
		}
		return null;
	}).catch(function () {
		return null;
	}).finally(function () {
		radioPipeStatusProxyInFlightPromise = null;
		if (radioPipeStatusProxyQueued) {
			radioPipeStatusProxyQueued = false;
			window.setTimeout(function () {
				pollRadioPipeStatusViaProxy();
			}, 0);
		}
	});

	return radioPipeStatusProxyInFlightPromise;
}

function syncRadioPipeSockets()
{
	pollRadioPipeStatusViaProxy();
}

function normalizeQueueStatusPayload(rawQueue)
{
	var queue = (rawQueue && typeof rawQueue === 'object' && !Array.isArray(rawQueue))
		? rawQueue
		: {};

	var pending = Number(queue.pending);
	if (!isFinite(pending) || pending < 0) {
		pending = 0;
	}
	pending = Math.floor(pending);

	var processed = Number(queue.processed);
	if (!isFinite(processed) || processed < 0) {
		processed = 0;
	}
	processed = Math.floor(processed);

	var lastProcessedAt = Number(queue.lastProcessedAt);
	if (!isFinite(lastProcessedAt) || lastProcessedAt < 0) {
		lastProcessedAt = 0;
	}
	lastProcessedAt = Math.floor(lastProcessedAt);

	var rawLastResult = (queue.lastResult && typeof queue.lastResult === 'object' && !Array.isArray(queue.lastResult))
		? queue.lastResult
		: null;
	var lastResult = null;
	if (rawLastResult) {
		var lastAction = String(rawLastResult.action || '').trim().toLowerCase();
		var lastDevice = normalizeClientDeviceId(String(rawLastResult.device || '').trim());
		var lastMessage = String(rawLastResult.message || '').trim();
		var lastOk = !!rawLastResult.ok;
		var lastAt = Number(rawLastResult.processedAt);
		if (!isFinite(lastAt) || lastAt < 0) {
			lastAt = 0;
		}
		lastAt = Math.floor(lastAt);

		if (lastAction !== '' || lastMessage !== '' || lastAt > 0 || lastDevice !== '') {
			lastResult = {
				action: lastAction,
				device: lastDevice,
				message: lastMessage,
				ok: lastOk,
				processedAt: lastAt
			};
		}
	}

	return {
		pending: pending,
		busy: !!queue.busy,
		processed: processed,
		lastProcessedAt: lastProcessedAt,
		lastResult: lastResult
	};
}

function buildQueueStatusSignature(queue)
{
	var normalizedQueue = normalizeQueueStatusPayload(queue);
	var lastResult = normalizedQueue.lastResult || {};

	return [
		String(normalizedQueue.pending || 0),
		normalizedQueue.busy ? '1' : '0',
		String(normalizedQueue.processed || 0),
		String(normalizedQueue.lastProcessedAt || 0),
		String(lastResult.action || ''),
		String(lastResult.device || ''),
		lastResult.ok ? '1' : '0',
		String(lastResult.message || ''),
		String(lastResult.processedAt || 0)
	].join('|');
}

function showQueueStatusForDuration(durationMs)
{
	var chip = document.getElementById('queueStatusChip');
	if (!chip) {
		return;
	}

	chip.classList.remove('hidden');

	if (queueStatusHideTimer !== null) {
		window.clearTimeout(queueStatusHideTimer);
		queueStatusHideTimer = null;
	}

	var visibleMs = Number(durationMs);
	if (!isFinite(visibleMs) || visibleMs <= 0) {
		visibleMs = QUEUE_STATUS_VISIBLE_MS;
	}

	queueStatusHideTimer = window.setTimeout(function () {
		var chipEl = document.getElementById('queueStatusChip');
		if (chipEl) {
			chipEl.classList.add('hidden');
		}
		queueStatusHideTimer = null;
	}, visibleMs);
}

function renderQueueStatus()
{
	var chip = document.getElementById('queueStatusChip');
	var text = document.getElementById('queueStatusText');
	if (!chip || !text) {
		return;
	}

	chip.classList.remove('pending');
	chip.classList.remove('success');
	chip.classList.remove('error');
	chip.classList.remove('busy');

	var queue = queueStatusState || {};
	var message = 'Idle';
	var title = 'No queued backend actions.';

	if ((queue.pending || 0) > 0) {
		message = String(queue.pending) + ' pending';
		title = 'Queued retune actions are waiting for watchdog processing.';
		chip.classList.add('pending');
	} else if (queue.busy) {
		message = 'Worker busy';
		title = 'Queue worker is currently processing actions.';
		chip.classList.add('busy');
	} else if (queue.lastResult) {
		var actionLabel = String(queue.lastResult.action || 'action').toUpperCase();
		var deviceSuffix = queue.lastResult.device ? (' (' + queue.lastResult.device + ')') : '';
		if (queue.lastResult.ok) {
			message = 'Last ' + actionLabel + ' OK' + deviceSuffix;
			title = queue.lastResult.message || 'Last queued action completed successfully.';
			chip.classList.add('success');
		} else {
			message = 'Last ' + actionLabel + ' failed' + deviceSuffix;
			title = queue.lastResult.message || 'Last queued action failed.';
			chip.classList.add('error');
		}
	}

	text.textContent = message;
	chip.title = title;
}

function updateQueueStatusFromServer(queuePayload)
{
	if (queueStatusLastSignature === '') {
		queueStatusLastSignature = buildQueueStatusSignature(queueStatusState);
	}

	var nextState = normalizeQueueStatusPayload(queuePayload);
	var nextSignature = buildQueueStatusSignature(nextState);
	var hasChanged = nextSignature !== queueStatusLastSignature;

	queueStatusState = nextState;
	queueStatusLastSignature = nextSignature;
	renderQueueStatus();

	if (hasChanged) {
		showQueueStatusForDuration(QUEUE_STATUS_VISIBLE_MS);
	}
}

function openTemplateDialog()
{
	var modal = document.getElementById('templateModal');
	if (!modal) {
		return;
	}
	refreshGlobalTemplateSelector();
	refreshTemplateDeviceSelector();
	modal.classList.remove('hidden');
}

function closeTemplateDialog()
{
	var modal = document.getElementById('templateModal');
	if (!modal) {
		return;
	}
	modal.classList.add('hidden');
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
		antennaDescriptionsBySerial: {},
		pipeOutputPresets: {}
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

	var rawPresets = (rawSettings.pipeOutputPresets && typeof rawSettings.pipeOutputPresets === 'object' && !Array.isArray(rawSettings.pipeOutputPresets))
		? rawSettings.pipeOutputPresets
		: {};
	for (var rawPresetId in rawPresets) {
		if (!Object.prototype.hasOwnProperty.call(rawPresets, rawPresetId)) {
			continue;
		}

		var normalizedPresetId = String(rawPresetId == null ? '' : rawPresetId).trim();
		if (normalizedPresetId === '') {
			continue;
		}

		var preset = rawPresets[rawPresetId];
		if (!preset || typeof preset !== 'object' || Array.isArray(preset)) {
			continue;
		}

		// Key is the name in new format; fall back to preset.name for old numeric-key migration
		var presetName = String(preset.name || normalizedPresetId).trim();
		if (presetName === '') {
			continue;
		}

		normalized.pipeOutputPresets[presetName] = {
			pipeOutputs: normalizeClientPipeOutputEntries(preset.pipeOutputs),
			raw: normalizeClientPipeOutputRawSettings(preset.raw)
		};
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
	var nextPipeOutputPresets = normalizedSettings.pipeOutputPresets;

	var currentDeviceFingerprint = computeUiSettingsFingerprint(deviceConfigsById);
	var nextDeviceFingerprint = computeUiSettingsFingerprint(nextDeviceConfigs);
	var currentAntennaFingerprint = computeUiSettingsFingerprint(antennaDescriptionsBySerial);
	var nextAntennaFingerprint = computeUiSettingsFingerprint(nextAntennaDescriptions);
 	var currentPipeOutputPresetsFingerprint = computeUiSettingsFingerprint(pipeOutputPresetsById);
	var nextPipeOutputPresetsFingerprint = computeUiSettingsFingerprint(nextPipeOutputPresets);

	if (
		currentDeviceFingerprint === nextDeviceFingerprint
		&& currentAntennaFingerprint === nextAntennaFingerprint
		&& currentPipeOutputPresetsFingerprint === nextPipeOutputPresetsFingerprint
	) {
		return false;
	}

	deviceConfigsById = nextDeviceConfigs;
	antennaDescriptionsBySerial = nextAntennaDescriptions;
	pipeOutputPresetsById = nextPipeOutputPresets;
	pendingDeviceConfigUpsertsById = {};
	pendingDeviceConfigDeletesById = {};
	pendingAntennaDescriptionsDirty = false;
	pendingPipeOutputPresetsDirty = false;
	rebuildLastSavedDeviceConfigFingerprints(deviceConfigsById);
	lastSavedAntennaDescriptionsFingerprint = nextAntennaFingerprint;
	lastSavedPipeOutputPresetsFingerprint = nextPipeOutputPresetsFingerprint;
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

		if (responseSettings && responseSettings.pipeOutputPresets && typeof responseSettings.pipeOutputPresets === 'object' && !Array.isArray(responseSettings.pipeOutputPresets)) {
			pipeOutputPresetsById = normalizeUiSettingsFromServer({ pipeOutputPresets: responseSettings.pipeOutputPresets }).pipeOutputPresets;
		}
		lastSavedAntennaDescriptionsFingerprint = computeUiSettingsFingerprint(antennaDescriptionsBySerial);
		pendingAntennaDescriptionsDirty = false;
		pendingPipeOutputPresetsDirty = false;
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
		if (settings.pipeOutputPresets && typeof settings.pipeOutputPresets === 'object' && !Array.isArray(settings.pipeOutputPresets)) {
			pipeOutputPresetsById = normalizeUiSettingsFromServer({ pipeOutputPresets: settings.pipeOutputPresets }).pipeOutputPresets;
		} else {
			pipeOutputPresetsById = {};
		}
		pendingDeviceConfigUpsertsById = {};
		pendingDeviceConfigDeletesById = {};
		pendingAntennaDescriptionsDirty = false;
		pendingPipeOutputPresetsDirty = false;
		rebuildLastSavedDeviceConfigFingerprints(deviceConfigsById);
		lastSavedAntennaDescriptionsFingerprint = computeUiSettingsFingerprint(antennaDescriptionsBySerial);
		lastSavedPipeOutputPresetsFingerprint = computeUiSettingsFingerprint(pipeOutputPresetsById);
	}).catch(function () {
		deviceConfigsById = {};
		antennaDescriptionsBySerial = {};
		pipeOutputPresetsById = {};
		pendingDeviceConfigUpsertsById = {};
		pendingDeviceConfigDeletesById = {};
		pendingAntennaDescriptionsDirty = false;
		pendingPipeOutputPresetsDirty = false;
		rebuildLastSavedDeviceConfigFingerprints(deviceConfigsById);
		lastSavedAntennaDescriptionsFingerprint = computeUiSettingsFingerprint(antennaDescriptionsBySerial);
		lastSavedPipeOutputPresetsFingerprint = computeUiSettingsFingerprint(pipeOutputPresetsById);
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
var currentEditingPipeOutputPresetId = null;
var currentEditingAntennaDeviceId = '';
var currentEditingAntennaSerial = '';

function normalizePipeOutputPresetIdList(value)
{
	var ids = [];
	if (Array.isArray(value)) {
		for (var i = 0; i < value.length; i++) {
			var id = String(value[i] == null ? '' : value[i]).trim();
			if (id === '' || ids.indexOf(id) !== -1) {
				continue;
			}
			ids.push(id);
		}
		return ids;
	}

	var single = String(value == null ? '' : value).trim();
	if (single !== '') {
		ids.push(single);
	}

	return ids;
}

function buildPipeOutputPresetCheckboxesMarkup(selectedPresetIds)
{
	var selected = {};
	var normalizedSelected = normalizePipeOutputPresetIdList(selectedPresetIds);
	for (var i = 0; i < normalizedSelected.length; i++) {
		selected[normalizedSelected[i]] = true;
	}

	var ids = Object.keys(pipeOutputPresetsById).sort(function (a, b) {
		var aName = String((pipeOutputPresetsById[a] && pipeOutputPresetsById[a].name) || a).toLowerCase();
		var bName = String((pipeOutputPresetsById[b] && pipeOutputPresetsById[b].name) || b).toLowerCase();
		if (aName < bName) {
			return -1;
		}
		if (aName > bName) {
			return 1;
		}
		return String(a).localeCompare(String(b));
	});

	if (!ids.length) {
		return '<div class="small">No pipe outputs yet. Click New Pipe-Output to create one.</div>';
	}

	var html = '';
	for (var index = 0; index < ids.length; index++) {
		var id = ids[index];
		var preset = pipeOutputPresetsById[id] || {};
		html += '<div class="pipe-output-entry-row">';
		html += '<label class="checkbox-label"><input type="checkbox" class="field-pipe-output-preset-toggle" value="' + escapeHtml(String(id)) + '"' + (selected[id] ? ' checked' : '') + '> ' + escapeHtml(String(preset.name || id)) + '</label>';
		html += '<button type="button" class="refresh-button compact action-edit-pipe-output-preset" data-preset-id="' + escapeHtml(String(id)) + '" title="Edit">&#9998;</button>';
		html += '</div>';
	}

	return html;
}

function readPipeOutputPresetIdsFromCard(card)
{
	var selected = [];
	if (!card) {
		return selected;
	}

	var toggles = card.querySelectorAll('.field-pipe-output-preset-toggle');
	for (var i = 0; i < toggles.length; i++) {
		if (!toggles[i].checked) {
			continue;
		}
		var id = String(toggles[i].value || '').trim();
		if (id === '' || selected.indexOf(id) !== -1) {
			continue;
		}
		selected.push(id);
	}

	return selected;
}

function resolvePipeOutputsForConfig(source, explicitPresetIds)
{
	var entries = [];
	var directEntries = normalizeClientPipeOutputEntries(source && source.pipeOutputs);
	for (var i = 0; i < directEntries.length; i++) {
		if (entries.indexOf(directEntries[i]) === -1) {
			entries.push(directEntries[i]);
		}
	}

	var presetIds = Array.isArray(explicitPresetIds)
		? normalizePipeOutputPresetIdList(explicitPresetIds)
		: normalizePipeOutputPresetIdList(source && source.pipeOutputPresetIds);
	for (var j = 0; j < presetIds.length; j++) {
		var preset = getPipeOutputPresetById(presetIds[j]);
		if (!preset) {
			continue;
		}
		var presetEntries = normalizeClientPipeOutputEntries(preset.pipeOutputs);
		for (var k = 0; k < presetEntries.length; k++) {
			if (entries.indexOf(presetEntries[k]) === -1) {
				entries.push(presetEntries[k]);
			}
		}
	}

	return entries;
}

function resolvePipeOutputRuntimeConfig(source, explicitPresetIds)
{
	var entries = resolvePipeOutputsForConfig(source, explicitPresetIds);
	var raw = normalizeClientPipeOutputRawSettings({});
	var rawChosen = false;
	var presetIds = Array.isArray(explicitPresetIds)
		? normalizePipeOutputPresetIdList(explicitPresetIds)
		: normalizePipeOutputPresetIdList(source && source.pipeOutputPresetIds);

	for (var i = 0; i < presetIds.length; i++) {
		var preset = getPipeOutputPresetById(presetIds[i]);
		if (!preset) {
			continue;
		}
		var candidate = normalizeClientPipeOutputRawSettings(preset.raw);
		if (candidate.enabled && !rawChosen) {
			raw = candidate;
			rawChosen = true;
		}
	}

	return {
		entries: entries,
		raw: raw
	};
}

function getPipeOutputPresetById(presetId)
{
	return pipeOutputPresetsById[presetId] || null;
}

function refreshPipeOutputPresetSelects()
{
	var containers = document.querySelectorAll('.pipe-output-presets-list');
	for (var i = 0; i < containers.length; i++) {
		var card = containers[i].closest('.device-card');
		var selectedIds = readPipeOutputPresetIdsFromCard(card);
		if (!selectedIds.length && card) {
			var cardConfig = getConfigForDevice(String(card.getAttribute('data-device-id') || ''));
			selectedIds = normalizePipeOutputPresetIdList(cardConfig.pipeOutputPresetIds);
		}
		containers[i].innerHTML = buildPipeOutputPresetCheckboxesMarkup(selectedIds);
	}
}

function savePipeOutputsToServer()
{
	var presets = {};
	for (var presetId in pipeOutputPresetsById) {
		if (!Object.prototype.hasOwnProperty.call(pipeOutputPresetsById, presetId)) {
			continue;
		}
		var preset = pipeOutputPresetsById[presetId];
		if (!preset || typeof preset !== 'object' || Array.isArray(preset)) {
			continue;
		}
		var presetName = String(presetId).trim();
		if (presetName === '') {
			continue;
		}
		presets[presetName] = {
			pipeOutputs: normalizeClientPipeOutputEntries(preset.pipeOutputs),
			raw: normalizeClientPipeOutputRawSettings(preset.raw)
		};
	}

	return postUserAction('pipe_outputs_set', { presets: presets }).then(function (result) {
		if (result && result.presets && typeof result.presets === 'object' && !Array.isArray(result.presets)) {
			pipeOutputPresetsById = normalizeUiSettingsFromServer({ pipeOutputPresets: result.presets }).pipeOutputPresets;
		}
		lastSavedPipeOutputPresetsFingerprint = computeUiSettingsFingerprint(pipeOutputPresetsById);
		pendingPipeOutputPresetsDirty = false;
	}).catch(function (error) {
		setStatus('Failed to save pipe outputs: ' + (error.message || 'Unknown error'), true);
		pendingPipeOutputPresetsDirty = true;
	});
}

function openPipeOutputPresetDialog(presetId)
{
	currentEditingPipeOutputPresetId = presetId;
	var modal = document.getElementById('pipeOutputPresetModal');
	var titleEl = document.getElementById('pipeOutputPresetModalTitle');
	var nameEl = document.getElementById('pipeOutputPresetModalName');
	var entriesEl = document.getElementById('pipeOutputPresetModalEntries');
	var rawEnabledEl = document.getElementById('pipeOutputPresetModalRawEnabled');
	var rawBitsEl = document.getElementById('pipeOutputPresetModalRawBits');
	var rawChannelsEl = document.getElementById('pipeOutputPresetModalRawChannels');
	var rawRateEl = document.getElementById('pipeOutputPresetModalRawRate');
	var rawUnsignedEl = document.getElementById('pipeOutputPresetModalRawUnsigned');
	var rawBigEndianEl = document.getElementById('pipeOutputPresetModalRawBigEndian');
	var rawPadEl = document.getElementById('pipeOutputPresetModalRawPad');
	var rawPadDelayEl = document.getElementById('pipeOutputPresetModalRawPadDelay');
	var deleteBtn = document.getElementById('pipeOutputPresetModalDelete');

	if (presetId) {
		var preset = getPipeOutputPresetById(presetId);
		if (!preset) {
			return;
		}

		titleEl.textContent = 'Edit Pipe Output';
		nameEl.value = String(presetId);
		entriesEl.value = normalizeClientPipeOutputEntries(preset.pipeOutputs).join('\n');
		var raw = normalizeClientPipeOutputRawSettings(preset.raw);
		rawEnabledEl.checked = raw.enabled;
		rawBitsEl.value = raw.bits;
		rawChannelsEl.value = raw.channels;
		rawRateEl.value = raw.rate;
		rawUnsignedEl.checked = raw.unsigned;
		rawBigEndianEl.checked = raw.bigEndian;
		rawPadEl.checked = raw.pad;
		rawPadDelayEl.value = raw.padDelay;
		deleteBtn.classList.remove('hidden');
	} else {
		titleEl.textContent = 'New Pipe Output';
		nameEl.value = '';
		entriesEl.value = '';
		rawEnabledEl.checked = false;
		rawBitsEl.value = '';
		rawChannelsEl.value = '';
		rawRateEl.value = '';
		rawUnsignedEl.checked = false;
		rawBigEndianEl.checked = false;
		rawPadEl.checked = true;
		rawPadDelayEl.value = '';
		deleteBtn.classList.add('hidden');
	}

	modal.classList.remove('hidden');
	nameEl.focus();
}

function closePipeOutputPresetDialog()
{
	var modal = document.getElementById('pipeOutputPresetModal');
	modal.classList.add('hidden');
	currentEditingPipeOutputPresetId = null;
}

function savePipeOutputPreset()
{
	var nameEl = document.getElementById('pipeOutputPresetModalName');
	var entriesEl = document.getElementById('pipeOutputPresetModalEntries');
	var rawEnabledEl = document.getElementById('pipeOutputPresetModalRawEnabled');
	var rawBitsEl = document.getElementById('pipeOutputPresetModalRawBits');
	var rawChannelsEl = document.getElementById('pipeOutputPresetModalRawChannels');
	var rawRateEl = document.getElementById('pipeOutputPresetModalRawRate');
	var rawUnsignedEl = document.getElementById('pipeOutputPresetModalRawUnsigned');
	var rawBigEndianEl = document.getElementById('pipeOutputPresetModalRawBigEndian');
	var rawPadEl = document.getElementById('pipeOutputPresetModalRawPad');
	var rawPadDelayEl = document.getElementById('pipeOutputPresetModalRawPadDelay');
	var name = String(nameEl.value || '').trim();
	var entries = normalizeClientPipeOutputEntries(String(entriesEl.value || ''));
	var rawSettings = normalizeClientPipeOutputRawSettings({
		enabled: !!(rawEnabledEl && rawEnabledEl.checked),
		bits: rawBitsEl ? rawBitsEl.value : '',
		channels: rawChannelsEl ? rawChannelsEl.value : '',
		rate: rawRateEl ? rawRateEl.value : '',
		unsigned: !!(rawUnsignedEl && rawUnsignedEl.checked),
		bigEndian: !!(rawBigEndianEl && rawBigEndianEl.checked),
		pad: !!(rawPadEl && rawPadEl.checked),
		padDelay: rawPadDelayEl ? rawPadDelayEl.value : ''
	});

	if (name === '') {
		setStatus('Preset name is required.', true);
		return;
	}

	if (!entries.length) {
		setStatus('Add at least one pipe-output command.', true);
		return;
	}

	var editingId = currentEditingPipeOutputPresetId;
	if (!editingId && pipeOutputPresetsById[name]) {
		setStatus('A pipe output named "' + name + '" already exists.', true);
		return;
	}
	if (editingId && editingId !== name) {
		delete pipeOutputPresetsById[editingId];
		for (var dId in deviceConfigsById) {
			if (!Object.prototype.hasOwnProperty.call(deviceConfigsById, dId)) {
				continue;
			}
			var dc = deviceConfigsById[dId];
			if (!dc || !Array.isArray(dc.pipeOutputPresetIds)) {
				continue;
			}
			var idx = dc.pipeOutputPresetIds.indexOf(editingId);
			if (idx !== -1) {
				dc.pipeOutputPresetIds[idx] = name;
				queueDeviceConfigPersistence(dId, {});
			}
		}
	}
	pipeOutputPresetsById[name] = {
		pipeOutputs: entries,
		raw: rawSettings
	};
	pendingPipeOutputPresetsDirty = true;
	savePipeOutputsToServer();
	closePipeOutputPresetDialog();
	refreshPipeOutputPresetSelects();
	renderDeviceList();
	setStatus('Pipe output saved.', false);
}

function deletePipeOutputPreset()
{
	if (!currentEditingPipeOutputPresetId) {
		return;
	}

	if (!window.confirm('Delete this pipe output preset?')) {
		return;
	}

	delete pipeOutputPresetsById[currentEditingPipeOutputPresetId];
	pendingPipeOutputPresetsDirty = true;
	savePipeOutputsToServer();
	closePipeOutputPresetDialog();
	refreshPipeOutputPresetSelects();
	renderDeviceList();
	setStatus('Pipe output deleted.', false);
}

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

function normalizeMountNameSegment(streamName)
{
	var raw = String(streamName == null ? '' : streamName).trim().toLowerCase();
	if (raw === '') {
		return 'rtl-sdr';
	}
	if (typeof raw.normalize === 'function') {
		raw = raw.normalize('NFKD').replace(/[\u0300-\u036f]/g, '');
	}
	raw = raw.replace(/[^a-z0-9._-]+/g, '-').replace(/-+/g, '-').replace(/^[-._]+|[-._]+$/g, '');
	return raw === '' ? 'rtl-sdr' : raw;
}

function getDefaultMountForName(streamName, format)
{
	var ext = String(format).toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	return '/' + normalizeMountNameSegment(streamName) + '.' + ext;
}

function getDefaultMountForDeviceIndex(deviceIndex, format)
{
	var ext = String(format).toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	var normalizedIndex = normalizeClientDeviceIndex(deviceIndex);
	if (normalizedIndex === '') {
		normalizedIndex = '0';
	}

	return '/dev' + normalizedIndex + '.' + ext;
}

function inferMountFollowsStreamName(mount, streamName, format)
{
	var normalized = String(mount == null ? '' : mount).trim();
	if (normalized === '') {
		return true;
	}
	if (normalized.charAt(0) !== '/') {
		normalized = '/' + normalized;
	}
	var ext = String(format).toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	var legacyDefault = '/rtl-sdr.' + ext;
	var byNameDefault = getDefaultMountForName(streamName, ext);
	var lowered = normalized.toLowerCase();
	return lowered === legacyDefault.toLowerCase() || lowered === byNameDefault.toLowerCase();
}

function inferMountFollowsDeviceIndex(mount, deviceIndex, format)
{
	var normalized = String(mount == null ? '' : mount).trim();
	if (normalized === '') {
		return false;
	}
	if (normalized.charAt(0) !== '/') {
		normalized = '/' + normalized;
	}
	var ext = String(format).toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	var byDeviceDefault = getDefaultMountForDeviceIndex(deviceIndex, ext);
	return normalized.toLowerCase() === byDeviceDefault.toLowerCase();
}

function normalizeMountLinkMode(value)
{
	var mode = String(value == null ? '' : value).trim().toLowerCase();
	if (mode === 'device' || mode === 'dev' || mode === 'link-device' || mode === 'device-index' || mode === 'index') {
		return 'device';
	}
	if (mode === 'name' || mode === 'stream' || mode === 'stream-name' || mode === 'linked' || mode === 'auto') {
		return 'name';
	}
	if (mode === 'manual' || mode === 'none' || mode === 'off' || mode === 'custom') {
		return 'manual';
	}
	return '';
}

function resolveMountLinkModeForConfig(config, deviceIndex)
{
	var source = (config && typeof config === 'object') ? config : {};
	var streamName = String(source.streamName || '');
	var streamFormat = String(source.streamFormat || 'mp3').toLowerCase() === 'ogg' ? 'ogg' : 'mp3';

	var explicitMode = normalizeMountLinkMode(source.streamMountLinkMode);
	if (explicitMode !== '') {
		return explicitMode;
	}

	var inferredFollowDevice = inferMountFollowsDeviceIndex(source.streamMount, deviceIndex, streamFormat);
	var followDevice = Object.prototype.hasOwnProperty.call(source, 'streamMountFollowDevice')
		? parseClientBooleanFlag(source.streamMountFollowDevice, inferredFollowDevice)
		: inferredFollowDevice;
	if (followDevice) {
		return 'device';
	}

	var inferredFollowName = inferMountFollowsStreamName(source.streamMount, streamName, streamFormat);
	var followName = Object.prototype.hasOwnProperty.call(source, 'streamMountFollowName')
		? parseClientBooleanFlag(source.streamMountFollowName, inferredFollowName)
		: inferredFollowName;

	return followName ? 'name' : 'manual';
}

function normalizeMountByFormat(mount, streamName, format, followName, followDevice, deviceIndex)
{
	var ext = String(format).toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	var normalizedDeviceIndex = normalizeClientDeviceIndex(deviceIndex);
	var shouldFollowDevice = followDevice;
	if (shouldFollowDevice === undefined || shouldFollowDevice === null) {
		shouldFollowDevice = inferMountFollowsDeviceIndex(mount, normalizedDeviceIndex, ext);
	} else {
		shouldFollowDevice = parseClientBooleanFlag(shouldFollowDevice, false);
	}

	var shouldFollow = followName;
	if (shouldFollow === undefined || shouldFollow === null) {
		shouldFollow = inferMountFollowsStreamName(mount, streamName, ext);
	} else {
		shouldFollow = parseClientBooleanFlag(shouldFollow, true);
	}

	if (shouldFollowDevice) {
		shouldFollow = false;
	}

	var preferredByName = getDefaultMountForName(streamName, ext);
	var preferredByDevice = getDefaultMountForDeviceIndex(normalizedDeviceIndex, ext);
	var preferred = shouldFollowDevice ? preferredByDevice : preferredByName;
	if (shouldFollowDevice || shouldFollow) {
		return preferred;
	}

	var raw = String(mount == null ? '' : mount).trim();
	if (raw === '') {
		return preferredByName;
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
	var streamName = String(config.streamName || '').trim();
	var streamFormat = String(config.streamFormat || 'mp3').toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	var deviceIndex = normalizeClientDeviceIndex(String(config.deviceIndex || getDeviceIndexForId(config.device || '')));
	var linkMode = resolveMountLinkModeForConfig(config, deviceIndex);
	var followDevice = linkMode === 'device';
	var followName = linkMode === 'name';
	var mount = normalizeMountByFormat(config.streamMount, streamName, streamFormat, followName, followDevice, deviceIndex);
	if (target === '') {
		return '';
	}
	return 'http://' + target + mount;
}

function buildProxyStreamUrl(config)
{
	var target = String(config.streamTarget || '').trim();
	var streamName = String(config.streamName || '').trim();
	var streamFormat = String(config.streamFormat || 'mp3').toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	var deviceIndex = normalizeClientDeviceIndex(String(config.deviceIndex || getDeviceIndexForId(config.device || '')));
	var linkMode = resolveMountLinkModeForConfig(config, deviceIndex);
	var followDevice = linkMode === 'device';
	var followName = linkMode === 'name';
	var mount = normalizeMountByFormat(config.streamMount, streamName, streamFormat, followName, followDevice, deviceIndex);
	if (target === '') {
		return '';
	}
	return apiUrl + '?proxy=stream&target=' + encodeURIComponent(target) + '&mount=' + encodeURIComponent(mount);
}

function stopListeningForDevice(deviceId, silent)
{
	var key = String(deviceId);
	var player = streamPlayersByDevice[key] || null;
	if (player) {
		player.stopped = true;
		if (player.retryTimer) {
			window.clearTimeout(player.retryTimer);
			player.retryTimer = null;
		}
		if (player.audio) {
			try {
				if (player.onError) {
					player.audio.removeEventListener('error', player.onError);
				}
				if (player.onEnded) {
					player.audio.removeEventListener('ended', player.onEnded);
				}
				if (player.onStalled) {
					player.audio.removeEventListener('stalled', player.onStalled);
				}
				player.audio.pause();
				player.audio.src = '';
				player.audio.load();
			} catch (error) {
			}
			player.audio = null;
		}
	}
	delete streamPlayersByDevice[key];
	if (!silent) {
		setStatus('Stopped listening on device ' + key + '.', false);
	}
}

function teardownStreamPlayerAudio(player)
{
	if (!player || !player.audio) {
		return;
	}

	try {
		if (player.onError) {
			player.audio.removeEventListener('error', player.onError);
		}
		if (player.onEnded) {
			player.audio.removeEventListener('ended', player.onEnded);
		}
		if (player.onStalled) {
			player.audio.removeEventListener('stalled', player.onStalled);
		}
		player.audio.pause();
		player.audio.src = '';
		player.audio.load();
	} catch (error) {
	}

	player.audio = null;
	player.onError = null;
	player.onEnded = null;
	player.onStalled = null;
}

function scheduleStreamListenReconnect(deviceId, reason)
{
	var key = String(deviceId || '');
	var player = streamPlayersByDevice[key] || null;
	if (!player || player.stopped) {
		return;
	}

	teardownStreamPlayerAudio(player);

	if (player.retryTimer) {
		window.clearTimeout(player.retryTimer);
		player.retryTimer = null;
	}

	player.retryTimer = window.setTimeout(function () {
		player.retryTimer = null;
		attemptStreamListenPlayback(key);
	}, STREAM_LISTEN_RETRY_DELAY_MS);

	var reasonText = String(reason || '').trim();
	if (reasonText === '') {
		reasonText = 'stream interruption';
	}
	setStatus('Stream ' + reasonText + ' on device ' + key + '; retrying...', true);
}

function attemptStreamListenPlayback(deviceId)
{
	var key = String(deviceId || '');
	var player = streamPlayersByDevice[key] || null;
	if (!player || player.stopped) {
		return;
	}
	if (player.connecting) {
		return;
	}

	player.connecting = true;
	if (player.retryTimer) {
		window.clearTimeout(player.retryTimer);
		player.retryTimer = null;
	}

	teardownStreamPlayerAudio(player);

	var audio = new Audio(player.url);
	audio.preload = 'none';

	var onStreamProblem = function () {
		if (player.stopped) {
			return;
		}
		player.connecting = false;
		player.retries = (Number(player.retries) || 0) + 1;
		scheduleStreamListenReconnect(key, 'unavailable');
	};

	player.audio = audio;
	player.onError = onStreamProblem;
	player.onEnded = onStreamProblem;
	player.onStalled = onStreamProblem;
	audio.addEventListener('error', player.onError);
	audio.addEventListener('ended', player.onEnded);
	audio.addEventListener('stalled', player.onStalled);

	var playPromise = audio.play();
	if (playPromise && typeof playPromise.then === 'function') {
		playPromise.then(function () {
			if (player.stopped) {
				teardownStreamPlayerAudio(player);
				return;
			}
			player.connecting = false;
			var hadRetries = (Number(player.retries) || 0) > 0;
			player.retries = 0;
			setStatus((hadRetries ? 'Reconnected. ' : '') + 'Listening on ' + player.url, false);
		}).catch(function (error) {
			if (player.stopped) {
				teardownStreamPlayerAudio(player);
				return;
			}
			player.connecting = false;
			player.retries = (Number(player.retries) || 0) + 1;
			scheduleStreamListenReconnect(key, (error && error.message) ? error.message : 'playback failure');
		});
		return;
	}

	player.connecting = false;
	player.retries = 0;
	setStatus('Listening on ' + player.url, false);
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

	streamPlayersByDevice[deviceId] = {
		audio: null,
		url: streamUrl,
		stopped: false,
		retryTimer: null,
		onError: null,
		onEnded: null,
		onStalled: null,
		connecting: false,
		retries: 0
	};
	attemptStreamListenPlayback(deviceId);
	renderDeviceList();
	setStatus('Connecting to ' + streamUrl + '...', false);
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
	var hasPipeOutputPresets = normalizePipeOutputPresetIdList(source.pipeOutputPresetIds).length > 0;
	var hasPipeOutputEntries = normalizeClientPipeOutputEntries(source.pipeOutputs).length > 0;
	var hasPipeOutputsConfigured = hasPipeOutputPresets || hasPipeOutputEntries;
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

	if (!recordEnabled && !streamEnabled && !hasPipeOutputsConfigured) {
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
	delete clean.biasT;
	delete clean.device;
	delete clean.deviceIndex;
	delete clean.deviceSerial;
	delete clean.outputDir;
	delete clean.streamMountFollowDevice;
	delete clean.streamMountLinkMode;
	delete clean.pipeOutputPresetId;
	delete clean.pipeOutputPresetsInline;
	clean.dcs = normalizeClientDcsCode(clean.dcs);
	clean.ctcss = normalizeClientCtcssTone(clean.ctcss);
	clean.postGain = normalizeClientPostGainValue(clean.postGain);
	clean.autoGain = parseClientBooleanFlag(clean.autoGain, false) ? '1' : '0';
	clean.voiceFilter = parseClientBooleanFlag(clean.voiceFilter, false) ? '1' : '0';
	clean.deemphasis = parseClientBooleanFlag(clean.deemphasis, false) ? '1' : '0';
	clean.deemphasisTau = String(clean.deemphasisTau || '75');
	clean.pipeOutputEnabled = parseClientBooleanFlag(clean.pipeOutputEnabled, false) ? '1' : '0';
	clean.pipeOutputs = normalizeClientPipeOutputEntries(clean.pipeOutputs);
	clean.pipeOutputPresetIds = normalizePipeOutputPresetIdList(clean.pipeOutputPresetIds);
	var tplInlinePresets = {};
	for (var tpIdx = 0; tpIdx < clean.pipeOutputPresetIds.length; tpIdx++) {
		var tpId = clean.pipeOutputPresetIds[tpIdx];
		var tpPreset = pipeOutputPresetsById[tpId];
		if (tpPreset) {
			tplInlinePresets[tpId] = {
				pipeOutputs: normalizeClientPipeOutputEntries(tpPreset.pipeOutputs),
				raw: normalizeClientPipeOutputRawSettings(tpPreset.raw)
			};
		}
	}
	clean.pipeOutputPresetsInline = tplInlinePresets;
	clean.streamFormat = String(clean.streamFormat || 'mp3').toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	clean.streamName = String(clean.streamName || '');
	var templateFollowName = parseClientBooleanFlag(
		clean.streamMountFollowName,
		inferMountFollowsStreamName(clean.streamMount, clean.streamName, clean.streamFormat)
	);
	clean.streamMountFollowName = templateFollowName;
	clean.streamMount = normalizeMountByFormat(clean.streamMount, clean.streamName, clean.streamFormat, templateFollowName, false, '');
	return clean;
}

function applyTemplateToDevice(deviceId, templateName, currentConfigOverride)
{
	var name = String(templateName || '');
	if (!name || !settingsTemplates[name]) {
		throw new Error('Template not found.');
	}
	var current = (currentConfigOverride && typeof currentConfigOverride === 'object' && !Array.isArray(currentConfigOverride))
		? applyClientOutputSelection(Object.assign({}, currentConfigOverride))
		: getConfigForDevice(deviceId);
	current.device = String(deviceId);
	current.deviceIndex = normalizeClientDeviceIndex(String(current.deviceIndex || getDeviceIndexForId(deviceId) || ''));
	var currentLinkMode = resolveMountLinkModeForConfig(current, current.deviceIndex || getDeviceIndexForId(deviceId));
	var currentFollowDevice = currentLinkMode === 'device';
	var currentBiasT = isBiasTEnabledValue(current.biasT) ? '1' : '0';
	var merged = Object.assign({}, current, settingsTemplates[name]);
	merged.biasT = currentBiasT;
	// Merge inline preset definitions from the template into the global pipe outputs store
	var tplPresets = merged.pipeOutputPresetsInline;
	if (tplPresets && typeof tplPresets === 'object' && !Array.isArray(tplPresets)) {
		var addedFromTemplate = false;
		for (var tpKey in tplPresets) {
			if (!Object.prototype.hasOwnProperty.call(tplPresets, tpKey)) {
				continue;
			}
			if (!pipeOutputPresetsById[tpKey]) {
				var tplPresetValue = tplPresets[tpKey];
				var tplPresetObj = (tplPresetValue && typeof tplPresetValue === 'object' && !Array.isArray(tplPresetValue))
					? tplPresetValue
					: { pipeOutputs: tplPresetValue };
				pipeOutputPresetsById[tpKey] = {
					pipeOutputs: normalizeClientPipeOutputEntries(tplPresetObj.pipeOutputs),
					raw: normalizeClientPipeOutputRawSettings(tplPresetObj.raw)
				};
				addedFromTemplate = true;
			}
		}
		if (addedFromTemplate) {
			savePipeOutputsToServer();
		}
	}
	delete merged.pipeOutputPresetsInline;
	merged.device = String(deviceId);
	merged.deviceIndex = normalizeClientDeviceIndex(String(getDeviceIndexForId(deviceId) || merged.deviceIndex || ''));
	merged.dcs = normalizeClientDcsCode(merged.dcs);
	merged.ctcss = normalizeClientCtcssTone(merged.ctcss);
	merged.postGain = normalizeClientPostGainValue(merged.postGain);
	merged.autoGain = parseClientBooleanFlag(merged.autoGain, false) ? '1' : '0';
	merged.voiceFilter = parseClientBooleanFlag(merged.voiceFilter, false) ? '1' : '0';
	merged.deemphasis = parseClientBooleanFlag(merged.deemphasis, false) ? '1' : '0';
	merged.deemphasisTau = String(merged.deemphasisTau || '75');
	merged.pipeOutputEnabled = parseClientBooleanFlag(merged.pipeOutputEnabled, false) ? '1' : '0';
	merged.pipeOutputs = normalizeClientPipeOutputEntries(merged.pipeOutputs);
	merged.pipeOutputPresetIds = normalizePipeOutputPresetIdList(merged.pipeOutputPresetIds);
	merged.streamFormat = String(merged.streamFormat || 'mp3').toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	merged.streamName = String(merged.streamName || '');
	var templateFollowName = parseClientBooleanFlag(
		merged.streamMountFollowName,
		inferMountFollowsStreamName(merged.streamMount, merged.streamName, merged.streamFormat)
	);
	merged.streamMountFollowName = templateFollowName;
	merged.streamMountFollowDevice = currentFollowDevice;
	if (currentFollowDevice) {
		merged.streamMountLinkMode = 'device';
	} else {
		var mergedLinkMode = normalizeMountLinkMode(merged.streamMountLinkMode);
		if (mergedLinkMode === 'device') {
			mergedLinkMode = templateFollowName ? 'name' : 'manual';
		}
		if (mergedLinkMode === '') {
			mergedLinkMode = templateFollowName ? 'name' : 'manual';
		}
		merged.streamMountLinkMode = mergedLinkMode;
		merged.streamMountFollowName = mergedLinkMode === 'name';
	}
	merged.streamMount = normalizeMountByFormat(
		merged.streamMount,
		merged.streamName,
		merged.streamFormat,
		merged.streamMountFollowName,
		merged.streamMountFollowDevice,
		merged.deviceIndex
	);
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
		resampleRate: '',
		squelch: '500',
		gain: 'auto',
		inputDejitter: '',
		dcs: '',
		ctcss: '',
		biasT: '0',
		threshold: '-40',
		postGain: '',
		autoGain: '0',
		voiceFilter: '0',
		deemphasis: '0',
		deemphasisTau: '75',
		pipeOutputEnabled: '1',
		pipeOutputs: [],
		pipeOutputPresetIds: [],
		silence: '2',
		outputMode: 'recorder',
		recordEnabled: true,
		streamEnabled: false,
		streamFormat: 'mp3',
		streamBitrate: '128',
		streamSampleRate: '44100',
		streamTarget: '127.0.0.1:8000',
		streamMount: '/rtl-sdr.mp3',
		streamMountLinkMode: 'name',
		streamMountFollowName: true,
		streamMountFollowDevice: false,
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

function buildResetConfig(deviceId, currentConfig)
{
	var defaults = getDefaultConfig(deviceId);
	var sourceConfig = (currentConfig && typeof currentConfig === 'object' && !Array.isArray(currentConfig))
		? currentConfig
		: {};
	defaults.biasT = isBiasTEnabledValue(sourceConfig.biasT) ? '1' : '0';
	var deviceIndex = normalizeClientDeviceIndex(String(sourceConfig.deviceIndex || getDeviceIndexForId(deviceId) || defaults.deviceIndex || ''));
	var preserveDeviceMountLink = resolveMountLinkModeForConfig(sourceConfig, deviceIndex) === 'device';

	if (!preserveDeviceMountLink) {
		return {
			preserveDeviceMountLink: false,
			config: defaults
		};
	}

	defaults.deviceIndex = deviceIndex;
	defaults.streamMountLinkMode = 'device';
	defaults.streamMountFollowName = true;
	defaults.streamMountFollowDevice = true;
	defaults.streamMount = normalizeMountByFormat(
		defaults.streamMount,
		defaults.streamName,
		defaults.streamFormat,
		true,
		true,
		deviceIndex
	);

	return {
		preserveDeviceMountLink: true,
		config: defaults
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

function normalizeClientPostGainValue(value)
{
	var raw = String(value == null ? '' : value).trim();
	if (raw === '') {
		return '';
	}
	if (!/^-?\d+(?:\.\d+)?$/.test(raw)) {
		return '';
	}
	var parsed = Number(raw);
	if (!isFinite(parsed) || parsed < -60 || parsed > 60) {
		return '';
	}
	return String(parsed);
}

function normalizeClientPipeOutputEntries(value)
{
	var entries = [];
	var rawEntries = [];
	if (Array.isArray(value)) {
		rawEntries = value;
	} else if (typeof value === 'string') {
		rawEntries = String(value).split(/\r\n|\r|\n/);
	} else {
		return entries;
	}

	for (var i = 0; i < rawEntries.length; i++) {
		var raw = rawEntries[i];
		if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
			if (Object.prototype.hasOwnProperty.call(raw, 'enabled') && !parseClientBooleanFlag(raw.enabled, true)) {
				continue;
			}
			raw = Object.prototype.hasOwnProperty.call(raw, 'options')
				? raw.options
				: (Object.prototype.hasOwnProperty.call(raw, 'raw') ? raw.raw : raw.value);
		}

		var normalized = String(raw == null ? '' : raw).trim();
		if (normalized === '') {
			continue;
		}

		normalized = normalized.replace(/[\u0000\r\n]+/g, '').trim();
		if (normalized === '') {
			continue;
		}

		entries.push(normalized);
		if (entries.length >= 16) {
			break;
		}
	}

	return entries;
}

function normalizeClientPipeOutputRawSettings(value)
{
	var source = (value && typeof value === 'object' && !Array.isArray(value)) ? value : {};
	var enabled = parseClientBooleanFlag(source.enabled, false);
	var bits = String(source.bits == null ? '' : source.bits).trim();
	var channels = String(source.channels == null ? '' : source.channels).trim();
	var rate = String(source.rate == null ? '' : source.rate).trim();
	var padDelay = String((source.padDelay != null ? source.padDelay : source.pipeOutputPadDelay) == null ? '' : (source.padDelay != null ? source.padDelay : source.pipeOutputPadDelay)).trim();

	if (bits !== '' && !/^[0-9]{1,2}$/.test(bits)) {
		bits = '';
	}
	if (channels !== '' && !/^[0-9]{1,2}$/.test(channels)) {
		channels = '';
	}
	if (rate !== '' && !/^[0-9]{2,6}$/.test(rate)) {
		rate = '';
	}
	if (padDelay !== '' && !/^[0-9]{1,6}$/.test(padDelay)) {
		padDelay = '';
	}

	var hasPad = Object.prototype.hasOwnProperty.call(source, 'pad') || Object.prototype.hasOwnProperty.call(source, 'pipeOutputPad');
	var pad = hasPad
		? parseClientBooleanFlag(source.pad != null ? source.pad : source.pipeOutputPad, false)
		: enabled;

	return {
		enabled: enabled,
		bits: bits,
		channels: channels,
		rate: rate,
		unsigned: parseClientBooleanFlag(source.unsigned, false),
		bigEndian: parseClientBooleanFlag(source.bigEndian, false),
		pad: pad,
		padDelay: padDelay
	};
}

function readPipeOutputEntriesFromCard(card)
{
	var entries = [];
	if (!card) {
		return entries;
	}

	var fields = card.querySelectorAll('.field-pipe-output-entry');
	for (var i = 0; i < fields.length; i++) {
		var value = String(fields[i].value || '').replace(/[\u0000\r\n]+/g, '').trim();
		if (value === '') {
			continue;
		}
		entries.push(value);
		if (entries.length >= 16) {
			break;
		}
	}

	return entries;
}

function syncPipeOutputFields()
{
	// No-op: pipe outputs are always visible; enabled state is per-entry checkbox
}

function renderPipeOutputRows(card, entries)
{
	if (!card) {
		return;
	}

	var list = card.querySelector('.pipe-output-list');
	if (!list) {
		return;
	}

	var normalizedEntries = [];
	if (Array.isArray(entries)) {
		for (var index = 0; index < entries.length; index++) {
			var candidate = entries[index];
			if (candidate && typeof candidate === 'object' && !Array.isArray(candidate)) {
				candidate = Object.prototype.hasOwnProperty.call(candidate, 'options')
					? candidate.options
					: (Object.prototype.hasOwnProperty.call(candidate, 'raw') ? candidate.raw : candidate.value);
			}
			var normalized = String(candidate == null ? '' : candidate).replace(/[\u0000\r\n]+/g, '').trim();
			normalizedEntries.push(normalized);
			if (normalizedEntries.length >= 16) {
				break;
			}
		}
	} else {
		normalizedEntries = normalizeClientPipeOutputEntries(entries);
	}

	if (!normalizedEntries.length) {
		normalizedEntries = [''];
	}

	var html = '';
	for (var i = 0; i < normalizedEntries.length; i++) {
		html += '' +
			'<div class="pipe-output-row">' +
				'<input type="text" class="field-pipe-output-entry" value="' + escapeHtml(String(normalizedEntries[i] || '')) + '" placeholder="COMMAND for --pipe-output (e.g. /usr/local/bin/my-consumer --stdin)">' +
				'<button type="button" class="refresh-button compact action-remove-pipe-output">Remove</button>' +
			'</div>';
	}

	list.innerHTML = html;
}

function appendPipeOutputPreviewArgs(line, source)
{
	var runtime = resolvePipeOutputRuntimeConfig(source);
	var entries = runtime.entries;
	for (var i = 0; i < entries.length; i++) {
		line += ' --pipe-output ' + entries[i];
	}

	var raw = runtime.raw;
	if (raw.enabled) {
		line += ' --pipe-output-raw';
		if (raw.bits !== '') {
			line += ' --pipe-output-bits ' + raw.bits;
		}
		if (raw.channels !== '') {
			line += ' --pipe-output-channels ' + raw.channels;
		}
		if (raw.rate !== '') {
			line += ' --pipe-output-rate ' + raw.rate;
		}
		if (raw.unsigned) {
			line += ' --pipe-output-unsigned';
		}
		if (raw.bigEndian) {
			line += ' --pipe-output-endian big';
		}
		if (raw.pad) {
			line += ' --pipe-output-pad';
			if (raw.padDelay !== '') {
				line += ' --pipe-output-pad-delay ' + raw.padDelay;
			}
		}
	}

	return line;
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
	
	// Check if pipe outputs are configured
	var hasPipeOutputPresets = (function() {
		var toggles = card.querySelectorAll('.field-pipe-output-preset-toggle');
		for (var i = 0; i < toggles.length; i++) {
			if (toggles[i].checked) {
				return true;
			}
		}
		return false;
	})();
	
	// enforce at least one enabled, unless pipe outputs are configured
	if (!isRecord && !isStream && !hasPipeOutputPresets) {
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
	var streamNameInput = card.querySelector('.field-stream-name');
	var mountInput = card.querySelector('.field-stream-mount');
	var linkModeSelect = card.querySelector('.field-stream-mount-link-mode');
	if (!formatSelect || !mountInput) {
		return;
	}
	var deviceId = normalizeClientDeviceId(String(card.getAttribute('data-device-id') || ''));
	var deviceIndex = normalizeClientDeviceIndex(String(getDeviceIndexForId(deviceId) || ''));
	var streamName = streamNameInput ? String(streamNameInput.value || '').trim() : '';
	var format = String(formatSelect.value || 'mp3').toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	var preferredByName = getDefaultMountForName(streamName, format);
	var preferredByDevice = getDefaultMountForDeviceIndex(deviceIndex, format);
	var currentMount = String(mountInput.value || '').trim();
	var inferredFollowDevice = inferMountFollowsDeviceIndex(currentMount, deviceIndex, format);
	var inferredFollowName = !inferredFollowDevice && inferMountFollowsStreamName(currentMount, streamName, format);
	var linkMode = linkModeSelect ? normalizeMountLinkMode(linkModeSelect.value) : '';
	if (linkMode === '') {
		linkMode = inferredFollowDevice ? 'device' : (inferredFollowName ? 'name' : 'manual');
	}

	if (linkModeSelect) {
		linkModeSelect.value = linkMode;
	}

	if (linkMode === 'device') {
		mountInput.value = preferredByDevice;
	} else if (linkMode === 'name') {
		mountInput.value = preferredByName;
	} else {
		var current = String(mountInput.value || '').trim();
		if (current === '' && force) {
			mountInput.value = preferredByName;
		} else if (current !== '' && current.charAt(0) !== '/') {
			mountInput.value = '/' + current;
		}
	}

	var placeholder = linkMode === 'device' ? preferredByDevice : preferredByName;
	var isLinked = linkMode === 'device' || linkMode === 'name';
	mountInput.placeholder = placeholder;
	mountInput.readOnly = isLinked;
	mountInput.classList.toggle('is-readonly', isLinked);
	if (linkMode === 'name') {
		mountInput.title = 'Automatically follows Stream Name. Set Mount Link to Manual to override.';
	} else if (linkMode === 'device') {
		mountInput.title = 'Automatically follows device index. Set Mount Link to Manual to override.';
	} else {
		mountInput.title = '';
	}
}

function splitPipelineCommandStages(commandLine)
{
	var raw = String(commandLine || '').trim();
	if (raw === '') {
		return [];
	}

	var stages = [];
	var current = '';
	var inSingleQuote = false;
	var inDoubleQuote = false;
	var escaped = false;

	for (var i = 0; i < raw.length; i++) {
		var ch = raw.charAt(i);
		if (escaped) {
			current += ch;
			escaped = false;
			continue;
		}

		if (ch === '\\') {
			current += ch;
			escaped = true;
			continue;
		}

		if (ch === "'" && !inDoubleQuote) {
			inSingleQuote = !inSingleQuote;
			current += ch;
			continue;
		}

		if (ch === '"' && !inSingleQuote) {
			inDoubleQuote = !inDoubleQuote;
			current += ch;
			continue;
		}

		if (ch === '|' && !inSingleQuote && !inDoubleQuote) {
			var stage = current.trim();
			if (stage !== '') {
				stages.push(stage);
			}
			current = '';
			continue;
		}

		current += ch;
	}

	var finalStage = current.trim();
	if (finalStage !== '') {
		stages.push(finalStage);
	}

	return stages;
}

function unwrapShellCommandArgument(commandArgument)
{
	var value = String(commandArgument || '').trim();
	if (value.length < 2) {
		return value;
	}

	var quote = value.charAt(0);
	if ((quote !== "'" && quote !== '"') || value.charAt(value.length - 1) !== quote) {
		return value;
	}

	var unwrapped = value.slice(1, -1);
	if (quote === "'") {
		unwrapped = unwrapped.replace(/'\\''/g, "'");
	} else {
		unwrapped = unwrapped.replace(/\\"/g, '"').replace(/\\\\/g, '\\');
	}

	return unwrapped.trim();
}

function normalizePipelineCommandForDisplay(commandLine)
{
	var raw = String(commandLine || '').trim();
	if (raw === '') {
		return '';
	}

	var shellWrapperMatch = raw.match(/^(?:nohup\s+)?(?:setsid\s+)?(?:\/bin\/)?(?:bash|sh)\s+-[lc]\s+([\s\S]+)$/i);
	if (!shellWrapperMatch) {
		return raw;
	}

	var commandArgument = unwrapShellCommandArgument(shellWrapperMatch[1]);
	if (commandArgument === '') {
		return raw;
	}

	if (commandArgument.indexOf('|') !== -1 || /\brtl_fm\b/i.test(commandArgument) || /\bffmpeg\b/i.test(commandArgument)) {
		return commandArgument;
	}

	return raw;
}

function buildPipelineStagePreviewLines(config)
{
	var source = (config && typeof config === 'object') ? config : {};
	var lines = [];
	var outputSelection = getClientOutputSelection(source);
	var deviceIndex = normalizeClientDeviceIndex(String(source.deviceIndex || getDeviceIndexForId(source.device || '')));
	var streamFormat = String(source.streamFormat || 'mp3').toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	var streamName = String(source.streamName || '');
	var mountLinkMode = resolveMountLinkModeForConfig(source, deviceIndex);
	var followDevice = mountLinkMode === 'device';
	var followName = mountLinkMode === 'name';
	var mount = normalizeMountByFormat(source.streamMount, streamName, streamFormat, followName, followDevice, deviceIndex);

	var streamSampleRate = (['22050', '44100', '48000', '96000'].indexOf(String(source.streamSampleRate || '')) !== -1) ? String(source.streamSampleRate) : '44100';
	var rtlLine = 'rtl_fm';
	var frequency = String(source.frequency || '').trim();
	if (frequency !== '') {
		rtlLine += ' -f ' + frequency;
	}
	rtlLine += ' -M ' + String(source.mode || 'fm');
	var rtlBandwidth = String(source.rtlBandwidth || '12000');
	var resampleRate = String(source.resampleRate || '').trim();
	var effectiveStdinRate = resampleRate !== '' ? resampleRate : rtlBandwidth;
	rtlLine += ' -s ' + rtlBandwidth +  ' -r ' + effectiveStdinRate;
	var squelch = String(source.squelch || '').trim();
	if (squelch !== '') {
		rtlLine += ' -l ' + squelch;
	}
	if (deviceIndex !== '') {
		rtlLine += ' -d ' + deviceIndex;
	}
	if (isBiasTEnabledValue(source.biasT)) {
		rtlLine += ' -T';
	}
	lines.push(rtlLine);

	var dcsCode = normalizeClientDcsCode(source.dcs);
	var ctcssTone = normalizeClientCtcssTone(source.ctcss);
	var postGain = normalizeClientPostGainValue(source.postGain);
	var inputDejitter = String(source.inputDejitter || '').trim();
	var effectiveInputDejitter = inputDejitter;
	if (effectiveInputDejitter === '' && rmsInputDejitterMs > 0) {
		effectiveInputDejitter = String(rmsInputDejitterMs);
	}
	var autoGainEnabled = parseClientBooleanFlag(source.autoGain, false);
	var voiceFilterEnabled = parseClientBooleanFlag(source.voiceFilter, false);
	var deemphasisEnabled = parseClientBooleanFlag(source.deemphasis, false);
	var deemphasisTau = String(source.deemphasisTau || '75').trim();
	if (outputSelection.streamEnabled && outputSelection.recordEnabled) {
		var recorderBothLine = 'radio-pipe --stdin --stdin-rate ' + effectiveStdinRate;
		if (effectiveInputDejitter !== '') {
			recorderBothLine += ' --input-dejitter ' + effectiveInputDejitter;
		}
		recorderBothLine += ' --stdout-pad';
		recorderBothLine += ' --stdout-rate ' + streamSampleRate;
		recorderBothLine += ' -t ' + String(source.threshold || '-40');
		recorderBothLine += ' -s ' + String(source.silence || '2');
		if (dcsCode !== '') {
			recorderBothLine += ' --dcs ' + dcsCode;
		}
		if (ctcssTone !== '') {
			recorderBothLine += ' --ctcss ' + ctcssTone;
		}
		if (postGain !== '') {
			recorderBothLine += ' --gain ' + postGain;
		}
		if (autoGainEnabled) {
			recorderBothLine += ' --auto-gain';
		}
		if (voiceFilterEnabled) {
			recorderBothLine += ' --voice-filter';
		}
		if (deemphasisEnabled) {
			recorderBothLine += ' --deemphasis';
			if (deemphasisTau !== '' && deemphasisTau !== '75') {
				recorderBothLine += ' ' + deemphasisTau;
			}
		}
		recorderBothLine = appendPipeOutputPreviewArgs(recorderBothLine, source);
		lines.push(recorderBothLine);
	} else if (outputSelection.streamEnabled) {
		var conditionerLine = 'radio-pipe --stdin --stdin-rate ' + effectiveStdinRate;
		if (effectiveInputDejitter !== '') {
			conditionerLine += ' --input-dejitter ' + effectiveInputDejitter;
		}
		conditionerLine += ' --stdout-pad';
		conditionerLine += ' --stdout-rate ' + streamSampleRate;
		if (dcsCode !== '') {
			conditionerLine += ' --dcs ' + dcsCode;
		}
		if (ctcssTone !== '') {
			conditionerLine += ' --ctcss ' + ctcssTone;
		}
		if (postGain !== '') {
			conditionerLine += ' --gain ' + postGain;
		}
		if (autoGainEnabled) {
			conditionerLine += ' --auto-gain';
		}
		if (voiceFilterEnabled) {
			conditionerLine += ' --voice-filter';
		}
		if (deemphasisEnabled) {
			conditionerLine += ' --deemphasis';
			if (deemphasisTau !== '' && deemphasisTau !== '75') {
				conditionerLine += ' ' + deemphasisTau;
			}
		}
		conditionerLine = appendPipeOutputPreviewArgs(conditionerLine, source);
		lines.push(conditionerLine);
	} else {
		var recorderLine = 'radio-pipe --stdin --stdin-rate ' + effectiveStdinRate;
		if (inputDejitter !== '') {
			recorderLine += ' --input-dejitter ' + inputDejitter;
		}
		recorderLine += ' -t ' + String(source.threshold || '-40');
		recorderLine += ' -s ' + String(source.silence || '2');
		if (dcsCode !== '') {
			recorderLine += ' --dcs ' + dcsCode;
		}
		if (ctcssTone !== '') {
			recorderLine += ' --ctcss ' + ctcssTone;
		}
		if (postGain !== '') {
			recorderLine += ' --gain ' + postGain;
		}
		if (autoGainEnabled) {
			recorderLine += ' --auto-gain';
		}
		if (voiceFilterEnabled) {
			recorderLine += ' --voice-filter';
		}
		if (deemphasisEnabled) {
			recorderLine += ' --deemphasis';
			if (deemphasisTau !== '' && deemphasisTau !== '75') {
				recorderLine += ' ' + deemphasisTau;
			}
		}
		recorderLine = appendPipeOutputPreviewArgs(recorderLine, source);
		var afterRecordAction = String(source.afterRecordAction || 'none').toLowerCase();
		if (afterRecordAction !== '' && afterRecordAction !== 'none') {
			recorderLine += ' --after ' + afterRecordAction;
		}
		lines.push(recorderLine);
	}

	if (outputSelection.streamEnabled) {
		var codec = streamFormat === 'ogg' ? 'libvorbis' : 'libmp3lame';
		var bitrate = String(source.streamBitrate || '128').trim() || '128';
		var streamTarget = String(source.streamTarget || '').trim();
		var streamUsername = String(source.streamUsername || '').trim();
		var streamPassword = String(source.streamPassword || '');
		var authPrefix = '';
		if (streamUsername !== '' && streamPassword !== '') {
			authPrefix = streamUsername + ':' + streamPassword + '@';
		}
		var destination = streamTarget !== ''
			? ('icecast://' + authPrefix + streamTarget + mount)
			: ('icecast://<target>' + mount);
		lines.push('ffmpeg -hide_banner -loglevel warning -nostats -f s16le -ar ' + streamSampleRate + ' -ac 1 -i pipe:0 -vn -b:a ' + bitrate + 'k -c:a ' + codec + ' -f ' + streamFormat + ' ' + destination);
	}

	return lines;
}

function buildFullPipelineCommandForDeviceCard(instance, config)
{
	var runningCommand = (instance && typeof instance === 'object' && typeof instance.command === 'string')
		? normalizePipelineCommandForDisplay(String(instance.command || ''))
		: '';
	if (runningCommand !== '') {
		return runningCommand;
	}

	var previewStages = buildPipelineStagePreviewLines(config);
	if (!previewStages.length) {
		return '';
	}

	return previewStages.join(' | ');
}

function buildPipelineLinesForDeviceCard(instance, config)
{
	var runningCommand = (instance && typeof instance === 'object' && typeof instance.command === 'string')
		? normalizePipelineCommandForDisplay(String(instance.command || ''))
		: '';
	if (runningCommand !== '') {
		var runningStages = splitPipelineCommandStages(runningCommand);
		if (runningStages.length > 0) {
			return runningStages;
		}
	}

	return buildPipelineStagePreviewLines(config);
}

function pipelineLinesMarkup(instance, config)
{
	var lines = buildPipelineLinesForDeviceCard(instance, config);
	if (!lines.length) {
		lines = ['Pipeline unavailable.'];
	}

	var htmlLines = [];
	for (var i = 0; i < lines.length; i++) {
		var fullLine = String(lines[i] || '');
		htmlLines.push('<span class="pipeline-line" title="' + escapeHtml(fullLine) + '">' + escapeHtml(fullLine) + '</span>');
	}

	return htmlLines.join('');
}

function copyFullPipelineForCard(card)
{
	var deviceId = normalizeClientDeviceId(String(card.getAttribute('data-device-id') || '').trim());
	if (deviceId === '') {
		setStatus('Missing device id.', true);
		return;
	}

	var config = readCardConfig(card);
	var instance = knownInstancesByDevice[deviceId] || null;
	var fullPipeline = buildFullPipelineCommandForDeviceCard(instance, config);
	if (fullPipeline === '') {
		setStatus('Pipeline unavailable for device ' + deviceId + '.', true);
		return;
	}

	if (navigator.clipboard && navigator.clipboard.writeText) {
		navigator.clipboard.writeText(fullPipeline).then(function () {
			setStatus('Copied full pipeline for device ' + deviceId + '.', false);
		}).catch(function () {
			setStatus('Unable to copy full pipeline.', true);
		});
		return;
	}

	var temp = document.createElement('textarea');
	temp.value = fullPipeline;
	document.body.appendChild(temp);
	temp.select();
	try {
		document.execCommand('copy');
		setStatus('Copied full pipeline for device ' + deviceId + '.', false);
	} catch (error) {
		setStatus('Unable to copy full pipeline.', true);
	}
	document.body.removeChild(temp);
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
		config: normalizedTemplate,
	};
}

function parseImportedTemplatesPayload(rawValue)
{
	var importedByName = {};

	function addImportedTemplate(candidate, fallbackName)
	{
		var parsed = parseImportedTemplatePayload(candidate);
		var parsedName = String(parsed && parsed.name ? parsed.name : '').trim();
		if (parsedName === '') {
			parsedName = String(fallbackName || '').trim();
		}
		if (parsedName === '') {
			parsedName = 'Imported Template';
		}

		var parsedConfig = (parsed && parsed.config && typeof parsed.config === 'object' && !Array.isArray(parsed.config))
			? parsed.config
			: {};
		var normalized = sanitizeTemplateConfig(parsedConfig);
		normalized.templateName = parsedName;
		importedByName[parsedName] = normalized;
	}

	if (Array.isArray(rawValue)) {
		for (var i = 0; i < rawValue.length; i++) {
			addImportedTemplate(rawValue[i], '');
		}
	} else if (rawValue && typeof rawValue === 'object') {
		if (rawValue.templates && typeof rawValue.templates === 'object' && !Array.isArray(rawValue.templates)) {
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

function normalizeImportedStreamServers(rawServers)
{
	var normalized = {};
	if (!rawServers || typeof rawServers !== 'object' || Array.isArray(rawServers)) {
		return normalized;
	}

	for (var rawId in rawServers) {
		if (!Object.prototype.hasOwnProperty.call(rawServers, rawId)) {
			continue;
		}

		var server = rawServers[rawId];
		if (!server || typeof server !== 'object' || Array.isArray(server)) {
			continue;
		}

		var name = String(server.name || '').trim();
		var target = String(server.target || '').trim();
		if (name === '' || target === '') {
			continue;
		}

		normalized[String(rawId)] = {
			name: name,
			target: target,
			username: String(server.username || ''),
			password: String(server.password || '')
		};
	}

	return normalized;
}

function normalizeImportedRecordingServers(rawServers)
{
	var normalized = {};
	if (!rawServers || typeof rawServers !== 'object' || Array.isArray(rawServers)) {
		return normalized;
	}

	for (var rawId in rawServers) {
		if (!Object.prototype.hasOwnProperty.call(rawServers, rawId)) {
			continue;
		}

		var server = rawServers[rawId];
		if (!server || typeof server !== 'object' || Array.isArray(server)) {
			continue;
		}

		var name = String(server.name || '').trim();
		var url = String(server.url || '').trim();
		if (name === '' || url === '') {
			continue;
		}

		normalized[String(rawId)] = {
			name: name,
			url: url,
			username: String(server.username || ''),
			password: String(server.password || '')
		};
	}

	return normalized;
}

function parseImportedServerBundles(rawValue)
{
	var payload = rawValue && typeof rawValue === 'object' && !Array.isArray(rawValue)
		? rawValue
		: {};

	var rawStreamServers = null;
	if (payload.streamingServers && typeof payload.streamingServers === 'object' && !Array.isArray(payload.streamingServers)) {
		rawStreamServers = payload.streamingServers;
	} else if (payload.streamServers && typeof payload.streamServers === 'object' && !Array.isArray(payload.streamServers)) {
		rawStreamServers = payload.streamServers;
	}

	var rawRecordingServers = null;
	if (payload.recordingServers && typeof payload.recordingServers === 'object' && !Array.isArray(payload.recordingServers)) {
		rawRecordingServers = payload.recordingServers;
	}

	return {
		streamingServers: normalizeImportedStreamServers(rawStreamServers),
		recordingServers: normalizeImportedRecordingServers(rawRecordingServers)
	};
}

function findMatchingStreamServerId(serverConfig)
{
	var target = String(serverConfig && serverConfig.target ? serverConfig.target : '').trim();
	if (target === '') {
		return '';
	}
	var username = String(serverConfig && serverConfig.username ? serverConfig.username : '');
	var password = String(serverConfig && serverConfig.password ? serverConfig.password : '');

	for (var id in streamServersById) {
		if (!Object.prototype.hasOwnProperty.call(streamServersById, id)) {
			continue;
		}
		var existing = streamServersById[id] || {};
		if (String(existing.target || '').trim() !== target) {
			continue;
		}
		if (String(existing.username || '') === username && String(existing.password || '') === password) {
			return String(id);
		}
	}

	for (var fallbackId in streamServersById) {
		if (!Object.prototype.hasOwnProperty.call(streamServersById, fallbackId)) {
			continue;
		}
		var fallback = streamServersById[fallbackId] || {};
		if (String(fallback.target || '').trim() === target) {
			return String(fallbackId);
		}
	}

	return '';
}

function findMatchingRecordingServerId(serverConfig)
{
	var url = String(serverConfig && serverConfig.url ? serverConfig.url : '').trim();
	if (url === '') {
		return '';
	}
	var username = String(serverConfig && serverConfig.username ? serverConfig.username : '');
	var password = String(serverConfig && serverConfig.password ? serverConfig.password : '');

	for (var id in recordingServersById) {
		if (!Object.prototype.hasOwnProperty.call(recordingServersById, id)) {
			continue;
		}
		var existing = recordingServersById[id] || {};
		if (String(existing.url || '').trim() !== url) {
			continue;
		}
		if (String(existing.username || '') === username && String(existing.password || '') === password) {
			return String(id);
		}
	}

	for (var fallbackId in recordingServersById) {
		if (!Object.prototype.hasOwnProperty.call(recordingServersById, fallbackId)) {
			continue;
		}
		var fallback = recordingServersById[fallbackId] || {};
		if (String(fallback.url || '').trim() === url) {
			return String(fallbackId);
		}
	}

	return '';
}

function mergeImportedServersIntoLocalStore(serverBundles)
{
	var streamIdMap = {};
	var recordingIdMap = {};
	var addedStreamServers = 0;
	var addedRecordingServers = 0;

	var importedStreamServers = serverBundles && serverBundles.streamingServers ? serverBundles.streamingServers : {};
	for (var importStreamId in importedStreamServers) {
		if (!Object.prototype.hasOwnProperty.call(importedStreamServers, importStreamId)) {
			continue;
		}
		var importStreamServer = importedStreamServers[importStreamId];
		var existingStreamId = findMatchingStreamServerId(importStreamServer);
		if (existingStreamId !== '') {
			streamIdMap[String(importStreamId)] = existingStreamId;
			continue;
		}

		var nextStreamId = getNextServerId();
		streamServersById[nextStreamId] = {
			name: String(importStreamServer.name || ''),
			target: String(importStreamServer.target || ''),
			username: String(importStreamServer.username || ''),
			password: String(importStreamServer.password || '')
		};
		streamIdMap[String(importStreamId)] = nextStreamId;
		addedStreamServers++;
	}

	var importedRecordingServers = serverBundles && serverBundles.recordingServers ? serverBundles.recordingServers : {};
	for (var importRecordingId in importedRecordingServers) {
		if (!Object.prototype.hasOwnProperty.call(importedRecordingServers, importRecordingId)) {
			continue;
		}
		var importRecordingServer = importedRecordingServers[importRecordingId];
		var existingRecordingId = findMatchingRecordingServerId(importRecordingServer);
		if (existingRecordingId !== '') {
			recordingIdMap[String(importRecordingId)] = existingRecordingId;
			continue;
		}

		var nextRecordingId = getNextRecordingServerId();
		recordingServersById[nextRecordingId] = {
			name: String(importRecordingServer.name || ''),
			url: String(importRecordingServer.url || ''),
			username: String(importRecordingServer.username || ''),
			password: String(importRecordingServer.password || '')
		};
		recordingIdMap[String(importRecordingId)] = nextRecordingId;
		addedRecordingServers++;
	}

	return {
		streamIdMap: streamIdMap,
		recordingIdMap: recordingIdMap,
		addedStreamServers: addedStreamServers,
		addedRecordingServers: addedRecordingServers,
		hasStreamChanges: addedStreamServers > 0,
		hasRecordingChanges: addedRecordingServers > 0
	};
}

function remapImportedTemplateServerIds(importedTemplates, streamIdMap, recordingIdMap)
{
	var remapped = {};
	var sourceTemplates = importedTemplates && typeof importedTemplates === 'object' && !Array.isArray(importedTemplates)
		? importedTemplates
		: {};

	for (var name in sourceTemplates) {
		if (!Object.prototype.hasOwnProperty.call(sourceTemplates, name)) {
			continue;
		}

		var templateConfig = sourceTemplates[name];
		if (!templateConfig || typeof templateConfig !== 'object' || Array.isArray(templateConfig)) {
			continue;
		}

		var nextConfig = Object.assign({}, templateConfig);
		var sourceStreamId = String(nextConfig.streamServerId || '').trim();
		if (sourceStreamId !== '' && streamIdMap && Object.prototype.hasOwnProperty.call(streamIdMap, sourceStreamId)) {
			nextConfig.streamServerId = String(streamIdMap[sourceStreamId]);
		}

		var sourceRecordingId = String(nextConfig.recordingServerId || '').trim();
		if (sourceRecordingId !== '' && recordingIdMap && Object.prototype.hasOwnProperty.call(recordingIdMap, sourceRecordingId)) {
			nextConfig.recordingServerId = String(recordingIdMap[sourceRecordingId]);
		}

		remapped[name] = nextConfig;
	}

	return remapped;
}

function collectServerBundlesForTemplates(templateMap)
{
	var bundles = {
		streamingServers: {},
		recordingServers: {}
	};
	if (!templateMap || typeof templateMap !== 'object' || Array.isArray(templateMap)) {
		return bundles;
	}

	for (var templateName in templateMap) {
		if (!Object.prototype.hasOwnProperty.call(templateMap, templateName)) {
			continue;
		}
		var templateConfig = templateMap[templateName];
		if (!templateConfig || typeof templateConfig !== 'object' || Array.isArray(templateConfig)) {
			continue;
		}

		var streamServerId = String(templateConfig.streamServerId || '').trim();
		if (streamServerId !== '' && Object.prototype.hasOwnProperty.call(streamServersById, streamServerId)) {
			bundles.streamingServers[streamServerId] = Object.assign({}, streamServersById[streamServerId]);
		}

		var recordingServerId = String(templateConfig.recordingServerId || '').trim();
		if (recordingServerId !== '' && Object.prototype.hasOwnProperty.call(recordingServersById, recordingServerId)) {
			bundles.recordingServers[recordingServerId] = Object.assign({}, recordingServersById[recordingServerId]);
		}
	}

	return bundles;
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
	var singleTemplateMap = {};
	singleTemplateMap[name] = payload.template;
	var serverBundles = collectServerBundlesForTemplates(singleTemplateMap);
	if (Object.keys(serverBundles.streamingServers).length > 0) {
		payload.streamingServers = serverBundles.streamingServers;
	}
	if (Object.keys(serverBundles.recordingServers).length > 0) {
		payload.recordingServers = serverBundles.recordingServers;
	}

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
		var exportPayload = { templates: payloadTemplates };
		var serverBundles = collectServerBundlesForTemplates(payloadTemplates);
		if (Object.keys(serverBundles.streamingServers).length > 0) {
			exportPayload.streamingServers = serverBundles.streamingServers;
		}
		if (Object.keys(serverBundles.recordingServers).length > 0) {
			exportPayload.recordingServers = serverBundles.recordingServers;
		}
		jsonContent = JSON.stringify(exportPayload, null, 2);
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

function deleteTemplateByName(templateName)
{
	var name = String(templateName || '').trim();
	if (name === '') {
		setStatus('Select a template to delete.', true);
		return Promise.resolve(false);
	}

	if (!settingsTemplates[name] || typeof settingsTemplates[name] !== 'object') {
		setStatus('Selected template was not found.', true);
		return Promise.resolve(false);
	}

	delete settingsTemplates[name];

	var clearedDeviceCount = 0;
	for (var deviceId in deviceConfigsById) {
		if (!Object.prototype.hasOwnProperty.call(deviceConfigsById, deviceId)) {
			continue;
		}

		var existingConfig = deviceConfigsById[deviceId];
		if (!existingConfig || typeof existingConfig !== 'object') {
			continue;
		}

		if (String(existingConfig.templateName || '') !== name) {
			continue;
		}

		var updatedConfig = Object.assign({}, existingConfig);
		updatedConfig.templateName = '';
		deviceConfigsById[deviceId] = updatedConfig;
		saveDeviceConfigs(String(deviceId));
		clearedDeviceCount++;
	}

	return saveTemplates().then(function () {
		return postUserAction('template_delete', { name: name });
	}).then(function (result) {
		if (result && result.templates && typeof result.templates === 'object' && !Array.isArray(result.templates)) {
			settingsTemplates = result.templates;
		}
		lastSavedTemplatesFingerprint = computeTemplatesFingerprint(collectTemplatesPayload());
		refreshGlobalTemplateSelector();
		renderDeviceList();

		var message = 'Deleted template "' + name + '".';
		if (clearedDeviceCount > 0) {
			message += ' Cleared template assignment on ' + clearedDeviceCount + ' device(s).';
		}
		setStatus(message, false);
		return true;
	});
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
		var importedServerBundles = parseImportedServerBundles(parsed);
		var serverMerge = mergeImportedServersIntoLocalStore(importedServerBundles);
		importedTemplates = remapImportedTemplateServerIds(importedTemplates, serverMerge.streamIdMap, serverMerge.recordingIdMap);
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

		var persistPromise = Promise.resolve();
		if (serverMerge.hasStreamChanges) {
			persistPromise = persistPromise.then(function () {
				return saveStreamServers();
			});
		}
		if (serverMerge.hasRecordingChanges) {
			persistPromise = persistPromise.then(function () {
				return saveRecordingServers();
			});
		}

		return persistPromise.then(function () {
			return saveTemplates();
		}).then(function () {
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
			if (serverMerge.addedStreamServers > 0 || serverMerge.addedRecordingServers > 0) {
				statusMessage += ' Added ' + serverMerge.addedStreamServers + ' stream server(s) and ' + serverMerge.addedRecordingServers + ' recording server(s).';
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
		var importedServerBundles = parseImportedServerBundles(parsed);
		var serverMerge = mergeImportedServersIntoLocalStore(importedServerBundles);
		var remappedTemplates = remapImportedTemplateServerIds(
			((function () { var tmp = {}; tmp[imported.name] = imported.config; return tmp; })()),
			serverMerge.streamIdMap,
			serverMerge.recordingIdMap
		);
		if (remappedTemplates[imported.name]) {
			imported.config = remappedTemplates[imported.name];
		}
		var existingTemplate = settingsTemplates[imported.name] || null;
		if (existingTemplate) {
			if (!window.confirm('Template "' + imported.name + '" already exists. Overwrite it?')) {
				return null;
			}
		}

		settingsTemplates[imported.name] = imported.config;
		var currentDeviceConfig = Object.assign({}, getConfigForDevice(deviceId));
		currentDeviceConfig.templateName = imported.name;
		deviceConfigsById[deviceId] = currentDeviceConfig;
		saveDeviceConfigs(deviceId);

		var persistPromise = Promise.resolve();
		if (serverMerge.hasStreamChanges) {
			persistPromise = persistPromise.then(function () {
				return saveStreamServers();
			});
		}
		if (serverMerge.hasRecordingChanges) {
			persistPromise = persistPromise.then(function () {
				return saveRecordingServers();
			});
		}

		return persistPromise.then(function () {
			return saveTemplates();
		}).then(function () {
			refreshGlobalTemplateSelector();
			renderDeviceList();
			var statusMessage = 'Imported template "' + imported.name + '".';
			if (serverMerge.addedStreamServers > 0 || serverMerge.addedRecordingServers > 0) {
				statusMessage += ' Added ' + serverMerge.addedStreamServers + ' stream server(s) and ' + serverMerge.addedRecordingServers + ' recording server(s).';
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

function getRxIndicator(deviceId, isRunning, config)
{
	if (!isRunning) {
		return { label: 'Rx Off', className: 'rx-off' };
	}

	var snapshot = radioPipeStatusByDevice[String(deviceId)] || null;
	if (!snapshot || !snapshot.hasStatus) {
		return { label: 'Rx IDLE', className: 'rx-idle' };
	}

	var audioReason = Array.isArray(snapshot.audioReason) ? snapshot.audioReason : [];
	if (audioReason.length === 0) {
		return { label: 'Rx IDLE', className: 'rx-idle' };
	}

	if (audioReason.indexOf('dcs') !== -1) {
		return { label: 'Rx DCS', className: 'rx-active' };
	}
	if (audioReason.indexOf('ctcss') !== -1) {
		return { label: 'Rx CTCSS', className: 'rx-active' };
	}
	if (audioReason.indexOf('rms') !== -1) {
		return { label: 'Rx AUDIO', className: 'rx-active' };
	}

	var openReason = inferRxOpenReason(snapshot);
	if (openReason === 'dcs') {
		return { label: 'Rx DCS', className: 'rx-active' };
	}
	if (openReason === 'ctcss') {
		return { label: 'Rx CTCSS', className: 'rx-active' };
	}

	return { label: 'Rx AUDIO', className: 'rx-active' };
}

function getConfiguredSilenceFloorDb(deviceId)
{
	var normalizedDeviceId = normalizeClientDeviceId(String(deviceId || '').trim());
	if (normalizedDeviceId === '') {
		return -100;
	}

	var resolvedThreshold = null;
	var instance = knownInstancesByDevice[normalizedDeviceId] || null;
	if (instance && instance.config && typeof instance.config === 'object' && !Array.isArray(instance.config)) {
		var instanceThreshold = Number(instance.config.threshold);
		if (isFinite(instanceThreshold)) {
			resolvedThreshold = instanceThreshold;
		}
	}

	if (resolvedThreshold === null) {
		var config = getConfigForDevice(normalizedDeviceId);
		if (config && typeof config === 'object' && !Array.isArray(config)) {
			var configThreshold = Number(config.threshold);
			if (isFinite(configThreshold)) {
				resolvedThreshold = configThreshold;
			}
		}
	}

	if (resolvedThreshold === null) {
		return -100;
	}

	return Math.max(-120, Math.min(0, resolvedThreshold));
}

function getRmsDbIndicator(deviceId, isRunning)
{
	if (!isRunning) {
		return { label: 'RMS IN OFF', className: 'rms-off', percent: 0 };
	}

	var snapshot = radioPipeStatusByDevice[String(deviceId)] || null;
	if (!snapshot || !snapshot.hasStatus) {
		return { label: 'RMS IN --', className: 'rms-idle', percent: 0 };
	}

	var rmsDb = normalizeClientRmsDbValue(snapshot.rmsDb);
	if (rmsDb === null) {
		return { label: 'RMS IN --', className: 'rms-idle', percent: 0 };
	}

	var minRmsDb = getConfiguredSilenceFloorDb(deviceId);
	var maxRmsDb = -25;
	if (maxRmsDb <= minRmsDb) {
		maxRmsDb = minRmsDb + 1;
	}
	var normalized = (rmsDb - minRmsDb) / (maxRmsDb - minRmsDb);
	if (!isFinite(normalized)) {
		normalized = 0;
	}

	var percent = Math.max(0, Math.min(1, normalized)) * 100;
	var className = 'rms-low';
	if (percent >= 66) {
		className = 'rms-high';
	} else if (percent >= 33) {
		className = 'rms-mid';
	}

	return {
		label: 'RMS IN ' + rmsDb.toFixed(1) + ' dB',
		className: className,
		percent: percent
	};
}

function getRmsIndicator(deviceId, isRunning)
{
	if (!isRunning) {
		return { label: 'RMS OUT OFF', className: 'rms-off', percent: 0 };
	}

	var snapshot = radioPipeStatusByDevice[String(deviceId)] || null;
	if (!snapshot || !snapshot.hasStatus) {
		return { label: 'RMS OUT --', className: 'rms-idle', percent: 0 };
	}

	// Prefer outputDb if present, else rmsDb
	var rmsDb = null;
	if (typeof snapshot.outputDb !== 'undefined' && snapshot.outputDb !== null) {
		rmsDb = normalizeClientRmsDbValue(snapshot.outputDb);
	} else {
		rmsDb = normalizeClientRmsDbValue(snapshot.rmsDb);
	}
	if (rmsDb === null) {
		return { label: 'RMS OUT --', className: 'rms-idle', percent: 0 };
	}

	var minRmsDb = getConfiguredSilenceFloorDb(deviceId);
	var maxRmsDb = -25;
	if (maxRmsDb <= minRmsDb) {
		maxRmsDb = minRmsDb + 1;
	}
	var normalized = (rmsDb - minRmsDb) / (maxRmsDb - minRmsDb);
	if (!isFinite(normalized)) {
		normalized = 0;
	}

	var percent = Math.max(0, Math.min(1, normalized)) * 100;
	var className = 'rms-low';
	if (percent >= 66) {
		className = 'rms-high';
	} else if (percent >= 33) {
		className = 'rms-mid';
	}

	return {
		label: 'RMS OUT ' + rmsDb.toFixed(1) + ' dB',
		className: className,
		percent: percent
	};
}

function getConfigForDevice(deviceId)
{
	var base = getDefaultConfig(deviceId);
	var descriptor = getDeviceDescriptor(deviceId);
	var saved = deviceConfigsById[String(deviceId)] || {};
	if (!saved || typeof saved !== 'object' || Array.isArray(saved)) {
		saved = {};
	}
	if (Object.keys(saved).length === 0) {
		var serialLookup = normalizeAntennaSerial(String(descriptor.serial || getDeviceSerialForId(deviceId)));
		if (serialLookup !== '') {
			var serialKey = 'sn:' + serialLookup;
			if (Object.prototype.hasOwnProperty.call(deviceConfigsById, serialKey)) {
				var serialSaved = deviceConfigsById[serialKey];
				if (serialSaved && typeof serialSaved === 'object' && !Array.isArray(serialSaved)) {
					saved = serialSaved;
				}
			}
		}
	}
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
	merged.postGain = normalizeClientPostGainValue(merged.postGain);
	merged.autoGain = parseClientBooleanFlag(merged.autoGain, false) ? '1' : '0';
	merged.pipeOutputEnabled = parseClientBooleanFlag(merged.pipeOutputEnabled, false) ? '1' : '0';
	merged.pipeOutputs = normalizeClientPipeOutputEntries(merged.pipeOutputs);
	var legacyPipeOutputPresetId = String(merged.pipeOutputPresetId || '').trim();
	var combinedPipeOutputPresetIds = normalizePipeOutputPresetIdList(merged.pipeOutputPresetIds);
	if (legacyPipeOutputPresetId !== '' && combinedPipeOutputPresetIds.indexOf(legacyPipeOutputPresetId) === -1) {
		combinedPipeOutputPresetIds.push(legacyPipeOutputPresetId);
	}
	merged.pipeOutputPresetIds = combinedPipeOutputPresetIds;
	delete merged.pipeOutputPresetId;
	merged.dcs = normalizeClientDcsCode(merged.dcs);
	merged.ctcss = normalizeClientCtcssTone(merged.ctcss);
	merged.streamFormat = String(merged.streamFormat || 'mp3').toLowerCase() === 'ogg' ? 'ogg' : 'mp3';
	merged.streamBitrate = String(merged.streamBitrate || '128');
	merged.streamSampleRate = (['22050', '44100', '48000','96000'].indexOf(String(merged.streamSampleRate || '')) !== -1) ? String(merged.streamSampleRate) : '44100';
	merged.streamTarget = String(merged.streamTarget || '127.0.0.1:8000');
	merged.streamName = String(merged.streamName || '');
	var inferredMountFollowName = inferMountFollowsStreamName(merged.streamMount, merged.streamName, merged.streamFormat);
	var parsedMountFollowName = parseClientBooleanFlag(merged.streamMountFollowName, inferredMountFollowName);
	var mergedMountLinkMode = resolveMountLinkModeForConfig(merged, merged.deviceIndex);
	var mergedMountFollowDevice = mergedMountLinkMode === 'device';
	if (mergedMountLinkMode === 'name') {
		parsedMountFollowName = true;
	} else if (mergedMountLinkMode === 'manual') {
		parsedMountFollowName = false;
	}
	merged.streamMountLinkMode = mergedMountLinkMode;
	merged.streamMountFollowName = parsedMountFollowName;
	merged.streamMountFollowDevice = mergedMountFollowDevice;
	merged.streamMount = normalizeMountByFormat(
		merged.streamMount,
		merged.streamName,
		merged.streamFormat,
		merged.streamMountFollowName,
		merged.streamMountFollowDevice,
		merged.deviceIndex
	);
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
		var hasPipeMode = resolvePipeOutputsForConfig(config).length > 0;
		var stateClass = isRunning ? 'running' : 'stopped';
		var activeOutputLabel = isBothMode
			? 'Rec+Stream'
			: (isStreamMode ? 'Streaming' : (isRecordMode ? 'Recording' : 'No Output'));
		if (hasPipeMode) {
			activeOutputLabel = activeOutputLabel === 'No Output'
				? 'PIPE'
				: (activeOutputLabel + '+PIPE');
		}
		var stateLabel = isRunning ? activeOutputLabel : 'Stopped';
		var rxIndicator = getRxIndicator(deviceId, isRunning, config);
		var rmsDbIndicator = getRmsDbIndicator(deviceId, isRunning);
		var rmsDbPercentText = isFinite(rmsDbIndicator.percent) ? String(rmsDbIndicator.percent.toFixed(1)) : '0.0';
		var outputIndicator = getRmsIndicator(deviceId, isRunning);
		var outputPercentText = isFinite(outputIndicator.percent) ? String(outputIndicator.percent.toFixed(1)) : '0.0';
		var metaPid = isRunning ? String(instance.pid || '') : 'Idle';
		var metaLog = isRunning ? String(instance.logFile || '') : 'No active log';
		var configPanelClass = isConfigOpen(deviceId) ? 'device-config' : 'device-config collapsed';
		var logPanelClass = isLogOpen(deviceId) ? 'device-log-panel' : 'device-log-panel collapsed';
		var logToggleLabel = isLogOpen(deviceId) ? 'Collapse Logs' : 'Expand Logs';
		var logCache = logContentByDevice[deviceId] || {};
		var logMeta = logCache.logFile ? logCache.logFile : 'No log loaded';
		var pipelineTileMarkup = pipelineLinesMarkup(instance, config);
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
		var streamNameValue = String(config.streamName || '').trim();
		var deviceTitleText = 'Dev ' + displayIndex;
		if (streamNameValue !== '') {
			deviceTitleText += ' - ' + streamNameValue;
		}
		var mountLinkMode = resolveMountLinkModeForConfig(config, String(config.deviceIndex || displayIndex));
		var mountFollowDevice = mountLinkMode === 'device';
		var mountPlaceholder = mountFollowDevice
			? getDefaultMountForDeviceIndex(String(config.deviceIndex || displayIndex), String(config.streamFormat || 'mp3'))
			: getDefaultMountForName(String(config.streamName || ''), String(config.streamFormat || 'mp3'));
		var mountLinkOptions = '' +
			'<option value="name"' + (mountLinkMode === 'name' ? ' selected' : '') + '>Tie to Stream Name</option>' +
			'<option value="device"' + (mountLinkMode === 'device' ? ' selected' : '') + '>Link to Device</option>' +
			'<option value="manual"' + (mountLinkMode === 'manual' ? ' selected' : '') + '>Manual</option>';
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

		markup += '' +
			'<article class="panel device-card" data-device-id="' + escapeHtml(deviceId) + '">' +
				'<div class="device-header">' +
					'<div class="device-title-row">' +
						'<div class="device-title-line">' +
							'<h3 class="device-title">' + escapeHtml(deviceTitleText) + '</h3>' +
							'<div class="state-pills">' +
								'<div class="state-pill ' + stateClass + '">' + stateLabel + '</div>' +
								'<div class="state-pill state-pill-rx ' + rxIndicator.className + '">' + rxIndicator.label + '</div>' +
							'</div>' +
						'</div>' +
						'<div class="device-rms-stack">' +
							'<div class="device-rms-meter device-rms-meter-rms ' + rmsDbIndicator.className + '" aria-label="' + escapeHtml(rmsDbIndicator.label) + '">' +
								'<div class="device-rms-track"><div class="device-rms-fill" style="width:' + escapeHtml(rmsDbPercentText) + '%;"></div></div>' +
								'<div class="device-rms-label">' + escapeHtml(rmsDbIndicator.label) + '</div>' +
							'</div>' +
							'<div class="device-rms-meter device-rms-meter-output ' + outputIndicator.className + '" aria-label="' + escapeHtml(outputIndicator.label) + '">' +
								'<div class="device-rms-track"><div class="device-rms-fill" style="width:' + escapeHtml(outputPercentText) + '%;"></div></div>' +
								'<div class="device-rms-label">' + escapeHtml(outputIndicator.label) + '</div>' +
							'</div>' +
						'</div>' +
					'</div>' +
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
					'<div class="meta-box full"><div class="meta-label-row"><span class="meta-label">Pipeline</span><button type="button" class="refresh-button compact action-copy-pipeline">Copy Full Pipeline</button></div><span class="meta-value pipeline-lines">' + pipelineTileMarkup + '</span></div>' +
				'</div>' +
				'<div class="' + configPanelClass + '">' +
					'<div class="form-row single">' +
						'<div><label>Stream Name</label><input type="text" class="field-stream-name" value="' + escapeHtml(String(config.streamName || '')) + '" placeholder="Optional custom stream name"></div>' +
					'</div>' +
					'<div class="form-row">' +
						'<div><label>Frequency</label><input type="text" class="field-frequency" value="' + escapeHtml(config.frequency || '') + '" placeholder="146.520M or 118M:137M:25k or 146.435M 146.560M"></div>' +
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
							'<option value="3000">3 kHz</option>' +
							'<option value="6000">6 kHz</option>' +
							'<option value="12000">12 kHz</option>' +
							'<option value="24000">24 kHz</option>' +
							'<option value="48000">48 kHz</option>' +
							'<option value="96000">96 kHz</option>' +
							'<option value="170000">170 kHz</option>' +
						'</select></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>RTL Resample</label><select class="field-rtl-resample">' +
							'<option value="">Off (use bandwidth)</option>' +
							'<option value="3000">3 kHz</option>' +
							'<option value="6000">6 kHz</option>' +
							'<option value="12000">12 kHz</option>' +
							'<option value="24000">24 kHz</option>' +
							'<option value="48000">48 kHz</option>' +
							'<option value="96000">96 kHz</option>' +
							'<option value="170000">170 kHz</option>' +
						'</select></div>' +
					'</div>' +
					'<div class="form-row">' +
						'<div><label>Squelch</label><input type="number" step="1" inputmode="numeric" class="field-squelch" value="' + escapeHtml(String(config.squelch || '500')) + '" placeholder="non-zero integer" title="Must be a non-zero integer"></div>' +
						'<div><label>RTL Gain</label><input type="text" class="field-gain" value="' + escapeHtml(String(config.gain || '')) + '" placeholder="auto or number"></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>radio-pipe Input Dejitter (ms)</label><input type="number" min="0" step="1" class="field-input-dejitter" value="' + escapeHtml(String(config.inputDejitter || '')) + '" placeholder="Optional: 0 to disable"></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>DCS Gate</label><select class="field-dcs">' + buildDcsOptions(String(config.dcs || '')) + '</select></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>CTCSS Gate</label><select class="field-ctcss">' + buildCtcssOptions(String(config.ctcss || '')) + '</select></div>' +
					'</div>' +
					'<div class="form-row">' +
						'<div><label>Audio Threshold (dB)</label><input type="text" class="field-threshold" value="' + escapeHtml(String(config.threshold || '-40')) + '" placeholder="-40 recommended start"></div>' +
						'<div><label>Silence (sec)</label><input type="text" class="field-silence" value="' + escapeHtml(String(config.silence || '2')) + '"></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>Bias-T <span style="font-size:12px;opacity:0.8;">(device-local, not in templates)</span></label><select class="field-bias-t"><option value="0">Disabled</option><option value="1">Enabled</option></select></div>' +
					'</div>' +
					'<div class="form-row">' +
						'<div><label class="checkbox-label"><input type="checkbox" class="field-record-enabled"> Enable Recording</label></div>' +
						'<div><label class="checkbox-label"><input type="checkbox" class="field-stream-enabled"> Enable Streaming</label></div>' +
					'</div>' +
					'<div class="form-row">' +
						'<div><label>radio-pipe Gain (dB)</label><input type="text" class="field-post-gain" value="' + escapeHtml(String(config.postGain || '')) + '" placeholder="Optional: -60 to 60"></div>' +
						'<div>' +
							'<label class="checkbox-label"><input type="checkbox" class="field-auto-gain"' + (parseClientBooleanFlag(config.autoGain, false) ? ' checked' : '') + '> Enable radio-pipe Auto Gain</label>' +
							'<label class="checkbox-label"><input type="checkbox" class="field-voice-filter"' + (parseClientBooleanFlag(config.voiceFilter, false) ? ' checked' : '') + '> Enable radio-pipe Voice Filter</label>' +
							'<label class="checkbox-label"><input type="checkbox" class="field-deemphasis"' + (parseClientBooleanFlag(config.deemphasis, false) ? ' checked' : '') + '> Enable De-emphasis</label>' +
							'<div style="display:flex;align-items:center;gap:6px;margin-top:2px;">' +
								'<label style="margin:0;white-space:nowrap;">TAU (µs)</label>' +
								'<input type="number" min="0" max="1000" step="1" class="field-deemphasis-tau" value="' + escapeHtml(String(config.deemphasisTau || '75')) + '" style="width:80px;">' +
							'</div>' +
						'</div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>Pipe Outputs</label><div class="pipe-output-presets-list">' + buildPipeOutputPresetCheckboxesMarkup(config.pipeOutputPresetIds) + '</div></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><button type="button" class="refresh-button action-new-pipe-output-preset">New Pipe-Output</button></div>' +
					'</div>' +
					'<div class="form-row output-stream-only hidden">' +
						'<div><label>Stream Format</label><select class="field-stream-format"><option value="mp3">mp3</option><option value="ogg">ogg</option></select></div>' +
						'<div><label>Stream Bitrate (kbps)</label><input type="number" min="16" max="320" step="1" class="field-stream-bitrate" value="' + escapeHtml(String(config.streamBitrate || '128')) + '"></div>' +
					'</div>' +
					'<div class="form-row single output-stream-only hidden">' +
						'<div><label>Stream Sample Rate</label><select class="field-stream-sample-rate"><option value="22050">22050 Hz</option><option value="44100">44100 Hz</option><option value="48000">48000 Hz</option><option value="96000">96000 Hz</option></select></div>' +
					'</div>' +
					'<div class="form-row output-stream-only hidden">' +
						'<div style="flex: 1;"><label>Target Icecast Server</label><select class="field-stream-server-id">' + buildStreamServerOptions(String(config.streamServerId || '')) + '</select></div>' +
						'<div style="flex: 0 0 auto; display: flex; gap: 8px; align-items: flex-end;"><button type="button" class="refresh-button action-edit-server" style="padding: 6px 12px;">Edit</button><button type="button" class="refresh-button action-new-server" style="padding: 6px 12px;">New</button></div>' +
					'</div>' +
					'<div class="form-row output-stream-only hidden">' +
						'<div><label>Mount Point</label><input type="text" class="field-stream-mount" value="' + escapeHtml(String(config.streamMount || '')) + '" placeholder="' + escapeHtml(mountPlaceholder) + '"></div>' +
						'<div><label>Mount Link</label><select class="field-stream-mount-link-mode">' + mountLinkOptions + '</select></div>' +
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
					'<div class="form-row single template-button-row">' +
						'<div><button type="button" class="refresh-button primary action-apply-config">Apply</button></div>' +
					'</div>' +
					'<div class="form-row single">' +
						'<div><label>Template</label><select class="field-template-name">' + templateSelectOptions + '</select></div>' +
					'</div>' +
					'<div class="form-row template-button-row">' +
						'<div><button type="button" class="refresh-button action-load-template">Load Template</button></div>' +
						'<div><button type="button" class="refresh-button action-save-template">Save Template</button></div>' +
					'</div>' +
					'<div class="form-row single template-button-row">' +
						'<div><button type="button" class="refresh-button primary action-save-template-start">' + (isRunning ? 'Save Template + Retune' : 'Save Template + Start') + '</button></div>' +
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
	syncRadioPipeSockets();
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
	var persistedDeviceId = deviceSerial !== '' ? ('sn:' + deviceSerial) : deviceId;

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

	var streamNameInput = card.querySelector('.field-stream-name');
	var streamName = streamNameInput ? streamNameInput.value.trim() : '';
	var streamFormatField = card.querySelector('.field-stream-format');
	var streamFormat = streamFormatField ? streamFormatField.value.trim() : 'mp3';
	var streamMountInput = card.querySelector('.field-stream-mount');
	var streamMountLinkModeSelect = card.querySelector('.field-stream-mount-link-mode');
	var requestedMountLinkMode = streamMountLinkModeSelect ? normalizeMountLinkMode(streamMountLinkModeSelect.value) : '';
	var streamMountRaw = streamMountInput ? streamMountInput.value.trim() : '';
	if (requestedMountLinkMode === '') {
		if (inferMountFollowsDeviceIndex(streamMountRaw, deviceIndex, streamFormat)) {
			requestedMountLinkMode = 'device';
		} else if (inferMountFollowsStreamName(streamMountRaw, streamName, streamFormat)) {
			requestedMountLinkMode = 'name';
		} else {
			requestedMountLinkMode = 'manual';
		}
	}
	var streamMountFollowDevice = requestedMountLinkMode === 'device';
	var streamMountFollowName = requestedMountLinkMode === 'name' || streamMountFollowDevice;
	var streamMount = normalizeMountByFormat(
		streamMountRaw,
		streamName,
		streamFormat,
		streamMountFollowName,
		streamMountFollowDevice,
		deviceIndex
	);
	var selectedPipeOutputPresetIds = readPipeOutputPresetIdsFromCard(card);

	return {
		device: persistedDeviceId,
		deviceIndex: deviceIndex,
		deviceSerial: deviceSerial,
		frequency: card.querySelector('.field-frequency').value.trim(),
		mode: card.querySelector('.field-mode').value.trim(),
		rtlBandwidth: card.querySelector('.field-rtl-bandwidth').value.trim(),
		resampleRate: card.querySelector('.field-rtl-resample') ? card.querySelector('.field-rtl-resample').value.trim() : '',
		recordEnabled: !!(card.querySelector('.field-record-enabled') && card.querySelector('.field-record-enabled').checked),
		streamEnabled: !!(card.querySelector('.field-stream-enabled') && card.querySelector('.field-stream-enabled').checked),
		outputMode: deriveClientOutputModeLabel(
			!!(card.querySelector('.field-record-enabled') && card.querySelector('.field-record-enabled').checked),
			!!(card.querySelector('.field-stream-enabled') && card.querySelector('.field-stream-enabled').checked)
		),
		squelch: normalizeClientSquelchValue(card.querySelector('.field-squelch').value),
		gain: card.querySelector('.field-gain').value.trim(),
		inputDejitter: card.querySelector('.field-input-dejitter') ? card.querySelector('.field-input-dejitter').value.trim() : '',
		postGain: normalizeClientPostGainValue(card.querySelector('.field-post-gain').value),
		autoGain: !!(card.querySelector('.field-auto-gain') && card.querySelector('.field-auto-gain').checked) ? '1' : '0',
		voiceFilter: !!(card.querySelector('.field-voice-filter') && card.querySelector('.field-voice-filter').checked) ? '1' : '0',
		deemphasis: !!(card.querySelector('.field-deemphasis') && card.querySelector('.field-deemphasis').checked) ? '1' : '0',
		deemphasisTau: card.querySelector('.field-deemphasis-tau') ? card.querySelector('.field-deemphasis-tau').value.trim() : '75',
		pipeOutputEnabled: '1',
		pipeOutputs: [],
		pipeOutputPresetIds: selectedPipeOutputPresetIds,
		dcs: dcsCode,
		ctcss: ctcssTone,
		biasT: card.querySelector('.field-bias-t').value.trim(),
		threshold: card.querySelector('.field-threshold').value.trim(),
		silence: card.querySelector('.field-silence').value.trim(),
		streamFormat: streamFormat,
		streamBitrate: card.querySelector('.field-stream-bitrate').value.trim(),
		streamSampleRate: card.querySelector('.field-stream-sample-rate') ? card.querySelector('.field-stream-sample-rate').value.trim() : '44100',
		streamTarget: streamTarget,
		streamMount: streamMount,
		streamMountLinkMode: requestedMountLinkMode,
		streamMountFollowName: streamMountFollowName,
		streamMountFollowDevice: streamMountFollowDevice,
		streamUsername: streamUsername,
		streamPassword: streamPassword,
		streamServerId: streamServerId,
		streamName: streamName,
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

function buildRuntimeConfigForSubmission(config)
{
	var runtimeConfig = Object.assign({}, config || {});
	var selectedPresetIds = normalizePipeOutputPresetIdList(runtimeConfig.pipeOutputPresetIds);
	var runtime = resolvePipeOutputRuntimeConfig(runtimeConfig, selectedPresetIds);
	runtimeConfig.pipeOutputs = runtime.entries;
	runtimeConfig.pipeOutputRaw = runtime.raw.enabled ? '1' : '0';
	runtimeConfig.pipeOutputBits = runtime.raw.bits;
	runtimeConfig.pipeOutputChannels = runtime.raw.channels;
	runtimeConfig.pipeOutputRate = runtime.raw.rate;
	runtimeConfig.pipeOutputUnsigned = runtime.raw.unsigned ? '1' : '0';
	runtimeConfig.pipeOutputBigEndian = runtime.raw.bigEndian ? '1' : '0';
	runtimeConfig.pipeOutputPad = runtime.raw.pad ? '1' : '0';
	runtimeConfig.pipeOutputPadDelay = runtime.raw.padDelay;
	return runtimeConfig;
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

	var openLogDeviceIds = [];
	var visibleDevices = collectVisibleDevices();
	for (var i = 0; i < visibleDevices.length; i++) {
		var visibleId = normalizeClientDeviceId(visibleDevices[i].id || visibleDevices[i].index || '');
		if (visibleId !== '' && isLogOpen(visibleId)) {
			openLogDeviceIds.push(visibleId);
		}
	}
	if (!openLogDeviceIds.length) {
		refreshOpenLogsRenderRequested = false;
		refreshOpenLogsQueued = false;
		return Promise.resolve();
	}

	refreshOpenLogsInFlightPromise = fetchDeviceLogsBatch(openLogDeviceIds).then(function () {
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
	var resampleSelect = card.querySelector('.field-rtl-resample');
	var biasTSelect = card.querySelector('.field-bias-t');
	var dcsSelect = card.querySelector('.field-dcs');
	var ctcssSelect = card.querySelector('.field-ctcss');
	var recordEnabledCheckbox = card.querySelector('.field-record-enabled');
	var streamEnabledCheckbox = card.querySelector('.field-stream-enabled');
	var streamFormatSelect = card.querySelector('.field-stream-format');
	var streamNameInput = card.querySelector('.field-stream-name');
	var streamMountLinkModeSelect = card.querySelector('.field-stream-mount-link-mode');
	var afterRecordSelect = card.querySelector('.field-after-record-action');
	var recordingServerSelect = card.querySelector('.field-recording-server-id');
	var postCommandArgInput = card.querySelector('.field-post-command-arg');
	var postGainInput = card.querySelector('.field-post-gain');
	var autoGainCheckbox = card.querySelector('.field-auto-gain');
	var voiceFilterCheckbox = card.querySelector('.field-voice-filter');
	var pipeOutputPresetsList = card.querySelector('.pipe-output-presets-list');
	var newPipeOutputPresetButton = card.querySelector('.action-new-pipe-output-preset');
	var storedConfig = getConfigForDevice(deviceId);
	var selectedPresetIds = normalizePipeOutputPresetIdList(storedConfig.pipeOutputPresetIds);
	modeSelect.value = storedConfig.mode || 'fm';
	bandwidthSelect.value = String(storedConfig.rtlBandwidth || '12000');
	if (resampleSelect) {
		resampleSelect.value = String(storedConfig.resampleRate || '');
	}
	if (postGainInput) {
		postGainInput.value = normalizeClientPostGainValue(storedConfig.postGain);
	}
	if (autoGainCheckbox) {
		autoGainCheckbox.checked = parseClientBooleanFlag(storedConfig.autoGain, false);
	}
	if (voiceFilterCheckbox) {
		voiceFilterCheckbox.checked = parseClientBooleanFlag(storedConfig.voiceFilter, false);
	}
	var deemphasisCheckbox = card.querySelector('.field-deemphasis');
	if (deemphasisCheckbox) {
		deemphasisCheckbox.checked = parseClientBooleanFlag(storedConfig.deemphasis, false);
	}
	var deemphasisTauInput = card.querySelector('.field-deemphasis-tau');
	if (deemphasisTauInput) {
		deemphasisTauInput.value = String(storedConfig.deemphasisTau || '75');
	}
	if (pipeOutputPresetsList) {
		pipeOutputPresetsList.innerHTML = buildPipeOutputPresetCheckboxesMarkup(selectedPresetIds);
	}
	syncPipeOutputFields();
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
	var streamSampleRateSelect = card.querySelector('.field-stream-sample-rate');
	if (streamSampleRateSelect) {
		streamSampleRateSelect.value = String(storedConfig.streamSampleRate || '44100');
	}
	if (streamMountLinkModeSelect) {
		streamMountLinkModeSelect.value = resolveMountLinkModeForConfig(storedConfig, storedConfig.deviceIndex || getDeviceIndexForId(deviceId));
	}
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
	if (pipeOutputPresetsList) {
		pipeOutputPresetsList.addEventListener('change', function (event) {
			var target = event.target;
			if (target && target.classList && target.classList.contains('field-pipe-output-preset-toggle')) {
				// Sync record/stream validation when pipe outputs change
				syncOutputModeFields(card);
				persistCardConfig(card);
			}
		});
		pipeOutputPresetsList.addEventListener('click', function (event) {
			var target = event.target;
			if (!target || !target.classList || !target.classList.contains('action-edit-pipe-output-preset')) {
				return;
			}
			var presetId = String(target.getAttribute('data-preset-id') || '').trim();
			if (presetId !== '') {
				openPipeOutputPresetDialog(presetId);
			}
		});
	}
	if (newPipeOutputPresetButton) {
		newPipeOutputPresetButton.addEventListener('click', function () {
			openPipeOutputPresetDialog(null);
		});
	}
	streamFormatSelect.addEventListener('change', function () {
		syncMountWithStreamFormat(card, true);
	});
	if (streamNameInput) {
		streamNameInput.addEventListener('input', function () {
			syncMountWithStreamFormat(card, true);
		});
		streamNameInput.addEventListener('change', function () {
			syncMountWithStreamFormat(card, true);
		});
	}
	if (streamMountLinkModeSelect) {
		streamMountLinkModeSelect.addEventListener('change', function () {
			syncMountWithStreamFormat(card, true);
		});
	}
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
		var nextOpenState = !isLogOpen(deviceId);
		openLogPanelsByDevice[deviceId] = nextOpenState;
		renderDeviceList();
		if (nextOpenState) {
			refreshOpenLogs(false);
		}
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

	var copyPipelineButton = card.querySelector('.action-copy-pipeline');
	if (copyPipelineButton) {
		copyPipelineButton.addEventListener('click', function () {
			copyFullPipelineForCard(card);
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
		saveTemplateStartButton.textContent = knownInstancesByDevice[deviceId] ? 'Save Template + Retune' : 'Save Template + Start';
		saveTemplateStartButton.addEventListener('click', function () {
			saveTemplateFromCard(true);
		});
	}

	var applyConfigButton = card.querySelector('.action-apply-config');
	if (applyConfigButton) {
		applyConfigButton.addEventListener('click', function () {
			if (knownInstancesByDevice[deviceId]) {
				startOrRetuneCard(card);
				return;
			}
			var config = readCardConfig(card);
			deviceConfigsById[String(deviceId)] = config;
			saveDeviceConfigs(String(deviceId));
			openConfigPanelsByDevice[String(deviceId)] = false;
			renderDeviceList();
			setStatus('Applied config changes for device ' + deviceId + '.', false);
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
				applyTemplateToDevice(deviceId, templateName, readCardConfig(card));
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
		syncRadioPipeSockets();
		applyUiSettingsFromServerPayload(result && result.settings ? result.settings : null);
		updateQueueStatusFromServer(result && result.queue ? result.queue : null);
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
	var normalizedDeviceId = normalizeClientDeviceId(String(config.device || '').trim());
	var requestedAction = knownInstancesByDevice[normalizedDeviceId] ? 'retune' : 'start';
	setStatus((requestedAction === 'retune' ? 'Queueing retune for device ' : 'Applying config for device ') + (config.device || '?') + '...', false);

	var postActionWithConfig = function (actionConfig) {
		return postUserAction(requestedAction, actionConfig).then(function (result) {
		if (result && result.queued) {
			updateQueueStatusFromServer({
				pending: Number(result.queuePosition || 0),
				busy: false,
				processed: 0,
				lastProcessedAt: queueStatusState && queueStatusState.lastProcessedAt ? queueStatusState.lastProcessedAt : 0,
				lastResult: queueStatusState && queueStatusState.lastResult ? queueStatusState.lastResult : null
			});
			setStatus(result.message || ('RETUNE queued for device ' + (actionConfig.device || '?') + '.'), false);
			refreshInstances();
			return;
		}

		setStatus(result.message || 'Started.', false);
		knownInstancesByDevice = {};
		for (var i = 0; i < result.instances.length; i++) {
			knownInstancesByDevice[String(result.instances[i].device)] = result.instances[i];
		}
		syncRadioPipeSockets();
		fetchDeviceLogs(String(actionConfig.device), true).then(function () {
			renderDeviceList();
		});
	}).catch(function (error) {
		setStatus(error.message, true);
	});
	};

	if (requestedAction !== 'retune') {
		postActionWithConfig(buildRuntimeConfigForSubmission(config));
		return;
	}

	saveUiSettingsNow().catch(function () {
		return null;
	}).finally(function () {
		var latestConfig = Object.assign({}, getConfigForDevice(normalizedDeviceId), readCardConfig(card));
		latestConfig.device = String(config.device || latestConfig.device || normalizedDeviceId);
		postActionWithConfig(buildRuntimeConfigForSubmission(latestConfig));
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
		syncRadioPipeSockets();
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

	var currentConfig = readCardConfig(card);
	var resetConfig = buildResetConfig(deviceId, currentConfig);

	var resetToDefaults = function () {
		if (resetConfig.preserveDeviceMountLink) {
			deviceConfigsById[deviceId] = resetConfig.config;
			saveDeviceConfigs(deviceId);
		} else {
			delete deviceConfigsById[deviceId];
			saveDeviceConfigs(deviceId, { remove: true });
		}
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
		syncRadioPipeSockets();
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
		syncRadioPipeSockets();
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

	document.getElementById('toggleTemplateToolbarButton').addEventListener('click', openTemplateDialog);
	document.getElementById('templateModalClose').addEventListener('click', closeTemplateDialog);
	document.getElementById('templateModalCancel').addEventListener('click', closeTemplateDialog);
	document.getElementById('templateModal').addEventListener('click', function (e) {
		if (e.target === this) {
			closeTemplateDialog();
		}
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
			syncRadioPipeSockets();
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

		closeTemplateDialog();

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
					return postUserAction('start', buildRuntimeConfigForSubmission(config)).then(function (result) {
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
				syncRadioPipeSockets();
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

		closeTemplateDialog();

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

	document.getElementById('deleteGlobalTemplateButton').addEventListener('click', function () {
		var templateSelect = document.getElementById('globalTemplateSelect');
		var templateName = templateSelect ? String(templateSelect.value || '').trim() : '';
		if (templateName === '') {
			setStatus('Select a template to delete.', true);
			return;
		}

		if (!window.confirm('Delete template "' + templateName + '"?')) {
			return;
		}

		deleteTemplateByName(templateName).catch(function (error) {
			setStatus(error && error.message ? error.message : 'Failed to delete template.', true);
		});
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

	document.getElementById('pipeOutputPresetModalClose').addEventListener('click', closePipeOutputPresetDialog);
	document.getElementById('pipeOutputPresetModalCancel').addEventListener('click', closePipeOutputPresetDialog);
	document.getElementById('pipeOutputPresetModalSave').addEventListener('click', savePipeOutputPreset);
	document.getElementById('pipeOutputPresetModalDelete').addEventListener('click', deletePipeOutputPreset);
	document.getElementById('pipeOutputPresetModal').addEventListener('click', function (e) {
		if (e.target === this) {
			closePipeOutputPresetDialog();
		}
	});
	document.getElementById('pipeOutputPresetModalEntries').addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
			e.preventDefault();
			savePipeOutputPreset();
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
	renderQueueStatus();

	Promise.all([loadUiSettingsFromServer(), loadTemplatesFromServer()]).finally(function () {
		refreshGlobalTemplateSelector();
		refreshTemplateDeviceSelector();
		refreshPipeOutputPresetSelects();
		renderDeviceList();
		Promise.all([loadStreamServersFromServer(), loadRecordingServersFromServer()]).finally(function () {
			refreshGlobalTemplateSelector();
			refreshTemplateDeviceSelector();
			refreshPipeOutputPresetSelects();
			renderDeviceList();
			scanDevices().finally(function () {
			refreshInstances();
			});
		});
	});
	window.setInterval(refreshInstances, 6000);
	window.setInterval(refreshOpenLogs, 2500);
	window.setInterval(pollRadioPipeStatusViaProxy, RADIO_PIPE_STATUS_PROXY_POLL_MS);
}

initializePage();
</script>
</body>
</html>
