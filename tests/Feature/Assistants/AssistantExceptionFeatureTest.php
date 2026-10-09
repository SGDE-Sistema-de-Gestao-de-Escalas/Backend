<?php

namespace Tests\Feature\Assistants;

use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantException;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantExceptionFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private School $school;
    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff']);

        $this->school = School::create([
            'name' => 'Escola Teste',
            'code' => 'ET-001',
            'acronym' => 'ET',
            'active' => true,
        ]);

        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $staffUser = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $this->assistant = Assistant::create([
            'user_id' => $staffUser->id,
            'school_id' => $this->school->id,
            'internal_number' => 'AST-EXC-1',
        ]);
    }

    public function test_admin_can_create_exception(): void
    {
        $payload = [
            'assistant_id' => $this->assistant->id,
            'type' => 'specific_schedule',
            'valid_from' => '2026-10-15',
            'valid_until' => '2026-10-16',
            'start_time' => '09:00',
            'end_time' => '13:00',
            'description' => 'Consulta médica agendada',
        ];

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistant-exceptions', $payload);

        $response->assertCreated();
        $this->assertDatabaseHas('assistant_exceptions', [
            'assistant_id' => $this->assistant->id,
            'description' => 'Consulta médica agendada',
        ]);
    }

    public function test_admin_can_list_and_delete_exceptions(): void
    {
        $exception = AssistantException::create([
            'assistant_id' => $this->assistant->id,
            'type' => 'unavailable',
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-11-02',
            'description' => 'Formação Externa',
        ]);

        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/assistant-exceptions')
            ->assertOk()
            ->assertJsonFragment(['description' => 'Formação Externa']);

        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->deleteJson("/api/assistant-exceptions/{$exception->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('assistant_exceptions', ['id' => $exception->id]);
    }
}
