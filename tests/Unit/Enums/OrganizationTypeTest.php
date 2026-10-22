<?php

namespace Tests\Unit\Enums;

use App\Enums\OrganizationType;
use PHPUnit\Framework\TestCase;

class OrganizationTypeTest extends TestCase
{
    public function test_it_has_agency_and_business_cases(): void
    {
        $this->assertSame('agency', OrganizationType::Agency->value);
        $this->assertSame('business', OrganizationType::Business->value);
    }

    public function test_it_labels_each_case(): void
    {
        $this->assertSame('Agency', OrganizationType::Agency->label());
        $this->assertSame('Business', OrganizationType::Business->label());
    }
}
