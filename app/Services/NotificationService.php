<?php

namespace App\Services;

use App\Enums\NotificationEvent;
use App\Enums\NotificationType;
use App\Models\Absences\Absence;
use App\Models\Assistants\AssistantException;
use App\Models\Assistants\AssistantTemporaryAssignments;
use App\Models\Auth\User;
use App\Models\Schedules\AssistantScheduleProfile;
use App\Models\Schedules\ScheduleRule;
use App\Models\System\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cria e consulta as notificações do sistema.
 *
 * - Criar: `notifyAdmins()` (genérico) + um método por evento (textos em PT-PT centralizados aqui).
 *   Nunca lança exceções: uma falha a notificar não pode estragar a operação principal (falta, geração, ...).
 * - Consultar: `paginateFor()`, `unreadCounts()`, `markAsRead()`, `markAllAsRead()` (usados pelo NotificationController).
 */
class NotificationService
{
    public const EXCEPTION_DAYS = 7;
    public const PROFILE_DAYS = 14;
    public const RULE_DAYS = 14;
    public const TRANSFER_DAYS = 3;
    public const PENDING_ABSENCE_DAYS = 3;

    // =====================================================================
    // CRIAR
    // =====================================================================

    /**
     * Cria a notificação para todos os administradores ativos.
     *
     * @param  array<string, mixed>  $data  Dados extra (ids, datas, `link` para o Frontend navegar).
     * @param  string|null  $dedupeKey  Se existir, cada administrador só recebe uma vez esta chave.
     * @param  bool  $excludeActor  Não notificar quem provocou o evento (ex.: quem gerou a escala).
     * @return int Número de notificações criadas.
     */
    public function notifyAdmins(
        NotificationEvent $event,
        string $title,
        ?string $body = null,
        ?string $schoolId = null,
        array $data = [],
        ?string $dedupeKey = null,
        ?User $actor = null,
        bool $excludeActor = false,
    ): int {
        try {
            $recipients = User::query()
                ->where('is_active', true)
                ->whereNull('anonymized_at')
                ->whereHas('role', fn ($q) => $q->where('slug', 'admin'))
                ->when($excludeActor && $actor, fn ($q) => $q->whereKeyNot($actor->getKey()))
                ->pluck('id');

            $created = 0;
            foreach ($recipients as $userId) {
                if ($dedupeKey !== null
                    && Notification::where('user_id', $userId)->where('dedupe_key', $dedupeKey)->exists()) {
                    continue;
                }

                Notification::create([
                    'user_id' => $userId,
                    'school_id' => $schoolId,
                    'type' => $event->type()->value,
                    'event' => $event->value,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data ?: null,
                    'dedupe_key' => $dedupeKey,
                ]);
                $created++;
            }

            return $created;
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    // ---- Alertas -------------------------------------------------------

    /**
     * Uma falta/exceção afetou uma escala já gerada (resultado de ScheduleIntegrityService::checkImpact).
     *
     * @param  array<string, mixed>  $impact
     */
    public function scheduleRecalculationRequired(
        string $schoolId,
        array $impact,
        ?string $assistantName = null,
        ?string $cause = 'falta',
        ?User $actor = null,
    ): int {
        if (empty($impact['requires_recalculation'])) {
            return 0;
        }

        $dates = array_values($impact['affected_dates'] ?? []);
        sort($dates);
        $count = count($dates);
        $range = $count > 0 ? ($count === 1 ? $this->fmt($dates[0]) : $this->fmt($dates[0]).' a '.$this->fmt($dates[$count - 1])) : '';
        $who = $assistantName ? " de {$assistantName}" : '';

        return $this->notifyAdmins(
            NotificationEvent::ScheduleRecalculationRequired,
            'Escala precisa de recálculo',
            "A {$cause}{$who} afeta a escala já gerada em {$count} dia(s)".($range ? " ({$range})" : '').'. Recalcule o horário.',
            $schoolId,
            ['affected_dates' => $dates, 'link' => '/dashboard'],
            actor: $actor,
        );
    }

    /**
     * Geração terminou com conflitos de cobertura obrigatória.
     *
     * @param  array<string, mixed>  $result  Resposta de ScheduleGenerationService::generate().
     */
    public function scheduleGenerationDeficit(string $schoolId, array $result, ?User $actor = null): int
    {
        $conflicts = count($result['conflicts'] ?? []);
        if ($conflicts === 0) {
            return 0;
        }

        $start = $result['start_date'] ?? null;
        $end = $result['end_date'] ?? null;

        return $this->notifyAdmins(
            NotificationEvent::ScheduleGenerationDeficit,
            'Geração com défice de cobertura',
            "A geração de {$this->period($start, $end)} terminou com {$conflicts} conflito(s) de cobertura obrigatória por resolver.",
            $schoolId,
            ['start_date' => $start, 'end_date' => $end, 'conflicts_count' => $conflicts, 'link' => '/dashboard'],
            actor: $actor,
        );
    }

    /**
     * A pré-validação da geração avisou que há assistentes sem perfil de horário (1 aviso por escola/dia).
     *
     * @param  array<string, mixed>  $result
     */
    public function assistantsMissingProfile(string $schoolId, array $result, ?User $actor = null): int
    {
        $item = collect($result['validations']['pre']['items'] ?? [])
            ->first(fn ($i) => ($i['code'] ?? null) === 'pre-profile' && ($i['status'] ?? null) === 'warn');

        if (! $item) {
            return 0;
        }

        return $this->notifyAdmins(
            NotificationEvent::AssistantsMissingProfile,
            'Assistentes sem perfil de horário',
            $item['detail'] ?? 'Existem assistentes ativos sem perfil de horário configurado.',
            $schoolId,
            ['link' => '/assistants'],
            dedupeKey: "missing-profile:{$schoolId}:".CarbonImmutable::today()->toDateString(),
            actor: $actor,
        );
    }

    /**
     * Um utilizador pediu a desativação da conta (RGPD).
     */
    public function deactivationRequested(User $requester, ?string $reason = null): int
    {
        $requester->loadMissing('assistant');

        return $this->notifyAdmins(
            NotificationEvent::DeactivationRequested,
            'Pedido de desativação de conta',
            "{$requester->name} pediu a desativação da conta.".($reason ? " Motivo: {$reason}" : ''),
            $requester->assistant?->school_id,
            ['user_id' => $requester->id, 'link' => '/assistants'],
        );
    }

    // ---- Faltas --------------------------------------------------------

    /**
     * Um assistente (não-administrador) registou uma falta.
     */
    public function absenceRegistered(Absence $absence, ?User $actor = null): int
    {
        if ($actor?->isAdmin()) {
            return 0; // o administrador já sabe: foi ele que registou
        }

        $absence->loadMissing(['assistant.user', 'absenceType']);

        return $this->notifyAdmins(
            NotificationEvent::AbsenceRegistered,
            'Nova ausência registada',
            "{$this->assistantName($absence)} registou ausência".($absence->absenceType ? " ({$absence->absenceType->name})" : '')
                ." {$this->absencePeriod($absence)}.",
            $absence->assistant?->school_id,
            ['absence_id' => $absence->id, 'assistant_id' => $absence->assistant_id, 'link' => '/absences'],
            actor: $actor,
        );
    }

    /**
     * Um assistente (não-administrador) anexou/substituiu o comprovativo de uma falta.
     */
    public function absenceDocumentUploaded(Absence $absence, ?User $actor = null): int
    {
        if ($actor?->isAdmin()) {
            return 0;
        }

        $absence->loadMissing(['assistant.user']);

        return $this->notifyAdmins(
            NotificationEvent::AbsenceDocumentUploaded,
            'Comprovativo de falta anexado',
            "{$this->assistantName($absence)} anexou um comprovativo à ausência {$this->absencePeriod($absence)}. Valide e justifique a falta.",
            $absence->assistant?->school_id,
            ['absence_id' => $absence->id, 'assistant_id' => $absence->assistant_id, 'link' => '/absences'],
            actor: $actor,
        );
    }

    // ---- Vigências (chamadas pelo comando agendado) ---------------------

    public function exceptionExpiring(AssistantException $exception): int
    {
        $exception->loadMissing('assistant.user');
        $until = $exception->valid_until;
        $days = $this->daysUntil($until);

        return $this->notifyAdmins(
            NotificationEvent::ExceptionExpiring,
            'Exceção de horário a expirar',
            "A exceção de horário de {$this->nameOf($exception->assistant)} termina {$this->inDays($days)} ({$this->fmt($until)}).",
            $exception->assistant?->school_id,
            ['exception_id' => $exception->id, 'assistant_id' => $exception->assistant_id, 'valid_until' => $until->toDateString(), 'link' => "/profile/{$exception->assistant_id}"],
            dedupeKey: "exception:{$exception->id}:{$until->toDateString()}",
        );
    }

    public function profileExpiring(AssistantScheduleProfile $profile): int
    {
        $profile->loadMissing('assistant.user');
        $until = $profile->valid_until;
        $days = $this->daysUntil($until);

        return $this->notifyAdmins(
            NotificationEvent::ProfileExpiring,
            'Perfil de horário a expirar',
            "O perfil de horário de {$this->nameOf($profile->assistant)} termina {$this->inDays($days)} ({$this->fmt($until)}) e não há perfil seguinte.",
            $profile->assistant?->school_id,
            ['profile_id' => $profile->id, 'assistant_id' => $profile->assistant_id, 'valid_until' => $until->toDateString(), 'link' => "/profile/{$profile->assistant_id}"],
            dedupeKey: "profile:{$profile->id}:{$until->toDateString()}",
        );
    }

    public function ruleExpiring(ScheduleRule $rule): int
    {
        $rule->loadMissing('activityType');
        $until = $rule->valid_until;
        $days = $this->daysUntil($until);
        $activity = $rule->activityType?->name ?? 'sem atividade';

        return $this->notifyAdmins(
            NotificationEvent::RuleExpiring,
            'Regra de escala a expirar',
            "A regra de escala \"{$activity}\" ({$rule->period_start}–{$rule->period_end}) termina {$this->inDays($days)} ({$this->fmt($until)}).",
            $rule->school_id,
            ['rule_id' => $rule->id, 'valid_until' => $until->toDateString(), 'link' => '/config'],
            dedupeKey: "rule:{$rule->id}:{$until->toDateString()}",
        );
    }

    /**
     * Cedência a terminar: avisa a escola de origem e a de destino (duas notificações com chaves distintas).
     */
    public function transferEnding(AssistantTemporaryAssignments $transfer): int
    {
        $transfer->loadMissing(['assistant.user', 'originSchool', 'destinationSchool']);
        $until = $transfer->valid_until;
        $days = $this->daysUntil($until);
        $name = $this->nameOf($transfer->assistant);

        $created = 0;
        foreach ([
            'origin' => [$transfer->origin_school_id, "A cedência de {$name} para {$transfer->destinationSchool?->name} termina {$this->inDays($days)} ({$this->fmt($until)})."],
            'destination' => [$transfer->destination_school_id, "A cedência de {$name} vinda de {$transfer->originSchool?->name} termina {$this->inDays($days)} ({$this->fmt($until)})."],
        ] as $side => [$schoolId, $body]) {
            $created += $this->notifyAdmins(
                NotificationEvent::TransferEnding,
                'Cedência a terminar',
                $body,
                $schoolId,
                ['transfer_id' => $transfer->id, 'assistant_id' => $transfer->assistant_id, 'valid_until' => $until->toDateString(), 'link' => '/dashboard'],
                dedupeKey: "transfer:{$transfer->id}:{$side}:{$until->toDateString()}",
            );
        }

        return $created;
    }

    /**
     * Resumo semanal de faltas ainda por justificar há mais de N dias (1 por escola/semana).
     */
    public function absencesPendingReview(string $schoolId, int $count, ?string $oldestFrom = null): int
    {
        $week = CarbonImmutable::today()->format('o-\WW');

        return $this->notifyAdmins(
            NotificationEvent::AbsencesPendingReview,
            'Faltas por justificar',
            "Existem {$count} falta(s) pendentes há mais de ".self::PENDING_ABSENCE_DAYS." dias".($oldestFrom ? " (a mais antiga desde {$this->fmt($oldestFrom)})" : '').'.',
            $schoolId,
            ['pending_count' => $count, 'link' => '/absences'],
            dedupeKey: "pending-review:{$schoolId}:{$week}",
        );
    }

    // ---- Info ----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $result
     */
    public function scheduleGenerated(string $schoolId, array $result, ?User $actor = null): int
    {
        return $this->notifyAdmins(
            NotificationEvent::ScheduleGenerated,
            'Escala gerada',
            "A escala de {$this->period($result['start_date'] ?? null, $result['end_date'] ?? null)} foi gerada e validada".($actor ? " por {$actor->name}" : '').'.',
            $schoolId,
            ['start_date' => $result['start_date'] ?? null, 'end_date' => $result['end_date'] ?? null, 'link' => '/dashboard'],
            actor: $actor,
            excludeActor: true,
        );
    }

    public function schedulePublished(string $schoolId, string $startDate, string $endDate, int $count, ?User $actor = null): int
    {
        if ($count <= 0) {
            return 0;
        }

        return $this->notifyAdmins(
            NotificationEvent::SchedulePublished,
            'Escala publicada',
            "{$count} escala(s) de {$this->period($startDate, $endDate)} foram publicadas".($actor ? " por {$actor->name}" : '').'.',
            $schoolId,
            ['start_date' => $startDate, 'end_date' => $endDate, 'published_count' => $count, 'link' => '/dashboard'],
            actor: $actor,
            excludeActor: true,
        );
    }

    public function transferAssigned(AssistantTemporaryAssignments $transfer, ?User $actor = null): int
    {
        $transfer->loadMissing(['assistant.user', 'originSchool']);

        return $this->notifyAdmins(
            NotificationEvent::TransferAssigned,
            'Cedência atribuída',
            "{$this->nameOf($transfer->assistant)} (de {$transfer->originSchool?->name}) foi cedido(a) a esta escola em {$this->fmt($transfer->valid_from)}.",
            $transfer->destination_school_id,
            ['transfer_id' => $transfer->id, 'assistant_id' => $transfer->assistant_id, 'link' => '/dashboard'],
            actor: $actor,
            excludeActor: true,
        );
    }

    // =====================================================================
    // COMANDO DIÁRIO (notifications:check-expirations)
    // =====================================================================

    /**
     * Procura vigências a expirar e faltas por justificar e cria as notificações em falta.
     *
     * @return array<string, int> Notificações criadas por tipo de evento.
     */
    public function runScheduledChecks(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $created = ['exceptions' => 0, 'profiles' => 0, 'rules' => 0, 'transfers' => 0, 'pending_absences' => 0];

        AssistantException::query()
            ->whereHas('assistant')
            ->whereBetween('valid_until', [$today->toDateString(), $today->addDays(self::EXCEPTION_DAYS)->toDateString()])
            ->with('assistant.user')
            ->get()
            ->each(function ($e) use (&$created) {
                $created['exceptions'] += $this->exceptionExpiring($e);
            });

        AssistantScheduleProfile::query()
            ->whereHas('assistant')
            ->whereBetween('valid_until', [$today->toDateString(), $today->addDays(self::PROFILE_DAYS)->toDateString()])
            ->with('assistant.user')
            ->get()
            ->each(function (AssistantScheduleProfile $p) use (&$created) {
                $hasNext = AssistantScheduleProfile::query()
                    ->where('assistant_id', $p->assistant_id)
                    ->whereKeyNot($p->getKey())
                    ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $p->valid_until->toDateString()))
                    ->exists();
                if (! $hasNext) {
                    $created['profiles'] += $this->profileExpiring($p);
                }
            });

        ScheduleRule::query()
            ->whereBetween('valid_until', [$today->toDateString(), $today->addDays(self::RULE_DAYS)->toDateString()])
            ->with('activityType')
            ->get()
            ->each(function ($r) use (&$created) {
                $created['rules'] += $this->ruleExpiring($r);
            });

        AssistantTemporaryAssignments::query()
            ->whereBetween('valid_until', [$today->toDateString(), $today->addDays(self::TRANSFER_DAYS)->toDateString()])
            ->with(['assistant.user', 'originSchool', 'destinationSchool'])
            ->get()
            ->each(function ($t) use (&$created) {
                $created['transfers'] += $this->transferEnding($t);
            });

        DB::table('absences')
            ->join('assistants', 'assistants.id', '=', 'absences.assistant_id')
            ->whereNull('absences.deleted_at')
            ->where('absences.status', 'pending')
            ->where('absences.created_at', '<=', $today->subDays(self::PENDING_ABSENCE_DAYS)->endOfDay())
            ->groupBy('assistants.school_id')
            ->selectRaw('assistants.school_id as school_id, count(*) as total, min(absences.valid_from) as oldest')
            ->get()
            ->each(function ($row) use (&$created) {
                $created['pending_absences'] += $this->absencesPendingReview($row->school_id, (int) $row->total, $row->oldest);
            });

        return $created;
    }

