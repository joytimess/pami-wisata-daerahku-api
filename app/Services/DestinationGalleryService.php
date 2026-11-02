<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Http\Resources\DestinationResource;
use App\Models\Destination;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DestinationGalleryService
{
    public function __construct(private DestinationImageProcessor $processor) {}

    /** @return array<string, mixed> */
    public function save(array $data, User $actor, ?Destination $destination = null): array
    {
        $files = $data['new_images'] ?? $data['images'] ?? (isset($data['image']) ? [$data['image']] : []);
        $manifest = $data['gallery_manifest'] ?? array_map(fn ($key) => ['upload_key' => (string) $key], array_keys($files));
        if (count($manifest) < 1 || count($manifest) > 10) {
            throw ValidationException::withMessages(['images' => 'A gallery must contain 1 to 10 images.']);
        }
        $payload = Arr::except($data, ['new_images', 'images', 'image', 'operation_key']);
        $payload['resource'] = $destination?->guid;
        $payload['files'] = array_map(fn ($file) => hash_file('sha256', $file->getRealPath()), $files);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $key = $data['operation_key'] ?? null;
        if ($key) {
            $operation = DB::table('destination_gallery_operations')->where('actor_guid', $actor->guid)->where('operation_key', $key)->first();
            if ($operation) {
                $currentActor = User::whereKey($actor->guid)->firstOrFail();
                abort_unless($currentActor->status === 'active' && (int) $currentActor->role >= 1, 403);
                if ($destination) {
                    Gate::forUser($currentActor)->authorize('updateGallery', $destination);
                }
                if ($operation->payload_hash !== $hash) {
                    throw new BookingException('Idempotency key belongs to a different save.', 'gallery_idempotency_conflict');
                }

                return json_decode($operation->response, true, flags: JSON_THROW_ON_ERROR);
            }
        }
        $staged = [];
        try {
            foreach ($files as $uploadKey => $file) {
                $staged[(string) $uploadKey] = $this->processor->process($file);
            }

            return DB::transaction(function () use ($data, $actor, $destination, $manifest, $hash, $key, $staged): array {
                $lockedActor = User::whereKey($actor->guid)->lockForUpdate()->firstOrFail();
                abort_unless($lockedActor->status === 'active' && (int) $lockedActor->role >= 1, 403);
                if ($key) {
                    $operation = DB::table('destination_gallery_operations')->where('actor_guid', $actor->guid)->where('operation_key', $key)->first();
                    if ($operation) {
                        if ($operation->payload_hash !== $hash) {
                            throw new BookingException('Idempotency key belongs to a different save.', 'gallery_idempotency_conflict');
                        }
                        foreach ($staged as $image) {
                            $this->processor->discard($image);
                        }

                        return json_decode($operation->response, true, flags: JSON_THROW_ON_ERROR);
                    }
                }
                $model = $destination ? Destination::whereKey($destination->guid)->lockForUpdate()->firstOrFail() : new Destination;
                if ($destination) {
                    Gate::forUser($lockedActor)->authorize('updateGallery', $model);
                    if (isset($data['gallery_version']) && (int) $data['gallery_version'] !== (int) $model->gallery_version) {
                        throw new BookingException('The gallery changed. Refresh before saving again.', 'gallery_version_conflict');
                    }
                }
                $existing = $model->exists ? $model->images()->get()->keyBy('guid') : collect();
                $guids = array_column($manifest, 'image_guid');
                if (count($guids) !== count(array_unique($guids)) || array_diff($guids, $existing->keys()->all())) {
                    throw ValidationException::withMessages(['gallery_manifest' => 'Images must belong to this destination and appear once.']);
                }
                app(CatalogService::class)->destinationSave(Arr::except($data, ['new_images', 'images', 'image', 'gallery_manifest', 'gallery_version', 'operation_key']), $model);
                $model->refresh();
                $model->images()->increment('sequence', 100);
                foreach ($manifest as $index => $entry) {
                    if (isset($entry['image_guid'])) {
                        $model->images()->whereKey($entry['image_guid'])->update(['sequence' => $index + 1]);
                    } else {
                        $image = $staged[$entry['upload_key']] ?? null;
                        if (! $image) {
                            throw ValidationException::withMessages(['new_images' => 'Missing upload.']);
                        }
                        $model->images()->create($image + ['sequence' => $index + 1]);
                        foreach (['thumbnail_path', 'display_path', 'fullscreen_path'] as $field) {
                            DB::table('destination_image_cleanups')->where('storage_disk', $image['storage_disk'])->where('path', $image[$field])->delete();
                        }
                    }
                }
                foreach ($existing as $guid => $image) {
                    if (! in_array($guid, $guids, true)) {
                        foreach (['thumbnail_path', 'display_path', 'fullscreen_path'] as $field) {
                            DB::table('destination_image_cleanups')->insertOrIgnore(['storage_disk' => $image->storage_disk, 'path' => $image->$field, 'eligible_at' => now()]);
                        }
                        $image->delete();
                    }
                }
                $cover = $model->images()->first();
                $model->image_path = $cover->display_path;
                $model->gallery_version = (int) $model->gallery_version + 1;
                $model->last_gallery_operation_key = $key;
                $model->save();
                $model->load(['images', 'category', 'activeFacilities'])->loadCount('reviews as rating_count')->loadAvg('reviews as rating_average', 'rating');
                $response = (new DestinationResource($model))->resolve();
                if ($key) {
                    DB::table('destination_gallery_operations')->insert(['actor_guid' => $actor->guid, 'operation_key' => $key, 'payload_hash' => $hash, 'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now()]);
                }

                return $response;
            }, 3);
        } catch (\Throwable $exception) {
            foreach ($staged as $image) {
                $this->processor->discard($image);
            }
            throw $exception;
        }
    }
}
