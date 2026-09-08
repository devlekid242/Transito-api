<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\JobRun;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Point d'entrée unique pour tracer l'exécution des commandes transito:*.
 *
 * Cycle de vie normal :
 *  1. JobRunTrackingSubscriber::onCommand() appelle openRun() AVANT que la commande ne s'exécute
 *     -> une ligne JobRun(status=RUNNING) est créée et flushée immédiatement, donc visible même
 *        si la commande crash violemment (segfault, kill -9, timeout d'hébergeur...).
 *  2. La commande peut (optionnellement) appeler finish() avec un statut et des données métier
 *     (compteurs, anomalies) pour enrichir le rapport.
 *  3. JobRunTrackingSubscriber::onTerminate() appelle toujours finalizeWithExitCode() en dernier
 *     recours : si finish() n'a pas été appelé (commande non encore migrée, ou crash), le run est
 *     clos automatiquement avec un statut déduit du code de sortie. Dans tous les cas, le code de
 *     sortie réel est enregistré.
 *
 * Un seul run "actif" à la fois : suffisant car chaque exécution de commande (y compris chaque
 * itération d'une boucle --watch) tourne dans son propre process PHP.
 */
final class JobReportRecorder
{
    private ?JobRun $lastRun = null;
    private bool $closed = true;
    private ?float $startedAtMicrotime = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function openRun(string $commandName, ?string $triggeredBy = null): JobRun
    {
        $run = new JobRun();
        $run->setCommandName($commandName);
        $run->setStatus(JobRun::STATUS_RUNNING);
        $run->setStartedAt(new \DateTimeImmutable());
        $run->setHost(gethostname() ?: null);
        $run->setTriggeredBy($triggeredBy);

        $this->em->persist($run);
        $this->em->flush();

        $this->lastRun = $run;
        $this->closed = false;
        $this->startedAtMicrotime = microtime(true);

        return $run;
    }

    public function getCurrentRun(): ?JobRun
    {
        return $this->closed ? null : $this->lastRun;
    }

    public function isOpen(): bool
    {
        return !$this->closed && $this->lastRun !== null;
    }

    /**
     * À appeler depuis le subscriber lors de ConsoleEvents::ERROR pour attacher l'exception
     * au run en cours avant qu'il ne soit clos par finalizeWithExitCode().
     */
    public function recordException(\Throwable $exception): void
    {
        if ($this->lastRun === null) {
            return;
        }

        $this->lastRun->setErrorMessage($exception->getMessage());
        $this->lastRun->setErrorTrace(mb_substr($exception->getTraceAsString(), 0, 5000));
    }

    /**
     * À appeler explicitement depuis une commande pour clore son run avec un rapport détaillé.
     *
     * @param array<string, mixed> $summary Compteurs métier (ex: ['checkedWallets' => 12, 'inconsistentWallets' => 1])
     * @param array<int, array<string, mixed>> $issues Détail des anomalies, le cas échéant
     */
    public function finish(string $status, array $summary = [], array $issues = []): void
    {
        if ($this->lastRun === null || $this->closed) {
            return;
        }

        $run = $this->lastRun;
        $run->setStatus($status);
        $run->setFinishedAt(new \DateTimeImmutable());
        $run->setDurationMs($this->elapsedMs());

        if ($summary !== []) {
            $run->setSummary($summary);
        }
        if ($issues !== []) {
            $run->setIssues($issues);
        }

        $this->em->persist($run);
        $this->em->flush();

        $this->closed = true;
    }

    /**
     * Filet de sécurité appelé systématiquement à ConsoleEvents::TERMINATE.
     * Clôt le run s'il ne l'a pas déjà été par finish(), et enregistre toujours le code de sortie réel.
     */
    public function finalizeWithExitCode(int $exitCode): void
    {
        if ($this->lastRun === null) {
            return;
        }

        $run = $this->lastRun;

        if (!$this->closed) {
            $run->setStatus($exitCode === 0 ? JobRun::STATUS_OK : JobRun::STATUS_ERROR);
            $run->setFinishedAt(new \DateTimeImmutable());
            $run->setDurationMs($this->elapsedMs());
            $this->closed = true;
        }

        $run->setExitCode($exitCode);

        $this->em->persist($run);
        $this->em->flush();
    }

    private function elapsedMs(): ?int
    {
        return $this->startedAtMicrotime !== null
            ? (int) round((microtime(true) - $this->startedAtMicrotime) * 1000)
            : null;
    }
}
