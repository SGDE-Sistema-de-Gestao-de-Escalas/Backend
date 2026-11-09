<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PurgeAnonymizedCriminalRecords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:purge-anonymized-criminal-records';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove registos criminais pendentes de contas anonimizadas';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pendingRecords = DB::table('assistants')
            ->join('users', 'users.id', '=', 'assistants.user_id')
            ->whereNotNull('users.anonymized_at')
            ->whereNotNull('assistants.deleted_at')
            ->whereNotNull('assistants.criminal_record_path')
            ->where('assistants.criminal_record_path', '<>', '')
            ->select('assistants.id', 'assistants.criminal_record_path')
            ->lazyById(100, 'assistants.id', 'id');

        $failures = 0;

        foreach ($pendingRecords as $record) {
            try {
                $configuredDisk = config('filesystems.documents_disk');
                $disk = str_contains($record->criminal_record_path, 'criminal_record') ? $configuredDisk : 'local';
                if (! Storage::disk($disk)->delete($record->criminal_record_path)) {
                    throw new \RuntimeException('Falha ao remover o ficheiro.');
                }

                DB::table('assistants')
                    ->where('id', $record->id)
                    ->where('criminal_record_path', $record->criminal_record_path)
                    ->update(['criminal_record_path' => null]);
            } catch (Throwable $exception) {
                report($exception);

                $failures++;

                $this->error("Limpeza pendente para o assistente {$record->id}.");
            }
        }

        $pendingAbsenceDocs = DB::table('absences')
            ->join('assistants', 'assistants.id', '=', 'absences.assistant_id')
            ->leftJoin('users', 'users.id', '=', 'assistants.user_id')
            ->where(function ($q) {
                $q->where('assistants.is_anonymized', true)
                  ->orWhereNotNull('users.anonymized_at');
            })
            ->whereNotNull('absences.document_path')
            ->where('absences.document_path', '<>', '')
            ->select('absences.id', 'absences.document_path')
            ->lazyById(100, 'absences.id', 'id');

        foreach ($pendingAbsenceDocs as $absenceDoc) {
            try {
                $configuredDisk = config('filesystems.documents_disk');
                $disk = str_contains($absenceDoc->document_path, 'absences_files') ? $configuredDisk : 'local';
                if (! Storage::disk($disk)->delete($absenceDoc->document_path)) {
                    throw new \RuntimeException('Falha ao remover o ficheiro comprovativo de ausência.');
                }

                DB::table('absences')
                    ->where('id', $absenceDoc->id)
                    ->where('document_path', $absenceDoc->document_path)
                    ->update([
                        'document_path' => null,
                        'document_name' => null,
                    ]);
            } catch (Throwable $exception) {
                report($exception);

                $failures++;

                $this->error("Limpeza pendente para a ausência {$absenceDoc->id}.");
            }
        }

        if ($failures > 0) {
            return self::FAILURE;
        }

        $this->info('Limpeza concluída. Não foram detetadas falhas.');

        return self::SUCCESS;
    }
}
