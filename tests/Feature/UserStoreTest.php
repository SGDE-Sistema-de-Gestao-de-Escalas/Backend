<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_staff_user(): void
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
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'role_id' => $staffRole->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ana Silva')
            ->assertJsonPath('data.email', 'ana@example.com')
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $this->assertDatabaseHas('users', [
            'id' => $response->json('data.id'),
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'role_id' => $staffRole->id,
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
            'name' => 'Ana Original',
            'email' => 'ana@example.com',
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/users', [
            'name' => 'Outra Ana',
            'email' => $existingUser->email,
            'role_id' => $staffRole->id,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 2);

        $this->assertDatabaseHas('users', [
            'id' => $existingUser->id,
            'name' => 'Ana Original',
            'email' => 'ana@example.com',
            'role_id' => $staffRole->id,
        ]);
    }
}