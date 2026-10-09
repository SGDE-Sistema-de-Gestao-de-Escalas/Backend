<?php

namespace Tests\Feature\Assistants;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssistantAnonymizeFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff']);

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
    }

    private function createInactiveAssistant(): Assistant
    {
        $staffRole = Role::where('slug', 'staff')->first();
        $user = User::factory()->create([
            'role_id' => $staffRole->id,
            'first_name' => 'João',
            'last_name' => 'Silva',
            'email' => 'joao.silva@teste.pt',
            'is_active' => false,
        ]);
        $user->delete(); // inativado / soft deleted

        $assistant = Assistant::create([
            'user_id' => $user->id,
            'school_id' => $this->school->id,
            'internal_number' => 'AST-ANON-1',
            'phone' => '912345678',
            'nif' => '123456789',
        ]);
        $assistant->delete();

        return $assistant;
    }

    public function test_can_check_anonymization_eligibility(): void
    {
        $assistant = $this->createInactiveAssistant();

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson("/api/assistants/{$assistant->id}/can-anonymize");

        $response->assertOk()
            ->assertJson([
                'can_anonymize' => true,
                'has_future_schedules' => false,
            ]);
    }

    public function test_admin_can_anonymize_inactive_assistant(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('criminal_records/doc.pdf', 'fake-content');

        $assistant = $this->createInactiveAssistant();
        $assistant->update(['criminal_record_path' => 'criminal_records/doc.pdf']);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson("/api/assistants/{$assistant->id}/anonymize");

        $response->assertOk()
            ->assertJsonPath('assistant.is_anonymized', true);

        $assistant->refresh();
        $this->assertTrue((bool) $assistant->is_anonymized);
        $this->assertNull($assistant->phone);
        $this->assertNull($assistant->nif);
        $this->assertNull($assistant->criminal_record_path);
        Storage::disk('local')->assertMissing('criminal_records/doc.pdf');
    }
}

