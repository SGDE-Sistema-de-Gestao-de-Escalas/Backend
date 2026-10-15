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

        if ($failures > 0) {
            return self::FAILURE;
        }

        $this->info('Limpeza concluída. Não foram detetadas falhas.');

        return self::SUCCESS;
    }
}
