<?php

// Settings
$recordingsRoot = '/mnt/Media/recordings';
$PAGE_TITLE = 'Icecast Stream Recordings';

// End Settings


function sendJsonResponse(array $payload, int $statusCode = 200): void
{
	http_response_code($statusCode);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-cache, no-store, must-revalidate');
	echo json_encode($payload);
	exit;
}

function normalizeRelativePath(string $relativePath): string
{
	$relativePath = str_replace('\\', '/', $relativePath);
	$parts = explode('/', ltrim($relativePath, '/'));
	$safeParts = array();

	foreach ($parts as $part) {
		if ($part === '' || $part === '.') {
			continue;
		}

		if ($part === '..') {
			return '';
		}

		$safeParts[] = $part;
	}

	return implode('/', $safeParts);
}

function formatBytes(int $bytes): string
{
	$units = array('B', 'KB', 'MB', 'GB', 'TB');
	$size = (float)$bytes;
	$unitIndex = 0;

	while ($size >= 1024 && $unitIndex < (count($units) - 1)) {
		$size /= 1024;
		$unitIndex++;
	}

	if ($unitIndex === 0) {
		return (string)((int)$size) . ' ' . $units[$unitIndex];
	}

	return number_format($size, 2) . ' ' . $units[$unitIndex];
}

function parseRecordingTimestamp(string $relativePath, int $fallbackTimestamp): int
{
	$fileName = basename($relativePath);

	if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})/', $fileName, $matches)) {
		$parsed = strtotime($matches[1] . ' ' . $matches[2] . ':' . $matches[3] . ':' . $matches[4]);
		if ($parsed !== false) {
			return (int)$parsed;
		}
	}

	$folderName = basename(dirname($relativePath));
	if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $folderName)) {
		$parsed = strtotime($folderName . ' 00:00:00');
		if ($parsed !== false) {
			return (int)$parsed;
		}
	}

	return $fallbackTimestamp;
}

function prettifyRecordingName(string $fileName): string
{
	$nameWithoutExtension = (string)pathinfo($fileName, PATHINFO_FILENAME);
	if ($nameWithoutExtension === '') {
		return $fileName;
	}

	$label = $nameWithoutExtension;
	if (preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}_(.+)$/', $nameWithoutExtension, $matches)) {
		$label = (string)$matches[1];
	}

	$label = str_replace('_', ' ', $label);
	$label = preg_replace('/\s+/', ' ', (string)$label);
	$label = trim((string)$label);

	if ($label === '') {
		return $nameWithoutExtension;
	}

	return $label;
}

function formatDuration(?float $seconds): string
{
	if ($seconds === null || $seconds <= 0) {
		return '?:??';
	}

	$totalSeconds = (int)round($seconds);
	$hours = intdiv($totalSeconds, 3600);
	$minutes = intdiv($totalSeconds % 3600, 60);
	$remainingSeconds = $totalSeconds % 60;

	if ($hours > 0) {
		return sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds);
	}

	return sprintf('%d:%02d', $minutes, $remainingSeconds);
}

function estimateWavDurationSeconds(string $fullPath, int $fileSize): ?float
{
	$handle = fopen($fullPath, 'rb');
	if ($handle === false) {
		return null;
	}

	$riffHeader = fread($handle, 12);
	if ($riffHeader === false || strlen($riffHeader) < 12) {
		fclose($handle);
		return null;
	}

	if (substr($riffHeader, 0, 4) !== 'RIFF' || substr($riffHeader, 8, 4) !== 'WAVE') {
		fclose($handle);
		return null;
	}

	$byteRate = null;
	$dataSize = null;

	for ($chunkCount = 0; $chunkCount < 200 && !feof($handle); $chunkCount++) {
		$chunkHeader = fread($handle, 8);
		if ($chunkHeader === false || strlen($chunkHeader) < 8) {
			break;
		}

		$chunkId = substr($chunkHeader, 0, 4);
		$chunkSizeData = unpack('VchunkSize', substr($chunkHeader, 4, 4));
		$chunkSize = isset($chunkSizeData['chunkSize']) ? (int)$chunkSizeData['chunkSize'] : 0;
		if ($chunkSize < 0) {
			break;
		}

		if ($chunkId === 'fmt ') {
			$bytesToRead = min($chunkSize, 32);
			$fmtData = $bytesToRead > 0 ? fread($handle, $bytesToRead) : '';

			if ($fmtData !== false && strlen($fmtData) >= 12) {
				$byteRateData = unpack('VbyteRate', substr($fmtData, 8, 4));
				if (isset($byteRateData['byteRate']) && (int)$byteRateData['byteRate'] > 0) {
					$byteRate = (int)$byteRateData['byteRate'];
				}
			}

			if ($chunkSize > $bytesToRead) {
				fseek($handle, $chunkSize - $bytesToRead, SEEK_CUR);
			}
		} elseif ($chunkId === 'data') {
			$dataSize = $chunkSize;
			if ($chunkSize > 0) {
				fseek($handle, $chunkSize, SEEK_CUR);
			}
		} else {
			if ($chunkSize > 0) {
				fseek($handle, $chunkSize, SEEK_CUR);
			}
		}

		if (($chunkSize % 2) === 1) {
			fseek($handle, 1, SEEK_CUR);
		}

		if ($byteRate !== null && $dataSize !== null) {
			break;
		}
	}

	fclose($handle);

	if ($byteRate === null || $byteRate <= 0) {
		return null;
	}

	if ($dataSize !== null && $dataSize > 0) {
		return (float)$dataSize / $byteRate;
	}

	if ($fileSize > 0) {
		return (float)$fileSize / $byteRate;
	}

	return null;
}

