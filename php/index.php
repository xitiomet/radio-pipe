<?php

// Settings
$recordingsRoot = '/opt/recordings/';
$PAGE_TITLE = 'Icecast Stream Recordings';
$supportedRecordingExtensions = array('wav', 'mp3', 'ogg');

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

function getRecordingMimeType(string $extension): string
{
	$normalizedExtension = strtolower($extension);
	if ($normalizedExtension === 'mp3') {
		return 'audio/mpeg';
	}

	if ($normalizedExtension === 'ogg') {
		return 'audio/ogg';
	}

	return 'audio/wav';
}

function decodeSynchsafeInt(string $bytes): int
{
	if (strlen($bytes) !== 4) {
		return 0;
	}

	return ((ord($bytes[0]) & 0x7F) << 21)
		| ((ord($bytes[1]) & 0x7F) << 14)
		| ((ord($bytes[2]) & 0x7F) << 7)
		| (ord($bytes[3]) & 0x7F);
}

function estimateMp3DurationSeconds(string $fullPath, int $fileSize): ?float
{
	$handle = fopen($fullPath, 'rb');
	if ($handle === false) {
		return null;
	}

	$dataOffset = 0;
	$id3Header = fread($handle, 10);
	if ($id3Header !== false && strlen($id3Header) === 10 && substr($id3Header, 0, 3) === 'ID3') {
		$dataOffset = 10 + decodeSynchsafeInt(substr($id3Header, 6, 4));
	}

	fseek($handle, $dataOffset);
	$scanBytes = fread($handle, 131072);
	fclose($handle);

	if ($scanBytes === false || strlen($scanBytes) < 4) {
		return null;
	}

	$bitrateTableMpeg1 = array(
		3 => array(0, 32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448, 0),
		2 => array(0, 32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384, 0),
		1 => array(0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0),
	);

	$bitrateTableMpeg2 = array(
		3 => array(0, 32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256, 0),
		2 => array(0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0),
		1 => array(0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0),
	);

	$sampleRateTable = array(
		3 => array(44100, 48000, 32000),
		2 => array(22050, 24000, 16000),
		0 => array(11025, 12000, 8000),
	);

	$scanLength = strlen($scanBytes);
	for ($offset = 0; $offset <= ($scanLength - 4); $offset++) {
		$headerData = unpack('Nheader', substr($scanBytes, $offset, 4));
		$header = isset($headerData['header']) ? (int)$headerData['header'] : 0;

		if (($header & 0xFFE00000) !== 0xFFE00000) {
			continue;
		}

		$versionBits = ($header >> 19) & 0x3;
		$layerBits = ($header >> 17) & 0x3;
		$bitrateIndex = ($header >> 12) & 0xF;
		$sampleRateIndex = ($header >> 10) & 0x3;
		$paddingBit = ($header >> 9) & 0x1;
		$channelMode = ($header >> 6) & 0x3;

		if ($versionBits === 1 || $layerBits === 0 || $bitrateIndex === 0 || $bitrateIndex === 15 || $sampleRateIndex === 3) {
			continue;
		}

		if (!isset($sampleRateTable[$versionBits][$sampleRateIndex])) {
			continue;
		}

		$sampleRate = (int)$sampleRateTable[$versionBits][$sampleRateIndex];
		$bitrateTable = ($versionBits === 3) ? $bitrateTableMpeg1 : $bitrateTableMpeg2;
		if (!isset($bitrateTable[$layerBits][$bitrateIndex])) {
			continue;
		}

		$bitrateKbps = (int)$bitrateTable[$layerBits][$bitrateIndex];
		if ($bitrateKbps <= 0 || $sampleRate <= 0) {
			continue;
		}

		$samplesPerFrame = 1152;
		$frameLength = 0;
		if ($layerBits === 3) {
			$samplesPerFrame = 384;
			$frameLength = (int)(floor((12 * $bitrateKbps * 1000) / $sampleRate) + $paddingBit) * 4;
		} elseif ($layerBits === 2) {
			$samplesPerFrame = 1152;
			$frameLength = (int)(floor((144 * $bitrateKbps * 1000) / $sampleRate) + $paddingBit);
		} elseif ($layerBits === 1) {
			$samplesPerFrame = ($versionBits === 3) ? 1152 : 576;
			if ($versionBits === 3) {
				$frameLength = (int)(floor((144 * $bitrateKbps * 1000) / $sampleRate) + $paddingBit);
			} else {
				$frameLength = (int)(floor((72 * $bitrateKbps * 1000) / $sampleRate) + $paddingBit);
			}
		}

		if ($frameLength <= 0) {
			continue;
		}

		// Prefer Xing/Info frame count when present for better VBR accuracy.
		if ($layerBits === 1) {
			$sideInfoLength = 0;
			if ($versionBits === 3) {
				$sideInfoLength = ($channelMode === 3) ? 17 : 32;
			} else {
				$sideInfoLength = ($channelMode === 3) ? 9 : 17;
			}

			$xingOffset = $offset + 4 + $sideInfoLength;
			if (($xingOffset + 8) <= $scanLength) {
				$xingTag = substr($scanBytes, $xingOffset, 4);
				if ($xingTag === 'Xing' || $xingTag === 'Info') {
					$flagsData = unpack('Nflags', substr($scanBytes, $xingOffset + 4, 4));
					$flags = isset($flagsData['flags']) ? (int)$flagsData['flags'] : 0;
					if (($flags & 0x1) === 0x1 && ($xingOffset + 12) <= $scanLength) {
						$framesData = unpack('Nframes', substr($scanBytes, $xingOffset + 8, 4));
						$frameCount = isset($framesData['frames']) ? (int)$framesData['frames'] : 0;
						if ($frameCount > 0) {
							return (float)($frameCount * $samplesPerFrame) / $sampleRate;
						}
					}
				}
			}
		}

		$audioBytes = max(0, $fileSize - ($dataOffset + $offset));
		if ($audioBytes <= 0) {
			return null;
		}

		return ((float)$audioBytes * 8.0) / ((float)$bitrateKbps * 1000.0);
	}

	return null;
}

