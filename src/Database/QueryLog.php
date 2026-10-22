<?php

declare(strict_types=1);

namespace Quire\Testing\Database;

/**
 * The SQL statements sent to the database, recorded by {@see QueryLogMiddleware} in tests.
 *
 * Use it through {@see \Quire\Testing\ExtensionTestCase::assertQueryCount()}.
 *
 * @api
 */
final class QueryLog
{
    /** @var list<string> */
    private array $queries = [];

    private bool $recording = false;

    /**
     * Starts recording, forgetting what was recorded before.
     */
    public function start(): void
    {
        $this->queries = [];
        $this->recording = true;
    }

    /**
     * Stops recording and returns the recorded statements.
     *
     * @return list<string>
     */
    public function stop(): array
    {
        $this->recording = false;

        return $this->queries;
    }

    /**
     * @internal Called by {@see QueryLogMiddleware}
     */
    public function record(string $sql): void
    {
        if ($this->recording) {
            $this->queries[] = $sql;
        }
    }
}
