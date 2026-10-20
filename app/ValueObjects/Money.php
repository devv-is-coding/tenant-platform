<?php

namespace App\ValueObjects;

use InvalidArgumentException;
use NumberFormatter;

/**
 * An amount of money held as integer minor units, so no float ever touches a
 * stored amount - the cure for the legacy's `30.0099...` corruption (defect 2).
 *
 * Deliberately minimal for now: building from minor units, parsing a typed
 * amount and formatting for display. Allocation, arithmetic and zero-decimal
 * currencies arrive with the rest of the money primitives in Phase 2; until
 * then every currency is assumed to have two minor digits.
 */
final readonly class Money
{
    /**
     * The number of minor digits every supported currency has.
     */
    private const MINOR_DIGITS = 2;

    private function __construct(public int $minor, public string $currency) {}

    /**
     * Create an amount from integer minor units, e.g. 250000 for 2,500.00.
     */
    public static function ofMinor(int $minor, string $currency): self
    {
        return new self($minor, $currency);
    }

    /**
     * Create an amount from a typed decimal string such as "2500" or "30.01".
     *
     * The string is converted with string and integer arithmetic only. Anything
     * other than digits with at most two decimal places is refused rather than
     * rounded, so a typo cannot silently become a different charge.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $amount, string $currency): self
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,'.self::MINOR_DIGITS.'}))?$/', $amount, $matches) !== 1) {
            throw new InvalidArgumentException("[{$amount}] is not an amount with at most ".self::MINOR_DIGITS.' decimal places.');
        }

        $major = ltrim($matches[1], '0');
        $fraction = str_pad($matches[2] ?? '', self::MINOR_DIGITS, '0');
        $digits = ltrim($major.$fraction, '0');

        if (strlen($digits) >= strlen((string) PHP_INT_MAX)) {
            throw new InvalidArgumentException("[{$amount}] is too large.");
        }

        return new self((int) $digits, $currency);
    }

    /**
     * Get the amount as a decimal string in major units, e.g. "2500.00".
     */
    public function toDecimal(): string
    {
        $padded = str_pad((string) abs($this->minor), self::MINOR_DIGITS + 1, '0', STR_PAD_LEFT);

        return ($this->minor < 0 ? '-' : '')
            .substr($padded, 0, -self::MINOR_DIGITS).'.'.substr($padded, -self::MINOR_DIGITS);
    }

    /**
     * Format the amount for display in the app's locale, e.g. "₱2,500.00".
     *
     * Display is the one place the amount becomes a float, because that is the
     * only number intl's formatter accepts. It is built from the exact decimal
     * string and never stored or calculated with.
     */
    public function format(): string
    {
        $formatter = new NumberFormatter(app()->getLocale(), NumberFormatter::CURRENCY);

        return (string) $formatter->formatCurrency((float) $this->toDecimal(), $this->currency);
    }
}
