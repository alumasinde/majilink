<?php
declare(strict_types=1);

namespace MajiLink\Http;

final class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = []
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Resource not found'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function unauthorized(string $message = 'Authentication required'): self
    {
        return new self(401, 'unauthenticated', $message);
    }

    public static function forbidden(string $message = 'Not allowed'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function validation(array $fields, string $message = 'The given data was invalid.'): self
    {
        return new self(422, 'validation_error', $message, $fields);
    }

    public static function conflict(string $message): self
    {
        return new self(409, 'conflict', $message);
    }

    public static function badRequest(string $message, string $code = 'bad_request'): self
    {
        return new self(400, $code, $message);
    }

    public static function tooManyRequests(string $message = 'Too many attempts. Try again later.'): self
    {
        return new self(429, 'rate_limited', $message);
    }
}
