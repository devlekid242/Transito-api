<?php

namespace App\Service\MobileMoney;

/**
 * Les WAF placés devant les API MTN/Airtel (F5 BIG-IP ASM notamment)
 * rejettent parfois des requêtes contenant des caractères accentués,
 * apostrophes typographiques, ou séquences ressemblant à de l'injection
 * SQL/XSS dans les champs texte libres (payerMessage, payeeNote). Ce n'est
 * pas documenté officiellement par MTN/Airtel, mais c'est un comportement
 * observé en pratique sur leur infrastructure sandbox.
 *
 * On assainit donc systématiquement tout texte libre envoyé dans le corps
 * d'une requête mobile money : translittération ASCII, whitelist stricte
 * de caractères, troncature à une longueur sûre.
 */
final class MobileMoneyTextSanitizer
{
    /**
     * @param int $maxLength MTN limite payerMessage/payeeNote à 160 caractères ;
     *                        on reste prudent avec une limite par défaut plus basse.
     */
    public static function sanitize(string $text, int $maxLength = 100): string
    {
        // 1) Translittération : "Réservation N'Guessan" -> "Reservation N'Guessan" (accents retirés, base latine conservée)
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($transliterated === false) {
            $transliterated = $text; // fallback si iconv échoue sur un environnement particulier
        }

        // 2) Whitelist stricte : lettres, chiffres, espace, et une poignée de
        // signes de ponctuation courants et inoffensifs. Tout le reste (apostrophes
        // typographiques ’, guillemets «», #, %, &, etc.) est retiré plutôt que
        // remplacé, pour ne jamais réintroduire un caractère à risque.
        $cleaned = preg_replace('/[^A-Za-z0-9 .,\-]/', '', $transliterated) ?? '';

        // 3) Espaces multiples -> un seul, on trim, puis on tronque à maxLength.
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned) ?? '');

        if ($cleaned === '') {
            $cleaned = 'Transito';
        }

        return mb_substr($cleaned, 0, $maxLength);
    }
}
