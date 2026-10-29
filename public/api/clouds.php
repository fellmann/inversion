<?php
$cfg = require __DIR__ . '/../../src/config.php';
require __DIR__ . '/../../src/CloudService.php';

$svc = new CloudService($cfg);
$svc->refreshAsync();
$f = $svc->path();
if ($f === null) {
    http_response_code(202);
    header('Content-Type: application/json');
    header('Retry-After: 3');
    echo json_encode($svc->status() + ['state' => 'building']);
    exit;
}
header('Content-Type: application/json');
header('Cache-Control: no-cache');
header('ETag: "' . basename($f) . '"');
ob_start('ob_gzhandler');
readfile($f);
