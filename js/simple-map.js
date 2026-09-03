(() => {
    const page = document.body.dataset.view || 'ssb';
    const viewNames = { ssb: 'QSO SSB', digi: 'QSO DIGI', qsl: 'QSL LoTW' };
    const phoneModes = new Set(['SSB', 'USB', 'LSB', 'FM', 'AM', 'CW']);
    const bandColors = {
        '10M': '#e45756',
        '15M': '#f28e2b',
        '20M': '#59a14f',
        '40M': '#4e79a7',
        '80M': '#7b61a8'
    };
    const qthGrid = 'FF57oc';
    const qthCall = 'LU2MET';
    const status = document.getElementById('map-status');
    const qsoLayer = L.layerGroup();
    const lineLayer = L.layerGroup();

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[character]));
    }

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
                if (isLetter) {
                    longitudeSize /= 10;
                    latitudeSize /= 10;
                } else {
                    longitudeSize /= 24;
                    latitudeSize /= 24;
                }
            }
        }
        return [latitude - 90 + latitudeSize / 2, longitude - 180 + longitudeSize / 2];
    }

    function isIncluded(qso) {
        const mode = String(qso.mode || '').toUpperCase();
        if (page === 'ssb') return phoneModes.has(mode);
        if (page === 'digi') return !phoneModes.has(mode);
        return String(qso.qsl_rcvd || '').toUpperCase() === 'Y';
    }

    function bandColor(band) {
        return bandColors[String(band || '').toUpperCase()] || '#68747d';
    }

    function shortestLongitude(longitude, originLongitude) {
        return originLongitude + ((((longitude - originLongitude) + 540) % 360) - 180);
    }

    function greatCirclePoints(start, end, pointCount = 64) {
        const startLat = start[0] * Math.PI / 180;
        const startLon = start[1] * Math.PI / 180;
        const endLat = end[0] * Math.PI / 180;
        const endLon = end[1] * Math.PI / 180;
        const startVector = [Math.cos(startLat) * Math.cos(startLon), Math.cos(startLat) * Math.sin(startLon), Math.sin(startLat)];
        const endVector = [Math.cos(endLat) * Math.cos(endLon), Math.cos(endLat) * Math.sin(endLon), Math.sin(endLat)];
        const angle = Math.acos(Math.min(1, Math.max(-1, startVector[0] * endVector[0] + startVector[1] * endVector[1] + startVector[2] * endVector[2])));
        if (angle < 0.000001) return [start, end];
        const sine = Math.sin(angle);
        const points = [];
        for (let index = 0; index <= pointCount; index += 1) {
            const fraction = index / pointCount;
            const firstWeight = Math.sin((1 - fraction) * angle) / sine;
            const secondWeight = Math.sin(fraction * angle) / sine;
            const x = firstWeight * startVector[0] + secondWeight * endVector[0];
            const y = firstWeight * startVector[1] + secondWeight * endVector[1];
            const z = firstWeight * startVector[2] + secondWeight * endVector[2];
            points.push([Math.atan2(z, Math.sqrt(x * x + y * y)) * 180 / Math.PI, Math.atan2(y, x) * 180 / Math.PI]);
        }
        let previousLongitude = start[1];
        return points.map(point => {
            const longitude = shortestLongitude(point[1], previousLongitude);
            previousLongitude = longitude;
            return [point[0], longitude];
        });
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

    function popupFor(group) {
        const first = group.items[0];
        let html = '<div class="simple-popup-title"><a href="https://www.qrz.com/db/'
            + encodeURIComponent(group.call) + '" target="_blank" rel="noopener"><strong>'
            + escapeHtml(group.call) + '</strong></a>';
        if (first.name) html += ' &nbsp;' + escapeHtml(first.name);
        html += '</div><div>' + escapeHtml(group.grid);
        if (first.qth) html += ' &nbsp;' + escapeHtml(first.qth);
        if (first.dxcc) html += '<br>DXCC: ' + escapeHtml(dxccText(first));
        if (first.sig || first.sig_info) html += '<br>' + escapeHtml([first.sig, first.sig_info].filter(Boolean).join(': '));
        html += '<br>Banda: ' + escapeHtml(group.band || 'N/D') + '</div><table class="simple-popup-table"><thead><tr><th>Fecha</th><th>Frecuencia</th><th>Modo</th></tr></thead><tbody>';
        group.items.forEach(qso => {
            const frequency = qso.freq === null || qso.freq === undefined || qso.freq === '' ? '' : Number(qso.freq).toFixed(3) + ' MHz';
            html += '<tr><td>' + escapeHtml(formatDate(qso)) + '</td><td>' + escapeHtml(frequency)
                    + '</td><td>' + escapeHtml(qso.mode || '') + '</td></tr>';
                if (qso.comment) html += '<tr><td colspan="3" class="simple-popup-comment">' + escapeHtml(qso.comment) + '</td></tr>';
        });
        return html + '</tbody></table>';
    }

    function setStatus(message, isError = false) {
        status.innerHTML = '<strong>' + viewNames[page] + '</strong><span class="' + (isError ? 'error' : '') + '">' + message + '</span>';
    }

    async function start() {
        const map = L.map('map', {
            minZoom: 2,
            maxZoom: 17,
            zoomControl: true,
            worldCopyJump: false
        });
        const oms = new OverlappingMarkerSpiderfier(map, { nearbyDistance: 20, keepSpiderfied: true });
        L.tileLayer.provider('Esri.NatGeoWorldMap', { maxZoom: 17 }).addTo(map);
        const qthPosition = gridCentre(qthGrid);
        if (!qthPosition) throw new Error('Grid QTH invalido');
        L.circleMarker(qthPosition, { radius: 4, color: '#18343a', fillColor: '#f3c969', fillOpacity: 1, weight: 1 })
            .bindTooltip(qthCall + ' (' + qthGrid.toUpperCase() + ')').addTo(map);
        qsoLayer.addTo(map);
        lineLayer.addTo(map);
        setStatus('Cargando QSOs...');

        const response = await fetch('data/qso_cache.json?view=' + page + '&v=' + Date.now());
        if (!response.ok) throw new Error('No se pudo cargar data/qso_cache.json');
        const cache = await response.json();
        const groups = new Map();
        (cache.qsos || []).filter(qso => qso.grid && isIncluded(qso)).forEach(qso => {
            const position = gridCentre(qso.grid);
            if (!position) return;
            const band = String(qso.band || '').toUpperCase();
            const key = String(qso.call || '') + '-' + String(qso.grid).toUpperCase() + '-' + band;
            if (!groups.has(key)) groups.set(key, { call: qso.call, grid: qso.grid, band, position, items: [] });
            groups.get(key).items.push(qso);
        });

        function renderGroups() {
            try {
                oms.clearMarkers();
            } catch (error) {
                console.warn('No se pudieron limpiar los marcadores agrupados', error);
            }
            qsoLayer.clearLayers();
            lineLayer.clearLayers();
            const bounds = L.latLngBounds([qthPosition]);
            groups.forEach(group => {
                const color = bandColor(group.band);
                const displayPosition = [group.position[0], shortestLongitude(group.position[1], qthPosition[1])];
                const marker = L.circleMarker(displayPosition, { radius: 3, color: '#ffffff', weight: 1, fillColor: color, fillOpacity: 0.92 });
                marker.bindPopup(popupFor(group));
                marker.addTo(qsoLayer);
                oms.addMarker(marker);
                const line = L.polyline(greatCirclePoints(qthPosition, displayPosition), { color, weight: 1, opacity: 0.65, noClip: false });
                line.addTo(lineLayer);
                bounds.extend(displayPosition);
            });
            return bounds;
        }

        const bounds = renderGroups();
        map.on('moveend', renderGroups);
        map.on('zoomend', renderGroups);
        map.setView(qthPosition, 3);
        const totalQsos = [...groups.values()].reduce((total, group) => total + group.items.length, 0);
        setStatus(groups.size + ' ubicaciones, ' + totalQsos + ' QSOs');
        document.getElementById('map-nav').innerHTML = '<a href="map-ssb.html">SSB</a><a href="map-digi.html">DIGI</a><a href="map-qsl.html">QSL LoTW</a>';
    }

    start().catch(error => setStatus(error.message, true));
})();
