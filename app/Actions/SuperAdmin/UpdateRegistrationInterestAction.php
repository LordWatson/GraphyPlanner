<?php

namespace App\Actions\SuperAdmin;

use App\Enums\RegistrationInterestStatus;
use App\Models\RegistrationInterest;
use Illuminate\Support\Facades\Log;

/**
 * Moves a registration through the manual onboarding pipeline (status, demo slot, notes). The
 * first move off `new` stamps `contacted_at`, which feeds the "time to first contact" stat.
 */
class UpdateRegistrationInterestAction
{
    /**
     * @param  array{status: RegistrationInterestStatus, demo_at: ?string, notes: ?string}  $data
     */
    public function __invoke(RegistrationInterest $interest, array $data): RegistrationInterest
    {
        $from = $interest->status;

        $interest->fill($data);

        if ($interest->contacted_at === null && $data['status'] !== RegistrationInterestStatus::New) {
            $interest->fill(['contacted_at' => now()]);
        }

        $interest->save();

        if ($from !== $interest->status) {
            Log::info('Registration interest status changed', [
                'registration_interest_id' => $interest->id,
                'from' => $from->value,
                'to' => $interest->status->value,
            ]);
        }

        return $interest;
    }
}
