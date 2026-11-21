<?php

namespace App\Support;

use Illuminate\Http\Request;

final class BusinessPhone
{
    public static function normalize(mixed $value): mixed
    {
        if (!is_string($value)) return $value;
        $value = trim($value);
        // Reject letters, extensions and URLs rather than silently changing them.
        if (!preg_match('/^(?:\+|00)[1-9][0-9\s().-]*$/', $value)) return $value;
        $value = preg_replace('/[\s().-]/', '', $value);
        return str_starts_with($value, '00') ? '+'.substr($value, 2) : $value;
    }

    public static function prepare(Request $request): void
    {
        if ($request->exists('phone')) $request->merge(['phone' => self::normalize($request->input('phone'))]);
    }

    public static function rules(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:16', 'regex:/^\+[1-9][0-9]{7,14}$/'];
    }

    public static function messages(): array
    {
        return ['phone.required'=>'Enter the business phone number, including its country calling code.',
            'phone.regex'=>'Enter a valid international business phone number, for example +974 5550 0001.',
            'phone.max'=>'The business phone number must have no more than 15 digits.'];
    }
}
