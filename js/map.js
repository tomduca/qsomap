/**
 * Interactive QSO map (Leaflet). The view is chosen by <body data-view>:
 *   ssb  - phone QSOs (SSB, AM, FM)
 *   cw   - CW
 *   rtty - RTTY
 *   ft   - FT8 and FT4
 *   qsl  - confirmed QSOs in any mode (QSL_RCVD = Y)
 * Other modes (PSK, JT65...) only appear on the QSL map once confirmed.
 * The station is the bare callsign in the query string (map-ssb.html?LU2MET);
 * without one, cache.php serves the installation's default station. Data
 * comes from cache.php, i.e. the station's qso_cache.json built by
 * scripts/build_cache.php. Big logs come grouped by grid square ("groups"
 * instead of "qsos"): the map then shows one point per square, sized by its
 * QSOs, and says so in the status bar.
 */
(() => {
    // Pages and their modes, in the order of the navigation links. A QSO
    // matches on MODE or SUBMODE (FT4 is logged as MFSK with SUBMODE FT4).
    const views = {
        ssb: { name: 'SSB', page: 'map-ssb.html', modes: ['SSB', 'USB', 'LSB', 'FM', 'AM'] },
        cw: { name: 'CW', page: 'map-cw.html', modes: ['CW'] },
        rtty: { name: 'RTTY', page: 'map-rtty.html', modes: ['RTTY'] },
        ft: { name: 'FT', page: 'map-ft.html', modes: ['FT8', 'FT4'] },
        qsl: { name: 'QSL LoTW', page: 'map-qsl.html', modes: null }
    };
    const view = views[document.body.dataset.view] ? document.body.dataset.view : 'ssb';
    const repositoryUrl = 'https://github.com/tomduca/qsomap';
    const bandColors = {
        '10M': '#e45756',
        '15M': '#f28e2b',
        '20M': '#59a14f',
        '40M': '#4e79a7',
        '80M': '#7b61a8'
    };
    const status = document.getElementById('map-status');
    const stationCall = [...new URLSearchParams(location.search)]
        .find(([name, value]) => value === '' && /^(?=.*\d)(?=.*[a-z])[a-z0-9]{3,10}$/i.test(name))?.[0].toUpperCase() || '';
    const stationQuery = stationCall ? '?' + stationCall : '';
    let stationName = stationCall;

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[character]));
    }

    /** Centre of a Maidenhead locator (4, 6 or 8 chars) as [lat, lon], or null. */
    function gridCentre(grid) {
        const value = String(grid || '').trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (value.length < 4 || value.length % 2 !== 0) return null;
        let latitude = 0;
        let longitude = 0;
        let latitudeSize = 10;
        let longitudeSize = 20;
        for (let index = 0; index < value.length / 2; index += 1) {
            const first = value[index * 2];
            const second = value[index * 2 + 1];
            const isLetter = index % 2 === 0;
            const longitudeIndex = isLetter ? first.charCodeAt(0) - 65 : Number(first);
            const latitudeIndex = isLetter ? second.charCodeAt(0) - 65 : Number(second);
            const maxIndex = isLetter ? (index === 0 ? 17 : 23) : 9;
            if (!Number.isInteger(longitudeIndex) || !Number.isInteger(latitudeIndex) || longitudeIndex < 0 || longitudeIndex > maxIndex || latitudeIndex < 0 || latitudeIndex > maxIndex) return null;
            longitude += longitudeIndex * longitudeSize;
            latitude += latitudeIndex * latitudeSize;
            if (index < value.length / 2 - 1) {
                const divisor = isLetter ? 10 : 24;
                longitudeSize /= divisor;
                latitudeSize /= divisor;
            }
        }
        return [latitude - 90 + latitudeSize / 2, longitude - 180 + longitudeSize / 2];
    }

    function isIncluded(qso) {
        const modes = views[view].modes;
        if (!modes) return String(qso.qsl_rcvd || '').toUpperCase() === 'Y';
        return [qso.mode, qso.submode].some(mode => modes.includes(String(mode || '').toUpperCase()));
    }

    function bandColor(band) {
        return bandColors[String(band || '').toUpperCase()] || '#68747d';
    }

    /** Shift a longitude by 360° so it is the closest copy to originLongitude. */
    function shortestLongitude(longitude, originLongitude) {
        return originLongitude + ((((longitude - originLongitude) + 540) % 360) - 180);
    }

    /** Points along the great circle between two [lat, lon] positions. */
    function greatCirclePoints(start, end, pointCount = 64) {
        const toVector = ([lat, lon]) => {
            const phi = lat * Math.PI / 180;
            const lambda = lon * Math.PI / 180;
            return [Math.cos(phi) * Math.cos(lambda), Math.cos(phi) * Math.sin(lambda), Math.sin(phi)];
        };
        const a = toVector(start);
        const b = toVector(end);
        const angle = Math.acos(Math.min(1, Math.max(-1, a[0] * b[0] + a[1] * b[1] + a[2] * b[2])));
        if (angle < 0.000001) return [start, end];
        const sine = Math.sin(angle);
        const points = [];
        let previousLongitude = start[1];
        for (let index = 0; index <= pointCount; index += 1) {
            const fraction = index / pointCount;
            const w1 = Math.sin((1 - fraction) * angle) / sine;
            const w2 = Math.sin(fraction * angle) / sine;
            const [x, y, z] = [0, 1, 2].map(axis => w1 * a[axis] + w2 * b[axis]);
            previousLongitude = shortestLongitude(Math.atan2(y, x) * 180 / Math.PI, previousLongitude);
            points.push([Math.atan2(z, Math.sqrt(x * x + y * y)) * 180 / Math.PI, previousLongitude]);
        }
        return points;
    }

    function formatDate(qso) {
        const date = String(qso.date || '');
        const time = String(qso.time || '').padStart(6, '0');
        if (date.length !== 8 || time.length < 4) return '';
        return date.slice(0, 4) + '-' + date.slice(4, 6) + '-' + date.slice(6, 8)
            + ' ' + time.slice(0, 2) + ':' + time.slice(2, 4) + ' UTC';
    }

    function dxccText(qso) {
        const code = String(qso.dxcc || '').trim();
        const entity = typeof DXCC_DATA !== 'undefined' && DXCC_DATA[code];
        return entity ? entity.name + ' (' + code + ')' : code;
    }

    /**
     * QRZ.com page of a callsign. The slashes of a portable call (UR7FM/MM,
     * EA8/DL1ABC) stay as they are: QRZ does not find the call with %2F.
     */
    function qrzUrl(call) {
        return 'https://www.qrz.com/db/' + String(call).split('/').map(encodeURIComponent).join('/');
    }

    function popupFor(group) {
        const first = group.items[0];
        let html = '<div class="popup-title"><a href="' + escapeHtml(qrzUrl(group.call)) + '" target="_blank" rel="noopener"><strong>'
            + escapeHtml(group.call) + '</strong></a>';
        if (first.name) html += ' &nbsp;' + escapeHtml(first.name);
        html += '</div><div>' + escapeHtml(group.grid);
        if (first.qth) html += ' &nbsp;' + escapeHtml(first.qth);
        if (first.dxcc) html += '<br>DXCC: ' + escapeHtml(dxccText(first));
        if (first.sig || first.sig_info) html += '<br>' + escapeHtml([first.sig, first.sig_info].filter(Boolean).join(': '));
        html += '<br>Band: ' + escapeHtml(group.band || 'n/a') + '</div><table class="popup-table"><thead><tr><th>Date</th><th>Frequency</th><th>Mode</th></tr></thead><tbody>';
        group.items.forEach(qso => {
            const frequency = qso.freq === null || qso.freq === undefined || qso.freq === '' ? '' : Number(qso.freq).toFixed(3) + ' MHz';
            html += '<tr><td>' + escapeHtml(formatDate(qso)) + '</td><td>' + escapeHtml(frequency)
                + '</td><td>' + escapeHtml(qso.mode || '') + '</td></tr>';
            if (qso.comment) html += '<tr><td colspan="3" class="popup-comment">' + escapeHtml(qso.comment) + '</td></tr>';
        });
        return html + '</tbody></table>';
    }

    /** Popup of a grid square of a grouped (big) log. */
    function popupForSquare(square) {
        const bands = [...square.bands.entries()].sort((a, b) => b[1] - a[1]);
        let html = '<div class="popup-title"><strong>' + escapeHtml(square.grid) + '</strong> &nbsp;'
            + escapeHtml(square.total + (square.total === 1 ? ' QSO' : ' QSOs')) + '</div>';
        const entities = [...square.dxcc].map(code => dxccText({ dxcc: code })).filter(Boolean);
        if (entities.length) html += '<div>DXCC: ' + escapeHtml(entities.join(', ')) + '</div>';
        html += '<table class="popup-table"><thead><tr><th>Band</th><th>QSOs</th></tr></thead><tbody>';
        bands.forEach(([band, count]) => {
            html += '<tr><td>' + escapeHtml(band || 'n/a') + '</td><td>' + count + '</td></tr>';
        });
        html += '</tbody></table>';
        if (square.calls.size) {
            html += '<div class="popup-calls">Latest: ' + [...square.calls].slice(0, 12).map(call => '<a href="'
                + escapeHtml(qrzUrl(call)) + '" target="_blank" rel="noopener">' + escapeHtml(call) + '</a>').join(', ') + '</div>';
        }
        return html;
    }

    /**
     * Draw a grouped cache: one point and path per grid square, in the color
     * of its band with most QSOs. Returns [squares, QSOs] shown.
     */
    function drawSquares(map, groups, qthPosition) {
        const squares = new Map();
        groups.forEach(group => {
            const count = view === 'qsl' ? group.q : (group.class === view ? group.n : 0);
            if (!count) return;
            const position = gridCentre(group.grid);
            if (!position) return;
            if (!squares.has(group.grid)) squares.set(group.grid, { grid: group.grid, position, total: 0, bands: new Map(), calls: new Set(), dxcc: new Set() });
            const square = squares.get(group.grid);
            square.total += count;
            square.bands.set(group.band, (square.bands.get(group.band) || 0) + count);
            (view === 'qsl' ? group.qsl_calls : group.calls).forEach(call => square.calls.add(call));
            group.dxcc.forEach(code => square.dxcc.add(code));
        });
        // Canvas instead of SVG: thousands of paths stay smooth.
        const renderer = L.canvas({ padding: 0.5 });
        let total = 0;
        squares.forEach(square => {
            total += square.total;
            const band = [...square.bands.entries()].sort((a, b) => b[1] - a[1])[0][0];
            const color = bandColor(band);
            const position = [square.position[0], shortestLongitude(square.position[1], qthPosition[1])];
            L.polyline(greatCirclePoints(qthPosition, position, 32), { renderer, color, weight: 1, opacity: 0.35, interactive: false }).addTo(map);
            L.circleMarker(position, {
                renderer, radius: Math.min(9, 3 + Math.log10(square.total) * 2), color: '#ffffff', weight: 1, fillColor: color, fillOpacity: 0.92
            }).bindPopup(popupForSquare(square)).addTo(map);
        });
        return [squares.size, total];
    }

    /**
     * Keep popups inside the map. Leaflet opens them above their point and
     * pans the map to make room, but this map cannot move past the poles, so a
     * popup near the top edge would be cut off. Such a popup opens below its
     * point instead; one too tall for either side opens where there is more
     * room and scrolls. Panning is left for the sides only.
     */
    function keepPopupsInsideMap() {
        const margin = 10;      // px kept free between a popup and the map edge
        const tip = 14;         // px the tip adds beyond the popup box
        const leafletUpdate = L.Popup.prototype._updatePosition;
        L.Popup.include({
            _updatePosition() {
                leafletUpdate.call(this);
                if (!this._map || !this._container) return;
                const container = this._container;
                const wrapper = this._wrapper;
                container.classList.remove('popup-below');
                container.style.top = '';
                wrapper.style.maxHeight = '';
                wrapper.style.overflowY = '';
                const pointY = this._map.latLngToContainerPoint(this._latlng).y;
                const above = pointY - margin;
                const below = this._map.getSize().y - pointY - margin;
                const needed = container.offsetHeight + tip;
                if (needed <= above) {
                    fadeBoxesUnder(container);
                    return;
                }
                const opensBelow = below > above;
                const room = opensBelow ? below : above;
                if (needed > room) {
                    wrapper.style.maxHeight = Math.max(80, room - tip) + 'px';
                    wrapper.style.overflowY = 'auto';
                }
                if (opensBelow) {
                    // Mirror Leaflet's offset: the same distance, measured downwards.
                    container.classList.add('popup-below');
                    container.style.top = container.style.bottom;
                    container.style.bottom = 'auto';
                }
                fadeBoxesUnder(container);
            },
            _adjustPan() {
                if (!this.options.autoPan || !this._map) return;
                if (this._map._panAnim) this._map._panAnim.stop();
                if (this._autopanning) {
                    this._autopanning = false;
                    return;
                }
                const map = this._map;
                const padding = L.point(this.options.autoPanPadding).x;
                const left = map.latLngToContainerPoint(this._latlng).x + this._containerLeft;
                const right = left + this._containerWidth;
                let dx = 0;
                if (right + padding > map.getSize().x) dx = right + padding - map.getSize().x;
                if (left - dx - padding < 0) dx = left - padding;
                if (dx) {
                    if (this.options.keepInView) this._autopanning = true;
                    map.fire('autopanstart').panBy([dx, 0]);
                }
            }
        });
    }

    /**
     * The status and navigation boxes float over the map: while an open popup
     * passes under one, the box fades so the popup can be read.
     */
    function fadeBoxesUnder(popup) {
        const rect = popup && popup.isConnected ? popup.getBoundingClientRect() : null;
        document.querySelectorAll('.map-status').forEach(box => {
            const boxRect = box.getBoundingClientRect();
            const overlaps = rect !== null && boxRect.left < rect.right && boxRect.right > rect.left
                && boxRect.top < rect.bottom && boxRect.bottom > rect.top;
            box.classList.toggle('under-popup', overlaps);
        });
    }

    function setStatus(message, isError = false, note = '') {
        status.innerHTML = '<strong>' + escapeHtml((stationName + ' ' + views[view].name).trim()) + '</strong><span class="' + (isError ? 'error' : '') + '">'
            + escapeHtml(message) + '</span>' + (note ? '<span class="map-note">' + escapeHtml(note) + '</span>' : '');
    }

    /**
     * Add entities from data/dxcc.json (refreshed monthly by scripts/update_dxcc.php)
     * that js/dxcc.js does not know yet. Names already in js/dxcc.js are kept.
     */
    async function loadDxccUpdates() {
        if (typeof DXCC_DATA === 'undefined') return;
        try {
            const response = await fetch('data/dxcc.json?v=' + new Date().toISOString().slice(0, 10));
            if (!response.ok) return;
            Object.entries(await response.json()).forEach(([code, name]) => {
                if (!DXCC_DATA[code]) DXCC_DATA[code] = { name, flag: '' };
            });
        } catch (error) {
            // Optional file: without it the base list is used.
        }
    }

    /** localStorage that never throws (private windows, blocked storage). */
    function storage(key, value) {
        try {
            if (value === undefined) return localStorage.getItem(key);
            if (value === null) localStorage.removeItem(key);
            else localStorage.setItem(key, value);
        } catch (error) {
            return null;
        }
    }

    /**
     * Update banner, shown only to the owner: opening a map once with ?owner
     * marks this browser (?owner=0 unmarks it). data/update.json is written
     * daily by scripts/check_update.php.
     */
    async function showUpdateBanner() {
        const owner = new URLSearchParams(location.search).get('owner');
        if (owner !== null) storage('qsomap-owner', owner === '0' ? null : '1');
        if (storage('qsomap-owner') !== '1') return;

        const response = await fetch('data/update.json?v=' + Date.now());
        if (!response.ok) return;
        const update = await response.json();
        if (!update.available || storage('qsomap-dismissed') === update.latest) return;

        const banner = document.createElement('div');
        banner.className = 'update-banner';
        banner.innerHTML = 'QSO Map v' + escapeHtml(update.latest) + ' is available (installed: v'
            + escapeHtml(update.current) + '). <a href="' + escapeHtml(update.url)
            + '" target="_blank" rel="noopener">See what changed</a>'
            + '<button type="button" aria-label="Dismiss">&times;</button>';
        banner.querySelector('button').addEventListener('click', () => {
            storage('qsomap-dismissed', update.latest);
            banner.remove();
            document.body.classList.remove('has-update-banner');
        });
        document.body.prepend(banner);
        document.body.classList.add('has-update-banner');
    }

    async function start() {
        setStatus('Loading QSOs...');
        keepPopupsInsideMap();
        const dxccUpdates = loadDxccUpdates();
        document.querySelector('.map-nav').innerHTML = Object.values(views)
            .map(item => '<a href="' + item.page + stationQuery + '"' + (item === views[view] ? ' aria-current="page"' : '') + '>'
                + escapeHtml(item.name) + '</a>').join('')
            + '<a class="map-credit" href="' + repositoryUrl + '" target="_blank" rel="noopener">QSO Map on GitHub</a>';
        const response = await fetch('cache.php' + (stationQuery || '?') + '&v=' + Date.now());
        if (response.status === 404) throw new Error(stationCall ? 'Unknown station ' + stationCall + ', or its map is not ready yet' : 'No station selected');
        if (!response.ok) throw new Error('Could not load the map data');
        const cache = await response.json();

        const station = cache.station || {};
        const qthPosition = gridCentre(station.grid);
        if (!qthPosition) throw new Error('Invalid QTH grid: set map.my_grid and rebuild the cache');
        stationName = String(station.callsign || stationName);
        document.title = (stationName + ' ' + views[view].name).trim() + ' | QSO Map';

        // Zooming out stops when the whole world (pole to pole, ±85° in Web
        // Mercator) fits the height of the window; the map does not scroll
        // above or below it. Tiles are 256 px, twice as tall per zoom level.
        const worldFitZoom = () => Math.max(0, Math.log2(document.getElementById('map').clientHeight / 256));
        const map = L.map('map', {
            minZoom: worldFitZoom(),
            maxZoom: 17,
            maxBounds: [[-85.06, -100000], [85.06, 100000]],
            maxBoundsViscosity: 1,
        });
        map.on('resize', () => map.setMinZoom(worldFitZoom()));
        map.on('popupclose', () => fadeBoxesUnder(null));
        map.on('move', () => {
            const popup = document.querySelector('.leaflet-popup');
            if (popup) fadeBoxesUnder(popup);
        });
        L.tileLayer.provider('Esri.NatGeoWorldMap', { maxZoom: 17 }).addTo(map);
        const oms = new OverlappingMarkerSpiderfier(map, { nearbyDistance: 20, keepSpiderfied: true });
        L.circleMarker(qthPosition, { radius: 4, color: '#18343a', fillColor: '#f3c969', fillOpacity: 1, weight: 1 })
            .bindTooltip(station.callsign + ' (' + String(station.grid).toUpperCase() + ')').addTo(map);

        if (cache.groups) {
            await dxccUpdates;
            const [squares, total] = drawSquares(map, cache.groups, qthPosition);
            map.setView(qthPosition, 3);
            setStatus(squares + ' grid squares, ' + total + ' QSOs', false,
                'This log is too big to show each QSO, so they are grouped by grid square. Click a square for its QSOs per band and latest callsigns.');
            return;
        }

        // One marker per callsign + grid + band; its popup lists every QSO.
        const groups = new Map();
        (cache.qsos || []).filter(qso => qso.grid && isIncluded(qso)).forEach(qso => {
            const position = gridCentre(qso.grid);
            if (!position) return;
            const band = String(qso.band || '').toUpperCase();
            const key = String(qso.call || '') + '-' + String(qso.grid).toUpperCase() + '-' + band;
            if (!groups.has(key)) groups.set(key, { call: qso.call, grid: qso.grid, band, position, items: [] });
            groups.get(key).items.push(qso);
        });

        await dxccUpdates;
        groups.forEach(group => {
            const color = bandColor(group.band);
            // Draw each contact on the world copy closest to the QTH so paths take the short way.
            const position = [group.position[0], shortestLongitude(group.position[1], qthPosition[1])];
            const marker = L.circleMarker(position, { radius: 3, color: '#ffffff', weight: 1, fillColor: color, fillOpacity: 0.92 })
                .bindPopup(popupFor(group))
                .addTo(map);
            oms.addMarker(marker);
            L.polyline(greatCirclePoints(qthPosition, position), { color, weight: 1, opacity: 0.65 }).addTo(map);
        });

        map.setView(qthPosition, 3);
        const totalQsos = [...groups.values()].reduce((total, group) => total + group.items.length, 0);
        setStatus(groups.size + ' locations, ' + totalQsos + ' QSOs');
    }

    start().catch(error => setStatus(error.message, true));
    showUpdateBanner().catch(() => {});
})();
