<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Cache;

use Hachitechs\Hideguard\Model\Cache\{PolicyFiles, SnapshotReader, ManagedVcl, Activation};
use Hachitechs\Hideguard\Plugin\CachePolicyMutation;
use Hachitechs\HideguardPhp\Rules\PathMatcher;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\HTTP\Client\{Curl, CurlFactory};
use Magento\PageCache\Model\Cache\Server;
use PHPUnit\Framework\TestCase;

class ManagedCacheTest extends TestCase
{
    private string $directory;
    private PolicyFiles $files;
    private DeploymentConfig $deployment;
    private const VCL = 'vcl 4.0; import std; backend default { .host="127.0.0.1"; } acl purge { "127.0.0.1"; } sub vcl_recv {}';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/hg_policy_' . bin2hex(random_bytes(6));
        $this->deployment = $this->createStub(DeploymentConfig::class);
        $this->deployment->method('get')->willReturnCallback(fn($key, $default = null) => match ($key) {
            'hideguard/cache/managed' => true, 'hideguard/cache/shared_path' => $this->directory,
            'http_cache_hosts' => [['host' => 'fixture']], default => $default,
        });
        $this->files = new PolicyFiles($this->createStub(DirectoryList::class), $this->deployment);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function export(): array
    {
        $reader = $this->createStub(SnapshotReader::class);
        $reader->method('read')->willReturn([
            ['store_id' => 1, 'bases' => ['https://shop.example/en/'],
                'patterns' => ['hideguard/*', 'catalog/product/*', 'diamond.html', 'Campaign/*']],
            ['store_id' => 2, 'bases' => ['https://shop.example/fr/'], 'patterns' => ['*']],
        ]);
        $vcl = (new ManagedVcl($this->files, $reader, new PathMatcher()))->generate(self::VCL);
        preg_match('/managed snapshot: ([a-f0-9]{64})/', $vcl, $match);
        self::assertNotEmpty($match);
        return [$vcl, $match[1]];
    }

    public function testExportNeverCreatesReadinessAndContainsScopedRoutesAndConservativeFallbacks(): void
    {
        [$vcl, $id] = $this->export();
        self::assertFileDoesNotExist($this->files->marker($id));
        self::assertSame($this->files->generation(), $this->files->descriptor($id)['generation']);
        foreach (
            ['!std.file_exists(', 'diamond', 'catalog/product/', '^/en/', 'X-Original-URL',
            'X-Hideguard-Snapshot', 'hash_data("hideguard:' . $id, 'client.ip !~ purge', 'obj.status != 0', 'return (pass)'] as $token
        ) {
            self::assertStringContainsString($token, $vcl);
        }
        self::assertStringNotContainsString('verificationId', $vcl);
    }

    public function testMutationRetiresBeforeBodyAndDoesNotRestoreReadinessOnRollback(): void
    {
        [, $id] = $this->export();
        $this->files->locked(fn() => $this->files->write('ready-' . $id, 'ready'));
        $before = $this->files->generation();
        try {
            (new CachePolicyMutation($this->files))->aroundReplace(new \stdClass(), function () use ($id): void {
                clearstatcache();
                self::assertFileDoesNotExist($this->files->marker($id));
                $this->files->mutate(fn() => self::assertTrue(true)); // Reentrant native persistence.
                throw new \RuntimeException('database rollback');
            });
            self::fail('Mutation error swallowed.');
        } catch (\RuntimeException $error) {
            self::assertSame('database rollback', $error->getMessage());
        }
        self::assertNotSame($before, $this->files->generation());
        self::assertFileDoesNotExist($this->files->marker($id));
    }

    /** @dataProvider persistenceMethods */
    public function testEveryNativeMutationUsesTheSameInvalidationBoundary(string $method): void
    {
        $files = $this->createMock(PolicyFiles::class);
        $files->expects(self::once())->method('mutate')->willReturnCallback(fn($work) => $work());
        self::assertSame('result', (new CachePolicyMutation($files))->$method(
            new \stdClass(),
            fn(...$args) => $args === ['a', 'b'] ? 'result' : 'bad',
            'a',
            'b'
        ));
    }

    public static function persistenceMethods(): array
    {
        return array_map(fn($method) => [$method], ['aroundSaveConfig', 'aroundDeleteConfig', 'aroundSave',
            'aroundDelete', 'aroundReplace', 'aroundDeleteByData', 'aroundImport']);
    }

    public function testActivationPurgesEveryProxyBeforeReadyAndRequiresBothAcks(): void
    {
        [, $id] = $this->export();
        $servers = $this->createStub(Server::class);
        $servers->method('getUris')->willReturn(['http://one/', 'http://two/']);
        $factory = $this->createMock(CurlFactory::class);
        $calls = [];
        $factory->expects(self::exactly(4))->method('create')->willReturnCallback(function () use ($id, &$calls) {
            $client = $this->createMock(Curl::class);
            $client->method('getStatus')->willReturn(200);
            $client->method('get')->willReturnCallback(function ($url) use ($id, &$calls): void {
                clearstatcache();
                $calls[] = [$url, is_file($this->files->marker($id))];
            });
            $client->method('getHeaders')->willReturnCallback(function () use ($id) {
                clearstatcache();
                return ['X-Hideguard-Snapshot' => $id,
                    'X-Hideguard-Ready' => is_file($this->files->marker($id)) ? '1' : '0'];
            });
            return $client;
        });
        (new Activation($this->files, $servers, $factory, $this->deployment))->activate($id);
        self::assertSame([['http://one/', false], ['http://two/', false], ['http://one/', true], ['http://two/', true]], $calls);
    }

    public function testConcurrentPublicationStopsMutationBeforeItsBodyWithBoundedWait(): void
    {
        $other = new PolicyFiles($this->createStub(DirectoryList::class), $this->deployment);
        $called = false;
        $start = hrtime(true);
        $this->files->locked(function () use ($other, &$called): void {
            try {
                $other->mutate(function () use (&$called): void {
                    $called = true;
                });
                self::fail('Mutation entered an active publication.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('busy', $error->getMessage());
            }
        });
        self::assertFalse($called);
        self::assertLessThan(5, (hrtime(true) - $start) / 1000000000);
    }

    public function testStaleSnapshotCannotMakeReadyAgain(): void
    {
        [, $id] = $this->export();
        $this->files->mutate(fn() => null);
        $factory = $this->createMock(CurlFactory::class);
        $factory->expects(self::never())->method('create');
        $this->expectExceptionMessage('stale');
        (new Activation($this->files, $this->createStub(Server::class), $factory, $this->deployment))->activate($id);
    }

    /** @dataProvider rejectedAcknowledgements */
    public function testAnyWrongAckRetiresReadiness(int $status, string $snapshot, string $ready): void
    {
        [, $id] = $this->export();
        $servers = $this->createStub(Server::class);
        $servers->method('getUris')->willReturn(['http://one/']);
        $client = $this->createStub(Curl::class);
        $client->method('getStatus')->willReturn($status);
        $client->method('getHeaders')->willReturn(['X-Hideguard-Snapshot' => $snapshot === 'same' ? $id : $snapshot,
            'X-Hideguard-Ready' => $ready]);
        $factory = $this->createStub(CurlFactory::class);
        $factory->method('create')->willReturn($client);
        try {
            (new Activation($this->files, $servers, $factory, $this->deployment))->activate($id);
            self::fail('Bad proxy acknowledgement accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('acknowledge', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->files->marker($id));
    }

    public static function rejectedAcknowledgements(): array
    {
        return [[503, 'same', '0'], [200, 'wrong', '0'], [200, 'same', '1']];
    }
}
