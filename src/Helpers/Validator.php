<?php

namespace App\Helpers;

use App\Core\ValidationException;

class Validator
{
    /**
     * Ensures each key in $required is a non-empty value in $data.
     * @throws ValidationException
     */
    public static function required(array $data, array $required): void
    {
        $errors = [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                $errors[$field] = 'This field is required';
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }
}
