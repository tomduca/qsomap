/////////////////////////////
// LOCAL STORAGE FUNCTIONS //
/////////////////////////////
// noinspection JSJQueryEfficiency

// Load from local storage or use default
function localStorageGetOrDefault(key, defaultVal) {
    const valStr = localStorage.getItem(key);
    if (null === valStr) {
        return defaultVal;
    } else {
        return JSON.parse(valStr);
    }
}

// Load from local storage and set GUI up appropriately
function loadLocalStorage() {
    $("#appendOnLoad").prop('checked', localStorageGetOrDefault('appendOnLoad', false));
    $("#userLookupEnabled").prop('checked', localStorageGetOrDefault('userLookupEnabled', true));
    $("#refLookupEnabled").prop('checked', localStorageGetOrDefault('refLookupEnabled', true));
    $("#basemap").val(localStorageGetOrDefault('basemap', 'Esri.NatGeoWorldMap'));
    $("#basemapOpacity").val(localStorageGetOrDefault('basemapOpacity', 0.5));
    $("#myCall").val(localStorageGetOrDefault('myCall', ''));
    $("#qthGrid").val(localStorageGetOrDefault('qthGrid', ''));
    $("#showMaidenheadGrid").prop('checked', localStorageGetOrDefault('showMaidenheadGrid', false));
    $("#showCQZones").prop('checked', localStorageGetOrDefault('showCQZones', false));
    $("#showITUZones").prop('checked', localStorageGetOrDefault('showITUZones', false));
    $("#showWABWAIGrid").prop('checked', localStorageGetOrDefault('showWABWAIGrid', false));
    $("#markersEnabled").prop('checked', localStorageGetOrDefault('markersEnabled', true));
    $("#qthMarker").prop('checked', localStorageGetOrDefault('qthMarker', true));
    $("#linesEnabled").prop('checked', localStorageGetOrDefault('linesEnabled', true));
    $("#gridSquaresEnabled").prop('checked', localStorageGetOrDefault('gridSquaresEnabled', false));
    $("#labelGridSquaresWorked").prop('checked', localStorageGetOrDefault('labelGridSquaresWorked', false));
    $("#colourLines").prop('checked', localStorageGetOrDefault('colourLines', true));
    $("#thickLines").prop('checked', localStorageGetOrDefault('thickLines', true));
    $("#heatmapEnabled").prop('checked', localStorageGetOrDefault('heatmapEnabled', false));
    $("#perBandHeatmapEnabled").prop('checked', localStorageGetOrDefault('perBandHeatmapEnabled', false));
    const tmpBandColours = localStorageGetOrDefault('bandColours', true);
    const tmpModeColours = localStorageGetOrDefault('modeColours', false);
    $("#bandColours").prop('checked', tmpBandColours);
    $("#modeColours").prop('checked', tmpModeColours);
    $("#fixedColour").prop('checked', !tmpBandColours && !tmpModeColours);
    $("#fixedMarkerColour").val(localStorageGetOrDefault('fixedMarkerColour', '#1e90ff'));
    $("#markerSize").val(localStorageGetOrDefault('markerSize', 1));
    $("#circleMarkers").prop('checked', localStorageGetOrDefault('circleMarkers', false));
    $("#outlineMarkers").prop('checked', localStorageGetOrDefault('outlineMarkers', true));
    $("#outdoorSymbols").prop('checked', localStorageGetOrDefault('outdoorSymbols', false));
    $("#hybridMarkerSize").prop('checked', localStorageGetOrDefault('hybridMarkerSize', false));
    $("#showMarkerShadows").prop('checked', localStorageGetOrDefault('showMarkerShadows', true));
    $("#showCallsignLabels").prop('checked', localStorageGetOrDefault('showCallsignLabels', false));
    $("#showGridSquareLabels").prop('checked', localStorageGetOrDefault('showGridSquareLabels', false));
    $("#showDistanceLabels").prop('checked', localStorageGetOrDefault('showDistanceLabels', false));
    $("#distanceUnit").val(localStorageGetOrDefault('distanceUnit', 'km'));
    $("#showComments").prop('checked', localStorageGetOrDefault('showComments', true));
    $("#inferOutdoorActivitiesFromComments").prop('checked', localStorageGetOrDefault('inferOutdoorActivitiesFromComments', false));
    $("#fineZoomControl").prop('checked', localStorageGetOrDefault('fineZoomControl', false));

    // Band colour schemes
    getAvailableBandColorSchemes().forEach(sc => $("#bandColorScheme").append($('<option>', {
        value: sc,
        text: sc
    })));
    const tmpBandColorScheme = localStorageGetOrDefault('bandColorScheme', bandColorScheme);
    $("#bandColorScheme").val(tmpBandColorScheme);
    setBandColorSchemeQSOMap(tmpBandColorScheme);
    $("#theme").val(localStorageGetOrDefault('theme', 'auto'));

    // Load lookup data. This had to be converted to an object for storage, now we need it back as a map.
    const lookupDataStr = localStorage.getItem('lookupData');
    if (lookupDataStr !== null) {
        lookupData = new Map(Object.entries(JSON.parse(lookupDataStr)));
    }

    // Re-apply theme and display settings now that the various controls have been set to their stored values
    setColorScheme();
    updateDisplay();
}
