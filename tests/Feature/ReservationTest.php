<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'TEST-001',
            'name' => 'Test product',
            'initial_stock' => 10,
            'available_quantity' => 10,
        ]);
    }

    public function test_reservation_reduces_available_stock(): void
    {
        $this->postJson($this->endpoint(), [
            'quantity' => 3,
        ], [
            'Idempotency-Key' => 'order-001',
        ])
            ->assertCreated()
            ->assertJsonPath('data.quantity', 3)
            ->assertJsonPath('data.replayed', false);

        $this->assertDatabaseHas('reservations', [
            'product_id' => $this->product->id,
            'quantity' => 3,
            'idempotency_key' => 'order-001',
        ]);

        $this->assertDatabaseCount('reservations', 1);
        $this->assertStockInvariant(7);
    }

    public function test_insufficient_stock_leaves_database_unchanged(): void
    {
        $this->product->update([
            'initial_stock' => 3,
            'available_quantity' => 3,
        ]);

        $this->postJson($this->endpoint(), [
            'quantity' => 5,
        ], [
            'Idempotency-Key' => 'order-002',
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

        $this->assertDatabaseCount('reservations', 0);
        $this->assertStockInvariant(3);
    }

    public function test_repeated_request_returns_original_reservation(): void
    {
        $first = $this->postJson($this->endpoint(), [
            'quantity' => 2,
        ], [
            'Idempotency-Key' => 'same-key',
        ])->assertCreated();

        $this->postJson($this->endpoint(), [
            'quantity' => 2,
        ], [
            'Idempotency-Key' => 'same-key',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.reservation_id',
                $first->json('data.reservation_id'),
            )
            ->assertJsonPath('data.replayed', true);

        $this->assertDatabaseCount('reservations', 1);
        $this->assertStockInvariant(8);
    }

    public function test_reusing_key_with_different_quantity_is_rejected(): void
    {
        $this->postJson($this->endpoint(), [
            'quantity' => 2,
        ], [
            'Idempotency-Key' => 'same-key',
        ])->assertCreated();

        $this->postJson($this->endpoint(), [
            'quantity' => 3,
        ], [
            'Idempotency-Key' => 'same-key',
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $this->assertDatabaseHas('reservations', [
            'product_id' => $this->product->id,
            'idempotency_key' => 'same-key',
            'quantity' => 2,
        ]);

        $this->assertDatabaseCount('reservations', 1);
        $this->assertStockInvariant(8);
    }

    public function test_invalid_quantities_are_rejected(): void
    {
        foreach ([0, -1, 11, 1.5, 'abc', null] as $quantity) {
            $this->postJson($this->endpoint(), [
                'quantity' => $quantity,
            ], [
                'Idempotency-Key' => 'invalid-quantity',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('quantity');
        }

        $this->assertDatabaseCount('reservations', 0);
        $this->assertStockInvariant(10);
    }

    public function test_idempotency_header_is_required(): void
    {
        $this->postJson($this->endpoint(), [
            'quantity' => 1,
            'idempotency_key' => 'body-key-is-not-a-header',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('reservations', 0);
        $this->assertStockInvariant(10);
    }

    private function endpoint(): string
    {
        return "/api/products/{$this->product->id}/reservations";
    }

    private function assertStockInvariant(int $expectedAvailable): void
    {
        $product = $this->product->fresh();
        $reserved = (int) $product->reservations()->sum('quantity');

        $this->assertSame(
            $expectedAvailable,
            $product->available_quantity,
        );

        $this->assertGreaterThanOrEqual(0, $product->available_quantity);

        $this->assertSame(
            $product->initial_stock,
            $product->available_quantity + $reserved,
        );
    }
}
