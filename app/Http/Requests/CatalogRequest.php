<?php

namespace App\Http\Requests;

use App\Models\Destination;
use App\Services\AdminDestinationScopeService;
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

        if ($role === null || (int) $role < $this->requiredRole() || $this->user()->status !== 'active') {
            return false;
        }
        if ($this->route('destination') instanceof Destination) {
            return app(AdminDestinationScopeService::class)->allows($this->user(), $this->route('destination')->guid);
        }

        return true;
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

        $coordinatesRequired = $this->routeIs('destinations.store') || $this->hasAny(['latitude', 'longitude']);

        return [
            'destination_category_guid' => [$required, 'uuid', Rule::exists('destination_categories', 'guid')],
            'name' => [$required, 'string', 'max:255'], 'location' => [$required, 'string', 'max:255'],
            'latitude' => [$coordinatesRequired ? 'required' : 'sometimes', 'numeric', 'between:-90,90'],
            'longitude' => [$coordinatesRequired ? 'required' : 'sometimes', 'numeric', 'between:-180,180'],
            'description' => [$required, 'string'], 'ticket_price' => [$required, 'integer', 'min:0', 'max:1000000000000'],
            'currency' => ['sometimes', Rule::in(['IDR'])], 'opening_hours' => [$required, 'string'],
            'timezone' => [$required, 'timezone'], 'image' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
