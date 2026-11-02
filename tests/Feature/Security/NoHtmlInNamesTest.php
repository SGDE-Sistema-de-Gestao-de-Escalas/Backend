<?php

namespace Tests\Feature\Security;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use App\Rules\NoHtmlCharacters;
use App\Services\AssistantImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NoHtmlInNamesTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $staff;
    private Assistant $assistant;
    private ActivityType $activity;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        $this->staff = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);
        $this->assistant = Assistant::create([
            'school_id' => $this->school->id,
            'user_id' => $this->staff->id,
            'internal_number' => 'A001',
            'available_for_transfer' => false,
        ]);
        $this->activity = ActivityType::create([
            'school_id' => $this->school->id,
            'name' => 'Portaria',
            'color' => '#6B7280',
            'active' => true,
        ]);
    }

    public static function dangerousTexts(): array
    {
        return [
            'imagem com evento' => ['<img src=x onerror=alert(1)>'],
            'script' => ['<script>alert(1)</script>'],
            'so o sinal menor' => ['Ana<'],
            'so o sinal maior' => ['Ana>'],
            'no meio do nome' => ['Ana <b>Silva</b>'],
        ];
    }

    public static function normalNames(): array
    {
        return [
            'acentos' => ['José Antunes'],
            'apostrofo' => ["D'Ávila"],
            'apostrofo tipografico' => ['D’Ávila'],
            'hifen' => ['Ana-Maria'],
            'e comercial' => ['Silva & Filhos'],
            'ponto e numero' => ['Sr. Costa 2º'],
        ];
    }

    #[DataProvider('dangerousTexts')]
    public function test_the_rule_rejects_angle_brackets(string $text): void
    {
        $validator = Validator::make(['name' => $text], ['name' => [new NoHtmlCharacters]]);

        $this->assertTrue($validator->fails());
    }

    #[DataProvider('normalNames')]
    public function test_the_rule_accepts_normal_names(string $text): void
    {
        $validator = Validator::make(['name' => $text], ['name' => [new NoHtmlCharacters]]);

        $this->assertFalse($validator->fails());
    }

    #[DataProvider('dangerousTexts')]
    public function test_staff_cannot_put_html_in_their_own_name(string $text): void
    {
        Sanctum::actingAs($this->staff);

        $this->putJson('/api/me', ['first_name' => $text, 'last_name' => 'Silva', 'email' => $this->staff->email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('first_name');

        $this->putJson('/api/me', ['first_name' => 'Ana', 'last_name' => $text, 'email' => $this->staff->email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('last_name');

        $this->assertNotSame($text, $this->staff->fresh()->first_name);
        $this->assertNotSame($text, $this->staff->fresh()->last_name);
    }

    public function test_staff_can_still_save_a_normal_name(): void
    {
        Sanctum::actingAs($this->staff);

        $this->putJson('/api/me', ['first_name' => 'José', 'last_name' => "D'Ávila", 'email' => $this->staff->email])
            ->assertOk();

        $this->assertSame("D'Ávila", $this->staff->fresh()->last_name);
    }

    public function test_admin_cannot_create_a_user_with_html_in_the_name(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/users', ['first_name' => '<b>Ana</b>', 'last_name' => 'Silva', 'email' => 'nova@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('first_name');
    }

    public function test_admin_cannot_create_an_assistant_with_html_in_the_name(): void
    {
        Sanctum::actingAs($this->admin);

        $this->withHeader('X-School-ID', $this->school->id)->postJson('/api/assistants', [
            'school_id' => $this->school->id,
            'first_name' => 'Ana',
            'last_name' => '<img src=x onerror=alert(1)>',
            'email' => 'ana.html@example.com',
            'internal_number' => 'H-001',
        ])->assertUnprocessable()->assertJsonValidationErrors('last_name');
    }

    public function test_admin_cannot_update_an_assistant_with_html_in_the_name(): void
    {
        Sanctum::actingAs($this->admin);

        $this->withHeader('X-School-ID', $this->school->id)
            ->putJson("/api/assistants/{$this->assistant->id}", ['first_name' => '<script>x</script>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('first_name');
    }

    public function test_admin_cannot_save_a_school_with_html_in_the_name(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/schools', ['name' => '<b>Escola</b>', 'acronym' => 'XX'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->putJson("/api/schools/{$this->school->id}", ['name' => '<b>Escola</b>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_admin_cannot_save_an_activity_type_with_html_in_the_name(): void
    {
        Sanctum::actingAs($this->admin);

        $this->withHeader('X-School-ID', $this->school->id)
            ->postJson('/api/activity-types', ['name' => '<img src=x onerror=alert(1)>', 'color' => '#112233'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->withHeader('X-School-ID', $this->school->id)
            ->putJson("/api/activity-types/{$this->activity->id}", ['name' => '<b>x</b>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_csv_import_rejects_html_in_the_name(): void
    {
        $csv = "internal_number;first_name;last_name;email\n"
            . "H-100;Ana;<img src=x onerror=alert(1)>;ana.csv@example.com\n"
            . "H-101;Rui;Costa;rui.csv@example.com\n";

        $result = app(AssistantImportService::class)->import($csv, $this->school->id, AssistantImportService::ON_DUPLICATE_SKIP);

        $this->assertSame(1, $result['summary']['failed']);
        $this->assertSame(1, $result['summary']['created']);
        $this->assertDatabaseMissing('users', ['email' => 'ana.csv@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'rui.csv@example.com']);
    }
}
