<?php

declare(strict_types=1);

namespace OscarAccountant\Tests\Conformance;

use OscarAccountant\Download;
use OscarAccountant\Errors\ApiError;
use OscarAccountant\Errors\OscarError;
use OscarAccountant\Errors\ResponseValidationError;
use OscarAccountant\Generated\Runtime\JsonModel;
use OscarAccountant\Generated\Runtime\Request;
use OscarAccountant\Internal\Hooks;
use OscarAccountant\Oscar;
use OscarAccountant\Paginator;
use OscarAccountant\RequestOptions;
use OscarAccountant\Upload;

/**
 * The runner of the conformance cases of the source repository, with a fake transport as the HTTP mock.
 *
 * The format and the matching rules are normative in the README of the conformance cases. A case is one call
 * of the client, the HTTP exchanges the call must make, and the result or the error it must give.
 */
final class Runner
{
    /**
     * The name of this runner in the `skip` field of a case.
     */
    public const RUNNER = 'php';

    public const DEFAULT_NOW = '2026-09-27T10:00:00Z';

    public const SLEEP_TOLERANCE = 1e-6;

    /**
     * The directory of the cases, or null outside the source repository.
     */
    public static function casesDir(): ?string
    {
        $directory = \dirname(__DIR__, 3) . '/spec/conformance/cases';

        return is_dir($directory) ? $directory : null;
    }

    /**
     * Every case of every file, in the order of the file names and then of each file.
     *
     * @return list<array<string, mixed>>
     */
    public static function loadCases(): array
    {
        $directory = self::casesDir();
        if ($directory === null) {
            return [];
        }
        $files = glob($directory . '/*.json');
        $files = \is_array($files) ? $files : [];
        sort($files);
        $cases = [];
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            foreach (Value::list(Value::map($data)['cases'] ?? null) as $case) {
                $cases[] = Value::map($case);
            }
        }

