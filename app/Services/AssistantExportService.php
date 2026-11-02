<?php

namespace App\Services;

use App\Models\Assistants\Assistant;
use App\Support\AssistantCsv;
use Illuminate\Database\Eloquent\Builder;

/**
 * Exportação dos assistentes de uma escola para CSV. Se a escola não tiver
 * nenhum assistente (no filtro pedido), o ficheiro só leva o cabeçalho: serve
 * de modelo vazio para a importação.
 */
class AssistantExportService
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_ALL = 'all';

    /**
     * Assistentes a exportar: da escola, sem os eliminados nem os anonimizados
     * (os dados destes já foram apagados de propósito).
     *
     * @return Builder<Assistant>
     */
    public function query(string $schoolId, string $status): Builder
    {
        return Assistant::query()
            ->with('user')
            ->where('school_id', $schoolId)
            ->where(fn ($query) => $query->where('is_anonymized', false)->orWhereNull('is_anonymized'))
            ->when($status === self::STATUS_ACTIVE, fn ($query) => $query->whereHas('user', fn ($user) => $user->where('is_active', true)))
            ->when($status === self::STATUS_INACTIVE, fn ($query) => $query->whereHas('user', fn ($user) => $user->where('is_active', false)))
            ->orderBy('internal_number');
    }

    /**
     * Escreve o CSV (BOM + cabeçalho + uma linha por assistente) em `$stream`.
     * Devolve quantos assistentes foram escritos.
     *
     * @param  resource  $stream
     */
    public function write($stream, string $schoolId, string $status): int
    {
        fwrite($stream, "\xEF\xBB\xBF");
        $this->writeRow($stream, AssistantCsv::COLUMNS);

        $count = 0;

        // chunk() (e não chunkById) para manter a ordenação por nº mecanográfico,
        // que é único entre os assistentes não eliminados.
        $this->query($schoolId, $status)->chunk(200, function ($assistants) use ($stream, &$count) {
            foreach ($assistants as $assistant) {
                $this->writeRow($stream, $this->row($assistant));
                $count++;
            }
        });

        return $count;
    }

    /**
     * @return list<string>
     */
    public function row(Assistant $assistant): array
    {
        $values = [
            'internal_number' => $assistant->internal_number,
            'first_name' => $assistant->user?->first_name,
            'last_name' => $assistant->user?->last_name,
            'email' => $assistant->user?->email,
            'phone' => $assistant->phone,
            'nif' => $assistant->nif,
            'social_security_number' => $assistant->social_security_number,
            'birth_date' => $assistant->birth_date?->format('Y-m-d'),
            'admission_date' => $assistant->admission_date?->format('Y-m-d'),
            'criminal_record_expiry' => $assistant->criminal_record_expiry?->format('Y-m-d'),
            'address_street' => $assistant->address_street,
            'address_zip_code' => $assistant->address_zip_code,
            'address_city' => $assistant->address_city,
            'emergency_contact_name' => $assistant->emergency_contact_name,
            'emergency_contact_phone' => $assistant->emergency_contact_phone,
            'emergency_contact_kinship' => $assistant->emergency_contact_kinship,
            'available_for_transfer' => AssistantCsv::boolToCell((bool) $assistant->available_for_transfer),
        ];

        return array_map(
            fn (string $column) => AssistantCsv::safeCell($values[$column]),
            AssistantCsv::COLUMNS
        );
    }

    /**
     * @param  resource  $stream
     * @param  list<string>  $row
     */
    private function writeRow($stream, array $row): void
    {
        fputcsv($stream, $row, AssistantCsv::DELIMITER, '"', '', "\r\n");
    }
}