function listRecordings(string $rootDirectory): array
{
	$realRoot = realpath($rootDirectory);
	if ($realRoot === false || !is_dir($realRoot)) {
		return array(
			'ok' => false,
			'error' => 'Recordings directory is not available: ' . $rootDirectory,
			'count' => 0,
			'groups' => array(),
		);
	}

	$items = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($realRoot, FilesystemIterator::SKIP_DOTS)
	);

	foreach ($iterator as $fileInfo) {
		if (!$fileInfo->isFile()) {
			continue;
		}

		if (strtolower((string)$fileInfo->getExtension()) !== 'wav') {
			continue;
		}

		$fullPath = $fileInfo->getPathname();
		$relativePath = substr($fullPath, strlen($realRoot) + 1);
		$fileName = $fileInfo->getBasename();
		$modifiedAt = (int)$fileInfo->getMTime();
		$parsedTimestamp = parseRecordingTimestamp($relativePath, $modifiedAt);
		$sizeBytes = (int)$fileInfo->getSize();
		$durationSeconds = estimateWavDurationSeconds($fullPath, $sizeBytes);

		$items[] = array(
			'path' => $relativePath,
			'name' => $fileName,
			'name_pretty' => prettifyRecordingName($fileName),
			'timestamp' => $parsedTimestamp,
			'timestamp_iso' => date('Y-m-d H:i:s', $parsedTimestamp),
			'date' => date('Y-m-d', $parsedTimestamp),
			'time_display' => date('H:i:s', $parsedTimestamp),
			'mtime' => $modifiedAt,
			'size_bytes' => $sizeBytes,
			'size_human' => formatBytes($sizeBytes),
			'duration_seconds' => $durationSeconds,
			'duration_display' => formatDuration($durationSeconds),
		);
	}

	usort($items, function (array $left, array $right): int {
		if ($left['timestamp'] === $right['timestamp']) {
			return strcmp($right['path'], $left['path']);
		}

		return $right['timestamp'] <=> $left['timestamp'];
	});

	$groupMap = array();
	foreach ($items as $item) {
		$groupDate = $item['date'];
		if (!isset($groupMap[$groupDate])) {
			$groupMap[$groupDate] = array();
		}

		$groupMap[$groupDate][] = $item;
	}

	$groups = array();
	foreach ($groupMap as $groupDate => $groupItems) {
		$groups[] = array(
			'date' => $groupDate,
			'items' => $groupItems,
		);
	}

	return array(
		'ok' => true,
		'generated_at' => time(),
		'count' => count($items),
		'groups' => $groups,
	);
}

function streamRecording(string $rootDirectory, string $requestedPath, bool $forceDownload = false): void
{
	$realRoot = realpath($rootDirectory);
	if ($realRoot === false || !is_dir($realRoot)) {
		http_response_code(500);
		echo 'Recordings directory is not available.';
		exit;
	}

	$normalizedPath = normalizeRelativePath($requestedPath);
	if ($normalizedPath === '') {
		http_response_code(400);
		echo 'Invalid file path.';
		exit;
	}

	$fullPath = realpath($realRoot . DIRECTORY_SEPARATOR . $normalizedPath);
	if ($fullPath === false || !is_file($fullPath)) {
		http_response_code(404);
		echo 'Recording not found.';
		exit;
	}

	if (strpos($fullPath, $realRoot . DIRECTORY_SEPARATOR) !== 0) {
		http_response_code(403);
		echo 'Access denied.';
		exit;
	}

	if (strtolower((string)pathinfo($fullPath, PATHINFO_EXTENSION)) !== 'wav') {
		http_response_code(400);
		echo 'Only WAV files are supported.';
		exit;
	}

	$fileSize = filesize($fullPath);
	if ($fileSize === false) {
		http_response_code(500);
		echo 'Unable to read recording file.';
		exit;
	}

	$size = (int)$fileSize;
	$rangeStart = 0;
	$rangeEnd = $size - 1;
	$statusCode = 200;

	if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/i', $_SERVER['HTTP_RANGE'], $matches)) {
		if ($matches[1] !== '') {
			$rangeStart = (int)$matches[1];
		}

		if ($matches[2] !== '') {
			$rangeEnd = (int)$matches[2];
		}

		if ($matches[1] === '' && $matches[2] !== '') {
			$suffixLength = (int)$matches[2];
			if ($suffixLength > 0) {
				$rangeStart = max(0, $size - $suffixLength);
				$rangeEnd = $size - 1;
			}
		}

		if ($rangeStart > $rangeEnd || $rangeStart >= $size) {
			header('Content-Range: bytes */' . $size);
			http_response_code(416);
			exit;
		}

		$rangeEnd = min($rangeEnd, $size - 1);
		$statusCode = 206;
	}

	$contentLength = ($rangeEnd - $rangeStart) + 1;

	header('Content-Type: audio/wav');
	header('Accept-Ranges: bytes');
	header('Cache-Control: no-cache, no-store, must-revalidate');
	header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . basename($fullPath) . '"');

	if ($statusCode === 206) {
		http_response_code(206);
		header('Content-Range: bytes ' . $rangeStart . '-' . $rangeEnd . '/' . $size);
	} else {
		http_response_code(200);
	}

	header('Content-Length: ' . $contentLength);

	$handle = fopen($fullPath, 'rb');
	if ($handle === false) {
		http_response_code(500);
		echo 'Unable to stream recording file.';
		exit;
	}

	set_time_limit(0);
	fseek($handle, $rangeStart);

	$remaining = $contentLength;
	$chunkSize = 8192;

	while (!feof($handle) && $remaining > 0) {
		$readLength = ($remaining > $chunkSize) ? $chunkSize : $remaining;
		$buffer = fread($handle, $readLength);

		if ($buffer === false || $buffer === '') {
			break;
		}

		echo $buffer;
		flush();
		$remaining -= strlen($buffer);

		if (connection_aborted()) {
			break;
		}
	}

	fclose($handle);
	exit;
}

