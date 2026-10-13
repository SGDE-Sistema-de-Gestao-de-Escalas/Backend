<?php

namespace Tests\Feature\Absences;

use App\Models\Absences\Absence;
use App\Models\Absences\AbsenceType;
use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AbsenceFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private School $school;
    private Assistant $assistant;
    private AbsenceType $absenceType;
    private AbsenceType $absenceTypeWithDoc;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

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

        $staffUser = User::factory()->create([
            'role_id' => $staffRole->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'is_active' => true,
        ]);

        $this->staff = $staffUser;

        $this->assistant = Assistant::create([
            'user_id' => $staffUser->id,
            'school_id' => $this->school->id,
            'internal_number' => 'AST-001',
        ]);

        $this->absenceType = AbsenceType::create([
            'name' => 'Assuntos Pessoais',
            'requires_document' => false,
        ]);

        $this->absenceTypeWithDoc = AbsenceType::create([
            'name' => 'Doença Comprovada',
            'requires_document' => true,
        ]);
    }

    public function test_guest_cannot_access_absences(): void
    {
        $this->getJson('/api/absences')->assertUnauthorized();
        $this->postJson('/api/absences', [])->assertUnauthorized();
    }

    public function test_staff_cannot_create_absences(): void
    {
        $payload = [
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-10-15',
        ];

        $this->actingAs($this->staff)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->postJson('/api/absences', $payload)
            ->assertForbidden();
    }

    public function test_admin_can_create_absence_without_document(): void
    {
        $payload = [
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-10-15',
            'valid_until' => '2026-10-17',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'justification' => 'Motivo de consulta médica.',
            'notes' => 'Avisou com antecedência.',
            'status' => 'pending',
        ];

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->postJson('/api/absences', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.assistant_id', (string) $this->assistant->id)
            ->assertJsonPath('data.assistant', 'Maria Santos')
            ->assertJsonPath('data.total_days', 3)
            ->assertJsonPath('data.days', 3)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.created_by_user_id', (string) $this->admin->id);

        $this->assertDatabaseHas('absences', [
            'assistant_id' => $this->assistant->id,
            'status' => 'pending',
            'total_days' => 3,
            'created_by_user_id' => $this->admin->id,
        ]);
    }

    public function test_admin_can_create_absence_with_document_and_download_it(): void
    {
        $file = UploadedFile::fake()->create('atestado.pdf', 200, 'application/pdf');

        $payload = [
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceTypeWithDoc->id,
            'valid_from' => '2026-10-20',
            'valid_until' => '2026-10-20',
            'status' => 'justified',
            'document' => $file,
        ];

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->postJson('/api/absences', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'justified')
            ->assertJsonPath('data.total_days', 1)
            ->assertJsonPath('data.justified_by_user_id', (string) $this->admin->id);

        $absenceId = $response->json('data.id');
        $absence = Absence::findOrFail($absenceId);

        $this->assertNotNull($absence->document_path);
        Storage::disk('local')->assertExists($absence->document_path);

        // Download document pelo admin
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->get("/api/absences/{$absenceId}/document")
            ->assertOk();
    }

    public function test_staff_can_view_own_absences_and_download_own_document(): void
    {
        $file = UploadedFile::fake()->create('comprovativo.pdf', 150, 'application/pdf');
        $filePath = Storage::disk('local')->putFile('absences', $file);

        $ownAbsence = Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-10-10',
            'valid_until' => '2026-10-10',
            'total_days' => 1,
            'status' => 'pending',
            'document_path' => $filePath,
        ]);

        // Outro assistente
        $otherStaffUser = User::factory()->create([
            'role_id' => $this->staff->role_id,
            'first_name' => 'João',
            'last_name' => 'Silva',
            'is_active' => true,
        ]);
        $otherAssistant = Assistant::create([
            'user_id' => $otherStaffUser->id,
            'school_id' => $this->school->id,
            'internal_number' => 'AST-002',
        ]);
        $otherFile = UploadedFile::fake()->create('outro_doc.pdf', 150, 'application/pdf');
        $otherFilePath = Storage::disk('local')->putFile('absences', $otherFile);
        $otherAbsence = Absence::create([
            'assistant_id' => $otherAssistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-10-12',
            'valid_until' => '2026-10-12',
            'total_days' => 1,
            'status' => 'pending',
            'document_path' => $otherFilePath,
        ]);

        // Assistente lista ausências: vê apenas a sua própria
        $this->actingAs($this->staff)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->getJson('/api/absences')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $ownAbsence->id);

        // Assistente visualiza a sua própria ausência
        $this->actingAs($this->staff)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->getJson("/api/absences/{$ownAbsence->id}")
            ->assertOk();

        // Assistente não pode visualizar a ausência de outro assistente
        $this->actingAs($this->staff)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->getJson("/api/absences/{$otherAbsence->id}")
            ->assertForbidden();

        // Assistente faz download do seu próprio documento
        $this->actingAs($this->staff)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->get("/api/absences/{$ownAbsence->id}/document")
            ->assertOk();

        // Assistente NÃO pode fazer download do documento de outro assistente
        $this->actingAs($this->staff)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->get("/api/absences/{$otherAbsence->id}/document")
            ->assertForbidden();
    }

    public function test_staff_can_attach_document_and_notes_to_own_absence(): void
    {
        $absence = Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-10-10',
            'valid_until' => '2026-10-10',
            'total_days' => 1,
            'status' => 'pending',
            'document_path' => null,
        ]);

        $file = UploadedFile::fake()->create('comprovativo.pdf', 100, 'application/pdf');

        // Assistente anexa o documento e notas à sua falta
        $response = $this->actingAs($this->staff)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->putJson("/api/absences/{$absence->id}", [
                'document' => $file,
                'justification' => 'Consulta no centro de saúde.',
                'notes' => 'Comprovativo anexado pelo assistente.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.justification', 'Consulta no centro de saúde.')
            ->assertJsonPath('data.notes', 'Comprovativo anexado pelo assistente.');

        $this->assertNotNull($response->json('data.document_path'));

        // O estado permanece pending
        $this->assertEquals('pending', $absence->fresh()->status);
    }

    public function test_staff_cannot_change_status_dates_or_assistant(): void
    {
        $absence = Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-10-10',
            'valid_until' => '2026-10-10',
            'total_days' => 1,
            'status' => 'pending',
        ]);

        // Assistente tenta alterar o status para justified -> 422
        $this->actingAs($this->staff)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->putJson("/api/absences/{$absence->id}", [
                'status' => 'justified',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // Assistente tenta alterar datas -> 422
        $this->actingAs($this->staff)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->putJson("/api/absences/{$absence->id}", [
                'valid_from' => '2026-10-25',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_from']);

        // Assistente tenta apagar a ausência -> 403
        $this->actingAs($this->staff)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->deleteJson("/api/absences/{$absence->id}")
            ->assertForbidden();
    }

    public function test_validation_requires_document_when_type_requires_it_and_status_is_justified(): void
    {
        $payload = [
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceTypeWithDoc->id,
            'valid_from' => '2026-10-20',
            'valid_until' => '2026-10-20',
            'status' => 'justified',
        ];

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->postJson('/api/absences', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['document']);

        // Se estiver como pending, não falha mesmo sem documento
        $payload['status'] = 'pending';
        $responsePending = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->postJson('/api/absences', $payload);

        $responsePending->assertCreated();
    }

    public function test_validation_fails_if_assistant_belongs_to_another_school(): void
    {
        $otherSchool = School::create([
            'name' => 'Escola Secundária B',
            'code' => 'ESC-002',
            'acronym' => 'EB',
            'address' => 'Rua B',
            'phone' => '210000001',
            'active' => true,
        ]);

        $payload = [
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-10-15',
        ];

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $otherSchool->id)
            ->postJson('/api/absences', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['assistant_id']);
    }

    public function test_admin_can_list_and_filter_absences_by_status_and_school(): void
    {
        Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-10-10',
            'valid_until' => '2026-10-10',
            'total_days' => 1,
            'status' => 'pending',
        ]);

        Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-10-12',
            'valid_until' => '2026-10-12',
            'total_days' => 1,
            'status' => 'justified',
        ]);

        // Filter status=pending
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->getJson('/api/absences?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending');

        // Filter status=justified
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->getJson('/api/absences?status=justified')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'justified');

        // Filter status=all
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->getJson('/api/absences?status=all')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_update_absence_status_and_remove_document(): void
    {
        $file = UploadedFile::fake()->create('atestado.pdf', 100, 'application/pdf');
        $filePath = Storage::disk('local')->putFile('absences', $file);

        $absence = Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-10-10',
            'valid_until' => '2026-10-10',
            'total_days' => 1,
            'status' => 'pending',
            'document_path' => $filePath,
        ]);

        Storage::disk('local')->assertExists($filePath);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->putJson("/api/absences/{$absence->id}", [
                'status' => 'justified',
                'remove_document' => true,
                'notes' => 'Justificada com comprovativo em papel no processo.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'justified')
            ->assertJsonPath('data.document_path', null)
            ->assertJsonPath('data.justified_by_user_id', (string) $this->admin->id);

        Storage::disk('local')->assertMissing($filePath);
    }

    public function test_admin_can_delete_absence(): void
    {
        $absence = Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-10-10',
            'valid_until' => '2026-10-10',
            'total_days' => 1,
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->deleteJson("/api/absences/{$absence->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Ausência eliminada com sucesso.');

        $this->assertSoftDeleted('absences', ['id' => $absence->id]);
    }
}
