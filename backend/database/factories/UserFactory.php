<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'name' => fake()->name(),
            'email' => null,
            'password' => static::$password ??= Hash::make('password'),
            'role_id' => Role::firstOrCreate(['slug' => Role::VENDEDOR], ['name' => 'Vendedor'])->id,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function role(string $slug): static
    {
        return $this->state(fn () => [
            'role_id' => Role::firstOrCreate(['slug' => $slug], ['name' => $slug])->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
