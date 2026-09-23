<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Channels\TelegramAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendTelegramReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $messageId)
    {
    }

    public function handle(TelegramAdapter $adapter): void
    {
        $message = Message::with('conversation.contact')->findOrFail($this->messageId);
        $contact = $message->conversation->contact;

        $result = $adapter->send($contact->external_contact_id, $message->body);

        $message->update([
            'status' => Message::STATUS_SENT,
            'external_message_id' => $result->externalMessageId,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Message::whereKey($this->messageId)->update(['status' => Message::STATUS_FAILED]);
    }
}
