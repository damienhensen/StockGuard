<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('stockguard:reset-demo')]
#[Description('Reset the local demo product and its reservations')]
class ResetDemo extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->error('Demo reset is only available in local and testing environments.');

            return self::FAILURE;
        }

        DB::transaction(function (): void {
            $product = Product::query()
                ->where('sku', 'GPU-RTX-001')
                ->lockForUpdate()
                ->firstOrFail();

            $product->reservations()->delete();

            $product->update([
                'initial_stock' => 10,
                'available_quantity' => 10,
            ]);
        });

        $this->info('Demo reset: 10 available, 0 reservations.');

        return self::SUCCESS;
    }
}
