<?php

namespace App\Services;

use App\Jobs\SendTelegramReplyJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

class ReplyDispatchService
{
    public function send(Conversation $conversation, User $agent, string $body): Message
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'sender_type' => 'agent',
            'sender_id' => $agent->id,
            'body' => $body,
            'status' => Message::STATUS_PENDING,
        ]);

        SendTelegramReplyJob::dispatch($message->id);

        return $message;
    }
}
