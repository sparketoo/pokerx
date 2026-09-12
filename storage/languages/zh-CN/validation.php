<?php

declare(strict_types=1);

return [
    'array' => ':attribute 必须是数组。',
    'boolean' => ':attribute 必须是布尔值。',
    'decimal' => ':attribute 小数位数必须为 :decimal 位。',
    'distinct' => ':attribute 存在重复值。',
    'in' => '选择的 :attribute 无效。',
    'integer' => ':attribute 必须是整数。',
    'list' => ':attribute 必须是列表数组。',
    'max' => [
        'numeric' => ':attribute 不能大于 :max。',
        'string' => ':attribute 长度不能超过 :max 个字符。',
    ],
    'min' => [
        'numeric' => ':attribute 不能小于 :min。',
        'array' => ':attribute 至少需要 :min 项。',
    ],
    'numeric' => ':attribute 必须是数字。',
    'present' => ':attribute 字段必须存在。',
    'required' => ':attribute 字段为必填项。',
    'string' => ':attribute 必须是字符串。',
];
