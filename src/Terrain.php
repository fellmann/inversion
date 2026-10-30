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
        // Kacheln zu einem Mosaik zusammensetzen: $M[y][x] (Pixelkoordinaten relativ zur oberen linken Kachel)
        $M = [];
        for ($ty = $ty0; $ty <= $ty1; $ty++) {
            for ($r = 0; $r < 256; $r++) {
                $row = [];
                for ($tx = $tx0; $tx <= $tx1; $tx++) {
                    $t = $tiles["$tx,$ty"] ?? null;
                    $row = array_merge($row, $t === null ? array_fill(0, 256, 0.0) : array_slice($t, $r * 256, 256));
                }
                $M[($ty - $ty0) * 256 + $r] = $row;
            }
        }
        $ox = $tx0 * 256;
        $oy = $ty0 * 256;

        // Statt nur einen Punkt abzutasten, wird jede Gitterzelle (res x res) flächig ausgewertet:
        // Mittel/Minimum/Maximum der DEM-Pixel in der Zelle und Anteil der Wasserpixel.
        // Wasser: Pixel, die zu ihren 4 Nachbarn exakt höhengleich sind (Seen, breite Flüsse, Meer sind im DEM flach).
        $half = max(1, (int)round($res / 2 / ($cfg['dem_px'] ?? 25.0))); // DEM-Pixel je halber Zelle (~2)
        $mean = [];
        $min = [];
        $max = [];
        $waterFrac = [];
        for ($j = 0; $j < $steps; $j++) {
            for ($i = 0; $i < $steps; $i++) {
                [$lat, $lon] = Geo::toLatLon($cfg, -$hs + $i * $res, -$hs + $j * $res);
                [$fx, $fy] = $pix($lat, $lon);
                $cx = (int)round($fx) - $ox;
                $cy = (int)round($fy) - $oy;
                $sum = 0.0;
                $lo = INF;
                $hi = -INF;
                $n = 0;
                $w = 0;
                for ($y = $cy - $half; $y < $cy + $half; $y++) {
                    for ($x = $cx - $half; $x < $cx + $half; $x++) {
                        $v = $M[$y][$x] ?? null;
                        if ($v === null) {
                            continue;
                        }
                        $sum += $v;
                        $lo = min($lo, $v);
                        $hi = max($hi, $v);
                        $n++;
                        if (abs($v - ($M[$y][$x - 1] ?? INF)) < 0.02 && abs($v - ($M[$y][$x + 1] ?? INF)) < 0.02
                            && abs($v - ($M[$y - 1][$x] ?? INF)) < 0.02 && abs($v - ($M[$y + 1][$x] ?? INF)) < 0.02) {
                            $w++;
                        }
                    }
                }
                $k = $j * $steps + $i;
                $mean[$k] = $n ? $sum / $n : 0.0;
                $min[$k] = $n ? $lo : 0.0;
                $max[$k] = $n ? $hi : 0.0;
                $waterFrac[$k] = $n ? $w / $n : 0.0;
            }
        }

        // Spitzen und Täler erhalten: an lokalen Maxima (Mittel über dem der 8 Nachbarn) Richtung Zellmaximum,
        // an lokalen Minima Richtung Zellminimum. An gleichmäßigen Hängen (Differenz ~0) bleibt es beim Mittelwert.
        $heights = [];
        $water = '';
        for ($j = 0; $j < $steps; $j++) {
            for ($i = 0; $i < $steps; $i++) {
                $k = $j * $steps + $i;
                $nb = 0.0;
                $c = 0;
                for ($dj = -1; $dj <= 1; $dj++) {
                    for ($di = -1; $di <= 1; $di++) {
                        if (($di || $dj) && $j + $dj >= 0 && $j + $dj < $steps && $i + $di >= 0 && $i + $di < $steps) {
                            $nb += $mean[($j + $dj) * $steps + $i + $di];
                            $c++;
                        }
                    }
                }
                $d = $mean[$k] - $nb / $c;
                $t = max(-1.0, min(1.0, $d / 5.0)); // 5 m Überhöhung gegenüber den Nachbarn = voller Effekt
                $h = $mean[$k] + ($t > 0 ? $t * 0.5 * ($max[$k] - $mean[$k]) : $t * 0.5 * ($mean[$k] - $min[$k]));
                $heights[] = (int)round($h);
                $water .= (string)(int)round(min(1.0, $waterFrac[$k]) * 9); // 0..9 = Wasseranteil der Zelle
            }
        }
        return [
            'source' => 'Terrarium DEM (AWS Open Data)',
            'center' => ['lat' => $cfg['lat'], 'lon' => $cfg['lon']],
            'res' => $res, 'nx' => $steps, 'ny' => $steps,
            'x0' => -$hs, 'y0' => -$hs,
            // Zeilenweise von Süd nach Nord, innerhalb der Zeile von West nach Ost; Meter ü. NN
            'heights' => $heights,
            // Wasseranteil je Gitterpunkt (Zelle res x res), gleiche Reihenfolge wie heights: Ziffer 0..9 = 0..100 %
            'water' => $water,
            'water_v' => 2,
        ];
    }

    /** Gelände für den Ort in $cfg (lat/lon), pro Ort dauerhaft gecacht (max_locations). */
    public static function get(array $cfg): array
    {
        $dir = $cfg['cache_dir'] . '/terrain';
        $f = sprintf('%s/%.3f_%.3f.json', $dir, $cfg['lat'], $cfg['lon']);
        if (is_file($f)) {
            $cached = json_decode(file_get_contents($f), true);
            if (($cached['water_v'] ?? 0) >= 2) { // ältere Cache-Dateien (andere Abtastung) neu berechnen
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
