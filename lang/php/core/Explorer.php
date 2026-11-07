<?php

declare(strict_types=1);

namespace Otaqq\Tansu;

use Closure;
use DateTimeImmutable;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Otaqq\Tansu\Content\Inspector;
use Otaqq\Tansu\Content\Optimizer;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * The operations of the protocol for one owner, on any Flysystem storage.
 *
 * Paths given to and returned by the methods are relative to the owner's root (see Path). Methods throw
 * TansuException for errors the client should see; storage failures are left as Flysystem exceptions.
 */
final class Explorer
{
    public const PROTOCOL = 1;

    /** Skips that point at the server setup are warnings; an animated image is expected */
    private const SKIP_LEVELS = [
        'memory' => LogLevel::WARNING,
        'gd_missing' => LogLevel::WARNING,
        'format_unsupported' => LogLevel::WARNING,
        'animated' => LogLevel::INFO,
    ];

    private readonly Limits $limits;

    private readonly Inspector $inspector;

    /**
     * @param Closure(string $location, string $path): string $url public URL of a stored file
     */
    public function __construct(
        private readonly FilesystemOperator $storage,
        private readonly Owner $owner,
        private readonly Closure $url,
        private readonly Options $options = new Options(),
        private readonly InUseChecker $inUse = new NothingInUse(),
        private ?LoggerInterface $logger = null,
        ?Optimizer $optimizer = null,
    ) {
        $this->limits = $owner->limits ?? $options->limits;
        $this->logger ??= new NullLogger();
        $this->inspector = new Inspector(
            $options->types,
            $options->stripMetadata,
            optimizer: $optimizer ?? new Optimizer($options->optimize->memoryMargin, maxPixels: $options->optimize->maxPixels),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function info(): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'accept' => $this->options->types->toArray(),
            'limits' => $this->limits->toArray(),
            'optimize' => $this->options->optimize->toArray(),
            'usage' => $this->usage(),
            'can' => ['manage' => $this->owner->canManage],
        ];
    }

    /**
     * @return array{size: int, files: int}
     */
    public function usage(): array
    {
        $size = 0;
        $files = 0;
        foreach ($this->entries(Path::ROOT, true) as $entry) {
            if ($entry instanceof FileAttributes) {
                $size += $entry->fileSize() ?? $this->storage->fileSize($entry->path());
                $files++;
            }
        }

        return ['size' => $size, 'files' => $files];
    }

    /**
     * @return array{root: array<string, mixed>}
     */
    public function tree(): array
    {
        $children = [];
        foreach ($this->entries(Path::ROOT, true) as $entry) {
            if ($entry instanceof DirectoryAttributes) {
                $children[Path::parent($this->pathOf($entry))][] = $this->pathOf($entry);
            }
        }
        $build = function (string $path) use (&$build, $children): array {
            $folders = $children[$path] ?? [];
            usort($folders, fn ($a, $b) => self::compare(Path::name($a), Path::name($b)));

            return [
                'name' => $path === Path::ROOT ? '' : Path::name($path),
                'path' => $path,
                'folders' => array_map($build, $folders),
            ];
        };

        return ['root' => $build(Path::ROOT)];
    }

    /**
     * @return array{path: string, folders: list<array<string, mixed>>, files: list<array<string, mixed>>}
     */
    public function folder(string $path): array
    {
        $this->assertFolder($path);
        $folders = [];
        $files = [];
        foreach ($this->entries($path, false) as $entry) {
            if ($entry instanceof DirectoryAttributes) {
                $folders[] = $this->folderData($this->pathOf($entry));
            } elseif ($entry instanceof FileAttributes) {
                $files[] = $this->fileData($entry);
            }
        }
        usort($folders, fn ($a, $b) => self::compare($a['name'], $b['name']));
        usort($files, fn ($a, $b) => self::compare($a['name'], $b['name']));

        return ['path' => $path, 'folders' => $folders, 'files' => $files];
    }

    /**
     * @return array{name: string, path: string}
     */
    public function createFolder(string $parent, mixed $name): array
    {
        $this->assertManage();
        $this->assertFolder($parent);
        $path = Path::join($parent, Name::folder($name));
        $this->assertFree($path);
        $this->storage->createDirectory($this->owner->location($path));

        return $this->folderData($path);
    }

