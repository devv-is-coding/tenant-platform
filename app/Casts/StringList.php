<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

/**
 * Stores a free-form list of strings, such as a tour's inclusions or a
 * hotel's amenities, as a JSON array.
 *
 * The list is checked at the model boundary, which the legacy's untyped
 * key/value rows never did (defect 6): only a list of strings is accepted, so
 * a number, a nested array or a keyed map is refused rather than stored.
 *
 * @implements CastsAttributes<list<string>|null, mixed>
 */
class StringList implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<string>|null
     *
     * @throws JsonException
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        /** @var list<string> */
        return json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidArgumentException|JsonException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("[{$key}] must be a list of strings.");
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException("[{$key}] must be a list of strings; found a ".get_debug_type($item).'.');
            }
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
