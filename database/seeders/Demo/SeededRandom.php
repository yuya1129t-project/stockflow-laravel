<?php

namespace Database\Seeders\Demo;

/**
 * 固定シードの擬似乱数（mulberry32）。Next.js 版（stockflow-next の prisma/seed-data.ts）と
 * 同じ計算にしてあるので、同じシードなら同じ数列になる（PHP の整数は 64 ビットなので 32 ビットに切り詰めて計算する）
 */
final class SeededRandom
{
    private const int MASK = 0xFFFFFFFF;

    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed & self::MASK;
    }

    /** 0 以上 1 未満 */
    public function next(): float
    {
        $this->state = ($this->state + 0x6D2B79F5) & self::MASK;
        $t = $this->state;
        $t = self::imul($t ^ ($t >> 15), $t | 1);
        $t ^= ($t + self::imul($t ^ ($t >> 7), $t | 61)) & self::MASK;

        return (($t ^ ($t >> 14)) & self::MASK) / 4294967296;
    }

    /** min 以上 max 以下の整数 */
    public function int(int $min, int $max): int
    {
        return $min + (int) floor($this->next() * ($max - $min + 1));
    }

    public function chance(float $p): bool
    {
        return $this->next() < $p;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * 重み付きで重複なしに count 件選ぶ
     *
     * @template T
     *
     * @param  list<T>  $items
     * @param  callable(T): int  $weight
     * @return list<T>
     */
    public function sampleWeighted(array $items, callable $weight, int $count): array
    {
        $pool = $items;
        $picked = [];
        while (count($picked) < $count && $pool !== []) {
            $total = 0;
            foreach ($pool as $item) {
                $total += $weight($item);
            }
            $r = $this->next() * $total;
            $index = 0;
            for ($last = count($pool) - 1; $index < $last; $index++) {
                $r -= $weight($pool[$index]);
                if ($r < 0) {
                    break;
                }
            }
            $picked[] = $pool[$index];
            array_splice($pool, $index, 1);
        }

        return $picked;
    }

    /** JavaScript の Math.imul と同じ（32 ビット整数の積の下位 32 ビット。符号なしで返す） */
    private static function imul(int $a, int $b): int
    {
        $a &= self::MASK;
        $b &= self::MASK;
        $high = (($a >> 16) * $b) & 0xFFFF;

        return ((($a & 0xFFFF) * $b) + ($high << 16)) & self::MASK;
    }
}
