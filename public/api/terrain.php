<?php
// GET ?lat=&lon=  (Standard: config.php) -> Gelände 100 m um den Ort
$cfg = require __DIR__ . '/../../src/config.php';
require __DIR__ . '/../../src/Geo.php';
require __DIR__ . '/../../src/Terrain.php';

header('Content-Type: application/json');
try {
    $cfg = Geo::fromRequest($cfg, $_GET);
    $terrain = Terrain::get($cfg);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['state' => 'error', 'message' => $e->getMessage()]);
    exit;
} catch (RuntimeException $e) {
    http_response_code(502);
    echo json_encode(['state' => 'error', 'message' => $e->getMessage()]);
    exit;
}
header('Cache-Control: public, max-age=86400');
ob_start('ob_gzhandler');
echo json_encode($terrain);
