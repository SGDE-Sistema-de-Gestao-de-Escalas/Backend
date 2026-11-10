<?php

namespace App\Services\Assistants;

use App\Models\Assistants\Assistant;
use App\Support\DocumentExtension;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AssistantDocumentStorageService
{
    /**
     * Obtém o disco configurado para documentos (ex: 'public' ou 'r2'),
     * mantendo compatibilidade com caminhos legados no disco 'local'.
     */
    public function getDisk(?string $path = null): string
    {
        $configuredDisk = (string) config('filesystems.documents_disk');

        if ($path !== null && $path !== '') {
            return (str_contains($path, 'criminal_record') || str_contains($path, 'absences_files'))
                ? $configuredDisk
                : 'local';
        }

        return $configuredDisk;
    }

    /**
     * Gera o caminho da pasta para os documentos de um assistente:
     * {nome_assistente}_{nr_interno}/{subfolder}
     */
    public function getAssistantDirectory(
        string $firstName,
        string $lastName,
        string $internalNumber,
        string $subfolder
    ): string {
        $normalizedName = preg_replace('/[\/\\\_\-\.]+/', ' ', trim("{$firstName} {$lastName}"));
        $normalizedInternal = preg_replace('/[\/\\\_\-\.]+/', ' ', trim($internalNumber));

        $safeName = Str::slug((string) $normalizedName, '_') ?: 'assistente';
        $safeInternal = Str::slug((string) $normalizedInternal, '_') ?: 'sem_numero';

        return "{$safeName}_{$safeInternal}/{$subfolder}";
    }

    /**
     * Guarda o ficheiro de registo criminal no disco configurado.
     */
    public function storeCriminalRecord(
        UploadedFile $file,
        string $firstName,
        string $lastName,
        string $internalNumber
    ): string {
        $folder = $this->getAssistantDirectory($firstName, $lastName, $internalNumber, 'criminal_record');
        $extension = DocumentExtension::for($file);
        $filename = 'registo_criminal_' . time() . '_' . Str::random(6) . ".{$extension}";

        return $file->storeAs($folder, $filename, $this->getDisk());
    }

    /**
     * Guarda o ficheiro comprovativo de falta no disco configurado.
     */
    public function storeAbsenceDocument(UploadedFile $file, Assistant $assistant): string
    {
        $assistant->loadMissing('user');

        $firstName = $assistant->user?->first_name ?? 'assistente';
        $lastName = $assistant->user?->last_name ?? '';
        $internalNumber = $assistant->internal_number ?? (string) $assistant->id;

        $folder = $this->getAssistantDirectory($firstName, $lastName, (string) $internalNumber, 'absences_files');
        $extension = DocumentExtension::for($file);
        $filename = 'ausencia_' . time() . '_' . Str::random(6) . ".{$extension}";

        return $file->storeAs($folder, $filename, $this->getDisk());
    }

    /**
     * Remove um ficheiro do disco correspondente.
     */
    public function deleteFile(?string $path, bool $strict = false): bool
    {
        if ($path === null || $path === '') {
            return true;
        }

        $disk = $this->getDisk($path);

        if ($strict) {
            return (bool) Storage::disk($disk)->delete($path);
        }

        try {
            return (bool) Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Remove todos os ficheiros físicos associados a um assistente (registo criminal e justificações de faltas)
     * e limpa os respetivos caminhos na base de dados.
     */
    public function purgeAllAssistantFiles(
        string $assistantId,
        ?string $criminalRecordPath = null,
        bool $strict = false
    ): void {
        if ($criminalRecordPath !== null && $criminalRecordPath !== '') {
            if (! $this->deleteFile($criminalRecordPath, $strict) && $strict) {
                throw new RuntimeException('Não foi possível remover o ficheiro do registo criminal.');
            }

            DB::table('assistants')
                ->where('id', $assistantId)
                ->where('criminal_record_path', $criminalRecordPath)
                ->update([
                    'criminal_record_path' => null,
                    'criminal_record_name' => null,
                ]);
        }

        $absenceDocs = DB::table('absences')
            ->where('assistant_id', $assistantId)
            ->whereNotNull('document_path')
            ->where('document_path', '<>', '')
            ->select('id', 'document_path')
            ->get();

        foreach ($absenceDocs as $absenceDoc) {
            if (! $this->deleteFile($absenceDoc->document_path, $strict) && $strict) {
                throw new RuntimeException('Não foi possível remover o ficheiro comprovativo de ausência.');
            }

            DB::table('absences')
                ->where('id', $absenceDoc->id)
                ->where('document_path', $absenceDoc->document_path)
                ->update([
                    'document_path' => null,
                    'document_name' => null,
                ]);
        }

        DB::table('absences')
            ->where('assistant_id', $assistantId)
            ->whereNotNull('document_name')
            ->update(['document_name' => null]);
    }

    /**
     * Devolve a resposta HTTP para download/visualização segura de um documento.
     */
    public function downloadResponse(
        ?string $path,
        string $notFoundMessage,
        string $missingOnDiskMessage
    ): StreamedResponse {
        if (! $path) {
            abort(404, $notFoundMessage);
        }

        $disk = $this->getDisk();

        if (! Storage::disk($disk)->exists($path)) {
            abort(404, $missingOnDiskMessage);
        }

        return Storage::disk($disk)->response($path, null, ['X-Content-Type-Options' => 'nosniff']);
    }
}
