<?php

declare(strict_types=1);

namespace App\Domain\Simulation;

use InvalidArgumentException;
use OverflowException;

/** Integer rational arithmetic on a 64-bit runtime. No binary floating point. */
final class FixedPoint
{
    public const SCALE = 1000000;
    public const MONEY_LIMIT = 1000000000;

    public static function decimal(string $value, int $maximum = 1000 * self::SCALE): int
    {
        if (preg_match('/^(0|[1-9][0-9]{0,3})(?:\.([0-9]{1,6}))?$/D', $value, $parts) !== 1) {
            throw new InvalidArgumentException('Expected a bounded decimal string with at most six places.');
        }
        $scaled = (int) $parts[1] * self::SCALE + (int) str_pad($parts[2] ?? '', 6, '0');
        if ($scaled > $maximum) {
            throw new InvalidArgumentException('Decimal exceeds policy bound.');
        }
        return $scaled;
    }

    public static function multiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || ($right > 0 && $left > intdiv(PHP_INT_MAX, $right))) {
            throw new OverflowException('Integer product exceeds policy precision.');
        }
        return $left * $right;
    }

    public static function add(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new OverflowException('Integer sum exceeds policy precision.');
        }
        if ($right < 0 && $left < PHP_INT_MIN - $right) {
            throw new OverflowException('Integer sum exceeds policy precision.');
        }
        return $left + $right;
    }

    /** Nonnegative rational rounding: ties round up to the next minor unit. */
    public static function round(int $numerator, int $denominator): int
    {
        if ($numerator < 0 || $denominator < 1) {
            throw new InvalidArgumentException('Invalid nonnegative rational.');
        }
        return intdiv($numerator, $denominator)
            + (int) ($numerator % $denominator >= intdiv($denominator, 2) + $denominator % 2);
    }

    public static function ceil(int $numerator, int $denominator): int
    {
        if ($numerator < 0 || $denominator < 1) {
            throw new InvalidArgumentException('Invalid nonnegative rational.');
        }
        return intdiv($numerator, $denominator) + (int) ($numerator % $denominator !== 0);
    }

    /** Six decimal places, half-up; null means an undefined denominator. */
    public static function ratio(int $numerator, int $denominator): ?string
    {
        if ($numerator === PHP_INT_MIN || $denominator < 0) {
            throw new InvalidArgumentException('Ratio exceeds the supported signed magnitude.');
        }
        if ($denominator === 0) {
            return null;
        }
        $negative = $numerator < 0;
        $absolute = abs($numerator);
        $whole = intdiv($absolute, $denominator);
        $fraction = self::round(self::multiply($absolute % $denominator, self::SCALE), $denominator);
        if ($fraction === self::SCALE) {
            $whole++;
            $fraction = 0;
        }
        return ($negative && ($whole !== 0 || $fraction !== 0) ? '-' : '')
            . $whole . '.' . str_pad((string) $fraction, 6, '0', STR_PAD_LEFT);
    }
}
