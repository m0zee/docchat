<?php

namespace App\Services\Calls;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\CallTransport;
use App\Exceptions\CallPlanException;
use App\Jobs\PlaceTwilioCallJob;
use App\Mail\CallLinkMail;
use App\Models\Call;
use App\Models\CallContact;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Outbound calls, now or at a set time: a phone call through Twilio, or a
 * call link the person opens in their browser. Only contacts who agreed to
 * be called, and did not opt out, are ever called.
 */
final class OutboundCalls
{
    public const MAX_AGENDA_POINTS = 8;

    public const MAX_POINT_CHARS = 200;

    /**
     * Make a call, or schedule it when $at is given.
     *
     * @param  list<string>  $agenda
     * @return array{call: Call, link: string|null, emailed: bool}
     */
    public function create(
        Workspace $workspace,
        CallContact $contact,
        CallTransport $transport,
        string $purpose,
        ?User $by = null,
        ?string $opening = null,
        array $agenda = [],
        ?CarbonInterface $at = null,
        ?string $timezone = null,
        bool $email = true,
    ): array {
        if ($contact->workspace_id !== $workspace->id || ! $contact->mayBeCalled()) {
            throw new CallPlanException('contact', __('Only contacts who agreed to be called, and did not opt out, can be called.'));
        }

        $phone = $transport === CallTransport::Phone;
        $agenda = self::cleanAgenda($agenda);

        if ($phone) {
            if (! CallSettings::twilioConfigured() || ! filled(config('docchat.calls.twilio.from_number'))) {
                throw new CallPlanException('transport', __('The phone line is not set up on this server (TWILIO_* in .env).'));
            }

            if ($contact->phone === null) {
                throw new CallPlanException('contact', __('This contact has no phone number.'));
            }
        }

        if ($at !== null) {
            self::checkTime($at, $phone);
        } elseif ($phone && ! CallingHours::allows()) {
            throw new CallPlanException('transport', __('Phone calls only start inside the calling hours: :hours.', ['hours' => CallingHours::describe()]));
        }

        $call = Call::query()->create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'created_by_user_id' => $by?->id,
            'direction' => CallDirection::Outbound,
            'transport' => $transport,
            'status' => match (true) {
                ! $phone => CallStatus::Waiting,
                $at !== null => CallStatus::Scheduled,
                default => CallStatus::Queued,
            },
            'purpose' => trim($purpose),
            'opening' => trim((string) $opening) ?: null,
            'agenda' => $agenda === [] ? null : $agenda,
            'to_number' => $phone ? $contact->phone : null,
            'scheduled_at' => $at?->copy()->utc(),
            'timezone' => $at === null ? null : self::timezone($timezone),
            'expires_at' => $phone ? null : ($at ?? now())->copy()->addDays(max(1, (int) config('docchat.calls.link_valid_days'))),
        ]);

        $call->setRelation('workspace', $workspace)->setRelation('contact', $contact);

        if ($phone) {
            if ($at === null) {
                PlaceTwilioCallJob::dispatch($call->id);
            }

            return ['call' => $call, 'link' => null, 'emailed' => false];
        }

        $token = $call->issueToken();
        $link = self::linkUrl($token);

        if ($at !== null) {
            // Kept (encrypted) only to send the "it's time" email.
            $call->forceFill(['link_token' => $token])->save();
        }

        $emailed = $email && $contact->email !== null;

        if ($emailed) {
            Mail::to($contact->email)->send(new CallLinkMail($call, $link));
        }

        return ['call' => $call, 'link' => $link, 'emailed' => $emailed];
    }

    /**
     * Move a scheduled call that hasn't started to a new time.
     */
    public function reschedule(Call $call, CarbonInterface $at, ?string $timezone = null): void
    {
        if (! self::isReschedulable($call)) {
            throw new CallPlanException('when', __('Only calls that have not started yet can be moved.'));
        }

        $phone = $call->transport === CallTransport::Phone;
        self::checkTime($at, $phone);

        $call->forceFill([
            'scheduled_at' => $at->copy()->utc(),
            'timezone' => self::timezone($timezone ?? $call->timezone),
            'expires_at' => $phone ? null : $at->copy()->addDays(max(1, (int) config('docchat.calls.link_valid_days'))),
        ])->save();
    }

    public static function isReschedulable(Call $call): bool
    {
        return $call->scheduled_at !== null
            && $call->scheduled_at->isFuture()
            && $call->direction === CallDirection::Outbound
            && in_array($call->status, [CallStatus::Scheduled, CallStatus::Waiting], true)
            && ($call->transport === CallTransport::Phone || $call->link_token !== null);
    }

    public function cancel(Call $call): bool
    {
        if (! $call->status->isPending()) {
            return false;
        }

        $call->forceFill(['link_token' => null])->save();
        $call->finish(CallStatus::Canceled, 'canceled_by_owner');

        return true;
    }

    /**
     * Start the calls whose time has come. Run every minute by the scheduler.
     *
     * @return array{phone: int, links: int, missed: int}
     */
    public function startDue(): array
    {
        $started = ['phone' => 0, 'links' => 0, 'missed' => 0];
        $grace = max(1, (int) config('docchat.calls.schedule_grace_minutes', 30));

        $phone = Call::query()->withoutGlobalScopes()
            ->where('status', CallStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(100)
            ->pluck('id');

        foreach ($phone as $id) {
            // Claim the call first, so two scheduler runs can't both dial it.
            $claimed = Call::query()->withoutGlobalScopes()->whereKey($id)
                ->where('status', CallStatus::Scheduled->value)
                ->update(['status' => CallStatus::Queued->value, 'updated_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            /** @var Call $call */
            $call = Call::query()->withoutGlobalScopes()->whereKey($id)->firstOrFail();

            if ($call->scheduled_at !== null && $call->scheduled_at->lt(now()->subMinutes($grace))) {
                // The server was down at the time: don't ring hours late.
                $call->finish(CallStatus::Canceled, 'missed_schedule');
                $started['missed']++;

                continue;
            }

            PlaceTwilioCallJob::dispatch($id);
            $started['phone']++;
        }

        $links = Call::query()->withoutGlobalScopes()
            ->where('transport', CallTransport::Web->value)
            ->where('status', CallStatus::Waiting->value)
            ->whereNotNull('link_token')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(100)
            ->get();

        foreach ($links as $call) {
            $claimed = DB::table('calls')->where('id', $call->id)->whereNotNull('link_token')->update(['link_token' => null, 'updated_at' => now()]);

            if ($claimed === 0 || $call->link_token === null) {
                continue;
            }

            $call->load('workspace', 'contact');

            if ($call->contact?->email !== null && $call->contact->mayBeCalled() && ! $call->isExpired()) {
                try {
                    Mail::to($call->contact->email)->send(new CallLinkMail($call, self::linkUrl($call->link_token), reminder: true));
                    $started['links']++;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $started;
    }

    /**
     * A date and time typed in a time zone, as a moment in time.
     */
    public static function parseLocal(string $date, string $time, ?string $timezone): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m-d H:i', trim($date).' '.trim($time), self::timezone($timezone)) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function timezone(?string $timezone): string
    {
        $timezone = trim((string) $timezone);

        return $timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : (string) config('app.timezone', 'UTC');
    }

    /**
     * One point per line or list entry; bullets and numbers are dropped.
     *
     * @param  list<string>|string  $agenda
     * @return list<string>
     */
    public static function cleanAgenda(array|string $agenda): array
    {
        $lines = is_string($agenda) ? preg_split('/\R/u', $agenda) ?: [] : $agenda;
        $points = [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', (string) $line) ?? '');

            if ($line !== '') {
                $points[] = Str::limit($line, self::MAX_POINT_CHARS, '');
            }
        }

        if (count($points) > self::MAX_AGENDA_POINTS) {
            throw new CallPlanException('agenda', __('An agenda can have up to :count points.', ['count' => self::MAX_AGENDA_POINTS]));
        }

        return $points;
    }

    private static function checkTime(CarbonInterface $at, bool $phone): void
    {
        if ($at->lt(now()->addMinute())) {
            throw new CallPlanException('when', __('Pick a time in the future.'));
        }

        $days = max(1, (int) config('docchat.calls.schedule_max_days', 60));

        if ($at->gt(now()->addDays($days))) {
            throw new CallPlanException('when', __('Calls can be scheduled up to :days days ahead.', ['days' => $days]));
        }

        if ($phone && ! CallingHours::allows($at)) {
            throw new CallPlanException('when', __('Phone calls only start inside the calling hours: :hours.', ['hours' => CallingHours::describe()]));
        }
    }

    private static function linkUrl(string $token): string
    {
        // APP_URL, not the address the page was opened on: the link is sent to someone else.
        return rtrim((string) config('app.url'), '/').'/call/'.$token;
    }
}
