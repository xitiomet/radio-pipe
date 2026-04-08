<?php

// Settings
$recordingsRoot = __DIR__ . '/recordings/';
$PAGE_TITLE = 'Radio Stream Recordings';
$supportedRecordingExtensions = array('wav', 'mp3', 'ogg');
$authenticate = FALSE; // Set to true to enable basic authentication for access control.
$authenticationDebug = FALSE; // Set to true to write authentication decisions to PHP error_log.
$authenticationSessionLifetimeSeconds = 315360000; // 10 years; effectively non-expiring unless server/browser clears cookies.

// Configure one or more login accounts for accessing recordings.
// Preferred format:
// $ACCOUNTS = array(
// 	array('username' => 'root', 'password' => 'password1'),
// 	array('username' => 'admin', 'hashedPassword' => '$6$salt$hash...'),
// );

if (file_exists(__DIR__ . '/config.php')) {
	include __DIR__ . '/config.php';
}

$recordingsRequestAuthUsername = '';
$recordingsRequestAuthVia = '';
$recordingsRequestAuthHash = '';

// End Settings


function recordingsAuthDebugEnabled(): bool
{
	global $authenticationDebug;
	return isset($authenticationDebug) && $authenticationDebug === true;
}

function recordingsAuthDebugLog(string $message, array $context = array()): void
{
	if (!recordingsAuthDebugEnabled()) {
		return;
	}

	$safeContext = array();
	foreach ($context as $key => $value) {
		if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
			$safeContext[$key] = $value;
			continue;
		}

		if (is_string($value)) {
			$safeContext[$key] = (strlen($value) > 180) ? (substr($value, 0, 177) . '...') : $value;
			continue;
		}

		if (is_array($value)) {
			$safeContext[$key] = 'array(' . count($value) . ')';
			continue;
		}

		$safeContext[$key] = gettype($value);
	}

	$contextJson = json_encode($safeContext);
	if ($contextJson === false) {
		$contextJson = '{}';
	}

	error_log('[recordings-auth] ' . $message . ' ' . $contextJson);
}


function startRecordingsAuthSession(): void
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		global $authenticationSessionLifetimeSeconds;
		$sessionLifetime = (int)$authenticationSessionLifetimeSeconds;
		if ($sessionLifetime < 0) {
			$sessionLifetime = 0;
		}

		@ini_set('session.gc_maxlifetime', (string)$sessionLifetime);

		$useSecureCookie = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
		if (PHP_VERSION_ID >= 70300) {
			session_set_cookie_params(array(
				'lifetime' => $sessionLifetime,
				'path' => '/',
				'domain' => '',
				'secure' => $useSecureCookie,
				'httponly' => true,
				'samesite' => 'Lax',
			));
		} else {
			session_set_cookie_params($sessionLifetime, '/; samesite=Lax', '', $useSecureCookie, true);
		}

		session_start();
	}
}

function normalizeConfiguredPasswordHash(string $rawHash): string
{
	$rawHash = trim($rawHash);
	if ($rawHash === '') {
		return '';
	}

	// Allow pasting a full /etc/shadow field; keep only the hash component.
	$hashParts = explode(':', $rawHash, 2);
	$normalizedHash = trim($hashParts[0]);
	if ($normalizedHash === '' || $normalizedHash === 'x' || $normalizedHash === '*' || $normalizedHash === '!' || strpos($normalizedHash, '!') === 0) {
		return '';
	}

	return $normalizedHash;
}

function verifyPasswordAgainstConfiguredHash(string $password, string $storedHash): bool
{
	$storedHash = normalizeConfiguredPasswordHash($storedHash);
	if ($storedHash === '' || $password === '') {
		return false;
	}

	$computedHash = crypt($password, $storedHash);
	if (!is_string($computedHash) || $computedHash === '') {
		return false;
	}

	if (function_exists('hash_equals')) {
		return hash_equals($storedHash, $computedHash);
	}

	return $storedHash === $computedHash;
}

function getConfiguredRecordingsAccounts(): array
{
	global $ACCOUNTS, $USERNAME, $PASSWORD;

	$configuredAccounts = array();

	if (isset($ACCOUNTS) && is_array($ACCOUNTS)) {
		foreach ($ACCOUNTS as $accountEntry) {
			if (!is_array($accountEntry)) {
				continue;
			}

			if (!isset($accountEntry['username'])) {
				continue;
			}

			if (!is_string($accountEntry['username'])) {
				continue;
			}

			$entryUsername = trim($accountEntry['username']);
			if ($entryUsername === '') {
				continue;
			}

			$normalizedAccount = array('username' => $entryUsername);

			if (isset($accountEntry['password']) && is_string($accountEntry['password']) && $accountEntry['password'] !== '') {
				$normalizedAccount['password'] = (string)$accountEntry['password'];
			}

			if (isset($accountEntry['hashedPassword']) && is_string($accountEntry['hashedPassword'])) {
				$normalizedHash = normalizeConfiguredPasswordHash((string)$accountEntry['hashedPassword']);
				if ($normalizedHash !== '') {
					$normalizedAccount['hashedPassword'] = $normalizedHash;
				}
			}

			if (!isset($normalizedAccount['password']) && !isset($normalizedAccount['hashedPassword'])) {
				continue;
			}

			$configuredAccounts[] = $normalizedAccount;
		}
	}

	if (isset($USERNAME, $PASSWORD) && is_string($USERNAME) && is_string($PASSWORD)) {
		$legacyUsername = trim($USERNAME);
		$legacyPassword = (string)$PASSWORD;
		if ($legacyUsername !== '' && $legacyPassword !== '') {
			$configuredAccounts[] = array(
				'username' => $legacyUsername,
				'password' => $legacyPassword,
			);
		}
	}

	return $configuredAccounts;
}

function verifyRecordingsLogin(string $username, string $password): bool
{
	return count(findMatchingConfiguredRecordingsAccount($username, $password)) > 0;
}

function findMatchingConfiguredRecordingsAccount(string $username, string $password): array
{
	$configuredAccounts = getConfiguredRecordingsAccounts();
	if (count($configuredAccounts) === 0) {
		recordingsAuthDebugLog('Configured credentials are missing; login attempt denied.', array('username' => $username));
		return array();
	}

	foreach ($configuredAccounts as $configuredAccount) {
		if (!hash_equals((string)$configuredAccount['username'], $username)) {
			continue;
		}

		if (isset($configuredAccount['password']) && hash_equals((string)$configuredAccount['password'], $password)) {
			recordingsAuthDebugLog('Configured credential verification completed.', array(
				'username' => $username,
				'success' => true,
				'configured_count' => count($configuredAccounts),
			));
			return $configuredAccount;
		}

		if (isset($configuredAccount['hashedPassword']) && verifyPasswordAgainstConfiguredHash($password, (string)$configuredAccount['hashedPassword'])) {
			recordingsAuthDebugLog('Configured credential verification completed.', array(
				'username' => $username,
				'success' => true,
				'configured_count' => count($configuredAccounts),
			));
			return $configuredAccount;
		}
	}

	recordingsAuthDebugLog('Configured credential verification completed.', array(
		'username' => $username,
		'success' => false,
		'configured_count' => count($configuredAccounts),
	));
	return array();
}

function getRecordingsAccountSecretMaterial(array $configuredAccount): string
{
	if (isset($configuredAccount['hashedPassword']) && is_string($configuredAccount['hashedPassword'])) {
		$normalizedHash = normalizeConfiguredPasswordHash((string)$configuredAccount['hashedPassword']);
		if ($normalizedHash !== '') {
			return 'hashed:' . $normalizedHash;
		}
	}

	if (isset($configuredAccount['password']) && is_string($configuredAccount['password']) && $configuredAccount['password'] !== '') {
		return 'plain:' . (string)$configuredAccount['password'];
	}

	return '';
}

function buildRecordingsAuthHashForAccount(array $configuredAccount): string
{
	$username = isset($configuredAccount['username']) && is_string($configuredAccount['username'])
		? trim((string)$configuredAccount['username'])
		: '';
	$secretMaterial = getRecordingsAccountSecretMaterial($configuredAccount);
	if ($username === '' || $secretMaterial === '') {
		return '';
	}

	return hash_hmac('sha256', 'recordings-auth|' . $username, $secretMaterial);
}

function findConfiguredRecordingsAccountByAuthHash(string $hash): array
{
	$normalizedHash = strtolower(trim($hash));
	if ($normalizedHash === '') {
		return array();
	}

	foreach (getConfiguredRecordingsAccounts() as $configuredAccount) {
		$candidateHash = buildRecordingsAuthHashForAccount($configuredAccount);
		if ($candidateHash === '') {
			continue;
		}

		if (hash_equals($candidateHash, $normalizedHash)) {
			return $configuredAccount;
		}
	}

	return array();
}

function recordingsAuthIsLoggedIn(): bool
{
	global $recordingsRequestAuthUsername;
	if (is_string($recordingsRequestAuthUsername) && $recordingsRequestAuthUsername !== '') {
		return true;
	}

	return isset($_SESSION['recordings_auth_logged_in'], $_SESSION['recordings_auth_username'])
		&& $_SESSION['recordings_auth_logged_in'] === true
		&& is_string($_SESSION['recordings_auth_username'])
		&& $_SESSION['recordings_auth_username'] !== '';
}

function getRecordingsAuthenticatedUsername(): string
{
	global $recordingsRequestAuthUsername;
	if (is_string($recordingsRequestAuthUsername) && $recordingsRequestAuthUsername !== '') {
		return $recordingsRequestAuthUsername;
	}

	if (!recordingsAuthIsLoggedIn()) {
		return '';
	}

	return (string)$_SESSION['recordings_auth_username'];
}

function getRecordingsAuthenticatedHash(): string
{
	global $recordingsRequestAuthHash;
	return is_string($recordingsRequestAuthHash) ? $recordingsRequestAuthHash : '';
}

function setRecordingsRequestAuthenticatedUser(string $username, string $via, string $hash = ''): void
{
	global $recordingsRequestAuthUsername, $recordingsRequestAuthVia, $recordingsRequestAuthHash;
	$recordingsRequestAuthUsername = $username;
	$recordingsRequestAuthVia = $via;
	$recordingsRequestAuthHash = $hash;
}

function clearRecordingsRequestAuthenticatedUser(): void
{
	global $recordingsRequestAuthUsername, $recordingsRequestAuthVia, $recordingsRequestAuthHash;
	$recordingsRequestAuthUsername = '';
	$recordingsRequestAuthVia = '';
	$recordingsRequestAuthHash = '';
}

function setRecordingsAuthenticatedUser(string $username): void
{
	session_regenerate_id(true);
	$_SESSION['recordings_auth_logged_in'] = true;
	$_SESSION['recordings_auth_username'] = $username;
	$_SESSION['recordings_auth_logged_in_at'] = time();
	setRecordingsRequestAuthenticatedUser($username, 'session');
}

function clearRecordingsAuthenticatedUser(): void
{
	clearRecordingsRequestAuthenticatedUser();
	unset($_SESSION['recordings_auth_logged_in']);
	unset($_SESSION['recordings_auth_username']);
	unset($_SESSION['recordings_auth_logged_in_at']);
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_regenerate_id(true);
	}
}

function getRecordingsRequestPath(): string
{
	$requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
	$path = parse_url($requestUri, PHP_URL_PATH);
	if (!is_string($path) || $path === '') {
		$scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
		return $scriptName !== '' ? $scriptName : 'index.php';
	}

	return $path;
}

function buildRecordingsRequestUrl(array $overrides = array(), array $removeKeys = array()): string
{
	$requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
	$queryString = parse_url($requestUri, PHP_URL_QUERY);
	$params = array();
	if (is_string($queryString) && $queryString !== '') {
		parse_str($queryString, $params);
		if (!is_array($params)) {
			$params = array();
		}
	}

	foreach ($removeKeys as $removeKey) {
		unset($params[(string)$removeKey]);
	}

	foreach ($overrides as $key => $value) {
		$key = (string)$key;
		if ($value === null || $value === '') {
			unset($params[$key]);
			continue;
		}

		$params[$key] = (string)$value;
	}

	$query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
	return getRecordingsRequestPath() . ($query !== '' ? '?' . $query : '');
}

function getRecordingsRequestHashFromRequest(): string
{
	if (isset($_GET['hash']) && is_string($_GET['hash'])) {
		return trim((string)$_GET['hash']);
	}

	if (isset($_POST['hash']) && is_string($_POST['hash'])) {
		return trim((string)$_POST['hash']);
	}

	return '';
}

function authenticateRecordingsRequestByHash(): bool
{
	$providedHash = getRecordingsRequestHashFromRequest();
	if ($providedHash === '') {
		return false;
	}

	$matchedAccount = findConfiguredRecordingsAccountByAuthHash($providedHash);
	if (count($matchedAccount) === 0) {
		recordingsAuthDebugLog('Hash authentication failed.', array('hash_present' => true));
		return false;
	}

	$username = isset($matchedAccount['username']) ? (string)$matchedAccount['username'] : '';
	setRecordingsRequestAuthenticatedUser($username, 'hash', buildRecordingsAuthHashForAccount($matchedAccount));
	recordingsAuthDebugLog('Hash authentication successful.', array('username' => $username));
	return true;
}

function respondToUnauthenticatedRecordingsRequest(string $ajaxAction): void
{
	if ($ajaxAction === 'list' || $ajaxAction === 'zip') {
		sendJsonResponse(array(
			'ok' => false,
			'error' => 'Authentication required.',
		), 401);
	}

	http_response_code(401);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-cache, no-store, must-revalidate');
	echo 'Authentication required.';
	exit;
}

