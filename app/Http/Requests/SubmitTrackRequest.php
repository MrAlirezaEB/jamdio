<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKb = (int) config('radio.max_upload_kb', 15360);

        return [
            'track' => ['required', 'file', 'mimetypes:audio/mpeg', 'mimes:mp3', 'max:'.$maxKb],
            'title' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'track.mimetypes' => 'Only MP3 audio files are accepted.',
            'track.mimes' => 'Only MP3 audio files are accepted.',
            'track.max' => 'The file is too large (max '.(int) (config('radio.max_upload_kb', 15360) / 1024).' MB).',
        ];
    }
}
