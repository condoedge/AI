<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Database\Factories;

use Condoedge\Ai\Tests\Fixtures\TestCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test Customer Factory
 *
 * Generates fake customer data for feature tests
 *
 * @extends Factory<TestCustomer>
 */
class TestCustomerFactory extends Factory
{
    protected $model = TestCustomer::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'status' => $this->faker->randomElement(['active', 'inactive', 'pending']),
            'country' => $this->faker->randomElement(['USA', 'Canada', 'UK', 'Germany', 'France', 'Australia']),
            'lifetime_value' => $this->faker->randomFloat(2, 100, 50000),
        ];
    }

    /**
     * Indicate that the customer is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Indicate that the customer is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Indicate that the customer is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Create a high-value customer.
     */
    public function highValue(): static
    {
        return $this->state(fn (array $attributes) => [
            'lifetime_value' => $this->faker->randomFloat(2, 10000, 100000),
        ]);
    }

    /**
     * Create a customer from a specific country.
     */
    public function fromCountry(string $country): static
    {
        return $this->state(fn (array $attributes) => [
            'country' => $country,
        ]);
    }
}
