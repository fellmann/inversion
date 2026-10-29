<?php
require_once __DIR__ . '/CloudBuilder.php';

// Cache-Logik: liefert die aktuellsten Wolkendaten, stößt bei neuem Modelllauf einen Hintergrund-Build an.
class CloudService
{
    public function __construct(private array $cfg) {}

    private function newest(): ?string
    {
        $files = glob("{$this->cfg['cache_dir']}/clouds_*.json");
        if (!$files) {
            return null;
        }
        rsort($files);
        return $files[0];
    }

    public function status(): array
    {
        $f = "{$this->cfg['cache_dir']}/status.json";
        return is_file($f) ? json_decode(file_get_contents($f), true) : ['state' => 'idle'];
    }

    /** Prüft (höchstens alle check_interval s) auf neuen Lauf. Wirft keine Exceptions bei Netzfehlern. */
    public function refresh(bool $force = false): void
    {
        $stamp = "{$this->cfg['cache_dir']}/checked";
        $have = $this->newest();
        if (!$force && $have && is_file($stamp) && time() - filemtime($stamp) < $this->cfg['check_interval']) {
            return;
        }
        $lock = fopen("{$this->cfg['cache_dir']}/build.lock", 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return; // anderer Build läuft
        }
        try {
            $b = new CloudBuilder($this->cfg);
            $run = $b->latestRun();
            touch($stamp);
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
        $stamp = "{$this->cfg['cache_dir']}/checked";
        if ($this->newest() && is_file($stamp) && time() - filemtime($stamp) < $this->cfg['check_interval']) {
            return;
        }
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

    public function path(): ?string
    {
        return $this->newest();
    }
}