$ajaxAction = isset($_GET['ajax']) ? (string)$_GET['ajax'] : '';
if ($ajaxAction === 'list') {
	$payload = listRecordings($recordingsRoot);
	if ($payload['ok'] !== true) {
		sendJsonResponse($payload, 500);
	}

	sendJsonResponse($payload, 200);
}

if ($ajaxAction === 'stream') {
	$requestedFile = isset($_GET['file']) ? (string)$_GET['file'] : '';
	$forceDownload = isset($_GET['download']) && (string)$_GET['download'] === '1';
	streamRecording($recordingsRoot, $requestedFile, $forceDownload);
}

?><!DOCTYPE html>
<html>
<head>
	<title><?=isset($PAGE_TITLE) ? $PAGE_TITLE : $_SERVER['HTTP_HOST']?></title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="apple-touch-fullscreen" content="yes">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="keywords" content="openstatic,midi,automation,java" />
</head>
<body class="theme-dark">
<div class="recordings-page-wrap">

<style type="text/css">
	body {
		margin: 0;
		background-color: #111111;
		color: #e0e0e0;
		font-family: Arial, Helvetica, sans-serif;
	}

	a {
		color: #8ebdff;
	}

	a:hover {
		color: #b6d5ff;
	}

	h2 {
		margin-top: 0;
		color: #f1f1f1;
	}

	body.theme-light {
		background-color: #f3f5f8;
		color: #1f1f1f;
	}

	body.theme-light a {
		color: #1f5fae;
	}

	body.theme-light a:hover {
		color: #2d75cf;
	}

	body.theme-light h2 {
		color: #1f1f1f;
	}

	.recordings-panel {
		border: 1px solid #343434;
		border-radius: 6px;
		background-color: #1b1b1b;
	}

	body.theme-light .recordings-panel {
		border-color: #c9d1db;
		background-color: #ffffff;
	}

	.recordings-list-wrap {
		height: 420px;
		min-height: 220px;
		overflow-x: auto;
		overflow-y: auto;
		-webkit-overflow-scrolling: touch;
		padding: 8px;
		background-color: #171717;
	}

	body.theme-light .recordings-list-wrap {
		background-color: #f9fbff;
	}

	.recording-date {
		margin-top: 8px;
		margin-bottom: 4px;
		padding: 4px 8px;
		background-color: #2a2a2a;
		color: #f2f2f2;
		border-radius: 4px;
		font-weight: bold;
	}

	body.theme-light .recording-date {
		background-color: #e8edf3;
		color: #1f2a34;
	}

	.recording-table {
		width: 100%;
		min-width: 760px;
		border-collapse: collapse;
		table-layout: fixed;
		margin-bottom: 8px;
	}

	.recording-table th {
		border-bottom: 1px solid #434343;
		padding: 5px 6px;
		font-size: 12px;
		font-weight: bold;
		background-color: #242424;
		color: #e9e9e9;
	}

	body.theme-light .recording-table th {
		border-bottom-color: #c9d1db;
		background-color: #eef2f7;
		color: #202a33;
	}

	.recording-table thead th {
		position: sticky;
		top: 0;
		z-index: 4;
	}

	.recording-table td {
		border-bottom: 1px dotted #3a3a3a;
		padding: 5px 6px;
		font-size: 14px;
		color: #dddddd;
	}

	body.theme-light .recording-table td {
		border-bottom-color: #d5dce5;
		color: #1f2a34;
	}

	.recording-row {
		cursor: pointer;
	}

	.recording-row:hover {
		background-color: #1b2a38;
	}

	body.theme-light .recording-row:hover {
		background-color: #e7f1ff;
	}

	.selected-recording {
		background-color: #26415e !important;
	}

	body.theme-light .selected-recording {
		background-color: #d4e7ff !important;
	}

	.recording-time {
		width: 85px;
		white-space: nowrap;
		font-family: monospace;
	}

	.recording-date-col {
		width: 110px;
		white-space: nowrap;
		font-family: monospace;
	}

	.recording-size {
		width: 90px;
		text-align: right;
		white-space: nowrap;
	}

	.recording-duration {
		width: 72px;
		text-align: right;
		white-space: nowrap;
		font-family: monospace;
	}

	.recording-download {
		width: 92px;
		text-align: center;
		white-space: nowrap;
	}

	.recording-name {
		width: 38%;
		max-width: 38%;
		word-break: break-word;
	}

	#audioPlayer {
		width: 100%;
        margin-top: 10px;
        margin-bottom: 10px;
        color: #f2f2f2;
	}

	.status-row {
		margin-bottom: 8px;
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 8px;
	}

	.recordings-controls {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 10px;
		padding: 8px;
		border-bottom: 1px solid #343434;
		background-color: #202020;
	}

	body.theme-light .recordings-controls {
		border-bottom-color: #d5dce5;
		background-color: #f3f7fc;
	}

	.recordings-filter-label {
		font-size: 13px;
		white-space: nowrap;
	}

	.recordings-filter-input {
		flex: 1 1 280px;
		min-height: 34px;
		padding: 6px 8px;
		border: 1px solid #4a4a4a;
		border-radius: 4px;
		background-color: #141414;
		color: #efefef;
	}

	body.theme-light .recordings-filter-input {
		border-color: #b9c4d1;
		background-color: #ffffff;
		color: #1f2a34;
	}

	.autoplay-new-label {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		font-size: 13px;
		white-space: nowrap;
	}

	.status-text {
		flex: 1 1 240px;
	}

	.refresh-button {
		min-height: 34px;
		padding: 6px 10px;
		white-space: nowrap;
		border: 1px solid #4a4a4a;
		border-radius: 4px;
		background-color: #2a2a2a;
		color: #f2f2f2;
		cursor: pointer;
	}

	body.theme-light .refresh-button {
		border-color: #b9c4d1;
		background-color: #ffffff;
		color: #1f2a34;
	}

	.refresh-button:hover {
		background-color: #353535;
	}

	body.theme-light .refresh-button:hover {
		background-color: #edf3fb;
	}

	.theme-button {
		min-width: 120px;
	}

	.recordings-layout {
		display: block;
	}

	.recordings-list-col {
		width: 100%;
	}

	.recordings-player-col {
		width: 100%;
		margin-bottom: 10px;
	}

	#downloadLink {
		display: inline-block;
		margin-top: 6px;
	}

	#selectedTitle,
	#selectedMeta {
		word-break: break-word;
	}

	.recordings-page-wrap {
		max-width: 1200px;
		margin: 0 auto;
		padding: 10px;
	}

	@media (max-width: 991px) {
		.recordings-list-col,
		.recordings-player-col {
			width: 100%;
		}

		.recordings-list-wrap {
			padding: 6px;
		}

		.recording-table th {
			font-size: 11px;
			padding: 6px 4px;
		}

		.recording-table td {
			font-size: 13px;
			padding: 6px 4px;
		}
	}

	@media (max-width: 767px) {
		.recording-date-col,
		.recording-size {
			display: none;
		}

		.recording-table {
			min-width: 560px;
		}

		.recording-download {
			width: 76px;
		}
	}

	@media (max-width: 575px) {
		.recordings-page-wrap {
			padding: 8px;
		}

		.recordings-controls {
			align-items: stretch;
		}

		.recordings-filter-label,
		.autoplay-new-label {
			width: 100%;
		}

		.recordings-filter-input {
			width: 100%;
			flex: 1 1 100%;
		}

		.recording-table {
			min-width: 500px;
		}

		.refresh-button {
			width: 100%;
		}
	}
