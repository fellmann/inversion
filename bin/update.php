#!/usr/bin/env php
<?php
// Lädt/aktualisiert den ICON-D2-Cache. Aufruf: php bin/update.php [--force]  (z. B. per Cron alle 15 min)
$cfg = require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/CloudService.php';
$svc = new CloudService($cfg);
$svc->refresh(in_array('--force', $argv, true));
$s = $svc->status();
echo json_encode($s), "\n";
exit($s['state'] === 'error' ? 1 : 0);
