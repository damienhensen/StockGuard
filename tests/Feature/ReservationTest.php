<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
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

        $this->postJson($this->endpoint(), [], [
            'Idempotency-Key' => 'missing-quantity',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

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

    public function test_stock_is_restored_when_reservation_creation_fails(): void
    {
        $this->withoutExceptionHandling();

        $stockDuringInsert = null;

        Reservation::creating(function (Reservation $reservation) use (
            &$stockDuringInsert,
        ): void {
            $stockDuringInsert = Product::query()
                ->findOrFail($reservation->product_id)
                ->available_quantity;

            throw new RuntimeException('Simulated reservation insert failure.');
        });

        try {
            $this->postJson($this->endpoint(), [
                'quantity' => 3,
            ], [
                'Idempotency-Key' => 'rollback-test',
            ]);

            $this->fail('Expected the reservation creation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated reservation insert failure.',
                $exception->getMessage(),
            );
        } finally {
            Reservation::flushEventListeners();
        }

        $this->assertSame(7, $stockDuringInsert);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertStockInvariant(10);
    }

    public function test_successful_request_can_be_replayed_when_stock_is_zero(): void
    {
        $first = $this->postJson($this->endpoint(), [
            'quantity' => 10,
        ], [
            'Idempotency-Key' => 'reserve-all',
        ])->assertCreated();

        $this->assertStockInvariant(0);

        $this->postJson($this->endpoint(), [
            'quantity' => 10,
        ], [
            'Idempotency-Key' => 'reserve-all',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.reservation_id',
                $first->json('data.reservation_id'),
            )
            ->assertJsonPath('data.replayed', true);

        $this->assertDatabaseCount('reservations', 1);
        $this->assertStockInvariant(0);
    }

    public function test_unknown_product_returns_not_found(): void
    {
        $unknownId = $this->product->id + 1;

        $this->postJson("/api/products/{$unknownId}/reservations", [
            'quantity' => 1,
        ], [
            'Idempotency-Key' => 'unknown-product',
        ])->assertNotFound();

        $this->assertDatabaseCount('reservations', 0);
        $this->assertStockInvariant(10);
    }

    public function test_idempotency_key_is_scoped_to_product(): void
    {
        $otherProduct = Product::create([
            'sku' => 'TEST-002',
            'name' => 'Other product',
            'initial_stock' => 10,
            'available_quantity' => 10,
        ]);

        $first = $this->postJson($this->endpoint(), [
            'quantity' => 2,
        ], [
            'Idempotency-Key' => 'shared-key',
        ])
            ->assertCreated()
            ->assertJsonPath('data.replayed', false);

        $second = $this->postJson(
            "/api/products/{$otherProduct->id}/reservations",
            ['quantity' => 3],
            ['Idempotency-Key' => 'shared-key'],
        )
            ->assertCreated()
            ->assertJsonPath('data.product_id', $otherProduct->id)
            ->assertJsonPath('data.replayed', false);

        $this->assertNotSame(
            $first->json('data.reservation_id'),
            $second->json('data.reservation_id'),
        );

        $this->assertDatabaseCount('reservations', 2);
        $this->assertStockInvariant(8);

        $otherProduct->refresh();

        $this->assertSame(7, $otherProduct->available_quantity);
        $this->assertSame(
            $otherProduct->initial_stock,
            $otherProduct->available_quantity
                + (int) $otherProduct->reservations()->sum('quantity'),
        );
    }

    public function test_different_keys_create_separate_reservations(): void
    {
        foreach (['order-a', 'order-b'] as $key) {
            $this->postJson($this->endpoint(), [
                'quantity' => 2,
            ], [
                'Idempotency-Key' => $key,
            ])
                ->assertCreated()
                ->assertJsonPath('data.replayed', false);
        }

        $this->assertDatabaseCount('reservations', 2);
        $this->assertStockInvariant(6);
    }

    public function test_overlong_idempotency_key_is_rejected(): void
    {
        $this->postJson($this->endpoint(), [
            'quantity' => 1,
        ], [
            'Idempotency-Key' => str_repeat('a', 256),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('reservations', 0);
        $this->assertStockInvariant(10);
    }

    public function test_insufficient_stock_does_not_claim_idempotency_key(): void
    {
        $this->product->update([
            'initial_stock' => 3,
            'available_quantity' => 3,
        ]);

        $this->postJson($this->endpoint(), [
            'quantity' => 5,
        ], [
            'Idempotency-Key' => 'retry-after-rejection',
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

        $this->assertDatabaseCount('reservations', 0);
        $this->assertStockInvariant(3);

        $this->postJson($this->endpoint(), [
            'quantity' => 2,
        ], [
            'Idempotency-Key' => 'retry-after-rejection',
        ])
            ->assertCreated()
            ->assertJsonPath('data.replayed', false);

        $this->assertDatabaseCount('reservations', 1);
        $this->assertStockInvariant(1);
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
