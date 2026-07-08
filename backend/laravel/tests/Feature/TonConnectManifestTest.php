<?php

it('serves the TON Connect manifest from the canonical bridge app URL', function () {
    $this->get('/tonconnect-manifest.json')
        ->assertOk()
        ->assertHeader('content-type', 'application/json')
        ->assertExactJson([
            'url' => 'https://cyberia.church/bridge',
            'name' => 'Cyberia Bridge',
            'iconUrl' => 'https://cyberia.church/apple-touch-icon.png',
            'termsOfUseUrl' => 'https://cyberia.church',
            'privacyPolicyUrl' => 'https://cyberia.church',
        ]);
});
