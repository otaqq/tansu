<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Laravel;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Otaqq\Tansu\Explorer;
use Otaqq\Tansu\Path;
use Otaqq\Tansu\TansuException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The HTTP endpoints of protocol/README.md.
 */
final class ExplorerController
{
    public function __construct(private readonly Tansu $tansu)
    {
    }

    public function info(Request $request): JsonResponse
    {
        return $this->run($request, fn (Explorer $e) => $e->info());
    }

    public function tree(Request $request): JsonResponse
    {
        return $this->run($request, fn (Explorer $e) => $e->tree());
    }

    public function folder(Request $request): JsonResponse
    {
        return $this->run($request, fn (Explorer $e) => $e->folder(Path::parse($request->query('path', '/'))));
    }

    public function createFolder(Request $request): JsonResponse
    {
        return $this->run($request, fn (Explorer $e) => $e->createFolder(Path::parse($request->json('path')), $request->json('name')), 201);
    }

    public function renameFolder(Request $request): JsonResponse
    {
        return $this->run($request, fn (Explorer $e) => $e->renameFolder(Path::parse($request->json('path')), $request->json('name')));
    }

    public function deleteFolder(Request $request): JsonResponse|Response
    {
        return $this->run($request, fn (Explorer $e) => $e->deleteFolder(Path::parse($request->query('path'))), 204);
    }

    public function upload(Request $request): JsonResponse
    {
        return $this->run($request, function (Explorer $e) use ($request) {
            $folder = Path::parse($request->input('path'));
            $file = $request->file('file');
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw $this->uploadError($file);
            }

            return $e->upload(
                $folder,
                $file->getClientOriginalName(),
                (int) $file->getSize(),
                fn () => (string) $file->get(),
                $request->only(['optimize', 'max_width', 'max_height', 'quality']),
            );
        }, 201);
    }

    public function renameFile(Request $request): JsonResponse
    {
        return $this->run($request, fn (Explorer $e) => $e->renameFile(Path::parse($request->json('path')), $request->json('name')));
    }

    public function deleteFile(Request $request): JsonResponse|Response
    {
        return $this->run($request, fn (Explorer $e) => $e->deleteFile(Path::parse($request->query('path'))), 204);
    }

    public function raw(Request $request): JsonResponse|StreamedResponse
    {
        try {
            $file = $this->explorer($request)->read(Path::parse($request->query('path')));
        } catch (TansuException|AuthorizationException|AuthenticationException $e) {
            return $this->error($e);
        }

        return new StreamedResponse(function () use ($file) {
            fpassthru($file['stream']);
            fclose($file['stream']);
        }, 200, [
            'Content-Type' => $file['type'],
            'Content-Length' => (string) $file['size'],
            'Content-Disposition' => 'inline; filename*=UTF-8\'\''.rawurlencode($file['name']),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    private function explorer(Request $request): Explorer
    {
        $requested = $request->query('owner');

        return $this->tansu->explorer($this->tansu->owner($request), is_string($requested) && $requested !== '' ? $requested : null);
    }

    /**
     * @param Closure(Explorer): mixed $action
     */
    private function run(Request $request, Closure $action, int $status = 200): JsonResponse|Response
    {
        try {
            $result = $action($this->explorer($request));
        } catch (TansuException|AuthorizationException|AuthenticationException $e) {
            return $this->error($e);
        }

        return $status === 204 ? new Response('', 204) : new JsonResponse($result, $status);
    }

    private function error(TansuException|AuthorizationException|AuthenticationException $e): JsonResponse
    {
        $e = match (true) {
            $e instanceof AuthenticationException => new TansuException('unauthenticated', 401, 'You are not logged in.'),
            $e instanceof AuthorizationException => TansuException::forbidden(),
            default => $e,
        };

        return new JsonResponse($e->toArray(), $e->status);
    }

    private function uploadError(mixed $file): TansuException
    {
        $error = $file instanceof UploadedFile ? $file->getError() : null;
        $postTooLarge = $file === null && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::postMaxSize();
        if ($postTooLarge || in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return TansuException::fileTooLarge();
        }

        return new TansuException('upload_failed', 400, 'The file did not arrive complete.');
    }

    private static function postMaxSize(): int
    {
        $value = trim((string) ini_get('post_max_size'));
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number ?: PHP_INT_MAX,
        };
    }
}
