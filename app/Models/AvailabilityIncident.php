<?php

namespace App\Models;

use App\Enums\AvailabilityIncidentReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A period in which a server or an application was down. An open incident (ended_at null) is still going on.
 */
class AvailabilityIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'application_id',
        'reason',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'reason' => AvailabilityIncidentReason::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function durationInSeconds(): int
    {
        return max(0, ($this->ended_at ?? now())->getTimestamp() - $this->started_at->getTimestamp());
    }
}
