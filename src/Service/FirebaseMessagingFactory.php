<?php

namespace App\Service;

use Kreait\Firebase\Contract\Messaging as MessagingContract;
use Kreait\Firebase\Factory;

/**
 * Fabrique le client Firebase Messaging à partir du fichier de compte de
 * service (JSON) téléchargé depuis la console Firebase.
 *
 * `Factory::withServiceAccount()` n'étant pas statique, on ne peut pas la
 * référencer directement comme factory Symfony ; cette classe fait le pont.
 */
class FirebaseMessagingFactory
{
    public static function create(string $credentialsPath): MessagingContract
    {
        return (new Factory())
            ->withServiceAccount($credentialsPath)
            ->createMessaging();
    }
}