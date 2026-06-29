<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JoinStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nickname' => ['required', 'string', 'min:2', 'max:30'],
            'avatar_path' => ['required', 'string', Rule::in(config('radio.avatars'))],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar_path.in' => 'Please pick one of the available avatars.',
        ];
    }
}
