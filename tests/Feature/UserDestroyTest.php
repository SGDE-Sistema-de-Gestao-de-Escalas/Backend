<?php

namespace Tests\Feature;

use App\Models\Auth\Role;
use App\Models\Auth\User;
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

        $this->postJson("/api/users/{$staff->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('message', 'Utilizador desativado com sucesso.');

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

        $this->postJson("/api/users/{$admin->id}/deactivate")
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

        $this->postJson("/api/users/{$target->id}/deactivate")
            ->assertForbidden();

        $target->refresh();

        $this->assertTrue($target->is_active);

        $this->assertTrue(
            $target->tokens()->whereKey($token->id)->exists()
        );

        $this->assertDatabaseCount('users', 2);
    }

    public function test_admin_can_permanently_delete_user_without_history(): void
    {
        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $target = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $token = $target->createToken('existing-access')->accessToken;

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Utilizador eliminado definitivamente com sucesso.')
            ->assertJsonPath('delete_action', 'hard_delete');

        $this->assertDatabaseMissing('users', [
            'id' => $target->id,
        ]);

        $this->assertNull(
            User::withTrashed()->find($target->id)
        );

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->id,
        ]);

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertDatabaseCount('users', 1);
    }
}
