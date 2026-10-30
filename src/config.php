<?php
// Zentrale Konfiguration
return [
    // Standard-Mittelpunkt (Freiburg, Münster); per ?lat=&lon= überschreibbar
    'lat' => 47.9959,
    'lon' => 7.8522,
    // Halbe Kantenlänge des Gebiets in Metern (20 x 20 km)
    'half_size' => 10000,
    // Horizontale Zellgröße des Zwischenformats in Metern
    'cell' => 1000,
    // Terrain-Auflösung in Metern
    'terrain_res' => 100,
    // Vorhersagestunden 0..N
    'forecast_hours' => 24,
    // Wolken oberhalb dieser Höhe (m ü. NN) werden ignoriert
    'max_height' => 12000,
    'dwd_base' => 'https://opendata.dwd.de/weather/nwp/icon-d2/grib',
    'dem_url' => 'https://s3.amazonaws.com/elevation-tiles-prod/terrarium/%d/%d/%d.png',
    'dem_zoom' => 12,
    'cache_dir' => getenv('INVERSION_CACHE') ?: __DIR__ . '/../cache',
    // Zwischengespeicherte Orte (Terrain, Wolken), älteste werden gelöscht
    'max_locations' => 40,
    // Mindestabstand zwischen Prüfungen auf neuen Modelllauf (Sekunden)
    'check_interval' => 600,
    'parallel' => 12,
];
