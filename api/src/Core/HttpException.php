<?php
declare(strict_types=1);

namespace App\Core;

final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly array $fields = [], public readonly string $errCode = '')
    {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'Not found'): self { return new self(404, $what, [], 'not_found'); }
    public static function forbidden(string $m = 'You do not have permission to do this'): self { return new self(403, $m, [], 'forbidden'); }
    public static function bad(string $m, array $fields = []): self { return new self(422, $m, $fields, 'validation'); }
    public static function unauth(string $m = 'Please sign in'): self { return new self(401, $m, [], 'unauthenticated'); }
}