function estimateOggDurationSeconds(string $fullPath, int $fileSize): ?float
{
	$handle = fopen($fullPath, 'rb');
	if ($handle === false) {
		return null;
	}

	$sampleRate = null;
	for ($pageCount = 0; $pageCount < 24 && !feof($handle); $pageCount++) {
		$pageHeader = fread($handle, 27);
		if ($pageHeader === false || strlen($pageHeader) < 27) {
			break;
		}

		if (substr($pageHeader, 0, 4) !== 'OggS') {
			break;
		}

		$segmentCount = ord($pageHeader[26]);
		$lacingValues = ($segmentCount > 0) ? fread($handle, $segmentCount) : '';
		if ($segmentCount > 0 && ($lacingValues === false || strlen($lacingValues) < $segmentCount)) {
			break;
		}

		$payloadSize = 0;
		for ($segmentIndex = 0; $segmentIndex < $segmentCount; $segmentIndex++) {
			$payloadSize += ord($lacingValues[$segmentIndex]);
		}

		$payload = ($payloadSize > 0) ? fread($handle, $payloadSize) : '';
		if ($payloadSize > 0 && ($payload === false || strlen($payload) < $payloadSize)) {
			break;
		}

		if ($sampleRate === null && is_string($payload) && strlen($payload) >= 16 && substr($payload, 0, 7) === "\x01vorbis") {
			$sampleRateData = unpack('VsampleRate', substr($payload, 12, 4));
			if (isset($sampleRateData['sampleRate']) && (int)$sampleRateData['sampleRate'] > 0) {
				$sampleRate = (int)$sampleRateData['sampleRate'];
			}
		}

		if ($sampleRate === null && is_string($payload) && strlen($payload) >= 12 && substr($payload, 0, 8) === 'OpusHead') {
			$sampleRate = 48000;
		}

		if ($sampleRate !== null) {
			break;
		}
	}

	fclose($handle);

	if ($sampleRate === null || $sampleRate <= 0 || $fileSize <= 0) {
		return null;
	}

	$tailHandle = fopen($fullPath, 'rb');
	if ($tailHandle === false) {
		return null;
	}

	$tailBytes = min($fileSize, 524288);
	if ($tailBytes <= 0) {
		fclose($tailHandle);
		return null;
	}

	fseek($tailHandle, $fileSize - $tailBytes);
	$tailData = fread($tailHandle, $tailBytes);
	fclose($tailHandle);

	if ($tailData === false || strlen($tailData) < 14) {
		return null;
	}

	$searchLength = strlen($tailData);
	$lastGranule = null;

	while ($searchLength > 0) {
		$candidate = strrpos(substr($tailData, 0, $searchLength), 'OggS');
		if ($candidate === false) {
			break;
		}

		if (($candidate + 14) <= strlen($tailData)) {
			$granuleBytes = substr($tailData, $candidate + 6, 8);
			$granuleParts = unpack('Vlow/Vhigh', $granuleBytes);
			if (isset($granuleParts['low'], $granuleParts['high'])) {
				$low = (int)$granuleParts['low'];
				$high = (int)$granuleParts['high'];

				if (!($high === 0xFFFFFFFF && $low === 0xFFFFFFFF)) {
					$granulePosition = ((float)$high * 4294967296.0) + (float)$low;
					if ($granulePosition > 0) {
						$lastGranule = $granulePosition;
						break;
					}
				}
			}
		}

		$searchLength = $candidate;
	}

	if ($lastGranule === null) {
		return null;
	}

	return $lastGranule / (float)$sampleRate;
}

