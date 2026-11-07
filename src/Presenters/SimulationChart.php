<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Domain\Simulation\FixedPoint;
use InvalidArgumentException;

/** SSR-ready SVG from the exact ledger chart. No browser-side financial calculation. */
final class SimulationChart
{
    public function render(array $chart): string
    {
        if (count($chart) < 1 || count($chart) > 1001) {
            throw new InvalidArgumentException('Invalid chart point count.');
        }
        $values = [];
        foreach (array_values($chart) as $index => $point) {
            if (
                ($point['ordinal'] ?? null) !== $index || !is_string($point['bankroll_minor'] ?? null)
                || preg_match('/^[0-9]{1,15}$/D', $point['bankroll_minor']) !== 1
            ) {
                throw new InvalidArgumentException('Invalid exact chart point.');
            }
            $values[] = (int) $point['bankroll_minor'];
        }
        $low = min($values);
        $span = max(1, max($values) - $low);
        $points = [];
        foreach ($values as $index => $value) {
            $x = 12 + FixedPoint::round($index * 576, max(1, count($values) - 1));
            $y = 140 - FixedPoint::round(FixedPoint::multiply($value - $low, 120), $span);
            $points[] = $x . ',' . $y;
        }
        [$firstX, $firstY] = explode(',', $points[0]);
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 160" role="img" '
            . 'aria-label="Hypothetical bankroll in minor units">'
            . '<title>Hypothetical bankroll. Range ' . $low . ' to ' . max($values) . ' minor units.</title>'
            . '<polyline fill="none" stroke="currentColor" stroke-width="2" points="'
            . implode(' ', $points) . '"/>'
            . '<circle fill="currentColor" r="3" cx="' . $firstX . '" cy="' . $firstY . '"/></svg>';
    }
}
