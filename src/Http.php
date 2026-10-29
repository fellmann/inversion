<?php
// Minimale HTTP-Helfer auf Basis von curl (parallele Downloads via curl_multi).
class Http
{
    public static function head(string $url): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return $code === 200;
    }

    public static function get(string $url): ?string
    {
        $r = self::multi([$url], 1);
        return $r[0];
    }

    /**
     * Lädt mehrere URLs parallel. Ruft $onDone($index, $body|null) sobald eine fertig ist,
     * damit große Datenmengen nicht alle im Speicher bleiben müssen.
     * Rückgabe (ohne Callback): Array der Bodies (null bei Fehler/404).
     */
    public static function multi(array $urls, int $parallel, ?callable $onDone = null): array
    {
        $results = [];
        $mh = curl_multi_init();
        $queue = array_keys($urls);
        $active = [];
        $start = function (int $i) use ($urls, $mh, &$active) {
            $ch = curl_init($urls[$i]);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true]);
            curl_multi_add_handle($mh, $ch);
            $active[(int)$ch] = [$ch, $i];
        };
        while ($queue && count($active) < $parallel) {
            $start(array_shift($queue));
        }
        while ($active) {
            curl_multi_exec($mh, $running);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                [, $i] = $active[(int)$ch];
                $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $body = ($info['result'] === CURLE_OK && $code === 200) ? curl_multi_getcontent($ch) : null;
                curl_multi_remove_handle($mh, $ch);
                unset($active[(int)$ch]);
                if ($onDone) {
                    $onDone($i, $body);
                } else {
                    $results[$i] = $body;
                }
                if ($queue) {
                    $start(array_shift($queue));
                }
            }
            if ($active) {
                curl_multi_select($mh, 1.0);
            }
        }
        curl_multi_close($mh);
        ksort($results);
        return $results;
    }

    /** bzip2-Dekompression (Extension oder Kommandozeilen-bzip2). */
    public static function bunzip2(string $data): ?string
    {
        if (function_exists('bzdecompress')) {
            $r = bzdecompress($data);
            return is_string($r) ? $r : null;
        }
        // Über Temp-Datei statt Pipe, um Pipe-Deadlocks (stdin voll, stdout ungelesen) zu vermeiden
        $tmp = tempnam(sys_get_temp_dir(), 'bz');
        file_put_contents($tmp, $data);
        $p = proc_open(['bzip2', '-dc'], [0 => ['file', $tmp, 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($p)) {
            unlink($tmp);
            return null;
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($p);
        unlink($tmp);
        return $out === '' ? null : $out;
    }
}
