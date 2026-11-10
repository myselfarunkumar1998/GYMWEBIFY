<?php
declare(strict_types=1);

namespace App;

use App\Core\{Auth, Config, DB, HttpException, Request, Response, Router};

final class App
{
    private static ?Router $router = null;

    public static function router(): Router
    {
        return self::$router ??= require APP_ROOT . '/src/routes.php';
    }

    public static function handle(Request $req): Response
    {
        Auth::reset();
        try {
            if (!Config::isInstalled()) throw new HttpException(503, 'Not installed yet. Open /install to set up.', [], 'not_installed');
            return self::router()->dispatch($req);
        } catch (HttpException $e) {
            return Response::error($e);
        } catch (\PDOException $e) {
            if (DB::pdo()->inTransaction()) DB::pdo()->rollBack();
            // Unique-key clashes are user errors; everything else is logged, not shown.
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'Duplicate')) return Response::error(HttpException::bad('This record already exists'));
            self::log($e);
            return Response::error(new HttpException(500, 'Something went wrong. Please try again.', [], 'server_error'));
        } catch (\Throwable $e) {
            self::log($e);
            return Response::error(new HttpException(500, Config::get('debug') ? $e->getMessage() : 'Something went wrong. Please try again.', [], 'server_error'));
        }
    }

    public static function log(\Throwable $e): void
    {
        $dir = Config::get('log_dir', APP_ROOT . '/logs');
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        @file_put_contents("$dir/error-" . date('Y-m') . '.log', '[' . date('c') . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND | LOCK_EX);
    }
}
