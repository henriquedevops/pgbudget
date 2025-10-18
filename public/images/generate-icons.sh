#!/bin/bash
# Script to generate PWA icons from an SVG or generate placeholder icons
# Usage: ./generate-icons.sh [source-image.png|source-image.svg]

# Check if ImageMagick is installed
if ! command -v convert &> /dev/null; then
    echo "ImageMagick is not installed. Installing..."
    echo "Run: sudo apt-get install imagemagick"
    exit 1
fi

# Create a simple placeholder icon using ImageMagick
create_placeholder_icon() {
    local size=$1
    local output=$2

    # Create a simple colored icon with $ symbol
    convert -size ${size}x${size} xc:#3182ce \
        -gravity center \
        -pointsize $((size / 2)) \
        -font "DejaVu-Sans-Bold" \
        -fill white \
        -annotate +0+0 '$' \
        "$output"

    echo "Created: $output"
}

# Icon sizes needed for PWA
SIZES=(72 96 128 144 152 192 384 512)

echo "Generating PWA icons..."

if [ -n "$1" ] && [ -f "$1" ]; then
    # Use provided source image
    SOURCE="$1"
    echo "Using source image: $SOURCE"

    for size in "${SIZES[@]}"; do
        convert "$SOURCE" -resize ${size}x${size} "icon-${size}x${size}.png"
        echo "Created: icon-${size}x${size}.png"
    done
else
    # Generate placeholder icons
    echo "No source image provided. Generating placeholder icons..."

    for size in "${SIZES[@]}"; do
        create_placeholder_icon "$size" "icon-${size}x${size}.png"
    done
fi

echo ""
echo "Icon generation complete!"
echo ""
echo "Generated icons:"
ls -lh icon-*.png

echo ""
echo "To use custom icons, run:"
echo "./generate-icons.sh your-logo.png"
