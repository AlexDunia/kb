<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class DeliverStaffInvitation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(public int $invitationId) {}

    public function uniqueId(): string
    {
        return (string) $this->invitationId;
    }

    public function handle(): void
    {
        $claimed = DB::table('event_staff_invitations')->where('id', $this->invitationId)->whereNull('sent_at')->whereNull('revoked_at')->whereNull('accepted_at')
            ->where('expires_at', '>', now())->where('next_delivery_at', '<=', now())->update(['next_delivery_at' => now()->addMinutes(5), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $invitation = DB::table('event_staff_invitations')->find($this->invitationId);
        $token = Crypt::decryptString($invitation->encrypted_token);
        $url = rtrim(config('app.frontend_url'), '/').'/event-invitations/accept#token='.rawurlencode($token);
        Mail::raw("You have been invited to help with an event. Sign in with this email address and accept your event access within seven days:\n".$url, fn ($message) => $message->to($invitation->email)->subject('Your Rush Hour event staff invitation'));
        DB::table('event_staff_invitations')->where('id', $invitation->id)->update(['sent_at' => now(), 'updated_at' => now()]);
    }
}
