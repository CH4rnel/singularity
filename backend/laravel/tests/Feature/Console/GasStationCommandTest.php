<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** ABI word from a decimal string, mirroring the sponsor feature test. */
function stationWord(string|int $value): string
{
    $decimal = (string) $value;
    $hex = '';

    while (bccomp($decimal, '0') > 0) {
        $hex = dechex((int) bcmod($decimal, '16')).$hex;
        $decimal = bcdiv($decimal, '16', 0);
    }

    return str_pad($hex === '' ? '0' : $hex, 64, '0', STR_PAD_LEFT);
}

function stationSummary(string $tank = '100000000000000000000', int $paused = 0): string
{
    return '0x'.implode('', array_map(stationWord(...), [
        $tank,
        '10000000000000000',
        '1000000000000000',
        21600,
        '5000000000000000000',
        '5000000000000000000',
        7,
        '70000000000000000',
        $paused,
    ]));
}

beforeEach(function () {
    config()->set('wallet.sponsor.enabled', true);
    config()->set('wallet.sponsor.station', '0x00000000000000000000000000000000000000f0');
    config()->set('wallet.sponsor.private_key', 'configured-elsewhere');
    config()->set('bridge.chains.cyberia.rpc_url', 'https://rpc.cyberia.church');

    Cache::flush();
});

it('reports the tank and what it has served', function () {
    Http::fake(['rpc.cyberia.church' => Http::response([
        'jsonrpc' => '2.0', 'id' => 1, 'result' => stationSummary(),
    ])]);

    $this->artisan('gas:station')
        ->expectsOutputToContain('10000 drips left')
        ->expectsOutputToContain('Lifetime: 7 drips')
        ->assertExitCode(0);
});

it('warns when the tank is nearly dry', function () {
    // Ten drips left, against a low-water mark of fifty.
    Http::fake(['rpc.cyberia.church' => Http::response([
        'jsonrpc' => '2.0', 'id' => 1, 'result' => stationSummary('100000000000000000'),
    ])]);

    $this->artisan('gas:station')
        ->expectsOutputToContain('Tank low: 10 drips left')
        ->assertExitCode(0);
});

it('separates an unreadable station from an empty one', function () {
    Http::fake(['rpc.cyberia.church' => Http::response('', 500)]);

    $this->artisan('gas:station')
        ->expectsOutputToContain('RPC problem, not an empty tank')
        ->assertExitCode(1);
});

it('names the missing setting when sponsorship is off', function () {
    config()->set('wallet.sponsor.station', '');

    $this->artisan('gas:station')
        ->expectsOutputToContain('WALLET_GAS_STATION_ADDRESS is unset')
        ->assertExitCode(0);
});
