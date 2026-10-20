<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}

const BCN_WATCHED_PATHS = ['includes/', 'blocks/', 'assets/', 'bynli-connect.php', 'uninstall.php'];

function bcn_version_problems(string $plugin_php, string $readme): array
{
    $problems = [];
    $header = preg_match('/^\s*\*\s*Version:\s*(\S+)/mi', $plugin_php, $m) ? $m[1] : '';
    $const  = preg_match("/define\\(\\s*'BYNLI_CONNECT_VERSION'\\s*,\\s*'([^']+)'/", $plugin_php, $m) ? $m[1] : '';
    $stable = preg_match('/^Stable tag:\s*(\S+)/mi', $readme, $m) ? $m[1] : '';
    if ($header === '' || $const === '' || $stable === '') {
        $problems[] = 'could not read the Version header, BYNLI_CONNECT_VERSION or Stable tag';
        return $problems;
    }
    if ($header !== $const) {
        $problems[] = "Version header $header differs from BYNLI_CONNECT_VERSION $const";
    }
    if ($stable !== $const) {
        $problems[] = "readme Stable tag $stable differs from BYNLI_CONNECT_VERSION $const";
    }
    if (bcn_changelog_section($readme, $const) === null) {
        $problems[] = "readme changelog has no entry for $const";
    }
    return $problems;
}

function bcn_changelog_section(string $readme, string $version): ?string
{
    $readme = str_replace("\r\n", "\n", $readme);
    $pos = strpos($readme, '== Changelog ==');
    if ($pos === false) {
        return null;
    }
    $log = substr($readme, $pos);
    if (!preg_match('/^= ' . preg_quote($version, '/') . ' =\s*$(.*?)(?=^= |\z)/ms', $log, $m)) {
        return null;
    }
    return $m[1];
}

function bcn_is_watched(string $path): bool
{
    foreach (BCN_WATCHED_PATHS as $w) {
        if ($path === $w || (substr($w, -1) === '/' && strpos($path, $w) === 0)) {
            return true;
        }
    }
    return false;
}

function bcn_undisclosed(array $commits, string $section): array
{
    $missing = [];
    foreach ($commits as $c) {
        $watched = array_filter($c['files'], 'bcn_is_watched');
        if (!$watched) {
            continue;
        }
        if (stripos($c['subject'], '[no-changelog]') !== false) {
            continue;
        }
        $pr = preg_match('/\(#(\d+)\)\s*$/', $c['subject'], $m) ? $m[1] : '';
        if ($pr !== '' && preg_match('/#' . $pr . '\b/', $section)) {
            continue;
        }
        $missing[] = $c;
    }
    return $missing;
}

function bcn_git(string $root, array $args): string
{
    $proc = proc_open(array_merge(['git', '-C', $root], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return '';
    }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return $out;
}

function bcn_commits_since(string $root, string $ref): array
{
    $raw = bcn_git($root, ['log', '--no-merges', '--name-only', '--format=%x01%H%x02%s', $ref . '..HEAD']);
    $commits = [];
    foreach (array_filter(explode("\x01", $raw)) as $block) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), 'strlen'));
        if (!$lines) {
            continue;
        }
        [$hash, $subject] = array_pad(explode("\x02", array_shift($lines), 2), 2, '');
        $commits[] = ['hash' => $hash, 'subject' => $subject, 'files' => $lines];
    }
    return $commits;
}

function bcn_default_since(string $root, string $version): string
{
    $prev = '';
    $readme = (string) file_get_contents($root . '/readme.txt');
    if (preg_match_all('/^= (\d+\.\d+\.\d+) =\s*$/m', $readme, $m)) {
        foreach ($m[1] as $v) {
            if ($v !== $version) {
                $prev = $v;
                break;
            }
        }
    }
    if ($prev === '') {
        return '';
    }
    $hash = trim(bcn_git($root, ['log', '--format=%H', '-S', "define('BYNLI_CONNECT_VERSION', '$prev')", '--', 'bynli-connect.php']));
    $lines = array_values(array_filter(explode("\n", $hash)));
    return $lines ? end($lines) : '';
}

function bcn_main(array $argv): int
{
    $root = realpath(__DIR__ . '/..');
    $since = '';
    $strict = false;
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '--since' && isset($argv[$i + 1])) {
            $since = $argv[++$i];
        } elseif ($argv[$i] === '--strict') {
            $strict = true;
        } elseif ($argv[$i] === '--root' && isset($argv[$i + 1])) {
            $root = realpath($argv[++$i]);
        } else {
            fwrite(STDERR, "usage: php tools/check-release.php [--since <ref>] [--strict] [--root <dir>]\n");
            return 2;
        }
    }
    $plugin = (string) file_get_contents($root . '/bynli-connect.php');
    $readme = (string) file_get_contents($root . '/readme.txt');
    $problems = bcn_version_problems($plugin, $readme);
    foreach ($problems as $p) {
        echo "ERROR $p\n";
    }
    $version = preg_match("/define\\(\\s*'BYNLI_CONNECT_VERSION'\\s*,\\s*'([^']+)'/", $plugin, $m) ? $m[1] : '';
    $section = $version !== '' ? (string) bcn_changelog_section($readme, $version) : '';
    if ($since === '' && $version !== '') {
        $since = bcn_default_since($root, $version);
    }
    $warn = 0;
    if ($since === '') {
        echo "WARN could not determine the previous release commit; pass --since <ref>\n";
        $warn++;
    } else {
        foreach (bcn_undisclosed(bcn_commits_since($root, $since), $section) as $c) {
            echo 'UNDISCLOSED ' . substr($c['hash'], 0, 8) . ' ' . $c['subject'] . "\n";
            $warn++;
        }
    }
    if ($problems) {
        return 1;
    }
    if ($warn && $strict) {
        return 1;
    }
    echo $warn ? "release check: $warn warning(s)\n" : "release check: ok\n";
    return 0;
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(bcn_main($argv));
}
