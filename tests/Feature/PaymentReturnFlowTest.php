<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const RETURN_FINGERPRINT = '14:6D:E9:83:C5:73:06:50:D8:EE:B9:95:2F:34:FC:64:16:A0:83:42:E6:1D:BE:A8:8A:04:96:B2:3F:CF:44:E5';

beforeEach(function () {
    config(['mobile.android.package' => 'id.wisatadaerahku.app']);
});

it('no longer serves the retired e-wallet payment return page', function () {
    $this->get('/payment/return')->assertNotFound();
});

it('publishes Android app link association only when fingerprints are configured', function () {
    config(['mobile.android.sha256_fingerprints' => []]);
    $this->getJson('/.well-known/assetlinks.json')->assertNotFound();

    config(['mobile.android.sha256_fingerprints' => [RETURN_FINGERPRINT, 'not-a-fingerprint', strtolower(RETURN_FINGERPRINT)]]);
    $response = $this->get('/.well-known/assetlinks.json')->assertOk()->assertHeader('Content-Type', 'application/json');

    expect($response->json())->toBe([[
        'relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => ['namespace' => 'android_app', 'package_name' => 'id.wisatadaerahku.app', 'sha256_cert_fingerprints' => [RETURN_FINGERPRINT]],
    ]]);
});
