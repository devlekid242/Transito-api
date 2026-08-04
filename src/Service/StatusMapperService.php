<?php

namespace App\Service;

class StatusMapperService
{
    public function normalizeTripStatus(?string $status): string
    {
        if ($status === null || trim($status) === '') {
            return 'planifie';
        }

        $normalized = strtolower(trim($status));
        $map = [
            'scheduled' => 'planifie',
            'planifie' => 'planifie',
            'boarding' => 'embarquement',
            'embarquement' => 'embarquement',
            'in_progress' => 'en_route',
            'inprogress' => 'en_route',
            'en_route' => 'en_route',
            'completed' => 'termine',
            'termine' => 'termine',
            'cancelled' => 'annule',
            'canceled' => 'annule',
            'annule' => 'annule',
        ];

        return $map[$normalized] ?? 'planifie';
    }

    public function normalizeTicketStatus(?string $status): string
    {
        if ($status === null || trim($status) === '') {
            return 'en_attente';
        }

        $normalized = strtolower(trim($status));
        $map = [
            'pending' => 'en_attente',
            'en_attente' => 'en_attente',
            'boarded' => 'embarque',
            'embarque' => 'embarque',
            'cancelled' => 'annule',
            'canceled' => 'annule',
            'annule' => 'annule',
        ];

        return $map[$normalized] ?? 'en_attente';
    }

    public function normalizeWithdrawalStatus(?string $status): string
    {
        if ($status === null || trim($status) === '') {
            return 'pending';
        }

        $normalized = strtolower(trim($status));
        $map = [
            'pending' => 'pending',
            'approved' => 'approved',
            'rejected' => 'rejected',
            'pended' => 'pending',
        ];

        return $map[$normalized] ?? 'pending';
    }

    /**
     * Map backend trip status to frontend status
     */
    public function mapTripStatus(string $backendStatus): string
    {
        $map = [
            'planifie' => 'SCHEDULED',
            'embarquement' => 'IN_PROGRESS',
            'en_route' => 'IN_PROGRESS',
            'termine' => 'COMPLETED',
            'annule' => 'CANCELLED'
        ];

        return $map[$this->normalizeTripStatus($backendStatus)] ?? ucfirst($backendStatus);
    }

    /**
     * Map backend bus status to frontend status
     */
    public function mapBusStatus(string $backendStatus): string
    {
        $map = [
            'disponible' => 'ACTIVE',
            'maintenance' => 'MAINTENANCE',
            'hors_service' => 'INACTIVE'
        ];

        return $map[$backendStatus] ?? ucfirst($backendStatus);
    }

    /**
     * Map backend ticket status to frontend boarding status
     */
    public function mapTicketStatus(string $backendStatus): string
    {
        $map = [
            'en_attente' => 'PENDING',
            'embarque' => 'BOARDED',
            'annule' => 'CANCELLED'
        ];

        return $map[$this->normalizeTicketStatus($backendStatus)] ?? 'NOT_FOUND';
    }

    /**
     * Map backend agency point status to frontend status
     */
    public function mapAgencyPointStatus(string $backendStatus): string
    {
        $map = [
            'active' => 'ACTIVE',
            'inactive' => 'INACTIVE'
        ];

        return $map[$backendStatus] ?? ucfirst($backendStatus);
    }

    /**
     * Map backend to frontend for all status types
     */
    public function mapStatus(string $backendStatus, string $type): string
    {
        switch ($type) {
            case 'trip':
                return $this->mapTripStatus($backendStatus);
            case 'bus':
                return $this->mapBusStatus($backendStatus);
            case 'ticket':
                return $this->mapTicketStatus($backendStatus);
            case 'agency_point':
                return $this->mapAgencyPointStatus($backendStatus);
            default:
                return ucfirst($backendStatus);
        }
    }
}