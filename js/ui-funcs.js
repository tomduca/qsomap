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
    updateStatsCallAndQTH();
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
    const showMarkerShadows = $("#showMarkerShadows").is(':checked');
    showMarkerShadows ? $(".leaflet-shadow-pane").show() : $(".leaflet-shadow-pane").hide();
    redrawAll();
}

// Update the callsign and QTH displayed on the stats page
async function updateStatsCallAndQTH() {
    let text = "";
    $("#stats-call").text($("#myCall").val());
    if ($("#mySIGRef").val() !== "") {
        if ($("#mySIGRefName").text() !== "") {
            text = " at " + $("#mySIGRef").val() + " " + $("#mySIGRefName").text();
        } else {
            text = " at " + $("#mySIGRef").val();
        }
    } else if ($("#qthGrid").val() !== "") {
        text = " in " + $("#qthGrid").val();
    }
    $("#stats-qth").text(text);
}

function updatePosFromGridInput() {
    let qthGrid = $("#qthGrid").val().toUpperCase();
    let pos = latLonForGridCentre(qthGrid);
    if (pos != null) {
        setQTH(pos);
    }
}

// Handle clearing existing QSOs
$("#clearQSOs").click(function () {
    clearData();
    redrawAll();
    recalculateStats();
});

// Listen for changes to SIG and SIG Ref that mean we can look up the name
$("#mySIG").change(function () {
    fetchSIGRefName();
});
$("#mySIGRef").change(function () {
    fetchSIGRefName();
});
async function fetchSIGRefName() {
    if ($('#refLookupEnabled').is(':checked') && $("#mySIG").val() !== "" && $("#mySIGRef").val() !== "") {
        const result = await performSIGRefLookupInner($("#mySIG").val(), $("#mySIGRef").val());
        if (result != null && result.name) {
            $("#mySIGRefName").text(result.name);
        } else {
            $("#mySIGRefName").text("");
        }
    } else {
        $("#mySIGRefName").text("");
    }
    updateStatsCallAndQTH();
}

// Listen for circle marker type toggle. When circle markers are enabled, uncheck and save the
// incompatible options that are being turned off.
$("#circleMarkers").change(function () {
    if ($("#circleMarkers").is(':checked')) {
        $("#outdoorSymbols").prop('checked', false).trigger('change');
        $("#hybridMarkerSize").prop('checked', false).trigger('change');
        $("#showMarkerShadows").prop('checked', false).trigger('change');
    }
});

// Listen for band colour scheme change (side-effect: update colour samples in menu)
$("#bandColorScheme").change(function () {
    setBandColorSchemeQSOMap($(this).val());
});

// Listen for UI colour scheme change (side-effect: apply colour scheme)
$('#theme').change(function() {
    setColorScheme();
});

// On a change of any control in the form, update the display and save the new value to localstorage. WARNING: The order
// in which jQuery bindings are done is important; some bindings deliberately happen before this section, and others after.
$(document).on('change', '[name]', function() {
    updateDisplay();
    if (this.type === 'checkbox') {
        localStorage.setItem(this.name, JSON.stringify(this.checked));
    } else {
        localStorage.setItem(this.name, JSON.stringify(this.value));
    }
});

// Panel toggle: show clicked panel, hide others
$('.panel-btn').on('click', function() {
    var $target = $($(this).data('bs-target'));
    var isVisible = $target.is(':visible');
    $('.panel-card').hide();
    if (!isVisible) $target.show();
});

// Panel close buttons
$(document).on('click', '.panel-card .btn-close', function() {
    $(this).closest('.panel-card').hide();
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
