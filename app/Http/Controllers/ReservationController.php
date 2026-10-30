<?php

namespace App\Http\Controllers;

use App\Enums\ApiErrorCode;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Product;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationController extends Controller
{
    public function store(
        StoreReservationRequest $request,
        Product $product
    ): JsonResponse {
        $data = $request->validated();

        $reservation = DB::transaction(function () use ($product, $data) {
            $product = Product::query()
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($product->available_quantity < $data['quantity']) {
                $error = ApiErrorCode::InsufficientStock;

                throw new HttpResponseException(
                    response()->json([
                        'error' => [
                            'code' => $error->value,
                            'message' => $error->message(),
                        ],
                    ], 409),
                );
            }

            $product->decrement('available_quantity', $data['quantity']);

            return $product->reservations()->create([
                'quantity' => $data['quantity'],
                'idempotency_key' => $data['idempotency_key'],
            ]);
        });

        return (new ReservationResource($reservation))
            ->response()
            ->setStatusCode(201);
    }
}
