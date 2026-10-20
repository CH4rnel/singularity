<?php

namespace App\Services\Contributions;

use Brick\Math\BigInteger;
use InvalidArgumentException;
use kornrunner\Keccak;

/**
 * The tree CyberiaContributorRewards verifies, built the way OpenZeppelin's
 * StandardMerkleTree builds it, so the same leaves give the same root in
 * PHP, in `@openzeppelin/merkle-tree` and in `MerkleProof.verify`.
 *
 *   leaf = keccak256(bytes.concat(keccak256(abi.encode(uint256 epoch, address account, uint256 amount))))
 *
 * Double hashing keeps a leaf from ever being mistaken for an inner node (a
 * 64-byte preimage), and the epoch inside the leaf means a proof is never
 * valid for any root but its own. Leaves are sorted, the tree is a complete
 * binary tree stored as an array, and pairs hash in sorted order — the three
 * choices StandardMerkleTree makes, pinned by `ContributionMerkleTreeTest`.
 */
class MerkleTree
{
    /** @var list<string> hex without 0x, tree[0] is the root */
    private array $tree = [];

    /** @var array<string, int> leaf hex => position in */
    private array $positions = [];

    /**
     * @param  list<string>  $leaves  0x-prefixed 32-byte hashes
     */
    public function __construct(array $leaves)
    {
        if ($leaves === []) {
            throw new InvalidArgumentException('A tree needs at least one leaf.');
        }

        $hex = array_map(fn (string $leaf): string => strtolower(substr($leaf, 2)), $leaves);
        sort($hex, SORT_STRING);

        $size = 2 * count($hex) - 1;
        $tree = array_fill(0, $size, '');

        foreach ($hex as $i => $leaf) {
            $tree[$size - 1 - $i] = $leaf;
            $this->positions[$leaf] = $size - 1 - $i;
        }

        for ($i = $size - 1 - count($hex); $i >= 0; $i--) {
            $tree[$i] = self::hashPair($tree[2 * $i + 1], $tree[2 * $i + 2]);
        }

        $this->tree = $tree;
    }

    public static function leaf(int $epoch, string $account, string $amountRaw): string
    {
        $encoded = self::word(dechex($epoch))
            .self::word(strtolower(substr($account, 2)))
            .self::word(BigInteger::of($amountRaw)->toBase(16));

        $inner = Keccak::hash((string) hex2bin($encoded), 256);

        return '0x'.Keccak::hash((string) hex2bin($inner), 256);
    }

    public function root(): string
    {
        return '0x'.$this->tree[0];
    }

    /**
     * @return list<string>
     */
    public function proof(string $leaf): array
    {
        $index = $this->positions[strtolower(substr($leaf, 2))] ?? null;

        if ($index === null) {
            throw new InvalidArgumentException('Leaf is not in the tree.');
        }

        $proof = [];

        while ($index > 0) {
            $sibling = $index % 2 === 1 ? $index + 1 : $index - 1;
            $proof[] = '0x'.$this->tree[$sibling];
            $index = intdiv($index - 1, 2);
        }

        return $proof;
    }

    /**
     * @param  list<string>  $proof
     */
    public static function verify(string $root, string $leaf, array $proof): bool
    {
        $node = strtolower(substr($leaf, 2));

        foreach ($proof as $sibling) {
            $node = self::hashPair($node, strtolower(substr($sibling, 2)));
        }

        return '0x'.$node === strtolower($root);
    }

    private static function hashPair(string $a, string $b): string
    {
        [$low, $high] = strcmp($a, $b) <= 0 ? [$a, $b] : [$b, $a];

        return Keccak::hash((string) hex2bin($low.$high), 256);
    }

    private static function word(string $hex): string
    {
        if (strlen($hex) > 64 || preg_match('/^[0-9a-f]*$/', $hex) !== 1) {
            throw new InvalidArgumentException('Not an ABI word.');
        }

        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }
}