</style>

<h2><?=$PAGE_TITLE?></h2>
<div class="status-row">
	<span id="statusText" class="status-text">Loading recordings...</span>
	<button type="button" class="refresh-button" onclick="refreshRecordings(true)">Refresh Now</button>
	<button type="button" id="themeToggleButton" class="refresh-button theme-button" onclick="toggleTheme()">Theme: Dark</button>
</div>


<div class="recordings-layout">
	<div class="recordings-player-col">
		<div class="recordings-panel" style="padding: 10px;">
			<b id="selectedTitle">No recording selected</b><br />
			<small id="selectedMeta">Select a recording to begin playback.</small>
			<br />
			<audio id="audioPlayer" controls preload="metadata"></audio>
			<br />
			<a id="downloadLink" href="#" style="display: none;">Download selected recording</a>
		</div>
	</div>

	<div class="recordings-list-col">
		<div class="recordings-panel">
			<div class="recordings-controls">
				<label for="recordingsFilterInput" class="recordings-filter-label">Filter by name:</label>
				<input type="text" id="recordingsFilterInput" class="recordings-filter-input" placeholder="Type to filter recordings..." autocomplete="off" />
				<label class="autoplay-new-label" for="autoPlayNewCheckbox"><input type="checkbox" id="autoPlayNewCheckbox" /> Auto play new recordings</label>
			</div>
			<div class="recordings-list-wrap" id="recordingsList"></div>
		</div>
	</div>
</div>

<script type="text/javascript">
var selectedPath = null;
var recordingsByPath = {};
var allRecordingsGroups = [];
var refreshInProgress = false;
var refreshEveryMs = 8000;
var refreshTimerId = null;
var hasLoadedRecordingsOnce = false;
var pendingAutoPlayPaths = [];
var shownRecordingsSizeBytes = 0;
var totalRecordingsSizeBytes = 0;
var lastStatusMessage = '';
var lastStatusIsError = false;

