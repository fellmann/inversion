<?php
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Grib2.php';

// Lädt einen kompletten ICON-D2-Lauf (DWD Open Data, ganzes Modellgebiet) und legt ihn kompakt ab:
//   cache/grid.json, bitmap.bin           Gitter + Gültigkeitsmaske (einmalig)
//   cache/hhl/L<k>.gz, hhl/meta.json      Höhen der Halbflächen (einmalig, 16-Bit-Rohwerte)
//   cache/run_<run>/t<TTT>_k<KK>.gz       Bedeckung 0..100 % als Bytes je Schicht und Stunde
//   cache/meta_<run>.json                 Lauf komplett (wird zuletzt geschrieben)
// Das Zwischenformat für einen Ort wird daraus erst bei Anfrage berechnet (LocationBuilder).
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
        @mkdir($this->dir, 0777, true);
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

    public function status(string $state, ?string $run, int $done, int $total, string $msg = ''): void
    {
        file_put_contents("{$this->dir}/status.json", json_encode(
            ['state' => $state, 'run' => $run, 'done' => $done, 'total' => $total, 'message' => $msg, 'updated' => time()]));
    }

    // ---------------------------------------------------------------- Statische Felder (hhl)

    /** Lädt einmalig die Schichthöhen für das ganze Gebiet. @return array{ks:int[],levels:array} */
    private function ensureStatic(string $run): array
    {
        $metaFile = "{$this->dir}/hhl/meta.json";
        if (is_file($metaFile)) {
            return json_decode(file_get_contents($metaFile), true);
        }
        $hdir = "{$this->dir}/hhl";
        @mkdir($hdir, 0777, true);
        $this->status('running', $run, 0, 66, 'Lade Höhenfelder (hhl)');
        $urls = [];
        for ($k = 1; $k <= 66; $k++) {
            $urls[$k] = $this->url($run, 'hhl', 0, $k, true);
        }
        $levels = [];
        $mins = [];
        $grid = null;
        $bitmap = null;
        $done = 0;
        Http::multi($urls, $this->cfg['parallel'], function ($k, $body) use (&$levels, &$mins, &$grid, &$bitmap, &$done, $hdir, $run) {
            $d = $body === null ? null : Http::bunzip2($body);
            if ($d === null) {
                throw new RuntimeException("hhl Level $k nicht ladbar");
            }
            $p = Grib2::parse($d);
            // bits = 0: konstantes Feld (Modelloberkante, Level 1)
            if ($p['template'] !== 0 || !in_array($p['bits'], [0, 16], true) || $p['bitmap'] === null) {
                throw new RuntimeException('hhl: unerwartetes Format');
            }
            if ($grid === null) {
                $grid = $p['grid'];
                $bitmap = $p['bitmap'];
            } elseif ($p['bitmap'] !== $bitmap) {
                throw new RuntimeException('hhl: abweichende Bitmap');
            }
            $levels[$k] = ['R' => $p['R'], 'E' => $p['E'], 'D' => $p['D'], 'const' => $p['bits'] === 0];
            if ($p['bits'] === 0) {
                $mins[$k] = Grib2::value($p, 0);
            } else {
                file_put_contents("$hdir/L$k.gz", gzdeflate($p['data'], 3));
                $mins[$k] = Grib2::value($p, min(unpack('n*', $p['data'])));
            }
            $this->status('running', $run, ++$done, 66, 'Lade Höhenfelder (hhl)');
        });
        file_put_contents("{$this->dir}/grid.json", json_encode(['grid' => $grid, 'bitmap_md5' => md5($bitmap)]));
        file_put_contents("{$this->dir}/bitmap.bin", $bitmap);

        // Schicht k liegt zwischen Halbfläche k (oben) und k+1 (unten); behalten, wenn irgendwo unter max_height
        $ks = [];
        for ($k = 65; $k >= 1; $k--) {
            if ($mins[$k + 1] < $this->cfg['max_height']) {
                $ks[] = $k;
            }
        }
        $meta = ['ks' => $ks, 'levels' => $levels];
        file_put_contents("$metaFile.tmp", json_encode($meta));
        rename("$metaFile.tmp", $metaFile);
        return $meta;
    }

    // ---------------------------------------------------------------- Build

    /** Baut (oder setzt fort) den Lauf; danach liegt meta_<run>.json vor. */
    public function build(string $run): void
    {
        $metaRun = "{$this->dir}/meta_$run.json";
        if (is_file($metaRun)) {
            return;
        }
        $st = $this->ensureStatic($run);
        $ks = $st['ks'];
        $md5 = json_decode(file_get_contents("{$this->dir}/grid.json"), true)['bitmap_md5'];

        $runDir = "{$this->dir}/run_$run";
        @mkdir($runDir, 0777, true);
        $steps = $this->cfg['forecast_hours'];
        $total = ($steps + 1) * count($ks);
        $done = 0;
        $tasks = [];
        for ($t = 0; $t <= $steps; $t++) {
            foreach ($ks as $k) {
                if (is_file($this->layerFile($run, $t, $k))) {
                    $done++;
                } else {
                    $tasks[] = [$t, $k, $this->url($run, 'clc', $t, $k)];
                }
            }
        }
        $this->status('running', $run, $done, $total, 'Lade ICON-D2 clc');

        $errors = 0;
        $last = 0;
        Http::multi(array_column($tasks, 2), $this->cfg['parallel'], function ($i, $body) use (&$tasks, &$done, &$errors, &$last, $run, $total, $md5) {
            [$t, $k] = $tasks[$i];
            $d = $body === null ? null : Http::bunzip2($body);
            if ($d === null) {
                $errors++;
                return;
            }
            $p = Grib2::parse($d);
            if ($p['bitmap'] === null || md5($p['bitmap']) !== $md5) {
                throw new RuntimeException('clc: Gültigkeitsmaske weicht ab');
            }
            $f = $this->layerFile($run, $t, $k);
            file_put_contents("$f.part", gzdeflate(Grib2::percentBytes($p), 3));
            rename("$f.part", $f);
            $done++;
            if (time() !== $last) {
                $last = time();
                $this->status('running', $run, $done, $total, 'Lade ICON-D2 clc');
            }
        });
        if ($errors) {
            throw new RuntimeException("$errors Dateien konnten nicht geladen werden (Lauf evtl. unvollständig); erneut versuchen");
        }

        file_put_contents("$metaRun.tmp", json_encode(['run' => $run, 'steps' => $steps, 'ks' => $ks, 'created' => time()]));
        rename("$metaRun.tmp", $metaRun);
        $this->cleanup($run);
        $this->status('idle', $run, $total, $total, 'fertig');
    }

    private function layerFile(string $run, int $t, int $k): string
    {
        return sprintf('%s/run_%s/t%03d_k%02d.gz', $this->dir, $run, $t, $k);
    }

    /** Alte Läufe, Ortscaches des alten Laufs und Reste des früheren Cache-Formats löschen. */
    private function cleanup(string $current): void
    {
        $d = $this->dir;
        foreach (glob("$d/meta_*.json") as $f) {
            if (basename($f) !== "meta_$current.json") {
                unlink($f);
            }
        }
        foreach (glob("$d/run_*", GLOB_ONLYDIR) as $dir) {
            if (basename($dir) !== "run_$current") {
                array_map('unlink', glob("$dir/*"));
                @rmdir($dir);
            }
        }
        foreach (glob("$d/loc/*.json") as $f) {
            if (strpos(basename($f), "{$current}_") !== 0) {
                unlink($f);
            }
        }
        // Altformat (vor Ortsauswahl)
        foreach (array_merge(glob("$d/clouds_*.json"), ["$d/hhl.json", "$d/terrain.json"]) as $f) {
            @unlink($f);
        }
        array_map('unlink', glob("$d/*.tmp") ?: []);
    }
}
