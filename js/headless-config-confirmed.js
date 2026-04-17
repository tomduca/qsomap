/**
 * Headless Map Configuration & Auto-Load - CONFIRMED QSOs ONLY
 * Loads only QSOs confirmed in Clublog or LoTW from JSON cache
 */

// Configuration
const HEADLESS_CONFIG = {
    callsign: 'LU2MET',
    grid: 'FF57oc',
    cacheFile: 'data/qso_cache.json',
    
    // Map settings - exact as specified
    settings: {
        basemap: 'Esri.NatGeoWorldMap',
        basemapOpacity: 0.5,
        markerSize: 0.5,  // Smaller markers
        circleMarkers: true,  // Use simple dot markers (circles)
        bandColorScheme: 'PSK Reporter',
        outlineMarkers: true,
        outdoorSymbols: true,  // xOTA activity symbols
        linesEnabled: true,
        geodesicLines: true,
        colourLines: true,
        showComments: true,
        inferOutdoorActivity: true,
        qthMarker: true,
        markersEnabled: true
    }
};

// Wait for map to initialize
$(document).ready(function() {
    console.log('Initializing headless QSO Map (CONFIRMED ONLY)...');
    
    // Wait for map setup to complete
    setTimeout(async function() {
        await initializeHeadlessMap();
    }, 1000);
});

async function initializeHeadlessMap() {
    try {
        console.log('Applying configuration...');
        
        // Set user info
        $("#myCall").val(HEADLESS_CONFIG.callsign);
        $("#qthGrid").val(HEADLESS_CONFIG.grid);
        
        // Apply map settings
        const s = HEADLESS_CONFIG.settings;
        
        // Basemap
        $("#basemap").val(s.basemap);
        $("#basemapOpacity").val(s.basemapOpacity);
        
        // Markers
        $("#markersEnabled").prop('checked', s.markersEnabled);
        $("#qthMarker").prop('checked', s.qthMarker);
        $("#markerSize").val(s.markerSize);
        $("#circleMarkers").prop('checked', s.circleMarkers);
        $("#outlineMarkers").prop('checked', s.outlineMarkers);
        
        // Band colors
        $("#bandColours").prop('checked', true);
        $("#bandColorScheme").val(s.bandColorScheme);
        if (typeof setBandColorSchemeQSOMap === 'function') {
            setBandColorSchemeQSOMap(s.bandColorScheme);
        }
        
        // xOTA symbols
        $("#outdoorSymbols").prop('checked', s.outdoorSymbols);
        
        // Lines
        $("#linesEnabled").prop('checked', s.linesEnabled);
        $("#colourLines").prop('checked', s.colourLines);
        $("#thickLines").prop('checked', false); // Thin lines
        
        // Comments
        $("#showComments").prop('checked', s.showComments);
        
        // Update position from grid
        if (typeof updatePosFromGridInput === 'function') {
            updatePosFromGridInput();
        }
        
        // Trigger basemap change
        if (typeof changeBasemap === 'function') {
            changeBasemap();
        }
        
        console.log('Configuration applied');
        
        // Dismiss any popovers or modals
        $('.popover').remove();
        $('.modal').modal('hide');
        $('[data-bs-toggle="popover"]').popover('dispose');
        
        // Load QSOs from cache
        await loadQSOsFromCache();
        
    } catch (error) {
        console.error('Error initializing headless map:', error);
    }
}

async function loadQSOsFromCache() {
    try {
        console.log('Loading CONFIRMED QSOs from cache...');
        
        const response = await fetch(HEADLESS_CONFIG.cacheFile);
        if (!response.ok) {
            console.error('Cache file not found');
            return;
        }
        
        const cache = await response.json();
        console.log(`Loaded cache with ${cache.total_qsos} QSOs`);
        
        let skipped = 0;
        let notConfirmed = 0;
        
        // First, filter only confirmed QSOs with grid
        const confirmedQsos = [];
        for (const qso of cache.qsos) {
            // Only process QSOs with grid
            if (!qso.grid || qso.grid.length < 4) {
                skipped++;
                continue;
            }
            
            // FILTER: Only include QSOs confirmed in LoTW or Clublog
            const lotwConfirmed = qso.lotw_qsl_rcvd === 'Y';
            const clublogConfirmed = qso.clublog_status === 'Y' || qso.qsl_rcvd === 'Y';
            
            if (!lotwConfirmed && !clublogConfirmed) {
                notConfirmed++;
                continue;
            }
            
            confirmedQsos.push(qso);
        }
        
        // Group confirmed QSOs by CALL-GRID and keep only first per combination
        const byCallGrid = new Map();
        for (const qso of confirmedQsos) {
            const key = qso.call + '-' + qso.grid;
            const qsoTime = moment(qso.date + ' ' + qso.time, 'YYYYMMDD HHmmss');
            
            if (!byCallGrid.has(key)) {
                byCallGrid.set(key, { qso, time: qsoTime });
            } else {
                // Keep the older QSO
                const existing = byCallGrid.get(key);
                if (qsoTime.isBefore(existing.time)) {
                    byCallGrid.set(key, { qso, time: qsoTime });
                }
            }
        }
        
        // Now add unique confirmed QSOs to map
        let loaded = 0;
        for (const [key, { qso }] of byCallGrid) {
            const formattedQso = {
                call: qso.call,
                band: qso.band,
                mode: qso.mode,
                freq: qso.freq,
                grid: qso.grid,
                dxcc: qso.dxcc || '',
                time: moment(qso.date + ' ' + qso.time, 'YYYYMMDD HHmmss'),
                comment: qso.comment || '',
                sig: qso.sig || '',
                sig_info: qso.sig_info || ''
            };
            
            // Add to map
            if (typeof putQSOIntoDataMap === 'function') {
                putQSOIntoDataMap(formattedQso);
                loaded++;
            }
        }
        
        console.log(`✓ Loaded ${loaded} CONFIRMED QSOs (${skipped} skipped - no grid, ${notConfirmed} not confirmed, ${confirmedQsos.length - loaded} duplicates)`);
        
        // Redraw map
        setTimeout(function() {
            if (typeof redrawAll === 'function') {
                redrawAll();
                console.log('Map rendered');
            }
        }, 100);
        
    } catch (error) {
        console.error('Error loading QSOs from cache:', error);
    }
}
