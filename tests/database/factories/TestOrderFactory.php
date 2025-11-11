<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Database\Factories;

use Condoedge\Ai\Tests\Fixtures\TestOrder;
use Condoedge\Ai\Tests\Fixtures\TestCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test Order Factory
 *
 * Generates fake order data for feature tests
 *
 * @extends Factory<TestOrder>
 */
class TestOrderFactory extends Factory
{
    protected $model = TestOrder::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'customer_id' => TestCustomer::factory(),
            'order_number' => 'ORD-' . $this->faker->unique()->numerify('######'),
            'total' => $this->faker->randomFloat(2, 10, 5000),
            'status' => $this->faker->randomElement(['pending', 'processing', 'completed', 'cancelled']),
            'order_date' => $this->faker->dateTimeBetween('-1 year', 'now'),
        ];
    }

    /**
     * Indicate that the order is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Indicate that the order is processing.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
        ]);
    }

    /**
     * Indicate that the order is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    /**
     * Indicate that the order is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }

    /**
     * Create a large order.
     */
    public function large(): static
    {
        return $this->state(fn (array $attributes) => [
            'total' => $this->faker->randomFloat(2, 1000, 10000),
        ]);
    }

    /**
     * Create a recent order.
     */
    public function recent(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_date' => $this->faker->dateTimeBetween('-30 days', 'now'),
        ]);
    }

    /**
     * Create an order for a specific customer.
     */
    public function forCustomer(TestCustomer $customer): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_id' => $customer->id,
        ]);
    }
}
