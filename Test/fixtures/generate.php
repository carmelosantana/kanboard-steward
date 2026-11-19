<?php
/**
 * Regenerates the hand-crafted image fixtures in this folder (php Test/fixtures/generate.php).
 * Ordinary PNG/JPEG/WebP/GIF inputs are generated in-test with GD, not committed.
 */
$dir = __DIR__;

function chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function tinyPng(): string
{
    $im = imagecreatetruecolor(1, 1);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
    ob_start();
    imagepng($im);
    return ob_get_clean();
}

// SVG: text that getimagesize can't size.
file_put_contents("$dir/logo.svg", '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"><rect width="16" height="16" fill="red"/></svg>' . "\n");

// ICO: one 1x1 32-bit BMP-encoded entry.
$bmp = pack('VVVvvVVVVVV', 40, 1, 2, 1, 32, 0, 8, 0, 0, 0, 0) . "\x00\x00\xff\xff" . "\x00\x00\x00\x00";
$ico = pack('vvv', 0, 1, 1) . pack('CCCCvvVV', 1, 1, 0, 0, 1, 32, strlen($bmp), 22) . $bmp;
file_put_contents("$dir/favicon.ico", $ico);

// Polyglot: a valid 1x1 PNG with a <script> in a tEXt chunk and after IEND.
$png = tinyPng();
$iend = strrpos($png, 'IEND') - 4;
$polyglot = substr($png, 0, $iend)
    . chunk('tEXt', "Comment\0<script>alert(1)</script>")
    . substr($png, $iend)
    . '<script>alert(document.cookie)</script>';
file_put_contents("$dir/polyglot.png", $polyglot);

// Decompression bomb header: a real 1x1 PNG whose IHDR claims 50000x50000 (CRC fixed up).
$png = tinyPng();
$ihdr = substr($png, 16, 13);
$ihdr = pack('NN', 50000, 50000) . substr($ihdr, 8);
file_put_contents("$dir/bomb-header.png", substr($png, 0, 8) . chunk('IHDR', $ihdr) . substr($png, 33));
