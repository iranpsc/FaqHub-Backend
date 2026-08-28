<?php

namespace App\Http\Requests;

use App\Services\HtmlSanitizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAnswerRequest extends FormRequest
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
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'content' => 'required|string|max:5000000', // Limit content size
        ];
    }

    /**
     * Get the validated data with sanitized content.
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        // If getting all validated data, sanitize content
        if ($key === null && isset($validated['content'])) {
            $sanitizer = app(HtmlSanitizer::class);
            $validated['content'] = $sanitizer->sanitize($validated['content']);
        }

        return $validated;
    }
}
