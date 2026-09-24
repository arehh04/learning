<?php

namespace App\Services\AI;

use App\Exceptions\AI\AiDraftingFailedException;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ClaudeAdapter
{
    private const MAX_HISTORY_MESSAGES = 20;

    private const SYSTEM_PROMPT = 'You are a helpful customer support assistant. Answer the customer clearly and concisely based on the conversation so far.';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    public function draft(Conversation $conversation): string
    {
        $history = $conversation->messages()
            ->orderByDesc('created_at')
            ->limit(self::MAX_HISTORY_MESSAGES)
            ->get()
            ->sortBy('created_at')
            ->values()
            ->map(fn (Message $message) => [
                'role' => $message->direction === Message::DIRECTION_INBOUND ? 'user' : 'assistant',
                'content' => $message->body,
            ])
            ->all();

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(15)->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 1024,
                'system' => self::SYSTEM_PROMPT,
                'messages' => $history,
            ]);
        } catch (ConnectionException $e) {
            throw new AiDraftingFailedException('Claude API connection failed: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new AiDraftingFailedException('Claude API request failed: '.$response->body());
        }

        $draft = trim((string) $response->json('content.0.text'));

        if ($draft === '') {
            throw new AiDraftingFailedException('Claude returned an empty draft.');
        }

        return $draft;
    }
}
