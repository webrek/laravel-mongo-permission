<?php

namespace Webrek\MongoPermission\Tests\Unit;

use Webrek\MongoPermission\PermissionRegistrar;
use Webrek\MongoPermission\Tests\TestCase;

class PermissionRegistrarTest extends TestCase
{
    public function test_get_team_id_returns_explicit_value_when_set(): void
    {
        $registrar = app(PermissionRegistrar::class);

        $registrar->setTeamId('teamA');

        $this->assertSame('teamA', $registrar->getTeamId());
    }

    public function test_get_team_id_returns_null_when_unset_and_no_resolver(): void
    {
        config()->set('permission.team_resolver', null);

        $registrar = app(PermissionRegistrar::class);

        $this->assertNull($registrar->getTeamId());
    }

    public function test_resolver_is_consulted_when_no_explicit_value(): void
    {
        config()->set('permission.team_resolver', fn () => 'fromResolver');

        $registrar = app(PermissionRegistrar::class);

        $this->assertSame('fromResolver', $registrar->getTeamId());
    }

    public function test_explicit_set_overrides_resolver(): void
    {
        config()->set('permission.team_resolver', fn () => 'fromResolver');

        $registrar = app(PermissionRegistrar::class);
        $registrar->setTeamId('explicit');

        $this->assertSame('explicit', $registrar->getTeamId());
    }

    public function test_global_helper_sets_team_id(): void
    {
        setPermissionsTeamId('viaHelper');

        $this->assertSame('viaHelper', app(PermissionRegistrar::class)->getTeamId());
    }

    public function test_registrar_is_a_singleton(): void
    {
        $a = app(PermissionRegistrar::class);
        $b = app(PermissionRegistrar::class);

        $this->assertSame($a, $b);
    }
}
