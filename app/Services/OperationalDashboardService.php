<?php

namespace App\Services;

use App\Models\User;
use App\Support\BookingRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class OperationalDashboardService
{
    public function __construct(private AdminDestinationScopeService $scope) {}

    public function metadata(User $actor, array $filters): array
    {
        $actor = $this->scope->actor($actor);

        return ['generated_at' => now()->utc()->toIso8601String(), 'last_updated_at' => now()->utc()->toIso8601String(),
            'financial' => $this->financial(),
            'scope' => ['mode' => (int) $actor->role === 2 ? 'global' : 'assigned_destinations', 'destination_guid' => $filters['destination_guid'] ?? null],
            'period' => $this->period($filters), 'metric_bases' => ['decisions' => 'decision_utc', 'pending_and_workload' => 'current_snapshot', 'booking_trend' => 'created_utc', 'capacity_and_visitors' => 'visit_destination_local_calendar_date'], 'admin_filter_applies_to' => isset($filters['admin_guid']) ? 'decision_metrics_only' : null];
    }

    public function get(User $actor, string $section, array $filters): mixed
    {
        $actor = $this->scope->actor($actor);
        abort_if(isset($filters['admin_guid']) && (int) $actor->role !== 2, 403);
        if (isset($filters['destination_guid'])) {
            $this->scope->authorize($actor, $filters['destination_guid']);
        }
        if ($section === 'action-center' && ! isset($filters['from'])) {
            $filters['from'] = now()->utc()->toDateString();
            $filters['to'] = now()->utc()->addDays(30)->toDateString();
        }

        return match ($section) {
            'queue', 'action-center' => $this->queue($actor, $filters, $section === 'action-center'),
            'destinations' => $this->destinations($actor, $filters),
            'visitors' => $this->metadata($actor, $filters) + ['metrics' => $this->visitors($actor, $filters), 'financial' => $this->financial()],
            'approvals' => $this->approvals($actor, $filters),
            default => $this->overview($actor, $filters),
        };
    }

    private function period(array $filters): array
    {
        return ['from' => $filters['from'] ?? now()->utc()->startOfMonth()->toDateString(),
            'to' => $filters['to'] ?? now()->utc()->toDateString(), 'date_basis' => $filters['date_basis'] ?? 'decision',
            'timezone' => 'UTC', 'visit_timezone' => 'destination_local_calendar_date'];
    }

    private function bookings(User $actor, array $filters): Builder
    {
        $query = DB::table('bookings as b')->join('destination_quotas as q', 'q.guid', '=', 'b.destination_quota_guid');
        $this->scope->apply($query, $actor, 'q.destination_guid');
        if (isset($filters['destination_guid'])) {
            $query->where('q.destination_guid', $filters['destination_guid']);
        }

        return $query;
    }

    private function between(Builder $query, string $column, array $filters, bool $date = false): Builder
    {
        $period = $this->period($filters);

        return $date ? $query->whereBetween($column, [$period['from'], $period['to']])
            : $query->where($column, '>=', $period['from'].' 00:00:00')->where($column, '<', CarbonImmutable::parse($period['to'], 'UTC')->addDay()->toDateTimeString());
    }

    private function decisions(User $actor, array $filters, bool $personal = false): Builder
    {
        $histories = DB::table('booking_status_histories')->where('old_status', 'pending_approval')
            ->whereIn('new_status', ['awaiting_payment', 'confirmed', 'rejected'])
            ->groupBy('booking_guid')->selectRaw('booking_guid, MIN(created_at) as decided_at');
        $query = DB::table('booking_status_histories as h')->joinSub($histories, 'first_decision', fn ($join) => $join->on('first_decision.booking_guid', '=', 'h.booking_guid')->on('first_decision.decided_at', '=', 'h.created_at'))
            ->join('bookings as b', 'b.guid', '=', 'h.booking_guid')->join('destination_quotas as q', 'q.guid', '=', 'b.destination_quota_guid')
            ->where('h.old_status', 'pending_approval')->whereIn('h.new_status', ['awaiting_payment', 'confirmed', 'rejected']);
        if ($personal) {
            $query->where('h.actor_guid', $actor->guid);
        } else {
            $this->scope->apply($query, $actor, 'q.destination_guid');
        }
        if (isset($filters['destination_guid'])) {
            $query->where('q.destination_guid', $filters['destination_guid']);
        }
        if (isset($filters['admin_guid'])) {
            $query->where('h.actor_guid', $filters['admin_guid']);
        }

        return $this->between($query, 'h.created_at', $filters);
    }

    private function decisionMetrics(Builder $query): array
    {
        $seconds = DB::getDriverName() === 'sqlite' ? '(julianday(h.created_at) - julianday(b.created_at)) * 86400' : 'TIMESTAMPDIFF(SECOND, b.created_at, h.created_at)';
        $row = $query->selectRaw("COUNT(DISTINCT CASE WHEN h.new_status IN ('confirmed','awaiting_payment') THEN b.guid END) as approved_decisions, COUNT(DISTINCT CASE WHEN h.new_status = 'rejected' THEN b.guid END) as rejected_decisions, AVG($seconds) as avg_response_seconds, COUNT(DISTINCT CASE WHEN h.created_at < b.approval_expires_at THEN b.guid END) as within_sla")->first();
        $approved = (int) $row->approved_decisions;
        $rejected = (int) $row->rejected_decisions;
        $total = $approved + $rejected;

        return ['approved_decisions' => $approved, 'rejected_decisions' => $rejected,
            'approval_rate_percent' => $total ? round($approved / $total * 100, 2) : null,
            'avg_response_seconds' => $row->avg_response_seconds === null ? null : round((float) $row->avg_response_seconds, 2),
            'sla_compliance_percent' => $total ? round((int) $row->within_sla / $total * 100, 2) : null, 'date_basis' => 'decision'];
    }

    private function approvals(User $actor, array $filters): array
    {
        $data = $this->metadata($actor, $filters) + ['team_operational_totals' => $this->decisionMetrics($this->decisions($actor, $filters)),
            'personal' => $this->decisionMetrics($this->decisions($actor, $filters, true)),
            'personal_history_scope' => 'own_actions_including_former_assignments',
            'series' => $this->decisions($actor, $filters)->selectRaw("DATE(h.created_at) as date, COUNT(DISTINCT CASE WHEN h.new_status IN ('confirmed','awaiting_payment') THEN b.guid END) as approved_decisions, COUNT(DISTINCT CASE WHEN h.new_status = 'rejected' THEN b.guid END) as rejected_decisions")->groupByRaw('DATE(h.created_at)')->orderBy('date')->get()->all()];
        if ((int) $actor->role === 2) {
            $data['staff_workload'] = $this->bookings($actor, $filters)->join('booking_approval_claims as c', 'c.booking_guid', '=', 'b.guid')
                ->where('b.status', 'pending_approval')->where('b.approval_expires_at', '>', now())->whereRaw($this->activeClaimSql())
                ->selectRaw('c.claimed_by_user_guid as admin_guid, COUNT(*) as active_claims')->groupBy('c.claimed_by_user_guid')->orderByDesc('active_claims')->orderBy('c.claimed_by_user_guid')->limit(100)->get()->all();
            $data['staff'] = $this->decisions($actor, $filters)->selectRaw('h.actor_guid as admin_guid, COUNT(DISTINCT b.guid) as decisions')->groupBy('h.actor_guid')->orderByDesc('decisions')->limit(100)->get()->all();
        }

        return $data;
    }

    private function overview(User $actor, array $filters): array
    {
        $pending = $this->bookings($actor, $filters)->where('b.status', 'pending_approval');
        $destinations = $this->scope->apply(DB::table('destinations'), $actor, 'destinations.guid');
        if (isset($filters['destination_guid'])) {
            $destinations->where('guid', $filters['destination_guid']);
        }
        $metrics = $this->decisionMetrics($this->decisions($actor, $filters)) + [
            'assigned_destinations' => $destinations->count(), 'pending_approvals' => (clone $pending)->count(),
            'expired_pending' => (clone $pending)->where('b.approval_expires_at', '<=', now())->count(),
            'actionable_pending' => (clone $pending)->where('b.approval_expires_at', '>', now())->count(),
            'pending_date_basis' => 'current_snapshot'];
        $claims = $this->bookings($actor, $filters)->leftJoin('booking_approval_claims as c', 'c.booking_guid', '=', 'b.guid')->where('b.status', 'pending_approval')->where('b.approval_expires_at', '>', now());
        $active = $this->activeClaimSql();
        $workload = $claims->selectRaw("COUNT(CASE WHEN $active THEN 1 END) as claimed, COUNT(CASE WHEN NOT ($active) THEN 1 END) as unclaimed, COUNT(CASE WHEN $active AND c.claimed_by_user_guid = ? THEN 1 END) as personal_claims", [$actor->guid])->first();

        return $this->metadata($actor, $filters) + ['metrics' => $metrics, 'personal' => $this->decisionMetrics($this->decisions($actor, $filters, true)),
            'assigned_workload' => $workload, 'visitors' => $this->visitors($actor, $filters),
            'booking_trend' => $this->between($this->bookings($actor, $filters), 'b.created_at', $filters)->selectRaw('DATE(b.created_at) as date, COUNT(*) as bookings, SUM(b.quantity) as quantity_requested')->groupByRaw('DATE(b.created_at)')->orderBy('date')->get()->all(),
            'financial' => $this->financial()];
    }

    private function visitors(User $actor, array $filters): array
    {
        $query = $this->between($this->bookings($actor, $filters), 'q.visit_date', $filters, true);
        $query->where('b.status', 'confirmed');
        $checked = 'CASE WHEN EXISTS (SELECT 1 FROM booking_tickets t WHERE t.booking_guid=b.guid) THEN (SELECT COUNT(*) FROM booking_tickets t WHERE t.booking_guid=b.guid AND t.checked_in_at IS NOT NULL) WHEN EXISTS (SELECT 1 FROM check_ins l WHERE l.booking_guid=b.guid) THEN b.quantity ELSE 0 END';
        $row = $query->selectRaw("COALESCE(SUM($checked),0) as checked_in_tickets, COUNT(CASE WHEN ($checked)>0 THEN 1 END) as checked_in_bookings")->first();

        return ['date_basis' => 'visit', 'timezone' => 'destination_local_calendar_date', 'checked_in_tickets' => (int) $row->checked_in_tickets,
            'checked_in_bookings' => (int) $row->checked_in_bookings, 'attendance_rate' => null, 'no_show_count' => null,
            'availability' => 'unavailable', 'reason' => 'insufficient_verified_data'];
    }

    private function destinations(User $actor, array $filters): mixed
    {
        $query = $this->scope->apply(DB::table('destinations as d'), $actor, 'd.guid');
        if (isset($filters['destination_guid'])) {
            $query->where('d.guid', $filters['destination_guid']);
        }
        $period = $this->period($filters);
        $quotas = DB::table('destination_quotas')->whereBetween('visit_date', [$period['from'], $period['to']])->selectRaw('destination_guid, SUM(capacity) as capacity')->groupBy('destination_guid');
        $held = "'".implode("','", BookingRules::HELD)."'";
        $bookings = DB::table('bookings as b')->join('destination_quotas as q', 'q.guid', '=', 'b.destination_quota_guid')->whereBetween('q.visit_date', [$period['from'], $period['to']])
            ->groupBy('q.destination_guid')->selectRaw("q.destination_guid, COUNT(*) as bookings, SUM(b.quantity) as quantity_requested, SUM(CASE WHEN b.status IN ($held) THEN b.quantity ELSE 0 END) as allocated, SUM(CASE WHEN b.status='confirmed' THEN b.quantity ELSE 0 END) as confirmed_quantity, SUM(CASE WHEN b.status IN ('awaiting_payment','confirmed') THEN b.quantity ELSE 0 END) as approved_quantity, SUM(CASE WHEN b.status='pending_approval' THEN 1 ELSE 0 END) as pending_approval_bookings, SUM(CASE WHEN b.status='awaiting_payment' THEN 1 ELSE 0 END) as awaiting_payment_bookings, SUM(CASE WHEN b.status='confirmed' THEN 1 ELSE 0 END) as confirmed_bookings, SUM(CASE WHEN b.status='rejected' THEN 1 ELSE 0 END) as rejected_bookings, SUM(CASE WHEN b.status='cancelled' THEN 1 ELSE 0 END) as cancelled_bookings, SUM(CASE WHEN b.status='expired' THEN 1 ELSE 0 END) as expired_bookings, SUM(CASE WHEN b.status='payment_verification' THEN 1 ELSE 0 END) as payment_verification_bookings, SUM(CASE WHEN b.status='payment_review' THEN 1 ELSE 0 END) as payment_review_bookings");

        return $query->leftJoinSub($quotas, 'capacity', 'capacity.destination_guid', '=', 'd.guid')->leftJoinSub($bookings, 'counts', 'counts.destination_guid', '=', 'd.guid')
            ->selectRaw('d.guid as destination_guid, d.name, d.timezone, COALESCE(capacity.capacity,0) as capacity, COALESCE(counts.bookings,0) as bookings, COALESCE(counts.quantity_requested,0) as quantity_requested, COALESCE(counts.allocated,0) as allocated, COALESCE(counts.confirmed_quantity,0) as confirmed_quantity, COALESCE(counts.approved_quantity,0) as approved_quantity, COALESCE(counts.pending_approval_bookings,0) as pending_approval_bookings, COALESCE(counts.awaiting_payment_bookings,0) as awaiting_payment_bookings, COALESCE(counts.confirmed_bookings,0) as confirmed_bookings, COALESCE(counts.rejected_bookings,0) as rejected_bookings, COALESCE(counts.cancelled_bookings,0) as cancelled_bookings, COALESCE(counts.expired_bookings,0) as expired_bookings, COALESCE(counts.payment_verification_bookings,0) as payment_verification_bookings, COALESCE(counts.payment_review_bookings,0) as payment_review_bookings')
            ->orderBy('d.name')->orderBy('d.guid')->paginate($filters['per_page'] ?? 15)->through(function ($row) {
                $row->remaining = max(0, (int) $row->capacity - (int) $row->allocated);
                $row->reserved_occupancy_percent = $row->capacity > 0 ? round($row->allocated / $row->capacity * 100, 2) : null;
                $row->confirmed_occupancy_percent = $row->capacity > 0 ? round($row->confirmed_quantity / $row->capacity * 100, 2) : null;
                $row->eligible_tickets = null;
                $row->date_basis = 'visit';

                return $row;
            });
    }

    private function activeClaimSql(): string
    {
        $now = DB::getPdo()->quote(now()->utc()->toDateTimeString());

        return "(b.status='pending_approval' AND b.approval_expires_at > $now AND c.claimed_by_user_guid IS NOT NULL AND c.expires_at > $now AND EXISTS (SELECT 1 FROM users u WHERE u.guid=c.claimed_by_user_guid AND u.status='active' AND (u.role=2 OR (u.role=1 AND EXISTS (SELECT 1 FROM destination_admin_assignments a WHERE a.destination_guid=q.destination_guid AND a.user_guid=u.guid)))))";
    }

    private function queue(User $actor, array $filters, bool $actions): mixed
    {
        $query = $this->bookings($actor, $filters)->leftJoin('booking_approval_claims as c', 'c.booking_guid', '=', 'b.guid');
        $query->where(function ($q) use ($actions, $filters) {
            $q->where('b.status', 'pending_approval');
            if ($actions) {
                $period = $this->period($filters);
                $q->orWhere(function ($upcoming) use ($period) {
                    $upcoming->where('b.status', 'confirmed')->whereBetween('q.visit_date', [$period['from'], $period['to']]);
                    $upcoming->whereExists(function ($destination) {
                        $destination->selectRaw('1')->from('destinations as visit_destination')->whereColumn('visit_destination.guid', 'q.destination_guid')
                            ->where(function ($localDay) {
                                foreach (DB::table('destinations')->distinct()->pluck('timezone') as $timezone) {
                                    $localDay->orWhere(fn ($zone) => $zone->where('visit_destination.timezone', $timezone)->where('q.visit_date', '>=', now($timezone)->toDateString()));
                                }
                            });
                    });
                });
            }
        });
        if (! $actions) {
            $query->where('b.approval_expires_at', '>', now());
            if (isset($filters['from'])) {
                $this->between($query, 'b.created_at', $filters);
            }
        }
        $active = $this->activeClaimSql();

        return $query->select('b.guid as booking_guid', 'q.destination_guid', 'b.destination_name_snapshot', 'b.status', 'q.visit_date', 'b.approval_expires_at', 'b.updated_at', 'c.guid as claim_guid')
            ->selectRaw("CASE WHEN $active THEN c.claimed_by_user_guid ELSE NULL END as owner_user_guid, CASE WHEN $active THEN c.expires_at ELSE NULL END as expires_at, CASE WHEN $active THEN 'active' ELSE 'unclaimed' END as claim_state, c.expires_at as previous_claim_expires_at")
            ->orderByRaw("CASE WHEN b.status='pending_approval' THEN 0 ELSE 1 END")->orderBy('b.approval_expires_at')->orderBy('b.guid')
            ->paginate($filters['per_page'] ?? 15)->through(function ($row) {
                $row->action = match (true) {
                    $row->status !== 'pending_approval' => 'upcoming_visit',
                    now()->gte($row->approval_expires_at) => 'approval_deadline_passed',
                    $row->previous_claim_expires_at && now()->gte($row->previous_claim_expires_at) => 'expired_claim',
                    now()->addHours(2)->gte($row->approval_expires_at) => 'approval_deadline_approaching',
                    default => 'pending_approval',
                };
                foreach (['approval_expires_at', 'expires_at', 'updated_at'] as $field) {
                    if ($row->$field !== null) {
                        $row->$field = CarbonImmutable::parse($row->$field, 'UTC')->toIso8601String();
                    }
                }
                unset($row->previous_claim_expires_at);

                return $row;
            });
    }

    private function financial(): array
    {
        return ['availability' => 'unavailable', 'reason' => 'payment_gateway_not_integrated', 'gross_revenue' => null, 'net_revenue' => null,
            'gateway_fees' => null, 'refunds' => null, 'settlement' => null];
    }
}
