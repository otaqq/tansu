<?php

declare(strict_types=1);

namespace Otaqq\Tansu\Tests\Laravel;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;
use Otaqq\Tansu\Laravel\AbstractOwnerResolver;
use Otaqq\Tansu\Laravel\TansuServiceProvider;

final class OwnerResolverTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TansuServiceProvider::class];
    }

    private function request(?GenericUser $user): Request
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_requires_a_logged_in_user(): void
    {
        $resolver = new class extends AbstractOwnerResolver
        {
            protected function ownerId(Request $request, ?string $requested): string
            {
                return 'acme';
            }
        };

        $this->expectException(AuthenticationException::class);
        $resolver->resolve($this->request(null), null);
    }

    public function test_builds_an_owner_from_owner_id_and_manages_by_default(): void
    {
        $resolver = new class extends AbstractOwnerResolver
        {
            protected function ownerId(Request $request, ?string $requested): string
            {
                return 'acme';
            }
        };

        $owner = $resolver->resolve($this->request(new GenericUser(['id' => 7])), null);

        $this->assertSame('acme', $owner->id);
        $this->assertSame('acme', $owner->root);
        $this->assertTrue($owner->canManage);
    }

    public function test_root_can_be_overridden(): void
    {
        $resolver = new class extends AbstractOwnerResolver
        {
            protected function ownerId(Request $request, ?string $requested): string
            {
                return 'acme';
            }

            protected function root(string $id): string
            {
                return 'tenants/'.$id.'/images';
            }
        };

        $owner = $resolver->resolve($this->request(new GenericUser(['id' => 7])), null);

        $this->assertSame('tenants/acme/images', $owner->root);
    }

    public function test_manage_follows_the_gate_ability_with_the_owner_id(): void
    {
        Gate::define('library.manage', fn ($user, string $id) => $user->id === 7 && $id === 'acme');

        $resolver = new class extends AbstractOwnerResolver
        {
            protected ?string $manageAbility = 'library.manage';

            protected function ownerId(Request $request, ?string $requested): string
            {
                return 'acme';
            }
        };

        $this->assertTrue($resolver->resolve($this->request(new GenericUser(['id' => 7])), null)->canManage);
        $this->assertFalse($resolver->resolve($this->request(new GenericUser(['id' => 9])), null)->canManage);
    }

    public function test_owner_id_may_authorize_the_requested_owner(): void
    {
        $resolver = new class extends AbstractOwnerResolver
        {
            protected function ownerId(Request $request, ?string $requested): string
            {
                if ($requested !== null && $requested !== 'acme') {
                    throw new AuthorizationException();
                }

                return 'acme';
            }
        };

        $this->assertSame('acme', $resolver->resolve($this->request(new GenericUser(['id' => 7])), 'acme')->id);

        $this->expectException(AuthorizationException::class);
        $resolver->resolve($this->request(new GenericUser(['id' => 7])), 'other');
    }
}