function applyTheme(themeName)
{
	var theme = (themeName === 'theme-light') ? 'theme-light' : 'theme-dark';
	document.body.classList.remove('theme-dark');
	document.body.classList.remove('theme-light');
	document.body.classList.add(theme);

	var themeToggleButton = document.getElementById('themeToggleButton');
	if (themeToggleButton) {
		themeToggleButton.textContent = (theme === 'theme-light') ? 'Theme: Light' : 'Theme: Dark';
	}
}

function toggleTheme()
{
	var nextTheme = document.body.classList.contains('theme-light') ? 'theme-dark' : 'theme-light';
	applyTheme(nextTheme);
	try {
		window.localStorage.setItem('recordingsTheme', nextTheme);
	} catch (error) {
	}
}

function initTheme()
{
	var savedTheme = null;
	try {
		savedTheme = window.localStorage.getItem('recordingsTheme');
	} catch (error) {
	}

	applyTheme(savedTheme === 'theme-light' ? 'theme-light' : 'theme-dark');
}

function adjustRecordingsListHeight()
{
	var recordingsListWrap = document.getElementById('recordingsList');
	if (!recordingsListWrap) {
		return;
	}

	var rect = recordingsListWrap.getBoundingClientRect();
	var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
	var bottomPadding = 12;
	var availableHeight = viewportHeight - rect.top - bottomPadding;
	var minHeight = 220;

	if (availableHeight < minHeight) {
		availableHeight = minHeight;
	}

	recordingsListWrap.style.height = Math.floor(availableHeight) + 'px';
}

function formatBytesForStatus(bytes)
{
	var units = ['B', 'KB', 'MB', 'GB', 'TB'];
	var size = Number(bytes);
	if (!isFinite(size) || size < 0) {
		size = 0;
	}

	var unitIndex = 0;
	while (size >= 1024 && unitIndex < (units.length - 1)) {
		size = size / 1024;
		unitIndex++;
	}

	if (unitIndex === 0) {
		return String(Math.round(size)) + ' ' + units[unitIndex];
	}

	return size.toFixed(2) + ' ' + units[unitIndex];
}

function buildStatusContextText()
{
	return 'Shown size ' + formatBytesForStatus(shownRecordingsSizeBytes) + ' • Total size ' + formatBytesForStatus(totalRecordingsSizeBytes) + ' • Autoplay queue ' + pendingAutoPlayPaths.length;
}

function renderStatusText()
{
	var statusText = document.getElementById('statusText');
	if (!statusText) {
		return;
	}

	var renderedText = lastStatusMessage;
	if (!lastStatusIsError && renderedText !== '') {
		renderedText += ' ' + buildStatusContextText() + '.';
	}

	statusText.textContent = renderedText;
	var isLightTheme = document.body.classList.contains('theme-light');
	statusText.style.color = lastStatusIsError ? (isLightTheme ? '#b00020' : '#ff8888') : (isLightTheme ? '#2d3742' : '#d4d4d4');
	adjustRecordingsListHeight();
}

function refreshStatusDetails()
{
	if (lastStatusMessage === '') {
		return;
	}

	renderStatusText();
}

function setStatus(text, isError)
{
	lastStatusMessage = String(text || '');
	lastStatusIsError = (isError === true);
	renderStatusText();
}

function updateSelectedRowHighlight()
{
	var rows = document.querySelectorAll('.recording-row');
	for (var index = 0; index < rows.length; index++) {
		if (rows[index].getAttribute('data-path') === selectedPath) {
			rows[index].className = 'recording-row selected-recording';
		} else {
			rows[index].className = 'recording-row';
		}
	}
}

function applySelectedToPlayer(autoPlay)
{
	var titleElement = document.getElementById('selectedTitle');
	var metaElement = document.getElementById('selectedMeta');
	var downloadLink = document.getElementById('downloadLink');
	var audioPlayer = document.getElementById('audioPlayer');

	if (!selectedPath || !recordingsByPath[selectedPath]) {
		titleElement.textContent = 'No recording selected';
		metaElement.textContent = 'Select a recording to begin playback.';
		downloadLink.style.display = 'none';
		audioPlayer.removeAttribute('data-path');
		audioPlayer.removeAttribute('src');
		audioPlayer.load();
		return;
	}

	var recording = recordingsByPath[selectedPath];
	titleElement.textContent = recording.time_display + ' - ' + recording.name_pretty;
	metaElement.textContent = recording.path + ' • ' + recording.duration_display + ' • ' + recording.size_human;

	var streamUrl = '?ajax=stream&file=' + encodeURIComponent(recording.path) + '&v=' + recording.mtime;
	var downloadUrl = '?ajax=stream&file=' + encodeURIComponent(recording.path) + '&download=1&v=' + recording.mtime;
	if (audioPlayer.getAttribute('data-path') !== recording.path) {
		audioPlayer.setAttribute('data-path', recording.path);
		audioPlayer.src = streamUrl;
		audioPlayer.load();
	}

	downloadLink.href = downloadUrl;
	downloadLink.style.display = 'inline-block';

	if (autoPlay === true) {
		var playPromise = audioPlayer.play();
		if (playPromise && typeof playPromise.catch === 'function') {
			playPromise.catch(function () {
			});
		}
	}
}

