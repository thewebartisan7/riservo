<?php

namespace App\DTOs;

use Carbon\CarbonImmutable;

class TimeWindow
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    public function durationInMinutes(): int
    {
        return (int) $this->start->diffInMinutes($this->end);
    }

    public function overlaps(self $other): bool
    {
        return $this->start->lt($other->end) && $this->end->gt($other->start);
    }

    public function contains(CarbonImmutable $point): bool
    {
        return $point->gte($this->start) && $point->lt($this->end);
    }

    /**
     * Intersect two sets of windows, returning only the overlapping portions.
     *
     * @param  array<self>  $a
     * @param  array<self>  $b
     * @return array<self>
     */
    public static function intersect(array $a, array $b): array
    {
        $result = [];

        foreach ($a as $windowA) {
            foreach ($b as $windowB) {
                $start = $windowA->start->max($windowB->start);
                $end = $windowA->end->min($windowB->end);

                if ($start->lt($end)) {
                    $result[] = new self($start, $end);
                }
            }
        }

        return self::merge($result);
    }

    /**
     * Subtract blocked ranges from windows.
     *
     * @param  array<self>  $windows
     * @param  array<self>  $blocks
     * @return array<self>
     */
    public static function subtract(array $windows, array $blocks): array
    {
        foreach ($blocks as $block) {
            $remaining = [];

            foreach ($windows as $window) {
                if (! $window->overlaps($block)) {
                    $remaining[] = $window;

                    continue;
                }

                // Left remainder
                if ($window->start->lt($block->start)) {
                    $remaining[] = new self($window->start, $block->start);
                }

                // Right remainder
                if ($window->end->gt($block->end)) {
                    $remaining[] = new self($block->end, $window->end);
                }
            }

            $windows = $remaining;
        }

        return $windows;
    }

    /**
     * Merge overlapping or adjacent windows into non-overlapping set.
     *
     * @param  array<self>  $windows
     * @return array<self>
     */
    public static function merge(array $windows): array
    {
        if (count($windows) <= 1) {
            return $windows;
        }

        usort($windows, fn (self $a, self $b) => $a->start->timestamp <=> $b->start->timestamp);

        $merged = [$windows[0]];

        for ($i = 1; $i < count($windows); $i++) {
            $last = $merged[count($merged) - 1];
            $current = $windows[$i];

            if ($current->start->lte($last->end)) {
                $merged[count($merged) - 1] = new self(
                    $last->start,
                    $last->end->max($current->end),
                );
            } else {
                $merged[] = $current;
            }
        }

        return $merged;
    }

    /**
     * Add new windows to existing set and merge.
     *
     * @param  array<self>  $windows
     * @param  array<self>  $additions
     * @return array<self>
     */
    public static function union(array $windows, array $additions): array
    {
        return self::merge(array_merge($windows, $additions));
    }
}
