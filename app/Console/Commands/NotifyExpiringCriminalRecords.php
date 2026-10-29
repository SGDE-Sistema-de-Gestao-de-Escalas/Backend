<?php

namespace App\Console\Commands;

use App\Mail\CriminalRecordExpiryAlertMail;
use App\Models\Assistants\Assistant;
use App\Models\Auth\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotifyExpiringCriminalRecords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:notify-expiring-criminal-records';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envia alertas por email aos administradores quando o registo criminal de um assistente atinge os marcos de 30 dias, 7 dias ou expira no próprio dia.';

    /**
     * Marcos exatos de aviso (em dias até à expiração).
     */
    private const MILESTONE_DAYS = [30, 7, 0];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $admins = User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', 'admin'))
            ->get();

        if ($admins->isEmpty()) {
            $this->info('Nenhum administrador ativo encontrado.');
            return self::SUCCESS;
        }

        $today = Carbon::today();
        $targetDatesByDays = [];
        foreach (self::MILESTONE_DAYS as $days) {
            $targetDatesByDays[$days] = $today->copy()->addDays($days)->format('Y-m-d');
        }

        $assistants = Assistant::forScheduleGeneration()
            ->with(['user', 'school'])
            ->whereNotNull('criminal_record_expiry')
            ->whereIn('criminal_record_expiry', array_values($targetDatesByDays))
            ->get();

        $sentCount = 0;

        foreach ($assistants as $assistant) {
            $assistantUser = $assistant->user;
            if (! $assistantUser || ! $assistant->criminal_record_expiry) {
                continue;
            }

            $expiryDateStr = Carbon::parse($assistant->criminal_record_expiry)->format('Y-m-d');
            $daysRemaining = (int) array_search($expiryDateStr, $targetDatesByDays, true);
            $schoolName = $assistant->school?->name ?? 'Estabelecimento de Ensino';

            try {
                Mail::to($admins)->send(new CriminalRecordExpiryAlertMail(
                    assistant: $assistant,
                    assistantUser: $assistantUser,
                    schoolName: $schoolName,
                    daysRemaining: $daysRemaining,
                ));
                $sentCount++;
            } catch (Throwable $e) {
                report($e);
                $this->error("Falha ao enviar alerta para o assistente {$assistant->id}.");
            }
        }

        $this->info("Verificação concluída. {$sentCount} alerta(s) enviado(s) aos administradores.");

        return self::SUCCESS;
    }
}

