<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DestinationFacilitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateFacilities', $this->route('destination')) ?? false;
    }

    public function rules(): array
    {
        return [
            'facility_guids' => ['present', 'array', 'max:100'],
            'facility_guids.*' => ['required', 'uuid', 'distinct:ignore_case', Rule::exists('facilities', 'guid')->where('is_active', true)],
        ];
    }
}
