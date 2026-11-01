<?php
if (!class_exists('DOMDocument')) {
    echo "SKIP relay console tests: dom unavailable\n";
    return;
}

define('RC_KEY', 'bynli_sh_' . str_repeat('a', 32));

function rc_page(array $opts, array $get = []): ?DOMXPath
{
    $cmd  = [PHP_BINARY, __DIR__ . '/fixtures/console-child.php', json_encode(['opts' => $opts, 'get' => $get])];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return null;
    }
    $html = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    if (trim($html) === '') {
        return null;
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    return new DOMXPath($doc);
}

function rc_q(?DOMXPath $x, string $query): array
{
    if ($x === null) {
        return [];
    }
    $out = [];
    foreach ($x->query($query) as $n) {
        $out[] = $n;
    }
    return $out;
}

function rc_text(array $nodes): string
{
    return isset($nodes[0]) ? trim(preg_replace('/\s+/', ' ', $nodes[0]->textContent)) : '';
}

function rc_cls(string $c): string
{
    return "contains(concat(' ', normalize-space(@class), ' '), ' $c ')";
}

$connected_opts = [
    'bynli_connect_api_key'        => RC_KEY,
    'bynli_connect_last_report'    => ['at' => time() - 60, 'kind' => 'heartbeat', 'status' => 200, 'ok' => true],
    'bynli_connect_report_history' => [['at' => time() - 60, 'kind' => 'heartbeat', 'ok' => true, 'status' => 200]],
];

$fresh = rc_page(['bynli_connect_api_key' => RC_KEY]);
t_true($fresh !== null, 'console: the page renders');
$steps = rc_q($fresh, '//li[' . rc_cls('bcn-onramp-step') . ']');
t_eq(count($steps), 3, 'empty activity: three on-ramp steps');
t_true(strpos($steps[0]->getAttribute('class'), 'is-done') !== false, 'empty activity: a saved key is step one, done');
t_true(strpos($steps[1]->getAttribute('class'), 'is-next') !== false && $steps[1]->getAttribute('aria-current') === 'step', 'empty activity: the test heartbeat is the current step');
t_true(strpos($steps[2]->getAttribute('class'), 'is-next') === false && strpos($steps[2]->getAttribute('class'), 'is-done') === false, 'empty activity: the daily report is neither current nor done');
t_eq(count(rc_q($fresh, '//*[@aria-current="step"]')), 1, 'empty activity: exactly one current step');
$cta = rc_q($fresh, '//div[' . rc_cls('bcn-empty--onramp') . ']//a[@data-go="connection"]');
t_eq(count($cta), 1, 'empty activity: one call to action into Connection');
t_eq(rc_text($cta), 'Send a test heartbeat', 'empty activity: the call to action is the next step');
t_true(isset($cta[0]) && strpos($cta[0]->getAttribute('href'), 'section=connection') !== false, 'empty activity: the call to action works without script');
t_eq(count(rc_q($fresh, '//div[' . rc_cls('bcn-empty--onramp') . ']//svg[@aria-hidden="true"]')), 1, 'empty activity: the illustration is hidden from assistive tech');
t_eq(count(rc_q($fresh, '//ul[' . rc_cls('bcn-log') . ']')), 0, 'empty activity: no log list when there is no history');

$nokey = rc_page([]);
$nokey_steps = rc_q($nokey, '//li[' . rc_cls('bcn-onramp-step') . ']');
t_true(isset($nokey_steps[0]) && strpos($nokey_steps[0]->getAttribute('class'), 'is-next') !== false, 'empty activity, no key: saving the key is the current step');
$nokey_cta = rc_q($nokey, '//div[' . rc_cls('bcn-empty--onramp') . ']//a[@data-go="connection"]');
t_eq(rc_text($nokey_cta), 'Add your key', 'empty activity, no key: the call to action adds the key');

$live = rc_page($connected_opts);
t_eq(count(rc_q($live, '//div[' . rc_cls('bcn-empty--onramp') . ']')), 0, 'activity with history: no on-ramp');
t_eq(count(rc_q($live, '//ul[' . rc_cls('bcn-log') . ']')), 1, 'activity with history: the log renders');

$hero = rc_q($live, '//*[@data-bcn="uplink-hero"]');
t_eq($hero[0]->getAttribute('data-state') ?? null, 'on', 'overview: a verified site reads connected');
t_true(strpos($hero[0]->getAttribute('class') ?? '', 'is-first-connect') === false, 'overview: no celebration on an ordinary load');
$moment = rc_q($live, '//*[@data-bcn="uplink-moment"]');
t_true(count($moment) === 1 && $moment[0]->hasAttribute('hidden'), 'overview: the first-connect line is hidden on an ordinary load');

$first = rc_page($connected_opts, ['tested' => 'first']);
$hero = rc_q($first, '//*[@data-bcn="uplink-hero"]');
t_true(strpos($hero[0]->getAttribute('class') ?? '', 'is-first-connect') !== false, 'overview: the first connect is celebrated after the no-script redirect');
$moment = rc_q($first, '//*[@data-bcn="uplink-moment"]');
t_true(count($moment) === 1 && !$moment[0]->hasAttribute('hidden'), 'overview: the first-connect line is shown');
t_true(strpos(implode(' ', array_map(function ($n) { return $n->textContent; }, rc_q($first, '//div[' . rc_cls('bcn-notice-ok') . ']'))), 'first signed heartbeat') !== false, 'flash: the redirect announces the first connect');

$plain_ok = rc_page($connected_opts, ['tested' => 'ok']);
$hero = rc_q($plain_ok, '//*[@data-bcn="uplink-hero"]');
t_true(strpos($hero[0]->getAttribute('class') ?? '', 'is-first-connect') === false, 'overview: a later successful ping is not celebrated');

$spoof = rc_page(['bynli_connect_api_key' => RC_KEY], ['tested' => 'first']);
$hero = rc_q($spoof, '//*[@data-bcn="uplink-hero"]');
t_eq($hero[0]->getAttribute('data-state') ?? null, 'off', 'overview: an unverified site still reads unverified');
t_true(strpos($hero[0]->getAttribute('class') ?? '', 'is-first-connect') === false, 'overview: a forged tested=first does not celebrate an unverified site');
$moment = rc_q($spoof, '//*[@data-bcn="uplink-moment"]');
t_true(count($moment) === 1 && $moment[0]->hasAttribute('hidden'), 'overview: a forged tested=first does not show the first-connect line');

$link = rc_q($fresh, '//a[@data-role="see-uplink"]');
t_true(count($link) === 1 && $link[0]->hasAttribute('hidden') && $link[0]->getAttribute('data-go') === 'overview', 'connection: the see-your-uplink link starts hidden and targets Overview');
t_eq(count(rc_q($fresh, '//*[@data-bcn="tile-connection"]')), 1, 'overview: the connection tile can be updated in place');
