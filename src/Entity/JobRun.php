<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\JobRunRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JobRunRepository::class)]
#[ORM\Table(name: 'job_run')]
#[ORM\Index(columns: ['command_name', 'started_at'], name: 'idx_job_run_command_started')]
#[ORM\Index(columns: ['status'], name: 'idx_job_run_status')]
#[ApiResource(
    operations: [
        new \ApiPlatform\Metadata\GetCollection(),
        new \ApiPlatform\Metadata\Get(),
    ]
)]
class JobRun
{
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_OK = 'OK';
    public const STATUS_WARNING = 'WARNING';
    public const STATUS_ERROR = 'ERROR';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'command_name', length: 191)]
    private string $commandName;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_RUNNING;

    #[ORM\Column(name: 'started_at')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'duration_ms', type: 'integer', nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(name: 'exit_code', type: 'smallint', nullable: true)]
    private ?int $exitCode = null;

    /** @var array<string, mixed>|null Compteurs métier (ex: checkedWallets, issueCount, freed, count...) */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $summary = null;

    /** @var array<int, array<string, mixed>>|null Détail des anomalies remontées par la commande */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $issues = null;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'error_trace', type: 'text', nullable: true)]
    private ?string $errorTrace = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $host = null;

    /** cron | manuel */
    #[ORM\Column(name: 'triggered_by', length: 20, nullable: true)]
    private ?string $triggeredBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommandName(): string
    {
        return $this->commandName;
    }

    public function setCommandName(string $commandName): static
    {
        $this->commandName = $commandName;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;
        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): static
    {
        $this->durationMs = $durationMs;
        return $this;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function setExitCode(?int $exitCode): static
    {
        $this->exitCode = $exitCode;
        return $this;
    }

    public function getSummary(): ?array
    {
        return $this->summary;
    }

    public function setSummary(?array $summary): static
    {
        $this->summary = $summary;
        return $this;
    }

    public function getIssues(): ?array
    {
        return $this->issues;
    }

    public function setIssues(?array $issues): static
    {
        $this->issues = $issues;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getErrorTrace(): ?string
    {
        return $this->errorTrace;
    }

    public function setErrorTrace(?string $errorTrace): static
    {
        $this->errorTrace = $errorTrace;
        return $this;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function setHost(?string $host): static
    {
        $this->host = $host;
        return $this;
    }

    public function getTriggeredBy(): ?string
    {
        return $this->triggeredBy;
    }

    public function setTriggeredBy(?string $triggeredBy): static
    {
        $this->triggeredBy = $triggeredBy;
        return $this;
    }

    /**
     * Utilisé par la future API admin pour sérialiser sans dépendre d'un normalizer particulier.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'commandName' => $this->commandName,
            'status' => $this->status,
            'startedAt' => $this->startedAt->format('c'),
            'finishedAt' => $this->finishedAt?->format('c'),
            'durationMs' => $this->durationMs,
            'exitCode' => $this->exitCode,
            'summary' => $this->summary,
            'issues' => $this->issues,
            'errorMessage' => $this->errorMessage,
            'host' => $this->host,
            'triggeredBy' => $this->triggeredBy,
        ];
    }
}
