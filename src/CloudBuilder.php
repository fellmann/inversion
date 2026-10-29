<?php
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Grib2.php';
require_once __DIR__ . '/Geo.php';

// Lädt ICON-D2 (DWD Open Data), schneidet das Gebiet um Freiburg aus und erzeugt das 3D-Wolken-Zwischenformat.
class CloudBuilder
{
    private array $cfg;
    private string $dir;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $this->dir = $cfg['cache_dir'];
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
    }

    // ---------------------------------------------------------------- Läufe / URLs

    private function url(string $run, string $var, int $step, int $level, bool $invariant = false): string
    {
        $hh = substr($run, 8, 2);
        $kind = $invariant ? 'time-invariant' : 'model-level';
        $t = sprintf('%03d', $invariant ? 0 : $step);
        return "{$this->cfg['dwd_base']}/$hh/$var/icon-d2_germany_regular-lat-lon_{$kind}_{$run}_{$t}_{$level}_{$var}.grib2.bz2";
    }

    /** Neuester vollständig veröffentlichter Lauf (YYYYMMDDHH) oder null. */
    public function latestRun(): ?string
    {
        $n = $this->cfg['forecast_hours'];
        $t = (int)(floor(time() / 10800) * 10800);
        for ($i = 0; $i < 8; $i++, $t -= 10800) {
            $run = gmdate('YmdH', $t);
            if (Http::head($this->url($run, 'clc', $n, 1)) && Http::head($this->url($run, 'clc', $n, 65))) {
                return $run;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- Fenster

    /** Fenster in Gitterindizes + Geo-Referenz des Fensters, bestimmt aus einer GRIB-Datei. */
    private function windowFor(array $g): array
    {
        $hs = $this->cfg['half_size'];
        [$latS, $lonW] = Geo::toLatLon($this->cfg, -$hs, -$hs);
        [$latN, $lonE] = Geo::toLatLon($this->cfg, $hs, $hs);
        $j0 = (int)floor(($latS - $g['lat0']) / $g['dlat']) - 1;
        $j1 = (int)ceil(($latN - $g['lat0']) / $g['dlat']) + 1;
        $i0 = (int)floor(fmod($lonW - $g['lon0'] + 360, 360) / $g['dlon']) - 1;
        $i1 = (int)ceil(fmod($lonE - $g['lon0'] + 360, 360) / $g['dlon']) + 1;
        return ['i0' => $i0, 'i1' => $i1, 'j0' => $j0, 'j1' => $j1,
            'lat0' => $g['lat0'] + $j0 * $g['dlat'], 'lon0' => fmod($g['lon0'] + $i0 * $g['dlon'], 360),
            'dlat' => $g['dlat'], 'dlon' => $g['dlon']];
    }

    private function fetchWindow(string $url, ?array &$win): ?array
    {
        $b = Http::get($url);
        if ($b === null) {
            return null;
        }
        $d = Http::bunzip2($b);
        if ($d === null) {
            return null;
        }
        if ($win === null) {
            $win = $this->windowFor(Grib2::grid($d));
        }
        return Grib2::window($d, $win['i0'], $win['i1'], $win['j0'], $win['j1']);
    }

    /** Höhen der Halbflächen (konstant) im Fenster; wird einmalig gecacht. */
    private function hhl(string $run): array
    {
        $f = "{$this->dir}/hhl.json";
        if (is_file($f)) {
            return json_decode(file_get_contents($f), true);
        }
        $this->status('running', $run, 0, 1, 'Lade Höhenfelder (hhl)');
        $urls = [];
        for ($k = 1; $k <= 66; $k++) {
            $urls[$k] = $this->url($run, 'hhl', 0, $k, true);
        }
        $first = Http::get($urls[66]);
        $win = null;
        $d = Http::bunzip2($first);
        $win = $this->windowFor(Grib2::grid($d));
        $levels = [66 => Grib2::window($d, $win['i0'], $win['i1'], $win['j0'], $win['j1'])];
        unset($urls[66]);
        Http::multi($urls, $this->cfg['parallel'], function ($kk, $body) use (&$levels, $win) {
            $d = $body === null ? null : Http::bunzip2($body);
            if ($d === null) {
                throw new RuntimeException("hhl Level $kk nicht ladbar");
            }
            $levels[$kk] = Grib2::window($d, $win['i0'], $win['i1'], $win['j0'], $win['j1']);
        });
        ksort($levels);
        $out = ['win' => $win, 'levels' => $levels];
        file_put_contents("$f.tmp", json_encode($out));
        rename("$f.tmp", $f);
        return $out;
    }

    // ---------------------------------------------------------------- Interpolation

    /** Bilinear aus Fenster-Array $w[j][i] an Lat/Lon. */
    private static function sample(array $w, array $win, float $lat, float $lon): float
    {
        $fy = ($lat - $win['lat0']) / $win['dlat'];
        $fx = fmod($lon - $win['lon0'] + 360, 360) / $win['dlon'];
        $ny = count($w);
        $nx = count($w[0]);
        $y0 = max(0, min($ny - 2, (int)floor($fy)));
        $x0 = max(0, min($nx - 2, (int)floor($fx)));
        $ay = min(1, max(0, $fy - $y0));
        $ax = min(1, max(0, $fx - $x0));
        $v = fn($y, $x) => $w[$y][$x] ?? 0.0;
        return $v($y0, $x0) * (1 - $ax) * (1 - $ay) + $v($y0, $x0 + 1) * $ax * (1 - $ay)
            + $v($y0 + 1, $x0) * (1 - $ax) * $ay + $v($y0 + 1, $x0 + 1) * $ax * $ay;
    }

    // ---------------------------------------------------------------- Build

    public function status(string $state, ?string $run, int $done, int $total, string $msg = ''): void
    {
        file_put_contents("{$this->dir}/status.json", json_encode(
            ['state' => $state, 'run' => $run, 'done' => $done, 'total' => $total, 'message' => $msg, 'updated' => time()]));
    }

    /** Baut (oder setzt fort) den Lauf und liefert den Pfad der fertigen JSON-Datei. */
    public function build(string $run): string
    {
        $final = "{$this->dir}/clouds_$run.json";
        if (is_file($final)) {
            return $final;
        }
        $cfg = $this->cfg;
        $H = $this->hhl($run);
        $win = $H['win'];
        $hl = $H['levels']; // [k][j][i]

        // Benötigte Schichten: Schicht k liegt zwischen Halbfläche k (oben) und k+1 (unten)
        $minBottom = function (int $k) use ($hl): float {
            $m = INF;
            foreach ($hl[$k + 1] as $row) {
                foreach ($row as $v) {
                    $m = min($m, $v);
                }
            }
            return $m;
        };
        $kTop = 65;
        while ($kTop > 1 && $minBottom($kTop - 1) < $cfg['max_height']) {
            $kTop--;
        }
        $ks = range(65, $kTop); // bottom -> top
        $nz = count($ks);

        $runDir = "{$this->dir}/run_$run";
        @mkdir($runDir, 0777, true);
        $steps = $cfg['forecast_hours'];
        $total = ($steps + 1) * $nz;
        $done = 0;

        $tasks = [];
        $have = [];
        for ($t = 0; $t <= $steps; $t++) {
            $sf = sprintf('%s/step_%03d.json', $runDir, $t);
            if (is_file($sf)) {
                $have[$t] = true;
                $done += $nz;
                continue;
            }
            foreach ($ks as $li => $k) {
                $tasks[] = [$t, $li, $this->url($run, 'clc', $t, $k)];
            }
        }
        $this->status('running', $run, $done, $total, 'Lade ICON-D2 clc');

        $acc = []; // [t] => ['n'=>..., 'layers'=>[li=>window]]
        $errors = 0;
        $lastStatus = 0;
        Http::multi(array_column($tasks, 2), $cfg['parallel'], function ($idx, $body) use (&$tasks, &$acc, &$done, &$errors, &$lastStatus, $nz, $win, $runDir, $run, $total) {
            [$t, $li] = $tasks[$idx];
            $d = $body === null ? null : Http::bunzip2($body);
            if ($d === null) {
                $errors++;
                return;
            }
            $acc[$t]['layers'][$li] = Grib2::window($d, $win['i0'], $win['i1'], $win['j0'], $win['j1']);
            $done++;
            if (count($acc[$t]['layers']) === $nz) {
                ksort($acc[$t]['layers']);
                file_put_contents(sprintf('%s/step_%03d.json', $runDir, $t), json_encode($acc[$t]['layers']));
                unset($acc[$t]);
            }
            if (time() !== $lastStatus) {
                $lastStatus = time();
                $this->status('running', $run, $done, $total, 'Lade ICON-D2 clc');
            }
        });
        if ($errors) {
            throw new RuntimeException("$errors Dateien konnten nicht geladen werden (Lauf evtl. unvollständig); erneut versuchen");
        }

        // Zielgitter
        $hs = $cfg['half_size'];
        $cell = $cfg['cell'];
        $n = intdiv(2 * $hs, $cell);
        $cols = [];
        for ($j = 0; $j < $n; $j++) {
            for ($i = 0; $i < $n; $i++) {
                $cols[] = Geo::toLatLon($cfg, -$hs + ($i + 0.5) * $cell, -$hs + ($j + 0.5) * $cell);
            }
        }
        // Halbflächenhöhen: nz+1 Ebenen (bottom -> top)
        $heights = [];
        $halfOrder = array_merge([66], array_map(fn($k) => $k, $ks)); // 66 = Boden, dann k = Oberkante der Schicht k
        foreach ($halfOrder as $hk) {
            foreach ($cols as [$lat, $lon]) {
                $heights[] = (int)round(self::sample($hl[$hk], $win, $lat, $lon));
            }
        }

        $times = [];
        $clc = [];
        $t0 = gmmktime((int)substr($run, 8, 2), 0, 0, (int)substr($run, 4, 2), (int)substr($run, 6, 2), (int)substr($run, 0, 4));
        for ($t = 0; $t <= $steps; $t++) {
            $times[] = gmdate('Y-m-d\TH:i:s\Z', $t0 + $t * 3600);
            $layers = json_decode(file_get_contents(sprintf('%s/step_%03d.json', $runDir, $t)), true);
            $flat = [];
            foreach ($ks as $li => $_) {
                foreach ($cols as [$lat, $lon]) {
                    $v = self::sample($layers[$li], $win, $lat, $lon);
                    $flat[] = (int)round(max(0, min(100, $v)));
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
            'half_size' => $hs, 'cell' => $cell,
            'nx' => $n, 'ny' => $n, 'nz' => $nz,
            'times' => $times,
            // heights: (nz+1) Halbflächen von unten nach oben, je ny*nx (Zeile j von Süd nach Nord, i von West nach Ost), m ü. NN
            // Schicht l liegt zwischen heights[l] und heights[l+1]
            'heights' => $heights,
            // clc[t]: nz*ny*nx Werte (0..100 %), Index = (l*ny + j)*nx + i
            'clc' => $clc,
        ];
        file_put_contents("$final.tmp", json_encode($out));
        rename("$final.tmp", $final);
        $this->cleanup();
        $this->status('idle', $run, $total, $total, 'fertig');
        return $final;
    }

    private function cleanup(): void
    {
        $runs = [];
        foreach (glob("{$this->dir}/clouds_*.json") as $f) {
            $runs[] = substr(basename($f, '.json'), 7);
        }
        rsort($runs);
        foreach (array_slice($runs, $this->cfg['keep_runs']) as $r) {
            @unlink("{$this->dir}/clouds_$r.json");
        }
        array_map('unlink', glob("{$this->dir}/*.tmp") ?: []);
        foreach (glob("{$this->dir}/run_*", GLOB_ONLYDIR) as $d) {
            // Zwischendateien (auch des gerade fertigen Laufs) werden nicht mehr gebraucht
            array_map('unlink', glob("$d/*"));
            @rmdir($d);
        }
    }
}
