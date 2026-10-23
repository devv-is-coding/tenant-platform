<?php

namespace App\ValueObjects;

use InvalidArgumentException;
use NumberFormatter;

/**
 * An amount of money held as integer minor units, so no float ever touches a
 * stored amount - the cure for the legacy's `30.0099...` corruption (defect 2).
 *
 * There is no way in from a float: `ofMinor()` takes an integer and `parse()`
 * a string, and every calculation stays in integers. The number of minor
 * digits comes from the currency (ISO 4217, through intl), never a hardcoded
 * hundred: a yen has none, a peso two, a dinar three.
 *
 * Rounding policy: the only operation that can produce a fraction of a minor
 * unit is `percentage()`, which rounds half away from zero (commercial
 * rounding). Splitting an amount never rounds it away: `allocate()` hands the
 * leftover minor units out so the parts always sum to the whole.
 */
final readonly class Money
{
    private function __construct(public int $minor, public string $currency) {}

    /**
     * Create an amount from integer minor units, e.g. 250000 for PHP 2,500.00.
     *
     * @throws InvalidArgumentException
     */
    public static function ofMinor(int $minor, string $currency): self
    {
        return new self($minor, self::validCurrency($currency));
    }

    /**
     * Create a zero amount in the currency.
     *
     * @throws InvalidArgumentException
     */
    public static function zero(string $currency): self
    {
        return self::ofMinor(0, $currency);
    }

    /**
     * Create an amount from a typed decimal string such as "2500" or "30.01".
     *
     * The string is converted with string and integer arithmetic only. Anything
     * other than digits with at most the currency's decimal places is refused
     * rather than rounded, so a typo cannot silently become a different charge.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $amount, string $currency): self
    {
        $currency = self::validCurrency($currency);
        $digits = self::minorDigitsOf($currency);
        $fractionPattern = $digits > 0 ? '(?:\.(\d{1,'.$digits.'}))?' : '';

        if (preg_match('/^(\d+)'.$fractionPattern.'$/', $amount, $matches) !== 1) {
            throw new InvalidArgumentException("[{$amount}] is not an amount with at most {$digits} decimal places.");
        }

        $fraction = str_pad($matches[2] ?? '', $digits, '0');
        $minorDigits = ltrim($matches[1].$fraction, '0');

        if (strlen($minorDigits) >= strlen((string) PHP_INT_MAX)) {
            throw new InvalidArgumentException("[{$amount}] is too large.");
        }

        return new self((int) $minorDigits, $currency);
    }

    /**
     * Get how many minor digits the currency has, e.g. 0 for JPY, 2 for PHP.
     */
    public static function minorDigitsOf(string $currency): int
    {
        $formatter = new NumberFormatter('en@currency='.$currency, NumberFormatter::CURRENCY);

        return (int) $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
    }

    /**
     * Add an amount in the same currency.
     *
     * @throws InvalidArgumentException
     */
    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    /**
     * Subtract an amount in the same currency.
     *
     * @throws InvalidArgumentException
     */
    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /**
     * Multiply by a whole number, e.g. a unit price by a quantity.
     */
    public function times(int $multiplier): self
    {
        return new self($this->minor * $multiplier, $this->currency);
    }

    /**
     * Take a whole-number percentage of the amount, e.g. a 30% deposit,
     * rounding half away from zero to the nearest minor unit.
     *
     * To split a total into a deposit and a balance, use `allocate()` or
     * subtract this from the total, so the two always add back up.
     *
     * @throws InvalidArgumentException
     */
    public function percentage(int $percent): self
    {
        if ($percent < 0) {
            throw new InvalidArgumentException("A percentage cannot be negative, [{$percent}] given.");
        }

        $rounded = intdiv(abs($this->minor) * $percent * 2 + 100, 200);

        return new self($this->minor < 0 ? -$rounded : $rounded, $this->currency);
    }

    /**
     * Split the amount by the given ratios, e.g. [1, 1, 1] for three equal
     * shares, with parts that always sum exactly to the whole.
     *
     * Each part first gets its share rounded down. The minor units left over
     * then go one each to the parts with the largest remainders, the earlier
     * part first on a tie: 100 split three ways is 34, 33, 33.
     *
     * @return list<self>
     *
     * @throws InvalidArgumentException
     */
    public function allocate(int ...$ratios): array
    {
        $total = array_sum($ratios);

        if ($ratios === [] || $total <= 0 || min($ratios) < 0) {
            throw new InvalidArgumentException('Ratios must be zero or more, with at least one above zero.');
        }

        $whole = abs($this->minor);
        $shares = [];
        $remainders = [];

        foreach (array_values($ratios) as $index => $ratio) {
            $shares[$index] = intdiv($whole * $ratio, $total);
            $remainders[$index] = ($whole * $ratio) % $total;
        }

        $leftover = $whole - array_sum($shares);

        // arsort keeps equal remainders in their original order, so ties go to the earlier part.
        arsort($remainders);
        $extraUnitFor = array_flip(array_slice(array_keys($remainders), 0, $leftover));

        return array_map(function (int $index) use ($shares, $extraUnitFor): self {
            $share = $shares[$index] + (isset($extraUnitFor[$index]) ? 1 : 0);

            return new self($this->minor < 0 ? -$share : $share, $this->currency);
        }, array_keys($shares));
    }

    /**
     * Determine whether the amount equals another, currency included.
     *
     * @throws InvalidArgumentException
     */
    public function equals(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor === $other->minor;
    }

    /**
     * Get the amount as a decimal string in major units, e.g. "2500.00", or
     * "1500" for a currency without minor units.
     */
    public function toDecimal(): string
    {
        $digits = self::minorDigitsOf($this->currency);
        $sign = $this->minor < 0 ? '-' : '';

        if ($digits === 0) {
            return $sign.abs($this->minor);
        }

        $padded = str_pad((string) abs($this->minor), $digits + 1, '0', STR_PAD_LEFT);

        return $sign.substr($padded, 0, -$digits).'.'.substr($padded, -$digits);
    }

    /**
     * Format the amount for display, in the app's locale unless another is
     * given, e.g. "₱2,500.00" or "¥1,500".
     *
     * Display is the one place the amount becomes a float, because that is the
     * only number intl's formatter accepts. It is built from the exact decimal
     * string and never stored or calculated with.
     */
    public function format(?string $locale = null): string
    {
        $formatter = new NumberFormatter($locale ?? app()->getLocale(), NumberFormatter::CURRENCY);

        return (string) $formatter->formatCurrency((float) $this->toDecimal(), $this->currency);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency} with {$other->currency}.");
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function validCurrency(string $currency): string
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("[{$currency}] is not a three-letter ISO 4217 currency code.");
        }

        return $currency;
    }
}
