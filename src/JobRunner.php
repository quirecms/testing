<?php

declare(strict_types=1);

namespace Quire\Testing;

use Quire\Jobs\Job;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

/**
 * Runs the jobs waiting in Quire's queue, as a worker would, in tests. A job that fails is rejected and
 * its exception thrown, so tests see it.
 *
 * @api
 */
final class JobRunner
{
    /**
     * @return int How many jobs ran
     */
    public static function run(ContainerInterface $container, int $limit = 100): int
    {
        // Tests use the test container, which has private services too.
        $transport = $container->get('messenger.transport.'.Job::TRANSPORT); // @phpstan-ignore symfonyContainer.privateService
        $bus = $container->get(MessageBusInterface::class); // @phpstan-ignore symfonyContainer.privateService
        // As a worker tells listeners what it does, e.g. the audit log, which records "in a job".
        $events = $container->get(EventDispatcherInterface::class); // @phpstan-ignore symfonyContainer.privateService

        if ($transport instanceof SyncTransport) {
            throw new \LogicException(\sprintf('Jobs run as soon as they\'re dispatched (the transport "%s" isn\'t a queue), so none wait.', Job::TRANSPORT));
        }
        $ran = 0;

        while ($ran < $limit) {
            $envelopes = [...$transport->get()];

            if ([] === $envelopes) {
                break;
            }

            foreach ($envelopes as $envelope) {
                $events->dispatch(new WorkerMessageReceivedEvent($envelope, Job::TRANSPORT));

                try {
                    $bus->dispatch($envelope->with(new ReceivedStamp(Job::TRANSPORT), new ConsumedByWorkerStamp()));
                } catch (\Throwable $e) {
                    $transport->reject($envelope);
                    $events->dispatch(new WorkerMessageFailedEvent($envelope, Job::TRANSPORT, $e));

                    throw $e;
                }

                $events->dispatch(new WorkerMessageHandledEvent($envelope, Job::TRANSPORT));

                $transport->ack($envelope);
                ++$ran;
            }
        }

        return $ran;
    }
}
