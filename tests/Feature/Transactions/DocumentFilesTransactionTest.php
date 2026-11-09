<?php

namespace Tests\Feature\Transactions;

use App\Models\Absences\Absence;
use App\Models\Absences\AbsenceType;
use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use App\Services\AbsenceService;
use App\Services\AssistantService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DocumentFilesTransactionTest extends TestCase
{
    use RefreshDatabase;

    private string $disk;
    private School $school;
    private User $admin;
    private Assistant $assistant;
    private AbsenceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = config('filesystems.documents_disk');
        Storage::fake($this->disk);
        Mail::fake();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        $staff = User::factory()->create([
            'role_id' => $staffRole->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'is_active' => true,
        ]);
        $this->assistant = Assistant::create([
            'school_id' => $this->school->id,
            'user_id' => $staff->id,
            'internal_number' => 'A001',
            'available_for_transfer' => false,
        ]);
        $this->type = AbsenceType::create(['name' => 'Assuntos Pessoais', 'requires_document' => false]);
    }

    private function pdf(string $name = 'ficheiro.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 10, 'application/pdf');
    }

    private function files(): array
    {
        return Storage::disk($this->disk)->allFiles();
    }

    private function absenceWithDocument(): Absence
    {
        $path = app(\App\Services\AssistantDocumentStorageService::class)->storeAbsenceDocument($this->pdf('antigo.pdf'), $this->assistant);

        return Absence::create([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->type->id,
            'valid_from' => now()->addDays(3)->toDateString(),
            'valid_until' => now()->addDays(3)->toDateString(),
            'total_days' => 1,
            'status' => 'pending',
            'document_name' => 'antigo.pdf',
            'document_path' => $path,
        ]);
    }

    private function assistantWithRecord(): Assistant
    {
        $service = app(\App\Services\AssistantDocumentStorageService::class);
        $path = $service->storeCriminalRecord($this->pdf('antigo.pdf'), 'Maria', 'Santos', 'A001');
        $this->assistant->update(['criminal_record_name' => 'antigo.pdf', 'criminal_record_path' => $path]);

        return $this->assistant->fresh();
    }

    public function test_a_failed_absence_update_keeps_the_old_document_when_replacing_it(): void
    {
        $absence = $this->absenceWithDocument();
        $oldPath = $absence->document_path;

        try {
            app(AbsenceService::class)->update(
                $absence,
                ['absence_type_id' => (string) Str::uuid()],
                $this->pdf('novo.pdf'),
                $this->admin
            );
            $this->fail('A atualizacao devia ter falhado.');
        } catch (QueryException) {
        }

        $this->assertSame([$oldPath], $this->files());
        $this->assertSame($oldPath, $absence->fresh()->document_path);
    }

    public function test_a_failed_absence_update_keeps_the_old_document_when_removing_it(): void
    {
        $absence = $this->absenceWithDocument();
        $oldPath = $absence->document_path;

        try {
            app(AbsenceService::class)->update(
                $absence,
                ['absence_type_id' => (string) Str::uuid(), 'remove_document' => true],
                null,
                $this->admin
            );
            $this->fail('A atualizacao devia ter falhado.');
        } catch (QueryException) {
        }

        $this->assertSame([$oldPath], $this->files());
    }

    public function test_a_successful_absence_update_replaces_the_document(): void
    {
        $absence = $this->absenceWithDocument();
        $oldPath = $absence->document_path;

        $updated = app(AbsenceService::class)->update($absence, ['notes' => 'ok'], $this->pdf('novo.pdf'), $this->admin);

        $this->assertNotSame($oldPath, $updated->document_path);
        $this->assertSame([$updated->document_path], $this->files());
    }

    public function test_a_successful_absence_update_can_remove_the_document(): void
    {
        $absence = $this->absenceWithDocument();

        $updated = app(AbsenceService::class)->update($absence, ['remove_document' => true], null, $this->admin);

        $this->assertNull($updated->document_path);
        $this->assertSame([], $this->files());
    }

    public function test_a_failed_absence_create_leaves_no_file_behind(): void
    {
        try {
            app(AbsenceService::class)->create([
                'assistant_id' => $this->assistant->id,
                'absence_type_id' => (string) Str::uuid(),
                'valid_from' => now()->addDays(3)->toDateString(),
                'valid_until' => now()->addDays(3)->toDateString(),
            ], $this->pdf('atestado.pdf'), $this->admin);
            $this->fail('A criacao devia ter falhado.');
        } catch (QueryException) {
        }

        $this->assertSame([], $this->files());
    }

    public function test_a_failed_absence_delete_keeps_the_document(): void
    {
        $absence = $this->absenceWithDocument();
        $oldPath = $absence->document_path;

        Absence::deleting(function () {
            throw new RuntimeException('falha ao apagar');
        });

        try {
            app(AbsenceService::class)->delete($absence);
            $this->fail('A eliminacao devia ter falhado.');
        } catch (RuntimeException) {
        }

        $this->assertSame([$oldPath], $this->files());
        $this->assertNull($absence->fresh()->deleted_at);
    }

    public function test_a_successful_absence_delete_removes_the_document(): void
    {
        $absence = $this->absenceWithDocument();

        $this->assertTrue(app(AbsenceService::class)->delete($absence));

        $this->assertSame([], $this->files());
        $this->assertSoftDeleted('absences', ['id' => $absence->id]);
    }

    public function test_a_failed_assistant_update_keeps_the_old_criminal_record_when_replacing_it(): void
    {
        $assistant = $this->assistantWithRecord();
        $oldPath = $assistant->criminal_record_path;

        try {
            app(AssistantService::class)->update(
                $assistant,
                ['school_id' => (string) Str::uuid()],
                $this->pdf('novo.pdf')
            );
            $this->fail('A atualizacao devia ter falhado.');
        } catch (QueryException) {
        }

        $this->assertSame([$oldPath], $this->files());
        $this->assertSame($oldPath, $assistant->fresh()->criminal_record_path);
    }

    public function test_a_failed_assistant_update_keeps_the_old_criminal_record_when_removing_it(): void
    {
        $assistant = $this->assistantWithRecord();
        $oldPath = $assistant->criminal_record_path;

        try {
            app(AssistantService::class)->update(
                $assistant,
                ['school_id' => (string) Str::uuid(), 'remove_criminal_record' => true]
            );
            $this->fail('A atualizacao devia ter falhado.');
        } catch (QueryException) {
        }

        $this->assertSame([$oldPath], $this->files());
    }

    public function test_a_successful_assistant_update_replaces_the_criminal_record(): void
    {
        $assistant = $this->assistantWithRecord();
        $oldPath = $assistant->criminal_record_path;

        $updated = app(AssistantService::class)->update($assistant, ['phone' => '910000000'], $this->pdf('novo.pdf'));

        $this->assertNotSame($oldPath, $updated->criminal_record_path);
        $this->assertSame([$updated->criminal_record_path], $this->files());
    }

    public function test_a_successful_assistant_update_can_remove_the_criminal_record(): void
    {
        $assistant = $this->assistantWithRecord();

        $updated = app(AssistantService::class)->update($assistant, ['remove_criminal_record' => true]);

        $this->assertNull($updated->criminal_record_path);
        $this->assertSame([], $this->files());
    }

    public function test_a_failed_assistant_create_leaves_no_file_and_no_user_behind(): void
    {
        $usersBefore = User::count();

        try {
            app(AssistantService::class)->create([
                'school_id' => (string) Str::uuid(),
                'first_name' => 'Rita',
                'last_name' => 'Lopes',
                'email' => 'rita@example.com',
                'internal_number' => 'A099',
            ], $this->pdf('registo.pdf'));
            $this->fail('A criacao devia ter falhado.');
        } catch (QueryException) {
        }

        $this->assertSame([], $this->files());
        $this->assertSame($usersBefore, User::count());
    }
}
