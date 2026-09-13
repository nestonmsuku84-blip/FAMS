<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';

function applicationUrl(string $path): string
{
    $base = rtrim(env('APP_URL'), '/');
    if ($base !== '') return $base . $path;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $path;
}
function sendAccountMail(string $to, string $subject, string $message): bool
{
    $from = env('MAIL_FROM', 'no-reply@fams.local');
    return mail($to, $subject, $message, "From: FAMS <{$from}>\r\nContent-Type: text/plain; charset=UTF-8");
}
