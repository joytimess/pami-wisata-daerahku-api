<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\Destination;
use App\Models\DestinationCategory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class CatalogService
{
    public function categories(array $filters)
    {
        return DestinationCategory::where('is_active', true)->orderBy('name')->paginate($filters['per_page'] ?? 15);
    }

    /** Administrative listing; `status` is active (default), inactive or all. */
    public function adminCategories(array $filters)
    {
        $query = DestinationCategory::query();
        $this->applyStatus($query, $filters['status'] ?? 'all');

        return $query->orderBy('name')->orderBy('guid')->paginate($filters['per_page'] ?? 15);
    }

    public function categorySave(array $data, ?DestinationCategory $category = null): DestinationCategory
    {
        return DB::transaction(function () use ($data, $category) {
            if ($category) {
                $category = DestinationCategory::whereKey($category->guid)->lockForUpdate()->firstOrFail();
            }
            $category ??= new DestinationCategory;
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $this->assertCategoryUnused($category);
            }
            $category->fill($data)->save();

            return $category;
        }, 3);
    }

    public function destinations(array $filters)
    {
        $query = Destination::with('category')->withCount('reviews as rating_count')->withAvg('reviews as rating_average', 'rating')->where('is_active', true)->whereHas('category', fn ($q) => $q->where('is_active', true));
        $this->applyFilters($query, $filters);

        return $query->paginate($filters['per_page'] ?? 15);
    }

    /** Administrative listing including deactivated destinations. */
    public function adminDestinations(array $filters)
    {
        $query = Destination::with('category')->withCount('reviews as rating_count')->withAvg('reviews as rating_average', 'rating');
        $this->applyStatus($query, $filters['status'] ?? 'all');
        $this->applyFilters($query, $filters);

        return $query->paginate($filters['per_page'] ?? 15);
    }

    private function applyStatus($query, string $status): void
    {
        match ($status) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            default => null,
        };
    }

    private function applyFilters($query, array $filters): void
    {
        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%'.addcslashes($filters['search'], '%_\\').'%');
        }
        if (! empty($filters['category'])) {
            $query->where('destination_category_guid', $filters['category']);
        }
        $sort = $filters['sort'] ?? 'name';
        $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('guid');
    }

    public function destination(Destination $destination): Destination
    {
        $destination->load('category')->loadCount('reviews as rating_count')->loadAvg('reviews as rating_average', 'rating');
        abort_unless($destination->is_active && $destination->category->is_active, 404);

        return $destination;
    }

    public function destinationSave(array $data, ?Destination $destination = null): Destination
    {
        $destination ??= new Destination;
        $image = $data['image'] ?? null;
        unset($data['image']);
        $oldPath = $destination->image_path;
        if (! empty($data['is_active'])) {
            $categoryGuid = $data['destination_category_guid'] ?? $destination->destination_category_guid;
            if ($categoryGuid && ! DestinationCategory::whereKey($categoryGuid)->where('is_active', true)->exists()) {
                throw new BookingException('The destination cannot be active while its category is inactive.', 'category_inactive');
            }
        }
        if ($image instanceof UploadedFile) {
            $path = $image->store('destinations');
            if ($path === false) {
                throw new \RuntimeException('Failed to store destination image.');
            }
            $data['image_path'] = $path;
        }
        try {
            $destination->fill($data)->save();
        } catch (\Throwable $e) {
            if (isset($data['image_path'])) {
                Storage::delete($data['image_path']);
            }
            throw $e;
        }
        // The old file is removed only after the new reference is committed, so a failure never leaves a broken link.
        if ($image && $oldPath) {
            Storage::delete($oldPath);
        }

        return $destination->load('category')->loadCount('reviews as rating_count')->loadAvg('reviews as rating_average', 'rating');
    }

    public function deactivate(Destination|DestinationCategory $model): Destination|DestinationCategory
    {
        if ($model instanceof DestinationCategory) {
            return $this->categorySave(['is_active' => false], $model);
        }
        $model->update(['is_active' => false]);

        return $model->loadCount('reviews as rating_count')->loadAvg('reviews as rating_average', 'rating');
    }

    /** A category cannot be deactivated while active destinations still depend on it. */
    private function assertCategoryUnused(DestinationCategory $category): void
    {
        if ($category->exists && Destination::where('destination_category_guid', $category->guid)->where('is_active', true)->exists()) {
            throw new BookingException('The category is still used by active destinations. Move or deactivate them first.', 'category_in_use');
        }
    }

    /** Streams the stored image; the storage path is never exposed to clients. */
    public function image(Destination $destination, bool $admin = false): Response
    {
        if (! $admin) {
            $this->destination($destination);
        }
        $path = $destination->image_path;
        $disk = Storage::disk();
        if (! $path || ! $disk->exists($path)) {
            throw new ModelNotFoundException;
        }

        return $disk->response($path, null, [
            'Cache-Control' => ($admin ? 'private' : 'public').', max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
