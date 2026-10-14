<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Unit;

use OscarAccountant\Errors\AuthenticationError;
use OscarAccountant\Errors\ConfigurationError;
use OscarAccountant\Http\Response;
use OscarAccountant\Internal\CliCredential;
use OscarAccountant\Internal\CliLogin;
use OscarAccountant\Internal\Credentials;
use OscarAccountant\Internal\Hooks;
use OscarAccountant\Internal\MissingCredential;
use OscarAccountant\Internal\ProviderCredential;
use OscarAccountant\Internal\StaticCredential;
use OscarAccountant\Oscar;
use OscarAccountant\Tests\Unit\Support\FakeOscar;
use OscarAccountant\Tests\Unit\Support\ScriptedTransport;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;

final class CredentialsTest extends TestCase
{
    use Environment;

    private const BASE = 'https://api.example.test';

    private ?FakeOscar $fake = null;

    private float $now = 1_790_000_000.0;

    protected function setUp(): void
    {
        $this->clearOscarEnvironment();
        CliLogin::forgetChecks();
    }

    protected function tearDown(): void
    {
        $this->fake?->remove();
        $this->fake = null;
        $this->restorePath();
        parent::tearDown();
    }

    private ?string $savedPath = null;

    private function fake(): FakeOscar
    {
        $this->fake ??= new FakeOscar();

        return $this->fake;
    }

    private function usePath(string $path): void
    {
        $current = getenv('PATH');
        $this->savedPath ??= \is_string($current) ? $current : '';
        putenv('PATH=' . $path . ':' . $this->savedPath);
    }

    private function restorePath(): void
    {
        if ($this->savedPath !== null) {
            putenv('PATH=' . $this->savedPath);
            $this->savedPath = null;
        }
    }

    private function login(?string $profile = null): CliLogin
    {
        $directory = $this->fake()->directory;

        return new CliLogin(self::BASE, $profile, fn(): float => $this->now, static fn(): string => $directory);
    }

    public function testOrder(): void
    {
        $hooks = new Hooks();
        self::assertInstanceOf(MissingCredential::class, Credentials::resolve(null, null, null, null, self::BASE, $hooks));
        self::assertInstanceOf(StaticCredential::class, Credentials::resolve('osk_a', null, null, null, self::BASE, $hooks));
        self::assertInstanceOf(ProviderCredential::class, Credentials::resolve('', static fn(): string => 't', null, null, self::BASE, $hooks));
        putenv('OSCAR_API_KEY=osk_env');
        $env = Credentials::resolve(null, null, 'cli', null, self::BASE, $hooks);
        self::assertSame('osk_env', $env->token());
        self::assertFalse($env->refreshable());
        $env->invalidate();
        putenv('OSCAR_API_KEY=');
        self::assertInstanceOf(CliCredential::class, Credentials::resolve(null, null, 'cli', null, self::BASE, $hooks));
        putenv('OSCAR_USE_CLI_LOGIN=1');
        self::assertInstanceOf(CliCredential::class, Credentials::resolve(null, null, null, null, self::BASE, $hooks));
        $this->expectException(ConfigurationError::class);
        Credentials::resolve('osk_a', static fn(): string => 't', null, null, self::BASE, $hooks);
    }

