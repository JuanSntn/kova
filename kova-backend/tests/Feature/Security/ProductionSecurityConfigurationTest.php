<?php

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use RuntimeException;
use Tests\TestCase;

class ProductionSecurityConfigurationTest extends TestCase
{
    public function test_secure_production_session_configuration_is_accepted(): void
    {
        $this->useProductionEnvironment();
        $this->setSecureSessionConfiguration();

        (new AppServiceProvider($this->app))->boot();

        $this->addToAssertionCount(1);
    }

    public function test_an_insecure_production_session_configuration_fails_fast(): void
    {
        $this->useProductionEnvironment();
        $this->setSecureSessionConfiguration();
        config(['session.secure' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SESSION_SECURE_COOKIE=true');

        (new AppServiceProvider($this->app))->boot();
    }

    private function useProductionEnvironment(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
    }

    private function setSecureSessionConfiguration(): void
    {
        config([
            'app.debug' => false,
            'session.driver' => 'database',
            'session.encrypt' => true,
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);
    }
}
