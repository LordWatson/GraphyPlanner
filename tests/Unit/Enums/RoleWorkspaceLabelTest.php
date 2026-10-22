<?php

namespace Tests\Unit\Enums;

use App\Enums\OrganizationType;
use App\Enums\Role;
use PHPUnit\Framework\TestCase;

class RoleWorkspaceLabelTest extends TestCase
{
    public function test_agency_labels_are_unchanged(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertSame($role->label(), $role->labelFor(OrganizationType::Agency));
        }
    }

    public function test_business_relabels_internal_roles(): void
    {
        $this->assertSame('Owner', Role::Owner->labelFor(OrganizationType::Business));
        $this->assertSame('Manager', Role::Strategist->labelFor(OrganizationType::Business));
        $this->assertSame('Creator', Role::Designer->labelFor(OrganizationType::Business));
        $this->assertSame('Viewer', Role::Viewer->labelFor(OrganizationType::Business));
    }

    public function test_business_assignable_roles_exclude_client_reviewer_and_super_admin(): void
    {
        $assignable = Role::assignableFor(OrganizationType::Business);

        $this->assertSame([Role::Owner, Role::Strategist, Role::Designer, Role::Viewer], $assignable);
        $this->assertNotContains(Role::ClientReviewer, $assignable);
        $this->assertNotContains(Role::SuperAdmin, $assignable);
    }

    public function test_agency_assignable_roles_exclude_only_client_reviewer(): void
    {
        $assignable = Role::assignableFor(OrganizationType::Agency);

        $this->assertNotContains(Role::ClientReviewer, $assignable);
        $this->assertContains(Role::Owner, $assignable);
        $this->assertContains(Role::Strategist, $assignable);
        $this->assertContains(Role::Designer, $assignable);
        $this->assertContains(Role::Viewer, $assignable);
    }
}
