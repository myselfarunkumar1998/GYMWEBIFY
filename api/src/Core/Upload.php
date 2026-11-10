<?php
declare(strict_types=1);

namespace App\Core;

/**
 * File uploads are stored under uploads/<gym>/ with random names. The uploads folder has an
 * .htaccess that denies direct access; files are streamed by an authenticated endpoint.
 */
final class Upload
{
    private const TYPES = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf',
    ];

    public static function dir(): string
    {
        return rtrim((string)Config::get('upload_dir', APP_ROOT . '/uploads'), '/');
    }

    /** Accepts either a multipart file or a data: URL (camera capture / signature pad). */
    public static function store(int $gymId, mixed $file, int $maxBytes = 5_000_000, array $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']): string
    {
        if (is_string($file) && str_starts_with($file, 'data:')) {
            if (!preg_match('#^data:([a-z/+-]+);base64,(.+)$#s', $file, $m)) throw HttpException::bad('Invalid file data');
            $bytes = base64_decode($m[2], true);
            if ($bytes === false) throw HttpException::bad('Invalid file data');
        } elseif (is_array($file) && ($file['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
            $bytes = file_get_contents($file['tmp_name']);
        } else {
            throw HttpException::bad('Upload failed');
        }
        if (strlen($bytes) > $maxBytes) throw HttpException::bad('File is too large (max ' . round($maxBytes / 1_000_000) . ' MB)');
        // Trust the bytes, not the declared type.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!isset(self::TYPES[$mime]) || !in_array($mime, $allowed, true)) throw HttpException::bad('Only JPG, PNG, WEBP or PDF files are allowed');
        if (str_starts_with($mime, 'image/') && @getimagesizefromstring($bytes) === false) throw HttpException::bad('The image could not be read');
        $rel = $gymId . '/' . date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        $path = self::dir() . '/' . $rel;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0750, true);
        file_put_contents($path, $bytes);
        return $rel;
    }

    public static function stream(int $gymId, ?string $rel): Response
    {
        if (!$rel || !preg_match('#^(\d+)/\d{4}/\d{2}/[a-f0-9]{32}\.(jpg|png|webp|pdf)$#', $rel, $m) || (int)$m[1] !== $gymId) throw HttpException::notFound();
        $path = self::dir() . '/' . $rel;
        if (!is_file($path)) throw HttpException::notFound();
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'][$m[2]];
        return new Response(200, file_get_contents($path), ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=3600', 'Content-Security-Policy' => "default-src 'none'"], true);
    }
}
