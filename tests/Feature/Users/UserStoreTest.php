<?php

namespace Tests\Feature;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_admin_user_without_role_id(): void
    {
        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/users', [
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'email' => 'ana@example.com',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Utilizador criado com sucesso.')
            ->assertJsonPath('data.first_name', 'Ana')
            ->assertJsonPath('data.last_name', 'Silva')
            ->assertJsonPath('data.email', 'ana@example.com')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $this->assertDatabaseHas('users', [
            'id' => $response->json('data.id'),
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'email' => 'ana@example.com',
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseCount('users', 2);
    }

    public function test_admin_cannot_create_user_with_existing_email(): void
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

        $existingUser = User::factory()->create([
            'first_name' => 'Ana',
            'last_name' => 'Original',
            'email' => 'ana@example.com',
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/users', [
            'first_name' => 'Outra',
            'last_name' => 'Ana',
            'email' => $existingUser->email,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 2);

        $this->assertDatabaseHas('users', [
            'id' => $existingUser->id,
            'first_name' => 'Ana',
            'last_name' => 'Original',
            'email' => 'ana@example.com',
            'role_id' => $staffRole->id,
        ]);
    }

    public function test_admin_cannot_create_user_with_role_id(): void
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

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/users', [
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'email' => 'ana@example.com',
            'role_id' => $staffRole->id,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role_id']);

        $this->assertDatabaseCount('users', 1);

        $this->assertDatabaseMissing('users', [
            'email' => 'ana@example.com',
        ]);
    }
}
