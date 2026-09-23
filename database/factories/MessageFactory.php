<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'direction' => Message::DIRECTION_INBOUND,
            'sender_type' => 'contact',
            'body' => fake()->sentence(),
            'status' => Message::STATUS_RECEIVED,
        ];
    }
}
