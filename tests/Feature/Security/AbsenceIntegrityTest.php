<?php

namespace Tests\Feature\Security;

use App\Models\Absences\Absence;
use App\Models\Absences\AbsenceType;
use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AbsenceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private Assistant $assistant;
    private Assistant $colleague;
    private AbsenceType $absenceType;
    private Carbon $base;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.documents_disk'));

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);

        $this->assistant = $this->registerAssistantFor($staffRole, 'A001');
        $this->colleague = $this->registerAssistantFor($staffRole, 'A002');

        $this->absenceType = AbsenceType::create(['name' => 'Assuntos Pessoais', 'requires_document' => false]);
        $this->base = now()->addDays(30)->startOfDay();
    }

    private function registerAssistantFor(Role $role, string $number): Assistant
    {
        $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        return Assistant::create([
            'school_id' => $this->school->id,
            'user_id' => $user->id,
            'internal_number' => $number,
            'available_for_transfer' => false,
        ]);
    }

    private function dateAtOffset(int $offset): string
    {
        return $this->base->copy()->addDays($offset)->toDateString();
    }

    private function absenceData(int $from, int $until, array $extra = [], ?Assistant $assistant = null): array
    {
        return array_merge([
            'assistant_id' => ($assistant ?? $this->assistant)->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => $this->dateAtOffset($from),
            'valid_until' => $this->dateAtOffset($until),
        ], $extra);
    }

    private function submitAbsence(array $payload)
    {
        Sanctum::actingAs($this->admin);

        return $this->withHeader('X-School-ID', $this->school->id)->postJson('/api/absences', $payload);
    }

    private function amendAbsence(Absence $absence, array $payload)
    {
        Sanctum::actingAs($this->admin);

        return $this->withHeader('X-School-ID', $this->school->id)->putJson("/api/absences/{$absence->id}", $payload);
    }

    private function givenAbsence(int $from, int $until, array $extra = [], ?Assistant $assistant = null): Absence
    {
        return Absence::create(array_merge([
            'assistant_id' => ($assistant ?? $this->assistant)->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => $this->dateAtOffset($from),
            'valid_until' => $this->dateAtOffset($until),
            'total_days' => $until - $from + 1,
            'status' => 'pending',
        ], $extra));
    }

    public function test_an_identical_absence_is_refused(): void
    {
        $this->givenAbsence(0, 2);

        $this->submitAbsence($this->absenceData(0, 2))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_from']);

        $this->assertSame(1, Absence::count());
    }

    public function test_the_same_request_repeated_creates_only_one_absence(): void
    {
        $this->submitAbsence($this->absenceData(0, 0))->assertCreated();

        foreach (range(1, 4) as $attempt) {
            $this->submitAbsence($this->absenceData(0, 0))->assertStatus(422);
        }

        $this->assertSame(1, Absence::count());
    }

    public static function overlappingRanges(): array
    {
        return [
            'apanha o fim' => [2, 6],
            'apanha o inicio' => [-3, 0],
            'esta dentro' => [1, 2],
            'contem a existente' => [-2, 8],
            'um dia no limite final' => [4, 4],
            'um dia no limite inicial' => [0, 0],
        ];
    }

    #[DataProvider('overlappingRanges')]
    public function test_an_overlapping_absence_for_the_same_assistant_is_refused(int $from, int $until): void
    {
        $this->givenAbsence(0, 4);

        $this->submitAbsence($this->absenceData($from, $until))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_from']);

        $this->assertSame(1, Absence::count());
    }

    public function test_an_absence_on_the_next_day_is_accepted(): void
    {
        $this->givenAbsence(0, 4);

        $this->submitAbsence($this->absenceData(5, 7))->assertCreated();
        $this->submitAbsence($this->absenceData(-3, -1))->assertCreated();

        $this->assertSame(3, Absence::count());
    }

    public function test_the_same_dates_for_another_assistant_are_accepted(): void
    {
        $this->givenAbsence(0, 4);

        $this->submitAbsence($this->absenceData(0, 4, [], $this->colleague))->assertCreated();
    }

    public function test_a_deleted_absence_does_not_block_new_ones(): void
    {
        $this->givenAbsence(0, 4)->delete();

        $this->submitAbsence($this->absenceData(0, 4))->assertCreated();
    }

    public function test_an_open_ended_absence_blocks_later_dates(): void
    {
        $this->givenAbsence(0, 0, ['valid_until' => null]);

        $this->submitAbsence($this->absenceData(50, 51))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_from']);
    }

    public function test_two_partial_absences_on_the_same_day_are_accepted_when_the_hours_do_not_overlap(): void
    {
        $this->submitAbsence($this->absenceData(0, 0, ['start_time' => '09:00', 'end_time' => '10:00']))->assertCreated();
        $this->submitAbsence($this->absenceData(0, 0, ['start_time' => '10:00', 'end_time' => '11:00']))->assertCreated();
        $this->submitAbsence($this->absenceData(0, 0, ['start_time' => '14:00', 'end_time' => '15:00']))->assertCreated();

        $this->assertSame(3, Absence::count());
    }

    public function test_partial_absences_with_overlapping_hours_are_refused(): void
    {
        $this->givenAbsence(0, 0, ['start_time' => '09:00', 'end_time' => '10:00']);

        $this->submitAbsence($this->absenceData(0, 0, ['start_time' => '09:30', 'end_time' => '10:30']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_from']);
    }

    public function test_a_full_day_absence_conflicts_with_a_partial_one_on_the_same_day(): void
    {
        $this->givenAbsence(0, 0, ['start_time' => '09:00', 'end_time' => '10:00']);

        $this->submitAbsence($this->absenceData(0, 0))->assertStatus(422);

        $this->givenAbsence(3, 3);

        $this->submitAbsence($this->absenceData(3, 3, ['start_time' => '09:00', 'end_time' => '10:00']))->assertStatus(422);
    }

    public function test_moving_an_absence_onto_another_one_is_refused(): void
    {
        $this->givenAbsence(0, 4);
        $second = $this->givenAbsence(10, 12);

        $this->amendAbsence($second, ['valid_from' => $this->dateAtOffset(3), 'valid_until' => $this->dateAtOffset(12)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_from']);

        $this->assertSame($this->dateAtOffset(10), $second->fresh()->valid_from->toDateString());
    }

    public function test_moving_an_absence_to_free_dates_is_accepted(): void
    {
        $this->givenAbsence(0, 4);
        $second = $this->givenAbsence(10, 12);

        $this->amendAbsence($second, ['valid_from' => $this->dateAtOffset(5), 'valid_until' => $this->dateAtOffset(12)])->assertOk();

        $this->assertSame($this->dateAtOffset(5), $second->fresh()->valid_from->toDateString());
    }

    public function test_saving_an_absence_with_unchanged_dates_does_not_conflict_with_itself(): void
    {
        $absence = $this->givenAbsence(0, 4);

        $this->amendAbsence($absence, [
            'valid_from' => $this->dateAtOffset(0),
            'valid_until' => $this->dateAtOffset(4),
            'notes' => 'Atualizada',
        ])->assertOk();

        $this->assertSame('Atualizada', $absence->fresh()->notes);
    }

    public function test_an_existing_overlap_does_not_block_editing_other_fields(): void
    {
        $this->givenAbsence(0, 4);
        $second = $this->givenAbsence(2, 6);

        $this->amendAbsence($second, [
            'valid_from' => $this->dateAtOffset(2),
            'valid_until' => $this->dateAtOffset(6),
            'status' => 'justified',
        ])->assertOk();

        $this->assertSame('justified', $second->fresh()->status);
    }

    public static function absurdRanges(): array
    {
        return [
            'ano 9999' => ['2027-01-04', '9999-12-31'],
            'ano 1' => ['0001-01-01', '2027-01-04'],
            'antes de 2000' => ['1999-12-31', '2000-01-02'],
            'depois de 2100' => ['2100-12-30', '2101-01-02'],
        ];
    }

    #[DataProvider('absurdRanges')]
    public function test_absurd_dates_are_refused_with_422(string $from, string $until): void
    {
        $response = $this->submitAbsence([
            'assistant_id' => $this->assistant->id,
            'absence_type_id' => $this->absenceType->id,
            'valid_from' => $from,
            'valid_until' => $until,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Absence::count());
    }

    public function test_an_absence_longer_than_a_year_is_refused(): void
    {
        $this->submitAbsence($this->absenceData(0, 366))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_until']);

        $this->assertSame(0, Absence::count());
    }

    public function test_an_absence_of_exactly_one_year_is_accepted(): void
    {
        $this->submitAbsence($this->absenceData(0, 365))->assertCreated();
    }

    public function test_the_total_days_sent_by_the_client_has_a_ceiling(): void
    {
        $this->submitAbsence($this->absenceData(0, 0, ['total_days' => 100000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['total_days']);

        $this->assertSame(0, Absence::count());
    }

    public function test_extending_an_absence_beyond_a_year_is_refused(): void
    {
        $absence = $this->givenAbsence(0, 4);

        $this->amendAbsence($absence, ['valid_from' => $this->dateAtOffset(0), 'valid_until' => $this->dateAtOffset(400)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valid_until']);

        $this->assertSame($this->dateAtOffset(4), $absence->fresh()->valid_until->toDateString());
    }
}
