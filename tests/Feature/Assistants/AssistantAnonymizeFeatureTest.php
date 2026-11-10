<?php

namespace Tests\Feature\Assistants;

use App\Models\Absences\Absence;
use App\Models\Absences\AbsenceType;
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
        $disk = config('filesystems.documents_disk');
        Storage::fake($disk);
        Storage::disk($disk)->put('criminal_records/doc.pdf', 'fake-content');
        Storage::disk($disk)->put('absences_files/atestado.pdf', 'fake-absence-doc');

        $assistant = $this->createInactiveAssistant();
        $assistant->update(['criminal_record_path' => 'criminal_records/doc.pdf']);

        $absenceType = AbsenceType::create([
            'name' => 'Doença',
            'slug' => 'doenca',
            'requires_document' => true,
            'active' => true,
        ]);

        $absence = Absence::create([
            'assistant_id' => $assistant->id,
            'absence_type_id' => $absenceType->id,
            'valid_from' => now()->subDays(5)->toDateString(),
            'valid_until' => now()->subDays(4)->toDateString(),
            'total_days' => 2,
            'document_name' => 'atestado.pdf',
            'document_path' => 'absences_files/atestado.pdf',
            'status' => 'justified',
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson("/api/assistants/{$assistant->id}/anonymize");

        $response->assertOk()
            ->assertJsonPath('assistant.is_anonymized', true);

        $assistant->refresh();
        $absence->refresh();
        $this->assertTrue((bool) $assistant->is_anonymized);
        $this->assertSame('anon_' . $assistant->id, $assistant->internal_number);
        $this->assertNull($assistant->phone);
        $this->assertNull($assistant->nif);
        $this->assertNull($assistant->criminal_record_path);
        $this->assertNull($absence->document_path);
        $this->assertNull($absence->document_name);
        Storage::disk($disk)->assertMissing('criminal_records/doc.pdf');
        Storage::disk($disk)->assertMissing('absences_files/atestado.pdf');

        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson("/api/assistants/{$assistant->id}")
            ->assertOk()
            ->assertJsonPath('data.internal_number', '---');
    }
}

