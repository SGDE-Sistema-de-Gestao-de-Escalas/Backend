<?php

namespace Tests\Feature\Security;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schedules\Schedule;
use App\Models\Schedules\ScheduleEntry;
use App\Models\Schools\School;
use App\Models\Schools\SchoolOperatingRule;
use App\Models\Schools\SchoolOperatingRuleDay;
use App\Models\System\ActivityType;
use App\Services\Schedules\ScheduleExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CsvFormulaInjectionTest extends TestCase
{
    use RefreshDatabase;

    private const SATURDAY = '2026-11-07';
    private const MONDAY = '2026-11-09';

    private School $school;
    private Assistant $assistant;
    private User $staff;
    private ActivityType $activity;

    protected function setUp(): void
    {
        parent::setUp();

        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);

        $rule = SchoolOperatingRule::create(['school_id' => $this->school->id, 'valid_from' => '2026-01-01']);
        for ($weekday = 0; $weekday <= 4; $weekday++) {
            SchoolOperatingRuleDay::create([
                'school_operating_rule_id' => $rule->id,
                'day_of_week' => $weekday,
                'opening_time' => '08:00:00',
                'closing_time' => '18:00:00',
                'lunch_start' => '12:00:00',
                'lunch_end' => '14:00:00',
                'lunch_duration_minutes' => 60,
            ]);
        }

        $this->staff = User::factory()->create([
            'role_id' => $staffRole->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'is_active' => true,
        ]);
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

        $schedule = new Schedule([
            'school_id' => $this->school->id,
            'assistant_id' => $this->assistant->id,
            'week_start' => self::MONDAY,
            'week_end' => '2026-11-15',
        ]);
        $schedule->status = 'published';
        $schedule->generated_at = now();
        $schedule->published_at = now();
        $schedule->save();

        ScheduleEntry::create([
            'schedule_id' => $schedule->id,
            'activity_type_id' => $this->activity->id,
            'activity_name' => 'Portaria',
            'date' => self::MONDAY,
            'start_time' => '09:00',
            'end_time' => '11:00',
        ]);
    }

    private function exportRows(): array
    {
        $stream = fopen('php://memory', 'w+');
        app(ScheduleExportService::class)->writeCsv(
            $stream,
            $this->school->fresh(),
            self::SATURDAY,
            self::MONDAY,
            null,
            'wall'
        );
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = array_values(array_filter(explode("\n", $content), fn (string $line) => trim($line) !== ''));

        return array_map(fn (string $line) => str_getcsv($line, ';', '"', '\\'), $lines);
    }

    private function rowForDate(array $rows, string $date): array
    {
        foreach ($rows as $row) {
            if (($row[1] ?? null) === $date) {
                return $row;
            }
        }

        $this->fail("Nao existe linha para {$date} no CSV.");
    }

    public static function formulaStarters(): array
    {
        return [
            'igual' => ['=1+1'],
            'mais' => ['+SUM(1;1)'],
            'menos' => ['-2+3'],
            'arroba' => ['@SUM(1;1)'],
            'hyperlink' => ['=HYPERLINK("http://exemplo.pt","clica")'],
        ];
    }

    #[DataProvider('formulaStarters')]
    public function test_a_school_name_that_looks_like_a_formula_is_neutralised(string $name): void
    {
        $this->school->update(['name' => $name]);

        $row = $this->rowForDate($this->exportRows(), self::SATURDAY);

        $this->assertSame("'" . $name, $row[0]);
    }

    #[DataProvider('formulaStarters')]
    public function test_an_assistant_name_that_looks_like_a_formula_is_neutralised(string $name): void
    {
        $this->staff->update(['first_name' => $name]);

        $row = $this->rowForDate($this->exportRows(), self::MONDAY);

        $this->assertSame("'" . trim($name . ' Santos'), $row[4]);
    }

    #[DataProvider('formulaStarters')]
    public function test_an_internal_number_that_looks_like_a_formula_is_neutralised(string $code): void
    {
        $this->assistant->update(['internal_number' => $code]);

        $row = $this->rowForDate($this->exportRows(), self::MONDAY);

        $this->assertSame("'" . $code, $row[3]);
    }

    public function test_no_cell_in_the_file_starts_with_a_formula_character(): void
    {
        $this->school->update(['name' => '=Escola']);
        $this->staff->update(['first_name' => '@Maria']);
        $this->assistant->update(['internal_number' => '+A001']);

        foreach ($this->exportRows() as $row) {
            foreach ($row as $cell) {
                if ($cell === '--') {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $cell, "Celula perigosa: {$cell}");
            }
        }
    }

    public function test_placeholders_and_normal_values_are_left_untouched(): void
    {
        $rows = $this->exportRows();

        $closed = $this->rowForDate($rows, self::SATURDAY);
        $this->assertSame('Escola A', $closed[0]);
        $this->assertSame('--', $closed[3]);
        $this->assertSame('--', $closed[4]);
        $this->assertSame('--', $closed[6]);

        $working = $this->rowForDate($rows, self::MONDAY);
        $this->assertSame('Escola A', $working[0]);
        $this->assertSame('A001', $working[3]);
        $this->assertSame('Maria Santos', $working[4]);
        $this->assertSame('09:00 – 11:00', $working[5]);
        $this->assertSame('09:00-11:00: Portaria', $working[7]);
        $this->assertSame('Em Serviço', $working[8]);
    }
}
