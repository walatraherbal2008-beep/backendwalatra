<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type,
            'listing_id' => (int) $this->listing_id,
            'id' => (int) $this->item_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'code' => $this->code,
            'price' => $this->type === 'product'
                ? ['min' => $this->price_min, 'max' => $this->price_max]
                : ['value' => $this->price_min],
            'image_url' => $this->image_url,
            'store' => [
                'id' => (int) $this->store_id,
                'name' => $this->store_name,
                'slug' => $this->store_slug,
                'city' => $this->city,
                'province' => $this->province,
            ],
            'published_at' => $this->published_at,
            'matched_in' => $this->matchedIn($request),
        ];
    }

    /**
     * Beri tahu FE field mana yang cocok agar bisa tampil
     * "ketemu di deskripsi / varian / kategori". Non-breaking: tambahan field.
     *
     * @return list<string>
     */
    private function matchedIn(Request $request): array
    {
        $keyword = trim((string) $request->query('q', ''));

        if ($keyword === '') {
            return [];
        }

        $tokens = collect(preg_split('/\s+/', mb_strtolower($keyword)) ?: [])
            ->map(fn ($token): string => trim((string) $token))
            ->filter(fn ($token): bool => $token !== '')
            ->take(10)
            ->values();

        if ($tokens->isEmpty()) {
            return [];
        }

        $haystacks = [
            'name' => mb_strtolower((string) ($this->name ?? '')),
            'code' => mb_strtolower((string) ($this->code ?? '')),
            'store' => mb_strtolower((string) ($this->store_name ?? '')),
        ];

        $matched = [];

        foreach ($haystacks as $field => $haystack) {
            if ($haystack !== '' && $tokens->every(fn (string $token): bool => str_contains($haystack, $token))) {
                $matched[] = $field;
            }
        }

        return array_values(array_unique($matched));
    }
}
