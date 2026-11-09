<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Variables que los tests pueden modificar (proveedor, claves, presupuesto).
     * Se restauran tras cada test para que el resultado no dependa del orden de ejecución.
     */
    private const ISOLATED_ENV = [
        'AI_PROVIDER',
        'AI_MONTHLY_BUDGET_USD',
        'GEMINI_API_KEY',
        'GEMINI_MODEL',
        'ANTHROPIC_API_KEY',
        'ANTHROPIC_MODEL',
    ];

    /** @var array<string, array{env: mixed, server: mixed, putenv: string|false}> */
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ISOLATED_ENV as $key) {
            $this->envSnapshot[$key] = [
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
                'putenv' => getenv($key),
            ];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envSnapshot as $key => $snap) {
            $snap['env'] === null ? $this->unsetGlobal($_ENV, $key) : $_ENV[$key] = $snap['env'];
            $snap['server'] === null ? $this->unsetGlobal($_SERVER, $key) : $_SERVER[$key] = $snap['server'];
            $snap['putenv'] === false ? putenv($key) : putenv("{$key}={$snap['putenv']}");
        }

        parent::tearDown();
    }

    /** @param array<string, mixed> $bag */
    private function unsetGlobal(array &$bag, string $key): void
    {
        unset($bag[$key]);
    }
}
