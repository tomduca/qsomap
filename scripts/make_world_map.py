"""
Build img/world-map-600.png, the base planisphere used by make_background.php,
from public-domain Natural Earth data (https://www.naturalearthdata.com/).

Output: 600x600 px, Web Mercator (+/-85.05 deg), centred on Greenwich, the
projection map-ssb-qrz.php and map-ssb-github.php draw on.

Inputs (download and unzip first):
  NE1_50M_SR_W.tif   https://naciscdn.org/naturalearth/50m/raster/NE1_50M_SR_W.zip
  ne_50m_coastline.geojson, ne_50m_admin_0_boundary_lines_land.geojson
                     https://github.com/nvkelso/natural-earth-vector/tree/master/geojson

Usage (Python 3 with Pillow and NumPy; a dev tool, not needed on the hosting):
  python3 scripts/make_world_map.py NE1_50M_SR_W.tif ne_50m_coastline.geojson \
      ne_50m_admin_0_boundary_lines_land.geojson img/world-map-600.png
"""

import json
import sys

import numpy as np
from PIL import Image, ImageDraw

SIZE = 600
SCALE = 4  # draw at 4x and downsample, for smooth edges and thin lines
COASTLINE = (90, 110, 120)
BORDERS = (150, 110, 90)


def project(lon, lat, size):
    """Longitude/latitude (degrees) to Web Mercator pixel coordinates."""
    lat = np.clip(lat, -85.0511287798, 85.0511287798)
    x = (np.asarray(lon) + 180.0) / 360.0 * size
    y = (0.5 - np.log(np.tan(np.pi / 4 + np.radians(lat) / 2)) / (2 * np.pi)) * size
    return x, y


def reproject(source, size):
    """Resample an equirectangular world raster into a Web Mercator square."""
    pixels = np.asarray(source.convert('RGB'))
    height, width = pixels.shape[:2]
    lon = (np.arange(size) + 0.5) / size * 360.0 - 180.0
    lat = np.degrees(np.arctan(np.sinh(np.pi * (1 - 2 * (np.arange(size) + 0.5) / size))))
    cols = np.clip(((lon + 180.0) / 360.0 * width).astype(int), 0, width - 1)
    rows = np.clip(((90.0 - lat) / 180.0 * height).astype(int), 0, height - 1)
    return Image.fromarray(pixels[rows[:, None], cols[None, :]])


def draw_lines(image, geojson_path, color, width):
    """Draw every LineString of a GeoJSON file, split where it wraps the antimeridian."""
    draw = ImageDraw.Draw(image)
    size = image.width
    with open(geojson_path, encoding='utf-8') as file:
        features = json.load(file)['features']
    for feature in features:
        geometry = feature['geometry']
        lines = [geometry['coordinates']] if geometry['type'] == 'LineString' else geometry['coordinates']
        for line in lines:
            points = np.asarray(line, dtype=float)
            x, y = project(points[:, 0], points[:, 1], size)
            breaks = np.where(np.abs(np.diff(x)) > size / 2)[0] + 1
            for part_x, part_y in zip(np.split(x, breaks), np.split(y, breaks)):
                if len(part_x) > 1:
                    draw.line(list(zip(part_x, part_y)), fill=color, width=width, joint='curve')


def main():
    if len(sys.argv) != 5:
        sys.exit(__doc__)
    raster_path, coastline_path, borders_path, output_path = sys.argv[1:]
    Image.MAX_IMAGE_PIXELS = None  # the 50m raster is 10800x5400
    image = reproject(Image.open(raster_path), SIZE * SCALE)
    draw_lines(image, borders_path, BORDERS, SCALE)
    draw_lines(image, coastline_path, COASTLINE, 3)
    image = image.resize((SIZE, SIZE), Image.LANCZOS)
    image.quantize(256, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE).save(output_path, optimize=True)
    print('Saved ' + output_path)


if __name__ == '__main__':
    main()
