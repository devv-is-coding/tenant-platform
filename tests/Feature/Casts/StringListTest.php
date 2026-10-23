<?php

use App\Casts\StringList;
use Illuminate\Database\Eloquent\Model;

/**
 * A model with one StringList attribute, so the cast runs as Eloquent runs it.
 */
function modelWithStringList(): Model
{
    return new class extends Model
    {
        protected function casts(): array
        {
            return ['inclusions' => StringList::class];
        }
    };
}

test('a list of strings is stored as a JSON array and reads back the same', function () {
    $model = modelWithStringList();

    $model->inclusions = ['Hotel pickup', 'Lunch at Apo Island', 'Snorkel gear'];

    expect($model->getAttributes()['inclusions'])->toBe('["Hotel pickup","Lunch at Apo Island","Snorkel gear"]')
        ->and($model->inclusions)->toBe(['Hotel pickup', 'Lunch at Apo Island', 'Snorkel gear']);
});

test('an empty list and no list both stay as they are', function (?array $value, ?string $stored) {
    $model = modelWithStringList();

    $model->inclusions = $value;

    expect($model->getAttributes()['inclusions'])->toBe($stored)
        ->and($model->inclusions)->toBe($value);
})->with([
    'empty list' => [[], '[]'],
    'null' => [null, null],
]);

test('anything but a list of strings is refused rather than stored', function (mixed $value) {
    $model = modelWithStringList();

    expect(fn () => $model->inclusions = $value)->toThrow(InvalidArgumentException::class);
})->with([
    'a number in the list' => [['Lunch', 3]],
    'a nested list' => [['Lunch', ['Snorkel gear']]],
    'a keyed map' => [['meal' => 'Lunch']],
    'a bare string' => ['Lunch'],
]);
