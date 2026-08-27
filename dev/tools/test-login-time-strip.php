<?php
/*
 * RM2815 — regression test for the 'time' URL parameter accumulation on the
 * login page (crawler trap).
 *
 * Replays the cleanup preg_replace() calls EXTRACTED FROM the patched file, so
 * the test is coupled to the real code rather than to a copy of it.
 *
 * Usage:
 *   php dev/tools/test-login-time-strip.php htdocs/core/tpl/login.tpl.php
 *
 * Exit code 0 if every case passes.
 */
// Extrait les preg_replace de nettoyage de `time` DIRECTEMENT du fichier patché
// (le test est ainsi couplé au vrai code, pas à une copie).
$target = $argv[1] ?? (__DIR__ . '/../../htdocs/core/tpl/login.tpl.php');
$src = file_get_contents($target);
preg_match_all("/^\t\\\$php_self = preg_replace\((.+)\);$/m", $src, $m, PREG_SET_ORDER);
$lines = [];
foreach ($m as $x) { if (strpos($x[1], 'time') !== false || strpos($x[1], '&amp;') !== false) $lines[] = $x[0]; }
// on ne garde que le bloc du captcha (les 4 dernières occurrences)
$lines = array_slice($lines, -4);
if (count($lines) !== 4) { fwrite(STDERR, "bloc introuvable\n"); exit(2); }
$code = implode("\n", $lines);
echo "--- code testé (extrait du fichier) ---\n$code\n\n";

function strip_time($php_self, $code) { eval($code); return $php_self; }

$cases = [
  // [entrée, attendu]
  ['/index.php?mainmenu=home&amp;time=20260812170126&amp;time=20260817185928&time=20260824163541', '/index.php?mainmenu=home'],
  ['/index.php?mainmenu=home',                       '/index.php?mainmenu=home'],
  ['/index.php?time=20260824163541',                 '/index.php'],
  ['/index.php?time=1&amp;mainmenu=home&amp;time=2', '/index.php?mainmenu=home'],
  ['/index.php',                                     '/index.php'],
  ['/index.php?mainmenu=home&amp;entity=2',          '/index.php?mainmenu=home&amp;entity=2'],
];
$ko = 0;
foreach ($cases as [$in, $want]) {
    $got = strip_time($in, $code);
    $ok  = ($got === $want);
    // idempotence : rejouer sur la sortie ne doit rien changer
    $idem = (strip_time($got, $code) === $got);
    if (!$ok || !$idem) $ko++;
    printf("%s %s\n   in       : %s\n   attendu  : %s\n   obtenu   : %s\n   idempotent: %s\n",
        $ok && $idem ? 'OK  ' : 'ECHEC', '', $in, $want, $got, $idem ? 'oui' : 'NON');
}
// cas de non-régression : 202 params comme observés en prod
$long = '/index.php?mainmenu=home' . str_repeat('&amp;time=20260824120000', 202);
$got = strip_time($long, $code);
$ok = ($got === '/index.php?mainmenu=home');
if (!$ok) $ko++;
printf("%s 202 params -> %s\n", $ok ? 'OK  ' : 'ECHEC', $got);

printf("\n%d/%d cas OK\n", count($cases) + 1 - $ko, count($cases) + 1);
exit($ko === 0 ? 0 : 1);
