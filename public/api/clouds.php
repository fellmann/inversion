<?php
// GET ?lat=&lon=  (Standard: config.php) -> 3D-Wolken-Zwischenformat für den Ort
$cfg = require __DIR__ . '/../../src/config.php';
require __DIR__ . '/../../src/Geo.php';
require __DIR__ . '/../../src/CloudService.php';

header('Content-Type: application/json');
try {
    $cfg = Geo::fromRequest($cfg, $_GET);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['state' => 'error', 'message' => $e->getMessage()]);
    exit;
}

$svc = new CloudService($cfg);
$svc->refreshAsync();
$meta = $svc->latest();
if ($meta === null) {
    http_response_code(202);
    header('Retry-After: 3');
    echo json_encode($svc->status() + ['state' => 'building']);
    exit;
}
try {
    $f = $svc->locationFile($meta);
} catch (OutOfDomain $e) {
    http_response_code(422);
    echo json_encode(['state' => 'error', 'message' => $e->getMessage()]);
    exit;
}
header('Cache-Control: no-cache');
header('ETag: "' . basename($f) . '"');
ob_start('ob_gzhandler');
readfile($f);
