<?php

namespace App\Concerns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Every delete in the admin area needs a reason, written into the audit log
 * with it. A delete is soft, so the reason is what explains, later, why a row
 * that still exists stopped showing.
 */
trait RequiresDeletionReason
{
    /**
     * Get the validation rules for the reason.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'delete_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * Validate the input and return the trimmed reason.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    protected function validatedReason(array $input): string
    {
        $validated = Validator::make($input, $this->rules(), [
            'delete_reason.required' => __('Say why this is being deleted. The reason is kept in the audit log.'),
        ])->validate();

        return trim($validated['delete_reason']);
    }
}
