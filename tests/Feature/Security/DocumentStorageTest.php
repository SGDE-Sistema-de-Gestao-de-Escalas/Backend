<?php

namespace Tests\Feature\Security;

use App\Models\Absences\Absence;
use App\Models\Absences\AbsenceType;
use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F-02: os documentos (atestados e registos criminais) não podem ficar num
 * disco servido diretamente pelo servidor web. O único caminho para os
 * ficheiros tem de ser a rota autenticada, que verifica as permissões.
 */
class DocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private Assistant $assistant;
    private AbsenceType $absenceType;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);

        $staff = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);
        $this->assistant = Assistant::create([
            'school_id' => $this->school->id,
            'user_id' => $staff->id,
            'internal_number' => 'A001',
            'available_for_transfer' => false,
        ]);

        $this->absenceType = AbsenceType::create(['name' => 'Doença', 'requires_document' => true]);
    }

    /** Lê o valor por omissão de `documents_disk`, ignorando o .env da máquina. */
    private function defaultDocumentsDisk(): string
    {
        $repository = Env::getRepository();
        $previous = $repository->get('DOCUMENTS_DISK');
        $repository->clear('DOCUMENTS_DISK');

        try {
            $config = require config_path('filesystems.php');
        } finally {
            if ($previous !== null) {
                $repository->set('DOCUMENTS_DISK', $previous);
            }
        }

        return $config['documents_disk'];
    }

    private function adminGet(string $url)
    {
        Sanctum::actingAs($this->admin);

        return $this->withHeader('X-School-ID', $this->school->id)->getJson($url);
    }

    public function test_default_documents_disk_is_not_served_by_the_web_server(): void
    {
        $disk = $this->defaultDocumentsDisk();

        $this->assertSame('local', $disk, 'O disco de documentos por omissão tem de ser privado.');

        // O disco "public" é o único que o `storage:link` expõe em /storage.
        $this->assertNotSame('public', $disk);
    }

    public function test_env_example_does_not_enable_the_public_disk_for_documents(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^DOCUMENTS_DISK=local\s*$/m', $example);
    }

    public function test_absence_json_never_exposes_a_public_storage_url(): void
    {
        // Mesmo que alguém volte a pôr o disco público por engano.
        config(['filesystems.documents_disk' => 'public']);
        Storage::fake('public');

        $path = Storage::disk('public')->putFile(
            'a001/absences_files',
            UploadedFile::fake()->create('atestado.pdf', 100, 'application/pdf')
        );

        $absence = Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-11-10',
            'valid_until' => '2026-11-10',
            'total_days' => 1,
            'status' => 'pending',
            'document_name' => 'atestado.pdf',
            'document_path' => $path,
        ]);

        $url = $this->adminGet("/api/absences/{$absence->id}")
            ->assertOk()
            ->json('data.document_url');

        $this->assertNotNull($url);
        $this->assertStringNotContainsString('/storage/', $url);
        $this->assertStringEndsWith("/api/absences/{$absence->id}/document", $url);
    }

    public function test_assistant_json_never_exposes_a_public_storage_url(): void
    {
        config(['filesystems.documents_disk' => 'public']);
        Storage::fake('public');

        $path = Storage::disk('public')->putFile(
            'a001/criminal_record',
            UploadedFile::fake()->create('registo.pdf', 100, 'application/pdf')
        );

        $this->assistant->update([
            'criminal_record_name' => 'registo.pdf',
            'criminal_record_path' => $path,
            'criminal_record_expiry' => now()->addYear()->toDateString(),
        ]);

        $url = $this->adminGet("/api/assistants/{$this->assistant->id}")
            ->assertOk()
            ->json('data.criminal_record_url');

        $this->assertNotNull($url);
        $this->assertStringNotContainsString('/storage/', $url);
        $this->assertStringEndsWith("/api/assistants/{$this->assistant->id}/criminal-record", $url);
    }

    public function test_uploaded_absence_document_is_not_stored_in_the_public_disk(): void
    {
        config(['filesystems.documents_disk' => $this->defaultDocumentsDisk()]);
        Storage::fake('local');
        Storage::fake('public');

        Sanctum::actingAs($this->admin);
        $response = $this->withHeader('X-School-ID', $this->school->id)->postJson('/api/absences', [
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-11-10',
            'valid_until' => '2026-11-10',
            'document' => UploadedFile::fake()->create('atestado.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $path = Absence::findOrFail($response->json('data.id'))->document_path;

        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_document_download_routes_require_authentication(): void
    {
        $absence = Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-11-10',
            'valid_until' => '2026-11-10',
            'total_days' => 1,
            'status' => 'pending',
        ]);

        $this->getJson("/api/absences/{$absence->id}/document")->assertUnauthorized();
        $this->getJson("/api/assistants/{$this->assistant->id}/criminal-record")->assertUnauthorized();
    }
}
