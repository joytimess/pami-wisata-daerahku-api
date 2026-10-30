<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\Favorite;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

class FavoriteService
{
    public function list(User $user, array $filters)
    {
        return Favorite::with(['destination.category', 'destination' => fn ($query) => $query->withCount('reviews as rating_count')->withAvg('reviews as rating_average', 'rating')])->where('user_guid', $user->guid)->orderByDesc('created_at')->orderBy('guid')->paginate($filters['per_page'] ?? 15);
    }

    public function add(User $user, Destination $destination): Favorite
    {
        abort_unless($destination->is_active && $destination->category()->where('is_active', true)->exists(), 404);
        try {
            return Favorite::firstOrCreate(['user_guid' => $user->guid, 'destination_guid' => $destination->guid])->load(['destination.category', 'destination' => fn ($query) => $query->withCount('reviews as rating_count')->withAvg('reviews as rating_average', 'rating')]);
        } catch (UniqueConstraintViolationException $e) {
            return Favorite::where('user_guid', $user->guid)->where('destination_guid', $destination->guid)->firstOrFail()->load(['destination.category', 'destination' => fn ($query) => $query->withCount('reviews as rating_count')->withAvg('reviews as rating_average', 'rating')]);
        }
    }

    public function remove(User $user, Destination $destination): void
    {
        Favorite::where('user_guid', $user->guid)->where('destination_guid', $destination->guid)->delete();
    }
}
