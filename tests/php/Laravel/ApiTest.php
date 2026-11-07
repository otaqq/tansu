<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Tests\Laravel;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use Otaqq\Tansu\InUseChecker;
use Otaqq\Tansu\Laravel\OwnerResolver;
use Otaqq\Tansu\Laravel\Tansu;
use Otaqq\Tansu\Laravel\TansuServiceProvider;
use Otaqq\Tansu\Limits;
use Otaqq\Tansu\Owner;
use Otaqq\Tansu\Tests\Images;

final class ApiTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TansuServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // The API is tested without the host's session and CSRF middleware
        $app['config']->set('tansu.routes.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function login(int|string $id = 7): void
    {
        $this->actingAs(new GenericUser(['id' => $id]));
    }

    private function jpeg(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, Images::jpeg());
    }

    public function test_requires_a_logged_in_user_with_the_default_resolver(): void
    {
        $this->getJson('/tansu/info')
            ->assertStatus(401)
            ->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'You are not logged in.']]);
    }

    public function test_info_with_configured_limits_and_types(): void
    {
        config(['tansu.limits.file_size' => 1000, 'tansu.limits.files' => 5, 'tansu.accept' => ['image/png']]);
        $this->login();

        $this->getJson('/tansu/info')->assertOk()->assertExactJson([
            'protocol' => 1,
            'accept' => [['type' => 'image/png', 'extensions' => ['png'], 'group' => 'image']],
            'limits' => ['file_size' => 1000, 'file_sizes' => null, 'total_size' => null, 'files' => 5],
            'optimize' => ['enabled' => false],
            'usage' => ['size' => 0, 'files' => 0],
            'can' => ['manage' => true],
        ]);
    }

    public function test_defaults_accept_every_group_with_a_size_per_group(): void
    {
        $this->login();

        $info = $this->getJson('/tansu/info')->assertOk();
        $this->assertSame(['image', 'image', 'image', 'image', 'document', 'video', 'video'], array_column($info->json('accept'), 'group'));
        $this->assertSame(['image' => 5 * 1024 * 1024, 'document' => 10 * 1024 * 1024, 'video' => 50 * 1024 * 1024], $info->json('limits.file_sizes'));
        $this->assertSame(50 * 1024 * 1024, $info->json('limits.file_size'));
    }

    public function test_the_app_config_is_merged_over_the_defaults_at_every_depth(): void
    {
        $defaults = require __DIR__.'/../../../lang/php/laravel/config/tansu.php';
        $merged = TansuServiceProvider::mergeConfig($defaults, [
            'accept' => ['image'],
            'limits' => ['file_size' => ['image' => 4 * 1024 * 1024], 'files' => 50],
            'optimize' => ['enabled' => true, 'custom' => ['quality' => ['default' => 80]]],
            'routes' => ['middleware' => ['web']],
        ]);

        $this->assertSame(['image'], $merged['accept']);
        $this->assertSame(['image' => 4 * 1024 * 1024, 'document' => 10 * 1024 * 1024, 'video' => 50 * 1024 * 1024], $merged['limits']['file_size']);
        $this->assertSame([null, 50], [$merged['limits']['total_size'], $merged['limits']['files']]);
        $this->assertTrue($merged['optimize']['enabled']);
        $this->assertSame(['min' => 40, 'max' => 100, 'default' => 80], $merged['optimize']['custom']['quality']);
        $this->assertSame(2.0, $merged['optimize']['memory_margin']);
        $this->assertSame(['web'], $merged['routes']['middleware']);
        $this->assertSame('tansu', $merged['routes']['prefix']);
        // A plain value replaces a map, and null hides custom input
        $this->assertSame(1000, TansuServiceProvider::mergeConfig($defaults, ['limits' => ['file_size' => 1000]])['limits']['file_size']);
        $this->assertNull(TansuServiceProvider::mergeConfig($defaults, ['optimize' => ['custom' => null]])['optimize']['custom']);
    }

    public function test_upload_with_an_optimization_preset(): void
    {
        config([
            'tansu.optimize.enabled' => true,
            'tansu.optimize.presets' => ['logo' => ['label' => ['en' => 'Logo', 'ja' => 'ロゴ'], 'max_width' => 100, 'max_height' => 100]],
        ]);
        $this->login();

        $this->getJson('/tansu/info')->assertOk()
            ->assertJsonPath('optimize.presets.0', ['key' => 'logo', 'label' => ['en' => 'Logo', 'ja' => 'ロゴ'], 'max_width' => 100, 'max_height' => 100, 'quality' => null])
            ->assertJsonPath('optimize.custom.quality.default', 85);
        $this->post('/tansu/files', ['path' => '/', 'optimize' => 'logo', 'file' => UploadedFile::fake()->createWithContent('a.jpg', Images::jpeg(400, 200))], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('optimized', true)->assertJsonPath('optimization_skipped', null);
        $this->assertSame([100, 50], Images::size(Storage::disk('public')->get('tansu/users/7/a.jpg')));

        $this->post('/tansu/files', ['path' => '/', 'optimize' => 'custom', 'quality' => '5', 'file' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_request');
    }

    public function test_skipped_optimizations_go_to_the_configured_log_channel(): void
    {
        config([
            'tansu.optimize.enabled' => true,
            'tansu.optimize.custom.max_width.min' => 1,
            'tansu.optimize.log_channel' => 'media',
        ]);
        Log::shouldReceive('channel')->once()->with('media')->andReturnSelf();
        Log::shouldReceive('log')->once()->withArgs(fn ($level, $message, $context) => $level === 'info'
            && str_contains($message, 'animated') && $context['path'] === '/anim.gif' && $context['owner'] === '7');
        $this->login();

        $this->post('/tansu/files', ['path' => '/', 'optimize' => 'custom', 'max_width' => '2', 'file' => UploadedFile::fake()->createWithContent('anim.gif', Images::animatedGif())], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('optimized', false)->assertJsonPath('optimization_skipped', 'animated');
    }

    public function test_upload_list_rename_and_delete(): void
    {
        $this->login();

        $this->postJson('/tansu/folders', ['path' => '/', 'name' => 'shop'])
            ->assertCreated()->assertExactJson(['name' => 'shop', 'path' => '/shop']);
        $this->post('/tansu/files', ['path' => '/shop', 'file' => $this->jpeg('front door.png')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJson(['name' => 'front-door.jpg', 'path' => '/shop/front-door.jpg', 'type' => 'image/jpeg', 'url' => '/storage/tansu/users/7/shop/front-door.jpg']);
        Storage::disk('public')->assertExists('tansu/users/7/shop/front-door.jpg');

        $this->getJson('/tansu/folder?path=/shop')->assertOk()->assertJsonPath('files.0.name', 'front-door.jpg');
        $this->getJson('/tansu/tree')->assertOk()->assertJsonPath('root.folders.0.path', '/shop');

        $this->patchJson('/tansu/files', ['path' => '/shop/front-door.jpg', 'name' => 'entrance'])
            ->assertOk()->assertJsonPath('path', '/shop/entrance.jpg');
        $this->patchJson('/tansu/folders', ['path' => '/shop', 'name' => 'store'])
            ->assertOk()->assertExactJson(['name' => 'store', 'path' => '/store']);
        $this->deleteJson('/tansu/files?path=/store/entrance.jpg')->assertNoContent();
        $this->deleteJson('/tansu/folders?path=/store')->assertNoContent();
        $this->getJson('/tansu/folder?path=/')->assertExactJson(['path' => '/', 'folders' => [], 'files' => []]);
    }

    public function test_errors_have_one_shape(): void
    {
        $this->login();

        $this->getJson('/tansu/folder?path=../etc')
            ->assertStatus(422)->assertExactJson(['error' => ['code' => 'invalid_path', 'message' => 'The path is not valid.']]);
        $this->getJson('/tansu/folder?path=/missing')
            ->assertNotFound()->assertJsonPath('error.code', 'not_found')->assertJsonPath('error.path', '/missing');
        $this->post('/tansu/files', ['path' => '/', 'file' => UploadedFile::fake()->createWithContent('x.jpg', '<?php')], ['Accept' => 'application/json'])
            ->assertStatus(415)->assertJsonPath('error.code', 'type_not_allowed');
        $this->post('/tansu/files', ['path' => '/'], ['Accept' => 'application/json'])
            ->assertStatus(400)->assertJsonPath('error.code', 'upload_failed');
        $this->postJson('/tansu/folders', ['path' => '/', 'name' => '...'])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_name');
    }

    public function test_file_too_large_by_configured_limit(): void
    {
        config(['tansu.limits.file_size' => 10]);
        $this->login();

        $this->post('/tansu/files', ['path' => '/', 'file' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(413)->assertJsonPath('error.code', 'file_too_large');
    }

    public function test_the_default_resolver_refuses_other_owners(): void
    {
        $this->login(7);

        $this->getJson('/tansu/info?owner=8')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->getJson('/tansu/info?owner=7')->assertOk();
    }

    public function test_a_host_resolver_decides_the_owner_its_rights_and_limits(): void
    {
        $this->app->instance(CompanyResolver::class, new CompanyResolver());
        config(['tansu.owner_resolver' => CompanyResolver::class]);

        $this->getJson('/tansu/info?owner=c1')->assertOk()
            ->assertJsonPath('can.manage', false)
            ->assertJsonPath('limits', ['file_size' => 100, 'file_sizes' => null, 'total_size' => 1000, 'files' => 2]);
        $this->postJson('/tansu/folders?owner=c1', ['path' => '/', 'name' => 'x'])->assertForbidden();
        $this->getJson('/tansu/info?owner=c2')->assertForbidden();
    }

    public function test_in_use_checker_from_config(): void
    {
        config(['tansu.in_use_checker' => EverythingInUse::class]);
        $this->login();
        $this->post('/tansu/files', ['path' => '/', 'file' => $this->jpeg()], ['Accept' => 'application/json'])->assertCreated();

        $this->deleteJson('/tansu/files?path=/photo.jpg')
            ->assertStatus(409)->assertJsonPath('error', ['code' => 'in_use', 'message' => 'The file is in use.', 'path' => '/photo.jpg']);
    }

    public function test_raw_serves_files_safely_when_urls_go_through_the_route(): void
    {
        config(['tansu.url' => 'route']);
        $this->login();
        $url = $this->post('/tansu/files', ['path' => '/', 'file' => $this->jpeg('写真.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->json('url');

        $this->assertSame('http://localhost/tansu/raw?path=%2F%E5%86%99%E7%9C%9F.jpg', $url);
        $response = $this->get($url)->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', "inline; filename*=UTF-8''%E5%86%99%E7%9C%9F.jpg");
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertSame(Storage::disk('public')->get('tansu/users/7/写真.jpg'), $response->streamedContent());

        $this->get('/tansu/raw?path=/missing.jpg')->assertNotFound();
    }

    public function test_location_turns_a_picked_path_into_a_disk_location(): void
    {
        $tansu = $this->app->make(Tansu::class);
        $owner = new Owner('7', 'tansu/users/7');

        $this->assertSame('tansu/users/7/a/b.jpg', $tansu->location($owner, '/a/b.jpg'));
        $this->assertNull($tansu->location($owner, '/../x'));
        $this->assertNull($tansu->location($owner, '/'));
    }
}

final class CompanyResolver implements OwnerResolver
{
    public function resolve(Request $request, ?string $requested): Owner
    {
        if ($requested !== 'c1') {
            throw new AuthorizationException();
        }

        return new Owner('c1', 'companies/c1', canManage: false, limits: new Limits(100, 1000, 2));
    }
}

final class EverythingInUse implements InUseChecker
{
    public function inUse(Owner $owner, array $paths): array
    {
        return $paths;
    }
}
