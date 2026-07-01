<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class FallbackUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is already behind the `admin` middleware.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKb = (int) config('radio.max_upload_kb', 15360);

        return [
            'track' => ['required', 'file', 'mimetypes:audio/mpeg', 'mimes:mp3', 'max:'.$maxKb],
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
