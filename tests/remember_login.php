<?php
declare(strict_types=1);

// Included by integration.php against its disposable database and HTTP server.
use App\Core\Database as DB;
use App\Core\RememberLogin;
use App\Models\PasswordReset;

function rememberedCookie(string $client): ?array
{
    global $clients;
    foreach (curl_getinfo($clients[$client], CURLINFO_COOKIELIST) as $line) {
        $fields = explode("\t", $line);
        if (($fields[5] ?? '') === RememberLogin::COOKIE && (int) $fields[4] > time()) return $fields;
    }
    return null;
}

$rememberCsrf = csrf('remember');
form('remember', '/register', ['csrf_token' => $rememberCsrf, 'full_name' => 'Remember Test', 'email' => 'remember@example.test', 'password' => 'Remember123']);
$rememberUserId = (int) DB::query('SELECT id FROM users WHERE email = ?', ['remember@example.test'])->get_result()->fetch_assoc()['id'];
request('admin', 'POST', '/api/users/save', ['id' => $rememberUserId, 'full_name' => 'Remember Test', 'email' => 'remember@example.test', 'role' => 'Farm Staff', 'status' => 'Active'], $adminHeaders);
$credentials = ['csrf_token' => $rememberCsrf, 'email' => 'remember@example.test', 'password' => 'Remember123'];
form('remember', '/login', $credentials);
check(rememberedCookie('remember') === null, 'Unchecked login does not create a persistent cookie');
curl_setopt($clients['remember'], CURLOPT_COOKIELIST, 'SESS');
request('remember', 'GET', '/api/dashboard', expected: 401);

$credentials['csrf_token'] = csrf('remember');
[, $failedLogin] = form('remember', '/login', array_replace($credentials, ['password' => 'wrong', 'remember_me' => '1']), 200);
check(str_contains($failedLogin, 'value="1" checked'), 'Failed login retains the checked Remember me option');
check(rememberedCookie('remember') === null, 'Failed login does not issue a persistent token');
form('remember', '/login', $credentials + ['remember_me' => '1']);
$cookie = rememberedCookie('remember');
check($cookie !== null && str_starts_with($cookie[0], '#HttpOnly_'), 'Remember cookie is HttpOnly');
check(abs((int) $cookie[4] - time() - 30 * 86400) < 10, 'Remember cookie expires in 30 days');
check($cookie[2] === '/' . basename(dirname(__DIR__)) . '/', 'Remember cookie is scoped to this application');
$initialToken = $cookie[6];
$stored = DB::query('SELECT token_hash FROM remember_tokens WHERE user_id = ?', [$rememberUserId])->get_result()->fetch_assoc();
check($stored['token_hash'] === hash('sha256', $initialToken) && $stored['token_hash'] !== $initialToken, 'Only the persistent token hash is stored');
curl_setopt($clients['remember'], CURLOPT_COOKIELIST, 'SESS');
request('remember', 'GET', '/api/dashboard');
$rotated = rememberedCookie('remember');
check($rotated[6] !== $initialToken && $rotated[4] === $cookie[4], 'Session restoration rotates the token without extending expiry');
request('replay', 'GET', '/api/dashboard', headers: ['Cookie: ' . RememberLogin::COOKIE . '=' . $initialToken], expected: 401);
request('tampered', 'GET', '/api/dashboard', headers: ['Cookie: ' . RememberLogin::COOKIE . '=' . str_repeat('a', 64)], expected: 401);
[, $rememberDashboard] = request('remember', 'GET', '/dashboard');
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $rememberDashboard, $match);
check(isset($match[1]), 'Restored session receives a fresh CSRF token');
form('remember', '/logout', ['csrf_token' => $match[1]]);
check(rememberedCookie('remember') === null, 'Sign out removes the persistent cookie');
request('revoked', 'GET', '/api/dashboard', headers: ['Cookie: ' . RememberLogin::COOKIE . '=' . $rotated[6]], expected: 401);

$credentials['csrf_token'] = csrf('remember');
form('remember', '/login', $credentials + ['remember_me' => '1']);
DB::query('UPDATE remember_tokens SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE user_id = ?', [$rememberUserId]);
curl_setopt($clients['remember'], CURLOPT_COOKIELIST, 'SESS');
request('remember', 'GET', '/api/dashboard', expected: 401);
check(rememberedCookie('remember') === null, 'Expired tokens clear the cookie and cannot sign in');

$credentials['csrf_token'] = csrf('remember');
form('remember', '/login', $credentials + ['remember_me' => '1']);
// Choosing a normal login again revokes the old remembered browser token.
form('remember', '/login', $credentials);
check(rememberedCookie('remember') === null, 'Unchecking Remember me removes a previous remembered login');
check(DB::query('SELECT token_hash FROM remember_tokens WHERE user_id = ?', [$rememberUserId])->get_result()->num_rows === 0, 'Unchecked login revokes the token in the database');

form('remember', '/login', $credentials + ['remember_me' => '1']);
request('admin', 'POST', '/api/users/save', ['id' => $rememberUserId, 'full_name' => 'Remember Test', 'email' => 'remember@example.test', 'role' => 'Farm Staff', 'password' => 'Changed123'], $adminHeaders);
curl_setopt($clients['remember'], CURLOPT_COOKIELIST, 'SESS');
request('remember', 'GET', '/api/dashboard', expected: 401);

$credentials['csrf_token'] = csrf('remember');
$credentials['password'] = 'Changed123';
form('remember', '/login', $credentials + ['remember_me' => '1']);
$rememberReset = new PasswordReset();
$rememberReset->create($rememberUserId, hash('sha256', '135790'));
check($rememberReset->verifyCode($rememberUserId, hash('sha256', '135790')), 'Remembered account reset code verifies');
check($rememberReset->consumeVerified($rememberUserId, password_hash('ResetPassword123', PASSWORD_DEFAULT)), 'Remembered account password resets');
curl_setopt($clients['remember'], CURLOPT_COOKIELIST, 'SESS');
request('remember', 'GET', '/api/dashboard', expected: 401);

$credentials['csrf_token'] = csrf('remember');
$credentials['password'] = 'ResetPassword123';
form('remember', '/login', $credentials + ['remember_me' => '1']);
request('admin', 'POST', '/api/users/delete', ['id' => $rememberUserId], $adminHeaders);
curl_setopt($clients['remember'], CURLOPT_COOKIELIST, 'SESS');
request('remember', 'GET', '/api/dashboard', expected: 401);
check(DB::query('SELECT token_hash FROM remember_tokens WHERE user_id = ?', [$rememberUserId])->get_result()->num_rows === 0, 'Deleting the account removes its remembered tokens');
