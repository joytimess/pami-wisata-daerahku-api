<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApprovalQueueService
{
    public function __construct(private AdminDestinationScopeService $scope) {}

    public function mutate(User $actor, string $guid, string $action, string $key, array $input = []): array
    {
        $this->scope->actor($actor);
        $operation = 'approval.'.$action;
        ksort($input);
        $hash = hash('sha256', json_encode([$guid, $input], JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($actor, $key, $operation, $hash) {
            DB::table('idempotency_records')->insertOrIgnore(['guid' => (string) Str::uuid(), 'user_guid' => $actor->guid,
                'operation' => $operation, 'key' => $key, 'request_hash' => $hash, 'status' => 'processing',
                'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);
        }, 3);

        return DB::transaction(function () use ($actor, $guid, $action, $key, $input, $operation, $hash) {
            $record = DB::table('idempotency_records')->where('user_guid', $actor->guid)->where('operation', $operation)->where('key', $key)->lockForUpdate()->first();
            if (! hash_equals($record->request_hash, $hash)) {
                throw new BookingException('Key belongs to different input.', 'idempotency_conflict');
            }
            $reference = DB::table('bookings')->join('destination_quotas', 'destination_quotas.guid', '=', 'bookings.destination_quota_guid')
                ->where('bookings.guid', $guid)->first(['destination_quotas.destination_guid', 'destination_quotas.guid as quota_guid']);
            abort_unless($reference, 404);
            $this->scope->lockDestination($reference->destination_guid);
            DB::table('destination_quotas')->where('guid', $reference->quota_guid)->lockForUpdate()->first();
            $booking = DB::table('bookings')->where('guid', $guid)->lockForUpdate()->first();
            $actor = $this->scope->authorize($actor, $reference->destination_guid, true);
            if ($action === 'reassign') {
                abort_unless((int) $actor->role === 2, 403);
            }
            if ($record->status === 'completed') {
                if (now()->gte($record->expires_at)) {
                    throw new BookingException('Key retention expired.', 'idempotency_key_expired');
                }

                return $this->present($booking, true);
            }
            $this->pending($booking);
            $claim = $this->current($booking, $actor->guid);
            if ($action === 'release') {
                if ($claim && $claim->claimed_by_user_guid) {
                    if ($claim->claimed_by_user_guid !== $actor->guid && (int) $actor->role !== 2) {
                        throw new BookingException('Claim belongs to another Admin.', 'claim_not_owned');
                    }
                    if ($claim->claimed_by_user_guid !== $actor->guid && empty($input['reason'])) {
                        throw new BookingException('Override requires a reason.', 'override_reason_required');
                    }
                    $this->close($guid, $actor->guid, 'released', $input['reason'] ?? null);
                }
            } else {
                $owner = $actor;
                if ($action === 'reassign') {
                    $owner = User::whereKey($input['admin_guid'])->sharedLock()->first();
                    abort_unless($owner && (int) $owner->role === 1 && $owner->status === 'active', 422);
                    $this->scope->authorize($owner, $reference->destination_guid, true, 422);
                } elseif ($claim && $claim->claimed_by_user_guid && $claim->claimed_by_user_guid !== $actor->guid) {
                    throw new BookingException('Booking is claimed by another Admin.', 'claim_owned', ['owner_user_guid' => $claim->claimed_by_user_guid, 'expires_at' => CarbonImmutable::parse($claim->expires_at, 'UTC')->toIso8601String()]);
                }
                if (! $claim || $claim->claimed_by_user_guid !== $owner->guid) {
                    $this->own($booking, $owner->guid, $actor->guid, $action === 'reassign' ? 'reassigned' : 'claimed', $input['reason'] ?? null);
                } elseif ($action === 'reassign') {
                    $this->history($guid, $actor->guid, $owner->guid, $owner->guid, 'reassigned', $input['reason']);
                }
            }
            $result = $this->present($booking, true);
            DB::table('idempotency_records')->where('guid', $record->guid)->update(['status' => 'completed', 'response_reference' => $guid,
                'response_body' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return $result;
        }, 3);
    }

    public function pending(object $booking): void
    {
        if ($booking->status !== 'pending_approval') {
            throw new BookingException('Booking is no longer pending.', 'booking_not_pending');
        }
        if (now()->gte($booking->approval_expires_at)) {
            throw new BookingException('Approval deadline passed.', 'approval_deadline_passed');
        }
    }

    /** Caller holds the destination, quota and booking locks and has freshly authorized actor. */
    public function decision(User $actor, object $booking): void
    {
        $this->pending($booking);
        $claim = $this->current($booking, $actor->guid);
        if ($claim && $claim->claimed_by_user_guid && $claim->claimed_by_user_guid !== $actor->guid) {
            throw new BookingException('Booking is claimed by another Admin. Reassign explicitly first.', 'claim_owned');
        }
        if (! $claim || ! $claim->claimed_by_user_guid) {
            if (config('operations.claim_mode') !== 'compat') {
                throw new BookingException('Explicit claim required.', 'claim_required');
            }
            $this->own($booking, $actor->guid, $actor->guid, 'claimed', 'legacy_compat_auto_claim');
        }
    }

    private function current(object $booking, ?string $actor): ?object
    {
        $claim = DB::table('booking_approval_claims')->where('booking_guid', $booking->guid)->lockForUpdate()->first();
        if ($claim && $claim->claimed_by_user_guid) {
            $ownerValid = $this->validOwner($claim->claimed_by_user_guid, $booking, true);
            if (now()->gte($claim->expires_at) || ! $ownerValid) {
                $this->close($booking->guid, $actor, 'expired', $ownerValid ? 'claim_timeout' : 'owner_access_revoked');
                $claim->claimed_by_user_guid = null;
            }
        }

        return $claim;
    }

    private function own(object $booking, string $owner, string $actor, string $event, ?string $reason): void
    {
        $claim = DB::table('booking_approval_claims')->where('booking_guid', $booking->guid)->first();
        $data = ['claimed_by_user_guid' => $owner, 'claimed_at' => now(), 'expires_at' => min(now()->addMinutes(config('operations.claim_minutes', 15)), CarbonImmutable::parse($booking->approval_expires_at)), 'updated_at' => now()];
        if ($claim) {
            DB::table('booking_approval_claims')->where('guid', $claim->guid)->update($data);
        } else {
            DB::table('booking_approval_claims')->insert($data + ['guid' => (string) Str::uuid(), 'booking_guid' => $booking->guid, 'created_at' => now()]);
        }
        $this->history($booking->guid, $actor, $claim?->claimed_by_user_guid, $owner, $event, $reason);
    }

    public function close(string $guid, ?string $actor, string $event, ?string $reason = null): void
    {
        $claim = DB::table('booking_approval_claims')->where('booking_guid', $guid)->lockForUpdate()->first();
        if (! $claim || ! $claim->claimed_by_user_guid) {
            return;
        }
        DB::table('booking_approval_claims')->where('guid', $claim->guid)->update(['claimed_by_user_guid' => null, 'expires_at' => null, 'claimed_at' => null, 'updated_at' => now()]);
        $this->history($guid, $actor, $claim->claimed_by_user_guid, null, $event, $reason);
    }

    private function history(string $guid, ?string $actor, ?string $from, ?string $to, string $event, ?string $reason): void
    {
        DB::table('booking_approval_claim_histories')->insert(['guid' => (string) Str::uuid(), 'booking_guid' => $guid, 'actor_guid' => $actor,
            'from_user_guid' => $from, 'to_user_guid' => $to, 'event' => $event, 'reason' => $reason, 'created_at' => now()]);
    }

    private function validOwner(string $guid, object $booking, bool $lock = false): bool
    {
        $query = User::whereKey($guid);
        if ($lock) {
            $query->sharedLock();
        }
        $owner = $query->first();
        if (! $owner || $owner->status !== 'active') {
            return false;
        }
        if ((int) $owner->role === 2) {
            return true;
        }
        if ((int) $owner->role !== 1) {
            return false;
        }
        $destination = DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->value('destination_guid');
        $assignments = DB::table('destination_admin_assignments')->where('destination_guid', $destination)->where('user_guid', $guid);
        if ($lock) {
            $assignments->sharedLock();
        }

        return (bool) $assignments->first();
    }

    public function present(object $booking, bool $lock = false): array
    {
        $query = DB::table('booking_approval_claims')->where('booking_guid', $booking->guid);
        if ($lock) {
            $query->sharedLock();
        }
        $claim = $query->first();
        $active = $claim && $claim->claimed_by_user_guid && $this->validOwner($claim->claimed_by_user_guid, $booking, $lock) && now()->lt($claim->expires_at) && $booking->status === 'pending_approval' && now()->lt($booking->approval_expires_at);

        return ['booking_guid' => $booking->guid, 'claim_guid' => $claim?->guid, 'owner_user_guid' => $active ? $claim->claimed_by_user_guid : null,
            'expires_at' => $active ? CarbonImmutable::parse($claim->expires_at, 'UTC')->toIso8601String() : null,
            'approval_expires_at' => CarbonImmutable::parse($booking->approval_expires_at, 'UTC')->toIso8601String(), 'state' => $active ? 'active' : 'unclaimed'];
    }
}
