<?php

namespace App\Http\Requests;

use App\Services\AdminDestinationScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()) {
            return false;
        }
        $actor = app(AdminDestinationScopeService::class)->actor($this->user());

        return ! $this->has('admin_guid') || (int) $actor->role === 2;
    }

    public function rules(): array
    {
        return ['from' => ['sometimes', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
            'date_basis' => ['sometimes', Rule::in(['decision', 'created', 'visit'])],
            'destination_guid' => ['sometimes', 'uuid'], 'admin_guid' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $section = $this->route('section') ?? 'queue';
            $basis = in_array($section, ['destinations', 'visitors', 'action-center'], true) ? 'visit' : ($section === 'queue' ? 'created' : 'decision');
            if ($this->has('date_basis') && $this->input('date_basis') !== $basis) {
                $validator->errors()->add('date_basis', 'This endpoint requires date_basis='.$basis.'.');
            }
            if ($this->has('admin_guid') && ! in_array($section, ['overview', 'approvals'], true)) {
                $validator->errors()->add('admin_guid', 'This endpoint does not support actor filtering.');
            }
            if ($this->has('from') && CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('to'))) > 366) {
                $validator->errors()->add('to', 'The period cannot exceed 366 days.');
            }
        }];
    }
}
