<?php

namespace App\Http\Requests;

use App\Services\AdminDestinationScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FinancialDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(AdminDestinationScopeService::class)->actor($this->user());

        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'date_basis' => ['sometimes', Rule::in(['paid_utc'])],
            'environment' => ['sometimes', Rule::in(['sandbox', 'demo', 'production'])],
            'destination_guid' => ['sometimes', 'uuid'], 'admin_guid' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->has('from')
                && CarbonImmutable::parse($this->input('from'), 'UTC')->diffInDays(CarbonImmutable::parse($this->input('to'), 'UTC')) >= 366) {
                $validator->errors()->add('to', 'The inclusive period cannot exceed 366 days.');
            }
        }];
    }
}
