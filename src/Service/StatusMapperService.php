<?php

namespace App\Service;

class StatusMapperService
{
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

        return $map[$backendStatus] ?? ucfirst($backendStatus);
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

        return $map[$backendStatus] ?? 'NOT_FOUND';
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