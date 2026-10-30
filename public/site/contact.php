<?php
declare(strict_types=1);

// Optional contact endpoint for the static landing page: validates the form,
// appends it to a log and returns the visitor to the page.

$name = trim((string) ($_POST['name'] ?? ''));
$contact = trim((string) ($_POST['contact'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

$valid = $name !== '' && mb_strlen($name) <= 80
    && $contact !== '' && mb_strlen($contact) <= 120
    && $message !== '' && mb_strlen($message) <= 1000;

if (!$valid) {
    header('Location: /site/#get?error=1', true, 303);
    exit;
}

$line = sprintf(
    "[%s] name=%s contact=%s ip=%s\n%s\n---\n",
    gmdate('c'),
    str_replace(["\r", "\n"], ' ', $name),
    str_replace(["\r", "\n"], ' ', $contact),
    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    str_replace(["\r\n", "\r"], "\n", $message)
);
@file_put_contents(dirname(__DIR__, 2) . '/storage/logs/contact.log', $line, FILE_APPEND | LOCK_EX);

header('Location: /site/#get?sent=1', true, 303);
exit;
