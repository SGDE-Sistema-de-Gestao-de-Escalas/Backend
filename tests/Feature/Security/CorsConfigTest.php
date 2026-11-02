<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * F-14: com CORS_ALLOWED_ORIGINS= (vazio, como no .env.example) a lista de
 * origens ficava vazia e o plano B (FRONTEND_URL) nunca entrava em ação.
 */
class CorsConfigTest extends TestCase
{
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $this->setEnv($name, $value);
        }

        parent::tearDown();
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv("$name=$value");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    private function corsWith(?string $origins, ?string $frontend): array
    {
        foreach (['CORS_ALLOWED_ORIGINS', 'FRONTEND_URL'] as $name) {
            $this->saved[$name] ??= $_ENV[$name] ?? (getenv($name) === false ? null : getenv($name));
        }

        $this->setEnv('CORS_ALLOWED_ORIGINS', $origins);
        $this->setEnv('FRONTEND_URL', $frontend);

        return require config_path('cors.php');
    }

    public function test_an_empty_variable_falls_back_to_the_frontend_url(): void
    {
        $config = $this->corsWith('', 'https://app.escola.pt');

        $this->assertSame(['https://app.escola.pt'], $config['allowed_origins']);
    }

    public function test_explicit_origins_are_used_and_trimmed(): void
    {
        $config = $this->corsWith('https://app.escola.pt, https://admin.escola.pt', 'https://outro.pt');

        $this->assertSame(['https://app.escola.pt', 'https://admin.escola.pt'], $config['allowed_origins']);
    }

    public function test_wildcard_origin_is_never_produced_by_default(): void
    {
        $config = $this->corsWith(null, null);

        $this->assertNotContains('*', $config['allowed_origins']);
        $this->assertTrue($config['supports_credentials']);
    }

    public function test_preflight_is_cached_and_headers_are_explicit(): void
    {
        $config = $this->corsWith('https://app.escola.pt', null);

        $this->assertGreaterThan(0, $config['max_age']);
        $this->assertNotContains('*', $config['allowed_headers']);
        $this->assertNotContains('*', $config['allowed_methods']);
        $this->assertContains('X-School-ID', $config['allowed_headers']);
        $this->assertContains('Content-Disposition', $config['exposed_headers']);
    }

    public function test_a_real_preflight_from_the_frontend_is_accepted(): void
    {
        config(['cors.allowed_origins' => ['https://app.escola.pt']]);

        $response = $this->call('OPTIONS', '/api/me', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.escola.pt',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-school-id',
        ]);

        $response->assertSuccessful();
        $response->assertHeader('Access-Control-Allow-Origin', 'https://app.escola.pt');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertStringContainsStringIgnoringCase('x-school-id', $response->headers->get('Access-Control-Allow-Headers'));
        $response->assertHeader('Access-Control-Max-Age', '3600');
    }
}
