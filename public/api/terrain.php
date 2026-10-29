<?php
$cfg = require __DIR__ . '/../../src/config.php';
require __DIR__ . '/../../src/Terrain.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=86400');
ob_start('ob_gzhandler');
echo json_encode(Terrain::get($cfg));
