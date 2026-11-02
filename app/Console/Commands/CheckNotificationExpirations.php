<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

class CheckNotificationExpirations extends Command
{
    protected $signature = 'notifications:check-expirations';

    protected $description = 'Cria notificações para vigências a expirar (exceções, exceções de funcionamento, perfis, regras, cedências) e faltas por justificar.';

    public function handle(NotificationService $notifications): int
    {
        $created = $notifications->runScheduledChecks();

        foreach ($created as $kind => $count) {
            $this->line(sprintf('%-18s %d', $kind, $count));
        }
        $this->info('Verificação concluída.');

        return self::SUCCESS;
    }
}
