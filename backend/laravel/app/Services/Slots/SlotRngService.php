<?php

namespace App\Services\Slots;

/**
 * Provably-fair RNG: deterministic HMAC-SHA256 stream keyed by the server
 * seed. The server commits sha256(server_seed) before the user funds the bet
 * and reveals server_seed after settlement, so the player can recompute every
 * reel locally and verify the house didn't pick the outcome after seeing the
 * deposit.
 *
 * Stream layout per spin: 9 cells * 4 bytes + 4 bytes jackpot probe.
 */
class SlotRngService
{
    public function newServerSeed(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hashServerSeed(string $serverSeed): string
    {
        return hash('sha256', $serverSeed);
    }

    /**
     * Pick a reels matrix + jackpot probe deterministically.
     *
     * @param  list<array{mint:string,weight:float}>  $weights  normalized; sum == 1.0
     * @return array{reels: array<int, array<int, string>>, jackpotRoll: int}
     */
    public function spin(string $serverSeed, string $clientSeed, int $nonce, array $weights): array
    {
        if ($weights === []) {
            throw new \DomainException('Empty weights');
        }

        $stream = $this->deriveStream($serverSeed, $clientSeed, $nonce, bytes: 9 * 4 + 4);
        $reels = [];

        $cumulative = [];
        $running = 0.0;
        foreach ($weights as $w) {
            $running += $w['weight'];
            $cumulative[] = $running;
        }
        // Guard against floating drift so the last bucket always catches 1.0.
        $cumulative[count($cumulative) - 1] = 1.0;

        $offset = 0;
        for ($row = 0; $row < 3; $row++) {
            $r = [];
            for ($col = 0; $col < 3; $col++) {
                $u32 = $this->readUint32($stream, $offset);
                $offset += 4;
                $r[] = $weights[$this->pickIndex($u32, $cumulative)]['mint'];
            }
            $reels[] = $r;
        }

        $jackpotRoll = $this->readUint32($stream, $offset) % 10_000;

        return ['reels' => $reels, 'jackpotRoll' => $jackpotRoll];
    }

    public function deriveStream(string $serverSeed, string $clientSeed, int $nonce, int $bytes): string
    {
        $message = sprintf('%s:%d', $clientSeed, $nonce);
        $base = hash_hmac('sha256', $message, $serverSeed, true);

        $out = '';
        $counter = 0;
        while (strlen($out) < $bytes) {
            $out .= hash_hmac('sha256', $base.pack('N', $counter), $serverSeed, true);
            $counter++;
        }

        return substr($out, 0, $bytes);
    }

    private function readUint32(string $stream, int $offset): int
    {
        $bytes = substr($stream, $offset, 4);
        $unpacked = unpack('N', $bytes);

        return $unpacked[1];
    }

    /**
     * @param  list<float>  $cumulative
     */
    private function pickIndex(int $u32, array $cumulative): int
    {
        // Use full 32-bit range / 2^32 to map u32 → [0,1).
        $u = $u32 / 4294967296.0;

        $lo = 0;
        $hi = count($cumulative) - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($u < $cumulative[$mid]) {
                $hi = $mid;
            } else {
                $lo = $mid + 1;
            }
        }

        return $lo;
    }
}
