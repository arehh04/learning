<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    public const OWNER_UNASSIGNED = 'unassigned';
    public const OWNER_AI = 'ai';
    public const OWNER_HUMAN = 'human';

    protected $fillable = [
        'contact_id', 'channel_id', 'status', 'owner_type', 'owner_agent_id',
        'last_inbound_at', 'opened_at', 'closed_at',
    ];

    protected $casts = [
        'last_inbound_at' => 'datetime',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function ownerAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function handoffEvents(): HasMany
    {
        return $this->hasMany(HandoffEvent::class);
    }
}
