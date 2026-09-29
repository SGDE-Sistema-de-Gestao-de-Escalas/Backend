<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_deactivate_staff_and_revoke_tokens(): void
    {
        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $staffRole = Role::factory()->create([
            'name' => 'Staff',
            'slug' => 'staff',
        ]);

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $staff = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $staff->createToken('browser');
        $staff->createToken('mobile');

        $this->assertSame(2, $staff->tokens()->count());

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/users/{$staff->id}")
            ->assertNoContent();

        $staff->refresh();
        $admin->refresh();

        $this->assertFalse($staff->is_active);
        $this->assertEquals($staffRole->id, $staff->role_id);
        $this->assertSame(0, $staff->tokens()->count());

        $this->assertTrue($admin->is_active);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $token = $admin->createToken('existing-access')->accessToken;

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/users/{$admin->id}")
            ->assertForbidden();

        $admin->refresh();

        $this->assertTrue($admin->is_active);
        $this->assertEquals($adminRole->id, $admin->role_id);

        $this->assertTrue(
            $admin->tokens()->whereKey($token->id)->exists()
        );

        $this->assertDatabaseCount('users', 2);
    }

    public function test_staff_cannot_deactivate_another_user(): void
    {
        $staffRole = Role::factory()->create([
            'name' => 'Staff',
            'slug' => 'staff',
        ]);

        $actor = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $target = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $token = $target->createToken('existing-access')->accessToken;

        Sanctum::actingAs($actor);

        $this->deleteJson("/api/users/{$target->id}")
            ->assertForbidden();

        $target->refresh();

        $this->assertTrue($target->is_active);

        $this->assertTrue(
            $target->tokens()->whereKey($token->id)->exists()
        );

        $this->assertDatabaseCount('users', 2);
    }
}