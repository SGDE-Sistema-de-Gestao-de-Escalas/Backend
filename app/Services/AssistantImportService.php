<?php

namespace App\Services;

use App\Rules\NoHtmlCharacters;
use App\Http\Requests\Assistants\StoreAssistantRequest;
use App\Models\Assistants\Assistant;
use App\Support\AssistantCsv;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Importação de assistentes a partir de um CSV.
 *
 * Cada linha é independente: as válidas são guardadas e as inválidas ficam
 * listadas com o motivo (e o número da linha no ficheiro). O assistente é
 * identificado pelo número mecanográfico. Se já existir, a linha é ignorada
 * (`skip`) ou o assistente é atualizado (`overwrite`). Criar e atualizar
 * passam pelo AssistantService, tal como o formulário, por isso valem as
 * mesmas regras e cada assistente novo recebe o email de acesso.
 */
class AssistantImportService
{
    public const ON_DUPLICATE_SKIP = 'skip';

    public const ON_DUPLICATE_OVERWRITE = 'overwrite';

    public const MAX_ROWS = 500;

    public function __construct(
        private readonly AssistantService $assistantService
    ) {}

    /**
     * @return array{
     *     summary: array{total_rows: int, created: int, updated: int, skipped: int, failed: int},
     *     ignored_columns: list<string>,
     *     errors: list<array<string, mixed>>,
     *     skipped: list<array<string, mixed>>
     * }
     */
    public function import(string $content, string $schoolId, string $onDuplicate): array
    {
        $parsed = AssistantCsv::parse($content);

        $this->assertHeader($parsed['columns']);

        $rows = $parsed['rows'];

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'O ficheiro não tem assistentes para importar (só tem o cabeçalho).']);
        }

        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'O ficheiro tem mais de '.self::MAX_ROWS.' linhas. Divida-o em vários ficheiros.']);
        }

        $repeated = $this->repeatedInFile($rows);

        $summary = ['total_rows' => count($rows), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $errors = [];
        $skipped = [];

        foreach ($rows as $line => $row) {
            $identity = ['row' => $line, 'internal_number' => $row['internal_number'] ?? null, 'email' => $row['email'] ?? null];

            if (isset($repeated[$line])) {
                $summary['failed']++;
                $errors[] = $identity + ['errors' => $repeated[$line]];

                continue;
            }

            try {
                $outcome = $this->importRow($row, $schoolId, $onDuplicate);
            } catch (Throwable $e) {
                report($e);

                $outcome = ['status' => 'failed', 'errors' => ['row' => ['Erro inesperado ao guardar esta linha.']]];
            }

            match ($outcome['status']) {
                'created' => $summary['created']++,
                'updated' => $summary['updated']++,
                'skipped' => $this->addSkipped($summary, $skipped, $identity, $outcome['reason']),
                'failed' => $this->addError($summary, $errors, $identity, $outcome['errors']),
            };
        }

        return [
            'summary' => $summary,
            'ignored_columns' => array_values(array_diff($parsed['columns'], AssistantCsv::COLUMNS)),
            'errors' => $errors,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string, ?string>  $row
     * @return array{status: string, reason?: string, errors?: array<string, list<string>>}
     */
    private function importRow(array $row, string $schoolId, string $onDuplicate): array
    {
        $data = $this->normalize($row);

        $existing = Assistant::with('user')->where('internal_number', $data['internal_number'] ?? '')->first();

        if ($existing !== null && $onDuplicate === self::ON_DUPLICATE_SKIP) {
            return ['status' => 'skipped', 'reason' => 'Já existe um assistente com este número mecanográfico.'];
        }

        if ($existing !== null) {
            if ($existing->school_id !== $schoolId) {
                return ['status' => 'failed', 'errors' => ['internal_number' => ['Este número mecanográfico pertence a um assistente de outra escola.']]];
            }

            if ($existing->is_anonymized) {
                return ['status' => 'failed', 'errors' => ['internal_number' => ['Este assistente foi anonimizado e não pode ser atualizado.']]];
            }
        }

        $validator = Validator::make($data, $this->rules($existing), $this->messages());

        if ($validator->fails()) {
            return ['status' => 'failed', 'errors' => $validator->errors()->toArray()];
        }

        // As células vazias não contam: ao criar ficam com o valor por omissão e,
        // ao atualizar, não apagam dados já guardados.
        $valid = array_filter($validator->validated(), fn ($value) => $value !== null);

        if ($existing === null) {
            $this->assistantService->create($valid + ['school_id' => $schoolId]);

            return ['status' => 'created'];
        }

        $this->assistantService->update($existing, $valid);

        return ['status' => 'updated'];
    }

    /**
     * Põe os valores no formato que a API espera: NIF/NSS sem espaços, datas
     * em AAAA-MM-DD e o booleano como true/false. Valores que não consegue
     * interpretar seguem como estão, para a validação os recusar.
     *
     * @param  array<string, ?string>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $data = [];

        foreach (AssistantCsv::COLUMNS as $column) {
            $data[$column] = $row[$column] ?? null;
        }

        foreach (['nif', 'social_security_number'] as $column) {
            if ($data[$column] !== null) {
                $data[$column] = str_replace(' ', '', $data[$column]);
            }
        }

        foreach (AssistantCsv::DATE_COLUMNS as $column) {
            if ($data[$column] !== null) {
                $data[$column] = AssistantCsv::normalizeDate($data[$column]);
            }
        }

        if ($data['available_for_transfer'] !== null) {
            $data['available_for_transfer'] = AssistantCsv::parseBool($data['available_for_transfer']) ?? $data['available_for_transfer'];
        }

        return $data;
    }

    /**
     * As mesmas regras do StoreAssistantRequest (sem a escola, que vem do
     * cabeçalho). Ao atualizar, a unicidade ignora o próprio assistente.
     *
     * @return array<string, mixed>
     */
    private function rules(?Assistant $existing): array
    {
        $unique = fn (string $table, string $column, ?string $ignoreId) => Rule::unique($table, $column)
            ->whereNull('deleted_at')
            ->ignore($ignoreId);

        $assistantId = $existing?->getKey();

        return [
            'first_name' => ['required', 'string', 'max:255', new NoHtmlCharacters],
            'last_name' => ['required', 'string', 'max:255', new NoHtmlCharacters],
            'email' => ['required', 'email', 'max:255', $unique('users', 'email', $existing?->user_id)],

            'internal_number' => ['required', 'string', 'max:50', $unique('assistants', 'internal_number', $assistantId)],
            'phone' => ['nullable', 'string', 'max:20', $unique('assistants', 'phone', $assistantId)],
            'nif' => ['nullable', 'digits:9', $unique('assistants', 'nif', $assistantId)],
            'social_security_number' => ['nullable', 'digits:11', $unique('assistants', 'social_security_number', $assistantId)],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'admission_date' => ['nullable', 'date_format:Y-m-d'],
            'criminal_record_expiry' => ['nullable', 'date_format:Y-m-d'],

            'address_street' => ['nullable', 'string', 'max:255'],
            'address_zip_code' => ['nullable', 'string', 'regex:/^\d{4}-\d{3}$/'],
            'address_city' => ['nullable', 'string', 'max:100'],

            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20'],
            'emergency_contact_kinship' => ['nullable', 'string', 'max:50'],

            'available_for_transfer' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            ...(new StoreAssistantRequest)->messages(),
            'birth_date.date_format' => 'A data de nascimento deve estar no formato AAAA-MM-DD ou DD/MM/AAAA.',
            'admission_date.date_format' => 'A data de admissão deve estar no formato AAAA-MM-DD ou DD/MM/AAAA.',
            'criminal_record_expiry.date_format' => 'A validade do registo criminal deve estar no formato AAAA-MM-DD ou DD/MM/AAAA.',
            'available_for_transfer.boolean' => 'A disponibilidade para transferência tem de ser "sim" ou "não".',
        ];
    }

    /**
     * O cabeçalho tem de ter as colunas obrigatórias e não pode repetir nomes.
     *
     * @param  list<string>  $columns
     */
    private function assertHeader(array $columns): void
    {
        $missing = array_values(array_diff(AssistantCsv::REQUIRED_COLUMNS, $columns));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => 'Faltam colunas obrigatórias no cabeçalho: '.implode(', ', $missing).'. Use o ficheiro exportado, ou o modelo vazio, como base.',
            ]);
        }

        $repeated = array_keys(array_filter(array_count_values($columns), fn ($count, $name) => $count > 1 && $name !== '', ARRAY_FILTER_USE_BOTH));

        if ($repeated !== []) {
            throw ValidationException::withMessages(['file' => 'O cabeçalho tem colunas repetidas: '.implode(', ', $repeated).'.']);
        }
    }

    /**
     * Linhas cujo número mecanográfico ou email já apareceu mais acima no
     * mesmo ficheiro (a primeira ocorrência é a que conta).
     *
     * @param  array<int, array<string, ?string>>  $rows
     * @return array<int, array<string, list<string>>>
     */
    private function repeatedInFile(array $rows): array
    {
        $seen = ['internal_number' => [], 'email' => []];
        $repeated = [];

        foreach ($rows as $line => $row) {
            foreach (['internal_number' => 'número mecanográfico', 'email' => 'email'] as $field => $label) {
                $value = mb_strtolower(trim((string) ($row[$field] ?? '')));

                if ($value === '') {
                    continue;
                }

                if (isset($seen[$field][$value])) {
                    $repeated[$line][$field][] = "Este {$label} já aparece na linha {$seen[$field][$value]} do ficheiro.";
                } else {
                    $seen[$field][$value] = $line;
                }
            }
        }

        return $repeated;
    }

    /**
     * @param  array<string, int>  $summary
     * @param  list<array<string, mixed>>  $skipped
     * @param  array<string, mixed>  $identity
     */
    private function addSkipped(array &$summary, array &$skipped, array $identity, string $reason): void
    {
        $summary['skipped']++;
        $skipped[] = $identity + ['reason' => $reason];
    }

    /**
     * @param  array<string, int>  $summary
     * @param  list<array<string, mixed>>  $errors
     * @param  array<string, mixed>  $identity
     * @param  array<string, list<string>>  $messages
     */
    private function addError(array &$summary, array &$errors, array $identity, array $messages): void
    {
        $summary['failed']++;
        $errors[] = $identity + ['errors' => $messages];
    }
}
