<?php

namespace App\Actions;

use App\Enums\RegistrationInterestStatus;
use App\Mail\RegistrationInterestReceivedMail;
use App\Mail\RegistrationInterestThanksMail;
use App\Models\RegistrationInterest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Stores a public "register interest" submission, thanks the applicant and alerts the team. One
 * transaction, so a row never lands without its emails queued (queues are `after_commit`, so
 * nothing is sent for a rolled-back write). Logs ids and geo only — no contact details.
 */
class RecordRegistrationInterestAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(array $attributes): RegistrationInterest
    {
        return DB::transaction(function () use ($attributes): RegistrationInterest {
            $interest = RegistrationInterest::create([
                ...$attributes,
                'status' => RegistrationInterestStatus::New,
            ]);

            Mail::to($interest->email, $interest->name)->queue(new RegistrationInterestThanksMail($interest));
            Mail::to(config('mail.registration_interest.to'))
                ->cc(config('mail.registration_interest.cc'))
                ->queue(new RegistrationInterestReceivedMail($interest));

            Log::info('Registration interest recorded', [
                'registration_interest_id' => $interest->id,
                'country' => $interest->country,
                'utm_source' => $interest->utm_source,
            ]);

            return $interest;
        });
    }
}
