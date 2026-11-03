<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

class EnforceCsrfForTesting extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
