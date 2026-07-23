<?php

namespace App\DataFixtures;

use App\Entity\Agency;
use App\Entity\AgencyPoint;
use App\Entity\Agent;
use App\Entity\Bus;
use App\Entity\PaymentLog;
use App\Entity\Reservation;
use App\Entity\Ticket;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Entity\WithdrawalRequest;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class DemoFixtures extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher) {}

    public function load(ObjectManager $manager): void
    {
        // Agency 1
        $agency1 = new Agency();
        $agency1->setName('Transito Congo');
        $agency1->setEmail('contact@transitocongo.test');
        $agency1->setPhone('+242066100100');
        $agency1->setAddress('Boulevard Triomphal, Brazzaville');
        $agency1->setDescription('Agence de voyage historique desservant les principales villes du Congo.');
        $agency1->setBannerUrl('https://example.com/images/transito-banner.jpg');
        $agency1->setLogoUrl('https://example.com/images/transito-logo.png');
        $agency1->setWebsiteUrl('https://transito-congo.test');
        $agency1->setMapUrl('https://maps.example.com/transito-congo');
        $agency1->setRegistrationNumber('TCG-2026');
        $agency1->setPasswordHash($this->passwordHasher->hashPassword(new User(), 'Transito2026!'));
        $agency1->setStatus('active');
        $manager->persist($agency1);

        $wallet1 = new Wallet();
        $wallet1->setAgency($agency1);
        $wallet1->setType(Wallet::TYPE_AGENCY);
        $wallet1->setAvailableBalance('0.00');
        $wallet1->setReservedBalance('0.00');
        $wallet1->setTotalEarned('0.00');
        $wallet1->setTotalWithdrawn('0.00');
        $agency1->setWallet($wallet1);
        $manager->persist($wallet1);

        $point11 = new AgencyPoint();
        $point11->setAgency($agency1);
        $point11->setCity('Brazzaville');
        $point11->setName('Gare Centrale Brazzaville');
        $point11->setAddress('Avenue du Général Leclerc');
        $point11->setQuartier('Plateau des 15 ans');
        $point11->setPhone('+242066100101');
        $point11->setLatitude(-4.267);
        $point11->setLongitude(15.283);
        $point11->setPointType('principal');
        $point11->setHasWifi(1);
        $point11->setHasAc(1);
        $manager->persist($point11);

        $point12 = new AgencyPoint();
        $point12->setAgency($agency1);
        $point12->setCity('Pointe-Noire');
        $point12->setName('Terminal Maritime Pointe-Noire');
        $point12->setAddress('Avenue de la République');
        $point12->setQuartier('Nouveau Quartier');
        $point12->setPhone('+242066100102');
        $point12->setLatitude(-4.783);
        $point12->setLongitude(11.865);
        $point12->setPointType('secondaire');
        $point12->setHasParking(1);
        $manager->persist($point12);

        $bus1 = new Bus();
        $bus1->setAgency($agency1);
        $bus1->setRegistrationNumber('CG-101-TR');
        $bus1->setCapacity(48);
        $bus1->setCategory('VIP');
        $bus1->setBrand('Mercedes');
        $bus1->setModel('Tourismo');
        $bus1->setColor('Blanc');
        $manager->persist($bus1);

        $agency1User = new User();
        $agency1User->setFullName('Admin Transito');
        $agency1User->setEmail('admin@transitocongo.test');
        $agency1User->setPhoneNumber('+242066100200');
        $agency1User->setRoles(['ROLE_PARTNER', 'ROLE_USER']);
        $agency1User->setVilleResidence('Brazzaville');
        $agency1User->setQuartier('Plateau des 15 ans');
        $agency1User->setPassword($this->passwordHasher->hashPassword($agency1User, 'Transito2026!'));
        $agency1User->setStatus('active');
        $manager->persist($agency1User);

        $agent1 = new Agent();
        $agent1->setUser($agency1User);
        $agent1->setAgency($agency1);
        $agent1->setAgentRole('admin_agence');
        $agent1->setStatus('active');
        $manager->persist($agent1);

        $trip1 = new Trip();
        $trip1->setAgency($agency1);
        $trip1->setBus($bus1);
        $trip1->setDepartureCity('Brazzaville');
        $trip1->setArrivalCity('Pointe-Noire');
        $trip1->setBoardingPoints([
            ['city' => 'Brazzaville', 'name' => 'Gare Centrale'],
            ['city' => 'Kintélé', 'name' => 'Station Kintélé']
        ]);
        $trip1->setDeboardingPoints([
            ['city' => 'Pointe-Noire', 'name' => 'Terminal Maritime'],
            ['city' => 'Pointe-Noire', 'name' => 'Zone Industrielle']
        ]);
        $trip1->setDeparturePoint($point11);
        $trip1->setArrivalPoint($point12);
        $trip1->setDepartureTime(new \DateTime('+3 days 08:00'));
        $trip1->setEstimatedArrivalTime(new \DateTime('+3 days 14:00'));
        $trip1->setTripDate(new \DateTime('+3 days'));
        $trip1->setDepartureTimeOfDay(new \DateTime('08:00'));
        $trip1->setArrivalTimeOfDay(new \DateTime('14:00'));
        $trip1->setPrice('25000.00');
        $trip1->setDriverName('Jean Mbemba');
        $trip1->setStatus('planifie');
        $manager->persist($trip1);

        $trip2 = new Trip();
        $trip2->setAgency($agency1);
        $trip2->setBus($bus1);
        $trip2->setDepartureCity('Brazzaville');
        $trip2->setArrivalCity('Dolisie');
        $trip2->setBoardingPoints([
            ['city' => 'Brazzaville', 'name' => 'Gare Centrale'],
            ['city' => 'Madibou', 'name' => 'Station Madibou']
        ]);
        $trip2->setDeboardingPoints([
            ['city' => 'Dolisie', 'name' => 'Gare Routière'],
            ['city' => 'Dolisie', 'name' => 'Zone Portuaire']
        ]);
        $trip2->setDeparturePoint($point11);
        $trip2->setArrivalPoint($point12);
        $trip2->setDepartureTime(new \DateTime('+4 days 06:30'));
        $trip2->setEstimatedArrivalTime(new \DateTime('+4 days 12:00'));
        $trip2->setTripDate(new \DateTime('+4 days'));
        $trip2->setDepartureTimeOfDay(new \DateTime('06:30'));
        $trip2->setArrivalTimeOfDay(new \DateTime('12:00'));
        $trip2->setPrice('22000.00');
        $trip2->setDriverName('Marie Nkounkou');
        $trip2->setStatus('planifie');
        $manager->persist($trip2);

        // Agency 2
        $agency2 = new Agency();
        $agency2->setName('Riviera Express');
        $agency2->setEmail('contact@rivieraexpress.test');
        $agency2->setPhone('+242066200200');
        $agency2->setAddress('Route Nationale 2, Pointe-Noire');
        $agency2->setDescription('Agence spécialisée dans les trajets rapides entre Pointe-Noire et les villes du sud.');
        $agency2->setRegistrationNumber('REV-2026');
        $agency2->setPasswordHash($this->passwordHasher->hashPassword(new User(), 'Riviera2026!'));
        $agency2->setStatus('active');
        $manager->persist($agency2);

        $point21 = new AgencyPoint();
        $point21->setAgency($agency2);
        $point21->setCity('Pointe-Noire');
        $point21->setName('Terminal Riviera');
        $point21->setAddress('Avenue Kennedy');
        $point21->setQuartier('Cité des Pêches');
        $point21->setPhone('+242066200201');
        $point21->setLatitude(-4.786);
        $point21->setLongitude(11.869);
        $point21->setPointType('principal');
        $point21->setHasVipLounge(1);
        $manager->persist($point21);

        $point22 = new AgencyPoint();
        $point22->setAgency($agency2);
        $point22->setCity('Dolisie');
        $point22->setName('Gare Routière Dolisie');
        $point22->setAddress('Boulevard de lIndépendance');
        $point22->setQuartier('Centre-Ville');
        $point22->setPhone('+242066200202');
        $point22->setLatitude(-4.259);
        $point22->setLongitude(12.686);
        $point22->setPointType('secondaire');
        $point22->setHasParking(1);
        $manager->persist($point22);

        $bus2 = new Bus();
        $bus2->setAgency($agency2);
        $bus2->setRegistrationNumber('CG-202-RE');
        $bus2->setCapacity(52);
        $bus2->setCategory('Classique');
        $bus2->setBrand('Volvo');
        $bus2->setModel('9700');
        $bus2->setColor('Bleu');
        $manager->persist($bus2);

        $agency2User = new User();
        $agency2User->setFullName('Manager Riviera');
        $agency2User->setEmail('manager@rivieraexpress.test');
        $agency2User->setPhoneNumber('+242066200300');
        $agency2User->setRoles(['ROLE_PARTNER', 'ROLE_USER']);
        $agency2User->setVilleResidence('Pointe-Noire');
        $agency2User->setQuartier('Cité des Pêches');
        $agency2User->setPassword($this->passwordHasher->hashPassword($agency2User, 'Riviera2026!'));
        $agency2User->setStatus('active');
        $manager->persist($agency2User);

        $agent2 = new Agent();
        $agent2->setUser($agency2User);
        $agent2->setAgency($agency2);
        $agent2->setAgentRole('agent_quai');
        $agent2->setStatus('active');
        $manager->persist($agent2);

        $trip3 = new Trip();
        $trip3->setAgency($agency2);
        $trip3->setBus($bus2);
        $trip3->setDepartureCity('Pointe-Noire');
        $trip3->setArrivalCity('Dolisie');
        $trip3->setBoardingPoints([
            ['city' => 'Pointe-Noire', 'name' => 'Terminal Riviera'],
            ['city' => 'Pointe-Noire', 'name' => 'Port de Plaisance']
        ]);
        $trip3->setDeboardingPoints([
            ['city' => 'Dolisie', 'name' => 'Gare Routière'],
            ['city' => 'Dolisie', 'name' => 'Centre Commercial']
        ]);
        $trip3->setDeparturePoint($point21);
        $trip3->setArrivalPoint($point22);
        $trip3->setDepartureTime(new \DateTime('+2 days 07:00'));
        $trip3->setEstimatedArrivalTime(new \DateTime('+2 days 12:30'));
        $trip3->setTripDate(new \DateTime('+2 days'));
        $trip3->setDepartureTimeOfDay(new \DateTime('07:00'));
        $trip3->setArrivalTimeOfDay(new \DateTime('12:30'));
        $trip3->setPrice('18000.00');
        $trip3->setDriverName('Carlos Ngoma');
        $trip3->setStatus('planifie');
        $manager->persist($trip3);

        // Users / clients
        $client1 = new User();
        $client1->setFullName('Samuel Kintoki');
        $client1->setEmail('samuel.kintoki@test.com');
        $client1->setPhoneNumber('+242066300300');
        $client1->setRoles(['ROLE_USER']);
        $client1->setVilleResidence('Brazzaville');
        $client1->setQuartier('Plateau des 15 ans');
        $client1->setPassword($this->passwordHasher->hashPassword($client1, 'Client2026!'));
        $client1->setStatus('active');
        $manager->persist($client1);

        $client2 = new User();
        $client2->setFullName('Fatou Mbala');
        $client2->setEmail('fatou.mbala@test.com');
        $client2->setPhoneNumber('+242066300301');
        $client2->setRoles(['ROLE_USER']);
        $client2->setVilleResidence('Pointe-Noire');
        $client2->setQuartier('Cité des Pêches');
        $client2->setPassword($this->passwordHasher->hashPassword($client2, 'Client2026!'));
        $client2->setStatus('active');
        $manager->persist($client2);

        $reservation1 = new Reservation();
        $reservation1->setUser($client1);
        $reservation1->setTrip($trip1);
        $reservation1->setTotalAmount('25000.00');
        $reservation1->setPaymentPhone('+242066300300');
        $reservation1->setPaymentMethod('MTN_MOMO');
        $reservation1->setPaymentStatus('paye');
        $reservation1->setTransactionReference('RES-2026-0001');
        $manager->persist($reservation1);

        $ticket1 = new Ticket();
        $ticket1->setReservation($reservation1);
        $ticket1->setPassengerName('Samuel Kintoki');
        $ticket1->setPassengerPhone($client1->getPhoneNumber());
        $ticket1->setPassengerCni('CG123456789');
        $ticket1->setSeatNumber(12);
        $ticket1->setQrCodeToken('QR-TRIP1-001');
        $ticket1->setStatus('embarque');
        $manager->persist($ticket1);

        $paymentLog1 = new PaymentLog();
        $paymentLog1->setReservation($reservation1);
        $paymentLog1->setOperator('MTN');
        $paymentLog1->setReference('PMT-2026-0001');
        $paymentLog1->setAmount('25000.00');
        $paymentLog1->setStatus('SUCCESS');
        $manager->persist($paymentLog1);

        $wallet1->setAvailableBalance('24500.00');
        $wallet1->setTotalEarned('24500.00');

        $transaction1 = new WalletTransaction();
        $transaction1->setWallet($wallet1);
        $transaction1->setType(WalletTransaction::TYPE_CREDIT);
        $transaction1->setSource(WalletTransaction::SOURCE_RESERVATION_PAYMENT);
        $transaction1->setAmount('24500.00');
        $transaction1->setFeeAmount('500.00');
        $transaction1->setBalanceAfter('24500.00');
        $transaction1->setReservation($reservation1);
        $transaction1->setDescription('Paiement réservation #1 (net après frais plateforme)');
        $manager->persist($transaction1);

        $platformWallet = new Wallet();
        $platformWallet->setType(Wallet::TYPE_PLATFORM);
        $platformWallet->setAvailableBalance('500.00');
        $platformWallet->setReservedBalance('0.00');
        $platformWallet->setTotalEarned('500.00');
        $platformWallet->setTotalWithdrawn('0.00');
        $manager->persist($platformWallet);

        $platformFeeTx = new WalletTransaction();
        $platformFeeTx->setWallet($platformWallet);
        $platformFeeTx->setType(WalletTransaction::TYPE_CREDIT);
        $platformFeeTx->setSource(WalletTransaction::SOURCE_PLATFORM_FEE);
        $platformFeeTx->setAmount('500.00');
        $platformFeeTx->setBalanceAfter('500.00');
        $platformFeeTx->setReservation($reservation1);
        $platformFeeTx->setDescription('Commission plateforme réservation #1');
        $manager->persist($platformFeeTx);

        $withdrawal1 = new WithdrawalRequest();
        $withdrawal1->setAgency($agency1);
        $withdrawal1->setRequestedBy($agency1User);
        $withdrawal1->setAmount('10000.00');
        $withdrawal1->setMethod('Mobile Money');
        $withdrawal1->setStatus('pending');
        $withdrawal1->setNotes('Demande de retrait de test');
        $manager->persist($withdrawal1);

        $wallet1->setAvailableBalance('14500.00');
        $wallet1->setReservedBalance('10000.00');

        $withdrawalHold = new WalletTransaction();
        $withdrawalHold->setWallet($wallet1);
        $withdrawalHold->setType(WalletTransaction::TYPE_DEBIT);
        $withdrawalHold->setSource(WalletTransaction::SOURCE_WITHDRAWAL_HOLD);
        $withdrawalHold->setAmount('10000.00');
        $withdrawalHold->setBalanceAfter('14500.00');
        $withdrawalHold->setWithdrawalRequest($withdrawal1);
        $withdrawalHold->setDescription('Fonds réservés pour demande de retrait');
        $manager->persist($withdrawalHold);

        $reservation2 = new Reservation();
        $reservation2->setUser($client2);
        $reservation2->setTrip($trip3);
        $reservation2->setTotalAmount('18000.00');
        $reservation2->setPaymentPhone('+242066300301');
        $reservation2->setPaymentMethod('AIRTEL_MONEY');
        $reservation2->setPaymentStatus('en_attente');
        $reservation2->setTransactionReference('RES-2026-0002');
        $manager->persist($reservation2);

        $ticket2 = new Ticket();
        $ticket2->setReservation($reservation2);
        $ticket2->setPassengerName('Fatou Mbala');
        $ticket2->setPassengerPhone($client2->getPhoneNumber());
        $ticket2->setPassengerCni('CG987654321');
        $ticket2->setSeatNumber(7);
        $ticket2->setQrCodeToken('QR-TRIP3-001');
        $ticket2->setStatus('en_attente');
        $manager->persist($ticket2);

        $manager->flush();
    }
}
