<?php
declare(strict_types=1);

namespace NZB\Modules;

use RuntimeException;

/**
 * PAR2 Reed–Solomon GF(2^16), primitive polynomial 0x1100B.
 * Pure PHP: no native extensions, FFI, shell programs or subprocesses.
 * https://parchive.github.io/doc/Parity%20Volume%20Set%20Specification%20v2.0.html
 */
final class Par2Galois
{
    private const ORDER = 65535;
    /** @var list<int>|null */
    private static ?array $antilog = null;
    /** @var list<int>|null */
    private static ?array $log = null;

    private static function init(): void
    {
        if (self::$antilog !== null)
        {
            return;
        }
        $logs = array_fill(0, 65536, 0);
        $powers = array_fill(0, 65536, 0);
        $value = 1;
        for ($i = 0; $i < self::ORDER; $i++)
        {
            $powers[$i] = $value;
            $logs[$value] = $i;
            $value <<= 1;
            if (($value & 0x10000) !== 0)
            {
                $value ^= 0x1100B;
            }
        }
        if ($value !== 1)
        {
            throw new RuntimeException('PAR2 Galois polynomial does not form the required field.');
        }
        $powers[self::ORDER] = 1;
        self::$antilog = $powers;
        self::$log = $logs;
    }

    public static function multiply(int $a, int $b): int
    {
        if (($a | $b) === 0 || $a === 0 || $b === 0)
        {
            return 0;
        }
        self::init();
        return self::$antilog[(self::$log[$a] + self::$log[$b]) % self::ORDER];
    }

    public static function inverse(int $a): int
    {
        if ($a === 0)
        {
            throw new RuntimeException('Cannot invert a zero Reed–Solomon coefficient.');
        }
        self::init();
        return self::$antilog[self::ORDER - self::$log[$a]];
    }

    public static function power(int $base, int $exponent): int
    {
        if ($exponent === 0)
        {
            return 1;
        }
        if ($base === 0)
        {
            return 0;
        }
        if ($exponent < 0)
        {
            throw new RuntimeException('Invalid recovery exponent.');
        }
        self::init();
        return self::$antilog[(self::$log[$base] * ($exponent % self::ORDER)) % self::ORDER];
    }

    /** @return list<int> */
    public static function inputConstants(int $count): array
    {
        if ($count < 0 || $count > 32768)
        {
            throw new RuntimeException('PAR2 supports at most 32768 input slices.');
        }
        self::init();
        $constants = [];
        for ($exponent = 1; count($constants) < $count && $exponent < self::ORDER; $exponent++)
        {
            if ($exponent % 3 !== 0 && $exponent % 5 !== 0 &&
                $exponent % 17 !== 0 && $exponent % 257 !== 0)
            {
                $constants[] = self::$antilog[$exponent];
            }
        }
        if (count($constants) !== $count)
        {
            throw new RuntimeException('PAR2 input slice coefficients exhausted.');
        }
        return $constants;
    }

    /** @param list<list<int>> $matrix
     * @return list<list<int>> */
    public static function inverseMatrix(array $matrix): array
    {
        $n = count($matrix);
        if ($n < 1 || $n > 32)
        {
            throw new RuntimeException('Native repair supports at most 32 missing slices per job.');
        }
        $rows = [];
        for ($i = 0; $i < $n; $i++)
        {
            if (!isset($matrix[$i]) || count($matrix[$i]) !== $n)
            {
                throw new RuntimeException('PAR2 coefficient matrix is not square.');
            }
            $rows[$i] = array_merge(array_values($matrix[$i]),
                array_map(static fn (int $x): int => $x === $i ? 1 : 0, range(0, $n - 1)));
        }
        for ($col = 0; $col < $n; $col++)
        {
            $pivot = $col;
            while ($pivot < $n && $rows[$pivot][$col] === 0)
            {
                $pivot++;
            }
            if ($pivot === $n)
            {
                throw new RuntimeException('Insufficient linearly independent PAR2 recovery slices.');
            }
            if ($pivot !== $col)
            {
                [$rows[$col], $rows[$pivot]] = [$rows[$pivot], $rows[$col]];
            }
            $inv = self::inverse($rows[$col][$col]);
            for ($x = 0; $x < 2 * $n; $x++)
            {
                $rows[$col][$x] = self::multiply($rows[$col][$x], $inv);
            }
            for ($r = 0; $r < $n; $r++)
            {
                if ($r === $col || $rows[$r][$col] === 0)
                {
                    continue;
                }
                $factor = $rows[$r][$col];
                for ($x = 0; $x < 2 * $n; $x++)
                {
                    $rows[$r][$x] ^= self::multiply($factor, $rows[$col][$x]);
                }
            }
        }
        return array_map(static fn (array $row): array => array_slice($row, $n), $rows);
    }

    /**
     * Apply a GF16 multiplier to a binary slice in PAR2 little-endian word order.
     * Output is the same length as input, and XOR is performed in GF(2).
     */
    public static function multiplySlice(string $slice, int $factor): string
    {
        if ($factor === 0)
        {
            return str_repeat("\0", strlen($slice));
        }
        if ($factor === 1)
        {
            return $slice;
        }
        if (strlen($slice) % 2 !== 0)
        {
            throw new RuntimeException('PAR2 recovery slices must contain complete 16-bit words.');
        }
        // GF addition is XOR, so precompute the low/high byte contributions
        // once per window instead of doing a log/modulo lookup for every word.
        $low = [];
        $high = [];
        for ($value = 0; $value < 256; $value++)
        {
            $low[$value] = self::multiply($value, $factor);
            $high[$value] = self::multiply($value << 8, $factor);
        }
        $out = str_repeat("\0", strlen($slice));
        for ($i = 0, $n = strlen($slice); $i < $n; $i += 2)
        {
            $product = $low[ord($slice[$i])] ^ $high[ord($slice[$i + 1])];
            $out[$i] = chr($product & 255);
            $out[$i + 1] = chr($product >> 8);
        }
        return $out;
    }
}
