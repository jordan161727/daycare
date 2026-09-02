<?php

/*
 * Build the favicon set from public/images/icon-angels-light.png.
 *
 *     php tools/generate-icons.php
 *
 * The source is a 512px square, but the angels only occupy 409x297 of it —
 * there is more than a hundred pixels of transparent margin above and below.
 * Scaled straight down, that margin is what a 16px tab spends half its height
 * on, and the mark arrives as a smudge. So the artwork is trimmed to its own
 * bounding box first and then scaled to fill the icon, which is the whole
 * difference between a legible tab and a stain on it.
 */

$dir = getcwd().'/public/images';
$source = imagecreatefrompng($dir.'/icon-angels-light.png');

/** The box the non-transparent pixels actually occupy. */
function artworkBounds(GdImage $image): array
{
    [$w, $h] = [imagesx($image), imagesy($image)];
    [$minX, $minY, $maxX, $maxY] = [$w, $h, -1, -1];

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            // 127 is fully transparent; anything under 120 is ink worth keeping.
            if ((imagecolorat($image, $x, $y) >> 24 & 0x7F) < 120) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }
    }

    return [$minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1];
}

[$srcX, $srcY, $srcW, $srcH] = artworkBounds($source);

/** Trimmed artwork, scaled to fill a square canvas. */
function render(GdImage $source, array $box, int $size, ?array $ground): GdImage
{
    [$srcX, $srcY, $srcW, $srcH] = $box;

    $out = imagecreatetruecolor($size, $size);

    if ($ground === null) {
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagealphablending($out, true);
    } else {
        imagefill($out, 0, 0, imagecolorallocate($out, ...$ground));
    }

    // Barely any: enough that the mark does not touch the tab's edge, not so
    // much that it shrinks again.
    $pad = (int) round($size * 0.02);
    $box = $size - 2 * $pad;

    // Fit the longer side, so a wide mark keeps its proportions rather than
    // being squashed square.
    $scale = min($box / $srcW, $box / $srcH);
    $w = (int) round($srcW * $scale);
    $h = (int) round($srcH * $scale);

    imagecopyresampled($out, $source, intdiv($size - $w, 2), intdiv($size - $h, 2), $srcX, $srcY, $w, $h, $srcW, $srcH);

    return $out;
}

// 16/32/48 keep their transparency. The 180 is Apple's, and gets a white
// ground: iOS composites a transparent touch icon onto black, which would lose
// pale blue entirely.
foreach ([16 => null, 32 => null, 48 => null, 180 => [255, 255, 255]] as $size => $ground) {
    $image = render($source, [$srcX, $srcY, $srcW, $srcH], $size, $ground);
    imagepng($image, "$dir/icon-angels-light-{$size}.png", 9);
    imagedestroy($image);

    printf("icon-angels-light-%d.png%s %d bytes\n", $size, str_repeat(' ', 8 - strlen((string) $size)), filesize("$dir/icon-angels-light-{$size}.png"));
}

/*
 * A real .ico, so the /favicon.ico a browser asks for by convention is this
 * icon too and not whatever was there before. PNG-payload entries, which every
 * browser since IE11 reads.
 */
$entries = [];

foreach ([16, 32, 48] as $size) {
    $entries[$size] = file_get_contents("$dir/icon-angels-light-{$size}.png");
}

$ico = pack('vvv', 0, 1, count($entries));
$offset = 6 + 16 * count($entries);

foreach ($entries as $size => $png) {
    $ico .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($png), $offset);
    $offset += strlen($png);
}

file_put_contents(getcwd().'/public/favicon.ico', $ico.implode('', $entries));

printf("favicon.ico          %d bytes (16, 32, 48)\n", filesize(getcwd().'/public/favicon.ico'));
printf("\nartwork trimmed from 512x512 to %dx%d before scaling\n", $srcW, $srcH);
