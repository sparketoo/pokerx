<?php

declare(strict_types=1);

return [
    'array' => 'The :attribute must be an array.',
    'boolean' => 'The :attribute field must be true or false.',
    'decimal' => 'The :attribute must have :decimal decimal places.',
    'distinct' => 'The :attribute field has a duplicate value.',
    'in' => 'The selected :attribute is invalid.',
    'integer' => 'The :attribute must be an integer.',
    'list' => 'The :attribute must be a list.',
    'max' => [
        'numeric' => 'The :attribute may not be greater than :max.',
        'string' => 'The :attribute may not be greater than :max characters.',
    ],
    'min' => [
        'numeric' => 'The :attribute must be at least :min.',
        'array' => 'The :attribute must have at least :min items.',
    ],
    'numeric' => 'The :attribute must be a number.',
    'present' => 'The :attribute field must be present.',
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute must be a string.',
];
