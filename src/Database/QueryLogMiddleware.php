<?php

declare(strict_types=1);

namespace Quire\Testing\Database;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Records every statement sent to the database in the {@see QueryLog}.
 *
 * @internal Registered by {@see \Quire\Testing\TestKernel}; register it in your own test environment with
 *           autoconfiguration, next to {@see QueryLog}
 */
final readonly class QueryLogMiddleware implements Middleware
{
    public function __construct(
        private QueryLog $log,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        $log = $this->log;

        return new class($driver, $log) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly QueryLog $log)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): Connection
            {
                $log = $this->log;

                return new class(parent::connect($params), $log) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly QueryLog $log)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $log = $this->log;

                        return new class(parent::prepare($sql), $log, $sql) extends AbstractStatementMiddleware {
                            public function __construct(Statement $statement, private readonly QueryLog $log, private readonly string $sql)
                            {
                                parent::__construct($statement);
                            }

                            public function execute(): Result
                            {
                                $this->log->record($this->sql);

                                return parent::execute();
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        $this->log->record($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->log->record($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
