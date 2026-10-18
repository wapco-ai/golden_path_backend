<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/** Decode multipart JSON before applying the same rules as a JSON request. */
abstract class CategoryWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AdminAuth protects the routes.
    }

    protected function prepareForValidation(): void
    {
        $input = $this->input();
        if (array_key_exists('payload', $input)) {
            $raw = $input['payload'];
            $decoded = is_string($raw) ? json_decode($raw) : null;
            if (!is_object($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw ValidationException::withMessages(['payload' => ['اطلاعات دسته‌بندی باید یک شیء JSON معتبر باشد.']]);
            }
            $input = array_replace($input, json_decode($raw, true));
            unset($input['payload']);
        }

        // The existing admin UI calls an uploaded file "icon"; older clients
        // use "image". Both pass through the existing image validation rules.
        $image = $this->file('image') ?? $this->file('icon');
        if ($image !== null) {
            $this->files->set('image', $image);
            $this->files->remove('icon');
            $this->convertedFiles = null;
        }
        // Historical payloads also use image for a selected pictogram filename.
        if (is_string($input['image'] ?? null)) {
            if (!array_key_exists('icon', $input)) $input['icon'] = $input['image'];
            unset($input['image']);
        }
        if (!array_key_exists('property_target', $input) && array_key_exists('propertyTarget', $input)) {
            $input['property_target'] = $input['propertyTarget'];
        }
        $this->replace($input);
    }

    public function messages(): array
    {
        return [
            'title.required' => 'عنوان دسته‌بندی الزامی است.',
            'title.string' => 'عنوان دسته‌بندی باید متن باشد.',
            'title.max' => 'عنوان دسته‌بندی نباید بیش از ۲۵۵ نویسه باشد.',
            'subcategories.array' => 'زیرگروه‌ها باید به صورت فهرست ارسال شوند.',
            'subcategories.*.array' => 'اطلاعات هر زیرگروه باید یک شیء باشد.',
            'subcategories.*.title.required' => 'عنوان زیرگروه الزامی است.',
            'subcategories.*.title.string' => 'عنوان زیرگروه باید متن باشد.',
            'subcategories.*.title.max' => 'عنوان زیرگروه نباید بیش از ۲۵۵ نویسه باشد.',
        ];
    }
}
