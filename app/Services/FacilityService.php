<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FacilityService
{
    public function listing(array $filters, bool $includeInactive = false): LengthAwarePaginator
    {
        $query = Facility::query();
        $status = $includeInactive ? ($filters['status'] ?? 'all') : 'active';
        if ($status !== 'all') {
            $query->where('is_active', $status === 'active');
        }

        return $query->orderBy('name')->orderBy('guid')->paginate($filters['per_page'] ?? 15);
    }

    public function save(array $data, ?Facility $facility = null): Facility
    {
        try {
            return DB::transaction(function () use ($data, $facility) {
                if ($facility) {
                    $facility = Facility::whereKey($facility->guid)->lockForUpdate()->firstOrFail();
                }
                $facility ??= new Facility;
                $facility->fill($data)->save();

                return $facility;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'The facility name or slug has already been taken.']);
        }
    }

    public function assign(Destination $destination, array $guids, ?User $actor = null): Destination
    {
        return DB::transaction(function () use ($destination, $guids, $actor) {
            $destination = Destination::whereKey($destination->guid)->lockForUpdate()->firstOrFail();
            if ($actor) {
                app(AdminDestinationScopeService::class)->authorize($actor, $destination->guid, true);
            }
            $facilities = Facility::whereIn('guid', $guids)->orderBy('guid')->lockForUpdate()->get();
            if ($facilities->count() !== count($guids) || $facilities->contains(fn (Facility $facility) => ! $facility->is_active)) {
                throw ValidationException::withMessages(['facility_guids' => 'Every facility must exist and be active.']);
            }
            $existing = DB::table('destination_facilities')->where('destination_guid', $destination->guid)->pluck('facility_guid')->all();
            $attach = [];
            foreach ($facilities as $facility) {
                $attach[$facility->guid] = in_array($facility->guid, $existing, true) ? [] : ['guid' => (string) Str::uuid()];
            }
            $destination->facilities()->sync($attach);

            return $destination->load(['category', 'activeFacilities'])->loadCount('reviews as rating_count')->loadAvg('reviews as rating_average', 'rating');
        }, 3);
    }
}
