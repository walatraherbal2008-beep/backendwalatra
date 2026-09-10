<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogBundleIndexRequest extends FormRequest
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
            'q.string' => 'Kata pencarian bundle harus berupa teks.',
            'q.max' => 'Kata pencarian bundle maksimal 100 karakter.',
            'store.string' => 'Slug toko harus berupa teks.',
            'store.max' => 'Slug toko maksimal 255 karakter.',
            'sort.in' => 'Urutan bundle tidak valid.',
            'per_page.integer' => 'Jumlah data per halaman harus berupa bilangan bulat.',
            'per_page.min' => 'Jumlah data per halaman minimal 1.',
            'per_page.max' => 'Jumlah data per halaman maksimal 50.',
            'page.integer' => 'Nomor halaman harus berupa bilangan bulat.',
            'page.min' => 'Nomor halaman minimal 1.',
        ];
    }
}
