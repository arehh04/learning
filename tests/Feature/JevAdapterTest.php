<?php

namespace Tests\Feature;

use App\Exceptions\AI\AiConfidenceCheckFailedException;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\JevAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JevAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluate_confidence_returns_the_noul_score(): void
    {
        Http::fake([
            'api.typesafe.ai/*' => Http::response([
                'model' => 'jev-latest',
                'answers' => ['confident_to_send' => ['type' => 'noul', 'noul' => 0.92]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
            ], 200),
        ]);

        $conversation = Conversation::factory()->create();
        Message::factory()->create(['conversation_id' => $conversation->id]);

        $adapter = new JevAdapter('fake-key');

        $score = $adapter->evaluateConfidence($conversation->fresh(), 'Sure, we can help with that.');

        $this->assertSame(0.92, $score);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.typesafe.ai/v1/systemone'
                && $request['model'] === 'jev-latest'
                && $request['questions']['confident_to_send']['type'] === 'noul';
        });
    }

    public function test_evaluate_confidence_throws_when_jev_api_fails(): void
    {
        Http::fake([
            'api.typesafe.ai/*' => Http::response(['error' => 'rate limited'], 429),
        ]);

        $conversation = Conversation::factory()->create();

        $adapter = new JevAdapter('fake-key');

        $this->expectException(AiConfidenceCheckFailedException::class);
        $adapter->evaluateConfidence($conversation->fresh(), 'a draft');
    }

    public function test_evaluate_confidence_throws_when_the_noul_field_is_missing(): void
    {
        Http::fake([
            'api.typesafe.ai/*' => Http::response([
                'model' => 'jev-latest',
                'answers' => ['confident_to_send' => ['type' => 'noul']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
            ], 200),
        ]);

        $conversation = Conversation::factory()->create();

        $adapter = new JevAdapter('fake-key');

        $this->expectException(AiConfidenceCheckFailedException::class);
        $adapter->evaluateConfidence($conversation->fresh(), 'a draft');
    }

    public function test_evaluate_confidence_throws_when_the_noul_field_is_not_numeric(): void
    {
        Http::fake([
            'api.typesafe.ai/*' => Http::response([
                'model' => 'jev-latest',
                'answers' => ['confident_to_send' => ['type' => 'noul', 'noul' => 'unsure']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
            ], 200),
        ]);

        $conversation = Conversation::factory()->create();

        $adapter = new JevAdapter('fake-key');

        $this->expectException(AiConfidenceCheckFailedException::class);
        $adapter->evaluateConfidence($conversation->fresh(), 'a draft');
    }
}