    /**
     * @return array{name: string, path: string}
     */
    public function renameFolder(string $path, mixed $name): array
    {
        $this->assertManage();
        if ($path === Path::ROOT) {
            throw TansuException::invalidPath();
        }
        $this->assertFolder($path);
        $target = Path::join(Path::parent($path), Name::folder($name));
        if ($target === $path) {
            return $this->folderData($path);
        }
        $this->assertFree($target);
        $this->assertNotInUse($this->filesWithin($path));

        $entries = $this->entries($path, true);
        $this->storage->createDirectory($this->owner->location($target));
        foreach ($entries as $entry) {
            $to = $target.substr($this->pathOf($entry), strlen($path));
            if ($entry instanceof DirectoryAttributes) {
                $this->storage->createDirectory($this->owner->location($to));
            } else {
                $this->storage->move($entry->path(), $this->owner->location($to));
            }
        }
        $this->storage->deleteDirectory($this->owner->location($path));

        return $this->folderData($target);
    }

    public function deleteFolder(string $path): void
    {
        $this->assertManage();
        if ($path === Path::ROOT) {
            throw TansuException::invalidPath();
        }
        $this->assertFolder($path);
        $this->assertNotInUse($this->filesWithin($path));
        $this->storage->deleteDirectory($this->owner->location($path));
    }

    /**
     * Stores an upload under a free name in $folder.
     *
     * @param int $size the upload's size in bytes, checked before $contents is read
     * @param Closure(): string $contents reads the upload
     * @param array<string, mixed> $optimize the upload's `optimize`, `max_width`, `max_height` and `quality` fields
     * @return array<string, mixed> the stored file, with `optimized` and `optimization_skipped`
     */
    public function upload(string $folder, mixed $name, int $size, Closure $contents, array $optimize = []): array
    {
        $this->assertManage();
        $this->assertFolder($folder);
        $optimization = $this->options->optimize->resolve($optimize);
        if ($size > $this->limits->fileSize) {
            throw TansuException::fileTooLarge();
        }
        $bytes = $contents();
        // The limit of the type's group, before any work
        if ($size > $this->limits->fileSizeFor($this->inspector->type($bytes))) {
            throw TansuException::fileTooLarge();
        }
        $inspection = $this->inspector->inspect($bytes, $optimization);
        unset($bytes);
        $this->assertRoom(strlen($inspection->bytes));

        $wanted = Name::file(is_string($name) && $name !== '' ? $name : 'file', FileTypes::extension($inspection->type));
        $path = $this->freeName($folder, $wanted);
        $this->storage->write($this->owner->location($path), $inspection->bytes);

        $skipped = $inspection->optimization?->skipped;
        if ($skipped !== null) {
            $this->logger->log(self::SKIP_LEVELS[$skipped] ?? LogLevel::WARNING, "tansu: stored without optimizing ($skipped)", [
                'owner' => $this->owner->id,
                'path' => $path,
                'type' => $inspection->type,
                ...$inspection->optimization->context,
                'memory_limit' => (string) ini_get('memory_limit'),
            ]);
        }

        return [
            ...$this->fileData($this->attributes($path)),
            'optimized' => $inspection->optimization?->optimized ?? false,
            'optimization_skipped' => $skipped,
        ];
    }

    /**
     * Renames a file in its folder. The extension stays the same.
     *
     * @return array<string, mixed>
     */
    public function renameFile(string $path, mixed $name): array
    {
        $this->assertManage();
        $this->assertFile($path);
        [, $extension] = Name::split(Path::name($path));
        if (is_string($name) && $extension !== '' && str_ends_with(strtolower($name), strtolower($extension))) {
            $name = substr($name, 0, -strlen($extension));
        }
        // Name::folder keeps dots inside the name ("v1.2"); Name::file then only adds the extension
        $base = Name::folder($name);
        $target = Path::join(Path::parent($path), $extension === '' ? $base : Name::file($base.'.x', substr($extension, 1)));
        if ($target !== $path) {
            $this->assertFree($target);
            $this->assertNotInUse([$path]);
            $this->storage->move($this->owner->location($path), $this->owner->location($target));
        }

        return $this->fileData($this->attributes($target));
    }

    public function deleteFile(string $path): void
    {
        $this->assertManage();
        $this->assertFile($path);
        $this->assertNotInUse([$path]);
        $this->storage->delete($this->owner->location($path));
    }