        return $cases;
    }

    /**
     * Play one case. Raises an `\AssertionError` (or `CaseMismatch`) when the client breaks it.
     *
     * @param array<string, mixed> $case
     */
    public static function play(array $case): void
    {
        $saved = self::setEnvironment(Value::map($case['env'] ?? []));
        try {
            $injected = new Injected(Value::map($case['inject'] ?? []));
            $player = new Player($case);
            $outcome = new Outcome();
            try {
                $client = self::client($case, $injected, $player);
                [$outcome->kind, $outcome->value] = self::run($client, Value::map($case['call']));
            } catch (CaseMismatch $error) {
                $outcome->error = $error;
            } catch (OscarError $error) {
                $outcome->error = $error;
            }
            self::checkOutcome($case, $player, $injected, $outcome);
        } finally {
            self::restoreEnvironment($saved);
        }
    }

    /**
     * The client of a case. An absent option is not passed: it keeps its default.
     *
     * @param array<string, mixed> $case
     */
    private static function client(array $case, Injected $injected, Player $player): Oscar
    {
        $client = Value::map($case['client'] ?? []);
        $tokenProvider = null;
        if (\array_key_exists('token_provider', $client)) {
            $remaining = array_map(Value::string(...), Value::list($client['token_provider']));
            $tokenProvider = static function () use (&$remaining): string {
                if ($remaining === []) {
                    throw new \RuntimeException('the token provider has no token left');
                }

                return array_shift($remaining);
            };
        }

        return new Oscar(
            apiKey: \array_key_exists('api_key', $client) ? Value::string($client['api_key']) : null,
            tokenProvider: $tokenProvider,
            baseUrl: \array_key_exists('base_url', $client) ? Value::string($client['base_url']) : null,
            timeout: \array_key_exists('timeout_ms', $client) ? Value::int($client['timeout_ms']) / 1000 : Oscar::DEFAULT_TIMEOUT,
            maxRetries: \array_key_exists('max_retries', $client) ? Value::int($client['max_retries']) : Oscar::DEFAULT_MAX_RETRIES,
            transport: $player,
            hooks: new Hooks(
                clock: $injected->clock(...),
                sleep: $injected->sleep(...),
                jitter: $injected->jitter(...),
                uuid: $injected->uuid(...),
            ),
        );
    }

    // -----------------------------------------------------------------------
    // The call
    // -----------------------------------------------------------------------

    /**
     * Run the call. The result as (kind, wire JSON).
     *
     * @param array<string, mixed> $call
     *
     * @return array{string, mixed}
     */
    public static function run(Oscar $client, array $call): array
    {
        if (\array_key_exists('spec', $call)) {
            return self::runSpec($client, $call);
        }
        [$resource, $method] = explode('.', Value::string($call['operation']));
        $property = self::camel($resource);
        $target = property_exists($client, $property) ? (new \ReflectionProperty($client, $property))->getValue($client) : null;
        if (!\is_object($target)) {
            throw new CaseMismatch(\sprintf('The client has no resource %s.', $resource));
        }
        $function = new \ReflectionMethod($target, self::camel($method));
        $parameters = [];
        foreach ($function->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }
        $arguments = [];
        foreach (Value::map($call['path'] ?? []) as $name => $value) {
            $arguments[self::argumentName($name, $parameters)] = $value;
        }
        foreach (Value::map($call['query'] ?? []) as $name => $value) {
            $arguments[self::argumentName($name, $parameters)] = $value;
        }
        if (\array_key_exists('body', $call)) {
            $arguments['body'] = self::bodyModel($parameters)::fromArray(Value::map($call['body']));
        }
        if (\array_key_exists('multipart', $call)) {
            $multipart = Value::map($call['multipart']);
            foreach (Value::map($multipart['fields'] ?? []) as $name => $value) {
                $arguments[self::argumentName($name, $parameters)] = $value;
            }
            $file = Value::map($multipart['file']);
            $arguments[self::argumentName(Value::string($file['field']), $parameters)] = Upload::fromString(
                (string) base64_decode(Value::string($file['content_base64']), true),
                isset($file['filename']) ? Value::string($file['filename']) : null,
                isset($file['content_type']) ? Value::string($file['content_type']) : null,
            );
        }
        if (\array_key_exists('options', $call)) {
            $arguments['options'] = self::callOptions(Value::map($call['options']));
        }
        $value = $function->invokeArgs($target, $arguments);
        if ($value instanceof Paginator) {
            if (($call['mode'] ?? 'page') === 'all') {
                $items = [];
                foreach ($value as $item) {
                    $items[] = self::wire($item);
                }

                return ['items', $items];
            }

            return ['json', self::wire($value->firstPage())];
        }
        if ($value instanceof Download) {
            return ['binary', self::binary($value)];
        }
        if ($value === null) {
            return ['none', null];
        }

        return ['json', self::wire($value)];
    }

    /**
     * A request spec handed to the runtime directly.
     *
     * @param array<string, mixed> $call
     *
     * @return array{string, mixed}
     */
    private static function runSpec(Oscar $client, array $call): array
    {
        $spec = Value::map($call['spec']);
        $method = Value::string($spec['method']);
        $response = Value::string($spec['response']);
        $query = [];
        foreach (Value::map($spec['query']) as $name => $value) {
            $query[$name] = \is_scalar($value) || $value === null ? $value : throw new CaseMismatch('A query value is not a scalar.');
        }
        $request = new Request(
            operationId: Value::string($spec['operation_id']),
            method: self::httpMethod($method),
            path: Value::string($spec['path']),
            pathParams: array_map(Value::string(...), Value::map($spec['path_params'])),
            query: $query,
            body: \array_key_exists('body', $spec) ? Value::map($spec['body']) : null,
            idempotent: $spec['idempotent'] === true,
            response: $response === 'binary' ? 'binary' : ($response === 'none' ? 'none' : 'json'),
        );
        $options = \array_key_exists('options', $call) ? self::callOptions(Value::map($call['options'])) : null;
        if ($response === 'json') {
            return ['json', self::wire($client->request($request, AnyModel::class, $options))];
        }
        if ($response === 'binary') {
            return ['binary', self::binary($client->download($request, $options))];
        }
        $client->requestNoContent($request, $options);

        return ['none', null];
    }

    /**
     * @return 'GET'|'POST'|'PUT'|'PATCH'|'DELETE'
     */
    private static function httpMethod(string $method): string
    {
        return match ($method) {
            'GET', 'POST', 'PUT', 'PATCH', 'DELETE' => $method,
            default => throw new CaseMismatch(\sprintf('The method %s is not an HTTP method of the API.', $method)),
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function callOptions(array $options): RequestOptions
    {
        return new RequestOptions(
            idempotencyKey: isset($options['idempotency_key']) ? Value::string($options['idempotency_key']) : null,
            timeout: isset($options['timeout_ms']) ? Value::int($options['timeout_ms']) / 1000 : null,
            maxRetries: isset($options['max_retries']) ? Value::int($options['max_retries']) : null,
            headers: array_map(Value::string(...), Value::map($options['headers'] ?? [])),
        );
    }

    /**
     * The PHP name of a wire name: camel case.
     *
     * @param array<string, \ReflectionParameter> $parameters
     */
    private static function argumentName(string $wire, array $parameters): string
    {
        $name = self::camel($wire);
        if (!isset($parameters[$name])) {
            throw new CaseMismatch(\sprintf('The method has no parameter for %s.', $wire));
        }

        return $name;
    }

    /**
     * The model class of the `$body` parameter.
     *
     * @param array<string, \ReflectionParameter> $parameters
     *
     * @return class-string<JsonModel>
     */
    private static function bodyModel(array $parameters): string
    {
        $type = isset($parameters['body']) ? $parameters['body']->getType() : null;
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
        if (!is_subclass_of($name, JsonModel::class)) {
            throw new CaseMismatch('The method has no model body.');
        }

        return $name;
    }

    /**
     * `bank_transactions` to `bankTransactions`, as the generator names it.
     */
    public static function camel(string $text): string
    {
        $spaced = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $text);
        preg_match_all('/[A-Za-z0-9]+/', $spaced, $match);
        $words = array_map(strtolower(...), $match[0]);
        $name = implode('', array_map(ucfirst(...), $words));

        return lcfirst($name);
    }

    /**
     * A model turned back into wire JSON.
     */
    private static function wire(mixed $value): mixed
    {
        if (!$value instanceof JsonModel) {
            throw new CaseMismatch('The call did not give a model.');
        }

        return self::plain($value->toArray());
    }

    /**
     * A value with every `\stdClass` turned into an array, as the case JSON is read: a field of any JSON value
     * (`mixed`) keeps a JSON object of a response as a `\stdClass`.
     */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (\is_array($value)) {
            return array_map(self::plain(...), $value);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function binary(Download $download): array
    {
        $content = $download->contents();

        return [
            'sha256' => hash('sha256', $content),
            'size' => \strlen($content),
            'content_type' => $download->contentType,
            'filename' => $download->filename,
        ];
    }

    // -----------------------------------------------------------------------
    // The outcome
    // -----------------------------------------------------------------------

    /**
     * Every rule of "The expected outcome", after the call.
     *
     * @param array<string, mixed> $case
     */
    private static function checkOutcome(array $case, Player $player, Injected $injected, Outcome $outcome): void
    {
        if ($player->failures !== []) {
            throw new \AssertionError($player->failures[0]);
        }
        if ($outcome->error instanceof CaseMismatch) {
            throw $outcome->error;
        }
        $expect = Value::map($case['expect']);
        if (\array_key_exists('error', $expect)) {
            $wanted = Value::map($expect['error']);
            if ($outcome->error === null) {
                throw new \AssertionError(\sprintf('expected %s, got a %s result', Value::string($wanted['type']), $outcome->kind));
            }
            self::checkError($wanted, $outcome->error);
        } else {
            if ($outcome->error !== null) {
                throw new \AssertionError('expected a result, got ' . $outcome->error::class . ': ' . $outcome->error->getMessage());
            }
            self::checkResult(Value::map($expect['result']), $outcome->kind, $outcome->value);
        }
        $exchanges = Value::list($case['exchanges']);
        if ($player->used !== \count($exchanges)) {
            throw new \AssertionError(\sprintf('%d of %d exchanges were used', $player->used, \count($exchanges)));
        }
        $wantedSleeps = array_map(Value::number(...), Value::list($case['sleeps'] ?? []));
        $message = \sprintf('sleeps: expected %s, got %s', json_encode($wantedSleeps), json_encode($injected->sleeps));
        if (\count($wantedSleeps) !== \count($injected->sleeps)) {
            throw new \AssertionError($message);
        }
        foreach ($wantedSleeps as $index => $want) {
            if (abs($want - $injected->sleeps[$index]) > self::SLEEP_TOLERANCE) {
                throw new \AssertionError($message);
            }
        }
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function checkError(array $expected, \Throwable $error): void
    {
        $type = Value::string($expected['type']);
        $last = strrchr('\\' . $error::class, '\\');
        $short = $last !== false ? substr($last, 1) : '';
        if (!$error instanceof OscarError || $short !== $type) {
            throw new \AssertionError(\sprintf('expected %s, got %s: %s', $type, $error::class, $error->getMessage()));
        }
        $fields = [
            'status' => $error instanceof ApiError || $error instanceof ResponseValidationError ? $error->status : null,
            'code' => $error instanceof ApiError ? $error->code : null,
            'title' => $error instanceof ApiError ? $error->title : null,
            'detail' => $error instanceof ApiError ? $error->detail : null,
            'errors' => $error instanceof ApiError ? $error->errors : null,
            'request_id' => $error instanceof ApiError || $error instanceof ResponseValidationError ? $error->requestId : null,
        ];
        foreach ($fields as $name => $actual) {
            if (\array_key_exists($name, $expected)) {
                $found = Matcher::mismatch($expected[$name], $actual, 'error.' . $name);
                if ($found !== null) {
                    throw new \AssertionError($found);
                }
            }
        }
        if (\array_key_exists('message', $expected) && $error->getMessage() !== $expected['message']) {
            throw new \AssertionError(\sprintf('error.message: expected %s, got %s', Value::string($expected['message']), $error->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function checkResult(array $expected, string $kind, mixed $value): void
    {
        $wanted = Value::string($expected['kind']);
        if ($kind !== $wanted) {
            throw new \AssertionError(\sprintf('expected a %s result, got %s', $wanted, $kind));
        }
        if ($wanted === 'none') {
            if ($value !== null) {
                throw new \AssertionError('expected no result');
            }

            return;
        }
        if ($wanted === 'binary') {
            $want = [];
            foreach (['sha256', 'size', 'content_type', 'filename'] as $name) {
                $want[$name] = $expected[$name];
            }
            if ($want !== $value) {
                throw new \AssertionError(\sprintf('binary: expected %s, got %s', json_encode($want), json_encode($value)));
            }

            return;
        }
        $found = Matcher::mismatch($expected['value'], $value, 'result', true);
        if ($found !== null) {
            throw new \AssertionError($found);
        }
    }

    // -----------------------------------------------------------------------
    // The environment
    // -----------------------------------------------------------------------

    /**
     * Unset every `OSCAR_*` variable, then set the ones of the case. The saved values, to put back.
     *
     * @param array<string, mixed> $env
     *
     * @return array<string, string>
     */
    private static function setEnvironment(array $env): array
    {
        $saved = [];
        foreach (getenv() as $name => $value) {
            if (str_starts_with($name, 'OSCAR_')) {
                $saved[$name] = $value;
                putenv($name);
            }
        }
        foreach ($env as $name => $value) {
            putenv($name . '=' . Value::string($value));
        }

        return $saved;
    }

    /**
     * @param array<string, string> $saved
     */
    private static function restoreEnvironment(array $saved): void
    {
        foreach (array_keys(getenv()) as $name) {
            if (str_starts_with($name, 'OSCAR_')) {
                putenv($name);
            }
        }
        foreach ($saved as $name => $value) {
            putenv($name . '=' . $value);
        }
    }
}
