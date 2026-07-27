<?php

namespace App\Http\Requests;

use App\Services\HtmlSanitizer;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCommentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'content' => 'required|string|min:5|max:20000', // Reasonable limit for comments
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
