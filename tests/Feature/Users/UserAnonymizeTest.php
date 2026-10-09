<?php

namespace Tests\Feature;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Filesystem\FilesystemAdapter;
use Mockery;
use Laravel\Sanctum\Sanctum;

class UserAnonymizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_anonymize_staff_and_preserve_record(): void
    {
        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $staffRole = Role::factory()->create([
            'name' => 'Staff',
            'slug' => 'staff',
        ]);

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $staff = User::factory()->create([
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'email' => 'ana.teste@example.com',
            'role_id' => $staffRole->id,
            'is_active' => true,
            'password' => 'password-de-teste',
            'provider' => 'google',
            'provider_id' => 'google-id-de-teste',
        ]);

        $staff->createToken('browser');
        $staff->createToken('mobile');

        $this->assertSame(2, $staff->tokens()->count());

        $resetTable = config('auth.passwords.users.table');

        DB::table($resetTable)->insert([
            [
                'email' => $staff->email,
                'token' => Hash::make('reset-staff-de-teste'),
                'created_at' => now(),
            ],
            [
                'email' => $admin->email,
                'token' => Hash::make('reset-admin-de-teste'),
                'created_at' => now(),
            ],
        ]);

        config([
            'session.driver' => 'database',
            'session.connection' => null,
        ]);

        $sessionTable = config('session.table');

        DB::table($sessionTable)->insert([
            [
                'id' => 'sessao-staff-teste',
                'user_id' => $staff->id,
                'payload' => base64_encode('dados-staff-teste'),
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'sessao-admin-teste',
                'user_id' => $admin->id,
                'payload' => base64_encode('dados-admin-teste'),
                'last_activity' => now()->timestamp,
            ],
        ]);

        $schoolId = (string) Str::uuid();
        $assistantId = (string) Str::uuid();

        DB::table('schools')->insert([
            'id' => $schoolId,
            'name' => 'Escola de Teste',
            'acronym' => 'ET',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        DB::table('assistants')->insert([
            'id' => $assistantId,
            'user_id' => $staff->id,
            'school_id' => $schoolId,
            'internal_number' => 'TEST-001',
            'phone' => '910000001',
            'nif' => '123456789',
            'social_security_number' => '12345678901',
            'birth_date' => '1990-01-01',
            'admission_date' => '2025-01-01',
            'criminal_record_path' => null,
            'criminal_record_expiry' => '2027-01-01',
            'address_street' => 'Rua de Teste',
            'address_zip_code' => '1000-001',
            'emergency_contact_name' => 'Contacto de Teste',
            'emergency_contact_phone' => '920000001',
            'emergency_contact_kinship' => 'Irmão',
            'available_for_transfer' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $staffNotificationId = (string) Str::uuid();
        $adminNotificationId = (string) Str::uuid();

        DB::table('notifications')->insert([
            [
                'id' => $staffNotificationId,
                'user_id' => $staff->id,
                'type' => 'account',
                'title' => 'Conta de Ana Silva',
                'body' => 'Email: ana.teste@example.com',
                'data' => json_encode(['email' => $staff->email]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $adminNotificationId,
                'user_id' => $admin->id,
                'type' => 'system',
                'title' => 'Aviso do administrador',
                'body' => 'Conteúdo a preservar',
                'data' => json_encode(['example' => 'preservar']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

       Sanctum::actingAs($admin);

        $this->getJson("/api/users/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('data.can_delete', true)
            ->assertJsonPath('data.cannot_delete_reason', null)
            ->assertJsonPath('data.delete_action', 'anonymize')
            ->assertJsonPath('data.delete_message', 'Os dados pessoais serão anonimizados e o histórico será mantido.');

        $this->deleteJson("/api/users/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Utilizador anonimizado com sucesso. O histórico foi mantido.')
            ->assertJsonPath('delete_action', 'anonymize');
            
        $this->assertDatabaseHas('notifications', [
            'id' => $staffNotificationId,
            'user_id' => $staff->id,
            'type' => 'anonymized',
            'title' => 'Notificação anonimizada',
            'body' => null,
            'data' => null,
        ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $adminNotificationId,
            'user_id' => $admin->id,
            'type' => 'system',
            'title' => 'Aviso do administrador',
            'body' => 'Conteúdo a preservar',
            'data' => json_encode(['example' => 'preservar']),
        ]);

        $this->assertDatabaseCount('notifications', 2);

        $this->assertDatabaseMissing($resetTable, [
            'email' => 'ana.teste@example.com',
        ]);

        $this->assertDatabaseHas($resetTable, [
            'email' => $admin->email,
        ]);

        $this->assertDatabaseMissing($sessionTable, [
            'id' => 'sessao-staff-teste',
        ]);

        $this->assertDatabaseHas($sessionTable, [
            'id' => 'sessao-admin-teste',
            'user_id' => $admin->id,
        ]);

        $anonymized = User::withTrashed()->findOrFail($staff->id);

        $this->assertSame('Utilizador', $anonymized->first_name);
        $this->assertSame('Anonimizado', $anonymized->last_name);
        $this->assertSame(
            'anon_' . $staff->id . '@deleted.local',
            $anonymized->email
        );
        $this->assertSame($staffRole->id, $anonymized->role_id);
        $this->assertFalse($anonymized->is_active);
        $this->assertNotNull($anonymized->anonymized_at);
        $this->assertNull($anonymized->email_verified_at);
        $this->assertNull($anonymized->provider);
        $this->assertNull($anonymized->provider_id);
        $this->assertNull($anonymized->remember_token);

        $this->assertFalse(
            Hash::check('password-de-teste', $anonymized->password)
        );

        $this->assertSame(0, $anonymized->tokens()->count());

        $this->assertSoftDeleted('users', ['id' => $staff->id]);
        $this->assertNull(User::find($staff->id));
        $this->assertDatabaseCount('users', 2);

        $this->assertTrue($admin->refresh()->is_active);
        $this->assertNull($admin->anonymized_at);
        $this->assertFalse($admin->trashed());

        $assistant = DB::table('assistants')
        ->where('id', $assistantId)
        ->first();

        $this->assertNotNull($assistant);
        $this->assertSame($staff->id, $assistant->user_id);
        $this->assertSame($schoolId, $assistant->school_id);
        $this->assertSame('TEST-001', $assistant->internal_number);
        $this->assertSame('2025-01-01', $assistant->admission_date);
        $this->assertNotNull($assistant->deleted_at);
        $this->assertFalse((bool) $assistant->available_for_transfer);

        foreach ([
            'phone',
            'nif',
            'social_security_number',
            'birth_date',
            'criminal_record_path',
            'criminal_record_expiry',
            'address_street',
            'address_zip_code',
            'emergency_contact_name',
            'emergency_contact_phone',
            'emergency_contact_kinship',
        ] as $field) {
            $this->assertNull($assistant->{$field}, "O campo {$field} deve ficar vazio.");
        }

        $this->assertDatabaseCount('assistants', 1);
        $this->assertDatabaseHas('schools', ['id' => $schoolId]);

        $this->getJson("/api/users/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('data.email', 'anon_' . $staff->id . '@deleted.local');

        $this->patchJson("/api/users/{$staff->id}", [
            'is_active' => true,
        ])->assertNotFound();

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonMissing(['id' => $staff->id])
            ->assertJsonMissing(['id' => $admin->id]);
    }

    public function test_anonymization_removes_criminal_record_file(): void
    {
        Storage::fake('local');

        $path = 'criminal-records/documento-teste.pdf';

        Storage::disk('local')->put($path, 'Conteúdo fictício de teste');
        Storage::disk('local')->assertExists($path);

        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $target = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $schoolId = (string) Str::uuid();
        $assistantId = (string) Str::uuid();

        DB::table('schools')->insert([
            'id' => $schoolId,
            'name' => 'Escola de Teste',
            'acronym' => 'ET',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('assistants')->insert([
            'id' => $assistantId,
            'user_id' => $target->id,
            'school_id' => $schoolId,
            'internal_number' => 'TEST-FILE-001',
            'criminal_record_path' => $path,
            'criminal_record_expiry' => '2027-01-01',
            'available_for_transfer' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(UserService::class)->anonymize($admin, $target);

        Storage::disk('local')->assertMissing($path);

        $this->assertDatabaseHas('assistants', [
            'id' => $assistantId,
            'user_id' => $target->id,
            'school_id' => $schoolId,
            'criminal_record_path' => null,
            'criminal_record_expiry' => null,
        ]);

        $this->assertSoftDeleted('assistants', ['id' => $assistantId]);
        $this->assertSoftDeleted('users', ['id' => $target->id]);

        $this->assertNotNull(
            User::withTrashed()->findOrFail($target->id)->anonymized_at
        );

        $this->assertTrue($admin->refresh()->is_active);
        $this->assertFalse($admin->trashed());
    }

    public function test_failed_file_removal_can_be_retried(): void
    {
        Storage::fake('local');

        $path = 'criminal-records/documento-teste.pdf';

        Storage::disk('local')->put($path, 'Conteúdo fictício de teste');
        Storage::disk('local')->assertExists($path);

        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $target = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $schoolId = (string) Str::uuid();
        $assistantId = (string) Str::uuid();

        DB::table('schools')->insert([
            'id' => $schoolId,
            'name' => 'Escola de Teste',
            'acronym' => 'ET',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('assistants')->insert([
            'id' => $assistantId,
            'user_id' => $target->id,
            'school_id' => $schoolId,
            'internal_number' => 'TEST-FILE-001',
            'criminal_record_path' => $path,
            'criminal_record_expiry' => '2027-01-01',
            'available_for_transfer' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $disk = Storage::disk('local');
        $attempts = 0;

        $adapter = Mockery::mock(FilesystemAdapter::class);

        $adapter->shouldReceive('delete')
            ->with($path)
            ->twice()
            ->andReturnUsing(function () use (&$attempts, $disk, $path) {
                $attempts++;

                if ($attempts === 1) {
                    return false;
                }

                return $disk->delete($path);
            });

        Storage::shouldReceive('disk')
            ->with('local')
            ->twice()
            ->andReturn($adapter);

        try {
            app(UserService::class)->anonymize($admin, $target);

            $this->fail('A primeira remoção deveria ter falhado.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Não foi possível remover o ficheiro do registo criminal.',
                $exception->getMessage()
            );
        }

        $disk->assertExists($path);

        $this->assertDatabaseHas('assistants', [
            'id' => $assistantId,
            'criminal_record_path' => $path,
        ]);

        $this->assertSoftDeleted('assistants', ['id' => $assistantId]);
        $this->assertSoftDeleted('users', ['id' => $target->id]);

        $this->assertNotNull(
            User::withTrashed()->findOrFail($target->id)->anonymized_at
        );

        $this->artisan('app:purge-anonymized-criminal-records')
            ->expectsOutput('Limpeza concluída. Não foram detetadas falhas.')
            ->assertSuccessful();

        $disk->assertMissing($path);

        $this->assertDatabaseHas('assistants', [
            'id' => $assistantId,
            'criminal_record_path' => null,
        ]);

        $this->assertSame(2, $attempts);
    }

    public function test_guest_cannot_anonymize_user(): void
    {
        $staffRole = Role::factory()->create([
            'name' => 'Staff',
            'slug' => 'staff',
        ]);

        $target = User::factory()->create([
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'email' => 'ana.teste@example.com',
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $token = $target->createToken('existing-access')->accessToken;

        $this->deleteJson("/api/users/{$target->id}")
            ->assertUnauthorized();

        $target->refresh();

        $this->assertTrue($target->is_active);
        $this->assertSame('Ana', $target->first_name);
        $this->assertSame('Silva', $target->last_name);
        $this->assertSame('ana.teste@example.com', $target->email);
        $this->assertNull($target->anonymized_at);
        $this->assertFalse($target->trashed());
        $this->assertTrue($target->tokens()->whereKey($token->id)->exists());
    }

    public function test_staff_cannot_anonymize_another_user(): void
    {
        $staffRole = Role::factory()->create([
            'name' => 'Staff',
            'slug' => 'staff',
        ]);

        $actor = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $target = User::factory()->create([
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'email' => 'ana.teste@example.com',
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $token = $target->createToken('existing-access')->accessToken;

        Sanctum::actingAs($actor);

        $this->deleteJson("/api/users/{$target->id}")
            ->assertForbidden();

        $target->refresh();

        $this->assertTrue($target->is_active);
        $this->assertSame('Ana', $target->first_name);
        $this->assertSame('Silva', $target->last_name);
        $this->assertSame('ana.teste@example.com', $target->email);
        $this->assertNull($target->anonymized_at);
        $this->assertFalse($target->trashed());
        $this->assertTrue($target->tokens()->whereKey($token->id)->exists());
    }

    public function test_admin_cannot_anonymize_own_account(): void
    {
        $adminRole = Role::factory()->create([
            'name' => 'Administrator',
            'slug' => 'admin',
        ]);

        $admin = User::factory()->create([
            'first_name' => 'Administrador',
            'last_name' => 'Teste',
            'email' => 'admin.teste@example.com',
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $token = $admin->createToken('existing-access')->accessToken;

        

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/users/{$admin->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        $admin->refresh();

        $this->assertTrue($admin->is_active);
        $this->assertSame('Administrador', $admin->first_name);
        $this->assertSame('Teste', $admin->last_name);
        $this->assertSame('admin.teste@example.com', $admin->email);
        $this->assertSame($adminRole->id, $admin->role_id);
        $this->assertNull($admin->anonymized_at);
        $this->assertFalse($admin->trashed());
        $this->assertTrue($admin->tokens()->whereKey($token->id)->exists());
        $this->assertDatabaseCount('users', 1);
    }
}
