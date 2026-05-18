<?php

namespace Webrek\MongoPermission\Tests\Unit;

use Webrek\MongoPermission\Guard;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class GuardTest extends TestCase
{
    public function test_returns_explicit_guard_when_passed(): void
    {
        $this->assertSame('api', Guard::resolveForModel(new TestUser, 'api'));
    }

    public function test_falls_back_to_model_property(): void
    {
        $user = new class extends TestUser {
            protected string $guard_name = 'api';
        };

        $this->assertSame('api', Guard::resolveForModel($user));
    }

    public function test_falls_back_to_auth_default(): void
    {
        config()->set('auth.defaults.guard', 'web');

        $this->assertSame('web', Guard::resolveForModel(new TestUser));
    }

    public function test_falls_back_to_config_default(): void
    {
        config()->set('auth.defaults.guard', null);
        config()->set('permission.default_guard', 'admin');

        $this->assertSame('admin', Guard::resolveForModel(new TestUser));
    }
}
