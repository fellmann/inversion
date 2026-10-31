<?php
require_once __DIR__ . '/Grib2.php';
require_once __DIR__ . '/Geo.php';

class OutOfDomain extends RuntimeException
{
}

// Berechnet aus dem zwischengespeicherten ICON-D2-Lauf das 3D-Wolken-Zwischenformat für einen Ort.
class LocationBuilder
{
    /** @param array $meta Inhalt von meta_<run>.json */
    public static function build(array $cfg, array $meta): array
    {
        ini_set('memory_limit', '512M');
        $dir = $cfg['cache_dir'];
        $run = $meta['run'];
        $g = json_decode(file_get_contents("$dir/grid.json"), true)['grid'];
        $bitmap = file_get_contents("$dir/bitmap.bin");
        $hmeta = json_decode(file_get_contents("$dir/hhl/meta.json"), true);
        $hs = $cfg['half_size'];

        // Fenster im Modellgitter um das Gebiet (+1 Zelle Rand für die Interpolation)
        [$latS, $lonW] = Geo::toLatLon($cfg, -$hs, -$hs);
        [$latN, $lonE] = Geo::toLatLon($cfg, $hs, $hs);
        $rel = fn(float $lon) => fmod($lon - $g['lon0'] + 720, 360);
        $j0 = (int)floor(($latS - $g['lat0']) / $g['dlat']) - 1;
        $j1 = (int)ceil(($latN - $g['lat0']) / $g['dlat']) + 1;
        $i0 = (int)floor($rel($lonW) / $g['dlon']) - 1;
        $i1 = (int)ceil($rel($lonE) / $g['dlon']) + 1;
        if ($j0 < 0 || $j1 >= $g['nj'] || $i0 < 0 || $i1 >= $g['ni']) {
            throw new OutOfDomain('Der Ort liegt außerhalb des ICON-D2-Modellgebiets (Deutschland und Umgebung).');
        }
        $win = ['lat0' => $g['lat0'] + $j0 * $g['dlat'], 'lon0' => fmod($g['lon0'] + $i0 * $g['dlon'], 360),
            'dlat' => $g['dlat'], 'dlon' => $g['dlon']];

        // Index der Fensterpunkte im gepackten Feld (Bitmap = Gültigkeitsmaske des Modellgebiets)
        $map = [];
        foreach (range($j0, $j1) as $jj => $j) {
            $k = Grib2::rank($bitmap, $j * $g['ni'] + $i0);
            foreach (range($i0, $i1) as $ii => $i) {
                if (!Grib2::isSet($bitmap, $j * $g['ni'] + $i)) {
                    throw new OutOfDomain('Der Ort liegt am Rand oder außerhalb des ICON-D2-Modellgebiets.');
                }
                $map[$jj][$ii] = $k++;
            }
        }

        // Halbflächenhöhen im Fenster
        $hl = [];
        for ($hk = 1; $hk <= 66; $hk++) {
            $m = $hmeta['levels'][$hk];
            if ($m['const']) {
                $c = $m['R'] / 10 ** $m['D'];
                $hl[$hk] = self::pick($map, fn() => $c);
                continue;
            }
            $b = gzinflate(file_get_contents("$dir/hhl/L$hk.gz"));
            $hl[$hk] = self::pick($map, fn($p) => ($m['R'] + ((ord($b[$p * 2]) << 8) | ord($b[$p * 2 + 1])) * 2 ** $m['E']) / 10 ** $m['D']);
        }

        // Schichten: von unten nach oben, soweit im Fenster unterhalb max_height
        $ks = [];
        foreach ($meta['ks'] as $k) {
            if (self::minOf($hl[$k + 1]) < $cfg['max_height']) {
                $ks[] = $k;
            }
        }
        $nz = count($ks);

        // Zielgitter (Zellmitten)
        $n = intdiv(2 * $hs, $cfg['cell']);
        $cols = [];
        for ($j = 0; $j < $n; $j++) {
            for ($i = 0; $i < $n; $i++) {
                $cols[] = Geo::toLatLon($cfg, -$hs + ($i + 0.5) * $cfg['cell'], -$hs + ($j + 0.5) * $cfg['cell']);
            }
        }
        $heights = [];
        foreach (array_merge([66], $ks) as $hk) { // 66 = Boden, dann Oberkante der Schichten
            foreach ($cols as [$lat, $lon]) {
                $heights[] = (int)round(self::sample($hl[$hk], $win, $lat, $lon));
            }
        }

        $times = [];
        $clc = [];
        $t0 = gmmktime((int)substr($run, 8, 2), 0, 0, (int)substr($run, 4, 2), (int)substr($run, 6, 2), (int)substr($run, 0, 4));
        for ($t = 0; $t <= $meta['steps']; $t++) {
            $times[] = gmdate('Y-m-d\TH:i:s\Z', $t0 + $t * 3600);
            $flat = [];
            foreach ($ks as $k) {
                $b = gzinflate(file_get_contents(sprintf('%s/run_%s/t%03d_k%02d.gz', $dir, $run, $t, $k)));
                $w = self::pick($map, fn($p) => ord($b[$p]));
                foreach ($cols as [$lat, $lon]) {
                    $flat[] = (int)round(max(0, min(100, self::sample($w, $win, $lat, $lon))));
                }
            }
            $clc[] = $flat;
        }

        $out = [
            'format' => 'cloud3d/1',
            'model' => 'ICON-D2',
            'source' => 'DWD Open Data, Variable clc (Wolkenbedeckungsgrad je Modellschicht, %)',
            'run' => gmdate('Y-m-d\TH:i:s\Z', $t0),
            'generated' => gmdate('Y-m-d\TH:i:s\Z'),
            'center' => ['lat' => $cfg['lat'], 'lon' => $cfg['lon']],
            'half_size' => $hs, 'cell' => $cfg['cell'],
            'nx' => $n, 'ny' => $n, 'nz' => $nz,
            'times' => $times,
            // heights: (nz+1) Halbflächen von unten nach oben, je ny*nx (Zeile j von Süd nach Nord, i von West nach Ost), m ü. NN
            // Schicht l liegt zwischen heights[l] und heights[l+1]
            'heights' => $heights,
            // clc[t]: nz*ny*nx Werte (0..100 %), Index = (l*ny + j)*nx + i
            'clc' => $clc,
        ];
        if (!empty($meta['surface'])) {
            // surface: 2D-Felder je Zeitschritt, je ny*nx Werte (Index j*nx+i)
            //   t2m (°C, 2 m über Modellgelände), precip (mm in der vorangegangenen Stunde, Schritt 0 = 0),
            //   snow (% des Niederschlags als Schnee)
            $out['surface'] = self::surface($dir, $run, $meta['steps'], $map, $win, $cols);
        }
        return $out;
    }

