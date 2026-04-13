///////////////////////////////
// DATA MANAGEMENT FUNCTIONS //
///////////////////////////////

// Put a QSO into the main data map. It must have a callsign and grid at this point. This can be called either from
// loadFile() if the QSO is fully populated in the file, or from processQSOFromQueue() if the QSO was in the queue and
// we now have a lookup successfully returning a grid.
function putQSOIntoDataMap(qso) {
    // The data map is keyed by "CALL-GRID" so we do not duplicate the combination. If there has not
    let key = qso.call + "-" + qso.grid;
    if (data.has(key)) {
        // We already have a QSO with this call-grid combination.
        // Keep only the first (oldest) QSO.
        let existingQso = data.get(key).qsos[0];
        if (qso.time && existingQso.time && qso.time.isBefore(existingQso.time)) {
            // This QSO is older, replace the existing one
            data.set(key, {qsos: [qso], call: qso.call, grid: qso.grid, dxcc: qso.dxcc});
        }
        // If the new QSO is not older, we ignore it (keep the first one)
    } else {
        // This is the first time we've seen this call-grid combination, so create a new item.
        data.set(key, {qsos: [qso], call: qso.call, grid: qso.grid, dxcc: qso.dxcc});
    }

    // If we haven't seen a name or a QTH description for this call & grid before, add them at the top
    // level, to avoid display functions having to iterate through qsos to find this.
    if (!data.get(key).name && qso.name) {
        data.get(key).name = qso.name;
    }
    if (!data.get(key).qth && qso.qth) {
        data.get(key).qth = qso.qth;
    }
}

// Clear the main "data" map, queue, associated counters, and our tracking of what years/bands/modes we have loaded.
// Called from the Clear button or before loading a new data file if we are in Replace rather than Append mode.
function clearData() {
    data = new Map();
    queue = [];
    qsoCount = 0;
    loadedAtLeastOnce = false;
    // Don't reset the grid as this will get annoying for people working with Cabrillo files that don't contain
    // that info
    // $("#qthGrid").val("");
    $("#myCall").val("");
    $("#mySIG").val("");
    $("#mySIGRef").val("");
    $("#mySIGRefName").text("");
    updateStatsCallAndQTH();
    years = new Set();
    bands = new Set();
    modes = new Set();
    populateFilterControls([], [], []);
}
