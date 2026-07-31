<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Data Transfer Object for application approval.
 */
class ApplicationApproveDto
{
    #[Assert\NotBlank(message: "Les notes du réviseur sont obligatoires")]
    #[Assert\Length(max: 1000, maxMessage: "Les notes du réviseur ne peuvent pas dépasser {{ limit }} caractères")]
    public ?string $reviewerNotes = null;

    // Additional fields for agency customization during approval
    #[Assert\Type(type: 'string', message: "Le nom de l'agence doit être une chaîne de caractères")]
    #[Assert\Length(max: 100, maxMessage: "Le nom de l'agence ne peut pas dépasser {{ limit }} caractères")]
    public ?string $agencyNameOverride = null;

    #[Assert\Type(type: 'string', message: "Le nom du représentant légal doit être une chaîne de caractères")]
    #[Assert\Length(max: 100, maxMessage: "Le nom du représentant légal ne peut pas dépasser {{ limit }} caractères")]
    public ?string $legalRepresentativeOverride = null;

    // Temporary credentials for the new admin user
    #[Assert\Type(type: 'string', message: "Le mot de passe doit être une chaîne de caractères")]
    #[Assert\Length(min: 8, max: 255, 
        minMessage: "Le mot de passe doit contenir au moins {{ limit }} caractères",
        maxMessage: "Le mot de passe ne peut pas dépasser {{ limit }} caractères")]
    public ?string $temporaryPassword = null;
}
