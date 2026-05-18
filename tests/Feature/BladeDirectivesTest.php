<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Webrek\MongoPermission\Models\Permission;
use Webrek\MongoPermission\Models\Role;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class BladeDirectivesTest extends TestCase
{
    public function test_role_directive_renders_when_user_has_role(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'admin']);
        $user->assignRole('admin');
        Auth::setUser($user->fresh());

        $rendered = Blade::render(
            "@role('admin') ok @endrole"
        );

        $this->assertSame('ok', trim($rendered));
    }

    public function test_role_directive_skips_when_user_lacks_role(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'admin']);
        Auth::setUser($user->fresh());

        $this->assertSame('', trim(Blade::render("@role('admin') ok @endrole")));
    }

    public function test_hasanyrole_directive(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'editor']);
        $user->assignRole('editor');
        Auth::setUser($user->fresh());

        $this->assertSame('yes', trim(Blade::render("@hasanyrole('admin|editor') yes @endhasanyrole")));
    }

    public function test_haspermission_directive(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Permission::create(['name' => 'edit']);
        $user->givePermissionTo('edit');
        Auth::setUser($user->fresh());

        $this->assertSame('yes', trim(Blade::render("@haspermission('edit') yes @endhaspermission")));
    }

    public function test_unlessrole_directive(): void
    {
        $user = TestUser::create(['name' => 'V']);
        Role::create(['name' => 'guest']);
        Auth::setUser($user->fresh());

        $this->assertSame('block', trim(Blade::render("@unlessrole('guest') block @endunlessrole")));
    }
}
