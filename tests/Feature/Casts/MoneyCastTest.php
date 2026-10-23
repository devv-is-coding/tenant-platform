<?php

use App\Casts\MoneyCast;
use App\Models\Tenant;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * A model with a price in its own currency column, as the central billing
 * tables store amounts.
 */
function modelWithCurrencyColumn(): Model
{
    return new class extends Model
    {
        protected function casts(): array
        {
            return ['price' => MoneyCast::class.':price_minor,currency'];
        }
    };
}

/**
 * A model with a price in the agency's currency, as tenant tables store amounts.
 */
function modelInAgencyCurrency(): Model
{
    return new class extends Model
    {
        protected function casts(): array
        {
            return ['price' => MoneyCast::class.':price_minor'];
        }
    };
}

test('an amount is stored as minor units with its currency and reads back the same', function () {
    $model = modelWithCurrencyColumn();

    $model->price = Money::parse('30.01', 'PHP');

    expect($model->getAttributes())->toBe(['price_minor' => 3001, 'currency' => 'PHP'])
        ->and($model->price)->toEqual(Money::ofMinor(3001, 'PHP'));
});

test('a change to the minor units is not undone by an amount read earlier', function () {
    $model = modelWithCurrencyColumn();
    $model->price = Money::ofMinor(3001, 'PHP');
    $model->price;

    $model->price_minor = 5000;

    expect($model->getAttributes()['price_minor'])->toBe(5000)
        ->and($model->price?->minor)->toBe(5000);
});

test('no amount is stored as null', function () {
    $model = modelWithCurrencyColumn();

    $model->price = null;

    expect($model->getAttributes())->toBe(['price_minor' => null])
        ->and($model->price)->toBeNull();
});

test('a bare number is refused, so a float cannot reach the column', function (mixed $value) {
    $model = modelWithCurrencyColumn();

    expect(fn () => $model->price = $value)->toThrow(InvalidArgumentException::class, 'only accepts a Money');
})->with([
    'a float' => [30.01],
    'an integer' => [3001],
    'a string' => ['30.01'],
]);

test('a tenant amount reads and stores in the agency\'s currency', function () {
    $agency = Tenant::factory()->create(['currency' => 'JPY']);

    $price = withinTenant($agency, function (): ?Money {
        $model = modelInAgencyCurrency();
        $model->price = Money::ofMinor(1500, 'JPY');

        expect($model->getAttributes())->toBe(['price_minor' => 1500]);

        return $model->price;
    });

    expect($price)->toEqual(Money::ofMinor(1500, 'JPY'));
});

test('a tenant amount in another currency is refused', function () {
    $agency = Tenant::factory()->create(['currency' => 'JPY']);

    withinTenant($agency, function (): void {
        $model = modelInAgencyCurrency();

        expect(fn () => $model->price = Money::ofMinor(1500, 'PHP'))
            ->toThrow(InvalidArgumentException::class, 'in JPY, the agency\'s currency, not PHP');
    });
});

test('a tenant amount outside an agency fails loudly instead of guessing a currency', function () {
    $model = modelInAgencyCurrency();

    expect(fn () => $model->price = Money::ofMinor(1500, 'JPY'))->toThrow(LogicException::class);
});
