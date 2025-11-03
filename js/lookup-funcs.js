/////////////////////////////
//    LOOKUP FUNCTIONS     //
/////////////////////////////

// Use a QSO's callsign to look up data through the Spothole API and fill in any missing info.
function performCallsignLookup(qso) {
    $.ajax({
        url: SPOTHOLE_BASE_URL + "/lookup/call",
        data: {call: qso.call},
        dataType: 'json',
        async: false,
        timeout: 10000,
        success: async function (result) {
            if (result.grid && result.grid.length > 0 && !qso.grid && qso.grid.toUpperCase() !== "AA00" && qso.grid.toUpperCase() !== "AA00AA" && qso.grid.toUpperCase() !== "AA00AA00") {
                qso.grid = result.grid;
            }

            if (result.name && result.name.length > 0 && !qso.name) {
                qso.name = name;
            }

            let bestQTH = (result.qth && result.qth.length > 0) ? result.qth : result.country;
            if (bestQTH.length > 0 && !qso.qth) {
                qso.qth = bestQTH;
            }

            // Store the looked up info in case we see this callsign again, then we don't need to query the
            // API unnecessarily.
            lookupData.set(qso.call, {grid: result.grid, name: result.name, qth: bestQTH});
            localStorage.setItem('lookupData', JSON.stringify(Object.fromEntries(lookupData)));
        },
        error: function () {
            console.log("Error from Spothole when looking up " + qso.call);
        }
    });
}

// Use a QSO's SIG reference to look up a grid via the Spothole API.
function performSIGRefLookup(qso) {
    $.ajax({
        url: SPOTHOLE_BASE_URL + "/lookup/sigref",
        data: {sig: qso.sigRefs[0].program, id: qso.sigRefs[0].ref},
        dataType: 'json',
        async: false,
        timeout: 10000,
        success: async function (result) {
            if (result.grid != null) {
                qso.grid = result.grid;
            }
            qso.qth = result.ref
            if (result.name != null) {
                qso.qth = result.ref + " " + result.name;
            }
        }
    });
}

// Process an item from the queue. Called regularly, this looks for a queued QSO (i.e. no grid) and tries to use the
// Spothole API or our local cache of QRZ.com results to populate the grid fields. If successful,
// the QSO will be inserted into the proper data map. The map objects will then be updated to match.
// We only clear one at a time this way to avoid overloading the remote APIs.
function processQSOFromQueue() {
    if (queue.length > 0) {
        // Pop the next QSO out of the queue
        let qso = queue.pop();
        // We have something in the queue. First see if it has a POTA/SOTA/WWBOTA reference; we can then query the
        // API for the reference's location.
        if (qso.sigRefs.length > 0) {
            performSIGRefLookup(qso)
        }

        // If we still have some missing data (and we will, because the SIG ref lookup above don't give an operator
        // name), we can then move on to querying the callsign data.
        // But first, check if we have any cached data from previous lookups. This can prevent us from
        // calling the API again for the same data. Name, QTH and grid are updated in the QSO if this data is found and
        // the QSO doesn't already have that data. (Not overwriting is important, because we don't want to risk
        // overwriting a POTA reference grid with the user's home QTH grid from QRZ etc.)
        if (lookupData.has(qso.call)) {
            if (!qso.name) {
                qso.name = lookupData.get(qso.call).name;
            }
            if (!qso.qth) {
                qso.qth = lookupData.get(qso.call).qth;
            }
            if (!qso.grid) {
                qso.grid = lookupData.get(qso.call).grid;
            }
        }

        // Now if we still have missing data, move on to making an actual query of the callsign
        if (!qso.grid || !qso.qth || !qso.name) {
            performCallsignLookup(qso);
        }

        // If we got a grid from any of the above methods, we can put the QSO into the data map and render it.
        if (qso.grid) {
            putQSOIntoDataMap(qso);
            redraw(qso.call + "-" + qso.grid);
        } else {
            // We tried and failed to look up this QSO with any available methods.
            failedLookupCount++;
        }
    }
}
