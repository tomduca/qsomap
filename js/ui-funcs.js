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
    myCall = $("#myCall").val();
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

function updatePosFromGridInput() {
    qthGrid = $("#qthGrid").val().toUpperCase();
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
$('#main-form').on('change', '[name]', function() {
    updateDisplay();
    if (this.type === 'checkbox') {
        localStorage.setItem(this.name, JSON.stringify(this.checked));
    } else {
        localStorage.setItem(this.name, JSON.stringify(this.value));
    }
});

// Stats pop-out panel
$('#stats-popout-link').on('click', function(e) {
    e.preventDefault();
    $('#stats-float-call').text($('#myCall').val() || '');
    $('#stats-float-qth').text($('#qthGrid').val() || '');
    $('#stats-content-inner').detach().appendTo('#stats-float-body');
    $('#stats-float').show();
    bootstrap.Offcanvas.getInstance($('#sidebar')[0]).hide();
});

$('#stats-float-close').on('click', function() {
    $('#stats-content-inner').detach().prependTo('#collapse-stats .accordion-body');
    $('#stats-float').hide();
});

$('#stats-float .card-header').on('mousedown', function(e) {
    if ($(e.target).closest('button,a').length) return;
    var pos = {x: e.pageX - $('#stats-float').offset().left, y: e.pageY - $('#stats-float').offset().top};
    $(document).on('mousemove.statsdrag', function(e) {
        $('#stats-float').css({left: e.pageX - pos.x, top: e.pageY - pos.y});
    }).on('mouseup.statsdrag', function() { $(document).off('.statsdrag'); });
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
