<?php

namespace App\Http\Requests\Admin;

class CategoryStoreRequest extends CategoryWriteRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'status' => 'sometimes|in:active,inactive',
            'code' => 'sometimes|nullable|string|max:64',
            'property_target' => 'sometimes|nullable|string|max:64',
            'icon' => 'sometimes|nullable|string|max:500',
            'sortOrder' => 'sometimes|integer|min:0|max:1000000',

            'languageTitles' => 'sometimes|array',
            'languageTitles.english' => 'sometimes|nullable|string|max:255',
            'languageTitles.arabic' => 'sometimes|nullable|string|max:255',
            'languageTitles.urdu' => 'sometimes|nullable|string|max:255',

            'subcategories' => 'sometimes|array',
            'subcategories.*' => 'required|array',
            'subcategories.*.title' => 'required|string|max:255',
            'subcategories.*.code' => 'sometimes|nullable|string|max:64',
            'subcategories.*.sortOrder' => 'sometimes|integer|min:0|max:1000000',

            // optional image file
            'image' => 'sometimes|file|mimes:jpg,jpeg,png|max:2048',
        ];
    }
}
