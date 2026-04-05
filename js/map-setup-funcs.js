/////////////////////////////
//       MAP SETUP         //
/////////////////////////////

function setUpMap() {
    // Create map
    map = L.map('map', {
        zoomControl: false,
        minZoom: 2,
        maxZoom: 17,
        zoomSnap: 1
    });

    // Create custom panes with explicit z-indices to enforce layer ordering
    map.createPane('overlaysPane');
    map.getPane('overlaysPane').style.zIndex = 250;
    map.getPane('overlaysPane').style.pointerEvents = 'none';
    map.createPane('heatmapPane');
    map.getPane('heatmapPane').style.zIndex = 260;
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
        color : MAIDENHEAD_GRID_COLOR_LIGHT,
        pane: 'overlaysPane'
    });

    // Add CQ zone layer (toggleable)
    cqZones = L.cqzones({
        color : CQ_ZONES_COLOR_LIGHT,
        pane: 'overlaysPane'
    });

    // Add worked CQ zones highlight layer (toggleable)
    cqZonesWorked = L.cqzonesWorked({
        color : CQ_ZONES_WORKED_COLOR_LIGHT,
        pane: 'overlaysPane'
    });

    // Add ITU zone layer (toggleable)
    ituZones = L.ituzones({
        color : ITU_ZONES_COLOR_LIGHT,
        pane: 'overlaysPane'
    });

    // Add worked ITU zones highlight layer (toggleable)
    ituZonesWorked = L.ituzonesWorked({
        color : ITU_ZONES_WORKED_COLOR_LIGHT,
        pane: 'overlaysPane'
    });

    // Add WAB/WAI grid layer (toggleable)
    wabwaiGrid = L.workedAllBritainIreland({
        color : WAB_WAI_GRID_COLOR_LIGHT,
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

    // Add gridsquares worked layers
    gridSquaresWorkedLayer = new L.LayerGroup();
    gridSquaresWorkedLayer.addTo(map);
    gridSquaresWorkedLabelsLayer = new L.LayerGroup();
    gridSquaresWorkedLabelsLayer.addTo(map);

    // Add heatmap layers
    heatmapLayer = L.heatLayer([], {radius: 25, pane: 'heatmapPane'});
    perBandHeatmapsGroup = new L.LayerGroup();
    HEATMAP_BAND_RENDER_ORDER.forEach(bandName => {
        let l = L.heatLayer([], {radius: 25, gradient: {1: bandToColor(bandName)}, pane: 'heatmapPane'});
        l.addTo(perBandHeatmapsGroup);
        perBandHeatmaps.set(bandName, l);
    });

    // Display a default view.
    map.setView([30, 0], 3);
}
