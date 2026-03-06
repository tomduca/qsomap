/////////////////////////////
//     CONTROLS SETUP      //
/////////////////////////////

// Listen for file select
$("#fileSelect").change(function () {
    // Get the file and parse it
    let file = $(this).prop('files')[0];
    const reader = new FileReader();
    reader.addEventListener(
        "load",
        () => loadFile(reader.result),
        false,
    );
    if (file) {
        reader.readAsText(file);
    }
});

// Update the various displays based on new settings. Called on UI control changes.
function updateDisplay() {
    setBasemap($("#basemap").val());
    setBasemapOpacity($("#basemapOpacity").val());
    updatePosFromGridInput();
    enableMaidenheadGrid($("#showMaidenheadGrid").is(':checked'));
    enableCQZones($("#showCQZones").is(':checked'));
    enableITUZones($("#showITUZones").is(':checked'));
    enableWABWAIGrid($("#showWABWAIGrid").is(':checked'));
    enableHeatmap($("#heatmapEnabled").is(':checked'));
    enablePerBandHeatmap($("#perBandHeatmapEnabled").is(':checked'));
    setFineZoomControl($("#fineZoomControl").is(':checked'));
}

// Save UI settings to localstorage. Called on UI control changes.
function saveLocalStorage() {
    localStorage.setItem('markersEnabled', $("#markersEnabled").is(':checked'));
    localStorage.setItem('userLookupEnabled', $("#userLookupEnabled").is(':checked'));
    localStorage.setItem('refLookupEnabled', $("#refLookupEnabled").is(':checked'));
    localStorage.setItem('myCall', JSON.stringify($("#myCall").val()));
    localStorage.setItem('qthMarker', $("#qthMarker").is(':checked'));
    localStorage.setItem('linesEnabled', $("#linesEnabled").is(':checked'));
    localStorage.setItem('gridSquaresEnabled', $("#gridSquaresEnabled").is(':checked'));
    localStorage.setItem('labelGridSquaresWorked', $("#labelGridSquaresWorked").is(':checked'));
    localStorage.setItem('colourLines', $("#colourLines").is(':checked'));
    localStorage.setItem('thickLines', $("#thickLines").is(':checked'));
    localStorage.setItem('bandColours', $("#bandColours").is(':checked'));
    localStorage.setItem('modeColours', $("#modeColours").is(':checked'));
    localStorage.setItem('fixedMarkerColour', JSON.stringify($("#fixedMarkerColour").val()));
    localStorage.setItem('markerSize', $("#markerSize").val());
    localStorage.setItem('outlineMarkers', $("#outlineMarkers").is(':checked'));
    localStorage.setItem('circleMarkers', $("#circleMarkers").is(':checked'));
    localStorage.setItem('hybridMarkerSize', $("#hybridMarkerSize").is(':checked'));
    const showMarkerShadows = $("#showMarkerShadows").is(':checked');
    showMarkerShadows ? $(".leaflet-shadow-pane").show() : $(".leaflet-shadow-pane").hide();
    localStorage.setItem('showMarkerShadows', showMarkerShadows);
    localStorage.setItem('outdoorSymbols', $("#outdoorSymbols").is(':checked'));
    localStorage.setItem('showCallsignLabels', $("#showCallsignLabels").is(':checked'));
    localStorage.setItem('showGridSquareLabels', $("#showGridSquareLabels").is(':checked'));
    localStorage.setItem('showDistanceLabels', $("#showDistanceLabels").is(':checked'));
    localStorage.setItem('distanceUnit', JSON.stringify($("#distanceUnit").val()));
    localStorage.setItem('showComments', $("#showComments").is(':checked'));
    localStorage.setItem('inferOutdoorActivitiesFromComments', $("#inferOutdoorActivitiesFromComments").is(':checked'));
    redrawAll();
}

function updatePosFromGridInput() {
    qthGrid = $("#qthGrid").val().toUpperCase();
    $("#stats-qth").text(qthGrid);
    localStorage.setItem('qthGrid', JSON.stringify(qthGrid));

    let pos = latLonForGridCentre(qthGrid);
    if (pos != null) {
        setQTH(pos);
    }
}

// Handle switching between append and replace existing QSOs on load
$(".loadBehaviourControl").change(function () {
    localStorage.setItem('appendOnLoad', $("#appendOnLoad").is(':checked'));
});

// Handle clearing existing QSOs
$("#clearQSOs").click(function () {
    clearData();
    redrawAll();
    recalculateStats();
});

// Listen for toggle changes where another should be toggled off when this is toggled on. These are called before the
// generic binding to .control so that their action happens before updateDisplay gets called.
$("#bandColours").change(function () {
    if ($("#bandColours").is(':checked')) {
        $("#modeColours").prop('checked', false);
    }
});
$("#modeColours").change(function () {
    if ($("#modeColours").is(':checked')) {
        $("#bandColours").prop('checked', false);
    }
});

// Listen for circle marker type toggle
$("#circleMarkers").change(function () {
    if ($("#circleMarkers").is(':checked')) {
        $("#outdoorSymbols").prop('checked', false);
        $("#hybridMarkerSize").prop('checked', false);
        $("#showMarkerShadows").prop('checked', false);
    }
});

// Listen for band colour scheme change
$("#bandColorScheme").change(function () {
    setBandColorSchemeQSOMap($(this).val());
    localStorage.setItem('bandColorScheme', JSON.stringify($(this).val()));
});

// Listen for UI colour scheme change
$('#theme').change(function() {
    localStorage.setItem('theme', JSON.stringify($(this).val()));
    setColorScheme();
});

// Listen for control changes. Most controls have this class, and therefore all perform the same updateupdateDisplay()
// and saveLocalStorage() functions when they are changed. WARNING: The order in which jQuery bindings are done is
// important, some bindings deliberately happen before this section, and others after.
$(".control").change(function() {
    updateDisplay();
    saveLocalStorage();
});
$(".textControl").on("input", function() {
    updateDisplay();
    saveLocalStorage();
});

// Populate the filter controls based on the years, bands and modes in the data we have loaded
function populateFilterControls(years, bands, modes) {
    $("#filter-year").empty();
    $("#filter-year").append($("<option></option>").attr("value", "*").text("All years"));
    // Years are sorted in reverse
    Array.from(years).sort().reverse().forEach(function (y) {
        $("#filter-year").append($("<option></option>").attr("value", y).text(y));
    });

    $("#filter-band").empty();
    $("#filter-band").append($("<option></option>").attr("value", "*").text("All bands"));
    // Bands are sorted according to the order they appear in our global list of bands
    Array.from(bands).filter(b => b != null && b.length > 0)
        .sort((a, b) => getKnownBands().findIndex((band) => band === a) - getKnownBands().findIndex((band) => band === b)).forEach(function (b) {
        $("#filter-band").append($("<option></option>").attr("value", b).text(b));
    });

    $("#filter-mode").empty();
    $("#filter-mode").append($("<option></option>").attr("value", "*").text("All modes"));
    // Modes are sorted alphabetically
    Array.from(modes).filter(m => m.length > 0).sort().forEach(function (m) {
        $("#filter-mode").append($("<option></option>").attr("value", m).text(m));
    });
}
