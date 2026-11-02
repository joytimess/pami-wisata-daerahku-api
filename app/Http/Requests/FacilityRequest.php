<?php

namespace App\Http\Requests;

use App\Models\Facility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->routeIs('facilities.index') ? 'viewAny' : 'manage', Facility::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Str::squish($this->input('name'))]);
        }
        if ($this->isMethod('POST')) {
            $slug = $this->input('slug', $this->input('name'));
            if (is_string($slug)) {
                $this->merge(['slug' => Str::slug($slug)]);
            }
        }
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [
                'status' => ['sometimes', Rule::in(['active', 'inactive', 'all'])],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ];
        }
        if ($this->isMethod('DELETE')) {
            return [];
        }
        $facility = $this->route('facility');

        return [
            'name' => ['bail', $this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:255', function ($attribute, $value, $fail) use ($facility) {
                if (Facility::where('name_key', Str::lower($value))->when($facility, fn ($query) => $query->where('guid', '!=', $facility->guid))->exists()) {
                    $fail('The name has already been taken.');
                }
            }],
            'slug' => $this->isMethod('POST')
                ? ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('facilities', 'slug')]
                : ['missing'],
            'icon_key' => ['sometimes', 'nullable', Rule::in([])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
