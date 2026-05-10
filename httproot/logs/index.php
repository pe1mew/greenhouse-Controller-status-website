<?php
// Backward-compatibility shim: the logs page used to live at /<prefix>/logs/.
// It moved to /<prefix>/log/. Redirect any bookmarked old URLs.
header('Location: ../log/', true, 301);
exit;
