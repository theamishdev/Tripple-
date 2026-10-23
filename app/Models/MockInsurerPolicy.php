<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MockInsurerPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_reference',
        'client_request_id',
        'agent_id',
        'policy_number',
        'status',
        'plan_code',
        'premium_amount',
        'payload',
    ];

    protected $casts = [
        'premium_amount' => 'decimal:2',
        'payload' => 'array',
    ];
}
