<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'store' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'sort' => [
                'nullable',
                'string',
                Rule::in(['latest', 'name_asc', 'name_desc', 'price_asc', 'price_desc']),
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.string' => 'Kata pencarian harus berupa teks.',
            'q.max' => 'Kata pencarian maksimal 100 karakter.',
            'store.string' => 'Slug toko harus berupa teks.',
            'store.max' => 'Slug toko maksimal 255 karakter.',
            'category.string' => 'Slug kategori harus berupa teks.',
            'category.max' => 'Slug kategori maksimal 255 karakter.',
            'min_price.numeric' => 'Harga minimum harus berupa angka.',
            'min_price.min' => 'Harga minimum tidak boleh kurang dari 0.',
            'max_price.numeric' => 'Harga maksimum harus berupa angka.',
            'max_price.min' => 'Harga maksimum tidak boleh kurang dari 0.',
            'max_price.gte' => 'Harga maksimum harus lebih besar atau sama dengan harga minimum.',
            'sort.in' => 'Urutan produk tidak valid.',
            'per_page.integer' => 'Jumlah data per halaman harus berupa bilangan bulat.',
            'per_page.min' => 'Jumlah data per halaman minimal 1.',
            'per_page.max' => 'Jumlah data per halaman maksimal 50.',
            'page.integer' => 'Nomor halaman harus berupa bilangan bulat.',
            'page.min' => 'Nomor halaman minimal 1.',
        ];
    }
}
