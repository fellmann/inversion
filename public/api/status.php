<?php
$cfg = require __DIR__ . '/../../src/config.php';
require __DIR__ . '/../../src/CloudService.php';
$svc = new CloudService($cfg);
$svc->refreshAsync(); // gedrosselte Prüfung auf neuen Modelllauf (max. alle 10 min)
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode($svc->status());
