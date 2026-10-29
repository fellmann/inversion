<?php
// Minimaler GRIB2-Reader für DWD-Regular-Lat-Lon-Felder (Gitter-Template 3.0,
// Packung 5.0 "simple packing", optionale Bitmap). Liest nur ein Fenster aus.
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

    /** @return array{lat0:float,lon0:float,dlat:float,dlon:float,ni:int,nj:int} */
    public static function grid(string $d): array
    {
        $g = self::sections($d)[3];
        $ni = unpack('N', substr($g, 30, 4))[1];
        $nj = unpack('N', substr($g, 34, 4))[1];
        $la1 = self::sm32(substr($g, 46, 4)) / 1e6;
        $lo1 = self::sm32(substr($g, 50, 4)) / 1e6;
        $la2 = self::sm32(substr($g, 55, 4)) / 1e6;
        $lo2 = self::sm32(substr($g, 59, 4)) / 1e6;
        if (ord($g[71]) !== 64) {
            throw new RuntimeException('Unerwarteter Scanmodus ' . ord($g[71]));
        }
        return ['lat0' => $la1, 'lon0' => $lo1, 'ni' => $ni, 'nj' => $nj,
            'dlat' => ($la2 - $la1) / ($nj - 1),
            'dlon' => fmod($lo2 - $lo1 + 360, 360) / ($ni - 1)];
    }

    /** Zerlegt in Sektionen (Index = Sektionsnummer). */
    private static function sections(string $d): array
    {
        $p = 16;
        $n = strlen($d);
        $out = [];
        while ($p + 5 <= $n && substr($d, $p, 4) !== '7777') {
            $len = unpack('N', substr($d, $p, 4))[1];
            $out[ord($d[$p + 4])] = substr($d, $p, $len);
            $p += $len;
        }
        return $out;
    }

    /**
     * Liest Rechteck [i0..i1] x [j0..j1] (Gitterindizes).
     * @return array<int,array<int,float|null>> [j - j0][i - i0]; null = fehlend (Bitmap)
     */
    public static function window(string $d, int $i0, int $i1, int $j0, int $j1): array
    {
        $s = self::sections($d);
        $ni = unpack('N', substr($s[3], 30, 4))[1];
        $s5 = $s[5];
        if (unpack('n', substr($s5, 9, 2))[1] !== 0) {
            throw new RuntimeException('Nur Packungs-Template 5.0 unterstützt');
        }
        $R = unpack('G', substr($s5, 11, 4))[1];
        $E = self::sm16(unpack('n', substr($s5, 15, 2))[1]);
        $D = self::sm16(unpack('n', substr($s5, 17, 2))[1]);
        $bits = ord($s5[19]);
        $bitmap = (ord($s[6][5]) === 0) ? substr($s[6], 6) : null;
        $data = substr($s[7], 5);
        $scale = 2 ** $E;
        $div = 10 ** $D;

        // Anzahl gesetzter Bits in $bitmap[0 .. $pos)
        $count = function (int $pos) use ($bitmap): int {
            $full = $pos >> 3;
            $c = 0;
            foreach (count_chars(substr($bitmap, 0, $full), 1) as $byte => $freq) {
                $c += substr_count(decbin($byte), '1') * $freq;
            }
            $rem = $pos & 7;
            if ($rem) {
                $c += substr_count(decbin(ord($bitmap[$full]) >> (8 - $rem)), '1');
            }
            return $c;
        };

        $out = [];
        for ($j = $j0; $j <= $j1; $j++) {
            $row = [];
            $k = $bitmap ? $count($j * $ni + $i0) : $j * $ni + $i0;
            for ($i = $i0; $i <= $i1; $i++) {
                $pos = $j * $ni + $i;
                if ($bitmap && !(ord($bitmap[$pos >> 3]) & (0x80 >> ($pos & 7)))) {
                    $row[] = null;
                    continue;
                }
                $row[] = ($R + self::bits($data, $k, $bits) * $scale) / $div;
                $k++;
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function bits(string $data, int $idx, int $nb): int
    {
        if ($nb === 16) {
            return (ord($data[$idx * 2]) << 8) | ord($data[$idx * 2 + 1]);
        }
        if ($nb === 8) {
            return ord($data[$idx]);
        }
        $bitPos = $idx * $nb;
        $chunk = str_pad(substr($data, $bitPos >> 3, 8), 8, "\0");
        $v = unpack('J', $chunk)[1];
        return ($v >> (64 - ($bitPos & 7) - $nb)) & ((1 << $nb) - 1);
    }
}
