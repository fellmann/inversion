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
}
