<?php

if (!function_exists('rupiah')) {
    function rupiah($value)
    {
        if ($value === null)
            return '-';
        return 'Rp ' . number_format($value, 0, ',', '.');
    }
}

if (!function_exists('money_plain')) {
    function money_plain($value)
    {
        if ($value === null)
            return '-';
        return number_format($value, 0, ',', '.');
    }
}

if (!function_exists('qty')) {
    function qty($value)
    {
        if ($value === null)
            return '0';

        $formatted = number_format((float) $value, 3, ',', '.');

        // hilangkan ,000 jika tidak ada desimal
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, ',');

        return $formatted;
    }
}

if (!function_exists('percent')) {
    function percent($value)
    {
        if ($value === null)
            return '-';
        return number_format($value, 2, ',', '.') . '%';
    }
}

if (!function_exists('decimal')) {
    function decimal($value)
    {
        if ($value === null)
            return '-';
        return number_format($value, 2, ',', '.');
    }
}