function renderRecordingsLoginPage(string $pageTitle, string $errorMessage = ''): void
{
	$safeTitle = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
	$safeErrorMessage = htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8');
	$actionPath = htmlspecialchars(getRecordingsRequestPath(), ENT_QUOTES, 'UTF-8');
	$hasConfiguredCredentials = (count(getConfiguredRecordingsAccounts()) > 0);
	$helperText = $hasConfiguredCredentials
		? 'Sign in with the configured recordings credentials.'
		: 'Login is disabled until ACCOUNTS (or USERNAME and PASSWORD) are configured.';

	http_response_code($errorMessage !== '' ? 401 : 200);
	echo '<!DOCTYPE html>';
	echo '<html><head>';
	echo '<title>' . $safeTitle . ' Login</title>';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<style type="text/css">';
	echo 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(160deg,#0f1419,#1e2b36);color:#e7edf3;font-family:Arial,Helvetica,sans-serif;padding:16px;box-sizing:border-box;}';
	echo '.login-card{width:100%;max-width:420px;background:#182129;border:1px solid #31414f;border-radius:12px;padding:24px;box-shadow:0 18px 48px rgba(0,0,0,0.35);}';
	echo '.login-card h1{margin:0 0 8px;font-size:24px;}';
	echo '.login-card p{margin:0 0 18px;color:#b8c7d6;line-height:1.5;}';
	echo '.login-error{margin:0 0 16px;padding:10px 12px;border:1px solid #8a2b2b;border-radius:8px;background:#4a1d1d;color:#ffd8d8;}';
	echo '.login-field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px;}';
	echo '.login-field label{font-size:13px;font-weight:bold;color:#d8e4ef;}';
	echo '.login-field input{min-height:42px;padding:10px 12px;border:1px solid #435363;border-radius:8px;background:#0f151a;color:#f2f7fb;font-size:14px;box-sizing:border-box;}';
	echo '.login-submit{width:100%;min-height:42px;border:1px solid #5b88b8;border-radius:8px;background:#2b5f96;color:#ffffff;font-size:15px;font-weight:bold;cursor:pointer;}';
	echo '.login-submit:hover{background:#3471b1;}';
	echo '</style></head><body>';
	echo '<form class="login-card" method="post" action="' . $actionPath . '">';
	echo '<h1>' . $safeTitle . '</h1>';
	//echo '<p>' . htmlspecialchars($helperText, ENT_QUOTES, 'UTF-8') . '</p>';
	if ($safeErrorMessage !== '') {
		echo '<div class="login-error">' . $safeErrorMessage . '</div>';
	}
	echo '<div class="login-field"><label for="login_username">Username</label><input type="text" id="login_username" name="login_username" autocomplete="username" required></div>';
	echo '<div class="login-field"><label for="login_password">Password</label><input type="password" id="login_password" name="login_password" autocomplete="current-password" required></div>';
	echo '<button type="submit" class="login-submit">Sign In</button>';
	echo '</form></body></html>';
	exit;
}

function enforceRecordingsAuthentication(bool $authenticateEnabled, string $pageTitle, string $ajaxAction): void
{
	if ($authenticateEnabled !== true) {
		return;
	}

	recordingsAuthDebugLog('Authentication enforcement active.', array(
		'ajax_action' => $ajaxAction,
		'method' => isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : '',
		'uri' => isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '',
		'remote_addr' => isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '',
	));

	startRecordingsAuthSession();

	if (isset($_GET['logout']) && (string)$_GET['logout'] === '1') {
		recordingsAuthDebugLog('Logout requested.', array('username' => getRecordingsAuthenticatedUsername()));
		clearRecordingsAuthenticatedUser();
		renderRecordingsLoginPage($pageTitle);
	}

	if (recordingsAuthIsLoggedIn()) {
		recordingsAuthDebugLog('Session already authenticated.', array('username' => getRecordingsAuthenticatedUsername()));
		return;
	}

	if (authenticateRecordingsRequestByHash()) {
		return;
	}

	$loginError = '';
	if (isset($_GET['username']) || isset($_GET['password'])) {
		$username = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
		$password = isset($_GET['password']) ? (string)$_GET['password'] : '';
		recordingsAuthDebugLog('Query login submitted.', array(
			'username' => $username,
			'has_password' => ($password !== ''),
		));

		$matchedAccount = findMatchingConfiguredRecordingsAccount($username, $password);
		if (count($matchedAccount) > 0) {
			$authHash = buildRecordingsAuthHashForAccount($matchedAccount);
			recordingsAuthDebugLog('Query login successful.', array('username' => $username));
			setRecordingsAuthenticatedUser($username);
			if ($authHash !== '') {
				setRecordingsRequestAuthenticatedUser($username, 'hash', $authHash);
			}
			if ($ajaxAction === '') {
				header('Location: ' . buildRecordingsRequestUrl(array('hash' => $authHash), array('username', 'password', 'logout')));
				exit;
			}

			return;
		}

		recordingsAuthDebugLog('Query login failed.', array('username' => $username));
		$loginError = (count(getConfiguredRecordingsAccounts()) === 0)
			? 'Server credentials are not configured. Set ACCOUNTS (or USERNAME and PASSWORD) first.'
			: 'Invalid username or password.';
	}

	if (
		isset($_SERVER['REQUEST_METHOD'])
		&& strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'POST'
		&& isset($_POST['login_username'], $_POST['login_password'])
	) {
		$username = trim((string)$_POST['login_username']);
		$password = (string)$_POST['login_password'];
		recordingsAuthDebugLog('Login form submitted.', array('username' => $username));

		if (verifyRecordingsLogin($username, $password)) {
			recordingsAuthDebugLog('Login successful.', array('username' => $username));
			setRecordingsAuthenticatedUser($username);
			header('Location: ' . buildRecordingsRequestUrl(array(), array('hash', 'logout')));
			exit;
		}

		recordingsAuthDebugLog('Login failed.', array('username' => $username));
		$loginError = (count(getConfiguredRecordingsAccounts()) === 0)
			? 'Server credentials are not configured. Set ACCOUNTS (or USERNAME and PASSWORD) first.'
			: 'Invalid username or password.';
	}

	if ($ajaxAction !== '') {
		recordingsAuthDebugLog('Blocking unauthenticated ajax request.', array('ajax_action' => $ajaxAction));
		respondToUnauthenticatedRecordingsRequest($ajaxAction);
	}

	renderRecordingsLoginPage($pageTitle, $loginError);
}


function sendJsonResponse(array $payload, int $statusCode = 200): void
{
	http_response_code($statusCode);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-cache, no-store, must-revalidate');
	$jsonOptions = 0;
	if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
		$jsonOptions |= JSON_INVALID_UTF8_SUBSTITUTE;
	}

	$encodedPayload = json_encode($payload, $jsonOptions);
	if ($encodedPayload === false) {
		http_response_code(500);
		$fallbackPayload = array(
			'ok' => false,
			'error' => 'Failed to encode JSON response: ' . json_last_error_msg(),
		);

		$encodedPayload = json_encode($fallbackPayload);
		if ($encodedPayload === false) {
			$encodedPayload = '{"ok":false,"error":"Failed to encode JSON response."}';
		}
	}

	echo $encodedPayload;
	exit;
}

function getRecordingsAuthorizationHeader(): string
{
	if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
		return trim((string)$_SERVER['HTTP_AUTHORIZATION']);
	}

	if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
		return trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
	}

	return '';
}

function decodeRecordingsBasicAuthorization(string $headerValue): array
{
	$empty = array('username' => '', 'password' => '');
	if ($headerValue === '' || stripos($headerValue, 'Basic ') !== 0) {
		return $empty;
	}

	$encodedPart = trim(substr($headerValue, 6));
	if ($encodedPart === '') {
		return $empty;
	}

	$decodedPart = base64_decode($encodedPart, true);
	if (!is_string($decodedPart) || $decodedPart === '' || strpos($decodedPart, ':') === false) {
		return $empty;
	}

	$pair = explode(':', $decodedPart, 2);
	return array(
		'username' => trim((string)$pair[0]),
		'password' => (string)$pair[1],
	);
}

function getRecordingsIngestCredentialsFromRequest(): array
{
	if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
		return array(
			'username' => trim((string)$_SERVER['PHP_AUTH_USER']),
			'password' => (string)$_SERVER['PHP_AUTH_PW'],
		);
	}

	$decodedBasic = decodeRecordingsBasicAuthorization(getRecordingsAuthorizationHeader());
	if ($decodedBasic['username'] !== '' || $decodedBasic['password'] !== '') {
		return $decodedBasic;
	}

	$usernameCandidates = array(
		isset($_POST['username']) ? (string)$_POST['username'] : '',
		isset($_POST['api_username']) ? (string)$_POST['api_username'] : '',
		isset($_POST['login_username']) ? (string)$_POST['login_username'] : '',
	);
	$passwordCandidates = array(
		isset($_POST['password']) ? (string)$_POST['password'] : '',
		isset($_POST['api_password']) ? (string)$_POST['api_password'] : '',
		isset($_POST['login_password']) ? (string)$_POST['login_password'] : '',
	);

	$username = '';
	foreach ($usernameCandidates as $candidate) {
		$trimmed = trim($candidate);
		if ($trimmed !== '') {
			$username = $trimmed;
			break;
		}
	}

	$password = '';
	foreach ($passwordCandidates as $candidate) {
		if ((string)$candidate !== '') {
			$password = (string)$candidate;
			break;
		}
	}

	return array('username' => $username, 'password' => $password);
}

function recordingsIngestRequestAuthorized(bool $authenticateEnabled): bool
{
	if ($authenticateEnabled !== true) {
		return true;
	}

	startRecordingsAuthSession();
	if (recordingsAuthIsLoggedIn()) {
		return true;
	}

	if (authenticateRecordingsRequestByHash()) {
		return true;
	}

	$credentials = getRecordingsIngestCredentialsFromRequest();
	$username = trim((string)$credentials['username']);
	$password = (string)$credentials['password'];
	if ($username === '' || $password === '') {
		recordingsAuthDebugLog('Ingest authentication failed: missing credentials.');
		return false;
	}

	$success = verifyRecordingsLogin($username, $password);
	recordingsAuthDebugLog('Ingest authentication attempt completed.', array(
		'username' => $username,
		'success' => $success,
	));
	return $success;
}

function sanitizeRecordingsIngestFilename(string $rawName): string
{
	$fileName = basename(str_replace('\\', '/', trim($rawName)));
	if ($fileName === '') {
		return '';
	}

	if (function_exists('iconv')) {
		$converted = @iconv('UTF-8', 'UTF-8//IGNORE', $fileName);
		if (is_string($converted) && $converted !== '') {
			$fileName = $converted;
		}
	}

	$fileName = preg_replace('/[[:cntrl:]]+/', '', $fileName) ?? $fileName;
	$fileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName) ?? $fileName;
	$fileName = trim($fileName, " ._-");
	if ($fileName === '' || $fileName === '.' || $fileName === '..') {
		return '';
	}

	$extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
	$baseName = (string)pathinfo($fileName, PATHINFO_FILENAME);
	$baseName = trim($baseName, " ._-");
	if ($baseName === '' || $extension === '') {
		return '';
	}

	if (strlen($baseName) > 120) {
		$baseName = substr($baseName, 0, 120);
	}

	return $baseName . '.' . $extension;
}

function detectRecordingsDateFolderFromFilename(string $fileName): string
{
	$baseName = (string)pathinfo($fileName, PATHINFO_FILENAME);

	if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:$|[_-])/', $baseName, $matches)) {
		$parts = explode('-', $matches[1]);
		if (count($parts) === 3 && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
			return $matches[1];
		}
	}

	if (preg_match('/^(\d{4})(\d{2})(\d{2})(?:$|[_-])/', $baseName, $matches)) {
		$year = (int)$matches[1];
		$month = (int)$matches[2];
		$day = (int)$matches[3];
		if (checkdate($month, $day, $year)) {
			return sprintf('%04d-%02d-%02d', $year, $month, $day);
		}
	}

	if (preg_match('/(\d{4})[-_](\d{2})[-_](\d{2})/', $baseName, $matches)) {
		$year = (int)$matches[1];
		$month = (int)$matches[2];
		$day = (int)$matches[3];
		if (checkdate($month, $day, $year)) {
			return sprintf('%04d-%02d-%02d', $year, $month, $day);
		}
	}

	return date('Y-m-d');
}

function resolveRecordingsIngestUploadErrorMessage(int $uploadError): string
{
	if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
		return 'Uploaded recording exceeds server upload limits.';
	}

	if ($uploadError === UPLOAD_ERR_PARTIAL) {
		return 'Uploaded recording was only partially received.';
	}

	if ($uploadError === UPLOAD_ERR_NO_FILE) {
		return 'No recording file was uploaded.';
	}

	if ($uploadError === UPLOAD_ERR_NO_TMP_DIR) {
		return 'Server temporary upload directory is missing.';
	}

	if ($uploadError === UPLOAD_ERR_CANT_WRITE) {
		return 'Server failed to write the uploaded recording.';
	}

	if ($uploadError === UPLOAD_ERR_EXTENSION) {
		return 'A PHP extension rejected the upload.';
	}

	return 'Upload failed with error code ' . $uploadError . '.';
}

function resolveRecordingsIngestUploadRequest(): array
{
	if (isset($_FILES) && is_array($_FILES) && count($_FILES) > 0) {
		$selectedUpload = null;
		if (isset($_FILES['recording']) && is_array($_FILES['recording'])) {
			$selectedUpload = $_FILES['recording'];
		} else {
			foreach ($_FILES as $uploadCandidate) {
				if (is_array($uploadCandidate)) {
					$selectedUpload = $uploadCandidate;
					break;
				}
			}
		}

		if (is_array($selectedUpload)) {
			$uploadError = isset($selectedUpload['error']) ? (int)$selectedUpload['error'] : UPLOAD_ERR_NO_FILE;
			if ($uploadError !== UPLOAD_ERR_OK) {
				return array(
					'ok' => false,
					'status_code' => 400,
					'error' => resolveRecordingsIngestUploadErrorMessage($uploadError),
				);
			}

			$tmpPath = isset($selectedUpload['tmp_name']) ? (string)$selectedUpload['tmp_name'] : '';
			$originalName = isset($selectedUpload['name']) ? (string)$selectedUpload['name'] : '';
			if ($tmpPath === '' || $originalName === '') {
				return array(
					'ok' => false,
					'status_code' => 400,
					'error' => 'Upload payload is incomplete.',
				);
			}

			if (!is_uploaded_file($tmpPath)) {
				return array(
					'ok' => false,
					'status_code' => 400,
					'error' => 'Upload source is invalid.',
				);
			}

			return array(
				'ok' => true,
				'tmp_path' => $tmpPath,
				'original_name' => $originalName,
				'is_uploaded_file' => true,
				'cleanup' => false,
			);
		}
	}

	$rawBody = file_get_contents('php://input');
	if (!is_string($rawBody) || $rawBody === '') {
		return array(
			'ok' => false,
			'status_code' => 400,
			'error' => 'No recording payload provided.',
		);
	}

	$rawName = '';
	if (isset($_POST['filename']) && is_string($_POST['filename']) && trim($_POST['filename']) !== '') {
		$rawName = trim((string)$_POST['filename']);
	} elseif (isset($_GET['filename']) && is_string($_GET['filename']) && trim($_GET['filename']) !== '') {
		$rawName = trim((string)$_GET['filename']);
	} elseif (isset($_SERVER['HTTP_X_RECORDING_FILENAME']) && is_string($_SERVER['HTTP_X_RECORDING_FILENAME'])) {
		$rawName = trim((string)$_SERVER['HTTP_X_RECORDING_FILENAME']);
	}

	if ($rawName === '') {
		return array(
			'ok' => false,
			'status_code' => 400,
			'error' => 'Filename is required when posting raw recording bytes.',
		);
	}

	$tmpPath = tempnam(sys_get_temp_dir(), 'recording_ingest_');
	if ($tmpPath === false) {
		return array(
			'ok' => false,
			'status_code' => 500,
			'error' => 'Failed to allocate temporary upload file.',
		);
	}

	if (file_put_contents($tmpPath, $rawBody, LOCK_EX) === false) {
		@unlink($tmpPath);
		return array(
			'ok' => false,
			'status_code' => 500,
			'error' => 'Failed to persist uploaded recording payload.',
		);
	}

	return array(
		'ok' => true,
		'tmp_path' => $tmpPath,
		'original_name' => $rawName,
		'is_uploaded_file' => false,
		'cleanup' => true,
	);
}

function storeRecordingIngestedFile(
	string $rootDirectory,
	array $allowedExtensions,
	string $tmpPath,
	string $originalName,
	bool $isUploadedFile
): array {
	$sanitizedName = sanitizeRecordingsIngestFilename($originalName);
	if ($sanitizedName === '') {
		return array(
			'ok' => false,
			'status_code' => 400,
			'error' => 'Filename is invalid.',
		);
	}

	$extension = strtolower((string)pathinfo($sanitizedName, PATHINFO_EXTENSION));
	if (!in_array($extension, $allowedExtensions, true)) {
		return array(
			'ok' => false,
			'status_code' => 400,
			'error' => 'Unsupported recording type: .' . $extension,
		);
	}

	if (!is_dir($rootDirectory) && !mkdir($rootDirectory, 0775, true) && !is_dir($rootDirectory)) {
		return array(
			'ok' => false,
			'status_code' => 500,
			'error' => 'Recordings root directory is not writable.',
		);
	}

	$dateFolder = detectRecordingsDateFolderFromFilename($sanitizedName);
	$targetDir = rtrim($rootDirectory, '/\\') . DIRECTORY_SEPARATOR . $dateFolder;
	if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
		return array(
			'ok' => false,
			'status_code' => 500,
			'error' => 'Failed to create destination directory for recordings.',
		);
	}

	if (!is_writable($targetDir)) {
		return array(
			'ok' => false,
			'status_code' => 500,
			'error' => 'Destination directory is not writable: ' . $targetDir,
		);
	}

	$baseName = (string)pathinfo($sanitizedName, PATHINFO_FILENAME);
	$targetFileName = $sanitizedName;
	$targetPath = $targetDir . DIRECTORY_SEPARATOR . $targetFileName;
	for ($suffix = 1; file_exists($targetPath) && $suffix <= 10000; $suffix++) {
		$targetFileName = $baseName . '_' . $suffix . '.' . $extension;
		$targetPath = $targetDir . DIRECTORY_SEPARATOR . $targetFileName;
	}

	if (file_exists($targetPath)) {
		return array(
			'ok' => false,
			'status_code' => 500,
			'error' => 'Failed to allocate a unique destination filename.',
		);
	}

	$writeSuccess = false;
	if ($isUploadedFile) {
		$writeSuccess = move_uploaded_file($tmpPath, $targetPath);
	}
	if (!$writeSuccess) {
		$writeSuccess = @rename($tmpPath, $targetPath);
	}
	if (!$writeSuccess) {
		$writeSuccess = @copy($tmpPath, $targetPath);
		if ($writeSuccess) {
			@unlink($tmpPath);
		}
	}

	if (!$writeSuccess || !is_file($targetPath)) {
		return array(
			'ok' => false,
			'status_code' => 500,
			'error' => 'Failed to store uploaded recording.',
		);
	}

	@chmod($targetPath, 0664);
	$fileSize = filesize($targetPath);

	return array(
		'ok' => true,
		'status_code' => 201,
		'file_name' => $targetFileName,
		'date_folder' => $dateFolder,
		'relative_path' => $dateFolder . '/' . $targetFileName,
		'size_bytes' => is_int($fileSize) ? $fileSize : 0,
	);
}

