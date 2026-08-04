<?php

namespace App\Tests;

use App\Service\StatusMapperService;
use PHPUnit\Framework\TestCase;

class StatusMapperServiceTest extends TestCase
{
    public function testNormalizeTripStatusAcceptsLegacyAndEnglishValues(): void
    {
        $service = new StatusMapperService();

        $this->assertSame('planifie', $service->normalizeTripStatus('SCHEDULED'));
        $this->assertSame('planifie', $service->normalizeTripStatus('scheduled'));
        $this->assertSame('embarquement', $service->normalizeTripStatus('boarding'));
        $this->assertSame('en_route', $service->normalizeTripStatus('in_progress'));
        $this->assertSame('termine', $service->normalizeTripStatus('COMPLETED'));
        $this->assertSame('annule', $service->normalizeTripStatus('cancelled'));
    }

    public function testNormalizeTicketStatusAcceptsLegacyAndEnglishValues(): void
    {
        $service = new StatusMapperService();

        $this->assertSame('en_attente', $service->normalizeTicketStatus('PENDING'));
        $this->assertSame('embarque', $service->normalizeTicketStatus('BOARDED'));
        $this->assertSame('annule', $service->normalizeTicketStatus('CANCELLED'));
    }
}
