<?php

namespace Tests\Feature\Assistants;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_admin_can_create_assistant_with_criminal_record_in_structured_public_folder(): void
    {
        Storage::fake(config('filesystems.documents_disk'));

        $file = UploadedFile::fake()->create('registo_criminal_teste.pdf', 1024, 'application/pdf');

        $payload = [
            'school_id' => $this->school->id,
            'first_name' => 'João Maria',
            'last_name' => 'D’Ávila Conceição',
            'email' => 'joao.conceicao@teste.pt',
            'internal_number' => 'ME/00127-A',
            'criminal_record' => $file,
            'criminal_record_expiry' => '2028-12-31',
        ];

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->post('/api/assistants', $payload, ['Accept' => 'application/json'])
            ->assertStatus(201);

        $data = $response->json('data');
        $this->assertTrue($data['has_criminal_record']);
        $this->assertSame('registo_criminal_teste.pdf', $data['criminal_record_name']);
        $this->assertNotNull($data['criminal_record_path']);

        // Verifica a estrutura da pasta: nome sanitizado + nr interno sanitizado / criminal_record
        // "João Maria D’Ávila Conceição" -> "joao_maria_davila_conceicao"
        // "ME/00127-A" -> "me_00127_a"
        $expectedFolderPrefix = 'joao_maria_davila_conceicao_me_00127_a/criminal_record';
        $this->assertStringStartsWith($expectedFolderPrefix, $data['criminal_record_path']);
        $this->assertStringContainsString('/criminal_record/registo_criminal_', $data['criminal_record_path']);

        Storage::disk(config('filesystems.documents_disk'))->assertExists($data['criminal_record_path']);
    }

    public function test_admin_can_download_assistant_criminal_record(): void
    {
        Storage::fake(config('filesystems.documents_disk'));

        $file = UploadedFile::fake()->create('registo.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->post('/api/assistants', [
                'school_id' => $this->school->id,
                'first_name' => 'Ana',
                'last_name' => 'Pereira',
                'email' => 'ana.pereira@teste.pt',
                'internal_number' => 'ME-5555',
                'criminal_record' => $file,
            ], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $assistantId = $response->json('data.id');

        $downloadResponse = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->get("/api/assistants/{$assistantId}/criminal-record");

        $downloadResponse->assertOk();
    }

    public function test_admin_can_update_assistant_and_replace_criminal_record(): void
    {
        Storage::fake(config('filesystems.documents_disk'));

        $file1 = UploadedFile::fake()->create('antigo.pdf', 500, 'application/pdf');

        $createResponse = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->post('/api/assistants', [
                'school_id' => $this->school->id,
                'first_name' => 'Carlos',
                'last_name' => 'Lopes',
                'email' => 'carlos.lopes@teste.pt',
                'internal_number' => 'ME-9999',
                'criminal_record' => $file1,
            ], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $assistantId = $createResponse->json('data.id');
        $oldPath = $createResponse->json('data.criminal_record_path');
        Storage::disk(config('filesystems.documents_disk'))->assertExists($oldPath);

        $file2 = UploadedFile::fake()->create('novo.pdf', 800, 'application/pdf');

        $updateResponse = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->put("/api/assistants/{$assistantId}", [
                'first_name' => 'Carlos',
                'last_name' => 'Lopes',
                'email' => 'carlos.lopes@teste.pt',
                'internal_number' => 'ME-9999',
                'criminal_record' => $file2,
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $newPath = $updateResponse->json('data.criminal_record_path');
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk(config('filesystems.documents_disk'))->assertMissing($oldPath);
        Storage::disk(config('filesystems.documents_disk'))->assertExists($newPath);
    }

    public function test_admin_can_remove_assistant_criminal_record(): void
    {
        Storage::fake(config('filesystems.documents_disk'));

        $file = UploadedFile::fake()->create('doc.pdf', 500, 'application/pdf');

        $createResponse = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->post('/api/assistants', [
                'school_id' => $this->school->id,
                'first_name' => 'Marta',
                'last_name' => 'Dias',
                'email' => 'marta.dias@teste.pt',
                'internal_number' => 'ME-7777',
                'criminal_record' => $file,
            ], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $assistantId = $createResponse->json('data.id');
        $path = $createResponse->json('data.criminal_record_path');
        Storage::disk(config('filesystems.documents_disk'))->assertExists($path);

        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->putJson("/api/assistants/{$assistantId}", [
                'first_name' => 'Marta',
                'last_name' => 'Dias',
                'email' => 'marta.dias@teste.pt',
                'internal_number' => 'ME-7777',
                'remove_criminal_record' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.has_criminal_record', false)
            ->assertJsonPath('data.criminal_record_path', null);

        Storage::disk(config('filesystems.documents_disk'))->assertMissing($path);
    }

    public function test_admin_can_register_criminal_record_expiry_date_without_uploading_file(): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/assistants', [
                'school_id' => $this->school->id,
                'first_name' => 'Manuel',
                'last_name' => 'Sousa',
                'email' => 'manuel.sousa@teste.pt',
                'internal_number' => 'ME-8888',
                'criminal_record_expiry' => '2029-05-15',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('data.criminal_record_expiry', '2029-05-15')
            ->assertJsonPath('data.has_criminal_record', false)
            ->assertJsonPath('data.criminal_record_path', null)
            ->assertJsonPath('data.criminal_record_url', null);
    }

    public function test_admin_can_store_criminal_record_in_cloudflare_r2_disk_with_same_folder_structure(): void
    {
        config(['filesystems.documents_disk' => 'r2']);
        Storage::fake('r2');

        $file = UploadedFile::fake()->create('registo_r2.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->post('/api/assistants', [
                'school_id' => $this->school->id,
                'first_name' => 'Vitória',
                'last_name' => 'Santos',
                'email' => 'vitoria.santos@teste.pt',
                'internal_number' => 'ME-R2-001',
                'criminal_record' => $file,
            ], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $data = $response->json('data');
        $this->assertTrue($data['has_criminal_record']);
        $this->assertSame('registo_r2.pdf', $data['criminal_record_name']);
        $this->assertNotNull($data['criminal_record_path']);

        // Garante que no R2 a estrutura de pastas é rigorosamente idêntica:
        // {nome_assistente}_{nr_interno}/criminal_record/
        $expectedFolderPrefix = 'vitoria_santos_me_r2_001/criminal_record';
        $this->assertStringStartsWith($expectedFolderPrefix, $data['criminal_record_path']);
        $this->assertStringContainsString('/criminal_record/registo_criminal_', $data['criminal_record_path']);

        Storage::disk('r2')->assertExists($data['criminal_record_path']);

        // Garante que o endpoint de download também obtém o ficheiro do disco R2
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->get("/api/assistants/{$data['id']}/criminal-record")
            ->assertOk();
    }

    public function test_assistants_index_supports_pagination_counts_sorting_and_unpaginated_dropdown(): void
    {
        $staffRole = Role::where('slug', 'staff')->first();

        $userZ = User::factory()->create([
            'role_id' => $staffRole->id,
            'first_name' => 'Zulmira',
            'last_name' => 'Costa',
            'email' => 'zulmira@escola.pt',
            'is_active' => true,
        ]);
        Assistant::create([
            'user_id' => $userZ->id,
            'school_id' => $this->school->id,
            'internal_number' => 'AO-001',
        ]);

        $userA = User::factory()->create([
            'role_id' => $staffRole->id,
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'email' => 'ana.silva@escola.pt',
            'is_active' => true,
        ]);
        Assistant::create([
            'user_id' => $userA->id,
            'school_id' => $this->school->id,
            'internal_number' => 'AO-002',
        ]);

        $userInactive = User::factory()->create([
            'role_id' => $staffRole->id,
            'first_name' => 'Bruno',
            'last_name' => 'Ferreira',
            'email' => 'bruno@escola.pt',
            'is_active' => false,
        ]);
        $inactiveAssistant = Assistant::create([
            'user_id' => $userInactive->id,
            'school_id' => $this->school->id,
            'internal_number' => 'AO-003',
        ]);
        $inactiveAssistant->delete();

        // 1. Default: status=active, per_page=10, sort_by=name asc
        $response = $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/assistants')
            ->assertOk();

        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('counts.all', 3)
            ->assertJsonPath('counts.active', 2)
            ->assertJsonPath('counts.inactive', 1)
            ->assertJsonPath('data.0.first_name', 'Ana')
            ->assertJsonPath('data.0.active', true)
            ->assertJsonPath('data.0.can_delete', true)
            ->assertJsonPath('data.0.cannot_delete_reason', null)
            ->assertJsonPath('data.1.first_name', 'Zulmira');

        // 2. Ordenação por internal_number asc
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/assistants?sort_by=internal_number&sort_direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.internal_number', 'AO-001')
            ->assertJsonPath('data.1.internal_number', 'AO-002');

        // 3. Filtro status=inactive
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/assistants?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.internal_number', 'AO-003')
            ->assertJsonPath('data.0.active', false)
            ->assertJsonPath('data.0.can_delete', false);

        // 4. Pesquisa por nome completo ("Ana Silva")
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/assistants?search=Ana%20Silva')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.internal_number', 'AO-002');

        // 5. per_page=-1 (devolve todos sem paginação)
        $this->actingAs($this->admin)
            ->withHeader('X-School-ID', $this->school->id)
            ->getJson('/api/assistants?status=all&per_page=-1')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('counts.all', 3);
    }
}

