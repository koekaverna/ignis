<?php
declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        if (!getenv('IGNIS_NO_SCOPE')) {
            yield new \Ignis\Symfony\IgnisBundle();
        }
        yield new \Doctrine\Bundle\DoctrineBundle\DoctrineBundle();
        if (!getenv('IGNIS_NO_DOCTRINE_SCOPE')) {
            yield new \Ignis\Doctrine\IgnisDoctrineBundle();
        }
    }

    protected function configureContainer(ContainerConfigurator $c, LoaderInterface $loader): void
    {
        $c->extension('framework', [
            'secret' => 'probe',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => false,
            'router' => ['utf8' => true],
        ]);

        $c->extension('security', [
            'password_hashers' => [InMemoryUser::class => ['algorithm' => 'plaintext']],
            'providers' => ['inmem' => ['memory' => ['users' => [
                'alice' => ['password' => 'alicepw', 'roles' => ['ROLE_USER']],
                'bob'   => ['password' => 'bobpw',   'roles' => ['ROLE_USER', 'ROLE_ADMIN']],
            ]]]],
            'firewalls' => ['main' => [
                'pattern'    => '^/',
                'stateless'  => true,
                'provider'   => 'inmem',
                'http_basic' => true,
            ]],
            'access_control' => [['path' => '^/whoami', 'roles' => 'ROLE_USER']],
        ]);

        // Two connections on the same database, so E24 can give them different pool settings.
        $connection = getenv('DATABASE_URL')
            ? ['url' => getenv('DATABASE_URL')]                                          // E24 runs the same app on PostgreSQL
            : ['driver' => 'pdo_sqlite', 'path' => '%kernel.project_dir%/var/probe.sqlite'];
        $mappings = ['App' => ['type' => 'attribute', 'dir' => '%kernel.project_dir%/src/Entity', 'prefix' => 'App\\Entity', 'is_bundle' => false]];

        $c->extension('doctrine', [
            'dbal' => ['default_connection' => 'default', 'connections' => ['default' => $connection, 'reporting' => $connection]],
            'orm'  => [
                'default_entity_manager' => 'default',
                'entity_managers' => ['default' => ['connection' => 'default', 'mappings' => $mappings]],
            ],
        ]);

        if (!getenv('IGNIS_NO_DOCTRINE_SCOPE')) {
            $c->extension('ignis_doctrine', [                  // E24 drives both connection modes from here
                'pool' => [
                    'size' => (int) (getenv('E24_POOL') ?: 0),
                    'wait_ms' => (int) (getenv('E24_POOL_WAIT_MS') ?: 5000),
                ],
                'connections' => ['reporting' => ['pool' => [
                    'size' => (int) (getenv('E24_POOL_REPORTING') ?: 0),
                ]]],
            ]);
        }

        // Narrowing knob: with it set, no vendor id is routed through Ignis\Scope::create(), so a
        // failure that survives it is not the scoped-object path's.
        if (getenv('IGNIS_NO_SCOPED_VENDOR')) {
            $c->parameters()->set('ignis.scoped_vendor_ids', []);
        }

        $s = $c->services();
        $s->defaults()->autowire()->autoconfigure();
        if (!getenv('IGNIS_NO_SCOPE')) {
            // ADR-0042, V-100: Symfony's own RequestStack, marked scoped by the container. The
            // 89-line facade this replaces measured identically and was deleted.
            $s->set('request_stack', \Symfony\Component\HttpFoundation\RequestStack::class);
        }
        $s->load('App\\', '../src/')->exclude('../src/Kernel.php');
        $s->get(\App\Controller\WhoAmI::class)->tag('controller.service_arguments');
        $s->get(\App\Controller\EmProbe::class)->tag('controller.service_arguments');
        $s->get(\App\Controller\PgProbe::class)->tag('controller.service_arguments');
        $s->get(\App\Controller\ResetProbe::class)->tag('controller.service_arguments');
        $s->get(\App\Controller\CacheGrowth::class)->tag('controller.service_arguments');
        $s->get(\App\Controller\SingletonProbe::class)->tag('controller.service_arguments');
        $s->get(\App\Controller\ScopedProbe::class)->tag('controller.service_arguments');
        // ADR-0042. The class knows nothing about this; the container decides, the way `lazy` is
        // decided. Unmarked, ScopedCart is a container singleton and two overlapping requests share
        // its state -- which is what IGNIS_NO_SCOPED_SERVICE turns this probe into: its control.
        $s->get(\App\Service\ScopedCart::class)->arg(0, 'built-at-boot');
        if (!getenv('IGNIS_NO_SCOPED_SERVICE') && !getenv('IGNIS_NO_SCOPE')) {
            $s->get(\App\Service\ScopedCart::class)->tag('ignis.scoped');
        }
        // In services_resetter's list on purpose: that list is what Kernel::boot() empties.
        $s->get(\App\Service\ResetWitness::class)->tag('kernel.reset', ['method' => 'reset']);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('whoami', '/whoami')->controller([\App\Controller\WhoAmI::class, '__invoke']);
        $routes->add('em', '/em')->controller([\App\Controller\EmProbe::class, '__invoke']);
        $routes->add('pg', '/pg')->controller([\App\Controller\PgProbe::class, '__invoke']);
        $routes->add('reset', '/reset')->controller([\App\Controller\ResetProbe::class, '__invoke']);
        $routes->add('cachegrowth', '/cachegrowth')->controller([\App\Controller\CacheGrowth::class, '__invoke']);
        $routes->add('singleton', '/singleton')->controller([\App\Controller\SingletonProbe::class, '__invoke']);
        $routes->add('scoped', '/scoped')->controller([\App\Controller\ScopedProbe::class, '__invoke']);
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__).'/var/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__).'/var/log';
    }
}
