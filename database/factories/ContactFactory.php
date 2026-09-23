<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'external_contact_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'name' => fake()->name(),
            'metadata' => [],
        ];
    }
}
