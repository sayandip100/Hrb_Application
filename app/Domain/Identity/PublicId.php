<?php

namespace App\Domain\Identity;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class PublicId
{
    public static function encode(string $type, int|string|null $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return rtrim(strtr(Crypt::encryptString('master-data:v1:'.$type.':'.$id), '+/', '-_'), '=');
    }

    public static function decode(string $type, mixed $token): int
    {
        if (! is_string($token) || strlen($token) > 1024 || ! preg_match('/\A[A-Za-z0-9_-]+\z/', $token)) {
            throw new DecryptException('Invalid public identifier.');
        }

        $payload = Crypt::decryptString(strtr($token, '-_', '+/'));
        $prefix = 'master-data:v1:'.$type.':';
        if (! str_starts_with($payload, $prefix)) {
            throw new DecryptException('Invalid public identifier.');
        }

        $id = substr($payload, strlen($prefix));
        if (! preg_match('/\A(?:0|[1-9][0-9]*)\z/', $id)
            || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new DecryptException('Invalid public identifier.');
        }

        return (int) $id;
    }
}
