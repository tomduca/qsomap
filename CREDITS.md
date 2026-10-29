# Credits

## Original project

This repository started as a fork of [QSO Map](https://git.ianrenton.com/ian/qsomap.git) by **Ian Renton (M0TRT)**, released under [The Unlicense](https://unlicense.org). It contributed the ideas of plotting QSOs on a map from Maidenhead grids, showing the home station with a path to each contact, and generating a data cache for the viewer.

The current version is a rewrite: the viewer, Maidenhead conversions, great-circle lines, mode and QSL filters, server-side sync and the static QRZ map were implemented for this project. It does not load any code from the original project.

## Libraries

| Library | Used for | License |
| --- | --- | --- |
| [Leaflet](https://leafletjs.com/) 1.9.4 | Interactive maps | BSD-2-Clause |
| [Leaflet Providers](https://github.com/leaflet-extras/leaflet-providers) | Esri base layer | BSD-2-Clause |
| [OverlappingMarkerSpiderfier-Leaflet](https://github.com/jawj/OverlappingMarkerSpiderfier-Leaflet) | Separating overlapping markers | MIT |

## Maps and services

- **Esri NatGeo World Map**: base layer of the interactive maps. Leaflet displays the attribution required by Esri; it must be kept.
- **[Clublog](https://clublog.org/)** and **[LoTW](https://lotw.arrl.org/)** (ARRL): QSO sources.
- **[HamQTH](https://www.hamqth.com/)** and **[Spothole](https://spothole.app/)**: lookups for missing grids.
- **[AD1C country files](https://www.country-files.com/)** (`cty.csv`): monthly refresh of the DXCC entity list.
- **[TrustedQSL](https://sourceforge.net/projects/trustedqsl/)** (ARRL) `vuccgrids.dat`: grids of each DXCC entity, used to drop grids that do not match a QSO's entity.
- **[QRZ.com](https://www.qrz.com/)**: linked from the popups, and the site where the static map is embedded.

## Local data

- `js/dxcc.js`: base DXCC entity catalog (code → name and flag, including deleted entities) used in the popups. New entities are added from `data/dxcc.json` (see AD1C above).
- `img/world-map-600.png`: base world map for the static map, built by `scripts/make_world_map.py` from [Natural Earth](https://www.naturalearthdata.com/) data (public domain): the *Natural Earth I with Shaded Relief and Water* 1:50m raster, coastlines and land boundaries. Made with Natural Earth.
