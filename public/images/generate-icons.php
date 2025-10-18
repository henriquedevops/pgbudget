<?php
/**
 * Generate simple placeholder PWA icons using PHP GD
 * Run: php generate-icons.php
 */

// Check if GD is available
if (!extension_loaded('gd')) {
    die("GD extension is not installed. Please install php-gd.\n");
}

// Icon sizes needed for PWA
$sizes = [72, 96, 128, 144, 152, 192, 384, 512];

// Colors
$bgColor = [49, 130, 206];    // #3182ce (blue)
$fgColor = [255, 255, 255];   // white

echo "Generating PWA icons...\n\n";

foreach ($sizes as $size) {
    $filename = "icon-{$size}x{$size}.png";

    // Create image
    $image = imagecreatetruecolor($size, $size);

    // Allocate colors
    $bg = imagecolorallocate($image, $bgColor[0], $bgColor[1], $bgColor[2]);
    $fg = imagecolorallocate($image, $fgColor[0], $fgColor[1], $fgColor[2]);

    // Fill background
    imagefill($image, 0, 0, $bg);

    // Calculate font size (proportional to image size)
    $fontSize = $size / 2.5;

    // Add dollar sign
    $text = '$';

    // Get text bounding box
    $bbox = imagettfbbox($fontSize, 0, __DIR__ . '/../../fonts/DejaVuSans-Bold.ttf', $text);

    // If font file doesn't exist, use built-in font
    if (!file_exists(__DIR__ . '/../../fonts/DejaVuSans-Bold.ttf')) {
        // Use built-in large font
        $fontId = 5;
        $textWidth = imagefontwidth($fontId) * strlen($text);
        $textHeight = imagefontheight($fontId);

        // Center the text
        $x = ($size - $textWidth) / 2;
        $y = ($size - $textHeight) / 2;

        // Draw multiple $ symbols to make it larger
        $scale = max(1, floor($size / 100));
        for ($i = 0; $i < $scale; $i++) {
            for ($j = 0; $j < $scale; $j++) {
                imagestring($image, $fontId, $x + ($i * 2), $y + ($j * 2), $text, $fg);
            }
        }
    } else {
        // Calculate text position to center it
        $textWidth = $bbox[2] - $bbox[0];
        $textHeight = $bbox[1] - $bbox[7];

        $x = ($size - $textWidth) / 2;
        $y = ($size + $textHeight) / 2;

        // Add text
        imagettftext($image, $fontSize, 0, $x, $y, $fg, __DIR__ . '/../../fonts/DejaVuSans-Bold.ttf', $text);
    }

    // Add rounded corners for larger icons
    if ($size >= 192) {
        $radius = $size / 8;
        imagefilledrectangle($image, 0, 0, $radius, $radius, $bg);
        imagefilledellipse($image, $radius, $radius, $radius * 2, $radius * 2, $bg);

        imagefilledrectangle($image, $size - $radius, 0, $size, $radius, $bg);
        imagefilledellipse($image, $size - $radius, $radius, $radius * 2, $radius * 2, $bg);

        imagefilledrectangle($image, 0, $size - $radius, $radius, $size, $bg);
        imagefilledellipse($image, $radius, $size - $radius, $radius * 2, $radius * 2, $bg);

        imagefilledrectangle($image, $size - $radius, $size - $radius, $size, $size, $bg);
        imagefilledellipse($image, $size - $radius, $size - $radius, $radius * 2, $radius * 2, $bg);
    }

    // Save PNG
    imagepng($image, $filename, 9);
    imagedestroy($image);

    echo "✓ Created: $filename (" . filesize($filename) . " bytes)\n";
}

echo "\nIcon generation complete!\n";
echo "\nGenerated files:\n";
system('ls -lh icon-*.png');

echo "\n\nTo use custom icons, replace these files with your own design.\n";
echo "Recommended: Use a logo or app icon in PNG/SVG format.\n";
