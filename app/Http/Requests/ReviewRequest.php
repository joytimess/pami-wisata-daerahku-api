<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->isMethod('GET') || $this->user() !== null;
    }

    public function rules(): array
    {
        return $this->isMethod('GET')
            ? ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]
            : ['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['required', 'string', 'max:2000']];
    }
}
