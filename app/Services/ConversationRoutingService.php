<?php

namespace App\Services;

use App\Models\Conversation;

class ConversationRoutingService
{
    public function decideInitialOwner(): string
    {
        if (! config('services.ai_agent.enabled')) {
            return Conversation::OWNER_UNASSIGNED;
        }

        return Conversation::OWNER_AI;
    }
}
