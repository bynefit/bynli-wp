<?php
require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-reporter.php';

t_eq(Bynli_Connect_Reporter::has_verified([]), false, 'has_verified: an empty history has never verified');
t_eq(Bynli_Connect_Reporter::has_verified([['ok' => false], ['ok' => 0], 'junk']), false, 'has_verified: failures and junk rows do not count');
t_eq(Bynli_Connect_Reporter::has_verified([['ok' => false], ['ok' => true]]), true, 'has_verified: one earlier success is enough');
t_eq(Bynli_Connect_Reporter::has_verified([['kind' => 'daily', 'ok' => true]]), true, 'has_verified: a daily report counts as a verification');

function fc_run(string $scenario, string $mode = 'ajax'): ?array
{
    $cmd  = [PHP_BINARY, __DIR__ . '/fixtures/heartbeat-child.php', $scenario, $mode];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return null;
    }
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $json = json_decode($out, true);
    return is_array($json) ? $json : null;
}

$first = fc_run('first');
t_eq($first['success'] ?? null, true, 'heartbeat: the first successful ping succeeds');
t_eq($first['data']['first_connect'] ?? null, true, 'heartbeat: the first success is flagged as the first connect');
t_eq($first['data']['status'] ?? null, 200, 'heartbeat: the response still carries the status');
t_true(isset($first['data']['last_at_human'], $first['data']['message']), 'heartbeat: the existing response keys are kept');

$after = fc_run('after-success');
t_eq($after['success'] ?? null, true, 'heartbeat: a later ping succeeds');
t_eq($after['data']['first_connect'] ?? null, false, 'heartbeat: no celebration when the site verified before');

$retry = fc_run('after-failures');
t_eq($retry['data']['first_connect'] ?? null, true, 'heartbeat: earlier failures do not count as a prior verification');

$down = fc_run('server-down');
t_eq($down['success'] ?? null, false, 'heartbeat: a rejected ping is an error');
t_true(!isset($down['data']['first_connect']), 'heartbeat: a failed ping never reports a first connect');

$forbidden = fc_run('forbidden');
t_eq($forbidden['success'] ?? null, false, 'heartbeat: a user without the capability is refused');
t_true(!isset($forbidden['data']['first_connect']), 'heartbeat: a refused request reveals nothing');

$nonce = fc_run('bad-nonce');
t_eq($nonce['success'] ?? null, false, 'heartbeat: a bad nonce is refused');

$long = fc_run('long-failing');
t_eq($long['success'] ?? null, true, 'heartbeat: recovery after a long failing streak succeeds');
t_eq($long['data']['first_connect'] ?? null, false, 'heartbeat: a site verified long ago is not celebrated again after 14 failed reports');

$twice = fc_run('first', 'twice');
t_eq($twice['runs'][0]['data']['first_connect'] ?? null, true, 'heartbeat: the first run is the first connect');
t_eq($twice['runs'][1]['data']['first_connect'] ?? null, false, 'heartbeat: the second run is not, even with the report history gone');
t_eq($twice['verified'] ?? null, true, 'heartbeat: a successful ping is remembered');
t_eq($twice['stored'] ?? null, 123, 'heartbeat: a later successful ping does not move the remembered time');
$failed_twice = fc_run('server-down', 'twice');
t_eq($failed_twice['verified'] ?? null, false, 'heartbeat: a failed ping is not remembered as verified');

$redir_first = fc_run('first', 'redirect')['redirect'] ?? '';
t_true(strpos($redir_first, 'tested=first') !== false, 'redirect: the no-JS path also reports the first connect');
t_true(strpos($redir_first, 'section=overview') !== false, 'redirect: a first connect lands on Overview, where the hero is');
$redir_ok = fc_run('after-success', 'redirect')['redirect'] ?? '';
t_true(strpos($redir_ok, 'tested=ok') !== false && strpos($redir_ok, 'section=connection') !== false, 'redirect: a later ping reports a plain ok on Connection');
t_true(strpos(fc_run('server-down', 'redirect')['redirect'] ?? '', 'tested=fail') !== false, 'redirect: a rejected ping reports a failure');
t_eq(fc_run('forbidden', 'redirect')['died'] ?? null, 403, 'redirect: a user without the capability is refused');
