<?php

namespace App\Casts;

use App\ValueObjects\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/**
 * Reads and writes an integer minor-units column as a Money.
 *
 * `'price' => MoneyCast::class.':price_minor,currency'` reads the `price_minor`
 * column with the currency in the row's own `currency` column, as the central
 * billing tables store it. Leave the currency column out for a tenant table:
 * an agency trades in one currency, kept only in `tenants.data`, so the cast
 * uses `tenant('currency')` and refuses to store an amount in any other.
 *
 * Only a Money can be assigned, never a bare number, so a float cannot reach
 * the column through the model.
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
class MoneyCast implements CastsAttributes
{
    /**
     * Do not cache the Money on the model: a cached object is written back on
     * save and would undo a direct change to the minor-units column.
     */
    public bool $withoutObjectCaching = true;

    public function __construct(
        private string $minorColumn,
        private ?string $currencyColumn = null,
    ) {}

    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws LogicException
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $minor = $attributes[$this->minorColumn] ?? null;

        if ($minor === null) {
            return null;
        }

        return Money::ofMinor((int) $minor, $this->currencyOf($attributes));
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, int|string|null>
     *
     * @throws InvalidArgumentException|LogicException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$this->minorColumn => null];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException("[{$key}] only accepts a Money; build one with Money::ofMinor() or Money::parse().");
        }

        if ($this->currencyColumn !== null) {
            return [$this->minorColumn => $value->minor, $this->currencyColumn => $value->currency];
        }

        $agencyCurrency = $this->agencyCurrency();

        if ($value->currency !== $agencyCurrency) {
            throw new InvalidArgumentException("[{$key}] is in {$agencyCurrency}, the agency's currency, not {$value->currency}.");
        }

        return [$this->minorColumn => $value->minor];
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws LogicException
     */
    private function currencyOf(array $attributes): string
    {
        if ($this->currencyColumn === null) {
            return $this->agencyCurrency();
        }

        $currency = $attributes[$this->currencyColumn] ?? null;

        if (! is_string($currency)) {
            throw new LogicException("The [{$this->currencyColumn}] column holds no currency.");
        }

        return $currency;
    }

    /**
     * @throws LogicException
     */
    private function agencyCurrency(): string
    {
        $currency = tenant('currency');

        if (! is_string($currency)) {
            throw new LogicException('An amount in the agency\'s currency needs an initialized agency with a currency set.');
        }

        return $currency;
    }
}
