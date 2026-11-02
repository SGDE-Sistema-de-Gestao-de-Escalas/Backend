<?php

namespace App\Models\System;

use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'school_id',
        'type',
        'event',
        'title',
        'body',
        'data',
        'dedupe_key',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Notificações da escola indicada + as globais (sem escola, ex.: pedidos RGPD).
     */
    public function scopeForSchool(Builder $query, ?string $schoolId): Builder
    {
        if (! $schoolId) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->where('school_id', $schoolId)->orWhereNull('school_id'));
    }
}
