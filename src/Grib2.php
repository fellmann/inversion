<?php
// Minimaler GRIB2-Reader für DWD-Regular-Lat-Lon-Felder (Gitter-Template 3.0,
// Packung 5.0 "simple packing", optionale Bitmap).
class Grib2
{
    /** Vorzeichen-Betrag-Darstellung (GRIB: oberstes Bit = Vorzeichen) */
    private static function sm16(int $v): int
    {
        return ($v & 0x8000) ? -($v & 0x7fff) : $v;
    }

    private static function sm32(string $b): int
    {
        $v = unpack('N', $b)[1];
        return ($v & 0x80000000) ? -($v & 0x7fffffff) : $v;
    }

    /** Zerlegt in Sektionen (Index = Sektionsnummer). */
    private static function sections(string $d): array
    {
        $p = 16;
        $n = strlen($d);
        $out = [];
        while ($p + 5 <= $n && substr($d, $p, 4) !== '7777') {
            $len = unpack('N', substr($d, $p, 4))[1];
            if ($len < 5) {
                break;
            }
            $out[ord($d[$p + 4])] = substr($d, $p, $len);
            $p += $len;
        }
        return $out;
    }

    /**
     * @return array{grid:array,template:int,R:float,E:int,D:int,bits:int,bitmap:?string,data:string}
     *   grid: lat0, lon0 (Grad, Ostrichtung 0..360), dlat, dlon, ni, nj; Scanmodus 64 (West->Ost, Süd->Nord)
     *   bitmap: Bit je Gitterpunkt (1 = Wert vorhanden) oder null; data: nur Werte der gesetzten Punkte
     */
    public static function parse(string $d): array
    {
        $s = self::sections($d);
        $g = $s[3];
        $ni = unpack('N', substr($g, 30, 4))[1];
        $nj = unpack('N', substr($g, 34, 4))[1];
        $la1 = self::sm32(substr($g, 46, 4)) / 1e6;
        $lo1 = self::sm32(substr($g, 50, 4)) / 1e6;
        $la2 = self::sm32(substr($g, 55, 4)) / 1e6;
        $lo2 = self::sm32(substr($g, 59, 4)) / 1e6;
        if (ord($g[71]) !== 64) {
            throw new RuntimeException('Unerwarteter Scanmodus ' . ord($g[71]));
        }
        $s5 = $s[5];
        return [
            'grid' => ['lat0' => $la1, 'lon0' => $lo1, 'ni' => $ni, 'nj' => $nj,
                'dlat' => ($la2 - $la1) / ($nj - 1),
                'dlon' => fmod($lo2 - $lo1 + 360, 360) / ($ni - 1)],
            'template' => unpack('n', substr($s5, 9, 2))[1],
            'R' => unpack('G', substr($s5, 11, 4))[1],
            'E' => self::sm16(unpack('n', substr($s5, 15, 2))[1]),
            'D' => self::sm16(unpack('n', substr($s5, 17, 2))[1]),
            'bits' => ord($s5[19]),
            'bitmap' => (ord($s[6][5]) === 0) ? substr($s[6], 6) : null,
            'data' => substr($s[7], 5),
        ];
    }

    /** Anzahl gesetzter Bits in $bitmap[0 .. $pos) = Index des Werts an Position $pos im gepackten Feld. */
    public static function rank(string $bitmap, int $pos): int
    {
        static $pc = null;
        if ($pc === null) {
            for ($b = 0; $b < 256; $b++) {
                $pc[$b] = substr_count(decbin($b), '1');
            }
        }
        $full = $pos >> 3;
        $c = 0;
        foreach (count_chars(substr($bitmap, 0, $full), 1) as $byte => $freq) {
            $c += $pc[$byte] * $freq;
        }
        $rem = $pos & 7;
        if ($rem) {
            $c += $pc[ord($bitmap[$full]) >> (8 - $rem)];
        }
        return $c;
    }

    public static function isSet(string $bitmap, int $pos): bool
    {
        return (ord($bitmap[$pos >> 3]) & (0x80 >> ($pos & 7))) !== 0;
    }

    /** Wert aus 16-Bit-Rohwert X: (R + X * 2^E) / 10^D */
    public static function value(array $p, int $x): float
    {
        return ($p['R'] + $x * 2 ** $p['E']) / 10 ** $p['D'];
    }

    /**
     * Bedeckungsgrad 0..100 % als ein Byte je Wert (nur über das obere Byte des 16-Bit-Werts, Auflösung ~1 %).
     * Sehr schnell, da ohne PHP-Schleife über 750.000 Werte.
     */
    public static function percentBytes(array $p): string
    {
        if ($p['template'] !== 0 || $p['bits'] !== 16) {
            throw new RuntimeException('Nur 16-Bit-Simple-Packing unterstützt');
        }
        $tbl = [];
        for ($h = 0; $h < 256; $h++) {
            $tbl[chr($h)] = chr(max(0, min(100, (int)round(self::value($p, $h * 256 + 128)))));
        }
        return strtr(preg_replace('/(.)./s', '$1', $p['data']), $tbl);
    }
}
