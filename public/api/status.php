<?php
$cfg = require __DIR__ . '/../../src/config.php';
require __DIR__ . '/../../src/CloudService.php';
header('Content-Type: application/json');
echo json_encode((new CloudService($cfg))->status());
