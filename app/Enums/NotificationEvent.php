<?php

namespace App\Enums;

/**
 * Catálogo de eventos que geram notificações para os administradores.
 * Cada evento pertence a uma categoria (NotificationType).
 */
enum NotificationEvent: string
{
    // Alertas
    case ScheduleRecalculationRequired = 'schedule.recalculation_required';
    case ScheduleGenerationDeficit = 'schedule.generation_deficit';
    case AssistantsMissingProfile = 'assistant.missing_profile';
    case DeactivationRequested = 'privacy.deactivation_requested';

    // Faltas
    case AbsenceRegistered = 'absence.registered';
    case AbsenceDocumentUploaded = 'absence.document_uploaded';
    case AbsencesPendingReview = 'absence.pending_review';

    // Vigências
    case ExceptionExpiring = 'exception.expiring';
    case OperatingExceptionExpiring = 'operating_exception.expiring';
    case ProfileExpiring = 'profile.expiring';
    case RuleExpiring = 'rule.expiring';
    case TransferEnding = 'transfer.ending';

    // Info
    case ScheduleGenerated = 'schedule.generated';
    case SchedulePublished = 'schedule.published';
    case TransferAssigned = 'transfer.assigned';

    public function type(): NotificationType
    {
        return match ($this) {
            self::ScheduleRecalculationRequired,
            self::ScheduleGenerationDeficit,
            self::AssistantsMissingProfile,
            self::DeactivationRequested => NotificationType::Alert,

            self::AbsenceRegistered,
            self::AbsenceDocumentUploaded,
            self::AbsencesPendingReview => NotificationType::Absence,

            self::ExceptionExpiring,
            self::OperatingExceptionExpiring,
            self::ProfileExpiring,
            self::RuleExpiring,
            self::TransferEnding => NotificationType::Expiry,

            self::ScheduleGenerated,
            self::SchedulePublished,
            self::TransferAssigned => NotificationType::Info,
        };
    }
}