    // =====================================================================
    // CONSULTAR (usado pela API)
    // =====================================================================

    /**
     * @param  array{type?: string|null, unread?: bool|null, per_page?: int|null}  $filters
     */
    public function paginateFor(User $user, ?string $schoolId, array $filters = []): LengthAwarePaginator
    {
        $query = $this->baseQuery($user, $schoolId);

        if (! empty($filters['type']) && in_array($filters['type'], NotificationType::values(), true)) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['unread'])) {
            $query->whereNull('read_at');
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * Contagem de não lidas: total e por categoria.
     *
     * @return array<string, int>
     */
    public function unreadCounts(User $user, ?string $schoolId): array
    {
        $rows = $this->baseQuery($user, $schoolId)
            ->whereNull('read_at')
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $counts = ['all' => 0];
        foreach (NotificationType::values() as $type) {
            $counts[$type] = (int) ($rows[$type] ?? 0);
            $counts['all'] += $counts[$type];
        }

        return $counts;
    }

    public function markAsRead(Notification $notification): Notification
    {
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification;
    }

    public function markAllAsRead(User $user, ?string $schoolId, ?string $type = null): int
    {
        $query = $this->baseQuery($user, $schoolId)->whereNull('read_at');

        if ($type && in_array($type, NotificationType::values(), true)) {
            $query->where('type', $type);
        }

        return $query->update(['read_at' => now()]);
    }

    // =====================================================================
    // Auxiliares
    // =====================================================================

    private function baseQuery(User $user, ?string $schoolId): Builder
    {
        return Notification::query()->where('user_id', $user->getKey())->forSchool($schoolId);
    }

    private function fmt(mixed $date): string
    {
        return CarbonImmutable::parse($date)->format('d/m/Y');
    }

    private function period(?string $start, ?string $end): string
    {
        if (! $start) {
            return 'período indicado';
        }

        return (! $end || $end === $start) ? $this->fmt($start) : $this->fmt($start).' a '.$this->fmt($end);
    }

    private function absencePeriod(Absence $absence): string
    {
        $from = $absence->valid_from;
        $until = $absence->valid_until ?? $from;

        return ($from && $until && $from->toDateString() !== $until->toDateString())
            ? 'de '.$this->fmt($from).' a '.$this->fmt($until)
            : 'em '.($from ? $this->fmt($from) : 'data indicada');
    }

    private function assistantName(Absence $absence): string
    {
        return $this->nameOf($absence->assistant);
    }

    private function nameOf(mixed $assistant): string
    {
        return $assistant?->user?->name ?: 'Um assistente';
    }

    private function daysUntil(mixed $date): int
    {
        return (int) max(0, CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($date)->startOfDay(), false));
    }

    private function inDays(int $days): string
    {
        return match (true) {
            $days <= 0 => 'hoje',
            $days === 1 => 'amanhã',
            default => "em {$days} dias",
        };
    }
}