function handleRecordingsIngestApi(string $rootDirectory, array $allowedExtensions, bool $authenticateEnabled): void
{
	$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
	if ($method === 'OPTIONS') {
		header('Allow: POST, OPTIONS');
		header('Access-Control-Allow-Origin: *');
		header('Access-Control-Allow-Methods: POST, OPTIONS');
		header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Recording-Filename');
		http_response_code(204);
		exit;
	}

	if ($method !== 'POST') {
		header('Allow: POST, OPTIONS');
		sendJsonResponse(array('ok' => false, 'error' => 'Method not allowed.'), 405);
	}

	if (!recordingsIngestRequestAuthorized($authenticateEnabled)) {
		sendJsonResponse(array('ok' => false, 'error' => 'Authentication required for ingest API.'), 401);
	}

	$upload = resolveRecordingsIngestUploadRequest();
	if ($upload['ok'] !== true) {
		sendJsonResponse(array('ok' => false, 'error' => (string)$upload['error']), (int)$upload['status_code']);
	}

	$saveResult = storeRecordingIngestedFile(
		$rootDirectory,
		$allowedExtensions,
		(string)$upload['tmp_path'],
		(string)$upload['original_name'],
		isset($upload['is_uploaded_file']) && $upload['is_uploaded_file'] === true
	);

	if (isset($upload['cleanup']) && $upload['cleanup'] === true && isset($upload['tmp_path'])) {
		@unlink((string)$upload['tmp_path']);
	}

	if ($saveResult['ok'] !== true) {
		sendJsonResponse(array('ok' => false, 'error' => (string)$saveResult['error']), (int)$saveResult['status_code']);
	}

	sendJsonResponse(array(
		'ok' => true,
		'message' => 'Recording ingested.',
		'file' => array(
			'name' => (string)$saveResult['file_name'],
			'date' => (string)$saveResult['date_folder'],
			'path' => (string)$saveResult['relative_path'],
			'size_bytes' => (int)$saveResult['size_bytes'],
		),
	), 201);
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

function decodeId3FrameSize(string $bytes, int $id3VersionMajor): int
{
	if (strlen($bytes) !== 4) {
		return 0;
	}

	if ($id3VersionMajor === 4) {
		return decodeSynchsafeInt($bytes);
	}

	$sizeData = unpack('Nsize', $bytes);
	return isset($sizeData['size']) ? (int)$sizeData['size'] : 0;
}

function normalizeMetadataTextValue(string $value): string
{
	$value = str_replace("\x00", ' ', $value);
	$value = preg_replace('/\s+/', ' ', $value);
	return trim((string)$value);
}

function decodeId3EncodedText(string $bytes, int $encodingByte): string
{
	if ($bytes === '') {
		return '';
	}

	$decodedText = '';
	if ($encodingByte === 1 || $encodingByte === 2) {
		$sourceEncoding = ($encodingByte === 1) ? 'UTF-16' : 'UTF-16BE';
		if (function_exists('mb_convert_encoding')) {
			$converted = @mb_convert_encoding($bytes, 'UTF-8', $sourceEncoding);
			if (is_string($converted)) {
				$decodedText = $converted;
			}
		}

		if ($decodedText === '' && function_exists('iconv')) {
			$converted = @iconv($sourceEncoding, 'UTF-8//IGNORE', $bytes);
			if (is_string($converted)) {
				$decodedText = $converted;
			}
		}
	} elseif ($encodingByte === 0) {
		if (function_exists('mb_convert_encoding')) {
			$converted = @mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
			if (is_string($converted)) {
				$decodedText = $converted;
			}
		} elseif (function_exists('iconv')) {
			$converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $bytes);
			if (is_string($converted)) {
				$decodedText = $converted;
			}
		}
	}

	if ($decodedText === '') {
		$decodedText = $bytes;
	}

	return normalizeMetadataTextValue($decodedText);
}

function decodeId3TextFramePayload(string $payload): string
{
	if ($payload === '') {
		return '';
	}

	$encodingByte = ord($payload[0]);
	return decodeId3EncodedText(substr($payload, 1), $encodingByte);
}

function decodeId3CommentFramePayload(string $payload): string
{
	if (strlen($payload) < 4) {
		return '';
	}

	$encodingByte = ord($payload[0]);
	$commentBytes = substr($payload, 4);
	if ($commentBytes === '') {
		return '';
	}

	if ($encodingByte === 1 || $encodingByte === 2) {
		$separatorPosition = strpos($commentBytes, "\x00\x00");
		if ($separatorPosition !== false) {
			$commentBytes = substr($commentBytes, $separatorPosition + 2);
		}
	} else {
		$separatorPosition = strpos($commentBytes, "\x00");
		if ($separatorPosition !== false) {
			$commentBytes = substr($commentBytes, $separatorPosition + 1);
		}
	}

	return decodeId3EncodedText($commentBytes, $encodingByte);
}

function extractMp3TagTextMetadata(string $fullPath, int $fileSize): array
{
	$metadata = array(
		'comment' => null,
		'title' => null,
		'artist' => null,
	);

	if ($fileSize <= 10) {
		return $metadata;
	}

	$handle = fopen($fullPath, 'rb');
	if ($handle === false) {
		return $metadata;
	}

	$id3Header = fread($handle, 10);
	if ($id3Header === false || strlen($id3Header) < 10 || substr($id3Header, 0, 3) !== 'ID3') {
		fclose($handle);
		return $metadata;
	}

	$id3VersionMajor = ord($id3Header[3]);
	if ($id3VersionMajor < 3 || $id3VersionMajor > 4) {
		fclose($handle);
		return $metadata;
	}

	$id3TagSize = decodeSynchsafeInt(substr($id3Header, 6, 4));
	if ($id3TagSize <= 0) {
		fclose($handle);
		return $metadata;
	}

	$id3TagData = fread($handle, min($id3TagSize, 262144));
	fclose($handle);

	if ($id3TagData === false || strlen($id3TagData) < 10) {
		return $metadata;
	}

	$offset = 0;
	$tagLength = strlen($id3TagData);
	while (($offset + 10) <= $tagLength) {
		$frameId = substr($id3TagData, $offset, 4);
		if ($frameId === "\x00\x00\x00\x00") {
			break;
		}

		if (!preg_match('/^[A-Z0-9]{4}$/', $frameId)) {
			break;
		}

		$frameSize = decodeId3FrameSize(substr($id3TagData, $offset + 4, 4), $id3VersionMajor);
		$frameDataOffset = $offset + 10;
		if ($frameSize <= 0 || ($frameDataOffset + $frameSize) > $tagLength) {
			break;
		}

		$framePayload = substr($id3TagData, $frameDataOffset, $frameSize);
		if ($frameId === 'COMM' && $metadata['comment'] === null) {
			$decodedComment = decodeId3CommentFramePayload($framePayload);
			if ($decodedComment !== '') {
				$metadata['comment'] = $decodedComment;
			}
		} elseif ($frameId === 'TIT2' && $metadata['title'] === null) {
			$decodedTitle = decodeId3TextFramePayload($framePayload);
			if ($decodedTitle !== '') {
				$metadata['title'] = $decodedTitle;
			}
		} elseif ($frameId === 'TPE1' && $metadata['artist'] === null) {
			$decodedArtist = decodeId3TextFramePayload($framePayload);
			if ($decodedArtist !== '') {
				$metadata['artist'] = $decodedArtist;
			}
		}

		if ($metadata['comment'] !== null && $metadata['title'] !== null && $metadata['artist'] !== null) {
			break;
		}

		$offset = $frameDataOffset + $frameSize;
	}

	return $metadata;
}

function unpackUnsignedInt32LE(string $data, int $offset): ?int
{
	if (($offset + 4) > strlen($data)) {
		return null;
	}

	$unpacked = unpack('Vvalue', substr($data, $offset, 4));
	if (!isset($unpacked['value'])) {
		return null;
	}

	return (int)$unpacked['value'];
}

function parseVorbisCommentPacketFields(string $packet): array
{
	$metadata = array(
		'comment' => null,
		'title' => null,
		'artist' => null,
	);

	$packetLength = strlen($packet);
	if ($packetLength < 8) {
		return $metadata;
	}

	$offset = 0;
	$vendorLength = unpackUnsignedInt32LE($packet, $offset);
	if ($vendorLength === null || $vendorLength < 0) {
		return $metadata;
	}

	$offset += 4 + $vendorLength;
	if ($offset > $packetLength) {
		return $metadata;
	}

	$commentCount = unpackUnsignedInt32LE($packet, $offset);
	if ($commentCount === null || $commentCount < 0) {
		return $metadata;
	}

	$offset += 4;
	$maxCommentCount = min($commentCount, 512);
	for ($commentIndex = 0; $commentIndex < $maxCommentCount; $commentIndex++) {
		$entryLength = unpackUnsignedInt32LE($packet, $offset);
		if ($entryLength === null || $entryLength < 0) {
			break;
		}

		$offset += 4;
		if (($offset + $entryLength) > $packetLength) {
			break;
		}

		$entry = substr($packet, $offset, $entryLength);
		$offset += $entryLength;

		$separatorPosition = strpos($entry, '=');
		if ($separatorPosition === false) {
			continue;
		}

		$key = strtoupper((string)substr($entry, 0, $separatorPosition));
		$value = normalizeMetadataTextValue((string)substr($entry, $separatorPosition + 1));
		if ($value === '') {
			continue;
		}

		if ($key === 'COMMENT' && $metadata['comment'] === null) {
			$metadata['comment'] = $value;
		} elseif ($key === 'TITLE' && $metadata['title'] === null) {
			$metadata['title'] = $value;
		} elseif ($key === 'ARTIST' && $metadata['artist'] === null) {
			$metadata['artist'] = $value;
		}

		if ($metadata['comment'] !== null && $metadata['title'] !== null && $metadata['artist'] !== null) {
			break;
		}
	}

	return $metadata;
}

function extractOggTagTextMetadata(string $fullPath, int $fileSize): array
{
	$metadata = array(
		'comment' => null,
		'title' => null,
		'artist' => null,
	);

	if ($fileSize <= 0) {
		return $metadata;
	}

	$handle = fopen($fullPath, 'rb');
	if ($handle === false) {
		return $metadata;
	}

	$bytesToRead = min($fileSize, 262144);
	$data = ($bytesToRead > 0) ? fread($handle, $bytesToRead) : '';
	fclose($handle);

	if ($data === false || $data === '') {
		return $metadata;
	}

	$opusTagPosition = strpos($data, 'OpusTags');
	if ($opusTagPosition !== false) {
		$parsed = parseVorbisCommentPacketFields(substr($data, $opusTagPosition + 8));
		if ($parsed['comment'] !== null || $parsed['title'] !== null) {
			return $parsed;
		}
	}

	$vorbisCommentPosition = strpos($data, "\x03vorbis");
	if ($vorbisCommentPosition !== false) {
		$parsed = parseVorbisCommentPacketFields(substr($data, $vorbisCommentPosition + 7));
		if ($parsed['comment'] !== null || $parsed['title'] !== null) {
			return $parsed;
		}
	}

	$fallbackCommentMatch = array();
	if (preg_match('/COMMENT=([^\x00\r\n]+)/i', $data, $fallbackCommentMatch)) {
		$fallbackComment = normalizeMetadataTextValue((string)$fallbackCommentMatch[1]);
		if ($fallbackComment !== '') {
			$metadata['comment'] = $fallbackComment;
		}
	}

	$fallbackTitleMatch = array();
	if (preg_match('/TITLE=([^\x00\r\n]+)/i', $data, $fallbackTitleMatch)) {
		$fallbackTitle = normalizeMetadataTextValue((string)$fallbackTitleMatch[1]);
		if ($fallbackTitle !== '') {
			$metadata['title'] = $fallbackTitle;
		}
	}

	$fallbackArtistMatch = array();
	if (preg_match('/ARTIST=([^\x00\r\n]+)/i', $data, $fallbackArtistMatch)) {
		$fallbackArtist = normalizeMetadataTextValue((string)$fallbackArtistMatch[1]);
		if ($fallbackArtist !== '') {
			$metadata['artist'] = $fallbackArtist;
		}
	}

	return $metadata;
}

function emptySourceMetadata(): array
{
	return array(
		'comment' => null,
		'title' => null,
		'artist' => null,
		'url' => null,
	);
}

function extractSourceMetadataFromText(string $text): array
{
	if ($text === '') {
		return emptySourceMetadata();
	}

	if (!preg_match('/(Source\s*URL:\s*(https?:\/\/[^\s"\'<>\x00]+))/i', $text, $matches)) {
		return emptySourceMetadata();
	}

	$detectedUrl = trim((string)$matches[2]);
	$detectedUrl = rtrim($detectedUrl, ".,;)]}\x00");
	if ($detectedUrl === '' || filter_var($detectedUrl, FILTER_VALIDATE_URL) === false) {
		return emptySourceMetadata();
	}

	return array(
		'comment' => 'Source URL: ' . $detectedUrl,
		'title' => null,
		'url' => $detectedUrl,
	);
}

function extractSourceMetadataFromMetadataText(string $text): array
{
	$sourceMetadata = extractSourceMetadataFromText($text);
	if ($sourceMetadata['url'] !== null) {
		return $sourceMetadata;
	}

	if (!preg_match('/(https?:\/\/[^\s"\'<>\x00]+)/i', $text, $matches)) {
		return emptySourceMetadata();
	}

	$detectedUrl = trim((string)$matches[1]);
	$detectedUrl = rtrim($detectedUrl, ".,;)]}\x00");
	if ($detectedUrl === '' || filter_var($detectedUrl, FILTER_VALIDATE_URL) === false) {
		return emptySourceMetadata();
	}

	return array(
		'comment' => 'Source URL: ' . $detectedUrl,
		'title' => null,
		'url' => $detectedUrl,
	);
}

function extractSourceMetadataFromChunk(string $chunk): array
{
	if ($chunk === '') {
		return emptySourceMetadata();
	}

	$directMatch = extractSourceMetadataFromText($chunk);
	if ($directMatch['url'] !== null) {
		return $directMatch;
	}

	if (strpos($chunk, "\x00") !== false) {
		$withoutNullBytes = str_replace("\x00", '', $chunk);
		$collapsedMatch = extractSourceMetadataFromText($withoutNullBytes);
		if ($collapsedMatch['url'] !== null) {
			return $collapsedMatch;
		}
	}

	return emptySourceMetadata();
}

