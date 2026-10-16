<?php
// Nettoyeur HTML de l'encart : php tests/html-sanitizer.php
require dirname(__DIR__) . '/src/HtmlSanitizer.php';
$cases = [
 'gras + lien' => ['<p>Photographe <b>Lyon</b> <a href="https://ex.fr/a?b=1&c=2">site</a></p>', '<p>Photographe <strong>Lyon</strong> <a href="https://ex.fr/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer sponsored">site</a></p>'],
 'titre div chrome' => ['<h1>Titre</h1><div>ligne</div>', '<h1>Titre</h1><p>ligne</p>'],
 'script supprimé' => ['<p>a<script>alert(1)</script>b</p>', '<p>ab</p>'],
 'onerror retiré' => ['<img src=x onerror=alert(1)><p onclick="x()">t</p>', '<p>t</p>'],
 'javascript: déballé' => ['<a href="javascript:alert(1)">x</a>', 'x'],
 'JaVaScRiPt espaces' => ['<a href=" JaVaScRiPt:alert(1)">x</a>', 'x'],
 'data: déballé' => ['<a href="data:text/html,<script>">x</a>', 'x'],
 'protocole relatif' => ['<a href="//evil.com">x</a>', 'x'],
 'style supprimé' => ['<style>body{}</style><p style="color:red">t</p>', '<p>t</p>'],
 'svg supprimé' => ['<svg><script>alert(1)</script></svg>ok', 'ok'],
 'bloc dans un bloc (fermé par le parseur)' => ['<p>a<h1>b</h1></p>', '<p>a</p><h1>b</h1>'],
 'lien imbriqué' => ['<a href="https://a.fr">x<a href="https://b.fr">y</a></a>', '<a href="https://a.fr" target="_blank" rel="noopener noreferrer sponsored">x</a><a href="https://b.fr" target="_blank" rel="noopener noreferrer sponsored">y</a>'],
 'utf8' => ['<p>Hélène Pêche — été</p>', '<p>Hélène Pêche — été</p>'],
 'entités' => ['<p>&lt;script&gt;</p>', '<p>&lt;script&gt;</p>'],
 'commentaire' => ['<!-- x --><p>t</p>', '<p>t</p>'],
 'iframe' => ['<iframe src="https://x"></iframe>t', 't'],
 'span déballé' => ['<p><span style="x">t</span></p>', '<p>t</p>'],
 'vide' => ['   ', ''],
 'br' => ['<p>a<br>b</p>', '<p>a<br>b</p>'],
 'identifiants dans l\'URL' => ['<a href="https://banque.fr@pirate.com">x</a>', 'x'],
 'mutation noscript' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>', ''],
];
$fail = 0;
foreach ($cases as $label => [$in, $expected]) {
  $out = HtmlSanitizer::sanitize($in);
  $ok = $out === $expected;
  if (!$ok) $fail++;
  echo ($ok ? 'PASS ' : 'FAIL ') . "$label" . ($ok ? '' : "\n   attendu: $expected\n   obtenu : $out") . "\n";
}
exit($fail ? 1 : 0);
