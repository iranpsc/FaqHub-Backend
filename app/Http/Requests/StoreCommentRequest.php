<?php

namespace App\Http\Requests;

use App\Services\HtmlSanitizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
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
            'content' => 'required|string|max:20000', // Reasonable limit for comments
        ];
    }

    /**
     * Prepare the data for validation.
     * Comments should be plain text, so strip all HTML.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('content')) {
            // Comments are plain text - strip all HTML tags
            $this->merge([
                'content' => strip_tags($this->input('content')),
            ]);
        }
    }

    /**
     * Get the validated data with escaped content.
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        // Escape any remaining special characters for safety
        if ($key === null && isset($validated['content'])) {
            $validated['content'] = HtmlSanitizer::escape($validated['content']);
        }

        return $validated;
    }
}
