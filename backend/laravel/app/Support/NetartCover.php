<?php

namespace App\Support;

/**
 * A generated cover for a token in the underground: net art from its address.
 *
 * Every token gets one whether or not it has a homepage, so a vacant lot is a
 * picture and not a grey box. The address is the seed and the only input
 * besides the label, which makes a cover a pure function — the same token
 * draws the same picture on the directory, in the wallet and inside somebody's
 * homepage, and it can be cached forever.
 *
 * The six styles are the wallet gallery's (`lib/wallet/netart.ts`) redrawn as
 * vector: rings, glyphs, wires, moiré, a burst and static.
 */
final class NetartCover
{
    private const WIDTH = 400;

    private const HEIGHT = 300;

    private const INK = '#e8e4dc';

    private const VOID = '#07080b';

    private const RED = '#d7263d';

    private const AQUA = '#72dccf';

    private const VIOLET = '#8b7bf7';

    private const AMBER = '#e8b44a';

    /** @var array<int, string> */
    public const STYLES = ['rings', 'glyphs', 'wires', 'moire', 'burst', 'static'];

    private int $draws = 0;

    private function __construct(private readonly string $seed) {}

    public static function svg(string $seed, string $label = ''): string
    {
        return (new self(strtolower($seed)))->render($label);
    }

    /** Which of the styles a seed draws, for tests and for whoever is curious. */
    public static function styleFor(string $seed): string
    {
        return (new self(strtolower($seed)))->pick(self::STYLES);
    }

