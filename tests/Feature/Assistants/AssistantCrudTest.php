<?php

namespace Tests\Feature\Assistants;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente']);

        $this->school = School::create([
            'name' => 'Escola Principal',
            'code' => 'ESC-001',
            'acronym' => 'EP',
            'address' => 'Rua da Escola',
            'phone' => '210000000',
            'active' => true,
        ]);

        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $this->staff = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);
    }

    private function createAssistant(string $internal = 'AST-100'): Assistant
    {
        $staffRole = Role::where('slug', 'staff')->first();
        $user = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        return Assistant::create([
            'user_id' => $user->id,
            'school_id' => $this->school->id,
            'internal_number' => $internal,
        ]);
    }

    public function test_guest_cannot_access_assistants(): void
    {
        $this->getJson('/api/assistants')->assertUnauthorized();
        $this->postJson('/api/assistants', [])->assertUnauthorized();
    }

    public function test_staff_cannot_list_assistants(): void
    {
        $this->actingAs($this->staff)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/assistants')
            ->assertForbidden();
    }

    public function test_admin_can_list_assistants_filtered_by_school(): void
    {
        $this->createAssistant('AST-1');
        $this->createAssistant('AST-2');

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/assistants')
            ->assertOk();

        $response->assertJsonCount(2, 'data');
    }

    public function test_admin_can_search_assistants_by_internal_number(): void
    {
        $this->createAssistant('AST-SEARCH-1');
        $this->createAssistant('AST-OTHER-2');

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/assistants?search=SEARCH')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.internal_number', 'AST-SEARCH-1');
    }

    public function test_admin_can_show_assistant(): void
    {
        $assistant = $this->createAssistant('AST-SHOW-1');

        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson("/api/assistants/{$assistant->id}")
            ->assertOk()
            ->assertJsonPath('data.internal_number', 'AST-SHOW-1');
    }


    public function test_admin_can_delete_assistant(): void
    {
        $assistant = $this->createAssistant('AST-DEL-1');
        $assistantUser = $assistant->user;
        $assistantUser->createToken('test-token');

        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/assistants/{$assistant->id}")
            ->assertOk()
            ->assertJson(['message' => 'Assistente eliminado com sucesso.']);

        $this->assertSoftDeleted('assistants', ['id' => $assistant->id]);
        $this->assertSoftDeleted('users', ['id' => $assistantUser->id]);
        $this->assertFalse($assistantUser->fresh()->is_active);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}

