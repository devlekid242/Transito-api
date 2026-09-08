<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\JobReportRecorder;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Trace automatiquement toute exécution des commandes préfixées "transito:" dans job_run,
 * sans nécessiter de modification des commandes elles-mêmes.
 *
 * Les commandes qui veulent enrichir leur rapport (compteurs, anomalies) appellent en plus
 * JobReportRecorder::finish() explicitement — voir sa docblock pour le cycle de vie complet.
 */
final class JobRunTrackingSubscriber implements EventSubscriberInterface
{
    private const TRACKED_PREFIX = 'transito:';

    public function __construct(private readonly JobReportRecorder $recorder)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onCommand',
            ConsoleEvents::ERROR => 'onError',
            ConsoleEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName();
        if ($name === null || !str_starts_with($name, self::TRACKED_PREFIX)) {
            return;
        }

        // Heuristique simple : un cron tourne sans TTY (non-interactif), une exécution manuelle
        // en terminal est interactive par défaut. À ajuster si besoin selon votre configuration.
        $triggeredBy = $event->getInput()->isInteractive() ? 'manuel' : 'cron';

        $this->recorder->openRun($name, $triggeredBy);
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        $name = $event->getCommand()?->getName();
        if ($name === null || !str_starts_with($name, self::TRACKED_PREFIX)) {
            return;
        }

        $this->recorder->recordException($event->getError());
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $name = $event->getCommand()?->getName();
        if ($name === null || !str_starts_with($name, self::TRACKED_PREFIX)) {
            return;
        }

        $this->recorder->finalizeWithExitCode($event->getExitCode());
    }
}
