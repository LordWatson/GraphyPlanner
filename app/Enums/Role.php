<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Strategist = 'strategist';
    case Designer = 'designer';
    case ClientReviewer = 'client_reviewer';
    case Viewer = 'viewer';
    case SuperAdmin = 'super_admin';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Strategist => 'Strategist',
            self::Designer => 'Designer',
            self::ClientReviewer => 'Client reviewer',
            self::Viewer => 'Viewer',
            self::SuperAdmin => 'Super admin',
        };
    }

    /**
     * Step B-1.5 — the same stored role reads differently in a business/creator workspace, which
     * has no agency vocabulary: Strategist → "Manager", Designer → "Creator". The enum values never
     * change (no `users.role` migration); only the label shown in the business shell does.
     */
    public function labelFor(OrganizationType $type): string
    {
        if ($type !== OrganizationType::Business) {
            return $this->label();
        }

        return match ($this) {
            self::Owner => 'Owner',
            self::Strategist => 'Manager',
            self::Designer => 'Creator',
            self::Viewer => 'Viewer',
            self::ClientReviewer => 'Reviewer',
            self::SuperAdmin => 'Super admin',
        };
    }

    /**
     * Roles that can be assigned when inviting or editing a teammate inside a tenant workspace.
     *
     * `ClientReviewer` is reserved for the client-portal invitation flow, and `SuperAdmin` is
     * org-less platform staff (`Gate::before` grants it every ability and it bypasses the
     * `OrganizationScope`) — it is provisioned out of band and must NEVER be assignable through
     * any tenant-facing staff flow, or an org Owner could escalate themselves or a teammate to
     * platform god-mode. Both are excluded in every workspace type. A business org narrows the
     * remainder to its own vocabulary (Owner/Manager/Creator/Viewer, no agency-only roles).
     *
     * @return list<Role>
     */
    public static function assignableFor(OrganizationType $type): array
    {
        if ($type === OrganizationType::Business) {
            return [self::Owner, self::Strategist, self::Designer, self::Viewer];
        }

        return array_values(array_filter(
            self::cases(),
            fn (Role $role) => $role !== self::ClientReviewer && $role !== self::SuperAdmin,
        ));
    }
}