function estimateRecordingDurationSeconds(string $fullPath, int $fileSize, string $extension): ?float
{
	$normalizedExtension = strtolower($extension);
	if ($normalizedExtension === 'wav') {
		return estimateWavDurationSeconds($fullPath, $fileSize);
	}

	if ($normalizedExtension === 'mp3') {
		return estimateMp3DurationSeconds($fullPath, $fileSize);
	}

	if ($normalizedExtension === 'ogg') {
		return estimateOggDurationSeconds($fullPath, $fileSize);
	}

	return null;
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

function listRecordings(string $rootDirectory, array $allowedExtensions): array
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

		$extension = strtolower((string)$fileInfo->getExtension());
		if (!in_array($extension, $allowedExtensions, true)) {
			continue;
		}

		$fullPath = $fileInfo->getPathname();
		$relativePath = substr($fullPath, strlen($realRoot) + 1);
		$fileName = $fileInfo->getBasename();
		$modifiedAt = (int)$fileInfo->getMTime();
		$parsedTimestamp = parseRecordingTimestamp($relativePath, $modifiedAt);
		$sizeBytes = (int)$fileInfo->getSize();
		$durationSeconds = estimateRecordingDurationSeconds($fullPath, $sizeBytes, $extension);

		$items[] = array(
			'path' => $relativePath,
			'name' => $fileName,
			'name_pretty' => prettifyRecordingName($fileName),
			'content_type' => getRecordingMimeType($extension),
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

function streamRecording(string $rootDirectory, array $allowedExtensions, string $requestedPath, bool $forceDownload = false): void
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

	$extension = strtolower((string)pathinfo($fullPath, PATHINFO_EXTENSION));
	if (!in_array($extension, $allowedExtensions, true)) {
		http_response_code(400);
		echo 'Unsupported recording file type.';
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

	header('Content-Type: ' . getRecordingMimeType($extension));
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
	$payload = listRecordings($recordingsRoot, $supportedRecordingExtensions);
	if ($payload['ok'] !== true) {
		sendJsonResponse($payload, 500);
	}

	sendJsonResponse($payload, 200);
}

if ($ajaxAction === 'stream') {
	$requestedFile = isset($_GET['file']) ? (string)$_GET['file'] : '';
	$forceDownload = isset($_GET['download']) && (string)$_GET['download'] === '1';
	streamRecording($recordingsRoot, $supportedRecordingExtensions, $requestedFile, $forceDownload);
}

if ($ajaxAction === 'zip') {
	$nameFilter = isset($_GET['filter']) ? strtolower(trim((string)$_GET['filter'])) : '';
	$startTs = isset($_GET['start']) && $_GET['start'] !== '' ? (int)$_GET['start'] : 0;
	$endTs = isset($_GET['end']) && $_GET['end'] !== '' ? (int)$_GET['end'] : 0;
	zipRecordingsFiltered($recordingsRoot, $supportedRecordingExtensions, $nameFilter, $startTs, $endTs);
}

function zipRecordingsFiltered(string $rootDirectory, array $allowedExtensions, string $nameFilter, int $startTs, int $endTs): void
{
	if (!class_exists('ZipArchive')) {
		http_response_code(500);
		header('Content-Type: text/plain');
		echo 'ZIP support (ZipArchive) is not available on this server.';
		exit;
	}

	$data = listRecordings($rootDirectory, $allowedExtensions);
	if ($data['ok'] !== true) {
		http_response_code(500);
		header('Content-Type: text/plain');
		echo isset($data['error']) ? $data['error'] : 'Failed to list recordings.';
		exit;
	}

	$realRoot = realpath($rootDirectory);
	if ($realRoot === false) {
		http_response_code(500);
		header('Content-Type: text/plain');
		echo 'Recordings directory is not available.';
		exit;
	}

	$matchingFiles = array();
	foreach ($data['groups'] as $group) {
		foreach ($group['items'] as $item) {
			if ($nameFilter !== '') {
				$prettyName = strtolower((string)$item['name_pretty']);
				$rawName = strtolower((string)$item['name']);
				if (strpos($prettyName, $nameFilter) === false && strpos($rawName, $nameFilter) === false) {
					continue;
				}
			}

			$ts = (int)$item['timestamp'];
			if ($startTs > 0 && $ts < $startTs) {
				continue;
			}

			if ($endTs > 0 && $ts > $endTs) {
				continue;
			}

			$matchingFiles[] = $item;
		}
	}

	if (empty($matchingFiles)) {
		http_response_code(404);
		header('Content-Type: text/plain');
		echo 'No recordings match the current filters.';
		exit;
	}

	$tmpFile = tempnam(sys_get_temp_dir(), 'recordings_zip_');
	if ($tmpFile === false) {
		http_response_code(500);
		header('Content-Type: text/plain');
		echo 'Failed to create temporary file for ZIP archive.';
		exit;
	}

	$zip = new ZipArchive();
	if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
		@unlink($tmpFile);
		http_response_code(500);
		header('Content-Type: text/plain');
		echo 'Failed to open ZIP archive for writing.';
		exit;
	}

	// Build the M3U playlist in chronological order (oldest first).
	$playlistItems = array_reverse($matchingFiles);
	$m3uLines = array('#EXTM3U');
	foreach ($playlistItems as $item) {
		$normalizedPath = normalizeRelativePath((string)$item['path']);
		if ($normalizedPath === '') {
			continue;
		}

		$fullPath = $realRoot . DIRECTORY_SEPARATOR . $normalizedPath;
		$resolvedPath = realpath($fullPath);
		if ($resolvedPath === false || !is_file($resolvedPath)) {
			continue;
		}

		if (strpos($resolvedPath, $realRoot . DIRECTORY_SEPARATOR) !== 0) {
			continue;
		}

		$durationSeconds = ($item['duration_seconds'] !== null && $item['duration_seconds'] > 0)
			? (int)round((float)$item['duration_seconds'])
			: -1;
		$title = $item['timestamp_iso'] . ' - ' . $item['name_pretty'];
		$zipAudioPath = 'audio/' . $normalizedPath;
		$m3uLines[] = '#EXTINF:' . $durationSeconds . ',' . $title;
		$m3uLines[] = $zipAudioPath;
		$zip->addFile($resolvedPath, $zipAudioPath);
	}

	$zip->addFromString('recordings.m3u', implode("\n", $m3uLines) . "\n");

	$zip->close();

	$zipSize = filesize($tmpFile);
	if ($zipSize === false || $zipSize === 0) {
		@unlink($tmpFile);
		http_response_code(500);
		header('Content-Type: text/plain');
		echo 'ZIP archive could not be generated.';
		exit;
	}

	$zipName = 'recordings_' . date('Y-m-d') . '.zip';
	header('Content-Type: application/zip');
	header('Content-Disposition: attachment; filename="' . $zipName . '"');
	header('Content-Length: ' . $zipSize);
	header('Cache-Control: no-cache, no-store, must-revalidate');

	set_time_limit(0);
	readfile($tmpFile);
	@unlink($tmpFile);
	exit;
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

	.recording-content-type {
		width: 120px;
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

	.checkbox-stack {
		display: flex;
		flex-direction: column;
		gap: 4px;
		flex: 0 0 auto;
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

	.export-zip-button {
		min-height: 34px;
		padding: 6px 10px;
		white-space: nowrap;
		border: 1px solid #2a5a2a;
		border-radius: 4px;
		background-color: #1a3a1a;
		color: #7cdb7c;
		cursor: pointer;
	}

	body.theme-light .export-zip-button {
		border-color: #5a9a5a;
		background-color: #eaf5ea;
		color: #1a4d1a;
	}

	.export-zip-button:hover {
		background-color: #234a23;
	}

	body.theme-light .export-zip-button:hover {
		background-color: #d4ecd4;
	}

	.recordings-filter-range-label {
		font-size: 13px;
		white-space: nowrap;
	}

	.recordings-filter-datetime {
		min-height: 34px;
		padding: 6px 8px;
		border: 1px solid #4a4a4a;
		border-radius: 4px;
		background-color: #141414;
		color: #efefef;
		font-size: 13px;
		flex: 0 0 auto;
	}

	body.theme-light .recordings-filter-datetime {
		border-color: #b9c4d1;
		background-color: #ffffff;
		color: #1f2a34;
	}

	.recordings-controls-row {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 10px;
		width: 100%;
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
		.recordings-filter-range-label,
		.autoplay-new-label,
		.checkbox-stack {
			width: 100%;
		}

		.recordings-filter-input {
			width: 100%;
			flex: 1 1 100%;
		}

		.recordings-filter-datetime {
			flex: 1 1 160px;
		}

		.recording-table {
			min-width: 500px;
		}

		.refresh-button,
		.export-zip-button {
			width: 32%;
		}
	}
</style>

<h2><?=$PAGE_TITLE?></h2>
<div class="status-row">
	<span id="statusText" class="status-text">Loading recordings...</span>
</div>
<div style="text-align: center; margin-bottom: 10px;">
	<button type="button" class="refresh-button" onclick="refreshRecordings(true)">Refresh Now</button>
	<button type="button" class="refresh-button" onclick="document.documentElement.requestFullscreen();">Fullscreen</button>
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
				<div class="recordings-controls-row">
					<label for="recordingsFilterInput" class="recordings-filter-label">Filter by name:</label>
					<input type="text" id="recordingsFilterInput" class="recordings-filter-input" placeholder="Type to filter recordings..." autocomplete="off" />
					<div class="checkbox-stack">
						<label class="autoplay-new-label" for="autoPlayNewCheckbox"><input type="checkbox" id="autoPlayNewCheckbox" /> Auto play new recordings</label>
						<label class="autoplay-new-label" for="keepPlayingChronologicallyCheckbox"><input type="checkbox" id="keepPlayingChronologicallyCheckbox" /> Keep playing chronologically</label>
					</div>
				</div>
				<div class="recordings-controls-row">
					<label for="recordingsFilterStart" class="recordings-filter-range-label">From:</label>
					<input type="datetime-local" id="recordingsFilterStart" class="recordings-filter-datetime" title="Filter recordings from this date/time (leave blank for no start limit)" />
					<label for="recordingsFilterEnd" class="recordings-filter-range-label">To:</label>
					<input type="datetime-local" id="recordingsFilterEnd" class="recordings-filter-datetime" title="Filter recordings up to this date/time (leave blank for no end limit)" />
					<button type="button" id="exportZipButton" class="export-zip-button" onclick="exportFilteredZip()" title="Download all filtered recordings as a ZIP archive">Export ZIP (0)</button>
				</div>
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
	var bottomPadding = 32;
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
	if (recording.content_type) {
		metaElement.textContent += ' • ' + recording.content_type;
	}

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

function getDateRangeFilter()
{
	var startInput = document.getElementById('recordingsFilterStart');
	var endInput = document.getElementById('recordingsFilterEnd');
	var startTs = null;
	var endTs = null;

	if (startInput && startInput.value !== '') {
		var startDate = new Date(startInput.value);
		if (!isNaN(startDate.getTime())) {
			startTs = Math.floor(startDate.getTime() / 1000);
		}
	}

	if (endInput && endInput.value !== '') {
		var endDate = new Date(endInput.value);
		if (!isNaN(endDate.getTime())) {
			endTs = Math.floor(endDate.getTime() / 1000);
		}
	}

	return { startTs: startTs, endTs: endTs };
}

function recordingMatchesDateRange(recording, dateRange)
{
	if (!dateRange) {
		return true;
	}

	var ts = parseInt(recording.timestamp, 10);
	if (dateRange.startTs !== null && ts < dateRange.startTs) {
		return false;
	}

	if (dateRange.endTs !== null && ts > dateRange.endTs) {
		return false;
	}

	return true;
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

function filterRecordingGroups(groups, normalizedFilter, dateRange)
{
	if (!groups || groups.length === 0) {
		return [];
	}

	var hasNameFilter = normalizedFilter !== '';
	var hasDateRange = !!(dateRange && (dateRange.startTs !== null || dateRange.endTs !== null));

	if (!hasNameFilter && !hasDateRange) {
		return groups;
	}

	var filteredGroups = [];
	for (var groupIndex = 0; groupIndex < groups.length; groupIndex++) {
		var group = groups[groupIndex];
		var filteredItems = [];

		for (var itemIndex = 0; itemIndex < group.items.length; itemIndex++) {
			var recording = group.items[itemIndex];
			if (!recordingMatchesFilter(recording, normalizedFilter)) {
				continue;
			}

			if (!recordingMatchesDateRange(recording, dateRange)) {
				continue;
			}

			filteredItems.push(recording);
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

function isKeepPlayingEnabled()
{
	var checkbox = document.getElementById('keepPlayingChronologicallyCheckbox');
	return !!(checkbox && checkbox.checked);
}

function playNextChronological()
{
	if (!isKeepPlayingEnabled()) {
		return false;
	}

	var normalizedFilter = getRecordingNameFilterValue();
	var dateRange = getDateRangeFilter();
	var filteredGroups = filterRecordingGroups(allRecordingsGroups, normalizedFilter, dateRange);

	// Flatten newest-first then reverse to get oldest-first chronological order.
	var flatItems = [];
	for (var gi = 0; gi < filteredGroups.length; gi++) {
		var items = filteredGroups[gi].items;
		for (var ii = 0; ii < items.length; ii++) {
			flatItems.push(items[ii]);
		}
	}
	flatItems.reverse();

	if (flatItems.length === 0) {
		return false;
	}

	var currentIndex = -1;
	for (var idx = 0; idx < flatItems.length; idx++) {
		if (flatItems[idx].path === selectedPath) {
			currentIndex = idx;
			break;
		}
	}

	var nextIndex = currentIndex + 1;
	if (nextIndex >= flatItems.length) {
		return false;
	}

	selectRecording(flatItems[nextIndex].path, true);
	return true;
}

function isAutoPlayNewEnabled()
{
	var checkbox = document.getElementById('autoPlayNewCheckbox');
	return !!(checkbox && checkbox.checked);
}

function collectNewMatchingPaths(groups, previousByPath, normalizedFilter, dateRange)
{
	var newPaths = [];

	for (var groupIndex = 0; groupIndex < groups.length; groupIndex++) {
		var group = groups[groupIndex];
		for (var itemIndex = 0; itemIndex < group.items.length; itemIndex++) {
			var recording = group.items[itemIndex];
			if (previousByPath[recording.path]) {
				continue;
			}

			if (!recordingMatchesFilter(recording, normalizedFilter)) {
				continue;
			}

			if (!recordingMatchesDateRange(recording, dateRange)) {
				continue;
			}

			newPaths.push(recording.path);
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
	if (playNextChronological()) {
		return;
	}

	tryStartPendingAutoPlay();
}

function applyCurrentFilterAndRender(autoPlay)
{
	var normalizedFilter = getRecordingNameFilterValue();
	var dateRange = getDateRangeFilter();
	var filteredGroups = filterRecordingGroups(allRecordingsGroups, normalizedFilter, dateRange);
	shownRecordingsSizeBytes = sumRecordingSizeBytesInGroups(filteredGroups);
	var visibleByPath = {};

	for (var groupIndex = 0; groupIndex < filteredGroups.length; groupIndex++) {
		var group = filteredGroups[groupIndex];
		for (var itemIndex = 0; itemIndex < group.items.length; itemIndex++) {
			var recording = group.items[itemIndex];
			visibleByPath[recording.path] = true;
		}
	}

	var filteredCount = countRecordingsInGroups(filteredGroups);
	var exportBtn = document.getElementById('exportZipButton');
	if (exportBtn) {
		exportBtn.textContent = 'Export ZIP (' + filteredCount + ')';
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
	var dateRange = getDateRangeFilter();
	var isFiltered = normalizedFilter !== '' || (dateRange.startTs !== null || dateRange.endTs !== null);
	var filterSuffix = isFiltered ? ' (filtered)' : '';
	setStatus(shownCount + ' recording(s) shown' + filterSuffix + '.', false);
}

function renderRecordings(groups)
{
	var recordingsList = document.getElementById('recordingsList');
	recordingsList.innerHTML = '';

	if (!groups || groups.length === 0) {
		var emptyMessage = document.createElement('div');
		emptyMessage.textContent = 'No WAV/MP3/OGG recordings found in /mnt/Media/recordings.';
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

		var contentTypeHead = document.createElement('th');
		contentTypeHead.className = 'recording-content-type';
		contentTypeHead.textContent = 'Content-Type';

		var sizeHead = document.createElement('th');
		sizeHead.className = 'recording-size';
		sizeHead.textContent = 'Size';

		var downloadHead = document.createElement('th');
		downloadHead.className = 'recording-download';
		downloadHead.textContent = 'Download';

		headRow.appendChild(dateHead);
		headRow.appendChild(timeHead);
		headRow.appendChild(nameHead);
		headRow.appendChild(contentTypeHead);
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

			var contentTypeCell = document.createElement('td');
			contentTypeCell.className = 'recording-content-type';
			contentTypeCell.textContent = recording.content_type ? recording.content_type : 'audio/unknown';

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
			row.appendChild(contentTypeCell);
			row.appendChild(durationCell);
			row.appendChild(sizeCell);
			row.appendChild(downloadCell);
			tableBody.appendChild(row);
		}

		table.appendChild(tableBody);

		recordingsList.appendChild(table);
	}
}

function exportFilteredZip()
{
	var normalizedFilter = getRecordingNameFilterValue();
	var dateRange = getDateRangeFilter();
	var url = '?ajax=zip';

	if (normalizedFilter !== '') {
		url += '&filter=' + encodeURIComponent(normalizedFilter);
	}

	if (dateRange.startTs !== null) {
		url += '&start=' + dateRange.startTs;
	}

	if (dateRange.endTs !== null) {
		url += '&end=' + dateRange.endTs;
	}

	window.location.href = url;
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
		var dateRange = getDateRangeFilter();
		var queuedMatchingCount = 0;
		if (hasLoadedRecordingsOnce && isAutoPlayNewEnabled()) {
			var newMatchingPaths = collectNewMatchingPaths(allRecordingsGroups, previousRecordingsByPath, normalizedFilter, dateRange);
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
		var isFiltered = normalizedFilter !== '' || (dateRange.startTs !== null || dateRange.endTs !== null);
		if (isFiltered) {
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

	var dateStartInput = document.getElementById('recordingsFilterStart');
	if (dateStartInput) {
		var oneWeekAgo = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000);
		var pad = function (n) { return String(n).padStart(2, '0'); };
		dateStartInput.value = oneWeekAgo.getFullYear() + '-' + pad(oneWeekAgo.getMonth() + 1) + '-' + pad(oneWeekAgo.getDate()) + 'T' + pad(oneWeekAgo.getHours()) + ':' + pad(oneWeekAgo.getMinutes());
		dateStartInput.addEventListener('change', onFilterInputChanged);
	}

	var dateEndInput = document.getElementById('recordingsFilterEnd');
	if (dateEndInput) {
		dateEndInput.addEventListener('change', onFilterInputChanged);
	}

	var autoPlayCheckbox = document.getElementById('autoPlayNewCheckbox');
	if (autoPlayCheckbox) {
		autoPlayCheckbox.addEventListener('change', onAutoPlayNewCheckboxChanged);
	}

	var keepPlayingCheckbox = document.getElementById('keepPlayingChronologicallyCheckbox');
	if (keepPlayingCheckbox) {
		keepPlayingCheckbox.addEventListener('change', function () {
			if (!isKeepPlayingEnabled()) {
				return;
			}
			// If nothing is selected yet, start from the oldest recording.
			if (!selectedPath || !isAudioPlayerActivelyPlaying()) {
				playNextChronological();
			}
		});
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
