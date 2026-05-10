<?php
// Suppresses Apache's default directory listing on hosts where AllowOverride is
// not set (so the .htaccess in this directory is ignored). Anyone hitting
// /<prefix>/log/logs/ gets sent to the real logs page instead.
header('X-Robots-Tag: noindex, nofollow');
header('Location: ../', true, 301);
exit;
