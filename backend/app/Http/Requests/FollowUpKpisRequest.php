<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FollowUpKpisRequest extends FormRequest
{
    public const MAX_DAYS = 366;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d', 'after:2000-01-01'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before:2100-01-01'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'seller_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if (! $v->errors()->hasAny(['from', 'to']) && CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('to'))) >= self::MAX_DAYS) {
                $v->errors()->add('to', 'El periodo no puede superar '.self::MAX_DAYS.' días.');
            }
        }];
    }

    public function messages(): array
    {
        return ['to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.'];
    }

    /** @return array{company_id: int|null, seller_id: int|null} */
    public function options(): array
    {
        return ['company_id' => $this->integer('company_id') ?: null, 'seller_id' => $this->integer('seller_id') ?: null];
    }
}
