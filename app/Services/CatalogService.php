<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\DestinationCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CatalogService
{
    public function categories(array $filters)
    {
        return DestinationCategory::where('is_active', true)->orderBy('name')->paginate($filters['per_page'] ?? 15);
    }

    public function categorySave(array $data, ?DestinationCategory $category = null): DestinationCategory
    {
        $category ??= new DestinationCategory;
        $category->fill($data)->save();

        return $category;
    }

    public function destinations(array $filters)
    {
        $query = Destination::with('category')->where('is_active', true)->whereHas('category', fn ($q) => $q->where('is_active', true));
        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }
        if (! empty($filters['category'])) {
            $query->where('destination_category_guid', $filters['category']);
        }
        $sort = $filters['sort'] ?? 'name';

        return $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('guid')->paginate($filters['per_page'] ?? 15);
    }

    public function destination(Destination $destination): Destination
    {
        $destination->load('category');
        abort_unless($destination->is_active && $destination->category->is_active, 404);

        return $destination;
    }

    public function destinationSave(array $data, ?Destination $destination = null): Destination
    {
        $destination ??= new Destination;
        $image = $data['image'] ?? null;
        unset($data['image']);
        $oldPath = $destination->image_path;
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
        if ($image && $oldPath) {
            Storage::delete($oldPath);
        }

        return $destination->load('category');
    }

    public function deactivate(Destination|DestinationCategory $model): Destination|DestinationCategory
    {
        $model->update(['is_active' => false]);

        return $model;
    }
}
