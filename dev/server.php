<?php

/**
 * Development server: the protocol on the PHP core alone (no framework), the UI sources and a demo page.
 * For trying tansu and for the browser tests; not for production (no authentication, no CSRF check).
 *
 *   php -S 0.0.0.0:8080 dev/server.php
 *
 * Owners: ?owner=<name> (default "demo"); the owner "readonly" may look but not change anything.
 * Files are stored in dev/storage. POST /__reset empties it.
 */

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Otaqq\Tansu\Explorer;
use Otaqq\Tansu\FileTypes;
use Otaqq\Tansu\Limits;
use Otaqq\Tansu\OptimizeOptions;
use Otaqq\Tansu\Options;
use Otaqq\Tansu\Owner;
use Otaqq\Tansu\Path;
use Otaqq\Tansu\TansuException;

require __DIR__.'/../vendor/autoload.php';

/**
 * Writes log entries (skipped optimizations) to the server's output.
 */
final class StderrLogger extends Psr\Log\AbstractLogger
{
    public function log($level, string|Stringable $message, array $context = []): void
    {
        file_put_contents('php://stderr', "[$level] $message ".json_encode($context, JSON_UNESCAPED_SLASHES)."\n");
    }
}

$root = dirname(__DIR__);
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

// Static files: the demo page and the UI sources
if ($method === 'GET' && ($uri === '/' || $uri === '/index.html')) {
    return serveFile(__DIR__.'/index.html', 'text/html; charset=utf-8');
}
if ($method === 'GET' && str_starts_with($uri, '/ui/')) {
    $file = realpath($root.'/ui/src/'.substr($uri, 4));
    if ($file !== false && str_starts_with($file, $root.'/ui/src/') && is_file($file)) {
        return serveFile($file, str_ends_with($file, '.js') ? 'text/javascript; charset=utf-8' : 'text/plain');
    }
    http_response_code(404);

    return true;
}

$storage = new Filesystem(new LocalFilesystemAdapter(__DIR__.'/storage'));

if ($method === 'POST' && $uri === '/__reset') {
    foreach ($storage->listContents('', false) as $entry) {
        $entry->isDir() ? $storage->deleteDirectory($entry->path()) : $storage->delete($entry->path());
    }
    http_response_code(204);

    return true;
}

if (!str_starts_with($uri, '/api/')) {
    http_response_code(404);

    return true;
}

$ownerId = is_string($_GET['owner'] ?? null) && preg_match('/^[a-z0-9-]{1,32}$/', $_GET['owner']) ? $_GET['owner'] : 'demo';
$explorer = new Explorer(
    $storage,
    new Owner($ownerId, "owners/$ownerId", canManage: $ownerId !== 'readonly'),
    fn (string $location, string $path) => '/api/raw?'.http_build_query(['path' => $path, 'owner' => $ownerId]),
    new Options(
        new FileTypes([...FileTypes::DEFAULT, FileTypes::SVG]),
        new Limits(['image' => 2 * 1024 * 1024, 'document' => 5 * 1024 * 1024, 'video' => 20 * 1024 * 1024], 30 * 1024 * 1024, 50),
        optimize: OptimizeOptions::fromArray([
            'enabled' => true,
            'presets' => [
                'thumb' => ['label' => ['en' => 'Thumbnail', 'ja' => 'サムネイル'], 'max_width' => 64, 'max_height' => 64, 'quality' => 80],
                'compact' => ['label' => ['en' => 'Smaller file', 'ja' => 'ファイルを小さく'], 'quality' => 60],
            ],
            'custom' => [
                'max_width' => ['min' => 1, 'max' => 4096, 'default' => 1200],
                'max_height' => ['min' => 1, 'max' => 4096, 'default' => 1200],
            ],
        ]),
    ),
    logger: new StderrLogger(),
);
if ($ownerId === 'readonly') {
    $storage->createDirectory('owners/readonly');
}

$body = static function (): array {
    $json = json_decode((string) file_get_contents('php://input'), true);

    return is_array($json) ? $json : [];
};

try {
    $route = $method.' '.substr($uri, 4);
    $result = match ($route) {
        'GET /info' => $explorer->info(),
        'GET /tree' => $explorer->tree(),
        'GET /folder' => $explorer->folder(Path::parse($_GET['path'] ?? '/')),
        'POST /folders' => [201, $explorer->createFolder(Path::parse($body()['path'] ?? null), $body()['name'] ?? null)],
        'PATCH /folders' => $explorer->renameFolder(Path::parse($body()['path'] ?? null), $body()['name'] ?? null),
        'DELETE /folders' => [204, $explorer->deleteFolder(Path::parse($_GET['path'] ?? null))],
        'POST /files' => [201, upload($explorer)],
        'PATCH /files' => $explorer->renameFile(Path::parse($body()['path'] ?? null), $body()['name'] ?? null),
        'DELETE /files' => [204, $explorer->deleteFile(Path::parse($_GET['path'] ?? null))],
        'GET /raw' => raw($explorer),
        default => throw TansuException::notFound($uri),
    };
} catch (TansuException $e) {
    $result = [$e->status, $e->toArray()];
}
if ($result === true) {
    return true;
}
[$status, $data] = is_array($result) && array_is_list($result) && count($result) === 2 && is_int($result[0]) ? $result : [200, $result];
http_response_code($status);
if ($status !== 204) {
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

return true;

function upload(Explorer $explorer): array
{
    $folder = Path::parse($_POST['path'] ?? null);
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        if (in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw TansuException::fileTooLarge();
        }
        throw new TansuException('upload_failed', 400, 'The file did not arrive complete.');
    }

    return $explorer->upload(
        $folder,
        $file['name'],
        (int) $file['size'],
        fn () => (string) file_get_contents($file['tmp_name']),
        array_intersect_key($_POST, array_flip(['optimize', 'max_width', 'max_height', 'quality'])),
    );
}

function raw(Explorer $explorer): bool
{
    $file = $explorer->read(Path::parse($_GET['path'] ?? null));
    header('Content-Type: '.$file['type']);
    header('Content-Length: '.$file['size']);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    fpassthru($file['stream']);

    return true;
}

function serveFile(string $file, string $type): bool
{
    header('Content-Type: '.$type);
    header('Cache-Control: no-store');
    readfile($file);

    return true;
}
