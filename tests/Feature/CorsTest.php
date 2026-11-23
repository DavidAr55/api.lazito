<?php

beforeEach(function () {
    config(['cors.allowed_origins' => ['https://intercambio.example']]);
});

it('lets the configured frontend origin call the API', function () {
    $this->withHeaders(['Origin' => 'https://intercambio.example', 'Access-Control-Request-Method' => 'POST'])
        ->options('/api/v1/groups')
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://intercambio.example');
});

it('never echoes an origin that is not allowed', function () {
    $response = $this->withHeaders(['Origin' => 'https://otro.example', 'Access-Control-Request-Method' => 'POST'])
        ->options('/api/v1/groups');

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://otro.example');
});
