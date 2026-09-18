<?php

namespace Webrek\MongoPermission\Tests\Feature;

use Webrek\MongoPermission\Support\AtomicArray;
use Webrek\MongoPermission\Tests\Models\TestUser;
use Webrek\MongoPermission\Tests\TestCase;

class AtomicGrantBoundaryTest extends TestCase
{
    public function test_conflicting_write_is_reloaded_and_preserved_before_retry(): void
    {
        $user = TestUser::create(['name' => 'Original', 'role_ids' => ['first']]);
        $user->name = 'Unsaved name';
        $interfered = false;
        [$before, $after] = AtomicArray::mutate($user, 'role_ids', function (array $roles) use ($user, &$interfered) {
            if (! $interfered) {
                $interfered = true;
                TestUser::whereKey($user->id)->update(['role_ids' => ['first', 'concurrent']]);
            }

            return [...$roles, 'requested'];
        });
        $this->assertSame(['first', 'concurrent'], $before);
        $this->assertSame(['first', 'concurrent', 'requested'], $after);
        $this->assertSame($after, $user->fresh()->role_ids);
        $this->assertSame($after, $user->role_ids);
        $this->assertFalse($user->isDirty('role_ids'));
        $this->assertTrue($user->isDirty('name'));
        $this->assertSame('Original', $user->fresh()->name);
    }

    public function test_missing_field_conflict_does_not_overwrite_concurrent_initialization(): void
    {
        $user = TestUser::create(['name' => 'Reader']);
        $interfered = false;
        AtomicArray::mutate($user, 'permission_ids', function (array $permissions) use ($user, &$interfered) {
            if (! $interfered) {
                $interfered = true;
                TestUser::whereKey($user->id)->update(['permission_ids' => ['concurrent']]);
            }

            return [...$permissions, 'requested'];
        });
        $this->assertSame(['concurrent', 'requested'], $user->fresh()->permission_ids);
    }

    public function test_unsaved_model_is_persisted_and_sparse_transform_output_becomes_a_list(): void
    {
        $user = new TestUser(['name' => 'New']);
        [$before, $after] = AtomicArray::mutate($user, 'role_ids', fn () => [4 => 'a', 9 => 'b']);
        $this->assertTrue($user->exists);
        $this->assertSame([], $before);
        $this->assertSame(['a', 'b'], $after);
        $this->assertSame(['a', 'b'], $user->fresh()->role_ids);
    }

    public function test_continuous_contention_fails_instead_of_overwriting_other_writes(): void
    {
        $user = TestUser::create(['name' => 'Reader', 'role_ids' => []]);
        $iteration = 0;
        try {
            AtomicArray::mutate($user, 'role_ids', function (array $roles) use ($user, &$iteration) {
                TestUser::whereKey($user->id)->update(['role_ids' => ['other-'.++$iteration]]);

                return [...$roles, 'requested'];
            });
            $this->fail('Continuous contention must not report success');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('retry limit', $e->getMessage());
            $this->assertSame(['other-'.$iteration], $user->fresh()->role_ids);
        }
    }
}
