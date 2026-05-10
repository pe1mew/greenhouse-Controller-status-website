<?php
require __DIR__ . '/../config.php';
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
header('X-Robots-Tag: noindex, nofollow');
@header_remove('X-Powered-By');

$files = [];
if (is_dir(GH_LOG_DIR)) {
    foreach (glob(GH_LOG_DIR . '/*.{log,txt}', GLOB_BRACE) as $f) {
        if (is_file($f)) {
            $files[] = [
                'name'  => basename($f),
                'size'  => filesize($f),
                'mtime' => filemtime($f),
            ];
        }
    }
    usort($files, function ($a, $b) { return $b['mtime'] - $a['mtime']; });
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Logs · Greenhouse status</title>
<link rel="stylesheet" href="../assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?: time() ?>">
</head>
<body class="dashboard">

<div class="tiles">

  <section class="tile" style="grid-column: 1 / -1">
    <h3 class="tile-title">Logs</h3>
    <?php if (empty($files)): ?>
      <p class="muted">No log files have been uploaded yet.</p>
    <?php else: ?>
      <ul class="logs-list">
        <?php foreach ($files as $f):
            $url  = 'logs/' . rawurlencode($f['name']);
            $kb   = number_format($f['size'] / 1024, 1);
            $when = date('Y-m-d H:i:s', $f['mtime']);
        ?>
          <li>
            <span class="log-info">
              <?= htmlspecialchars($f['name']) ?>
              <small><?= $kb ?> KB · <?= htmlspecialchars($when) ?></small>
            </span>
            <a class="btn-download" href="<?= htmlspecialchars($url, ENT_QUOTES) ?>" download>Download</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

</div>

<footer>
  <span>Greenhouse Controller &nbsp;&bull;&nbsp; logs</span>
  <a href="https://github.com/pe1mew/-greenhouse-Controller-status-website"
     target="_blank" rel="noopener noreferrer">GitHub &nearr;</a>
</footer>

</body>
</html>
