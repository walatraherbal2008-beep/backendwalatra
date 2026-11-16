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
            'albucare' => ['nutrisi-dan-kebugaran', 'daya-tahan-tubuh'],
            'albucare-50-kapsul' => ['nutrisi-dan-kebugaran', 'daya-tahan-tubuh'],
            'sarikurmaqu' => ['nutrisi-dan-kebugaran'],
            'cardina-healt-squalene' => ['nutrisi-dan-kebugaran'],
            'garlic' => ['daya-tahan-tubuh'],
            'hexabumin' => ['nutrisi-dan-kebugaran'],
            'sendifit' => ['sendi-dan-otot'],
            'susu-kambing-etaku-versi-terbaru' => ['nutrisi-dan-kebugaran'],
            'kapsul-gamat-emas' => ['pencernaan', 'daya-tahan-tubuh'],
            'keloreena-100-kapsul' => ['nutrisi-dan-kebugaran', 'kesehatan-mata'],
            'keloreena-50-kapsul' => ['nutrisi-dan-kebugaran', 'kesehatan-mata'],
            'kapsul-sehat-lambungku' => ['pencernaan'],
            'kapsul-sehat-lambungku-30-kapsul' => ['pencernaan'],
            'kapsul-sehat-lambungku-15-kapsul' => ['pencernaan'],
            'oilfit' => ['sendi-dan-otot'],
            'propolis-sm' => ['pernapasan-dan-tenggorokan', 'daya-tahan-tubuh'],
            'jelly-gamat-qnc' => ['daya-tahan-tubuh', 'sendi-dan-otot'],
            'radimax' => ['nutrisi-dan-kebugaran'],
            'proskin' => ['kesehatan-kulit'],
            'sarikurma-plus' => ['nutrisi-dan-kebugaran'],
            'sapu-jagad' => ['sendi-dan-otot'],
            'bio-squalen' => ['daya-tahan-tubuh', 'nutrisi-dan-kebugaran'],
            'sehat-wanita' => ['kesehatan-wanita'],
            'sehat-wanita-50-kapsul' => ['kesehatan-wanita'],
        ];
    }
}
