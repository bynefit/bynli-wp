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

$first = rc_page($connected_opts + ['bynli_connect_verified_at' => time()], ['tested' => 'first']);
$hero = rc_q($first, '//*[@data-bcn="uplink-hero"]');
t_true(strpos($hero[0]->getAttribute('class') ?? '', 'is-first-connect') !== false, 'overview: the first connect is celebrated after the no-script redirect');
$moment = rc_q($first, '//*[@data-bcn="uplink-moment"]');
t_true(count($moment) === 1 && !$moment[0]->hasAttribute('hidden'), 'overview: the first-connect line is shown');
t_true(strpos(implode(' ', array_map(function ($n) { return $n->textContent; }, rc_q($first, '//div[' . rc_cls('bcn-notice-ok') . ']'))), 'first signed heartbeat') !== false, 'flash: the redirect announces the first connect');

$stale = rc_page($connected_opts + ['bynli_connect_verified_at' => time() - 3600], ['tested' => 'first']);
$hero = rc_q($stale, '//*[@data-bcn="uplink-hero"]');
t_true(strpos($hero[0]->getAttribute('class') ?? '', 'is-first-connect') === false, 'overview: an old first-connect URL does not replay the celebration');
$moment = rc_q($stale, '//*[@data-bcn="uplink-moment"]');
t_true(count($moment) === 1 && $moment[0]->hasAttribute('hidden'), 'overview: an old first-connect URL does not show the first-connect line');
t_eq(count(rc_q($stale, '//div[' . rc_cls('bcn-notice-ok') . ']')), 1, 'flash: an old first-connect URL degrades to the plain success banner');
t_true(strpos(implode(' ', array_map(function ($n) { return $n->textContent; }, rc_q($stale, '//div[' . rc_cls('bcn-notice-ok') . ']'))), 'first signed heartbeat') === false, 'flash: an old first-connect URL does not claim a first heartbeat');
$skewed = rc_page($connected_opts + ['bynli_connect_verified_at' => time() + 9999], ['tested' => 'first']);
$hero = rc_q($skewed, '//*[@data-bcn="uplink-hero"]');
t_true(strpos($hero[0]->getAttribute('class') ?? '', 'is-first-connect') === false, 'overview: a verification time in the future does not count as fresh');
$failed_after = rc_page([
    'bynli_connect_api_key'        => RC_KEY,
    'bynli_connect_verified_at'    => time() - 120,
    'bynli_connect_last_report'    => ['at' => time() - 10, 'kind' => 'heartbeat', 'status' => 401, 'ok' => false],
    'bynli_connect_report_history' => [['at' => time() - 120, 'kind' => 'heartbeat', 'ok' => true, 'status' => 200], ['at' => time() - 10, 'kind' => 'heartbeat', 'ok' => false, 'status' => 401]],
], ['tested' => 'first']);
$hero = rc_q($failed_after, '//*[@data-bcn="uplink-hero"]');
t_eq($hero[0]->getAttribute('data-state') ?? null, 'off', 'overview: a site whose latest heartbeat failed reads unverified');
t_true(strpos($hero[0]->getAttribute('class') ?? '', 'is-first-connect') === false, 'overview: an old first-connect URL does not celebrate once the latest heartbeat has failed');
$moment = rc_q($failed_after, '//*[@data-bcn="uplink-moment"]');
t_true(count($moment) === 1 && $moment[0]->hasAttribute('hidden'), 'overview: the first-signal line stays hidden once the latest heartbeat has failed');
t_eq(count(rc_q($failed_after, '//div[' . rc_cls('bcn-notice-ok') . ']')), 0, 'flash: no success banner once the latest heartbeat has failed');
$unflagged = rc_page($connected_opts, ['tested' => 'first']);
$hero = rc_q($unflagged, '//*[@data-bcn="uplink-hero"]');
t_true(strpos($hero[0]->getAttribute('class') ?? '', 'is-first-connect') === false, 'overview: a first-connect URL with no recorded verification does not celebrate');

$plain_ok = rc_page($connected_opts, ['tested' => 'ok']);
$hero = rc_q($plain_ok, '//*[@data-bcn="uplink-hero"]');
t_true(strpos($hero[0]->getAttribute('class') ?? '', 'is-first-connect') === false, 'overview: a later successful ping is not celebrated');

$spoof = rc_page(['bynli_connect_api_key' => RC_KEY], ['tested' => 'first']);
$hero = rc_q($spoof, '//*[@data-bcn="uplink-hero"]');
t_eq($hero[0]->getAttribute('data-state') ?? null, 'off', 'overview: an unverified site still reads unverified');
t_true(strpos($hero[0]->getAttribute('class') ?? '', 'is-first-connect') === false, 'overview: a forged tested=first does not celebrate an unverified site');
$moment = rc_q($spoof, '//*[@data-bcn="uplink-moment"]');
t_true(count($moment) === 1 && $moment[0]->hasAttribute('hidden'), 'overview: a forged tested=first does not show the first-connect line');

