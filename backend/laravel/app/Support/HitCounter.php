<?php

namespace App\Support;

/**
 * "You are visitor number 0000042" — as an image, the way it always was.
 *
 * An image because that is the one thing a sandboxed homepage can embed from
 * another host without asking anybody: `<img src>` needs no CORS, no script
 * and no cookie, and works in a page written in 1997.
 */
final class HitCounter
{
    private const DIGITS = 7;

    private const CELL_WIDTH = 18;

    private const CELL_HEIGHT = 26;

    public static function svg(int $hits): string
    {
        $digits = str_pad((string) max(0, min($hits, 9_999_999)), self::DIGITS, '0', STR_PAD_LEFT);
        $width = self::DIGITS * self::CELL_WIDTH + 4;
        $height = self::CELL_HEIGHT + 4;
        $cells = '';

        foreach (str_split($digits) as $index => $digit) {
            $x = 2 + $index * self::CELL_WIDTH;
            $cells .= sprintf(
                '<rect x="%d" y="2" width="%d" height="%d" fill="url(#drum)" stroke="#2b2b2b"/>'
                .'<text x="%d" y="%d" text-anchor="middle" font-family="ui-monospace, Menlo, Consolas, monospace" font-size="19" font-weight="700" fill="#7dff7a">%s</text>',
                $x,
                self::CELL_WIDTH - 2,
                self::CELL_HEIGHT,
                $x + (self::CELL_WIDTH - 2) / 2,
                self::CELL_HEIGHT - 4,
                $digit,
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" role="img" aria-label="%3$s visitors">'
            .'<defs><linearGradient id="drum" x1="0" y1="0" x2="0" y2="1">'
            .'<stop offset="0" stop-color="#000"/><stop offset="0.5" stop-color="#1c1c1c"/><stop offset="1" stop-color="#000"/>'
            .'</linearGradient></defs><rect width="%1$d" height="%2$d" fill="#000"/>%4$s</svg>',
            $width,
            $height,
            $digits,
            $cells,
        );
    }
}