function detectSourceMetadataForRecording(string $fullPath, int $fileSize, string $extension): array
{
	$metadata = emptySourceMetadata();
	if ($fileSize <= 0) {
		return $metadata;
	}

	$normalizedExtension = strtolower($extension);
	$tagMetadata = array('comment' => null, 'title' => null);
	if ($normalizedExtension === 'mp3') {
		$tagMetadata = extractMp3TagTextMetadata($fullPath, $fileSize);
	} elseif ($normalizedExtension === 'ogg') {
		$tagMetadata = extractOggTagTextMetadata($fullPath, $fileSize);
	}

	if (isset($tagMetadata['comment']) && is_string($tagMetadata['comment'])) {
		$normalizedComment = normalizeMetadataTextValue($tagMetadata['comment']);
		if ($normalizedComment !== '') {
			$metadata['comment'] = $normalizedComment;
		}
	}

	if (isset($tagMetadata['title']) && is_string($tagMetadata['title'])) {
		$normalizedTitle = normalizeMetadataTextValue($tagMetadata['title']);
		if ($normalizedTitle !== '') {
			$metadata['title'] = $normalizedTitle;
		}
	}

	if (isset($tagMetadata['artist']) && is_string($tagMetadata['artist'])) {
		$normalizedArtist = normalizeMetadataTextValue($tagMetadata['artist']);
		if ($normalizedArtist !== '') {
			$metadata['artist'] = $normalizedArtist;
		}
	}

	foreach (array('comment', 'title') as $metadataFieldName) {
		if (!isset($metadata[$metadataFieldName]) || !is_string($metadata[$metadataFieldName])) {
			continue;
		}

		$fieldValue = trim($metadata[$metadataFieldName]);
		if ($fieldValue === '') {
			continue;
		}

		$fieldSourceMetadata = extractSourceMetadataFromMetadataText($fieldValue);
		if ($fieldSourceMetadata['url'] !== null) {
			$metadata['url'] = $fieldSourceMetadata['url'];
			if ($metadata['comment'] === null || trim((string)$metadata['comment']) === '') {
				$metadata['comment'] = $fieldSourceMetadata['comment'];
			}
			return $metadata;
		}
	}

	$handle = fopen($fullPath, 'rb');
	if ($handle === false) {
		return $metadata;
	}

	$headBytesToRead = min($fileSize, 131072);
	if ($headBytesToRead > 0) {
		$headChunk = fread($handle, $headBytesToRead);
		if ($headChunk !== false) {
			$headMetadata = extractSourceMetadataFromChunk((string)$headChunk);
			if ($headMetadata['url'] !== null) {
				if ($metadata['comment'] === null) {
					$metadata['comment'] = $headMetadata['comment'];
				}
				$metadata['url'] = $headMetadata['url'];
				fclose($handle);
				return $metadata;
			}
		}
	}

	$tailBytesToRead = min($fileSize, 16384);
	if ($tailBytesToRead > 0) {
		$tailOffset = max(0, $fileSize - $tailBytesToRead);
		fseek($handle, $tailOffset);
		$tailChunk = fread($handle, $tailBytesToRead);
		if ($tailChunk !== false) {
			$tailMetadata = extractSourceMetadataFromChunk((string)$tailChunk);
			if ($tailMetadata['url'] !== null) {
				if ($metadata['comment'] === null) {
					$metadata['comment'] = $tailMetadata['comment'];
				}
				$metadata['url'] = $tailMetadata['url'];
				fclose($handle);
				return $metadata;
			}
		}
	}

	fclose($handle);

	return $metadata;
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

function recordingCachePath(string $fullPath): string
{
	return dirname($fullPath) . DIRECTORY_SEPARATOR . pathinfo($fullPath, PATHINFO_FILENAME) . '.json';
}

function readRecordingCache(string $fullPath, int $recordingMtime): ?array
{
	$cachePath = recordingCachePath($fullPath);
	if (!is_file($cachePath)) {
		return null;
	}

	$raw = @file_get_contents($cachePath);
	if ($raw === false || $raw === '') {
		return null;
	}

	$data = @json_decode($raw, true);
	if (!is_array($data)) {
		return null;
	}

	// Invalidate cache if the recording has been modified since the cache was written.
	if (!isset($data['recording_mtime']) || (int)$data['recording_mtime'] !== $recordingMtime) {
		return null;
	}

	// Invalidate cache if it was written before filesize/md5/artist fields were added.
	if (!array_key_exists('filesize_bytes', $data) || !array_key_exists('md5_checksum', $data) || !array_key_exists('metadata_artist', $data)) {
		return null;
	}

	// Invalidate cache if the file type has changed (e.g. .wav replaced by .mp3).
	if (isset($data['content_type'])) {
		$currentContentType = getRecordingMimeType(strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)));
		if ($data['content_type'] !== $currentContentType) {
			return null;
		}
	}

	return $data;
}

function writeRecordingCache(string $fullPath, int $recordingMtime, array $payload): void
{
	$cachePath = recordingCachePath($fullPath);
	$payload['recording_mtime'] = $recordingMtime;

	// Sanitize any non-finite float values (INF, NAN) which cause json_encode to fail.
	foreach ($payload as $key => $value) {
		if (is_float($value) && !is_finite($value)) {
			$payload[$key] = null;
		}
	}

	$jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
	if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
		$jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
	}

	$encoded = json_encode($payload, $jsonFlags);
	if ($encoded === false) {
		return;
	}

	@file_put_contents($cachePath, $encoded, LOCK_EX);
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

		$cache = readRecordingCache($fullPath, $modifiedAt);
		if ($cache !== null) {
			$durationSeconds = isset($cache['duration_seconds']) ? $cache['duration_seconds'] : null;
			$sourceMetadata = array(
				'comment' => isset($cache['metadata_comment']) ? $cache['metadata_comment'] : null,
				'title'   => isset($cache['metadata_title'])   ? $cache['metadata_title']   : null,
				'artist'  => isset($cache['metadata_artist'])  ? $cache['metadata_artist']  : null,
				'url'     => isset($cache['source_url'])        ? $cache['source_url']        : null,
			);
		} else {
			$durationSeconds = estimateRecordingDurationSeconds($fullPath, $sizeBytes, $extension);
			$sourceMetadata = detectSourceMetadataForRecording($fullPath, $sizeBytes, $extension);
			writeRecordingCache($fullPath, $modifiedAt, array(
				'content_type'     => getRecordingMimeType($extension),
				'filesize_bytes'   => $sizeBytes,
				'md5_checksum'     => md5_file($fullPath),
				'duration_seconds' => $durationSeconds,
				'timestamp'        => $parsedTimestamp,
				'timestamp_iso'    => date('Y-m-d H:i:s', $parsedTimestamp),
				'date'             => date('Y-m-d', $parsedTimestamp),
				'time_display'     => date('H:i:s', $parsedTimestamp),
				'metadata_comment' => $sourceMetadata['comment'],
				'metadata_title'   => $sourceMetadata['title'],
				'metadata_artist'  => $sourceMetadata['artist'],
				'source_url'       => $sourceMetadata['url'],
			));
		}

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
			'metadata_comment' => $sourceMetadata['comment'],
			'metadata_title' => $sourceMetadata['title'],
			'metadata_artist' => $sourceMetadata['artist'],
			'source_url' => $sourceMetadata['url'],
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

function sendAudioStreamAccessHeaders(): void
{
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
	header('Access-Control-Allow-Headers: Range, Origin, Accept, Access-Control-Request-Private-Network');
	header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges, Content-Type');

	if (
		isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_PRIVATE_NETWORK'])
		&& strtolower((string)$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_PRIVATE_NETWORK']) === 'true'
	) {
		header('Access-Control-Allow-Private-Network: true');
	}
}

function resolveRequestedRecordingFile(string $rootDirectory, array $allowedExtensions, string $requestedPath): array
{
	$realRoot = realpath($rootDirectory);
	if ($realRoot === false || !is_dir($realRoot)) {
		return array(
			'ok' => false,
			'status_code' => 500,
			'message' => 'Recordings directory is not available.',
		);
	}

	$normalizedPath = normalizeRelativePath($requestedPath);
	if ($normalizedPath === '') {
		return array(
			'ok' => false,
			'status_code' => 400,
			'message' => 'Invalid file path.',
		);
	}

	$fullPath = realpath($realRoot . DIRECTORY_SEPARATOR . $normalizedPath);
	if ($fullPath === false || !is_file($fullPath)) {
		return array(
			'ok' => false,
			'status_code' => 404,
			'message' => 'Recording not found.',
		);
	}

	if (strpos($fullPath, $realRoot . DIRECTORY_SEPARATOR) !== 0) {
		return array(
			'ok' => false,
			'status_code' => 403,
			'message' => 'Access denied.',
		);
	}

	$extension = strtolower((string)pathinfo($fullPath, PATHINFO_EXTENSION));
	if (!in_array($extension, $allowedExtensions, true)) {
		return array(
			'ok' => false,
			'status_code' => 400,
			'message' => 'Unsupported recording file type.',
		);
	}

	return array(
		'ok' => true,
		'root' => $realRoot,
		'normalized_path' => $normalizedPath,
		'full_path' => $fullPath,
		'extension' => $extension,
	);
}

function sanitizeHeaderValue(string $value): string
{
	return str_replace(array("\r", "\n"), '', trim($value));
}

function parseHttpWrapperResponseMetadata(array $wrapperData): array
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

		$separatorPosition = strpos($trimmedLine, ':');
		if ($separatorPosition === false) {
			continue;
		}

		$headerName = strtolower(trim(substr($trimmedLine, 0, $separatorPosition)));
		$headerValue = sanitizeHeaderValue(substr($trimmedLine, $separatorPosition + 1));
		if ($headerName === '' || $headerValue === '') {
			continue;
		}

		$headers[$headerName] = $headerValue;
	}

	return array(
		'status_code' => $statusCode,
		'headers' => $headers,
	);
}

function guessLiveStreamMimeType(string $sourceUrl, string $fallbackExtension): string
{
	$sourcePath = parse_url($sourceUrl, PHP_URL_PATH);
	$sourceExtension = is_string($sourcePath) ? strtolower((string)pathinfo($sourcePath, PATHINFO_EXTENSION)) : '';
	if (in_array($sourceExtension, array('mp3', 'ogg', 'wav'), true)) {
		return getRecordingMimeType($sourceExtension);
	}

	if (in_array($fallbackExtension, array('mp3', 'ogg', 'wav'), true)) {
		return getRecordingMimeType($fallbackExtension);
	}

	return 'audio/mpeg';
}