function selectRecording(path, autoPlay)
{
	selectedPath = path;
	removePendingAutoPlayPath(path);
	updateSelectedRowHighlight();
	applySelectedToPlayer(autoPlay === true);
}

function isAudioPlayerActivelyPlaying()
{
	var audioPlayer = document.getElementById('audioPlayer');
	if (!audioPlayer) {
		return false;
	}

	return !audioPlayer.paused && !audioPlayer.ended;
}

function getRecordingNameFilterValue()
{
	var filterInput = document.getElementById('recordingsFilterInput');
	if (!filterInput) {
		return '';
	}

	return String(filterInput.value || '').toLowerCase().trim();
}

function recordingMatchesFilter(recording, normalizedFilter)
{
	if (normalizedFilter === '') {
		return true;
	}

	var prettyName = String(recording.name_pretty || '').toLowerCase();
	var rawName = String(recording.name || '').toLowerCase();

	return prettyName.indexOf(normalizedFilter) !== -1 || rawName.indexOf(normalizedFilter) !== -1;
}

function filterRecordingGroups(groups, normalizedFilter)
{
	if (!groups || groups.length === 0) {
		return [];
	}

	if (normalizedFilter === '') {
		return groups;
	}

	var filteredGroups = [];
	for (var groupIndex = 0; groupIndex < groups.length; groupIndex++) {
		var group = groups[groupIndex];
		var filteredItems = [];

		for (var itemIndex = 0; itemIndex < group.items.length; itemIndex++) {
			var recording = group.items[itemIndex];
			if (recordingMatchesFilter(recording, normalizedFilter)) {
				filteredItems.push(recording);
			}
		}

		if (filteredItems.length > 0) {
			filteredGroups.push({
				date: group.date,
				items: filteredItems
			});
		}
	}

	return filteredGroups;
}

function countRecordingsInGroups(groups)
{
	var count = 0;
	for (var groupIndex = 0; groupIndex < groups.length; groupIndex++) {
		count += groups[groupIndex].items.length;
	}

	return count;
}

function sumRecordingSizeBytesInGroups(groups)
{
	var totalBytes = 0;
	for (var groupIndex = 0; groupIndex < groups.length; groupIndex++) {
		var items = groups[groupIndex].items;
		for (var itemIndex = 0; itemIndex < items.length; itemIndex++) {
			var sizeBytes = parseInt(items[itemIndex].size_bytes, 10);
			if (!isNaN(sizeBytes) && sizeBytes > 0) {
				totalBytes += sizeBytes;
			}
		}
	}

	return totalBytes;
}

function isAutoPlayNewEnabled()
{
	var checkbox = document.getElementById('autoPlayNewCheckbox');
	return !!(checkbox && checkbox.checked);
}

function collectNewMatchingPaths(groups, previousByPath, normalizedFilter)
{
	var newPaths = [];

	for (var groupIndex = 0; groupIndex < groups.length; groupIndex++) {
		var group = groups[groupIndex];
		for (var itemIndex = 0; itemIndex < group.items.length; itemIndex++) {
			var recording = group.items[itemIndex];
			if (previousByPath[recording.path]) {
				continue;
			}

			if (recordingMatchesFilter(recording, normalizedFilter)) {
				newPaths.push(recording.path);
			}
		}
	}

	return newPaths;
}

function removePendingAutoPlayPath(path)
{
	if (!path || pendingAutoPlayPaths.length === 0) {
		return;
	}

	var beforeLength = pendingAutoPlayPaths.length;

	var remainingPaths = [];
	for (var index = 0; index < pendingAutoPlayPaths.length; index++) {
		if (pendingAutoPlayPaths[index] !== path) {
			remainingPaths.push(pendingAutoPlayPaths[index]);
		}
	}

	pendingAutoPlayPaths = remainingPaths;
	if (pendingAutoPlayPaths.length !== beforeLength) {
		refreshStatusDetails();
	}
}

function queuePendingAutoPlayPaths(paths)
{
	if (!paths || paths.length === 0) {
		return 0;
	}

	var queuedCount = 0;
	for (var index = 0; index < paths.length; index++) {
		var path = paths[index];
		if (!path || path === selectedPath) {
			continue;
		}

		var alreadyQueued = false;
		for (var pendingIndex = 0; pendingIndex < pendingAutoPlayPaths.length; pendingIndex++) {
			if (pendingAutoPlayPaths[pendingIndex] === path) {
				alreadyQueued = true;
				break;
			}
		}

		if (alreadyQueued) {
			continue;
		}

		pendingAutoPlayPaths.push(path);
		queuedCount++;
	}

	return queuedCount;
}

