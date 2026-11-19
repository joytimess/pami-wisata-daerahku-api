<?php

test('the public homepage directs visitors to Wisata Daerahku', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSeeText('Buka aplikasi Wisata Daerahku')
        ->assertDontSeeText('Laracasts');
});
