<?php
// Returns first error string from array of validation results, or '' if all pass.
function vFirst(array $checks): string {
    foreach ($checks as $e) { if ($e !== '') return $e; }
    return '';
}

function vRequired(string $val, string $label): string {
    return $val === '' ? "{$label} is required." : '';
}

function vMaxLen(string $val, int $max, string $label): string {
    return mb_strlen($val) > $max ? "{$label} must be {$max} characters or fewer." : '';
}

function vMinLen(string $val, int $min, string $label): string {
    return mb_strlen($val) < $min ? "{$label} must be at least {$min} characters." : '';
}

function vEmail(string $email): string {
    if ($email === '') return '';
    return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? 'Please enter a valid email address.' : '';
}

function vUrl(string $url, string $label = 'URL'): string {
    if ($url === '') return '';
    return filter_var($url, FILTER_VALIDATE_URL) === false ? "{$label} must be a valid URL (include https://)." : '';
}

function vUsername(string $val): string {
    if ($val === '') return 'Username is required.';
    if (mb_strlen($val) < 3) return 'Username must be at least 3 characters.';
    if (mb_strlen($val) > 50) return 'Username must be 50 characters or fewer.';
    if (!preg_match('/^[a-zA-Z0-9._\-]+$/', $val))
        return 'Username may only contain letters, numbers, dots, hyphens, and underscores.';
    return '';
}

function vDate(string $date, string $label = 'Date'): string {
    if ($date === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return "{$label} must be a valid date.";
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return ($d && $d->format('Y-m-d') === $date) ? '' : "{$label} is not a valid date.";
}