function tryStartPendingAutoPlay()
{
	var beforeQueueLength = pendingAutoPlayPaths.length;

	if (!isAutoPlayNewEnabled()) {
		return false;
	}

	if (pendingAutoPlayPaths.length === 0) {
		return false;
	}

	if (isAudioPlayerActivelyPlaying()) {
		return false;
	}

	var normalizedFilter = getRecordingNameFilterValue();
	while (pendingAutoPlayPaths.length > 0) {
		var nextPath = pendingAutoPlayPaths.shift();
		if (!recordingsByPath[nextPath]) {
			continue;
		}

		if (!recordingMatchesFilter(recordingsByPath[nextPath], normalizedFilter)) {
			continue;
		}

		selectedPath = nextPath;
		updateSelectedRowHighlight();
		applySelectedToPlayer(true);
		if (pendingAutoPlayPaths.length !== beforeQueueLength) {
			refreshStatusDetails();
		}
		return true;
	}

	if (pendingAutoPlayPaths.length !== beforeQueueLength) {
		refreshStatusDetails();
	}

	return false;
}

function onAutoPlayNewCheckboxChanged()
{
	if (!isAutoPlayNewEnabled()) {
		if (pendingAutoPlayPaths.length > 0) {
			pendingAutoPlayPaths = [];
			refreshStatusDetails();
		}
		return;
	}

	tryStartPendingAutoPlay();
}

function onAudioPlayerEnded()
{
	tryStartPendingAutoPlay();
}

function applyCurrentFilterAndRender(autoPlay)
{
	var normalizedFilter = getRecordingNameFilterValue();
	var filteredGroups = filterRecordingGroups(allRecordingsGroups, normalizedFilter);
	shownRecordingsSizeBytes = sumRecordingSizeBytesInGroups(filteredGroups);
	var visibleByPath = {};

	for (var groupIndex = 0; groupIndex < filteredGroups.length; groupIndex++) {
		var group = filteredGroups[groupIndex];
		for (var itemIndex = 0; itemIndex < group.items.length; itemIndex++) {
			var recording = group.items[itemIndex];
			visibleByPath[recording.path] = true;
		}
	}

	renderRecordings(filteredGroups);

	if (selectedPath && !recordingsByPath[selectedPath]) {
		selectedPath = null;
	}

	if (selectedPath && !visibleByPath[selectedPath] && !isAudioPlayerActivelyPlaying()) {
		selectedPath = null;
	}

	if (!selectedPath && filteredGroups.length > 0 && filteredGroups[0].items.length > 0) {
		selectedPath = filteredGroups[0].items[0].path;
	}

	updateSelectedRowHighlight();
	applySelectedToPlayer(autoPlay === true);

	return countRecordingsInGroups(filteredGroups);
}

function onFilterInputChanged()
{
	var shownCount = applyCurrentFilterAndRender(false);
	tryStartPendingAutoPlay();
	var normalizedFilter = getRecordingNameFilterValue();
	var filterSuffix = normalizedFilter === '' ? '' : ' (filtered)';
	setStatus(shownCount + ' recording(s) shown' + filterSuffix + '.', false);
}

function renderRecordings(groups)
{
	var recordingsList = document.getElementById('recordingsList');
	recordingsList.innerHTML = '';

	if (!groups || groups.length === 0) {
		var emptyMessage = document.createElement('div');
		emptyMessage.textContent = 'No WAV recordings found in /mnt/Media/recordings.';
		recordingsList.appendChild(emptyMessage);
		return;
	}

	for (var groupIndex = 0; groupIndex < groups.length; groupIndex++) {
		var group = groups[groupIndex];

		var header = document.createElement('div');
		header.className = 'recording-date';
		header.textContent = group.date + ' (' + group.items.length + ')';
		recordingsList.appendChild(header);

		var table = document.createElement('table');
		table.className = 'recording-table';

		var tableHead = document.createElement('thead');
		var headRow = document.createElement('tr');

		var dateHead = document.createElement('th');
		dateHead.className = 'recording-date-col';
		dateHead.textContent = 'Date';

		var timeHead = document.createElement('th');
		timeHead.className = 'recording-time';
		timeHead.textContent = 'Time';

		var nameHead = document.createElement('th');
		nameHead.className = 'recording-name';
		nameHead.textContent = 'Name';

		var durationHead = document.createElement('th');
		durationHead.className = 'recording-duration';
		durationHead.textContent = 'Duration';

		var sizeHead = document.createElement('th');
		sizeHead.className = 'recording-size';
		sizeHead.textContent = 'Size';

		var downloadHead = document.createElement('th');
		downloadHead.className = 'recording-download';
		downloadHead.textContent = 'Download';

		headRow.appendChild(dateHead);
		headRow.appendChild(timeHead);
		headRow.appendChild(nameHead);
		headRow.appendChild(durationHead);
		headRow.appendChild(sizeHead);
		headRow.appendChild(downloadHead);
		tableHead.appendChild(headRow);
		table.appendChild(tableHead);

		var tableBody = document.createElement('tbody');

		for (var itemIndex = 0; itemIndex < group.items.length; itemIndex++) {
			var recording = group.items[itemIndex];
			var row = document.createElement('tr');
			row.className = 'recording-row';
			row.setAttribute('data-path', recording.path);
			row.onclick = (function (path) {
				return function () {
					selectRecording(path, true);
				};
			})(recording.path);

			var timeCell = document.createElement('td');
			timeCell.className = 'recording-time';
			timeCell.textContent = recording.time_display;

			var dateCell = document.createElement('td');
			dateCell.className = 'recording-date-col';
			dateCell.textContent = recording.date;

			var nameCell = document.createElement('td');
			nameCell.className = 'recording-name';
			nameCell.textContent = recording.name_pretty;
			nameCell.title = recording.name;

			var durationCell = document.createElement('td');
			durationCell.className = 'recording-duration';
			durationCell.textContent = recording.duration_display;

			var sizeCell = document.createElement('td');
			sizeCell.className = 'recording-size';
			sizeCell.textContent = recording.size_human;

			var downloadCell = document.createElement('td');
			downloadCell.className = 'recording-download';

			var rowDownloadLink = document.createElement('a');
			rowDownloadLink.href = '?ajax=stream&file=' + encodeURIComponent(recording.path) + '&download=1&v=' + recording.mtime;
			rowDownloadLink.textContent = 'Download';
			rowDownloadLink.setAttribute('download', recording.name);
			rowDownloadLink.onclick = function (event) {
				event.stopPropagation();
			};

			downloadCell.appendChild(rowDownloadLink);

			row.appendChild(dateCell);
			row.appendChild(timeCell);
			row.appendChild(nameCell);
			row.appendChild(durationCell);
			row.appendChild(sizeCell);
			row.appendChild(downloadCell);
			tableBody.appendChild(row);
		}

		table.appendChild(tableBody);

		recordingsList.appendChild(table);
	}
}

