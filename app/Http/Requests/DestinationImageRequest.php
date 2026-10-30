<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestinationImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && (int) $this->user()->role >= 1;
    }

    public function rules(): array
    {
        return ['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']];
    }
}
