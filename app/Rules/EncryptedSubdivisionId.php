<?php

namespace App\Rules;

use App\Domain\Identity\PublicId;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Validation\ValidationRule;

class EncryptedSubdivisionId implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $id = PublicId::decode('subdivision', $value);
            if ($id < 1 || $id > 2147483647) {
                $fail('The :attribute must be a valid encrypted Sub-Division ID.');
            }
        } catch (DecryptException) {
            $fail('The :attribute must be a valid encrypted Sub-Division ID.');
        }
    }
}
