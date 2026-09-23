<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'channel_id' => Channel::factory(),
            'status' => Conversation::STATUS_OPEN,
            'owner_type' => Conversation::OWNER_UNASSIGNED,
        ];
    }
}
