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

        [$reservation, $replayed] = DB::transaction(function () use ($product, $data) {
            $product = Product::query()
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $product->reservations()
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existing !== null) {
                if ($existing->quantity !== (int) $data['quantity']) {
                    $error = ApiErrorCode::IdempotencyConflict;

                    throw new HttpResponseException(
                        response()->json([
                            'error' => [
                                'code' => $error->value,
                                'message' => $error->message(),
                            ],
                        ], 409),
                    );
                }

                return [$existing, true];
            }

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

            $reservation = $product->reservations()->create([
                'quantity' => $data['quantity'],
                'idempotency_key' => $data['idempotency_key'],
            ]);

            return [$reservation, false];
        });

        return (new ReservationResource($reservation, $replayed))
            ->response()
            ->setStatusCode($replayed ? 200 : 201);
    }
}
