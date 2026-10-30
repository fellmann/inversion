<?php
// Lokale Näherung: Meter (Ost x, Nord y) relativ zum Mittelpunkt <-> Lat/Lon
class Geo
{
    const M_PER_DEG_LAT = 111132.0;

    public static function toLatLon(array $cfg, float $x, float $y): array
    {
        $lat = $cfg['lat'] + $y / self::M_PER_DEG_LAT;
        $lon = $cfg['lon'] + $x / (111320.0 * cos(deg2rad($cfg['lat'])));
        return [$lat, $lon];
    }

    /** Übernimmt ?lat=&lon= aus der Anfrage in die Konfiguration (Standard: config.php). */
    public static function fromRequest(array $cfg, array $q): array
    {
        foreach (['lat', 'lon'] as $k) {
            if (isset($q[$k]) && !is_numeric($q[$k])) {
                throw new InvalidArgumentException('Ungültige Koordinaten');
            }
        }
        $lat = isset($q['lat']) ? (float)$q['lat'] : $cfg['lat'];
        $lon = isset($q['lon']) ? (float)$q['lon'] : $cfg['lon'];
        if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 85 || abs($lon) > 180) {
            throw new InvalidArgumentException('Ungültige Koordinaten');
        }
        $cfg['lat'] = round($lat, 3); // ~100 m: Cache-Schlüssel
        $cfg['lon'] = round($lon, 3);
        return $cfg;
    }
}
