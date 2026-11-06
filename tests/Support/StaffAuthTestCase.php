<?php

namespace Tests\Support;

use App\Domain\Identity\PublicId;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

abstract class StaffAuthTestCase extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Staff authentication tests require isolated SQLite :memory:.');
        }

        config([
            'staff_auth.cache_store' => 'array',
            'sanctum.stateful' => ['localhost:5173'],
        ]);
        Http::preventStrayRequests();
        $this->withHeader('Origin', 'http://localhost:5173')->withCredentials();
    }

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Refusing to migrate a non-isolated database.');
        }
    }

    protected function migrateFreshUsing(): array
    {
        return ['--path' => [base_path('tests/Fixtures/Migrations')], '--realpath' => true];
    }

    protected function publicId(string $type, int $id): string
    {
        return PublicId::encode($type, $id);
    }

    protected function matchesPublicId(string $type, int $expected): Closure
    {
        return fn (mixed $value): bool => is_string($value) && PublicId::decode($type, $value) === $expected;
    }

    protected function browser(string $method, string $path, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $response = $this->json($method, $path, $data);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }

        return $response;
    }
}
