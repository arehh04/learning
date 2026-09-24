<?php

namespace App\Services\AI;

use App\Exceptions\AI\AiConfidenceCheckFailedException;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;

class JevAdapter
{
    public function __construct(
        private readonly string $apiKey,
    ) {
    }

    public function evaluateConfidence(Conversation $conversation, string $draft): float
    {
        $transcript = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn (Message $message) => ($message->direction === Message::DIRECTION_INBOUND ? 'Customer' : 'Agent').': '.$message->body)
            ->implode("\n");

        $response = Http::withToken($this->apiKey)->post('https://api.typesafe.ai/v1/systemone', [
            'state' => $transcript."\n\nDrafted reply: ".$draft,
            'model' => 'jev-latest',
            'questions' => [
                'confident_to_send' => [
                    'type' => 'noul',
                    'instructions' => 'Given the conversation and the drafted reply, is this reply confident and appropriate to send to the customer as-is?',
                    'criteria' => [
                        'true' => 'confident and appropriate to send',
                        'false' => 'uncertain, incorrect, or needs a human',
                    ],
                ],
            ],
        ]);

        if (! $response->successful()) {
            throw new AiConfidenceCheckFailedException('Jev API request failed: '.$response->body());
        }

        $score = $response->json('answers.confident_to_send.noul');

        if (! is_numeric($score)) {
            throw new AiConfidenceCheckFailedException('Jev response missing a numeric confidence score.');
        }

        return (float) $score;
    }
}
