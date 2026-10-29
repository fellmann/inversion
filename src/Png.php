<?php
// Minimaler PNG-Decoder (8 Bit, nicht interlaced, Truecolor/Truecolor+Alpha), ohne GD.
class Png
{
    /** @return array{w:int,h:int,ch:int,rows:array<int,array<int,int>>} rows[y] = 1-basiertes Byte-Array */
    public static function decode(string $png): array
    {
        if (substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('Kein PNG');
        }
        $p = 8;
        $idat = '';
        while ($p < strlen($png)) {
            $len = unpack('N', substr($png, $p, 4))[1];
            $type = substr($png, $p + 4, 4);
            $body = substr($png, $p + 8, $len);
            if ($type === 'IHDR') {
                $h = unpack('N', substr($body, 4, 4))[1];
                $w = unpack('N', substr($body, 0, 4))[1];
                $depth = ord($body[8]);
                $ctype = ord($body[9]);
                $ilace = ord($body[12]);
                if ($depth !== 8 || !in_array($ctype, [2, 6], true) || $ilace !== 0) {
                    throw new RuntimeException('Nicht unterstütztes PNG-Format');
                }
            } elseif ($type === 'IDAT') {
                $idat .= $body;
            } elseif ($type === 'IEND') {
                break;
            }
            $p += 12 + $len;
        }
        $ch = $ctype === 2 ? 3 : 4;
        $raw = gzuncompress($idat);
        $stride = $w * $ch;
        $prev = array_fill(1, $stride, 0);
        $rows = [];
        for ($y = 0; $y < $h; $y++) {
            $off = $y * ($stride + 1);
            $f = ord($raw[$off]);
            $cur = unpack('C*', substr($raw, $off + 1, $stride));
            switch ($f) {
                case 1:
                    for ($i = $ch + 1; $i <= $stride; $i++) {
                        $cur[$i] = ($cur[$i] + $cur[$i - $ch]) & 255;
                    }
                    break;
                case 2:
                    for ($i = 1; $i <= $stride; $i++) {
                        $cur[$i] = ($cur[$i] + $prev[$i]) & 255;
                    }
                    break;
                case 3:
                    for ($i = 1; $i <= $stride; $i++) {
                        $a = $i > $ch ? $cur[$i - $ch] : 0;
                        $cur[$i] = ($cur[$i] + (($a + $prev[$i]) >> 1)) & 255;
                    }
                    break;
                case 4:
                    for ($i = 1; $i <= $stride; $i++) {
                        $a = $i > $ch ? $cur[$i - $ch] : 0;
                        $b = $prev[$i];
                        $c = $i > $ch ? $prev[$i - $ch] : 0;
                        $pa = abs($b - $c);
                        $pb = abs($a - $c);
                        $pc = abs($a + $b - 2 * $c);
                        $pr = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        $cur[$i] = ($cur[$i] + $pr) & 255;
                    }
                    break;
            }
            $rows[$y] = $cur;
            $prev = $cur;
        }
        return ['w' => $w, 'h' => $h, 'ch' => $ch, 'rows' => $rows];
    }
}
