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

        Storage::fake(config('filesystems.documents_disk'));

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
        $disk = config('filesystems.documents_disk');
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
            ->assertJsonPath('data.document_name', 'atestado.pdf')
            ->assertJsonPath('data.justified_by_user_id', (string) $this->admin->id);

        $absenceId = $response->json('data.id');
        $absence = Absence::findOrFail($absenceId);

        $this->assertNotNull($absence->document_path);
        $this->assertStringStartsWith('maria_santos_ast_001/absences_files/ausencia_', $absence->document_path);
        Storage::disk($disk)->assertExists($absence->document_path);

        // Download document pelo admin
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->get("/api/absences/{$absenceId}/document")
            ->assertOk();
    }

    public function test_staff_can_view_own_absences_and_download_own_document(): void
    {
        $disk = config('filesystems.documents_disk');
        $file = UploadedFile::fake()->create('comprovativo.pdf', 150, 'application/pdf');
        $filePath = Storage::disk($disk)->putFile('maria_santos_ast_001/absences_files', $file);

        $ownAbsence = Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-10-10',
            'valid_until' => '2026-10-10',
            'total_days' => 1,
            'status' => 'pending',
            'document_name' => 'comprovativo.pdf',
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
        $otherFilePath = Storage::disk($disk)->putFile('joao_silva_ast_002/absences_files', $otherFile);
        $otherAbsence = Absence::create([
            'assistant_id' => $otherAssistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-10-12',
            'valid_until' => '2026-10-12',
            'total_days' => 1,
            'status' => 'pending',
            'document_name' => 'outro_doc.pdf',
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
        $disk = config('filesystems.documents_disk');

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

        // Assistente anexa o documento e notas à sua falta (via PUT ou POST /document)
        $response = $this->actingAs($this->staff)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->postJson("/api/absences/{$absence->id}/document", [
                'document' => $file,
                'justification' => 'Consulta no centro de saúde.',
                'notes' => 'Comprovativo anexado pelo assistente.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.justification', 'Consulta no centro de saúde.')
            ->assertJsonPath('data.notes', 'Comprovativo anexado pelo assistente.')
            ->assertJsonPath('data.document_name', 'comprovativo.pdf');

        $docPath = $response->json('data.document_path');
        $this->assertNotNull($docPath);
        $this->assertStringStartsWith('maria_santos_ast_001/absences_files/ausencia_', $docPath);
        Storage::disk($disk)->assertExists($docPath);

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

    public function test_absence_requiring_document_can_be_created_without_document_and_returns_warning(): void
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

        $response->assertCreated()
            ->assertJsonPath('data.status', 'justified')
            ->assertJsonPath('data.requires_document', true)
            ->assertJsonPath('data.has_document', false)
            ->assertJsonPath('data.missing_document', true)
            ->assertJsonPath('data.warning', 'Este tipo de ausência requer a apresentação de documento comprovativo.')
            ->assertJsonPath('warning', 'Este tipo de ausência requer a apresentação de documento comprovativo.');

        // Também ao criar como pending sem documento
        $payload['status'] = 'pending';
        $responsePending = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->postJson('/api/absences', $payload);

        $responsePending->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.missing_document', true)
            ->assertJsonPath('warning', 'Este tipo de ausência requer a apresentação de documento comprovativo.');
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
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('meta.per_page', 8)
            ->assertJsonPath('meta.counts.all', 2)
            ->assertJsonPath('meta.counts.pending', 1)
            ->assertJsonPath('meta.counts.justified', 1)
            ->assertJsonPath('meta.counts.unjustified', 0)
            ->assertJsonPath('counts.all', 2)
            ->assertJsonPath('counts.pending', 1)
            ->assertJsonPath('counts.justified', 1)
            ->assertJsonPath('counts.unjustified', 0);

        // Filter status=justified
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->getJson('/api/absences?status=justified')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'justified')
            ->assertJsonPath('counts.all', 2);

        // Filter status=all and per_page=-1
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->getJson('/api/absences?status=all&per_page=-1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('counts.all', 2);

        // Filter by date overlap
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->getJson('/api/absences?start_date=2026-10-11&end_date=2026-10-13')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.valid_from', '2026-10-12');
    }

    public function test_admin_can_update_absence_status_and_remove_document(): void
    {
        $disk = config('filesystems.documents_disk');
        $file = UploadedFile::fake()->create('atestado.pdf', 100, 'application/pdf');
        $filePath = Storage::disk($disk)->putFile('maria_santos_ast_001/absences_files', $file);

        $absence = Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-10-10',
            'valid_until' => '2026-10-10',
            'total_days' => 1,
            'status' => 'pending',
            'document_name' => 'atestado.pdf',
            'document_path' => $filePath,
        ]);

        Storage::disk($disk)->assertExists($filePath);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->putJson("/api/absences/{$absence->id}", [
                'status' => 'justified',
                'remove_document' => true,
                'notes' => 'Justificada com comprovativo em papel no processo.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'justified')
            ->assertJsonPath('data.document_name', null)
            ->assertJsonPath('data.document_path', null)
            ->assertJsonPath('data.justified_by_user_id', (string) $this->admin->id);

        Storage::disk($disk)->assertMissing($filePath);
    }

    public function test_past_absence_permissions_and_restrictions(): void
    {
        $pastDate = now()->subDays(5)->format('Y-m-d');
        $futureDate = now()->addDays(5)->format('Y-m-d');

        $pastPendingAbsence = Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => $pastDate,
            'valid_until' => $pastDate,
            'total_days' => 1,
            'status' => 'pending',
        ]);

        $pastFinalizedAbsence = Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => $pastDate,
            'valid_until' => $pastDate,
            'total_days' => 1,
            'status' => 'unjustified',
        ]);

        $futureAbsence = Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => $futureDate,
            'valid_until' => $futureDate,
            'total_days' => 1,
            'status' => 'justified',
        ]);

        // 1. Falta pendente no passado: Admin pode editar e eliminar totalmente (can_edit=true, can_delete=true)
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->getJson("/api/absences/{$pastPendingAbsence->id}")
            ->assertOk()
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.can_delete', true);

        // 2. Falta já concluída (unjustified/justified) no passado: Admin vê can_edit=false e can_delete=false, mas can_justify=true e can_attach_document=true
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->getJson("/api/absences/{$pastFinalizedAbsence->id}")
            ->assertOk()
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_delete', false)
            ->assertJsonPath('data.cannot_edit_reason', 'Esta falta já foi validada e encerrada pela direção.')
            ->assertJsonPath('data.cannot_delete_reason', 'Não é possível eliminar uma falta passada já registada no histórico.')
            ->assertJsonPath('data.can_justify', true)
            ->assertJsonPath('data.can_attach_document', true);

        // 3. Falta futura (mesmo já justificada): Admin vê can_edit=true e can_delete=true
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->getJson("/api/absences/{$futureAbsence->id}")
            ->assertOk()
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.can_delete', true);

        // 4. Admin não pode alterar datas numa falta passada já validada/injustificada
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->putJson("/api/absences/{$pastFinalizedAbsence->id}", [
                'valid_from' => $futureDate,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_from']);

        // 5. Admin não pode eliminar uma falta passada já validada/injustificada
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->deleteJson("/api/absences/{$pastFinalizedAbsence->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['absence']);

        // 6. Staff não pode anexar documento/notas numa falta já aprovada (justified)
        $this->actingAs($this->staff)
            ->withHeader('X-School-ID', (string) $this->school->id)
            ->putJson("/api/absences/{$futureAbsence->id}", [
                'notes' => 'Tentativa após aprovação',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['absence']);
    }

    public function test_admin_can_delete_absence(): void
    {
        $futureDate = now()->addDays(3)->format('Y-m-d');

        $absence = Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => $futureDate,
            'valid_until' => $futureDate,
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
