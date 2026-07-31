<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Data Transfer Object for application rejection.
 */
class ApplicationRejectDto
{
    #[Assert\NotBlank(message: "La raison du rejet est obligatoire")]
    #[Assert\Length(max: 1000, maxMessage: "La raison du rejet ne peut pas dépasser {{ limit }} caractères")]
    public string $rejectionReason;

    #[Assert\Length(max: 1000, maxMessage: "Les notes du réviseur ne peuvent pas dépasser {{ limit }} caractères")]
    public ?string $reviewerNotes = null;
}
