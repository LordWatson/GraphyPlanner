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
     * Step B-1.5 — roles that can be assigned when inviting a teammate into an org of this type.
     * `ClientReviewer` is reserved for the client-portal invitation flow in both. A business org
     * additionally never assigns `SuperAdmin` (platform staff) — its team is Owner/Manager/Creator/
     * Viewer. Agency behaviour is unchanged (every role except `ClientReviewer`).
     *
     * @return list<Role>
     */
    public static function assignableFor(OrganizationType $type): array
    {
        if ($type === OrganizationType::Business) {
            return [self::Owner, self::Strategist, self::Designer, self::Viewer];
        }

        return array_values(array_filter(self::cases(), fn (Role $role) => $role !== self::ClientReviewer));
    }
}