    /** Fensterwerte eines 2D-Felds aus der Cachedatei (Kopfzeile [R,E,D,bits] + 16-Bit-Rohdaten). */
    private static function surfaceField(string $f, array $map): array
    {
        [$hdr, $b] = explode("\n", gzinflate(file_get_contents($f)), 2);
        [$R, $E, $D, $bits] = json_decode($hdr);
        if ($bits === 0) {
            $c = $R / 10 ** $D;
            return self::pick($map, fn() => $c);
        }
        return self::pick($map, fn($p) => ($R + ((ord($b[$p * 2]) << 8) | ord($b[$p * 2 + 1])) * 2 ** $E) / 10 ** $D);
    }

    private static function surface(string $dir, string $run, int $steps, array $map, array $win, array $cols): array
    {
        $f = fn(string $v, int $t) => self::surfaceField(sprintf('%s/run_%s/s_%s_t%03d.gz', $dir, $run, $v, $t), $map);
        $diff = fn(array $a, array $b) => array_map(fn($ra, $rb) => array_map(fn($x, $y) => max(0.0, $x - $y), $ra, $rb), $a, $b);
        $res = ['t2m' => [], 'precip' => [], 'snow' => []];
        $prevTot = $prevSnow = null;
        for ($t = 0; $t <= $steps; $t++) {
            $T = $f('t_2m', $t);
            $tot = $f('tot_prec', $t);
            $snow = $f('snow_gsp', $t);
            $rate = $prevTot === null ? self::pick($map, fn() => 0.0) : $diff($tot, $prevTot);
            $srate = $prevSnow === null ? $rate : $diff($snow, $prevSnow);
            $prevTot = $tot;
            $prevSnow = $snow;
            $a = $b = $c = [];
            foreach ($cols as [$lat, $lon]) {
                $r = self::sample($rate, $win, $lat, $lon);
                $sr = min($r, self::sample($srate, $win, $lat, $lon));
                $a[] = round(self::sample($T, $win, $lat, $lon) - 273.15, 1);
                $b[] = round($r, 2);
                $c[] = $r > 0.005 ? (int)round(100 * $sr / $r) : 0;
            }
            $res['t2m'][] = $a;
            $res['precip'][] = $b;
            $res['snow'][] = $c;
        }
        return $res;
    }

    private static function pick(array $map, callable $f): array
    {
        return array_map(fn($row) => array_map($f, $row), $map);
    }

    private static function minOf(array $w): float
    {
        return min(array_map('min', $w));
    }

    /** Bilinear aus Fenster-Array $w[j][i] an Lat/Lon. */
    private static function sample(array $w, array $win, float $lat, float $lon): float
    {
        $fy = ($lat - $win['lat0']) / $win['dlat'];
        $fx = fmod($lon - $win['lon0'] + 720, 360) / $win['dlon'];
        $ny = count($w);
        $nx = count($w[0]);
        $y0 = max(0, min($ny - 2, (int)floor($fy)));
        $x0 = max(0, min($nx - 2, (int)floor($fx)));
        $ay = min(1, max(0, $fy - $y0));
        $ax = min(1, max(0, $fx - $x0));
        return $w[$y0][$x0] * (1 - $ax) * (1 - $ay) + $w[$y0][$x0 + 1] * $ax * (1 - $ay)
            + $w[$y0 + 1][$x0] * (1 - $ax) * $ay + $w[$y0 + 1][$x0 + 1] * $ax * $ay;
    }
}
