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
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F-03: a extensão do ficheiro guardado tem de vir do CONTEÚDO (validado como
 * PDF/imagem) e nunca do nome que o cliente envia. Senão, um "atestado.html"
 * com cabeçalho de PDF ficava gravado como .html e era servido como text/html.
 */
class DocumentExtensionTest extends TestCase
{
    use RefreshDatabase;

    private const PDF_CONTENT = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n<script>alert(1)</script>";

    private School $school;
    private User $admin;
    private Assistant $assistant;
    private AbsenceType $absenceType;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.documents_disk'));

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

    /**
     * Ficheiro real em disco. Os ficheiros "fake" do Laravel deduzem o tipo pelo
     * NOME, o que escondia o problema: num upload verdadeiro o tipo vem do conteúdo.
     */
    private function realUpload(string $clientName, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $content);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    public static function dangerousNames(): array
    {
        return [
            'html' => ['atestado.html'],
            'htm' => ['atestado.htm'],
            'svg' => ['atestado.svg'],
            'js' => ['atestado.js'],
            'xml' => ['atestado.xml'],
            'maiusculas' => ['ATESTADO.HTML'],
            'dupla extensão' => ['atestado.pdf.html'],
        ];
    }

    private function uploadAbsenceDocument(string $clientName): string
    {
        Sanctum::actingAs($this->admin);

        $response = $this->withHeader('X-School-ID', $this->school->id)->postJson('/api/absences', [
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-11-10',
            'valid_until' => '2026-11-10',
            'document' => $this->realUpload($clientName, self::PDF_CONTENT),
        ]);

        $response->assertCreated();

        return Absence::findOrFail($response->json('data.id'))->document_path;
    }

    private function uploadCriminalRecord(string $clientName): string
    {
        Sanctum::actingAs($this->admin);

        $response = $this->withHeader('X-School-ID', $this->school->id)->post('/api/assistants', [
            'school_id' => $this->school->id,
            'first_name' => 'Ana',
            'last_name' => 'Pereira',
            'email' => 'ana.pereira@teste.pt',
            'internal_number' => 'ME-5555',
            'criminal_record' => $this->realUpload($clientName, self::PDF_CONTENT),
        ], ['Accept' => 'application/json']);

        $response->assertCreated();

        return $response->json('data.criminal_record_path');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dangerousNames')]
    public function test_absence_document_extension_comes_from_the_content(string $clientName): void
    {
        $path = $this->uploadAbsenceDocument($clientName);

        $this->assertMatchesRegularExpression('/\.pdf$/', $path);
        $this->assertDoesNotMatchRegularExpression('/\.(html?|svg|js|xml)/i', $path);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dangerousNames')]
    public function test_criminal_record_extension_comes_from_the_content(string $clientName): void
    {
        $path = $this->uploadCriminalRecord($clientName);

        $this->assertMatchesRegularExpression('/\.pdf$/', $path);
        $this->assertDoesNotMatchRegularExpression('/\.(html?|svg|js|xml)/i', $path);
    }

    public function test_real_html_disguised_as_pdf_by_name_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->withHeader('X-School-ID', $this->school->id)->postJson('/api/absences', [
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => '2026-11-10',
            'valid_until' => '2026-11-10',
            'document' => $this->realUpload('atestado.pdf', '<html><script>alert(1)</script></html>'),
        ])->assertUnprocessable()->assertJsonValidationErrors('document');
    }

    public function test_download_responses_forbid_content_type_sniffing(): void
    {
        $path = $this->uploadAbsenceDocument('atestado.pdf');
        $absence = Absence::where('document_path', $path)->firstOrFail();

        Sanctum::actingAs($this->admin);
        $this->withHeader('X-School-ID', $this->school->id)
            ->get("/api/absences/{$absence->id}/document")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->uploadCriminalRecord('registo.pdf');
        $assistant = Assistant::whereNotNull('criminal_record_path')->firstOrFail();

        $this->withHeader('X-School-ID', $this->school->id)
            ->get("/api/assistants/{$assistant->id}/criminal-record")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
