<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Uso: php scripts/prepare-brand-logo.php <origen.webp> <destino.png>\n");
    exit(1);
}

[$script, $sourcePath, $destinationPath] = $argv;

$source = imagecreatefromwebp($sourcePath);

if ($source === false) {
    fwrite(STDERR, "No se pudo abrir el archivo WebP de origen.\n");
    exit(1);
}

$width = imagesx($source);
$height = imagesy($source);
$pixelCount = $width * $height;
$visited = str_repeat("\0", $pixelCount);
$queue = new SplQueue();

$isExteriorWhite = static function (int $color): bool {
    $red = ($color >> 16) & 0xFF;
    $green = ($color >> 8) & 0xFF;
    $blue = $color & 0xFF;

    return min($red, $green, $blue) >= 225
        && max($red, $green, $blue) - min($red, $green, $blue) <= 24;
};

$enqueue = static function (int $x, int $y) use (
    $source,
    $width,
    $height,
    &$visited,
    $queue,
    $isExteriorWhite,
): void {
    if ($x < 0 || $x >= $width || $y < 0 || $y >= $height) {
        return;
    }

    $index = ($y * $width) + $x;

    if (ord($visited[$index]) === 1 || !$isExteriorWhite(imagecolorat($source, $x, $y))) {
        return;
    }

    $visited[$index] = "\1";
    $queue->enqueue($index);
};

for ($x = 0; $x < $width; $x++) {
    $enqueue($x, 0);
    $enqueue($x, $height - 1);
}

for ($y = 0; $y < $height; $y++) {
    $enqueue(0, $y);
    $enqueue($width - 1, $y);
}

while (!$queue->isEmpty()) {
    $index = $queue->dequeue();
    $x = $index % $width;
    $y = intdiv($index, $width);

    $enqueue($x - 1, $y);
    $enqueue($x + 1, $y);
    $enqueue($x, $y - 1);
    $enqueue($x, $y + 1);
}

$output = imagecreatetruecolor($width, $height);
imagealphablending($output, false);
imagesavealpha($output, true);
$transparent = imagecolorallocatealpha($output, 255, 255, 255, 127);

$palette = [];

for ($y = 0; $y < $height; $y++) {
    for ($x = 0; $x < $width; $x++) {
        $index = ($y * $width) + $x;
        $color = imagecolorat($source, $x, $y);
        $red = ($color >> 16) & 0xFF;
        $green = ($color >> 8) & 0xFF;
        $blue = $color & 0xFF;

        if (ord($visited[$index]) === 1) {
            imagesetpixel($output, $x, $y, $transparent);
            continue;
        }

        $opaque = imagecolorallocatealpha($output, $red, $green, $blue, 0);
        imagesetpixel($output, $x, $y, $opaque);

        if (!($red > 242 && $green > 242 && $blue > 242)) {
            $quantizedRed = (int) round($red / 16) * 16;
            $quantizedGreen = (int) round($green / 16) * 16;
            $quantizedBlue = (int) round($blue / 16) * 16;
            $key = sprintf(
                '#%02X%02X%02X',
                min(255, $quantizedRed),
                min(255, $quantizedGreen),
                min(255, $quantizedBlue),
            );
            $palette[$key] = ($palette[$key] ?? 0) + 1;
        }
    }
}

$destinationDirectory = dirname($destinationPath);

if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0775, true)) {
    fwrite(STDERR, "No se pudo crear el directorio de destino.\n");
    exit(1);
}

imagepng($output, $destinationPath, 9);
imagedestroy($source);
imagedestroy($output);

arsort($palette);
$dominantColors = array_slice($palette, 0, 8, true);

printf("Logo preparado: %dx%d px\n", $width, $height);
echo "Colores dominantes no blancos:\n";

foreach ($dominantColors as $color => $count) {
    printf("  %s: %d píxeles\n", $color, $count);
}
