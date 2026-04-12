/////////////////////////////
//       MAP SETUP         //
/////////////////////////////

function setUpMap() {
    // Create map
    map = L.map('map', {
        zoomControl: false,
        attributionControl: true,  // Enable attribution
        minZoom: 2,
        maxZoom: 17,
        zoomSnap: 1
    });
    
    // Add custom attribution for original project on bottom-left
    L.control.attribution({
        position: 'bottomleft',
        prefix: 'Based on <a href="https://git.ianrenton.com/ian/qsomap" target="_blank">QSO Map</a> by Ian Renton'
    }).addTo(map);

    // Create custom panes with explicit z-indices to enforce layer ordering
    map.createPane('overlaysPane');
    map.getPane('overlaysPane').style.zIndex = 250;
    map.getPane('overlaysPane').style.pointerEvents = 'none';
    map.createPane('workedOverlaysPane');
    map.getPane('workedOverlaysPane').style.zIndex = 260;
    map.getPane('workedOverlaysPane').style.pointerEvents = 'none';
    map.createPane('heatmapPane');
    map.getPane('heatmapPane').style.zIndex = 270;
    map.getPane('heatmapPane').style.pointerEvents = 'none';
    map.createPane('linesPane');
    map.getPane('linesPane').style.zIndex = 280;
    map.getPane('linesPane').style.pointerEvents = 'none';
    map.createPane('ownPosPane');
    map.getPane('ownPosPane').style.zIndex = 310;
    map.getPane('ownPosPane').style.pointerEvents = 'auto';
    map.createPane('qsoMarkersPane');
    map.getPane('qsoMarkersPane').style.zIndex = 340;
    map.getPane('qsoMarkersPane').style.pointerEvents = 'auto';

    // Add basemap
    basemapLayer = L.tileLayer.provider($('#basemap').val(), {
        opacity: parseFloat($('#basemapOpacity').val()),
        edgeBufferTiles: 1
    });
    basemapLayer.addTo(map);

    // Add Maidenhead grid (toggleable)
    maidenheadGrid = L.maidenhead({
        color : $('#maidenheadGridColour').val(),
        pane: 'overlaysPane'
    });

    gridSquaresWorked = L.gridSquaresWorked({
        color: $('#maidenheadGridWorkedColour').val(),
        pane: 'workedOverlaysPane'
    });

    // Add CQ zone layer (toggleable)
    cqZones = L.cqzones({
        color : $('#cqZonesColour').val(),
        pane: 'overlaysPane'
    });

    // Add worked CQ zones highlight layer (toggleable)
    cqZonesWorked = L.cqzonesWorked({
        color : $('#cqZonesWorkedColour').val(),
        pane: 'workedOverlaysPane'
    });

    // Add ITU zone layer (toggleable)
    ituZones = L.ituzones({
        color : $('#ituZonesColour').val(),
        pane: 'overlaysPane'
    });

    // Add worked ITU zones highlight layer (toggleable)
    ituZonesWorked = L.ituzonesWorked({
        color : $('#ituZonesWorkedColour').val(),
        pane: 'workedOverlaysPane'
    });

    // Add WAB/WAI grid layer (toggleable)
    wabwaiGrid = L.workedAllBritainIreland({
        color : $('#wabwaiGridColour').val(),
        pane: 'overlaysPane'
    });

    // Add marker layer
    markersLayer = new L.LayerGroup();
    markersLayer.addTo(map);

    // Set up Overlapping Marker Spiderfier so markers at the same position fan out on click
    oms = new OverlappingMarkerSpiderfier(map, {nearbyDistance: 20, keepSpiderfied: true});

    // Add lines layer
    linesLayer = new L.LayerGroup();
    linesLayer.addTo(map);

    // Add own position marker layer
    ownPosLayer = new L.LayerGroup();
    ownPosLayer.addTo(map);

    // Add heatmap layers
    heatmapLayer = L.heatLayer([], {radius: 25, pane: 'heatmapPane'});
    perBandHeatmapsGroup = new L.LayerGroup();
    HEATMAP_BAND_RENDER_ORDER.forEach(bandName => {
        // Normalize band name to lowercase for color lookup
        let l = L.heatLayer([], {radius: 25, gradient: {1: bandToColor(bandName.toLowerCase())}, pane: 'heatmapPane'});
        l.addTo(perBandHeatmapsGroup);
        perBandHeatmaps.set(bandName, l);
    });

    // Display a default view.
    map.setView([30, 0], 3);
}
