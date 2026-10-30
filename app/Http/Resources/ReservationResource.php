<?php

namespace App\Http\Resources;

use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

class ReservationResource extends JsonResource
{
    public function __construct(
        Reservation $resource,
        private readonly bool $replayed = false,
    ) {
        return parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reservation_id' => $this->id,
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
            'replayed' => $this->replayed,
        ];
    }
}