function streamRecording(string $rootDirectory, array $allowedExtensions, string $requestedPath, bool $forceDownload = false): void
{
	$resolvedRecording = resolveRequestedRecordingFile($rootDirectory, $allowedExtensions, $requestedPath);
	if ($resolvedRecording['ok'] !== true) {
		http_response_code((int)$resolvedRecording['status_code']);
		echo (string)$resolvedRecording['message'];
		exit;
	}

	$fullPath = (string)$resolvedRecording['full_path'];
	$extension = (string)$resolvedRecording['extension'];

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

	sendAudioStreamAccessHeaders();
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

function streamLiveSourceForRecording(string $rootDirectory, array $allowedExtensions, string $requestedPath): void
{
	$resolvedRecording = resolveRequestedRecordingFile($rootDirectory, $allowedExtensions, $requestedPath);
	if ($resolvedRecording['ok'] !== true) {
		http_response_code((int)$resolvedRecording['status_code']);
		echo (string)$resolvedRecording['message'];
		exit;
	}

	$fullPath = (string)$resolvedRecording['full_path'];
	$fallbackExtension = (string)$resolvedRecording['extension'];
	$fileSize = filesize($fullPath);
	$sourceMetadata = detectSourceMetadataForRecording($fullPath, $fileSize !== false ? (int)$fileSize : 0, $fallbackExtension);
	$liveSourceUrl = isset($sourceMetadata['url']) ? trim((string)$sourceMetadata['url']) : '';
	if ($liveSourceUrl === '' || filter_var($liveSourceUrl, FILTER_VALIDATE_URL) === false) {
		http_response_code(404);
		echo 'Live source URL is not available for this recording.';
		exit;
	}

	if (!filter_var($liveSourceUrl, FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED)) {
		$pathValue = parse_url($liveSourceUrl, PHP_URL_PATH);
		if (!is_string($pathValue) || $pathValue === '') {
			http_response_code(400);
			echo 'Live source URL path is invalid.';
			exit;
		}
	}

	$requestHeaders = array(
		'Accept: audio/*,*/*;q=0.9',
		'Connection: close',
	);
	if (isset($_SERVER['HTTP_USER_AGENT']) && trim((string)$_SERVER['HTTP_USER_AGENT']) !== '') {
		$requestHeaders[] = 'User-Agent: ' . sanitizeHeaderValue((string)$_SERVER['HTTP_USER_AGENT']);
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

	$remoteHandle = @fopen($liveSourceUrl, 'rb', false, $context);
	if ($remoteHandle === false) {
		http_response_code(502);
		echo 'Unable to connect to the live source stream.';
		exit;
	}

	$streamMetadata = stream_get_meta_data($remoteHandle);
	$responseMetadata = parseHttpWrapperResponseMetadata(
		isset($streamMetadata['wrapper_data']) && is_array($streamMetadata['wrapper_data'])
			? $streamMetadata['wrapper_data']
			: array()
	);
	$statusCode = isset($responseMetadata['status_code']) ? (int)$responseMetadata['status_code'] : 200;
	if ($statusCode >= 400) {
		fclose($remoteHandle);
		http_response_code(502);
		echo 'Live source returned HTTP ' . $statusCode . '.';
		exit;
	}

	$remoteHeaders = isset($responseMetadata['headers']) && is_array($responseMetadata['headers'])
		? $responseMetadata['headers']
		: array();
	$remoteContentType = isset($remoteHeaders['content-type']) ? sanitizeHeaderValue((string)$remoteHeaders['content-type']) : '';
	$contentType = $remoteContentType;
	if (
		$contentType === ''
		|| (
			stripos($contentType, 'audio/') !== 0
			&& stripos($contentType, 'application/ogg') !== 0
			&& stripos($contentType, 'application/octet-stream') !== 0
		)
	) {
		$contentType = guessLiveStreamMimeType($liveSourceUrl, $fallbackExtension);
	}

	$downloadName = basename((string)parse_url($liveSourceUrl, PHP_URL_PATH));
	if ($downloadName === '' || $downloadName === '/' || $downloadName === '.') {
		$downloadName = basename($fullPath);
	}

	sendAudioStreamAccessHeaders();
	header('Content-Type: ' . $contentType);
	header('Cache-Control: no-cache, no-store, must-revalidate');
	header('Content-Disposition: inline; filename="' . $downloadName . '"');
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
			$chunkMetadata = stream_get_meta_data($remoteHandle);
			if (isset($chunkMetadata['timed_out']) && $chunkMetadata['timed_out'] === true) {
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

$ajaxAction = isset($_GET['ajax']) ? (string)$_GET['ajax'] : '';
$apiAction = isset($_GET['api']) ? trim((string)$_GET['api']) : '';

if ($ajaxAction === 'ingest' || $apiAction === 'recording_ingest') {
	handleRecordingsIngestApi($recordingsRoot, $supportedRecordingExtensions, $authenticate === true);
}

enforceRecordingsAuthentication($authenticate === true, $PAGE_TITLE, $ajaxAction);
if (
	($ajaxAction === 'stream' || $ajaxAction === 'live_proxy')
	&& isset($_SERVER['REQUEST_METHOD'])
	&& strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'OPTIONS'
) {
	sendAudioStreamAccessHeaders();
	http_response_code(204);
	exit;
}

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

if ($ajaxAction === 'live_proxy') {
	$requestedFile = isset($_GET['file']) ? (string)$_GET['file'] : '';
	streamLiveSourceForRecording($recordingsRoot, $supportedRecordingExtensions, $requestedFile);
}

if ($ajaxAction === 'zip') {
	$nameFilter = isset($_GET['filter']) ? strtolower(trim((string)$_GET['filter'])) : '';
	$startTs = isset($_GET['start']) && $_GET['start'] !== '' ? (int)$_GET['start'] : 0;
	$endTs = isset($_GET['end']) && $_GET['end'] !== '' ? (int)$_GET['end'] : 0;
	zipRecordingsFiltered($recordingsRoot, $supportedRecordingExtensions, $nameFilter, $startTs, $endTs);
}

if ($ajaxAction === 'run_action') {
	$requestedFile = isset($_GET['file']) ? (string)$_GET['file'] : '';
	$actionIndex = isset($_GET['action']) && ctype_digit((string)$_GET['action']) ? (int)$_GET['action'] : -1;
	runRecordingAction($recordingsRoot, $supportedRecordingExtensions, $requestedFile, $actionIndex);
}

function recordingMatchesNameFilter(array $recording, string $nameFilter): bool
{
	if ($nameFilter === '') {
		return true;
	}

	$candidateValues = array(
		isset($recording['name_pretty']) ? strtolower((string)$recording['name_pretty']) : '',
		isset($recording['name']) ? strtolower((string)$recording['name']) : '',
		isset($recording['metadata_comment']) ? strtolower((string)$recording['metadata_comment']) : '',
		isset($recording['metadata_title']) ? strtolower((string)$recording['metadata_title']) : '',
	);

	foreach ($candidateValues as $candidateValue) {
		if ($candidateValue === '') {
			continue;
		}

		if (strpos($candidateValue, $nameFilter) !== false) {
			return true;
		}
	}

	return false;
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
			if (!recordingMatchesNameFilter($item, $nameFilter)) {
				continue;
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

		$jsonCachePath = recordingCachePath($resolvedPath);
		if (is_file($jsonCachePath)) {
			$zipJsonPath = 'audio/' . dirname($normalizedPath) . '/' . pathinfo($normalizedPath, PATHINFO_FILENAME) . '.json';
			$zip->addFile($jsonCachePath, $zipJsonPath);
		}
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

function runRecordingAction(string $rootDirectory, array $allowedExtensions, string $requestedPath, int $actionIndex): void
{
	global $RECORDING_ACTIONS;

	if (!isset($RECORDING_ACTIONS) || !is_array($RECORDING_ACTIONS) || $actionIndex < 0 || !isset($RECORDING_ACTIONS[$actionIndex])) {
		sendJsonResponse(array('ok' => false, 'error' => 'Invalid action index.'), 400);
	}

	$action = $RECORDING_ACTIONS[$actionIndex];
	if (!is_array($action) || !isset($action['command']) || !is_string($action['command']) || trim($action['command']) === '') {
		sendJsonResponse(array('ok' => false, 'error' => 'Action command is not configured.'), 400);
	}

	$resolvedRecording = resolveRequestedRecordingFile($rootDirectory, $allowedExtensions, $requestedPath);
	if ($resolvedRecording['ok'] !== true) {
		sendJsonResponse(array('ok' => false, 'error' => (string)$resolvedRecording['message']), (int)$resolvedRecording['status_code']);
	}

	$fullPath = (string)$resolvedRecording['full_path'];
	$command = str_replace('%s', escapeshellarg($fullPath), (string)$action['command']);
	$output = array();
	$returnCode = 0;
	exec($command . ' 2>&1', $output, $returnCode);

	sendJsonResponse(array(
		'ok' => true,
		'label' => isset($action['label']) ? (string)$action['label'] : '',
		'return_code' => $returnCode,
		'output' => implode("\n", $output),
	), 200);
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
		--recording-date-sticky-offset: 0px;
		--recording-table-sticky-offset: 34px;
		height: 420px;
		min-height: 220px;
		overflow-x: auto;
		overflow-y: auto;
		-webkit-overflow-scrolling: touch;
		padding: 0;
		background-color: #171717;
	}

	body.theme-light .recordings-list-wrap {
		background-color: #f9fbff;
	}

	.recording-date {
		margin: 0;
		padding: 4px 8px;
		position: sticky;
		top: var(--recording-date-sticky-offset);
		z-index: 5;
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

	.recording-date + .recording-table {
		margin-top: 0;
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
		top: var(--recording-table-sticky-offset);
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
		width: 50px;
		text-align: center;
		white-space: nowrap;
		font-family: monospace;
	}

	.recording-table th.recording-content-type,
	.recording-table td.recording-content-type {
		padding-left: 3px;
		padding-right: 3px;
	}

	.recording-download {
		width: 92px;
		text-align: center;
		white-space: nowrap;
	}

	.recording-actions {
		text-align: center;
		white-space: nowrap;
		padding-left: 6px;
		padding-right: 6px;
	}

	.recording-name {
		width: 38%;
		max-width: 38%;
		word-break: break-word;
	}

	#audioPlayer {
		display: none;
	}

	.custom-audio-player {
		margin-top: 10px;
		margin-bottom: 10px;
		padding: 10px;
		border: 1px solid #3a3a3a;
		border-radius: 8px;
		background: linear-gradient(135deg, #20262e 0%, #171717 100%);
		display: flex;
		align-items: center;
		gap: 14px;
	}

	body.theme-light .custom-audio-player {
		border-color: #c4d2e1;
		background: linear-gradient(135deg, #f4f8fd 0%, #e9f0f8 100%);
	}

	.custom-audio-player.is-empty {
		opacity: 0.75;
	}

	.audio-controls-cluster {
		flex: 0 0 auto;
		display: flex;
		align-items: center;
		gap: 8px;
	}

	.audio-icon-button {
		width: 42px;
		height: 42px;
		padding: 0;
		border: 1px solid #53779a;
		border-radius: 999px;
		background-color: #24374a;
		color: #eaf4ff;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		cursor: pointer;
		transition: background-color 0.15s ease, border-color 0.15s ease, transform 0.1s ease;
	}

	body.theme-light .audio-icon-button {
		border-color: #6f8db3;
		background-color: #3a6da4;
		color: #ffffff;
	}

	.audio-icon-button:hover:enabled {
		background-color: #2f4f70;
		border-color: #7ba4d2;
	}

	body.theme-light .audio-icon-button:hover:enabled {
		background-color: #427ab8;
		border-color: #5e88b5;
	}

	.audio-icon-button:active:enabled {
		transform: translateY(1px);
	}

	.audio-icon-button:disabled {
		opacity: 0.55;
		cursor: not-allowed;
	}

	.audio-play-pause-button {
		width: 52px;
		height: 52px;
		border-color: #4f76a1;
		background-color: #1f3f61;
	}

	.audio-play-pause-button:hover:enabled {
		background-color: #27517c;
		border-color: #6f9dce;
	}

	body.theme-light .audio-play-pause-button {
		border-color: #6c8ab0;
		background-color: #2f5f92;
	}

	body.theme-light .audio-play-pause-button:hover:enabled {
		background-color: #3b72ad;
	}

	.audio-play-pause-button.is-playing {
		border-color: #4d8552;
		background-color: #285a31;
		color: #e9ffe9;
	}

	body.theme-light .audio-play-pause-button.is-playing {
		border-color: #4f8c5b;
		background-color: #2f6d3c;
		color: #ffffff;
	}

	.audio-skip-button {
		position: relative;
	}

	.audio-skip-button .audio-svg-icon {
		width: 18px;
		height: 18px;
	}

	.audio-skip-value {
		position: absolute;
		right: 4px;
		bottom: 2px;
		font-size: 9px;
		font-weight: bold;
		line-height: 1;
		opacity: 0.9;
	}

	.audio-svg-icon {
		width: 22px;
		height: 22px;
		display: block;
		fill: currentColor;
	}

	.audio-play-pause-button .audio-icon-pause {
		display: none;
	}

	.audio-play-pause-button.is-playing .audio-icon-play {
		display: none;
	}

	.audio-play-pause-button.is-playing .audio-icon-pause {
		display: block;
	}

	.audio-progress-wrap {
		flex: 1 1 auto;
		min-width: 0;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	.audio-seekbar {
		--seek-progress: 0%;
		-webkit-appearance: none;
		appearance: none;
		width: 100%;
		height: 16px;
		background: transparent;
		cursor: pointer;
	}

	.audio-seekbar:disabled {
		cursor: not-allowed;
		opacity: 0.65;
	}

	.audio-seekbar::-webkit-slider-runnable-track {
		height: 6px;
		border-radius: 999px;
		background: linear-gradient(to right, #79adff 0%, #79adff var(--seek-progress), #4b4b4b var(--seek-progress), #4b4b4b 100%);
	}

	body.theme-light .audio-seekbar::-webkit-slider-runnable-track {
		background: linear-gradient(to right, #2f75d6 0%, #2f75d6 var(--seek-progress), #c7d4e2 var(--seek-progress), #c7d4e2 100%);
	}

	.audio-seekbar::-webkit-slider-thumb {
		-webkit-appearance: none;
		appearance: none;
		width: 14px;
		height: 14px;
		margin-top: -4px;
		border: 1px solid #c8ddff;
		border-radius: 50%;
		background-color: #f3f8ff;
	}

	body.theme-light .audio-seekbar::-webkit-slider-thumb {
		border-color: #215fb6;
		background-color: #ffffff;
	}

	.audio-seekbar::-moz-range-track {
		height: 6px;
		border: none;
		border-radius: 999px;
		background-color: #4b4b4b;
	}

	body.theme-light .audio-seekbar::-moz-range-track {
		background-color: #c7d4e2;
	}

	.audio-seekbar::-moz-range-progress {
		height: 6px;
		border-radius: 999px;
		background-color: #79adff;
	}

	body.theme-light .audio-seekbar::-moz-range-progress {
		background-color: #2f75d6;
	}

	.audio-seekbar::-moz-range-thumb {
		width: 14px;
		height: 14px;
		border: 1px solid #c8ddff;
		border-radius: 50%;
		background-color: #f3f8ff;
	}

	body.theme-light .audio-seekbar::-moz-range-thumb {
		border-color: #215fb6;
		background-color: #ffffff;
	}

	.audio-time-row {
		display: flex;
		justify-content: space-between;
		align-items: center;
		font-size: 12px;
		color: #c8d4e0;
		font-family: monospace;
	}

	body.theme-light .audio-time-row {
		color: #34495e;
	}

	.selected-recording-summary {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	.recordings-controls {
		display: flex;
		flex-direction: column;
		align-items: stretch;
		gap: 10px;
		padding: 8px;
		border-bottom: 1px solid #343434;
		background-color: #202020;
	}

	body.theme-light .recordings-controls {
		border-bottom-color: #d5dce5;
		background-color: #f3f7fc;
	}

	.recordings-controls-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 10px;
		width: 100%;
		min-height: 34px;
	}

	.recordings-controls-header-actions {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-left: auto;
		flex-shrink: 0;
	}

	.collapsed-filter-badge {
		display: none;
		align-items: center;
		gap: 6px;
		font-size: 12px;
		color: #b0c4de;
		background: #2a2a2a;
		border: 1px solid #444;
		border-radius: 4px;
		padding: 3px 8px;
		max-width: 50%;
		overflow: hidden;
		white-space: nowrap;
		text-overflow: ellipsis;
		min-width: 0;
		flex-shrink: 1;
	}

	body.theme-light .collapsed-filter-badge {
		background: #e8eef5;
		border-color: #b9c4d1;
		color: #3a5268;
	}

	.collapsed-filter-badge.is-visible {
		display: flex;
	}

	.collapsed-filter-badge-label {
		font-weight: bold;
		flex-shrink: 0;
	}

	.collapsed-filter-badge-value {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		min-width: 0;
	}

	.recordings-controls-title {
		font-size: 12px;
		font-weight: bold;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		color: #aebccd;
	}

	body.theme-light .recordings-controls-title {
		color: #3a5268;
	}

	.filter-toggle-button {
		min-width: 120px;
	}

	.header-icon-button {
		width: 34px;
		min-width: 34px;
		height: 34px;
		min-height: 34px;
		padding: 0;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		line-height: 1;
	}

	.header-action-icon {
		width: 16px;
		height: 16px;
		fill: none;
		stroke: currentColor;
		stroke-width: 2;
		stroke-linecap: round;
		stroke-linejoin: round;
	}

	.theme-icon-button .theme-icon-sun,
	.theme-icon-button .theme-icon-moon {
		display: none;
	}

	body.theme-light .theme-icon-button .theme-icon-sun {
		display: block;
	}

	body.theme-dark .theme-icon-button .theme-icon-moon {
		display: block;
	}

	.recordings-controls-body {
		display: flex;
		flex-direction: column;
		gap: 10px;
		width: 100%;
	}

	.recordings-controls.is-collapsed .recordings-controls-body {
		display: none;
	}

	.recordings-filter-label {
		font-size: 13px;
		white-space: nowrap;
	}

	.recordings-filter-input-wrap {
		flex: 1 1 280px;
		position: relative;
		display: flex;
		align-items: center;
	}

	.recordings-filter-input {
		width: 100%;
		min-height: 34px;
		padding: 6px 30px 6px 8px;
		border: 1px solid #4a4a4a;
		border-radius: 4px;
		background-color: #141414;
		color: #efefef;
		box-sizing: border-box;
	}

	body.theme-light .recordings-filter-input {
		border-color: #b9c4d1;
		background-color: #ffffff;
		color: #1f2a34;
	}

	.filter-clear-button {
		position: absolute;
		right: 4px;
		top: 50%;
		transform: translateY(-50%);
		background: none;
		border: none;
		color: #999;
		cursor: pointer;
		font-size: 16px;
		line-height: 1;
		padding: 2px 4px;
		display: none;
	}

	.filter-clear-button.is-visible {
		display: block;
	}

	.filter-clear-button:hover {
		color: #fff;
	}

	body.theme-light .filter-clear-button:hover {
		color: #333;
	}

	.recording-filter-icon {
		display: inline-block;
		vertical-align: middle;
		width: 14px;
		height: 14px;
		margin-left: 5px;
		cursor: pointer;
		opacity: 0;
		transition: opacity 0.15s;
		flex-shrink: 0;
		color: #888;
	}

	.recording-row:hover .recording-filter-icon {
		opacity: 0.7;
	}

	.recording-filter-icon:hover {
		opacity: 1 !important;
		color: #4fc3f7;
	}

	body.theme-light .recording-filter-icon:hover {
		color: #0d6efd;
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
		display: block;
		margin-top: 2px;
		font-size: 12px;
		line-height: 1.3;
		color: #d4d4d4;
	}

	body.theme-light .status-text {
		color: #2d3742;
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

	.recordings-pagination {
		display: flex;
		justify-content: center;
		padding: 12px 8px 4px;
	}

	.previous-week-button {
		min-height: 36px;
		padding: 8px 14px;
		border: 1px solid #4a4a4a;
		border-radius: 999px;
		background-color: #242424;
		color: #f2f2f2;
		cursor: pointer;
		font-weight: bold;
	}

	body.theme-light .previous-week-button {
		border-color: #b9c4d1;
		background-color: #ffffff;
		color: #1f2a34;
	}

	.previous-week-button:hover:enabled {
		background-color: #353535;
	}

	body.theme-light .previous-week-button:hover:enabled {
		background-color: #edf3fb;
	}

	.previous-week-button:disabled {
		opacity: 0.55;
		cursor: not-allowed;
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

	.selected-recording-actions {
		display: none;
		margin-top: 6px;
		gap: 8px;
		align-items: center;
		width: 100%;
	}

	.selected-recording-action-button {
		flex: 1 1 0;
		min-width: 0;
		max-width: 100%;
		text-align: center;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	.selected-recording-action-button.is-live {
		font-weight: bold;
		letter-spacing: 0.02em;
		border-color: #7a1f1f;
		background-color: #5b1717;
		color: #ffeaea;
	}

	.selected-recording-action-button.is-live:hover {
		background-color: #742020;
	}

	body.theme-light .selected-recording-action-button.is-live {
		border-color: #b00020;
		background-color: #b00020;
		color: #ffffff;
	}

	body.theme-light .selected-recording-action-button.is-live:hover {
		background-color: #8f0019;
	}

	#selectedTitle,
	#selectedMeta {
		word-break: break-word;
	}

	#selectedMeta:empty {
		display: none;
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
			padding: 0px;
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

		.custom-audio-player {
			flex-direction: column;
			align-items: stretch;
			gap: 8px;
		}

		.audio-controls-cluster {
			justify-content: center;
			width: 100%;
		}

		.audio-progress-wrap {
			width: 100%;
		}

		.recordings-controls {
			align-items: stretch;
		}

		.recordings-controls-header {
			flex-wrap: wrap;
		}

		.recordings-controls-header-actions {
			width: 100%;
		}

		.header-icon-button {
			flex: 0 0 34px;
		}

		.filter-toggle-button {
			width: auto;
			flex: 1 1 auto;
			margin-left: 0;
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
	}

	.confirm-modal-overlay {
		display: none;
		position: fixed;
		top: 0;
		left: 0;
		width: 100%;
		height: 100%;
		background: rgba(0, 0, 0, 0.55);
		z-index: 10000;
		align-items: center;
		justify-content: center;
	}

	.confirm-modal-overlay.is-visible {
		display: flex;
	}

	.confirm-modal-box {
		background: #1e1e1e;
		border: 1px solid #444;
		border-radius: 8px;
		padding: 24px 28px 20px;
		max-width: 420px;
		width: 90%;
		box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
		color: #d4d4d4;
		font-size: 14px;
		line-height: 1.5;
	}

	body.theme-light .confirm-modal-box {
		background: #fff;
		border-color: #ccc;
		box-shadow: 0 8px 32px rgba(0, 0, 0, 0.18);
		color: #2d3742;
	}

	.confirm-modal-message {
		margin: 0 0 18px;
		word-wrap: break-word;
	}

	.confirm-modal-buttons {
		display: flex;
		justify-content: flex-end;
		gap: 10px;
	}

	.confirm-modal-button {
		padding: 7px 18px;
		border-radius: 4px;
		border: 1px solid #555;
		background: #2a2a2a;
		color: #d4d4d4;
		font-size: 13px;
		cursor: pointer;
		line-height: 1.4;
	}

	body.theme-light .confirm-modal-button {
		background: #f0f0f0;
		border-color: #bbb;
		color: #2d3742;
	}

	.confirm-modal-button:hover {
		background: #3a3a3a;
	}

	body.theme-light .confirm-modal-button:hover {
		background: #ddd;
	}

	.confirm-modal-button.is-primary {
		background: #0d6efd;
		border-color: #0d6efd;
		color: #fff;
	}

	.confirm-modal-button.is-primary:hover {
		background: #0b5ed7;
		border-color: #0b5ed7;
	}
</style>

<h2><?=$PAGE_TITLE?></h2>

<div class="recordings-layout">
	<div class="recordings-player-col">
		<div class="recordings-panel" style="padding: 10px;">
			<div class="selected-recording-summary">
				<b id="selectedTitle">No recording selected</b>
				<small id="selectedMeta">Select a recording to begin playback.</small>
				<small id="statusText" class="status-text">Loading recordings...</small>
			</div>
			<div class="custom-audio-player is-empty" id="customAudioPlayer">
				<div class="audio-controls-cluster">
					<button type="button" id="audioSkipBackButton" class="audio-icon-button audio-skip-button" aria-label="Skip back 10 seconds" title="Back 10s" disabled>
						<svg class="audio-svg-icon" viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path d="M13 8L3 16l10 8V8zm16 0L19 16l10 8V8z"></path></svg>
						<span class="audio-skip-value" aria-hidden="true">10</span>
					</button>
					<button type="button" id="audioPlayPauseButton" class="audio-icon-button audio-play-pause-button" aria-label="Play" title="Play" disabled>
						<svg class="audio-svg-icon audio-icon-play" viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path d="M11 7l14 9-14 9V7z"></path></svg>
						<svg class="audio-svg-icon audio-icon-pause" viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path d="M10 7h5v18h-5zM18 7h5v18h-5z"></path></svg>
					</button>
					<button type="button" id="audioSkipForwardButton" class="audio-icon-button audio-skip-button" aria-label="Skip forward 10 seconds" title="Forward 10s" disabled>
						<svg class="audio-svg-icon" viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path d="M3 8l10 8-10 8V8zm16 0l10 8-10 8V8z"></path></svg>
						<span class="audio-skip-value" aria-hidden="true">10</span>
					</button>
				</div>
				<div class="audio-progress-wrap">
					<input type="range" id="audioSeekBar" class="audio-seekbar" min="0" max="1000" value="0" step="1" aria-label="Seek playback position" disabled />
					<div class="audio-time-row">
						<span id="audioCurrentTime">0:00</span>
						<span id="audioDuration">?:??</span>
					</div>
				</div>
			</div>
			<audio id="audioPlayer" preload="metadata"></audio>
			<div class="selected-recording-actions" id="selectedRecordingActions">
				<button type="button" id="downloadSelectedButton" class="refresh-button selected-recording-action-button" style="display: none;">Download selected recording</button>
				<button type="button" id="listenLiveButton" class="refresh-button selected-recording-action-button" aria-pressed="false" style="display: none;">Listen live</button>
			</div>
		</div>
	</div>

	<div class="recordings-list-col">
		<div class="recordings-panel">
			<div class="recordings-controls" id="recordingsControls">
				<div class="recordings-controls-header">
					<span class="recordings-controls-title">Filters and Controls</span>
					<div class="collapsed-filter-badge" id="collapsedFilterBadge">
						<span class="collapsed-filter-badge-label">Filter:</span>
						<span class="collapsed-filter-badge-value" id="collapsedFilterBadgeValue"></span>
					</div>
					<div class="recordings-controls-header-actions">
						<button type="button" id="headerClearFilterButton" class="refresh-button header-icon-button" onclick="onFilterClearButtonClicked()" aria-label="Clear filter" title="Clear filter" style="display: none;">
							<svg class="header-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
								<line x1="18" y1="6" x2="6" y2="18"></line>
								<line x1="6" y1="6" x2="18" y2="18"></line>
							</svg>
						</button>
						<button type="button" class="refresh-button header-icon-button" onclick="refreshRecordings(true)" aria-label="Refresh now" title="Refresh now">
							<svg class="header-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
								<polyline points="23 4 23 10 17 10"></polyline>
								<path d="M20.49 15a9 9 0 1 1 2.13-9"></path>
							</svg>
						</button>
						<button type="button" class="refresh-button header-icon-button" onclick="document.documentElement.requestFullscreen();" aria-label="Fullscreen" title="Fullscreen">
							<svg class="header-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
								<polyline points="8 3 3 3 3 8"></polyline>
								<polyline points="16 3 21 3 21 8"></polyline>
								<polyline points="3 16 3 21 8 21"></polyline>
								<polyline points="21 16 21 21 16 21"></polyline>
							</svg>
						</button>
						<button type="button" id="themeToggleButton" class="refresh-button header-icon-button theme-icon-button" onclick="toggleTheme()" aria-label="Switch to light mode" title="Switch to light mode">
							<svg class="header-action-icon theme-icon-sun" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
								<circle cx="12" cy="12" r="4"></circle>
								<line x1="12" y1="2" x2="12" y2="5"></line>
								<line x1="12" y1="19" x2="12" y2="22"></line>
								<line x1="2" y1="12" x2="5" y2="12"></line>
								<line x1="19" y1="12" x2="22" y2="12"></line>
								<line x1="4.93" y1="4.93" x2="7.05" y2="7.05"></line>
								<line x1="16.95" y1="16.95" x2="19.07" y2="19.07"></line>
								<line x1="16.95" y1="7.05" x2="19.07" y2="4.93"></line>
								<line x1="4.93" y1="19.07" x2="7.05" y2="16.95"></line>
							</svg>
							<svg class="header-action-icon theme-icon-moon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
								<path d="M21 12.79A9 9 0 1 1 11.21 3c.13 0 .25 0 .38.01A7 7 0 0 0 21 12.79z"></path>
							</svg>
						</button>
						<button type="button" id="toggleFiltersButton" class="refresh-button filter-toggle-button" onclick="toggleFiltersVisibility()" aria-expanded="true">Hide Filters</button>
					</div>
				</div>
				<div class="recordings-controls-body" id="recordingsControlsBody">
					<div class="recordings-controls-row">
						<label for="recordingsFilterInput" class="recordings-filter-label">Filter:</label>
						<div class="recordings-filter-input-wrap">
							<input type="text" id="recordingsFilterInput" class="recordings-filter-input" placeholder="Type to filter name, comment, or title..." autocomplete="off" />
							<button type="button" id="filterClearButton" class="filter-clear-button" aria-label="Clear filter" title="Clear filter">&times;</button>
						</div>
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
var playerSkipAmountSeconds = 10;
var recordingsAuthHash = <?=json_encode(getRecordingsAuthenticatedHash(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
var recordingActions = <?=json_encode(isset($RECORDING_ACTIONS) && is_array($RECORDING_ACTIONS) ? $RECORDING_ACTIONS : array(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;

function appendRecordingsAuthHash(url)
{
	if (!recordingsAuthHash) {
		return url;
	}

	if (/[?&]hash=/.test(url)) {
		return url;
	}

	return url + (url.indexOf('?') === -1 ? '?' : '&') + 'hash=' + encodeURIComponent(recordingsAuthHash);
}

function applyTheme(themeName)
{
	var theme = (themeName === 'theme-light') ? 'theme-light' : 'theme-dark';
	document.body.classList.remove('theme-dark');
	document.body.classList.remove('theme-light');
	document.body.classList.add(theme);

	var themeToggleButton = document.getElementById('themeToggleButton');
	if (themeToggleButton) {
		var nextThemeLabel = (theme === 'theme-light') ? 'Switch to dark mode' : 'Switch to light mode';
		themeToggleButton.setAttribute('aria-label', nextThemeLabel);
		themeToggleButton.setAttribute('title', nextThemeLabel);
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

function applyFiltersVisibility(collapsed)
{
	var controlsWrap = document.getElementById('recordingsControls');
	var controlsBody = document.getElementById('recordingsControlsBody');
	var toggleButton = document.getElementById('toggleFiltersButton');
	if (!controlsWrap || !controlsBody || !toggleButton) {
		return;
	}

	var shouldCollapse = (collapsed === true);
	controlsWrap.classList.toggle('is-collapsed', shouldCollapse);
	controlsBody.setAttribute('aria-hidden', shouldCollapse ? 'true' : 'false');
	toggleButton.textContent = shouldCollapse ? 'Show Filters' : 'Hide Filters';
	toggleButton.setAttribute('aria-expanded', shouldCollapse ? 'false' : 'true');
	updateCollapsedFilterBadge();
	adjustRecordingsListHeight();
}

function toggleFiltersVisibility()
{
	var controlsWrap = document.getElementById('recordingsControls');
	if (!controlsWrap) {
		return;
	}

	var collapseNext = !controlsWrap.classList.contains('is-collapsed');
	applyFiltersVisibility(collapseNext);
	try {
		window.localStorage.setItem('recordingsFiltersCollapsed', collapseNext ? '1' : '0');
	} catch (error) {
	}
}

function initFiltersVisibility()
{
	var collapsed = true;
	try {
		var storedPreference = window.localStorage.getItem('recordingsFiltersCollapsed');
		if (storedPreference === '0' || storedPreference === '1') {
			collapsed = (storedPreference === '1');
		}
	} catch (error) {
	}

	applyFiltersVisibility(collapsed);
}

function adjustRecordingsListHeight()
{
	var recordingsListWrap = document.getElementById('recordingsList');
	if (!recordingsListWrap) {
		return;
	}

	var rect = recordingsListWrap.getBoundingClientRect();
	var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
	var bottomPadding = 18;
	var availableHeight = viewportHeight - rect.top - bottomPadding;
	var minHeight = 220;

	if (availableHeight < minHeight) {
		availableHeight = minHeight;
	}

	recordingsListWrap.style.height = Math.floor(availableHeight) + 'px';
	updateRecordingsStickyOffsets();
}

function updateRecordingsStickyOffsets()
{
	var recordingsListWrap = document.getElementById('recordingsList');
	if (!recordingsListWrap) {
		return;
	}

	var stickyTableOffset = 0;
	var firstDateHeader = recordingsListWrap.querySelector('.recording-date');
	if (firstDateHeader) {
		stickyTableOffset = firstDateHeader.offsetHeight;
		var dateHeaderStyles = window.getComputedStyle(firstDateHeader);
		stickyTableOffset += parseFloat(dateHeaderStyles.marginBottom) || 0;
	}

	recordingsListWrap.style.setProperty('--recording-date-sticky-offset', '0px');
	recordingsListWrap.style.setProperty('--recording-table-sticky-offset', Math.max(0, Math.ceil(stickyTableOffset)) + 'px');
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

function formatPlaybackClock(totalSeconds)
{
	var numericSeconds = Number(totalSeconds);
	if (!isFinite(numericSeconds) || numericSeconds < 0) {
		return '0:00';
	}

	var roundedSeconds = Math.floor(numericSeconds);
	var hours = Math.floor(roundedSeconds / 3600);
	var minutes = Math.floor((roundedSeconds % 3600) / 60);
	var seconds = roundedSeconds % 60;

	if (hours > 0) {
		return hours + ':' + String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
	}

	return minutes + ':' + String(seconds).padStart(2, '0');
}

function updateCustomPlayerUi()
{
	var audioPlayer = document.getElementById('audioPlayer');
	var customPlayer = document.getElementById('customAudioPlayer');
	var playPauseButton = document.getElementById('audioPlayPauseButton');
	var skipBackButton = document.getElementById('audioSkipBackButton');
	var skipForwardButton = document.getElementById('audioSkipForwardButton');
	var seekBar = document.getElementById('audioSeekBar');
	var currentTimeLabel = document.getElementById('audioCurrentTime');
	var durationLabel = document.getElementById('audioDuration');
	var listenLiveButton = document.getElementById('listenLiveButton');

	if (!audioPlayer || !customPlayer || !playPauseButton || !skipBackButton || !skipForwardButton || !seekBar || !currentTimeLabel || !durationLabel) {
		return;
	}

	var hasSource = !!audioPlayer.getAttribute('src');
	var duration = Number(audioPlayer.duration);
	var currentTime = Number(audioPlayer.currentTime);
	if (!isFinite(currentTime) || currentTime < 0) {
		currentTime = 0;
	}

	var hasDuration = isFinite(duration) && duration > 0;

	playPauseButton.disabled = !hasSource;
	var playPauseLabel = audioPlayer.paused ? 'Play' : 'Pause';
	playPauseButton.setAttribute('aria-label', playPauseLabel);
	playPauseButton.setAttribute('title', playPauseLabel);
	playPauseButton.classList.toggle('is-playing', hasSource && !audioPlayer.paused);

	skipBackButton.disabled = !hasDuration;
	skipForwardButton.disabled = !hasDuration;
	skipBackButton.setAttribute('title', 'Back ' + playerSkipAmountSeconds + 's');
	skipForwardButton.setAttribute('title', 'Forward ' + playerSkipAmountSeconds + 's');

	seekBar.disabled = !hasDuration;
	if (hasDuration) {
		var progress = Math.min(1, Math.max(0, currentTime / duration));
		seekBar.value = String(Math.round(progress * 1000));
		seekBar.style.setProperty('--seek-progress', (progress * 100).toFixed(3) + '%');
		durationLabel.textContent = formatPlaybackClock(duration);
	} else {
		seekBar.value = '0';
		seekBar.style.setProperty('--seek-progress', '0%');
		durationLabel.textContent = hasSource ? '?:??' : '0:00';
	}

	currentTimeLabel.textContent = formatPlaybackClock(currentTime);

	if (listenLiveButton) {
		var selectedRecording = (selectedPath && recordingsByPath[selectedPath]) ? recordingsByPath[selectedPath] : null;
		var isLiveMode = hasSource && audioPlayer.getAttribute('data-mode') === 'live';
		var liveButtonLabel = selectedRecording ? buildListenLiveButtonLabel(selectedRecording) : 'Listen Live';
		listenLiveButton.classList.toggle('is-live', isLiveMode);
		listenLiveButton.setAttribute('aria-pressed', isLiveMode ? 'true' : 'false');
		listenLiveButton.textContent = liveButtonLabel;
		listenLiveButton.setAttribute('title', liveButtonLabel + (isLiveMode ? ' (Currently playing live source)' : ' (Play live source in built-in player)'));
	}

	if (selectedPath && recordingsByPath[selectedPath]) {
		renderSelectedPlaybackSummary(recordingsByPath[selectedPath], audioPlayer);
	}

	customPlayer.classList.toggle('is-empty', !hasSource);
	customPlayer.classList.toggle('is-playing', hasSource && !audioPlayer.paused);
}

function onCustomPlayPauseClicked()
{
	var audioPlayer = document.getElementById('audioPlayer');
	if (!audioPlayer || !audioPlayer.getAttribute('src')) {
		return;
	}

	if (audioPlayer.paused) {
		var playPromise = audioPlayer.play();
		if (playPromise && typeof playPromise.catch === 'function') {
			playPromise.catch(function () {
			});
		}
		return;
	}

	audioPlayer.pause();
}

function skipPlaybackBySeconds(offsetSeconds)
{
	var audioPlayer = document.getElementById('audioPlayer');
	if (!audioPlayer) {
		return;
	}

	var duration = Number(audioPlayer.duration);
	if (!isFinite(duration) || duration <= 0) {
		return;
	}

	var currentTime = Number(audioPlayer.currentTime);
	if (!isFinite(currentTime) || currentTime < 0) {
		currentTime = 0;
	}

	var targetTime = currentTime + Number(offsetSeconds);
	if (!isFinite(targetTime)) {
		targetTime = currentTime;
	}

	targetTime = Math.min(duration, Math.max(0, targetTime));
	audioPlayer.currentTime = targetTime;
	updateCustomPlayerUi();
}

function onSkipBackClicked()
{
	skipPlaybackBySeconds(-playerSkipAmountSeconds);
}

function onSkipForwardClicked()
{
	skipPlaybackBySeconds(playerSkipAmountSeconds);
}

function onCustomSeekChanged()
{
	var audioPlayer = document.getElementById('audioPlayer');
	var seekBar = document.getElementById('audioSeekBar');
	if (!audioPlayer || !seekBar) {
		return;
	}

	var duration = Number(audioPlayer.duration);
	if (!isFinite(duration) || duration <= 0) {
		return;
	}

	var progress = Number(seekBar.value) / 1000;
	if (!isFinite(progress)) {
		progress = 0;
	}

	progress = Math.min(1, Math.max(0, progress));
	seekBar.style.setProperty('--seek-progress', (progress * 100).toFixed(3) + '%');
	audioPlayer.currentTime = duration * progress;
	updateCustomPlayerUi();
}

function initializeCustomPlayerControls()
{
	var playPauseButton = document.getElementById('audioPlayPauseButton');
	if (playPauseButton) {
		playPauseButton.addEventListener('click', onCustomPlayPauseClicked);
	}

	var skipBackButton = document.getElementById('audioSkipBackButton');
	if (skipBackButton) {
		skipBackButton.addEventListener('click', onSkipBackClicked);
	}

	var skipForwardButton = document.getElementById('audioSkipForwardButton');
	if (skipForwardButton) {
		skipForwardButton.addEventListener('click', onSkipForwardClicked);
	}

	var seekBar = document.getElementById('audioSeekBar');
	if (seekBar) {
		seekBar.addEventListener('input', onCustomSeekChanged);
		seekBar.addEventListener('change', onCustomSeekChanged);
	}

	updateCustomPlayerUi();
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

function normalizeLiveSourceUrl(urlValue)
{
	var normalizedUrl = String(urlValue || '').trim();
	if (normalizedUrl === '') {
		return '';
	}

	normalizedUrl = normalizedUrl.replace(/[.,;)\]}]+$/, '');
	if (!/^https?:\/\//i.test(normalizedUrl)) {
		return '';
	}

	return normalizedUrl;
}

function getLiveSourceUrlFromRecording(recording)
{
	if (!recording) {
		return '';
	}

	if (typeof recording.metadata_comment === 'string' && recording.metadata_comment !== '') {
		var commentMatch = recording.metadata_comment.match(/Source\s*URL:\s*(https?:\/\/[^\s"'<>]+)/i);
		if (commentMatch && commentMatch[1]) {
			var parsedFromComment = normalizeLiveSourceUrl(commentMatch[1]);
			if (parsedFromComment !== '') {
				return parsedFromComment;
			}
		}
	}

	if (typeof recording.source_url === 'string') {
		return normalizeLiveSourceUrl(recording.source_url);
	}

	return '';
}

function buildLiveProxyUrl(recording)
{
	if (!recording || typeof recording.path !== 'string') {
		return '';
	}

	var normalizedPath = String(recording.path || '').trim();
	if (normalizedPath === '') {
		return '';
	}

	var proxyUrl = '?ajax=live_proxy&file=' + encodeURIComponent(normalizedPath);
	if (typeof recording.mtime !== 'undefined' && recording.mtime !== null && String(recording.mtime).trim() !== '') {
		proxyUrl += '&v=' + encodeURIComponent(String(recording.mtime));
	}

	return appendRecordingsAuthHash(proxyUrl);
}

function getRecordingFileName(recording)
{
	if (!recording) {
		return 'recording';
	}

	var fileName = String(recording.name || '').trim();
	if (fileName !== '') {
		return fileName;
	}

	var pathValue = String(recording.path || '').trim();
	if (pathValue !== '') {
		var pathParts = pathValue.split('/');
		if (pathParts.length > 0) {
			return String(pathParts[pathParts.length - 1] || 'recording');
		}
	}

	return 'recording';
}

function getRecordingStreamTitle(recording)
{
	if (!recording) {
		return 'Stream';
	}

	var streamTitle = String(recording.name_pretty || '').trim();
	if (streamTitle !== '') {
		return streamTitle;
	}

	return getRecordingFileName(recording);
}

function buildDownloadButtonLabel(recording)
{
	return 'Download (' + getRecordingFileName(recording) + ')';
}

function buildListenLiveButtonLabel(recording)
{
	return 'Listen Live - ' + getRecordingStreamTitle(recording);
}

function renderSelectedPlaybackSummary(recording, audioPlayer)
{
	var titleElement = document.getElementById('selectedTitle');
	var metaElement = document.getElementById('selectedMeta');
	if (!titleElement || !metaElement || !recording) {
		return;
	}

	var isLiveMode = false;
	if (audioPlayer) {
		isLiveMode = (audioPlayer.getAttribute('data-mode') === 'live' && audioPlayer.getAttribute('data-path') === recording.path);
	}

	if (isLiveMode) {
		var liveSourceUrl = getLiveSourceUrlFromRecording(recording);
		titleElement.textContent = 'LIVE - ' + recording.name_pretty;
		metaElement.textContent = 'Source URL: ' + (liveSourceUrl !== '' ? liveSourceUrl : 'Unavailable') + ' • ' + recording.path;
		if (recording.content_type) {
			metaElement.textContent += ' • ' + recording.content_type;
		}
		return;
	}

	titleElement.textContent = recording.time_display + ' - ' + recording.name_pretty;
	metaElement.textContent = '';
}

function playAudioElement(audioPlayer)
{
	if (!audioPlayer) {
		return;
	}

	var playPromise = audioPlayer.play();
	if (playPromise && typeof playPromise.catch === 'function') {
		playPromise.catch(function () {
		});
	}
}

function setFilterToName(name)
{
	var filterInput = document.getElementById('recordingsFilterInput');
	if (!filterInput) {
		return;
	}

	filterInput.value = name;
	updateFilterClearButton();
	onFilterInputChanged();
	filterInput.focus();
}

function updateFilterClearButton()
{
	var filterInput = document.getElementById('recordingsFilterInput');
	var clearButton = document.getElementById('filterClearButton');
	var headerClearButton = document.getElementById('headerClearFilterButton');
	var hasValue = !!(filterInput && filterInput.value.length > 0);

	if (clearButton) {
		clearButton.classList.toggle('is-visible', hasValue);
	}

	if (headerClearButton) {
		headerClearButton.style.display = hasValue ? 'inline-flex' : 'none';
	}

	updateCollapsedFilterBadge();
}

function updateCollapsedFilterBadge()
{
	var controlsWrap = document.getElementById('recordingsControls');
	var badge = document.getElementById('collapsedFilterBadge');
	var badgeValue = document.getElementById('collapsedFilterBadgeValue');
	var filterInput = document.getElementById('recordingsFilterInput');
	if (!badge || !badgeValue || !controlsWrap || !filterInput) {
		return;
	}

	var isCollapsed = controlsWrap.classList.contains('is-collapsed');
	var filterValue = filterInput.value.trim();
	var shouldShow = isCollapsed && filterValue.length > 0;

	badge.classList.toggle('is-visible', shouldShow);
	badgeValue.textContent = filterValue;
}

function onFilterClearButtonClicked()
{
	var filterInput = document.getElementById('recordingsFilterInput');
	if (!filterInput) {
		return;
	}

	filterInput.value = '';
	updateFilterClearButton();
	onFilterInputChanged();
	filterInput.focus();
}

var confirmModalResolve = null;

function showConfirmModal(message)
{
	return new Promise(function (resolve) {
		var overlay = document.getElementById('confirmModalOverlay');
		var messageEl = document.getElementById('confirmModalMessage');
		var okButton = document.getElementById('confirmModalOkButton');
		var cancelButton = document.getElementById('confirmModalCancelButton');
		if (!overlay || !messageEl || !okButton || !cancelButton) {
			resolve(false);
			return;
		}

		if (confirmModalResolve) {
			confirmModalResolve(false);
			confirmModalResolve = null;
		}

		messageEl.textContent = message;
		overlay.classList.add('is-visible');
		okButton.focus();
		confirmModalResolve = resolve;

		function cleanup() {
			overlay.classList.remove('is-visible');
			okButton.removeEventListener('click', onOk);
			cancelButton.removeEventListener('click', onCancel);
			overlay.removeEventListener('click', onOverlayClick);
			document.removeEventListener('keydown', onKeyDown);
			confirmModalResolve = null;
		}

		function onOk() {
			cleanup();
			resolve(true);
		}

		function onCancel() {
			cleanup();
			resolve(false);
		}

		function onOverlayClick(e) {
			if (e.target === overlay) {
				onCancel();
			}
		}

		function onKeyDown(e) {
			if (e.key === 'Escape') {
				onCancel();
			}
		}

		okButton.addEventListener('click', onOk);
		cancelButton.addEventListener('click', onCancel);
		overlay.addEventListener('click', onOverlayClick);
		document.addEventListener('keydown', onKeyDown);
	});
}

function runRecordingAction(actionIndex, recordingPath, event)
{
	if (event) {
		event.stopPropagation();
	}

	var url = appendRecordingsAuthHash('?ajax=run_action&action=' + encodeURIComponent(actionIndex) + '&file=' + encodeURIComponent(recordingPath));
	fetch(url)
		.then(function (response) { return response.json(); })
		.then(function (data) {
			if (data.ok) {
				refreshRecordings(true);
			} else {
				setStatus('Action failed: ' + (data.error || 'Unknown error.'), true);
			}
		})
		.catch(function () {
			setStatus('Action request failed.', true);
		});
}

function onDownloadSelectedButtonClicked()
{
	if (!selectedPath || !recordingsByPath[selectedPath]) {
		return;
	}

	var recording = recordingsByPath[selectedPath];
	var downloadUrl = '?ajax=stream&file=' + encodeURIComponent(recording.path) + '&download=1&v=' + recording.mtime;
	window.location.href = appendRecordingsAuthHash(downloadUrl);
}

function onListenLiveButtonClicked()
{
	if (!selectedPath || !recordingsByPath[selectedPath]) {
		return;
	}

	var recording = recordingsByPath[selectedPath];
	var liveSourceUrl = getLiveSourceUrlFromRecording(recording);
	if (liveSourceUrl === '') {
		return;
	}

	var livePlaybackUrl = buildLiveProxyUrl(recording);
	if (livePlaybackUrl === '') {
		return;
	}

	var audioPlayer = document.getElementById('audioPlayer');
	if (!audioPlayer) {
		return;
	}

	var currentMode = audioPlayer.getAttribute('data-mode');
	var currentLiveUrl = audioPlayer.getAttribute('data-live-url');
	if (currentMode !== 'live' || currentLiveUrl !== liveSourceUrl) {
		audioPlayer.setAttribute('data-path', recording.path);
		audioPlayer.setAttribute('data-mode', 'live');
		audioPlayer.setAttribute('data-live-url', liveSourceUrl);
		audioPlayer.src = livePlaybackUrl;
		audioPlayer.load();
	}

	playAudioElement(audioPlayer);
	updateCustomPlayerUi();
}

function applySelectedToPlayer(autoPlay, forceRecordingSource)
{
	var titleElement = document.getElementById('selectedTitle');
	var metaElement = document.getElementById('selectedMeta');
	var actionsWrap = document.getElementById('selectedRecordingActions');
	var downloadButton = document.getElementById('downloadSelectedButton');
	var listenLiveButton = document.getElementById('listenLiveButton');
	var audioPlayer = document.getElementById('audioPlayer');

	if (!selectedPath || !recordingsByPath[selectedPath]) {
		titleElement.textContent = 'No recording selected';
		metaElement.textContent = 'Select a recording to begin playback.';
		if (actionsWrap) {
			actionsWrap.style.display = 'none';
		}
		if (downloadButton) {
			downloadButton.textContent = 'Download';
			downloadButton.setAttribute('title', 'Download selected recording');
			downloadButton.style.display = 'none';
		}
		if (listenLiveButton) {
			listenLiveButton.classList.remove('is-live');
			listenLiveButton.textContent = 'Listen Live';
			listenLiveButton.setAttribute('aria-pressed', 'false');
			listenLiveButton.setAttribute('title', 'Listen live source');
			listenLiveButton.style.display = 'none';
		}
		audioPlayer.removeAttribute('data-path');
		audioPlayer.removeAttribute('data-mode');
		audioPlayer.removeAttribute('data-live-url');
		audioPlayer.removeAttribute('src');
		audioPlayer.load();
		updateCustomPlayerUi();
		return;
	}

	var recording = recordingsByPath[selectedPath];

	var streamUrl = appendRecordingsAuthHash('?ajax=stream&file=' + encodeURIComponent(recording.path) + '&v=' + recording.mtime);
	var currentMode = audioPlayer.getAttribute('data-mode');
	var currentPath = audioPlayer.getAttribute('data-path');
	var isLiveModeForSelectedRecording = (currentMode === 'live' && currentPath === recording.path);
	var shouldLoadRecordingStream = (forceRecordingSource === true) || (!isLiveModeForSelectedRecording && currentPath !== recording.path);

	if (shouldLoadRecordingStream) {
		audioPlayer.setAttribute('data-path', recording.path);
		audioPlayer.setAttribute('data-mode', 'recording');
		audioPlayer.removeAttribute('data-live-url');
		audioPlayer.src = streamUrl;
		audioPlayer.load();
	} else if (!isLiveModeForSelectedRecording) {
		audioPlayer.setAttribute('data-path', recording.path);
		audioPlayer.setAttribute('data-mode', 'recording');
		audioPlayer.removeAttribute('data-live-url');
	}

	if (actionsWrap) {
		actionsWrap.style.display = 'flex';
	}

	if (downloadButton) {
		var downloadButtonLabel = buildDownloadButtonLabel(recording);
		downloadButton.textContent = downloadButtonLabel;
		downloadButton.setAttribute('title', downloadButtonLabel);
		downloadButton.style.display = 'inline-block';
	}

	if (listenLiveButton) {
		var liveSourceUrl = getLiveSourceUrlFromRecording(recording);
		var listenLiveLabel = buildListenLiveButtonLabel(recording);
		listenLiveButton.classList.remove('is-live');
		listenLiveButton.textContent = listenLiveLabel;
		listenLiveButton.setAttribute('aria-pressed', 'false');
		listenLiveButton.setAttribute('title', listenLiveLabel + ' (Play live source in built-in player)');
		if (liveSourceUrl !== '') {
			listenLiveButton.style.display = 'inline-block';
		} else {
			listenLiveButton.style.display = 'none';
		}
	}

	renderSelectedPlaybackSummary(recording, audioPlayer);

	if (autoPlay === true) {
		playAudioElement(audioPlayer);
	}

	updateCustomPlayerUi();
}

function selectRecording(path, autoPlay)
{
	var forceRecordingSource = (selectedPath === path);
	selectedPath = path;
	removePendingAutoPlayPath(path);
	updateSelectedRowHighlight();
	applySelectedToPlayer(autoPlay === true, forceRecordingSource);
}

function initializeSelectedRecordingActionButtons()
{
	var downloadButton = document.getElementById('downloadSelectedButton');
	if (downloadButton) {
		downloadButton.addEventListener('click', onDownloadSelectedButtonClicked);
	}

	var listenLiveButton = document.getElementById('listenLiveButton');
	if (listenLiveButton) {
		listenLiveButton.addEventListener('click', onListenLiveButtonClicked);
	}
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

function parseDateTimeInputValue(inputValue)
{
	if (!inputValue) {
		return null;
	}

	var parsedDate = new Date(inputValue);
	if (isNaN(parsedDate.getTime())) {
		return null;
	}

	return parsedDate;
}

function parseDateOnlyValue(dateValue)
{
	if (!dateValue) {
		return null;
	}

	var parts = String(dateValue).split('-');
	if (parts.length !== 3) {
		return null;
	}

	var year = parseInt(parts[0], 10);
	var monthIndex = parseInt(parts[1], 10) - 1;
	var day = parseInt(parts[2], 10);
	if (isNaN(year) || isNaN(monthIndex) || isNaN(day)) {
		return null;
	}

	var parsedDate = new Date(year, monthIndex, day);
	if (isNaN(parsedDate.getTime())) {
		return null;
	}

	return parsedDate;
}

function formatDateTimeLocalValue(date)
{
	if (!date || isNaN(date.getTime())) {
		return '';
	}

	var pad = function (value) {
		return String(value).padStart(2, '0');
	};

	return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
}

function shiftDateByDays(date, dayDelta)
{
	var shiftedDate = new Date(date.getTime());
	shiftedDate.setDate(shiftedDate.getDate() + dayDelta);
	return shiftedDate;
}

function shiftDateByMinutes(date, minuteDelta)
{
	var shiftedDate = new Date(date.getTime());
	shiftedDate.setMinutes(shiftedDate.getMinutes() + minuteDelta);
	return shiftedDate;
}

function buildRecordingDateHeading(dateValue, itemCount)
{
	var headingText = String(dateValue || '');
	var parsedDate = parseDateOnlyValue(dateValue);
	if (parsedDate) {
		var weekdayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
		headingText = weekdayNames[parsedDate.getDay()] + ' ' + headingText;
	}

	return headingText + ' (' + itemCount + ')';
}

function getRecordingFormatLabel(recording)
{
	var fileName = '';
	if (recording) {
		fileName = String(recording.name || recording.path || '');
	}

	var lastDotIndex = fileName.lastIndexOf('.');
	if (lastDotIndex === -1 || lastDotIndex === (fileName.length - 1)) {
		return 'N/A';
	}

	return fileName.substring(lastDotIndex + 1).toUpperCase();
}

function getOldestRecordingInGroups(groups)
{
	if (!groups || groups.length === 0) {
		return null;
	}

	var lastGroup = groups[groups.length - 1];
	if (!lastGroup || !lastGroup.items || lastGroup.items.length === 0) {
		return null;
	}

	return lastGroup.items[lastGroup.items.length - 1];
}

function buildPreviousWeekRange()
{
	var startInput = document.getElementById('recordingsFilterStart');
	var endInput = document.getElementById('recordingsFilterEnd');
	var currentStart = startInput ? parseDateTimeInputValue(startInput.value) : null;
	var currentEnd = endInput ? parseDateTimeInputValue(endInput.value) : null;
	var nextStart = null;
	var nextEnd = null;

	if (currentStart && currentEnd) {
		nextStart = shiftDateByDays(currentStart, -7);
		nextEnd = shiftDateByDays(currentEnd, -7);
	} else if (currentStart) {
		nextStart = shiftDateByDays(currentStart, -7);
		nextEnd = shiftDateByMinutes(currentStart, -1);
	} else if (currentEnd) {
		nextStart = shiftDateByDays(currentEnd, -14);
		nextEnd = shiftDateByDays(currentEnd, -7);
	} else {
		var normalizedFilter = getRecordingNameFilterValue();
		var currentDateRange = getDateRangeFilter();
		var visibleGroups = filterRecordingGroups(allRecordingsGroups, normalizedFilter, currentDateRange);
		var oldestVisibleRecording = getOldestRecordingInGroups(visibleGroups);
		if (!oldestVisibleRecording) {
			return null;
		}

		var oldestVisibleTimestamp = parseInt(oldestVisibleRecording.timestamp, 10);
		if (isNaN(oldestVisibleTimestamp) || oldestVisibleTimestamp <= 0) {
			return null;
		}

		var oldestVisibleDate = new Date(oldestVisibleTimestamp * 1000);
		if (isNaN(oldestVisibleDate.getTime())) {
			return null;
		}

		nextStart = shiftDateByDays(oldestVisibleDate, -7);
		nextEnd = shiftDateByMinutes(oldestVisibleDate, -1);
	}

	if (!nextStart || !nextEnd || isNaN(nextStart.getTime()) || isNaN(nextEnd.getTime())) {
		return null;
	}

	return {
		startDate: nextStart,
		endDate: nextEnd,
		startTs: Math.floor(nextStart.getTime() / 1000),
		endTs: Math.floor(nextEnd.getTime() / 1000)
	};
}

function hasRecordingsForFilterRange(normalizedFilter, dateRange)
{
	if (!dateRange || dateRange.startTs === null || dateRange.endTs === null) {
		return false;
	}

	for (var groupIndex = 0; groupIndex < allRecordingsGroups.length; groupIndex++) {
		var group = allRecordingsGroups[groupIndex];
		for (var itemIndex = 0; itemIndex < group.items.length; itemIndex++) {
			var recording = group.items[itemIndex];
			if (!recordingMatchesFilter(recording, normalizedFilter)) {
				continue;
			}

			if (recordingMatchesDateRange(recording, dateRange)) {
				return true;
			}
		}
	}

	return false;
}

function goToPreviousWeek()
{
	var previousWeekRange = buildPreviousWeekRange();
	if (!previousWeekRange) {
		return;
	}

	var startInput = document.getElementById('recordingsFilterStart');
	var endInput = document.getElementById('recordingsFilterEnd');
	if (startInput) {
		startInput.value = formatDateTimeLocalValue(previousWeekRange.startDate);
	}

	if (endInput) {
		endInput.value = formatDateTimeLocalValue(previousWeekRange.endDate);
	}

	var recordingsList = document.getElementById('recordingsList');
	if (recordingsList) {
		recordingsList.scrollTop = 0;
	}

	onFilterInputChanged();
}

function getDateRangeFilter()
{
	var startInput = document.getElementById('recordingsFilterStart');
	var endInput = document.getElementById('recordingsFilterEnd');
	var startTs = null;
	var endTs = null;

	if (startInput && startInput.value !== '') {
		var startDate = parseDateTimeInputValue(startInput.value);
		if (startDate) {
			startTs = Math.floor(startDate.getTime() / 1000);
		}
	}

	if (endInput && endInput.value !== '') {
		var endDate = parseDateTimeInputValue(endInput.value);
		if (endDate) {
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
	var metadataComment = String(recording.metadata_comment || '').toLowerCase();
	var metadataTitle = String(recording.metadata_title || '').toLowerCase();

	return prettyName.indexOf(normalizedFilter) !== -1
		|| rawName.indexOf(normalizedFilter) !== -1
		|| metadataComment.indexOf(normalizedFilter) !== -1
		|| metadataTitle.indexOf(normalizedFilter) !== -1;
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

function initPlaybackOptionPreferences()
{
	var autoPlayCheckbox = document.getElementById('autoPlayNewCheckbox');
	var keepPlayingCheckbox = document.getElementById('keepPlayingChronologicallyCheckbox');

	try {
		if (autoPlayCheckbox) {
			var storedAutoPlay = window.localStorage.getItem('recordingsAutoPlayNewEnabled');
			if (storedAutoPlay === '0' || storedAutoPlay === '1') {
				autoPlayCheckbox.checked = (storedAutoPlay === '1');
			}
		}

		if (keepPlayingCheckbox) {
			var storedKeepPlaying = window.localStorage.getItem('recordingsKeepPlayingChronologicallyEnabled');
			if (storedKeepPlaying === '0' || storedKeepPlaying === '1') {
				keepPlayingCheckbox.checked = (storedKeepPlaying === '1');
			}
		}
	} catch (error) {
	}
}

function persistPlaybackOptionPreferences()
{
	var autoPlayCheckbox = document.getElementById('autoPlayNewCheckbox');
	var keepPlayingCheckbox = document.getElementById('keepPlayingChronologicallyCheckbox');

	try {
		if (autoPlayCheckbox) {
			window.localStorage.setItem('recordingsAutoPlayNewEnabled', autoPlayCheckbox.checked ? '1' : '0');
		}

		if (keepPlayingCheckbox) {
			window.localStorage.setItem('recordingsKeepPlayingChronologicallyEnabled', keepPlayingCheckbox.checked ? '1' : '0');
		}
	} catch (error) {
	}
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
	persistPlaybackOptionPreferences();

	if (!isAutoPlayNewEnabled()) {
		if (pendingAutoPlayPaths.length > 0) {
			pendingAutoPlayPaths = [];
			refreshStatusDetails();
		}
		return;
	}

	tryStartPendingAutoPlay();
}

function onKeepPlayingChronologicallyCheckboxChanged()
{
	persistPlaybackOptionPreferences();

	if (!isKeepPlayingEnabled()) {
		return;
	}

	// If nothing is selected yet, start from the oldest recording.
	if (!selectedPath || !isAudioPlayerActivelyPlaying()) {
		playNextChronological();
	}
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
		emptyMessage.textContent = 'No WAV/MP3/OGG recordings found.';
		recordingsList.appendChild(emptyMessage);
		updateRecordingsStickyOffsets();
		return;
	}

	for (var groupIndex = 0; groupIndex < groups.length; groupIndex++) {
		var group = groups[groupIndex];

		var header = document.createElement('div');
		header.className = 'recording-date';
		header.textContent = buildRecordingDateHeading(group.date, group.items.length);
		recordingsList.appendChild(header);

		var table = document.createElement('table');
		table.className = 'recording-table';
		if (recordingActions.length > 0) {
			table.style.tableLayout = 'auto';
		}

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
		contentTypeHead.textContent = 'Format';

		var sizeHead = document.createElement('th');
		sizeHead.className = 'recording-size';
		sizeHead.textContent = 'Size';

		var downloadHead = document.createElement('th');
		downloadHead.className = 'recording-download';
		downloadHead.textContent = 'Download';

		headRow.appendChild(dateHead);
		headRow.appendChild(timeHead);
		headRow.appendChild(nameHead);
		headRow.appendChild(sizeHead);
		headRow.appendChild(contentTypeHead);
		headRow.appendChild(durationHead);
		if (recordingActions.length > 0) {
			var actionsHead = document.createElement('th');
			actionsHead.className = 'recording-actions';
			actionsHead.textContent = 'Actions';
			headRow.appendChild(actionsHead);
		}
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
			var nameText = document.createElement('span');
			nameText.textContent = recording.name_pretty;
			nameCell.appendChild(nameText);
			var filterIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
			filterIcon.setAttribute('class', 'recording-filter-icon');
			filterIcon.setAttribute('viewBox', '0 0 24 24');
			filterIcon.setAttribute('fill', 'none');
			filterIcon.setAttribute('stroke', 'currentColor');
			filterIcon.setAttribute('stroke-width', '2');
			filterIcon.setAttribute('stroke-linecap', 'round');
			filterIcon.setAttribute('stroke-linejoin', 'round');
			filterIcon.setAttribute('aria-hidden', 'true');
			filterIcon.setAttribute('focusable', 'false');
			var filterPolygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
			filterPolygon.setAttribute('points', '22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3');
			filterIcon.appendChild(filterPolygon);
			filterIcon.setAttribute('title', 'Filter by: ' + recording.name_pretty);
			filterIcon.onclick = (function (name) {
				return function (event) {
					event.stopPropagation();
					setFilterToName(name);
				};
			})(recording.name_pretty);
			nameCell.appendChild(filterIcon);
			nameCell.title = recording.name;

			var durationCell = document.createElement('td');
			durationCell.className = 'recording-duration';
			durationCell.textContent = recording.duration_display;

			var contentTypeCell = document.createElement('td');
			contentTypeCell.className = 'recording-content-type';
			contentTypeCell.textContent = getRecordingFormatLabel(recording);

			var sizeCell = document.createElement('td');
			sizeCell.className = 'recording-size';
			sizeCell.textContent = recording.size_human;

			var downloadCell = document.createElement('td');
			downloadCell.className = 'recording-download';

			var rowDownloadLink = document.createElement('a');
			rowDownloadLink.href = appendRecordingsAuthHash('?ajax=stream&file=' + encodeURIComponent(recording.path) + '&download=1&v=' + recording.mtime);
			rowDownloadLink.textContent = 'Download';
			rowDownloadLink.setAttribute('download', recording.name);
			rowDownloadLink.onclick = function (event) {
				event.stopPropagation();
			};

			downloadCell.appendChild(rowDownloadLink);

			row.appendChild(dateCell);
			row.appendChild(timeCell);
			row.appendChild(nameCell);
			row.appendChild(sizeCell);
			row.appendChild(contentTypeCell);
			row.appendChild(durationCell);
			if (recordingActions.length > 0) {
				var actionsCell = document.createElement('td');
				actionsCell.className = 'recording-actions';
				for (var actionIdx = 0; actionIdx < recordingActions.length; actionIdx++) {
					if (actionIdx > 0) {
						actionsCell.appendChild(document.createTextNode(' | '));
					}
					var actionLink = document.createElement('a');
					actionLink.href = '#';
					actionLink.textContent = String(recordingActions[actionIdx].label || '');
					actionLink.onclick = (function (idx, path, confirmation) {
						return function (event) {
							event.preventDefault();
							event.stopPropagation();
							if (confirmation) {
								showConfirmModal(confirmation).then(function (confirmed) {
									if (confirmed) {
										runRecordingAction(idx, path);
									}
								});
							} else {
								runRecordingAction(idx, path);
							}
							return false;
						};
					})(actionIdx, recording.path, recordingActions[actionIdx].confirmation || '');
					actionsCell.appendChild(actionLink);
				}
				row.appendChild(actionsCell);
			}
			row.appendChild(downloadCell);
			tableBody.appendChild(row);
		}

		table.appendChild(tableBody);

		recordingsList.appendChild(table);
	}

	var previousWeekWrap = document.createElement('div');
	previousWeekWrap.className = 'recordings-pagination';

	var previousWeekButton = document.createElement('button');
	previousWeekButton.type = 'button';
	previousWeekButton.className = 'previous-week-button';
	previousWeekButton.textContent = 'Previous week';

	var previousWeekRange = buildPreviousWeekRange();
	var normalizedFilter = getRecordingNameFilterValue();
	if (!previousWeekRange || !hasRecordingsForFilterRange(normalizedFilter, previousWeekRange)) {
		previousWeekButton.disabled = true;
		previousWeekButton.title = 'No older recordings match the current filters.';
	} else {
		previousWeekButton.onclick = goToPreviousWeek;
		previousWeekButton.title = 'Shift the current date filters back one week.';
	}

	previousWeekWrap.appendChild(previousWeekButton);
	recordingsList.appendChild(previousWeekWrap);
	updateRecordingsStickyOffsets();
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

	window.location.href = appendRecordingsAuthHash(url);
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

	xhr.open('GET', appendRecordingsAuthHash('?ajax=list&rnd=' + Math.random()), true);
	xhr.send(null);
}

function startRecordingsPage()
{
	initTheme();
	initFiltersVisibility();
	initializeCustomPlayerControls();
	initializeSelectedRecordingActionButtons();
	initPlaybackOptionPreferences();
	var filterInput = document.getElementById('recordingsFilterInput');
	if (filterInput) {
		filterInput.addEventListener('input', function () {
			updateFilterClearButton();
			onFilterInputChanged();
		});
	}

	var filterClearButton = document.getElementById('filterClearButton');
	if (filterClearButton) {
		filterClearButton.addEventListener('click', onFilterClearButtonClicked);
	}

	var dateStartInput = document.getElementById('recordingsFilterStart');
	if (dateStartInput) {
		var oneWeekAgo = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000);
		dateStartInput.value = formatDateTimeLocalValue(oneWeekAgo);
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
		keepPlayingCheckbox.addEventListener('change', onKeepPlayingChronologicallyCheckboxChanged);
	}

	var audioPlayer = document.getElementById('audioPlayer');
	if (audioPlayer) {
		audioPlayer.addEventListener('ended', onAudioPlayerEnded);
		audioPlayer.addEventListener('play', updateCustomPlayerUi);
		audioPlayer.addEventListener('pause', updateCustomPlayerUi);
		audioPlayer.addEventListener('loadedmetadata', updateCustomPlayerUi);
		audioPlayer.addEventListener('durationchange', updateCustomPlayerUi);
		audioPlayer.addEventListener('timeupdate', updateCustomPlayerUi);
		audioPlayer.addEventListener('emptied', updateCustomPlayerUi);
		audioPlayer.addEventListener('seeking', updateCustomPlayerUi);
		audioPlayer.addEventListener('seeked', updateCustomPlayerUi);
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

<div class="confirm-modal-overlay" id="confirmModalOverlay" role="dialog" aria-modal="true" aria-labelledby="confirmModalMessage">
	<div class="confirm-modal-box">
		<div class="confirm-modal-message" id="confirmModalMessage"></div>
		<div class="confirm-modal-buttons">
			<button type="button" class="confirm-modal-button" id="confirmModalCancelButton">Cancel</button>
			<button type="button" class="confirm-modal-button is-primary" id="confirmModalOkButton">OK</button>
		</div>
	</div>
</div>

</div>
</body>
</html>
