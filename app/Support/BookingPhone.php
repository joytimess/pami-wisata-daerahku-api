<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final class BookingPhone
{
    public static function normalize(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 64 || ! preg_match('/^\+[0-9][0-9 ()-]*$/D', trim($value))) {
            throw ValidationException::withMessages(['visitor_phone' => 'Use a valid international phone number beginning with + and its country code.']);
        }
        $parser = PhoneNumberUtil::getInstance();
        try {
            $phone = $parser->parse(trim($value), null);
            if ($parser->isValidNumber($phone)) {
                return $parser->format($phone, PhoneNumberFormat::E164);
            }
        } catch (NumberParseException) {
        }

        throw ValidationException::withMessages(['visitor_phone' => 'Use a valid international phone number beginning with + and its country code.']);
    }
}
