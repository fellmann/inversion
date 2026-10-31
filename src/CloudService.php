<?php
require_once __DIR__ . '/CloudBuilder.php';
require_once __DIR__ . '/LocationBuilder.php';

// Cache-Logik: hält den letzten ICON-D2-Lauf vor, stößt bei neuem Modelllauf einen Hintergrund-Build an
// und berechnet das Zwischenformat pro Ort bei Bedarf (mit Ortscache).
class CloudService
{
    public function __construct(private array $cfg) {}

    /** Meta des neuesten vollständig gespeicherten Laufs. */
    public function latest(): ?array
    {
        $files = glob("{$this->cfg['cache_dir']}/meta_*.json");
        if (!$files) {
            return null;
        }
        rsort($files);
        return json_decode(file_get_contents($files[0]), true);
    }

    public function status(): array
    {
        $f = "{$this->cfg['cache_dir']}/status.json";
        return is_file($f) ? json_decode(file_get_contents($f), true) : ['state' => 'idle'];
    }

    private function checkDue(): bool
    {
        $stamp = "{$this->cfg['cache_dir']}/checked";
        return !($this->latest() && is_file($stamp) && time() - filemtime($stamp) < $this->cfg['check_interval']);
    }

    /** Prüft (höchstens alle check_interval s) auf neuen Lauf und baut ihn. Wirft keine Exceptions bei Netzfehlern. */
    public function refresh(bool $force = false): void
    {
        if (!$force && !$this->checkDue()) {
            return;
        }
        @mkdir($this->cfg['cache_dir'], 0777, true);
        $lock = fopen("{$this->cfg['cache_dir']}/build.lock", 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return; // anderer Build läuft
        }
        try {
            $b = new CloudBuilder($this->cfg);
            $run = $b->latestRun();
            touch("{$this->cfg['cache_dir']}/checked");
            if ($run !== null) {
                $b->build($run);
            }
        } catch (Throwable $e) {
            (new CloudBuilder($this->cfg))->status('error', null, 0, 0, $e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
        }
    }

    /** Startet refresh() als losgelöster Hintergrundprozess, falls nötig. */
    public function refreshAsync(): void
    {
        if (!$this->checkDue()) {
            return;
        }
        @mkdir($this->cfg['cache_dir'], 0777, true);
        $lock = fopen("{$this->cfg['cache_dir']}/build.lock", 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return;
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        $cmd = escapeshellarg(self::cliBinary()) . ' ' . escapeshellarg(__DIR__ . '/../bin/update.php');
        exec("nohup $cmd > " . escapeshellarg($this->cfg['cache_dir'] . '/update.log') . ' 2>&1 &');
    }

    /** PHP_BINARY ist unter Apache/FPM nicht die CLI; dann 'php' aus dem Standardpfad bzw. PATH nutzen. */
    private static function cliBinary(): string
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
            return PHP_BINARY;
        }
        foreach (['/usr/local/bin/php', '/usr/bin/php'] as $p) {
            if (is_executable($p)) {
                return $p;
            }
        }
        return 'php';
    }

    /**
     * Pfad der JSON-Datei mit den Wolkendaten für den Ort in $this->cfg (lat/lon); berechnet sie bei Bedarf.
     * @throws OutOfDomain wenn der Ort außerhalb des Modellgebiets liegt
     */
    public function locationFile(array $meta): string
    {
        $dir = "{$this->cfg['cache_dir']}/loc";
        // "_s": Ortsdatei enthält die 2D-Felder (Läufe aus älteren Versionen bekommen so neue Dateien)
        $f = sprintf('%s/%s%s_%.3f_%.3f.json', $dir, $meta['run'], empty($meta['surface']) ? '' : '_s', $this->cfg['lat'], $this->cfg['lon']);
        if (is_file($f)) {
            touch($f);
            return $f;
        }
        @mkdir($dir, 0777, true);
        $lock = fopen("$f.lock", 'c');
        flock($lock, LOCK_EX); // gleichzeitige Anfragen für denselben Ort nicht doppelt rechnen
        if (!is_file($f)) {
            try {
                file_put_contents("$f.tmp", json_encode(LocationBuilder::build($this->cfg, $meta)));
                rename("$f.tmp", $f);
            } finally {
                flock($lock, LOCK_UN);
                @unlink("$f.lock");
            }
        }
        self::prune($dir, $this->cfg['max_locations']);
        return $f;
    }

    /** Behält nur die zuletzt benutzten $max Dateien. */
    public static function prune(string $dir, int $max): void
    {
        $files = glob("$dir/*.json");
        if (count($files) <= $max) {
            return;
        }
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, $max) as $f) {
            @unlink($f);
        }
    }
}
