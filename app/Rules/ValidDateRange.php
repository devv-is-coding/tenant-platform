<?php

namespace App\Rules;

use App\ValueObjects\DateRange;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Translation\PotentiallyTranslatedString;
use InvalidArgumentException;

/**
 * Validates an end date against its start date by building the DateRange the
 * form will store, so validation and the value object can never disagree.
 *
 * Put it on the end field and name the start field:
 * `'ends_on' => ['required', new ValidDateRange('starts_on')]`.
 */
class ValidDateRange implements DataAwareRule, ValidationRule
{
    /**
     * All of the data under validation.
     *
     * @var array<string, mixed>
     */
    private array $data = [];

    public function __construct(private string $startField) {}

    /**
     * Set the data under validation.
     *
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $startsOn = Arr::get($this->data, $this->startField);

        if (! $this->isDate($value)) {
            $fail('The :attribute must be a date in the Y-m-d format.');

            return;
        }

        // The start field's own rules report a bad start date.
        if (! $this->isDate($startsOn)) {
            return;
        }

        try {
            DateRange::of($startsOn, $value);
        } catch (InvalidArgumentException) {
            $fail('The :attribute cannot be before the start date.');
        }
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private function isDate(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        try {
            DateRange::of($value, $value);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