    /**
     * Opens a stored file for serving it (GET /raw).
     *
     * @return array{stream: resource, type: string, size: int, name: string}
     */
    public function read(string $path): array
    {
        $this->assertFile($path);
        $location = $this->owner->location($path);

        return [
            'stream' => $this->storage->readStream($location),
            'type' => FileTypes::typeOf(Path::name($path)) ?? 'application/octet-stream',
            'size' => $this->storage->fileSize($location),
            'name' => Path::name($path),
        ];
    }

    /**
     * Natural, case-insensitive order of names.
     */
    public static function compare(string $a, string $b): int
    {
        return strnatcasecmp($a, $b) ?: strcmp($a, $b);
    }

    /**
     * Entries under a folder, without hidden names (starting with ".") and without anything inside hidden folders.
     *
     * @return list<StorageAttributes>
     */
    private function entries(string $path, bool $deep): array
    {
        $location = $this->owner->location($path);
        if (!$this->storage->directoryExists($location)) {
            return [];
        }
        $entries = [];
        foreach ($this->storage->listContents($location, $deep) as $entry) {
            $relative = substr($entry->path(), strlen($location));
            if ($relative === '' || str_contains('/'.ltrim($relative, '/'), '/.')) {
                continue;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    private function pathOf(StorageAttributes $entry): string
    {
        return '/'.ltrim(substr($entry->path(), strlen($this->owner->root)), '/');
    }

    /**
     * @return list<string>
     */
    private function filesWithin(string $folder): array
    {
        $files = [];
        foreach ($this->entries($folder, true) as $entry) {
            if ($entry instanceof FileAttributes) {
                $files[] = $this->pathOf($entry);
            }
        }

        return $files;
    }

    private function freeName(string $folder, string $name): string
    {
        [$base, $extension] = Name::split($name);
        $path = Path::join($folder, $name);
        for ($i = 1; $this->exists($path); $i++) {
            $suffix = "-$i";
            $path = Path::join($folder, mb_strcut($base, 0, 255 - strlen($suffix.$extension), 'UTF-8').$suffix.$extension);
        }

        return $path;
    }

    private function exists(string $path): bool
    {
        $location = $this->owner->location($path);

        return $this->storage->fileExists($location) || $this->storage->directoryExists($location);
    }

    private function attributes(string $path): FileAttributes
    {
        $location = $this->owner->location($path);

        return new FileAttributes($location, $this->storage->fileSize($location), null, $this->storage->lastModified($location));
    }

    /**
     * @return array{name: string, path: string}
     */
    private function folderData(string $path): array
    {
        return ['name' => Path::name($path), 'path' => $path];
    }

    /**
     * @return array<string, mixed>
     */
    private function fileData(FileAttributes $entry): array
    {
        $path = $this->pathOf($entry);
        $modified = $entry->lastModified() ?? $this->storage->lastModified($entry->path());

        return [
            'name' => Path::name($path),
            'path' => $path,
            'size' => $entry->fileSize() ?? $this->storage->fileSize($entry->path()),
            'type' => FileTypes::typeOf(Path::name($path)) ?? 'application/octet-stream',
            'modified' => (new DateTimeImmutable('@'.$modified))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format(DATE_ATOM),
            'url' => ($this->url)($entry->path(), $path),
        ];
    }

    private function assertManage(): void
    {
        if (!$this->owner->canManage) {
            throw TansuException::forbidden();
        }
    }

    private function assertFolder(string $path): void
    {
        if ($path !== Path::ROOT && !$this->storage->directoryExists($this->owner->location($path))) {
            throw TansuException::notFound($path);
        }
    }

    private function assertFile(string $path): void
    {
        if ($path === Path::ROOT || !$this->storage->fileExists($this->owner->location($path))) {
            throw TansuException::notFound($path);
        }
    }

    private function assertFree(string $path): void
    {
        if ($this->exists($path)) {
            throw TansuException::nameTaken($path);
        }
    }

    /**
     * @param list<string> $paths
     */
    private function assertNotInUse(array $paths): void
    {
        if ($paths === []) {
            return;
        }
        $used = $this->inUse->inUse($this->owner, $paths);
        if ($used !== []) {
            throw TansuException::inUse($used[0]);
        }
    }

    private function assertRoom(int $size): void
    {
        if ($this->limits->totalSize === null && $this->limits->files === null) {
            return;
        }
        $usage = $this->usage();
        if (($this->limits->totalSize !== null && $usage['size'] + $size > $this->limits->totalSize)
            || ($this->limits->files !== null && $usage['files'] + 1 > $this->limits->files)) {
            throw TansuException::quotaExceeded();
        }
    }
}
