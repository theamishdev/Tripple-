<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuanceAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'policy_request_id',
        'attempt_number',
        'scenario',
        'status',
        'request_payload',
        'response_payload',
        'error_message',
        'latency_ms',
        'provider_reference',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'latency_ms' => 'integer',
        'attempt_number' => 'integer',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
