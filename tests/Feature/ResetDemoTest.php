<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_restores_demo_and_preserves_other_products(): void
    {
        $demo = $this->createProduct('GPU-RTX-001');
        $other = $this->createProduct('OTHER-001');

        foreach ([$demo, $other] as $product) {
            $this->postJson(
                "/api/products/{$product->id}/reservations",
                ['quantity' => 3],
                ['Idempotency-Key' => 'before-reset'],
            )->assertCreated();
        }

        $this->artisan('stockguard:reset-demo')
            ->assertSuccessful();

        $this->assertSame(10, $demo->fresh()->available_quantity);
        $this->assertSame(10, $demo->fresh()->initial_stock);
        $this->assertSame(0, $demo->reservations()->count());

        $this->assertSame(7, $other->fresh()->available_quantity);
        $this->assertSame(1, $other->reservations()->count());

        // Opnieuw resetten moet hetzelfde resultaat geven.
        $this->artisan('stockguard:reset-demo')
            ->assertSuccessful();

        $this->assertSame(10, $demo->fresh()->available_quantity);
        $this->assertSame(0, $demo->reservations()->count());
        $this->assertSame(1, $other->reservations()->count());
    }

    public function test_reset_is_refused_in_production(): void
    {
        $demo = $this->createProduct('GPU-RTX-001');

        $this->postJson(
            "/api/products/{$demo->id}/reservations",
            ['quantity' => 3],
            ['Idempotency-Key' => 'preserve-me'],
        )->assertCreated();

        $this->app->instance('env', 'production');

        try {
            $this->artisan('stockguard:reset-demo')
                ->expectsOutput(
                    'Demo reset is only available in local and testing environments.',
                )
                ->assertFailed();
        } finally {
            $this->app->instance('env', 'testing');
        }

        $this->assertSame(7, $demo->fresh()->available_quantity);
        $this->assertSame(1, $demo->reservations()->count());
    }

    private function createProduct(string $sku): Product
    {
        return Product::create([
            'sku' => $sku,
            'name' => 'Test product',
            'initial_stock' => 10,
            'available_quantity' => 10,
        ]);
    }
}