t_eq(count(rc_q($live, '//div[' . rc_cls('bcn-notice-ok') . ']')), 0, 'flash: no banner on an ordinary load');
t_eq(count(rc_q($spoof, '//div[' . rc_cls('bcn-notice-ok') . ']')), 0, 'flash: a forged tested=first prints no success banner for an unverified site');
$spoof_ok = rc_page(['bynli_connect_api_key' => RC_KEY], ['tested' => 'ok']);
t_eq(count(rc_q($spoof_ok, '//div[' . rc_cls('bcn-notice-ok') . ']')), 0, 'flash: a forged tested=ok prints no success banner for an unverified site');
t_eq(count(rc_q($plain_ok, '//div[' . rc_cls('bcn-notice-ok') . ']')), 1, 'flash: a verified site shows the plain success banner');
t_eq(count(rc_q($fresh, '//ol[@data-bcn="onramp"][@role="list"]')), 1, 'empty activity: the on-ramp is a list in every browser and the script can find it');
t_eq(count(rc_q($fresh, '//a[@data-bcn="onramp-cta"]')), 1, 'empty activity: the call to action can be hidden once connected');
t_eq(count(rc_q($fresh, '//label[' . rc_cls('bcn-check') . '][@for="bcn_beacons"]')), 1, 'connection: the analytics checkbox row takes part in the 44px rule');

$link = rc_q($fresh, '//a[@data-role="see-uplink"]');
t_true(count($link) === 1 && $link[0]->hasAttribute('hidden') && $link[0]->getAttribute('data-go') === 'overview', 'connection: the see-your-uplink link starts hidden and targets Overview');
t_eq(count(rc_q($fresh, '//*[@data-bcn="tile-connection"]')), 1, 'overview: the connection tile can be updated in place');
t_eq(count(rc_q($fresh, '//*[@data-bcn="tile-last"]')), 1, 'overview: the daily report tile can be updated in place');

function rc_node(array $responses): ?array
{
    $proc = @proc_open(['node', __DIR__ . '/fixtures/admin-child.js', json_encode($responses)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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

$ok_first = ['success' => true, 'data' => ['message' => 'ok', 'status' => 200, 'last_at_human' => '1 min ago', 'first_connect' => true]];
$ok_again = ['success' => true, 'data' => ['message' => 'ok', 'status' => 200, 'last_at_human' => '2 min ago', 'first_connect' => false]];
$js = rc_node([$ok_first, $ok_again]);
if ($js === null) {
    echo "SKIP console script tests: node unavailable\n";
} else {
    $a = $js['afterFirst'] ?? [];
    t_eq($a['heroState'] ?? null, 'on', 'script: the hero flips to connected in place');
    t_eq($a['status'] ?? null, 'CONNECTED', 'script: the readout says CONNECTED');
    t_eq($a['sub'] ?? null, 'Reporting to Bynefit · last ping 1 min ago', 'script: the supporting line is rewritten');
    t_eq($a['momentHidden'] ?? null, false, 'script: the first-connect line is revealed');
    t_eq($a['celebrating'] ?? null, true, 'script: the hero celebrates the first connect');
    t_eq([$a['tile'] ?? null, $a['tileState'] ?? null, $a['pill'] ?? null], ['Verified', 'ok', 'Connected'], 'script: the tile and topbar pill follow');
    t_eq([$a['lastTile'] ?? null, $a['lastTileState'] ?? null], ['1 min ago', 'ok'], 'script: the daily report tile shows the ping time instead of never');
    t_eq($a['linkHidden'] ?? null, false, 'script: the see-your-uplink link appears');
    t_eq([$a['stepTwoDone'] ?? null, $a['stepTwoCurrent'] ?? null, $a['stepThreeCurrent'] ?? null, $a['stepThreeNext'] ?? null], [true, false, 'step', true], 'script: the on-ramp moves on to the daily report');
    t_eq($a['ctaHidden'] ?? null, true, 'script: the on-ramp call to action is hidden once connected');
    t_eq($a['stepTwoSr'] ?? null, 1, 'script: the finished step is announced as done');
    t_eq($js['afterOtherAnimation'] ?? null, true, 'script: only the ping ends the celebration, not the other animations');
    t_eq($js['afterPing'] ?? null, false, 'script: the celebration clears when the ping ends, so it cannot replay');
    t_eq($js['listenersLeft'] ?? null, 0, 'script: the settle listener removes itself');
    t_eq($js['afterSecond']['celebrating'] ?? null, false, 'script: a later ping does not celebrate');
    t_eq($js['afterSecond']['linkHidden'] ?? null, true, 'script: a later ping hides the see-your-uplink link');
    t_eq([$js['afterLink']['overviewActive'] ?? null, $js['afterLink']['connectionActive'] ?? null], [true, false], 'script: the link switches to the Overview panel');
    t_eq([$js['afterLink']['overviewFocused'] ?? null, $js['afterLink']['navFocused'] ?? null], [true, false], 'script: focus moves into the panel the link opened');
    t_eq($js['afterNav']['overviewFocused'] ?? null, false, 'script: a rail click leaves focus on the rail');
}

$unin = proc_open([PHP_BINARY, __DIR__ . '/fixtures/uninstall-child.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $unin_pipes);
$unin_out = is_resource($unin) ? (string) stream_get_contents($unin_pipes[1]) : '';
if (is_resource($unin)) {
    fclose($unin_pipes[1]);
    fclose($unin_pipes[2]);
    proc_close($unin);
}
$deleted = json_decode($unin_out, true) ?: [];
t_true(in_array('bynli_connect_verified_at', $deleted, true), 'uninstall: the remembered verification is removed');
t_true(in_array('bynli_connect_report_history', $deleted, true), 'uninstall: the existing options are still removed');
