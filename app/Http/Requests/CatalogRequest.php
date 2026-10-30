<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogRequest extends FormRequest
{
    private function requiredRole(): int
    {
        return $this->routeIs('categories.*', 'admin.categories.*') ? 2 : 1;
    }

    public function authorize(): bool
    {
        // Public reads stay open; every admin route and every write needs the minimum role.
        if ($this->isMethod('GET') && ! $this->routeIs('admin.*') && ! $this->routeIs('destinations.image.admin')) {
            return true;
        }
        $role = $this->user()?->role;

        return $role !== null && (int) $role >= $this->requiredRole();
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [
                'search' => ['sometimes', 'string', 'max:100'], 'category' => ['sometimes', 'uuid'],
                'sort' => ['sometimes', Rule::in(['name', '-name', 'created_at', '-created_at'])],
                'status' => ['sometimes', Rule::in(['active', 'inactive', 'all'])],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ];
        }
        if ($this->isMethod('DELETE')) {
            return [];
        }
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        if ($this->routeIs('categories.*')) {
            return [
                'name' => [$required, 'string', 'max:255'],
                'slug' => [$required, 'string', 'alpha_dash', 'max:255', Rule::unique('destination_categories', 'slug')->ignore($this->route('category')?->guid, 'guid')],
                'is_active' => ['sometimes', 'boolean'],
            ];
        }

        return [
            'destination_category_guid' => [$required, 'uuid', Rule::exists('destination_categories', 'guid')],
            'name' => [$required, 'string', 'max:255'], 'location' => [$required, 'string', 'max:255'],
            'description' => [$required, 'string'], 'ticket_price' => [$required, 'integer', 'min:0', 'max:1000000000000'],
            'currency' => ['sometimes', Rule::in(['IDR'])], 'opening_hours' => [$required, 'string'],
            'timezone' => [$required, 'timezone'], 'image' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
