<?php

namespace Tests\Feature\Security;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F-25: GET /assistants/export era lido como "mostrar o assistente com id 'export'"
 * (404), porque a rota estava registada depois do apiResource.
 */
class AssistantCsvRoutesTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        $this->staff = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);

        Assistant::create([
            'school_id' => $this->school->id,
            'user_id' => $this->staff->id,
            'internal_number' => 'A001',
            'available_for_transfer' => false,
        ]);
    }

    private function export(User $user, string $query = '')
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-School-ID', $this->school->id)
            ->get('/api/assistants/export'.$query);
    }

    public function test_admin_can_export_the_assistants_csv(): void
    {
        $response = $this->export($this->admin, '?status=all');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('internal_number', $response->streamedContent());
        $this->assertStringContainsString('A001', $response->streamedContent());
    }

    public function test_staff_cannot_export_the_assistants_csv(): void
    {
        // 403 exato: um 404 aqui esconderia o problema das rotas.
        $this->export($this->staff)->assertForbidden();
    }

    public function test_an_assistant_can_still_be_shown_by_uuid(): void
    {
        $assistant = Assistant::firstOrFail();

        Sanctum::actingAs($this->admin);
        $this->withHeader('X-School-ID', $this->school->id)
            ->getJson("/api/assistants/{$assistant->id}")
            ->assertOk();
    }
}