    public function testProvider(): void
    {
        $values = ['t1', ''];
        $provider = new ProviderCredential(static function () use (&$values): mixed {
            return array_shift($values) ?? throw new \RuntimeException('empty');
        });
        self::assertSame('t1', $provider->token());
        self::assertFalse($provider->refreshable());
        $provider->invalidate();
        foreach (['did not return a token', 'failed: empty'] as $message) {
            try {
                $provider->token();
                self::fail('expected AuthenticationError');
            } catch (AuthenticationError $error) {
                self::assertStringContainsString($message, $error->getMessage());
                self::assertNull($error->status);
            }
        }
        $missing = new MissingCredential();
        self::assertFalse($missing->refreshable());
        $missing->invalidate();
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testCliTokenIsCachedUntilItExpires(): void
    {
        $fake = $this->fake();
        $fake->token(['token' => 'tok_1', 'expires_at' => '2026-09-21T14:00:00Z', 'kind' => 'oauth', 'base_url' => self::BASE . '/', 'profile' => 'work']);
        $this->now = (float) (new \DateTimeImmutable('2026-09-21T13:00:00Z'))->format('U');
        $login = $this->login('work');
        self::assertSame(['tok_1', 'tok_1'], [$login->token(), $login->token()]);
        self::assertSame(['--version', 'auth token --json --base-url ' . self::BASE . ' --profile work'], $fake->calls());
        $this->now += 3541.0;
        $fake->token(['token' => 'tok_2', 'expires_at' => null, 'base_url' => self::BASE]);
        $tokens = [$login->token(), $login->token()];
        $login->invalidate();
        $tokens[] = $login->token();
        self::assertSame(['tok_2', 'tok_2', 'tok_2'], $tokens);
        self::assertCount(4, $fake->calls(), 'the version is checked once per process');
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testCliFailures(): void
    {
        $fake = $this->fake();
        $cases = [
            'the oscar CLI answered for https://other.example.test' => static fn() => $fake->token(['token' => 't', 'base_url' => 'https://other.example.test']),
            'answered for not a url' => static fn() => $fake->token(['token' => 't', 'base_url' => 'not a url']),
            'exit code 3: not logged in. Run `oscar login` first.' => static fn() => $fake->answer(3, '', "not logged in\nmore"),
            'exit code 1: no message.' => static fn() => $fake->answer(1, ''),
            'cannot read' => static fn() => $fake->answer(0, 'not json'),
        ];
        foreach ($cases as $message => $prepare) {
            $prepare();
            try {
                $this->login()->token();
                self::fail('expected AuthenticationError: ' . $message);
            } catch (AuthenticationError $error) {
                self::assertStringContainsStringIgnoringCase($message, $error->getMessage());
                self::assertStringNotContainsString('"token"', $error->getMessage());
            }
        }
        CliLogin::forgetChecks();
        $fake->version('something else 1.0');
        try {
            $this->login()->token();
            self::fail('expected AuthenticationError');
        } catch (AuthenticationError $error) {
            self::assertStringContainsString('is not the oscar CLI', $error->getMessage());
        }
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testCliOutputComesThroughSockets(): void
    {
        $fake = $this->fake();
        $fake->token(['token' => 'tok_1', 'base_url' => self::BASE]);
        $directory = $fake->directory;
        $login = new CliLogin(self::BASE, null, fn(): float => $this->now, static fn(): string => $directory, 0.5);
        self::assertSame('tok_1', $login->token());
        self::assertSame(['--version', 'auth token --json --base-url ' . self::BASE], $fake->calls());
        // A socket, not a pipe and not a file: stream_select() works on it also on Windows, and nothing is on disk.
        self::assertSame(['socket', 'socket'], $fake->stdoutKinds());
        $fake->answer(3, '', "not logged in\n");
        $login->invalidate();
        try {
            $login->token();
            self::fail('expected AuthenticationError');
        } catch (AuthenticationError $error) {
            self::assertStringContainsString('exit code 3: not logged in.', $error->getMessage());
        }
        $fake->token(['token' => 'tok_2', 'base_url' => self::BASE]);
        $fake->sleep(2);
        $started = microtime(true);
        try {
            $login->token();
            self::fail('expected AuthenticationError');
        } catch (AuthenticationError $error) {
            self::assertStringContainsString('did not answer within 0.5 seconds', $error->getMessage());
        }
        self::assertLessThan(1.5, microtime(true) - $started);
    }

    public function testParse(): void
    {
        self::assertSame(['token' => 't', 'expires' => null, 'base_url' => 'u'], CliLogin::parse('{"token":"t","base_url":"u","extra":1}'));
        self::assertSame(1_790_000_000.0, CliLogin::parse('{"token":"t","base_url":"u","expires_at":"2026-09-21T14:13:20Z"}')['expires']);
        foreach (['[]', '"x"', '{"token":"","base_url":"u"}', '{"token":"t"}', '{"token":"t","base_url":"u","expires_at":5}', '{"token":"t","base_url":"u","expires_at":"never"}'] as $bad) {
            try {
                CliLogin::parse($bad);
                self::fail('expected AuthenticationError for ' . $bad);
            } catch (AuthenticationError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNotOnPath(): void
    {
        $login = new CliLogin(self::BASE, null, static fn(): float => 0.0, static fn(): string => 'relative/dir:/nonexistent-oscar');
        $this->expectException(AuthenticationError::class);
        $this->expectExceptionMessage('The oscar CLI is not installed');
        $login->token();
    }

    public function testFind(): void
    {
        $directory = $this->fake()->directory;
        self::assertSame($directory . '/oscar', CliLogin::find('relative:' . $directory, false));
        self::assertNull(CliLogin::find('', false));
        self::assertNull(CliLogin::find(null, false));
        $windows = sys_get_temp_dir() . '/oscar-php-win-' . bin2hex(random_bytes(4));
        mkdir($windows);
        touch($windows . '/oscar.cmd');
        try {
            $found = CliLogin::find('C:\\nope;' . $windows, true, '.COM;.EXE;.CMD');
            self::assertNull($found, 'a POSIX path is not absolute on Windows');
            self::assertNull(CliLogin::find('\\\\server\\share', true));
        } finally {
            unlink($windows . '/oscar.cmd');
            rmdir($windows);
        }
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testOscarDropsTheCliTokenAfterA401(): void
    {
        $fake = $this->fake();
        $fake->token(['token' => 'tok_old', 'expires_at' => null, 'base_url' => self::BASE]);
        $this->usePath($fake->directory);
        $transport = new ScriptedTransport([new Response(204, [], ''), new Response(401, [], '{"code":"authentication_failed"}'), new Response(204, [], '')]);
        $oscar = new Oscar(credential: 'cli', baseUrl: self::BASE, transport: $transport);
        $request = new \OscarAccountant\Generated\Runtime\Request('x', 'DELETE', '/api/x/', [], [], null, false, 'none');
        $oscar->requestNoContent($request, null);
        $fake->token(['token' => 'tok_new', 'expires_at' => null, 'base_url' => self::BASE]);
        $oscar->requestNoContent($request, null);
        self::assertSame('Bearer tok_old', $transport->requests[1]->header('authorization'));
        self::assertSame('Bearer tok_new', $transport->requests[2]->header('authorization'));
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testUseCliLoginVariableAndProfile(): void
    {
        $fake = $this->fake();
        $fake->token(['token' => 'tok', 'base_url' => self::BASE]);
        $this->usePath($fake->directory);
        putenv('OSCAR_USE_CLI_LOGIN=1');
        putenv('OSCAR_PROFILE=team');
        $transport = new ScriptedTransport([new Response(204, [], '')]);
        $oscar = new Oscar(baseUrl: self::BASE, transport: $transport);
        $oscar->requestNoContent(new \OscarAccountant\Generated\Runtime\Request('x', 'DELETE', '/api/x/', [], [], null, false, 'none'), null);
        self::assertStringEndsWith('--profile team', $fake->calls()[1]);
    }
}
