<?php

namespace App\Http\Requests;

use App\Models\Question;
use App\Services\HtmlSanitizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreQuestionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Question::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => 'required|exists:categories,id',
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:5000000', // Limit content size
            'tags' => 'required|array|min:1|max:10', // Limit number of tags
            'tags.*' => 'required|array',
            'tags.*.id' => 'required_without:tags.*.name|exists:tags,id',
            'tags.*.name' => 'required_without:tags.*.id|string|max:50',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $sanitizer = app(HtmlSanitizer::class);

        // Sanitize the title (remove all HTML)
        if ($this->has('title')) {
            $this->merge([
                'title' => $sanitizer->sanitize($this->input('title')),
            ]);
        }

        // Sanitize tag names
        if ($this->has('tags') && is_array($this->input('tags'))) {
            $sanitizedTags = [];
            foreach ($this->input('tags') as $tag) {
                if (isset($tag['name'])) {
                    $tag['name'] = $sanitizer->sanitize($tag['name']);
                }
                $sanitizedTags[] = $tag;
            }
            $this->merge(['tags' => $sanitizedTags]);
        }
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
