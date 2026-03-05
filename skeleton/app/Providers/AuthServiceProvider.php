<?php

declare(strict_types=1);

namespace App\Providers;

use Fabriq\Kernel\ServiceProvider;
use Fabriq\Security\JwtAuthenticator;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(JwtAuthenticator::class, function () {
            $config = $this->app->config();
            return new JwtAuthenticator(
                secret: (string) $config->get('auth.jwt.secret', ''),
                algorithm: (string) $config->get('auth.jwt.algorithm', 'HS256'),
                ttl: (int) $config->get('auth.jwt.ttl', 3600),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
