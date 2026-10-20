<?php
require_once BYNLI_CONNECT_PLUGIN_DIR . 'tools/check-release.php';

$plugin = "<?php\n/**\n * Version:           1.2.3\n */\ndefine('BYNLI_CONNECT_VERSION', '1.2.3');\n";
$readme = "Stable tag:        1.2.3\n\n== Changelog ==\n\n= 1.2.3 =\n* **Added:** A thing. (#10)\n* **Fixed:** Another. (#11)\n\n= 1.2.2 =\n* **Added:** Old. (#9)\n";

t_eq(bcn_version_problems($plugin, $readme), [], 'consistent release has no problems');
t_eq(count(bcn_version_problems(str_replace("Version:           1.2.3", "Version:           1.2.4", $plugin), $readme)), 1, 'header drift detected');
t_eq(count(bcn_version_problems($plugin, str_replace('Stable tag:        1.2.3', 'Stable tag:        1.2.2', $readme))), 1, 'stable tag drift detected');
t_eq(count(bcn_version_problems($plugin, str_replace('= 1.2.3 =', '= 1.2.9 =', $readme))), 1, 'missing changelog entry detected');
t_eq(count(bcn_version_problems("<?php\n", $readme)), 1, 'unreadable version reported');

$section = bcn_changelog_section(str_replace("\n", "\r\n", $readme), '1.2.3');
t_true(strpos((string) $section, '#10') !== false && strpos((string) $section, '#9') === false, 'section is bounded and CRLF safe');
t_eq(bcn_changelog_section($readme, '0.0.1'), null, 'unknown version has no section');

$commits = [
    ['hash' => 'a1', 'subject' => 'Add thing (#10)', 'files' => ['includes/class-x.php']],
    ['hash' => 'a2', 'subject' => 'Silent remote write feature (#12)', 'files' => ['includes/class-inbound.php', 'readme.txt']],
    ['hash' => 'a3', 'subject' => 'Tidy readme (#13)', 'files' => ['readme.txt']],
    ['hash' => 'a4', 'subject' => 'Refactor internals (#14) [no-changelog]', 'files' => ['assets/admin.js']],
    ['hash' => 'a5', 'subject' => 'Direct commit without a PR number', 'files' => ['blocks/b.php']],
    ['hash' => 'a6', 'subject' => 'Prefix collision (#1)', 'files' => ['assets/admin.css']],
    ['hash' => 'a7', 'subject' => 'Test only (#15)', 'files' => ['tests/test-x.php']],
];
$missing = array_column(bcn_undisclosed($commits, (string) $section), 'hash');
t_eq($missing, ['a2', 'a5', 'a6'], 'undisclosed user-visible commits are flagged, covered and non-shipping ones are not');

$tmp = sys_get_temp_dir() . '/bcn-rel-' . getmypid();
mkdir($tmp);
file_put_contents($tmp . '/f.php', "x");
$g = function (string $a) use ($tmp) {
    return shell_exec('git -C ' . escapeshellarg($tmp) . ' -c user.email=t@t -c user.name=t -c commit.gpgsign=false ' . $a . ' 2>&1');
};
$g('init -q');
mkdir($tmp . '/includes');
file_put_contents($tmp . '/includes/a.php', 'a');
$g('add -A');
$g('commit -q -m "first (#1)"');
$base = trim((string) $g('rev-parse HEAD'));
file_put_contents($tmp . '/includes/a.php', 'b');
$g('commit -qam "change a (#2)"');
file_put_contents($tmp . '/readme.txt', 'r');
$g('add -A');
$g('commit -q -m "readme only (#3)"');
$found = bcn_commits_since($tmp, $base);
t_eq(count($found), 2, 'git log parsing returns commits since the ref');
t_eq($found[1]['files'], ['includes/a.php'], 'files parsed per commit');
t_eq(array_column(bcn_undisclosed($found, ''), 'subject'), ['change a (#2)'], 'end to end against a real git history');
foreach (glob($tmp . '/*') as $f) {
    is_dir($f) ? null : unlink($f);
}
