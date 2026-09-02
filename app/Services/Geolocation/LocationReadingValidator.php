<?php

declare(strict_types=1);

namespace App\Services\Geolocation;

use App\Support\Geo\LocationReading;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Turns the untrusted payload sent by the browser into a LocationReading.
 *
 * This is the only path from request input to the attendance workflow, so
 * the workflow can take the value object for granted. Anything the browser
 * sends beyond these three numbers - a timestamp, a distance it computed
 * itself - is ignored: the server keeps its own clock and does its own
 * arithmetic.
 */
final readonly class LocationReadingValidator
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function validate(array $input): LocationReading
    {
        $data = Validator::make($input, self::rules(), self::messages())->validate();

        return LocationReading::make(
            (float) $data['latitude'],
            (float) $data['longitude'],
            (float) $data['accuracy'],
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // A fix the browser cannot bound at all arrives as an absurd
            // number; 100 km is already "no idea" and is refused later by
            // the accuracy ceiling. Anything beyond it is malformed input.
            'accuracy' => ['required', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    /**
     * One sentence per field, whatever the failing rule.
     *
     * @return array<string, string>
     */
    private static function messages(): array
    {
        $messages = [];

        foreach (self::rules() as $attribute => $rules) {
            foreach ($rules as $rule) {
                $messages[$attribute.'.'.explode(':', $rule)[0]] = __('attendance.validation.'.$attribute);
            }
        }

        return $messages;
    }
}
