<?php

namespace App\Models;

use App\Enums\PolicyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PolicyRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'travel_days',
        'plan_code',
        'sum_insured',
        'premium_amount',
        'currency',
        'pricing_breakdown',
        'status',
        'policy_number',
        'provider_reference',
        'attempt_count',
        'last_error',
        'issued_at',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'trip_start' => 'date:Y-m-d',
        'trip_end' => 'date:Y-m-d',
        'travel_days' => 'integer',
        'sum_insured' => 'decimal:2',
        'premium_amount' => 'decimal:2',
        'pricing_breakdown' => 'array',
        'attempt_count' => 'integer',
        'issued_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PolicyRequest $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class)->orderBy('attempt_number', 'asc');
    }

    public function stateHistories(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class)->orderBy('created_at', 'asc');
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class)->orderBy('created_at', 'asc');
    }

    public function isIssued(): bool
    {
        return $this->status === PolicyStatus::ISSUED->value;
    }
}
