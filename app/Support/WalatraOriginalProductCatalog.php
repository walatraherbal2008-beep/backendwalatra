<?php

namespace App\Support;

use Illuminate\Support\Str;

class WalatraOriginalProductCatalog
{
    /**
     * Canonical product identity and marketplace prices from the user-provided
     * Walatra price list. Weights and detailed product facts are intentionally
     * not inferred from this source.
     *
     * @return list<array{code: string, name: string, slug: string, sku: string, price: int}>
     */
    public static function products(): array
    {
        $rows = [
            ['ALB', 'ALBUCARE', 199000],
            ['AMP', 'ALBUCARE 50 KAPSUL', 114500],
            ['AQU', 'SarikurmaQU', 110000],
            ['CHS', 'CARDINA HEALT SQUALENE', 128000],
            ['GRC', 'GARLIC', 163000],
            ['HEX', 'Hexabumin', 95000],
            ['IFI', 'SENDIFIT', 137000],
            ['KAM', 'SUSU KAMBING ETAKU VERSI TERBARU', 110000],
            ['KGE', 'KAPSUL GAMAT EMAS', 149000],
            ['KLR', 'KELOREENA 100 KAPSUL', 172000],
            ['KLRN', 'KELOREENA 50 KAPSUL', 103000],
            ['KSL', 'Kapsul Sehat Lambungku', 250000],
            ['LMB', 'Kapsul Sehat Lambungku 30 kapsul', 125000],
            ['MLS', 'Kapsul Sehat Lambungku 15 kapsul', 75000],
            ['OIL', 'OILFIT', 132000],
            ['PSM', 'PROPOLIS SM', 106000],
            ['QNC', 'Jelly Gamat QnC', 199000],
            ['RAD', 'RADIMAX', 150000],
            ['SKIN', 'PROSKIN', 129000],
            ['SKP', 'SariKurma PLUS', 115000],
            ['SPJ', 'SAPU JAGAD', 135000],
            ['SQU', 'BIO SQUALEN', 123000],
            ['WBW', 'Sehat Wanita', 199000],
            ['WNT', 'SEHAT WANITA 50 KAPSUL', 137000],
        ];

        return array_map(
            static fn (array $row): array => [
                'code' => $row[0],
                'name' => $row[1],
                'slug' => Str::slug($row[1]),
                'sku' => 'SKU-WAL-ORIGINAL-'.$row[0],
                'price' => $row[2],
            ],
            $rows,
        );
    }
}
