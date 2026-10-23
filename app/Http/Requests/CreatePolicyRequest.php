<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CreatePolicyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'string', 'max:100'],
            'agent_id' => ['required', 'string', 'max:100'],
            'customer_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'destination' => ['required', 'string', Rule::in(['DOMESTIC', 'INTERNATIONAL'])],
            'trip_start' => ['required', 'date_format:Y-m-d'],
            'trip_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:trip_start'],
            'plan_code' => ['required', 'string', Rule::in(['BASIC', 'PLUS', 'PREMIUM'])],
            'sum_insured' => ['required', Rule::in([100000, 300000, 500000, '100000', '300000', '500000'])],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'trip_end.after_or_equal' => 'The trip_end date must be greater than or equal to trip_start.',
            'plan_code.in' => 'The selected plan_code is invalid. Supported plans: BASIC, PLUS, PREMIUM.',
            'destination.in' => 'The selected destination is invalid. Supported destinations: DOMESTIC, INTERNATIONAL.',
            'sum_insured.in' => 'The selected sum_insured is invalid. Supported coverage amounts: 100000, 300000, 500000.',
        ];
    }

    /**
     * Return meaningful JSON validation errors with HTTP 422.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed for policy request intake.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
