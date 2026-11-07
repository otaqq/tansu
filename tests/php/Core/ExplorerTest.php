<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Tests\Core;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Otaqq\Tansu\Explorer;
use Otaqq\Tansu\FileTypes;
use Otaqq\Tansu\InUseChecker;
use Otaqq\Tansu\Limits;
use Otaqq\Tansu\Options;
use Otaqq\Tansu\Owner;
use Otaqq\Tansu\TansuException;
use Otaqq\Tansu\Tests\Images;
use PHPUnit\Framework\TestCase;

final class ExplorerTest extends TestCase
{
    private string $dir;

    private Filesystem $storage;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tansu-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->storage = new Filesystem(new LocalFilesystemAdapter($this->dir));
    }

    protected function tearDown(): void
    {
        $this->storage->deleteDirectory('');
        @rmdir($this->dir);
    }

    private function explorer(?Owner $owner = null, ?Options $options = null, ?InUseChecker $inUse = null): Explorer
    {
        return new Explorer(
            $this->storage,
            $owner ?? new Owner('42', 'tansu/42'),
            fn (string $location) => "/storage/$location",
            $options ?? new Options(),
            ...($inUse ? [$inUse] : []),
        );
    }

    private function upload(Explorer $explorer, string $folder, string $name, ?string $bytes = null): array
    {
        $bytes ??= Images::jpeg();

        return $explorer->upload($folder, $name, strlen($bytes), fn () => $bytes);
    }

    public function test_an_owner_starts_empty(): void
    {
        $explorer = $this->explorer();

        $this->assertSame(['path' => '/', 'folders' => [], 'files' => []], $explorer->folder('/'));
        $this->assertSame(['root' => ['name' => '', 'path' => '/', 'folders' => []]], $explorer->tree());
        $this->assertSame(['size' => 0, 'files' => 0], $explorer->usage());
    }

    public function test_info(): void
    {
        $info = $this->explorer(new Owner('1', 'o/1', canManage: false, limits: new Limits(100, 1000, 3)))->info();

        $this->assertSame(1, $info['protocol']);
        $this->assertSame(['type' => 'image/jpeg', 'extensions' => ['jpg', 'jpeg']], $info['accept'][0]);
        $this->assertSame(['file_size' => 100, 'total_size' => 1000, 'files' => 3], $info['limits']);
        $this->assertSame(['manage' => false], $info['can']);
    }

    public function test_upload_stores_under_the_owner_root_with_the_extension_of_the_content(): void
    {
        $file = $this->upload($this->explorer(), '/', 'shop photo.png');

        $this->assertSame('shop-photo.jpg', $file['name']);
        $this->assertSame('/shop-photo.jpg', $file['path']);
        $this->assertSame('image/jpeg', $file['type']);
        $this->assertSame('/storage/tansu/42/shop-photo.jpg', $file['url']);
        $this->assertSame(filesize($this->dir.'/tansu/42/shop-photo.jpg'), $file['size']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $file['modified']);
    }

    public function test_upload_never_overwrites(): void
    {
        $explorer = $this->explorer();
        $this->upload($explorer, '/', 'a.jpg');
        $explorer->createFolder('/', 'a-1.jpg');

        $this->assertSame('a-2.jpg', $this->upload($explorer, '/', 'a.jpg')['name']);
    }

    public function test_upload_strips_metadata(): void
    {
        $this->upload($this->explorer(), '/', 'a.jpg', Images::withExif(Images::jpeg()));

        $this->assertStringNotContainsString('GPS-SECRET', file_get_contents($this->dir.'/tansu/42/a.jpg'));
    }

    public function test_upload_checks_in_order_and_reads_nothing_too_large(): void
    {
        $explorer = $this->explorer(options: new Options(limits: new Limits(10)));

        try {
            $explorer->upload('/', 'a.jpg', 11, fn () => $this->fail('read'));
            $this->fail('no exception');
        } catch (TansuException $e) {
            $this->assertSame('file_too_large', $e->errorCode);
        }
        try {
            $explorer->upload('/missing', 'a.jpg', 11, fn () => '');
            $this->fail('no exception');
        } catch (TansuException $e) {
            $this->assertSame('not_found', $e->errorCode);
        }
    }

    public function test_upload_rejects_content_of_other_types(): void
    {
        $this->expectExceptionObject(TansuException::typeNotAllowed());
        $this->upload($this->explorer(), '/', 'evil.jpg', '<?php system($_GET["c"]);');
    }

    public function test_quota_by_file_count_and_total_size(): void
    {
        $byCount = $this->explorer(new Owner('1', 'o/1', limits: new Limits(1_000_000, null, 1)));
        $this->upload($byCount, '/', 'a.jpg');
        try {
            $this->upload($byCount, '/', 'b.jpg');
            $this->fail('no exception');
        } catch (TansuException $e) {
            $this->assertSame('quota_exceeded', $e->errorCode);
        }

        // The stored size is smaller than the upload (metadata is stripped), so take it from the first upload
        $stored = $this->upload($this->explorer(new Owner('3', 'o/3')), '/', 'a.jpg')['size'];
        $bySize = $this->explorer(new Owner('2', 'o/2', limits: new Limits(1_000_000, $stored * 2 - 1)));
        $this->upload($bySize, '/', 'a.jpg');
        $this->expectExceptionObject(TansuException::quotaExceeded());
        $this->upload($bySize, '/', 'b.jpg');
    }

    public function test_owners_do_not_see_each_other(): void
    {
        $this->upload($this->explorer(new Owner('1', 'tansu/1')), '/', 'a.jpg');
        $other = $this->explorer(new Owner('11', 'tansu/11'));

        $this->assertSame([], $other->folder('/')['files']);
        $this->assertSame(0, $other->usage()['files']);
    }

    public function test_folders_tree_and_listing_in_natural_order(): void
    {
        $explorer = $this->explorer();
        $explorer->createFolder('/', 'b');
        $explorer->createFolder('/', 'A');
        $explorer->createFolder('/b', 'item10');
        $explorer->createFolder('/b', 'item2');
        $this->upload($explorer, '/b', 'z.jpg');
        $this->upload($explorer, '/b', 'Y.jpg');
        mkdir($this->dir.'/tansu/42/.hidden');
        touch($this->dir.'/tansu/42/b/.DS_Store');

        $this->assertSame(
            ['root' => ['name' => '', 'path' => '/', 'folders' => [
                ['name' => 'A', 'path' => '/A', 'folders' => []],
                ['name' => 'b', 'path' => '/b', 'folders' => [
                    ['name' => 'item2', 'path' => '/b/item2', 'folders' => []],
                    ['name' => 'item10', 'path' => '/b/item10', 'folders' => []],
                ]],
            ]]],
            $explorer->tree(),
        );
        $listing = $explorer->folder('/b');
        $this->assertSame(['item2', 'item10'], array_column($listing['folders'], 'name'));
        $this->assertSame(['Y.jpg', 'z.jpg'], array_column($listing['files'], 'name'));
    }

    public function test_create_folder_refuses_taken_names_and_missing_parents(): void
    {
        $explorer = $this->explorer();
        $explorer->createFolder('/', 'a');

        try {
            $explorer->createFolder('/', 'a');
            $this->fail('no exception');
        } catch (TansuException $e) {
            $this->assertSame(['name_taken', '/a'], [$e->errorCode, $e->path]);
        }
        $this->expectExceptionObject(TansuException::notFound('/missing'));
        $explorer->createFolder('/missing', 'b');
    }

    public function test_rename_folder_moves_everything_inside(): void
    {
        $explorer = $this->explorer();
        $explorer->createFolder('/', 'old');
        $explorer->createFolder('/old', 'sub');
        $this->upload($explorer, '/old/sub', 'a.jpg');

        $this->assertSame(['name' => 'new', 'path' => '/new'], $explorer->renameFolder('/old', 'new'));
        $this->assertSame(['a.jpg'], array_column($explorer->folder('/new/sub')['files'], 'name'));
        $this->assertDirectoryDoesNotExist($this->dir.'/tansu/42/old');
    }

    public function test_rename_folder_refuses_taken_names_and_the_root(): void
    {
        $explorer = $this->explorer();
        $explorer->createFolder('/', 'a');
        $explorer->createFolder('/', 'b');

        try {
            $explorer->renameFolder('/a', 'b');
            $this->fail('no exception');
        } catch (TansuException $e) {
            $this->assertSame('name_taken', $e->errorCode);
        }
        $this->assertSame('/a', $explorer->renameFolder('/a', 'a')['path']);
        $this->expectExceptionObject(TansuException::invalidPath());
        $explorer->renameFolder('/', 'x');
    }

    public function test_delete_folder_deletes_everything_inside(): void
    {
        $explorer = $this->explorer();
        $explorer->createFolder('/', 'a');
        $this->upload($explorer, '/a', 'x.jpg');

        $explorer->deleteFolder('/a');

        $this->assertSame([], $explorer->folder('/')['folders']);
        $this->assertSame(0, $explorer->usage()['files']);
    }

    public function test_rename_file_keeps_the_extension(): void
    {
        $explorer = $this->explorer();
        $this->upload($explorer, '/', 'a.jpg');

        $this->assertSame('/b.jpg', $explorer->renameFile('/a.jpg', 'b')['path']);
        $this->assertSame('/c.jpg', $explorer->renameFile('/b.jpg', 'c.JPG')['path']);
        $this->assertSame('/d.php.jpg', $explorer->renameFile('/c.jpg', 'd.php')['path']);
        $this->assertSame('/v1.2.jpg', $explorer->renameFile('/d.php.jpg', 'v1.2')['path']);
    }

    public function test_rename_file_refuses_taken_and_empty_names(): void
    {
        $explorer = $this->explorer();
        $this->upload($explorer, '/', 'a.jpg');
        $this->upload($explorer, '/', 'b.jpg');

        try {
            $explorer->renameFile('/a.jpg', 'b');
            $this->fail('no exception');
        } catch (TansuException $e) {
            $this->assertSame('name_taken', $e->errorCode);
        }
        $this->expectExceptionObject(TansuException::invalidName());
        $explorer->renameFile('/a.jpg', '...');
    }

    public function test_delete_file(): void
    {
        $explorer = $this->explorer();
        $this->upload($explorer, '/', 'a.jpg');
        $explorer->deleteFile('/a.jpg');

        $this->assertFileDoesNotExist($this->dir.'/tansu/42/a.jpg');
        $this->expectExceptionObject(TansuException::notFound('/a.jpg'));
        $explorer->deleteFile('/a.jpg');
    }

    public function test_files_in_use_cannot_be_renamed_or_deleted_nor_their_folders(): void
    {
        $inUse = new class implements InUseChecker {
            /** @var list<array{string, list<string>}> */
            public array $calls = [];

            public function inUse(Owner $owner, array $paths): array
            {
                $this->calls[] = [$owner->id, $paths];

                return array_values(array_intersect($paths, ['/f/used.jpg']));
            }
        };
        $explorer = $this->explorer(inUse: $inUse);
        $explorer->createFolder('/', 'f');
        $this->upload($explorer, '/f', 'used.jpg');
        $this->upload($explorer, '/f', 'free.jpg');

        foreach ([
            fn () => $explorer->deleteFile('/f/used.jpg'),
            fn () => $explorer->renameFile('/f/used.jpg', 'x'),
            fn () => $explorer->deleteFolder('/f'),
            fn () => $explorer->renameFolder('/f', 'g'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('no exception');
            } catch (TansuException $e) {
                $this->assertSame(['in_use', '/f/used.jpg'], [$e->errorCode, $e->path]);
            }
        }
        $this->assertFileExists($this->dir.'/tansu/42/f/used.jpg');
        $explorer->deleteFile('/f/free.jpg');
        $this->assertSame(['42', ['/f/free.jpg']], end($inUse->calls));
    }

    public function test_read_only_owners_can_look_but_not_change(): void
    {
        $this->upload($this->explorer(), '/', 'a.jpg');
        $explorer = $this->explorer(new Owner('42', 'tansu/42', canManage: false));

        $this->assertCount(1, $explorer->folder('/')['files']);
        foreach ([
            fn () => $explorer->createFolder('/', 'x'),
            fn () => $this->upload($explorer, '/', 'b.jpg'),
            fn () => $explorer->renameFile('/a.jpg', 'b'),
            fn () => $explorer->deleteFile('/a.jpg'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('no exception');
            } catch (TansuException $e) {
                $this->assertSame('forbidden', $e->errorCode);
            }
        }
    }

    public function test_read(): void
    {
        $explorer = $this->explorer();
        $this->upload($explorer, '/', 'a.jpg');

        $file = $explorer->read('/a.jpg');
        $this->assertSame('image/jpeg', $file['type']);
        $this->assertSame($file['size'], strlen(stream_get_contents($file['stream'])));
        $this->expectExceptionObject(TansuException::notFound('/b.jpg'));
        $explorer->read('/b.jpg');
    }

    public function test_svg_is_accepted_and_sanitized_only_when_enabled(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $explorer = $this->explorer(options: new Options(new FileTypes([...FileTypes::DEFAULT, FileTypes::SVG])));

        $file = $this->upload($explorer, '/', 'logo.svg', $svg);
        $this->assertSame('image/svg+xml', $file['type']);
        $this->assertStringNotContainsString('alert', file_get_contents($this->dir.'/tansu/42/logo.svg'));
    }
}
