<?php
require_once __DIR__ . '/Png.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Geo.php';

// Geländemodell aus AWS-Terrarium-Kacheln (SRTM/Copernicus etc.) auf regelmäßiges Gitter resampled.
class Terrain
{
    public static function build(array $cfg): array
    {
        ini_set('memory_limit', '1024M');
        $z = $cfg['dem_zoom'];
        $n = 2 ** $z;
        $res = $cfg['terrain_res'];
        $hs = $cfg['half_size'];
        $steps = intdiv(2 * $hs, $res) + 1;

        // Tile-Pixelkoordinaten (global, float) für Lat/Lon
        $pix = function (float $lat, float $lon) use ($n): array {
            $x = ($lon + 180) / 360 * $n * 256;
            $y = (1 - asinh(tan(deg2rad($lat))) / M_PI) / 2 * $n * 256;
            return [$x, $y];
        };
        [$latS, $lonW] = Geo::toLatLon($cfg, -$hs, -$hs);
        [$latN, $lonE] = Geo::toLatLon($cfg, $hs, $hs);
        [$px0, $py1] = $pix($latS, $lonW);
        [$px1, $py0] = $pix($latN, $lonE);
        $tx0 = (int)floor($px0 / 256) ; $tx1 = (int)floor($px1 / 256) ;
        $ty0 = (int)floor($py0 / 256) ; $ty1 = (int)floor($py1 / 256) ;
        $urls = [];
        $keys = [];
        for ($ty = $ty0; $ty <= $ty1; $ty++) {
            for ($tx = $tx0; $tx <= $tx1; $tx++) {
                $urls[] = sprintf($cfg['dem_url'], $z, $tx, $ty);
                $keys[] = [$tx, $ty];
            }
        }
        $bodies = Http::multi($urls, 6);
        $tiles = [];
        foreach ($bodies as $i => $b) {
            if ($b === null) {
                throw new RuntimeException('DEM-Kachel nicht ladbar: ' . $urls[$i]);
            }
            $png = Png::decode($b);
            $h = [];
            foreach ($png['rows'] as $y => $row) {
                $ch = $png['ch'];
                for ($x = 0; $x < $png['w']; $x++) {
                    $o = $x * $ch;
                    $h[$y * 256 + $x] = $row[$o + 1] * 256 + $row[$o + 2] + $row[$o + 3] / 256 - 32768;
                }
            }
            $tiles[$keys[$i][0] . ',' . $keys[$i][1]] = $h;
        }
        $at = function (int $gx, int $gy) use ($tiles): float {
            $t = $tiles[intdiv($gx, 256) . ',' . intdiv($gy, 256)] ?? null;
            if ($t === null) {
                return 0.0;
            }
            return $t[($gy % 256) * 256 + ($gx % 256)];
        };

        // Wasserflächen: im DEM exakt flach (Seen, große Flüsse, Meer). Prüft ±2 DEM-Pixel (~±50 m) um den Gitterpunkt.
        $isWater = function (int $cx, int $cy) use ($at): bool {
            $min = INF;
            $max = -INF;
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $v = $at($cx + $dx, $cy + $dy);
                    $min = min($min, $v);
                    $max = max($max, $v);
                }
            }
            return $max - $min < 0.02;
        };

        $heights = [];
        $water = '';
        for ($j = 0; $j < $steps; $j++) {
            for ($i = 0; $i < $steps; $i++) {
                [$lat, $lon] = Geo::toLatLon($cfg, -$hs + $i * $res, -$hs + $j * $res);
                [$fx, $fy] = $pix($lat, $lon);
                $water .= $isWater((int)floor($fx), (int)floor($fy)) ? '1' : '0';
                $fx -= 0.5;
                $fy -= 0.5;
                $x0 = (int)floor($fx);
                $y0 = (int)floor($fy);
                $ax = $fx - $x0;
                $ay = $fy - $y0;
                $v = $at($x0, $y0) * (1 - $ax) * (1 - $ay) + $at($x0 + 1, $y0) * $ax * (1 - $ay)
                    + $at($x0, $y0 + 1) * (1 - $ax) * $ay + $at($x0 + 1, $y0 + 1) * $ax * $ay;
                $heights[] = (int)round($v);
            }
        }
        return [
            'source' => 'Terrarium DEM (AWS Open Data)',
            'center' => ['lat' => $cfg['lat'], 'lon' => $cfg['lon']],
            'res' => $res, 'nx' => $steps, 'ny' => $steps,
            'x0' => -$hs, 'y0' => -$hs,
            // Zeilenweise von Süd nach Nord, innerhalb der Zeile von West nach Ost; Meter ü. NN
            'heights' => $heights,
            // Wasserflächen: gleiche Reihenfolge wie heights, '1' = Wasser
            'water' => $water,
        ];
    }

    /** Gelände für den Ort in $cfg (lat/lon), pro Ort dauerhaft gecacht (max_locations). */
    public static function get(array $cfg): array
    {
        $dir = $cfg['cache_dir'] . '/terrain';
        $f = sprintf('%s/%.3f_%.3f.json', $dir, $cfg['lat'], $cfg['lon']);
        if (is_file($f)) {
            $cached = json_decode(file_get_contents($f), true);
            if (isset($cached['water'])) { // ältere Cache-Dateien ohne Wasserflächen neu berechnen
                touch($f);
                return $cached;
            }
        }
        @mkdir($dir, 0777, true);
        $t = self::build($cfg);
        file_put_contents("$f.tmp", json_encode($t));
        rename("$f.tmp", $f);
        require_once __DIR__ . '/CloudService.php';
        CloudService::prune($dir, $cfg['max_locations']);
        return $t;
    }
}