function refreshRecordings(manualRefresh)
{
	if (refreshInProgress) {
		return;
	}

	refreshInProgress = true;

	var xhr = new XMLHttpRequest();
	xhr.onreadystatechange = function () {
		if (xhr.readyState !== 4) {
			return;
		}

		refreshInProgress = false;

		if (xhr.status < 200 || xhr.status >= 300) {
			setStatus('Refresh failed (' + xhr.status + ').', true);
			return;
		}

		var response = null;
		try {
			response = JSON.parse(xhr.responseText);
		} catch (error) {
			setStatus('Refresh failed (invalid JSON).', true);
			return;
		}

		if (!response.ok) {
			setStatus(response.error ? response.error : 'Refresh failed.', true);
			return;
		}

		var previousRecordingsByPath = recordingsByPath;
		var computedTotalSizeBytes = 0;
		recordingsByPath = {};
		for (var groupIndex = 0; groupIndex < response.groups.length; groupIndex++) {
			var group = response.groups[groupIndex];
			for (var itemIndex = 0; itemIndex < group.items.length; itemIndex++) {
				var recording = group.items[itemIndex];
				recordingsByPath[recording.path] = recording;

				var sizeBytes = parseInt(recording.size_bytes, 10);
				if (!isNaN(sizeBytes) && sizeBytes > 0) {
					computedTotalSizeBytes += sizeBytes;
				}
			}
		}

		totalRecordingsSizeBytes = computedTotalSizeBytes;

		allRecordingsGroups = response.groups;

		var normalizedFilter = getRecordingNameFilterValue();
		var queuedMatchingCount = 0;
		if (hasLoadedRecordingsOnce && isAutoPlayNewEnabled()) {
			var newMatchingPaths = collectNewMatchingPaths(allRecordingsGroups, previousRecordingsByPath, normalizedFilter);
			queuedMatchingCount = queuePendingAutoPlayPaths(newMatchingPaths);
		}

		if (selectedPath && !recordingsByPath[selectedPath]) {
			selectedPath = null;
		}

		var shownCount = applyCurrentFilterAndRender(false);
		var startedQueuedPlayback = tryStartPendingAutoPlay();

		var refreshedAt = new Date(response.generated_at * 1000).toLocaleTimeString();
		var prefix = manualRefresh ? 'Refreshed. ' : '';
		var statusText = prefix + response.count + ' recording(s) found';
		if (normalizedFilter !== '') {
			statusText += ', ' + shownCount + ' shown';
		}
		statusText += '. Last refresh ' + refreshedAt + '.';
		if (startedQueuedPlayback) {
			statusText += ' Auto-playing newest queued recording.';
		} else if (queuedMatchingCount > 0) {
			statusText += ' Queued ' + queuedMatchingCount + ' new matching recording(s) for autoplay.';
		}
		setStatus(statusText, false);

		hasLoadedRecordingsOnce = true;
	};

	xhr.open('GET', '?ajax=list&rnd=' + Math.random(), true);
	xhr.send(null);
}

function startRecordingsPage()
{
	initTheme();
	var filterInput = document.getElementById('recordingsFilterInput');
	if (filterInput) {
		filterInput.addEventListener('input', onFilterInputChanged);
	}

	var autoPlayCheckbox = document.getElementById('autoPlayNewCheckbox');
	if (autoPlayCheckbox) {
		autoPlayCheckbox.addEventListener('change', onAutoPlayNewCheckboxChanged);
	}

	var audioPlayer = document.getElementById('audioPlayer');
	if (audioPlayer) {
		audioPlayer.addEventListener('ended', onAudioPlayerEnded);
	}

	adjustRecordingsListHeight();
	refreshRecordings(false);
	refreshTimerId = setInterval(function () {
		refreshRecordings(false);
	}, refreshEveryMs);
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', startRecordingsPage);
} else {
	startRecordingsPage();
}

window.addEventListener('beforeunload', function () {
	if (refreshTimerId !== null) {
		clearInterval(refreshTimerId);
	}
});

window.addEventListener('resize', function () {
	adjustRecordingsListHeight();
});
</script>

</div>
</body>
</html>
