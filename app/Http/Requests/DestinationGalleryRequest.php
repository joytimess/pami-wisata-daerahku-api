<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class DestinationGalleryRequest extends CatalogRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('gallery_manifest') && is_string($this->input('gallery_manifest'))) {
            $this->merge(['gallery_manifest' => json_decode($this->input('gallery_manifest'), true)]);
        }
        if ($this->header('Idempotency-Key')) {
            $this->merge(['operation_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function rules(): array
    {
        abort_if((int) $this->server('CONTENT_LENGTH', 0) > 55 * 1024 * 1024, 413, 'Gallery request exceeds 55 MiB.');
        $rules = $this->routeIs('destinations.image.update') ? [] : parent::rules();
        $create = $this->routeIs('destinations.store');
        $mutation = $create || $this->has('gallery_manifest') || $this->hasFile('image') || $this->hasFile('images') || $this->hasFile('new_images');
        if (! $mutation) {
            return $rules;
        }
        $file = ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120'];

        return array_merge($rules, [
            'image' => ['sometimes', ...$file],
            'images' => [$create && ! $this->hasFile('image') ? 'required' : 'sometimes', 'array', 'min:1', 'max:10'],
            'images.*' => $file,
            'gallery_version' => [$create ? 'sometimes' : 'required', 'integer', 'min:0'],
            'operation_key' => ['required', 'uuid'],
            'gallery_manifest' => [$create || $this->hasFile('image') ? 'sometimes' : 'required', 'array', 'list', 'min:1', 'max:10'],
            'gallery_manifest.*' => ['array:image_guid,upload_key'],
            'gallery_manifest.*.image_guid' => ['required_without:gallery_manifest.*.upload_key', 'prohibits:gallery_manifest.*.upload_key', 'uuid', 'distinct'],
            'gallery_manifest.*.upload_key' => ['required_without:gallery_manifest.*.image_guid', 'string', 'regex:/^[a-zA-Z0-9_-]{1,64}$/', 'distinct'],
            'new_images' => ['sometimes', 'array', 'max:10'],
            'new_images.*' => $file,
        ]);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $manifest = $this->input('gallery_manifest');
            if (is_array($manifest)) {
                $keys = array_column($manifest, 'upload_key');
                $files = array_keys($this->file('new_images', []));
                sort($keys);
                sort($files);
                if ($keys !== $files) {
                    $validator->errors()->add('new_images', 'Every upload must match exactly one manifest entry.');
                }
            }
            if ($this->hasFile('new_images') && ! $this->has('gallery_manifest')) {
                $validator->errors()->add('new_images', 'New uploads require a gallery manifest.');
            }
            if (($this->hasFile('image') && ($this->hasFile('images') || $this->has('gallery_manifest'))) || ($this->hasFile('images') && $this->has('gallery_manifest'))) {
                $validator->errors()->add('images', 'Use only one gallery upload format.');
            }
        }];
    }
}
