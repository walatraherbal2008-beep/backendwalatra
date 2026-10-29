<?php

namespace App\Support;

class WalatraProductCategoryMap
{
    /**
     * Pemetaan slug produk katalog Walatra ke slug kategorinya.
     * Dipakai oleh WalatraCatalogSeeder dan CategorySeeder agar konsisten.
     *
     * @return array<string, list<string>>
     */
    public static function map(): array
    {
        return [
            'kapsul-sehat-lambungku' => ['pencernaan'],
            'propolis-sm' => ['pernapasan-dan-tenggorokan', 'daya-tahan-tubuh'],
            'qnc-jelly-gamat' => ['daya-tahan-tubuh', 'sendi-dan-otot'],
            'bio-squalene' => ['daya-tahan-tubuh', 'nutrisi-dan-kebugaran'],
            'garlic' => ['daya-tahan-tubuh'],
            'sendifit' => ['sendi-dan-otot'],
            'kapsul-oil-fit' => ['sendi-dan-otot'],
            'sehat-wanita' => ['kesehatan-wanita'],
            'etaku-goat-milk' => ['nutrisi-dan-kebugaran'],
            'proskin' => ['kesehatan-kulit'],
            'keloreena' => ['vitalitas-pria', 'nutrisi-dan-kebugaran'],
            'hexabumin' => ['nutrisi-dan-kebugaran'],
            'albucare' => ['nutrisi-dan-kebugaran'],
            'kurmaqu-gmj' => ['nutrisi-dan-kebugaran'],
            'radimax' => ['nutrisi-dan-kebugaran'],
            'kapsul-gamat-emas' => ['daya-tahan-tubuh', 'sendi-dan-otot'],
        ];
    }
}
