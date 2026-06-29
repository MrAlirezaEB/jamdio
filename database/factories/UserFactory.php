<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $avatars = config('radio.avatars', ['avatars/a1.svg']);

        return [
            'nickname' => fake()->userName(),
            'avatar_path' => fake()->randomElement($avatars),
            'session_id' => Str::random(40),
            'ip_address' => fake()->ipv4(),
            'is_blocked' => false,
            'last_active_at' => now(),
        ];
    }

    /** A guest who is offline (last active beyond the active window). */
    public function offline(): static
    {
        return $this->state(fn () => [
            'last_active_at' => now()->subMinutes(5),
        ]);
    }

    /** A blocked guest. */
    public function blocked(): static
    {
        return $this->state(fn () => [
            'is_blocked' => true,
        ]);
    }
}
