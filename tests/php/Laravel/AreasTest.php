<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Tests\Laravel;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use Otaqq\Tansu\Laravel\TansuManager;
use Otaqq\Tansu\Laravel\TansuServiceProvider;
use Otaqq\Tansu\Tests\Images;

/**
 * More than one library from one package (config tansu.areas): each area has its own disk, types and routes, overlaid
 * on the default area. A route belongs to the area whose route name it carries.
 */
final class AreasTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TansuServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('tansu.routes.middleware', []);
        // The route name extends the default's ("tansu."), to exercise the longest-prefix match in the controller
        $app['config']->set('tansu.areas.docs', [
            'disk' => 'docs',
            'accept' => ['image/png'],
            'routes' => ['prefix' => 'docs-files', 'name' => 'tansu.docs.', 'middleware' => []],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('docs');
        $this->actingAs(new GenericUser(['id' => 7]));
    }

    public function test_each_area_stores_on_its_own_disk(): void
    {
        $this->post('/tansu/files', ['path' => '/', 'file' => UploadedFile::fake()->createWithContent('a.jpg', Images::jpeg())], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->post('/docs-files/files', ['path' => '/', 'file' => UploadedFile::fake()->createWithContent('b.png', Images::png())], ['Accept' => 'application/json'])
            ->assertCreated();

        Storage::disk('public')->assertExists('tansu/users/7/a.jpg');
        Storage::disk('public')->assertMissing('tansu/users/7/b.png');
        Storage::disk('docs')->assertExists('tansu/users/7/b.png');
        Storage::disk('docs')->assertMissing('tansu/users/7/a.jpg');
    }

    public function test_each_area_reports_its_own_accepted_types(): void
    {
        $this->assertSame(
            ['image', 'image', 'image', 'image', 'document', 'video', 'video'],
            array_column($this->getJson('/tansu/info')->assertOk()->json('accept'), 'group'),
        );
        $this->assertSame(
            [['type' => 'image/png', 'extensions' => ['png'], 'group' => 'image']],
            $this->getJson('/docs-files/info')->assertOk()->json('accept'),
        );
    }

    public function test_an_area_refuses_a_type_it_does_not_accept(): void
    {
        // The docs area accepts PNG only, so a JPEG is rejected there (415) but allowed on the default area
        $this->post('/docs-files/files', ['path' => '/', 'file' => UploadedFile::fake()->createWithContent('a.jpg', Images::jpeg())], ['Accept' => 'application/json'])
            ->assertStatus(415)->assertJsonPath('error.code', 'type_not_allowed');
        Storage::disk('docs')->assertMissing('tansu/users/7/a.jpg');
    }

    public function test_the_manager_lists_areas_and_resolves_them(): void
    {
        $manager = app(TansuManager::class);

        $this->assertSame(['default', 'docs'], $manager->names());
        $this->assertSame('tansu.', $manager->routes()['default']['name']);
        $this->assertSame('tansu.docs.', $manager->routes()['docs']['name']);
        // null and "default" are the same cached area; a named area is a distinct instance
        $this->assertSame($manager->area(), $manager->area('default'));
        $this->assertNotSame($manager->area('default'), $manager->area('docs'));
    }

    public function test_an_unknown_area_is_a_configuration_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(TansuManager::class)->area('nope');
    }
}
