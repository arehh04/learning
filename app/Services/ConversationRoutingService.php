<?php

namespace App\Services;

use App\Models\Conversation;

class ConversationRoutingService
{
    public function decideInitialOwner(): string
    {
        // MVP: the AI agent is a routing stub with no real logic yet, so
        // every new conversation lands in the shared human queue. The
        // "ai" owner type exists on Conversation for when real routing
        // logic replaces this trivial rule — see the design spec §3, §6.
        return Conversation::OWNER_UNASSIGNED;
    }
}
