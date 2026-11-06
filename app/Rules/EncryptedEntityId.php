<?php

namespace App\Rules;

use App\Domain\Identity\PublicId;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Validation\ValidationRule;

class EncryptedEntityId implements ValidationRule
{
    public function __construct(private string $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            if (PublicId::decode($this->type, $value) < 1) {
                $fail('The :attribute must be a valid encrypted identifier.');
            }
        } catch (DecryptException) {
            $fail('The :attribute must be a valid encrypted identifier.');
        }
    }
}