    private function render(string $label): string
    {
        $style = $this->pick(self::STYLES);
        $body = match ($style) {
            'rings' => $this->rings(),
            'glyphs' => $this->glyphs(),
            'wires' => $this->wires(),
            'moire' => $this->moire(),
            'burst' => $this->burst(),
            default => $this->static(),
        };

        $title = $label === '' ? 'net art' : $label;
        $tag = $label === '' ? '' : sprintf(
            '<rect x="0" y="%d" width="%d" height="34" fill="rgba(5,6,7,0.82)"/>'
            .'<text x="14" y="%d" font-family="ui-monospace, Menlo, Consolas, monospace" font-size="17" letter-spacing="2" fill="%s">%s</text>',
            self::HEIGHT - 34,
            self::WIDTH,
            self::HEIGHT - 11,
            self::AQUA,
            $this->escape($label),
        );

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" role="img" aria-label="%3$s">'
            .'<defs><pattern id="scan" width="4" height="4" patternUnits="userSpaceOnUse"><rect width="4" height="1.5" fill="rgba(0,0,0,0.22)"/></pattern></defs>'
            .'%4$s<rect width="%1$d" height="%2$d" fill="url(#scan)"/>%5$s</svg>',
            self::WIDTH,
            self::HEIGHT,
            $this->escape($title),
            $body,
            $tag,
        );
    }

    /* ---------------------------------------------------------- styles --- */

    /** Rings with pieces missing, and one red arc that matters. */
    private function rings(): string
    {
        $out = $this->background(self::VOID);
        $cx = self::WIDTH / 2;
        $cy = self::HEIGHT / 2;

        for ($ring = 0; $ring < 30; $ring++) {
            $radius = $this->between(8, 140);
            $start = $this->between(0, M_PI * 2);
            $sweep = $this->between(0.3, M_PI * 1.8);
            $end = $start + $sweep;
            $color = $this->random() < 0.08 ? self::RED : ($this->random() < 0.4 ? self::AQUA : self::INK);

            $out .= sprintf(
                '<path d="M %.1f %.1f A %.1f %.1f 0 %d 1 %.1f %.1f" fill="none" stroke="%s" stroke-width="%.1f" opacity="%.2f"/>',
                $cx + cos($start) * $radius,
                $cy + sin($start) * $radius,
                $radius,
                $radius,
                $sweep > M_PI ? 1 : 0,
                $cx + cos($end) * $radius,
                $cy + sin($end) * $radius,
                $color,
                $this->between(0.6, 4.5),
                $this->between(0.35, 1),
            );
        }

        return $out;
    }

    /** An alphabet nobody taught: strokes on a 3×3 lattice, cell by cell. */
    private function glyphs(): string
    {
        $out = $this->background('#0d0e12');
        $cell = 50;

        for ($row = 0; $row < self::HEIGHT / $cell; $row++) {
            for ($column = 0; $column < self::WIDTH / $cell; $column++) {
                $color = $this->random() < 0.07 ? self::RED : self::INK;
                $strokes = 2 + (int) floor($this->random() * 3);

                for ($stroke = 0; $stroke < $strokes; $stroke++) {
                    [$ax, $ay] = $this->lattice($column, $row, $cell);
                    [$bx, $by] = $this->lattice($column, $row, $cell);
                    $out .= sprintf(
                        '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="4" stroke-linecap="square" opacity="%.2f"/>',
                        $ax, $ay, $bx, $by, $color, $this->between(0.55, 1),
                    );
                }
            }
        }

        return $out;
    }

    /** Poles and the wires between them, against a sky the colour of paper. */
    private function wires(): string
    {
        $horizon = $this->random() < 0.5 ? '#c96a5a' : '#b9b0a0';
        $out = '<defs><linearGradient id="sky" x1="0" y1="0" x2="0" y2="1">'
            .'<stop offset="0" stop-color="#f1ede4"/><stop offset="0.75" stop-color="#d9d2c4"/>'
            .'<stop offset="1" stop-color="'.$horizon.'"/></linearGradient></defs>'
            .'<rect width="'.self::WIDTH.'" height="'.self::HEIGHT.'" fill="url(#sky)"/>';

        $out .= sprintf(
            '<circle cx="%.1f" cy="%.1f" r="18" fill="%s" opacity="0.85"/>',
            $this->between(60, 340),
            $this->between(40, 110),
            self::RED,
        );

        $poles = [];
        $x = -$this->between(0, 30);

        while ($x < self::WIDTH + 60) {
            $top = $this->between(40, 130);
            $poles[] = ['x' => $x, 'arms' => [$top + 10, $top + 24], 'top' => $top];
            $x += $this->between(90, 170);
        }

        foreach ($poles as $pole) {
            $out .= sprintf('<line x1="%.1f" y1="%d" x2="%.1f" y2="%.1f" stroke="#111" stroke-width="5"/>', $pole['x'], self::HEIGHT, $pole['x'], $pole['top']);

            foreach ($pole['arms'] as $arm) {
                $out .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#111" stroke-width="2.5"/>', $pole['x'] - 26, $arm, $pole['x'] + 26, $arm);
            }
        }

        for ($index = 0; $index < count($poles) - 1; $index++) {
            $left = $poles[$index];
            $right = $poles[$index + 1];

            foreach ([[0, -22], [0, 22], [1, -18], [1, 0], [1, 18]] as [$arm, $offset]) {
                $x1 = $left['x'] + $offset;
                $y1 = $left['arms'][$arm];
                $x2 = $right['x'] + $offset;
                $y2 = $right['arms'][$arm];
                $sag = $this->between(12, 34);
                $out .= sprintf(
                    '<path d="M %.1f %.1f Q %.1f %.1f %.1f %.1f" fill="none" stroke="#111" stroke-width="0.9"/>',
                    $x1, $y1, ($x1 + $x2) / 2, ($y1 + $y2) / 2 + $sag * 2, $x2, $y2,
                );
            }
        }

        return $out;
    }

    /** Two sets of rings a hair apart. The pattern is in neither of them. */
    private function moire(): string
    {
        $out = $this->background(self::VOID);
        $gap = $this->between(4.5, 7);
        $sets = [
            [self::WIDTH / 2, self::HEIGHT / 2, self::INK],
            [
                self::WIDTH / 2 + $this->between(-30, 30),
                self::HEIGHT / 2 + $this->between(-30, 30),
                $this->random() < 0.5 ? self::RED : self::VIOLET,
            ],
        ];

        foreach ($sets as [$x, $y, $color]) {
            $out .= sprintf('<g fill="none" stroke="%s" stroke-width="%.2f">', $color, $gap * 0.45);

            for ($radius = $gap; $radius < 260; $radius += $gap) {
                $out .= sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f"/>', $x, $y, $radius);
            }

            $out .= '</g>';
        }

        return $out;
    }

    /** A burst from the centre, printed three times out of register. */
    private function burst(): string
    {
        $out = $this->background(self::VOID);
        $rays = [];

        for ($ray = 0; $ray < 110; $ray++) {
            $rays[] = [$this->between(0, M_PI * 2), $this->between(6, 40), $this->between(50, 230), $this->between(0.4, 2.4)];
        }

        foreach ([['#ff2846', -3], ['#28ffc8', 3], ['#7878ff', 0]] as [$color, $dx]) {
            $out .= sprintf('<g stroke="%s" opacity="0.7" style="mix-blend-mode:screen">', $color);

            foreach ($rays as [$angle, $from, $to, $width]) {
                $out .= sprintf(
                    '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke-width="%.1f"/>',
                    self::WIDTH / 2 + $dx + cos($angle) * $from,
                    self::HEIGHT / 2 + sin($angle) * $from,
                    self::WIDTH / 2 + $dx + cos($angle) * $to,
                    self::HEIGHT / 2 + sin($angle) * $to,
                    $width,
                );
            }

            $out .= '</g>';
        }

        return $out;
    }

    /** Static, torn by rows that slipped sideways. */
    private function static(): string
    {
        $out = $this->background('#111');
        $block = 12.5;

        for ($y = 0; $y < self::HEIGHT; $y += $block) {
            for ($x = 0; $x < self::WIDTH; $x += $block) {
                $grey = (int) (18 + $this->random() * 75);
                $out .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="rgb(%d,%d,%d)"/>', $x, $y, $block, $block, $grey, $grey, (int) ($grey * 1.06));
            }
        }

        for ($tear = 0; $tear < 6; $tear++) {
            $out .= sprintf(
                '<rect x="0" y="%.1f" width="%d" height="%.1f" fill="%s" opacity="%.2f"/>',
                $this->between(0, self::HEIGHT),
                self::WIDTH,
                $this->between(2, 12),
                $this->pick([self::AQUA, self::RED, self::INK, self::AMBER]),
                $this->between(0.12, 0.4),
            );
        }

        return $out;
    }

    /* -------------------------------------------------------- plumbing --- */

    private function background(string $color): string
    {
        return sprintf('<rect width="%d" height="%d" fill="%s"/>', self::WIDTH, self::HEIGHT, $color);
    }

    /** @return array{0: float, 1: float} */
    private function lattice(int $column, int $row, int $cell): array
    {
        return [
            $column * $cell + $cell * (0.22 + floor($this->random() * 3) * 0.28),
            $row * $cell + $cell * (0.22 + floor($this->random() * 3) * 0.28),
        ];
    }

    /**
     * A stream of numbers in [0, 1) that only the seed decides: each draw is
     * a hash of the seed and its position, so no state outlives one cover.
     */
    private function random(): float
    {
        $this->draws++;

        return hexdec(substr(hash('sha256', $this->seed.':'.$this->draws), 0, 8)) / 4294967296;
    }

    private function between(float $low, float $high): float
    {
        return $low + $this->random() * ($high - $low);
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[(int) floor($this->random() * count($items))];
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
