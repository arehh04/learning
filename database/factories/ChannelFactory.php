<?php

namespace Database\Factories;

use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    public function definition(): array
    {
        return [
            'type' => 'telegram',
            'label' => 'Telegram Bot',
            'credentials' => json_encode(['bot_token' => 'test-token']),
            'is_active' => true,
        ];
    }
}
