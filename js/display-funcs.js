/////////////////////////////
//  QSO DISPLAY FUNCTIONS //
/////////////////////////////

// Tracks the currently-loaded basemap provider string to avoid unnecessary tile reloads
let loadedBasemap = "Esri.NatGeoWorldMap";

// Redraw all the objects that are rendered on the map. Clear old markers and draw new ones. This is
// called when a bulk change needs to happen, for example the first load occurs, a clear occurs,
// or a UI change occurs e.g. changing how colours are done for all markers.
function redrawAll() {
    // Clear existing markers, lines and heatmaps
    markers.forEach(marker => markersLayer.removeLayer(marker));
    markers = new Map();
    oms.clearMarkers();
    lines.forEach(line => linesLayer.removeLayer(line));
    lines = new Map();
    gridSquaresWorked.clearGridSquares();
    try {
        heatmapLayer.setLatLngs([]);
    } catch (e) {}
    perBandHeatmapsGroup.eachLayer(function (l) {
        try {
            l.setLatLngs([]);
        } catch (e) {}
    });

    // Add own position marker
    createOwnPosMarker(qthPos);

    // Iterate through qsos, creating markers, lines, worked squares etc. This covers everything displayed per-QSO apart
    // from the heatmaps, which are covered further down.
    data.forEach((value, key) => redraw(key));

    // Calculate data sets for the heatmaps. We have to do this in two stages because the "intensity" value we use when
    // drawing the heatmap is based on how many total qsos there are, or how qsos per band there are. So first, we build
    // data sets. Each item is simply a [lat,lon].
    heatmapData = [];
    perBandHeatmapsData = new Map();
    getKnownBands().forEach(band => perBandHeatmapsData.set(band, []));
    data.forEach((d) => {
        let pos = getIconPosition(d);
        if (pos != null) {
            d.qsos.forEach((qso) => {
                heatmapData.push([pos[0], pos[1], 1]);
                if (qso.band && perBandHeatmapsData.has(qso.band)) {
                    perBandHeatmapsData.get(qso.band).push([pos[0], pos[1], 1]);
                }
            });
        }
    });

    // Now for every point, calculate an intensity based on the total number of points, and store it.
    heatmapData.forEach(d => d[2] = (heatmapData.length > 0) ? (1000 / Math.max(Math.min(heatmapData.length / 100, 5), 1)) : 1000);
    perBandHeatmapsData.forEach(pbd => {
        pbd.forEach(d => d[2] = (heatmapData.length > 0) ? (5000 / Math.max(Math.min(pbd.length / 100, 5), 1)) : 1000);
    });

    // Load the data into the heatmaps
    try {
    heatmapLayer.setLatLngs(heatmapData);
    } catch (e) {}
    perBandHeatmaps.forEach((value, key) => {
        try {
        value.setLatLngs(perBandHeatmapsData.get(key));
        } catch (e) {}
    });
}

// Redraw a specific QSO (which can include creating the marker for the first time if it doesn't
// already exist, but cannot include deleting it). This is called when processing updates from the
// queue, because at this point we know we only need to update a single marker, and we don't need
// to redraw everything. (This is also delegated to from the redrawAll method to avoid duplication
// of code.) The key parameter is the CALLSIGN-GRID key from the "data" map.
function redraw(key) {
    let d = data.get(key);
    const pos = getIconPosition(d);
    const markersEnabled = $('#markersEnabled').is(':checked');
    const circleMarkers = $('#circleMarkers').is(':checked');
    const markerSize = parseFloat($('#markerSize').val());
    const hybridMarkerSize = $('#hybridMarkerSize').is(':checked');
    const outlineMarkers = $('#outlineMarkers').is(':checked');
    const linesEnabled = $('#linesEnabled').is(':checked');
    const colourLines = $('#colourLines').is(':checked');
    const thickLines = $('#thickLines').is(':checked');
    const gridSquaresEnabled = $('#gridSquaresEnabled').is(':checked');
    const labelGridSquaresWorked = $('#labelGridSquaresWorked').is(':checked');
    if (anyQSOMatchesFilter(d) && pos != null) {
        // Add or update marker
        if (markersEnabled) {
            // Get an existing marker if we have one, else create a new one.
            let m;
            if (markers.has(key)) {
                m = markers.get(key)
            } else if (circleMarkers) {
                // Get color before creating marker so it's set from the start
                const color = qsoToColour(d);
                m = L.circleMarker(pos, { 
                    radius: 5 * markerSize, 
                    fillOpacity: 1.0, 
                    opacity: 1.0, 
                    weight: 1, 
                    fill: true, 
                    fillColor: color,
                    color: color,
                    pane: 'qsoMarkersPane' 
                });
            } else {
                m = L.marker(pos, { pane: 'qsoMarkersPane' });
            }

            // Set the icon for the marker
            if (circleMarkers) {
                // Update the colour if marker already existed
                if (markers.has(key)) {
                    const color = qsoToColour(d);
                    m.setStyle({fillColor: color, color: color});
                }
            } else {
                // Set the icon
                m.setIcon(getIcon(d, markerSize));
            }

            // Set popup text for the marker
            m.bindPopup(getPopupText(d));

            // Create label under the marker
            let tooltipText = getTooltipText(d);
            if (tooltipText) {
                m.bindTooltip(tooltipText, {permanent: true, direction: 'bottom', offset: L.point(0, -10)});
            }

            // Use outlined icons if requested (circle markers version, needs doing before adding to layer)
            if (circleMarkers) {
                m.options.stroke = outlineMarkers;
            }

            // If this marker was newly created, add it to the layer and register with OMS
            if (!markers.has(key)) {
                markersLayer.addLayer(m);
                oms.addMarker(m);
            }

            // If we are using hybrid marker size and this is a non-xOTA marker, reduce its size
            let thisMarkerSize = markerSize;
            if (hybridMarkerSize && !circleMarkers && (getIconName(d) === "fa-crosshairs" || getIconName(d) === "fa-none")) {
                thisMarkerSize = thisMarkerSize - 0.25;
                if (thisMarkerSize < 0.125) {
                    thisMarkerSize = 0.125;
                }
                m.setIcon(getIcon(d, thisMarkerSize));
            }

            // Adjust marker size (if we're using real markers not circles, circles already have their radius set at
            // creation, whereas markers need CSS applied here)
            if (!circleMarkers) {
                $(m._icon).find("svg").css("width", (32 * thisMarkerSize) + "px");
                $(m._icon).find("svg").css("height", (44 * thisMarkerSize) + "px");
                $(m._icon).find("svg").css("margin-top", ((1 - thisMarkerSize) * 40) + "px");
                $(m._icon).find("svg").css("margin-left", ((1 - thisMarkerSize) * 8) + "px");
                $(m._icon).find("i").css("font-size", (14 + (thisMarkerSize - 1) * 10 - ((getIconName(d) === "fa-circle") ? 2 : 0)) + "px");
                $(m._icon).find("i").css("margin-top", (10 + (1 - thisMarkerSize) * 28 + ((getIconName(d) === "fa-circle") ? 2 : 0)) + "px");
                $(m._icon).find("i").css("position", "absolute");
                $(m._icon).find("i").css("top", "0px");
                let ml = 0;
                if (thisMarkerSize > 1.4) {
                    ml = 3;
                } else if (thisMarkerSize < 0.9 || (thisMarkerSize > 1.1 && thisMarkerSize < 1.4)) {
                    ml = 1;
                }
                $(m._icon).find("i").css("margin-left", ml + "px");
            }

            // Use outlined icons if requested (standard markers version, needs doing after adding to layer)
            if (!circleMarkers && outlineMarkers) {
                $(m._icon).addClass("outlinedmarker");
            }

            // Store the marker for next time
            markers.set(key, m);
        }

        // Add or replace geodesic line
        if (linesEnabled && qthPos != null) {
            // Leaflet.Geodesic doesn't support changing the style of a line after creation, so we need
            // to remove the existing line if it exists, and replace it.
            if (lines.has(key)) {
                linesLayer.removeLayer(lines.get(key));
            }

            let line = L.geodesic([qthPos, pos], {
                color: colourLines ? qsoToColour(d) : "black",
                wrap: false,
                steps: 5,
                weight: thickLines ? 3 : 1,
                pane: 'linesPane'
            });
            linesLayer.addLayer(line);

            // Store the line so we can clear it next time
            lines.set(key, line);
        }

        // Add worked square. The layer handles deduplication internally.
        if (gridSquaresEnabled) {
            gridSquaresWorked.addGridSquare(d.grid.substring(0, 4), labelGridSquaresWorked);
        }
    }
}

// Zoom the display to fit all markers, so long as we have at least two so the zoom isn't janky
function zoomToFit() {
    var markersTemp = [...markers.values()];
    if (ownPosMarker != null) {
        markersTemp.push(ownPosMarker);
    }
    if (markersTemp.length >= 2) {
        var group = new L.featureGroup(markersTemp);
        map.fitBounds(group.getBounds().pad(0.1));
    }
}

// Updates overlay layer colours from the UI controls. For static layers (which have no setColor method),
// this recreates the layer objects with the new colour and re-adds them if they were visible.
function updateOverlayColours() {
    // Static layers: recreate with new colour, preserve visibility
    var maidenheadVisible = map.hasLayer(maidenheadGrid);
    var cqZonesVisible = map.hasLayer(cqZones);
    var ituZonesVisible = map.hasLayer(ituZones);
    var wabwaiVisible = map.hasLayer(wabwaiGrid);

    if (maidenheadVisible) { map.removeLayer(maidenheadGrid); }
    if (cqZonesVisible) { map.removeLayer(cqZones); }
    if (ituZonesVisible) { map.removeLayer(ituZones); }
    if (wabwaiVisible) { map.removeLayer(wabwaiGrid); }

    maidenheadGrid = L.maidenhead({ color: $('#maidenheadGridColour').val(), pane: 'overlaysPane' });
    cqZones = L.cqzones({ color: $('#cqZonesColour').val(), pane: 'overlaysPane' });
    ituZones = L.ituzones({ color: $('#ituZonesColour').val(), pane: 'overlaysPane' });
    wabwaiGrid = L.workedAllBritainIreland({ color: $('#wabwaiGridColour').val(), pane: 'overlaysPane' });

    if (maidenheadVisible) { maidenheadGrid.addTo(map); }
    if (cqZonesVisible) { cqZones.addTo(map); }
    if (ituZonesVisible) { ituZones.addTo(map); }
    if (wabwaiVisible) { wabwaiGrid.addTo(map); }

    // Worked layers: update colour in place and redraw
    cqZonesWorked.setColor($('#cqZonesWorkedColour').val());
    ituZonesWorked.setColor($('#ituZonesWorkedColour').val());
    gridSquaresWorked.setColor($('#maidenheadGridWorkedColour').val());
}

// Shows/hides the Maidenhead grid overlay
function enableMaidenheadGrid(show) {
    if (maidenheadGrid) {
        if (show) {
            maidenheadGrid.addTo(map);
        } else {
            map.removeLayer(maidenheadGrid);
        }
    }
}

// Shows/hides the Maidenhead grids worked
function enableMaidenheadGridWorked(show) {
    if (gridSquaresWorked) {
        if (show) {
            gridSquaresWorked.addTo(map);
        } else {
            map.removeLayer(gridSquaresWorked);
        }
    }
}

// Shows/hides the CQ zone overlay
function enableCQZones(show) {
    if (cqZones) {
        if (show) {
            cqZones.addTo(map);
        } else {
            map.removeLayer(cqZones);
        }
    }
}

// Shows/hides the worked CQ zones highlight overlay
function enableCQZonesWorked(show) {
    if (cqZonesWorked) {
        if (show) {
            cqZonesWorked.addTo(map);
        } else {
            map.removeLayer(cqZonesWorked);
        }
    }
}

// Shows/hides the ITU zone overlay
function enableITUZones(show) {
    if (ituZones) {
        if (show) {
            ituZones.addTo(map);
        } else {
            map.removeLayer(ituZones);
        }
    }
}

// Shows/hides the worked ITU zones highlight overlay
function enableITUZonesWorked(show) {
    if (ituZonesWorked) {
        if (show) {
            ituZonesWorked.addTo(map);
        } else {
            map.removeLayer(ituZonesWorked);
        }
    }
}

// Shows/hides the WAB/WAI grid overlay
function enableWABWAIGrid(show) {
    if (wabwaiGrid) {
        if (show) {
            wabwaiGrid.addTo(map);
        } else {
            map.removeLayer(wabwaiGrid);
        }
    }
}

// Shows/hides the Heatmap layer
function enableHeatmap(show) {
    if (heatmapLayer) {
        if (show) {
            // Repopulate the display
            try {
                heatmapLayer.setLatLngs(heatmapData);
            } catch (e) {}
            heatmapLayer.addTo(map);
        } else {
            map.removeLayer(heatmapLayer);
        }
    }
}

// Shows/hides the Per-Band Heatmap layer
function enablePerBandHeatmap(show) {
    if (perBandHeatmapsGroup) {
        if (show) {
            // Repopulate the display
            perBandHeatmaps.forEach((value, key) => {
                try {
                    value.setLatLngs(perBandHeatmapsData.get(key));
                } catch (e) {}
            });
            perBandHeatmapsGroup.addTo(map);
        } else {
            map.removeLayer(perBandHeatmapsGroup);
        }
    }
}

// Enable/disable fine control of the map zoom level
function setFineZoomControl(enable) {
    if (enable) {
        map.options.zoomDelta = 0.25;
        map.options.zoomSnap = 0;
        map.options.wheelPxPerZoomLevel = 200;
    } else {
        map.options.zoomDelta = 1.0;
        map.options.zoomSnap = 1.0;
        map.options.wheelPxPerZoomLevel = 60;
    }
}

// Get text for the normal click-to-appear popups. Takes a data item that may contain multiple QSOs.
function getPopupText(d) {
    let text = "<span style='display:inline-block; white-space: nowrap;'><i class='fa-solid fa-user markerPopupIcon'></i>&nbsp;<span class='popupBlock'><a href='https://www.qrz.com/db/" + d.call + "' target='_blank'><b>" + d.call + "</b></a>";
    if (d.name) {
        text += "&nbsp;&nbsp;" + d.name;
    }
    text += "</span></span><br/>"

    // QTH information. If we have one or more QSOs with SIG/xOTA references but they're all the same, we list them. If
    // we don't have any QSOs with SIG/xOTA references, we use the qth text which will be from QRZ or HamQTH. If we have
    // more than one QSO with *different* references, we only display the grid here, and instead list the references
    // separately under each QSO.
    let sigRefsPerQSO = d.qsos.map(q => listSIGRefsWithLinks(q));
    let sigRefsExist = sigRefsPerQSO.every(v => v.length > 0);
    let sigRefsEqual = sigRefsPerQSO.every((val, i, arr) => val === arr[0]);

    text += "<span style='display:inline-block; white-space: nowrap;'><i class='fa-solid fa-location-dot markerPopupIcon'></i>&nbsp;<span class='popupBlock'>";
    if (!sigRefsExist) {
        if (d.qth) {
            text += d.qth + "&nbsp;&nbsp;&nbsp;";
        }
    } else if (sigRefsEqual) {
        text += sigRefsPerQSO[0] + "&nbsp;&nbsp;&nbsp;";
    }

    if (d.grid) {
        text += formatGrid(d.grid);
        if (qthPos) {
            text += "&nbsp;(" + getDistanceString(d) + ")";
        }
    }
    text += "</span></span><table class='popupQSOTable'>"

    getQSOsMatchingFilter(d).forEach(qso => {
        if (qso.freq || qso.time || (qso.comment && $('#showComments').is(':checked'))) {
            text += "<tr><td><i class='fa-solid fa-comment markerPopupIcon'></i></td><td>";
            if (qso.freq) {
                text += qso.freq.toFixed(3);
                if (qso.mode) {
                    text += "&nbsp;MHz&nbsp;" + qso.mode + "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
                }
            }
            text += "</td><td style='text-align: right'>";
            if (qso.time) {
                text += qso.time.format("HH:mm[,&nbsp;]DD[&nbsp;]MMM[&nbsp;]YYYY");
            }
            text += "</td></tr>";

            // As above, if our SIG/xOTA references were different for each QSO, we now need to list them. Again if one set
            // is empty we use the QTH field if it exists. This could be the case if you have e.g. two QSOs with a hunter,
            // one of which was a P2P and the other they were at home, both in the same grid.
            if (sigRefsExist && !sigRefsEqual) {
                let sigRefs = listSIGRefsWithLinks(qso);
                if (sigRefs.length > 0) {
                    text += "<tr><td></td><td colspan='2'>" + sigRefs + "</td></tr>";
                } else if (d.qth) {
                    text += "<tr><td></td><td colspan='2'>" + d.qth + "</td></tr>";
                }
            }
            if (qso.comment && $('#showComments').is(':checked')) {
                text += "<tr><td></td><td colspan='2'>" + qso.comment + "</td></tr>";
            }
        }
    });
    text += "</table>";
    return text;
}

// Get text for the permanent labels underneath the markers (referred to by Leaflet as tooltips)
function getTooltipText(d) {
    const basemapIsDark = ['CartoDB.DarkMatter', 'Esri.WorldImagery'].includes($('#basemap').val());
    let showCall = $('#showCallsignLabels').is(':checked') && d.call;
    let showGrid = $('#showGridSquareLabels').is(':checked') && d.grid;
    let showDist = $('#showDistanceLabels').is(':checked') && d.grid;
    let labelText = "";

    if (showCall) {
        labelText += d.call;
    }
    if (showGrid) {
        if (labelText) {
            labelText += "<br/>";
        }
        labelText += formatGrid(d.grid);
    }
    if (showDist) {
        if (labelText) {
            labelText += "<br/>";
        }
        labelText += getDistanceString(d);
    }

    if (labelText) {
        return "<div style='padding-top: 5px; color: " + (basemapIsDark ? "white" : "black") + "; text-align: center;'>" + labelText + "</div>";
    } else {
        return "";
    }
}

// Get text for the permanent labels underneath the own QTH marker (referred to by Leaflet as a tooltip).
function getOwnQTHTooltipText() {
    const basemapIsDark = ['CartoDB.DarkMatter', 'Esri.WorldImagery'].includes($('#basemap').val());
    let showCall = $('#showCallsignLabels').is(':checked') && $("#myCall").val() !== "";
    let showGrid = $('#showGridSquareLabels').is(':checked') && $("#qthGrid").val() !== "";
    let labelText = "";

    if (showCall) {
        labelText += $("#myCall").val();
    }
    if (showGrid) {
        if (labelText) {
            labelText += "<br/>";
        }
        labelText += formatGrid($("#qthGrid").val());
    }

    if (labelText) {
        return "<div style='padding-top: 5px; color: " + (basemapIsDark ? "white" : "black") + "; text-align: center;'>" + labelText + "</div>";
    } else {
        return "";
    }
}


// Gets the lat/long position for a data item's grid reference. Null is returned if the position
// is unknown or 0,0. If the user QTH location has been provided, we adjust the longitude of the
// qso to be their longitude +-180 degrees, so that we are correctly displaying markers either
// side of them on the map, and calculating the great circle distance and bearing as the short
// path.
function getIconPosition(d) {
    let grid = d.grid;
    if (grid && latLonForGridCentre(grid) != null) {
        let [lat, lon] = latLonForGridCentre(grid);
        if (lat != null && lon != null && !isNaN(lat) && !isNaN(lon) && (lat !== 0.0 || lon !== 0.0)) {
            let wrapEitherSideOfLon = 0;
            if (qthPos != null) {
                wrapEitherSideOfLon = qthPos[1];
            }
            let tmpLon = lon;
            while (tmpLon < wrapEitherSideOfLon - 180) {
                tmpLon += 360;
            }
            while (tmpLon > wrapEitherSideOfLon + 180) {
                tmpLon -= 360;
            }
            return [lat, tmpLon];
        }
    }
    return null;
}

// Set the basemap
function setBasemap(basemapname) {
    // Only change if we have to, to avoid a flash of reloading content
    if (loadedBasemap !== basemapname) {
        loadedBasemap = basemapname;
        if (typeof basemapLayer !== 'undefined') {
            map.removeLayer(basemapLayer);
        }
        basemapLayer = L.tileLayer.provider(basemapname, {
            opacity: parseFloat($('#basemapOpacity').val()),
            edgeBufferTiles: 1
        });
        basemapLayer.addTo(map);

        // Identify dark basemaps to ensure we use white text for unselected icons
        // and change the background colour appropriately
        const basemapIsDark = basemapname === "CartoDB.DarkMatter" || basemapname === "Esri.WorldImagery";
        $("#map").css('background-color', basemapIsDark ? "black" : "white");

        if ($('#showMaidenheadGrid').is(':checked')) {
            map.removeLayer(maidenheadGrid);
            maidenheadGrid.addTo(map);
        }
        if ($('#showCQZones').is(':checked')) {
            map.removeLayer(cqZones);
            cqZones.addTo(map);
        }
        if ($('#showCQZonesWorked').is(':checked')) {
            cqZonesWorked.setWorkedZones(cqZonesWorked.options.workedZones);
        }
        if ($('#showITUZones').is(':checked')) {
            map.removeLayer(ituZones);
            ituZones.addTo(map);
        }
        if ($('#showITUZonesWorked').is(':checked')) {
            ituZonesWorked.setWorkedZones(ituZonesWorked.options.workedZones);
        }
        if ($('#showWABWAIGrid').is(':checked')) {
            map.removeLayer(wabwaiGrid);
            wabwaiGrid.addTo(map);
        }
    }
}

// Set the basemap opacity
function setBasemapOpacity(opacity) {
    if (typeof basemapLayer !== 'undefined') {
        basemapLayer.setOpacity(opacity);
    }
}


// Update the status indicator. Called regularly, and uses internal software state to choose what to display.
async function updateStatus() {
    if (loadedAtLeastOnce) {
        let qsosDone = qsoCount - queue.length;
        let label = qsosDone + " / " + qsoCount;
        let extraClass = "";
        if (qsosDone === qsoCount && qsoCount !== 0) {
            label = "Done";
            extraClass = " bg-success";
        }
        let status = `<div class="progress" role="progressbar" aria-label="File loading progress" aria-valuenow="${qsosDone*100/qsoCount}" aria-valuemin="0" aria-valuemax="100" style="height: 2em">
            <div class="progress-bar ${extraClass}" style="width: ${qsosDone*100/qsoCount}%">${label}</div>
        </div>`;
        if (status !== $("#loadingStatus").html()) {
            $("#loadingStatus").html(status);
        }
        $("#loadingStatus").show();
    }
}

// Update the stats
function recalculateStats() {
    updateStatsCallAndQTH();

    // Prepare a list of all QSOs, not structured underneath callsigns, to extract data that's easier to deal with this way.
    let allQSOs = [];
    data.forEach((d) => {
        d.qsos.forEach((qso) => {
            allQSOs.push(qso);
        });
    });

    // QSO and callsign count
    $("#stats-qso-count").text(allQSOs.length);
    $("#stats-call-count").text(data.size);

    // Sort QSOs by time, find start and end
    allQSOs.sort((a, b) => a.time < b.time ? -1 : 1);
    let totalDuration;
    if (allQSOs.length > 0) {
        $("#stats-start-time").html(allQSOs[0].time.format('HH:mm[,&nbsp;]DD[&nbsp;]MMM[&nbsp;]YYYY'));
        if (allQSOs.length > 1) {
            totalDuration = moment.duration(allQSOs[allQSOs.length - 1].time.diff(allQSOs[0].time));
            $("#stats-end-time").html(allQSOs[allQSOs.length - 1].time.format('HH:mm[,&nbsp;]DD[&nbsp;]MMM[&nbsp;]YYYY'));
            $("#stats-duration").text(formatDurationText(totalDuration));
        } else {
            $("#stats-end-time").text("-");
            $("#stats-duration").text("-");
        }
    } else {
        $("#stats-start-time").text("-");
        $("#stats-end-time").text("-");
        $("#stats-duration").text("-");
    }

    // Find any "off times". The rows for these are normally hidden, but if we have a gap of >1 hour anywhere in the
    // log, we consider this an "off time" for contesting purposes and show how much on/off time there was.
    if (allQSOs.length > 1) {
        let totalOffTime = moment.duration(0);
        for (let i = 1; i < allQSOs.length; i++) {
            let gap = moment.duration(allQSOs[i].time.diff(allQSOs[i - 1].time));
            if (gap.as('minutes') >= 60) {
                totalOffTime.add(gap);
            }
        }
        if (totalOffTime.as('minutes') > 0) {
            $("#stats-time-off").text(formatDurationText(totalOffTime));
            $("#stats-time-on").text(formatDurationText(totalDuration.subtract(totalOffTime)));
            $(".statsTimeOnOffRow").show();
        } else {
            $(".statsTimeOnOffRow").hide();
        }
    } else {
        $(".statsTimeOnOffRow").hide();
    }

    // Find all unique grids
    let allGridSquares = [...new Set(allQSOs.filter(q => q.grid != null && q.grid.length >= 4).map(q => q.grid.substring(0, 4)))].sort();
    $("#stats-gridsquare-count").text(allGridSquares.length);
    $("#stats-gridsquare-list").text(allGridSquares.join(", "));
    let allGridFields = [...new Set(allQSOs.filter(q => q.grid != null && q.grid.length >= 2).map(q => q.grid.substring(0, 2)))].sort();
    $("#stats-gridfield-count").text(allGridFields.length);
    $("#stats-gridfield-list").text(allGridFields.join(", "));

    // Find all unique DXCCs, sort them by their name
    let allDXCCs = [...new Set(allQSOs.filter(q => q.dxcc != null && q.dxcc !== "").map(q => q.dxcc))].sort((a, b) => DXCC_DATA[a].name < DXCC_DATA[b].name ? -1 : 1);
    $("#stats-dxcc-count").text(allDXCCs.length);
    // For DXCCs, the list is a bit more complex as we want counts per DXCC and names/flags. We also want the display to
    // be a table to improve the layout. So now we map DXCCs to QSOs and extract details, and build the table.
    let dxccTable = $("#stats-dxcc-table");
    dxccTable.html("");
    dxccTable.append("<thead><tr><th>DXCC</th><th>QSOs</th><th>DXCC</th><th>QSOs</th></tr></thead>");
    dxccTable.append("<tbody>");
    // Faff making the table because we need two sets of columns to use the space neatly
    let rowCount = Math.ceil(allDXCCs.length / 2.0) * 2;
    for (let row = 0; row < rowCount; row++) {
        let tr2 = $(`<tr></tr>`);
        for (let col = 0; col < 2; col++) {
            if (allDXCCs.length > (row * 2) + col) {
                // Create cells for DXCC flag/name and QSO count
                let dxcc = allDXCCs[(row * 2) + col];
                let qsosInDXCC = [...new Set(allQSOs.filter(q => q.dxcc === dxcc))];
                tr2.append(`<td><img src="img/flags/${dxcc}.png" class="flag" width="24" alt="${DXCC_DATA[dxcc].name} flag"/>&nbsp;${DXCC_DATA[dxcc].name}</td>`);
                tr2.append(`<td>${qsosInDXCC.length}</td>`);
            }
        }
        dxccTable.find('tbody').append(tr2);
    }

    // Find all unique CQ zones
    let allCQZs = [...new Set(allQSOs.filter(q => q.cqz != null && q.cqz !== "").map(q => q.cqz))].sort((a, b) => parseInt(a) < parseInt(b) ? -1 : 1);
    $("#stats-cqz-count").text(allCQZs.length);
    $("#stats-cqz-list").text(allCQZs.join(", "));
    if (cqZonesWorked) { cqZonesWorked.setWorkedZones(allCQZs); }

    // Find all unique ITU zones
    let allITUZs = [...new Set(allQSOs.filter(q => q.ituz != null && q.ituz !== "").map(q => q.ituz))].sort((a, b) => parseInt(a) < parseInt(b) ? -1 : 1);
    $("#stats-ituz-count").text(allITUZs.length);
    $("#stats-ituz-list").text(allITUZs.join(", "));
    if (ituZonesWorked) { ituZonesWorked.setWorkedZones(allITUZs); }

    // Find all combinations of band and mode, and for each mode note down how many uses there were.
    let bandsUsed = [...new Set(allQSOs.map(q => q.band))];
    let modeFamilies = ["CW", "Phone", "Data"];

    // Create band/mode table
    let bandModeTable = $("#stats-band-mode-table");
    bandModeTable.html("");
    bandModeTable.append("<thead><tr><th>Band</th><th>QSOs</th></tr></thead>");
    bandModeTable.append("<tbody>");
    modeFamilies.forEach(m => {
        bandModeTable.find('thead tr').append(`<th>${m}</th>`);
    });
    getKnownBands().forEach(band => {
       if (bandsUsed.includes(band)) {
           let tr = $(`<tr></tr>`);
           tr.append(`<th>${band}</th>`);
           tr.append(`<td>${allQSOs.filter(q => q.band === band).length}</td>`);
           modeFamilies.forEach(m => {
               tr.append(`<td>${allQSOs.filter(q => q.band === band && getModeFamily(q.mode) === m).length}</td>`);
           });
           bandModeTable.find('tbody').append(tr);
       }
    });

}


// Function to set the color scheme based on the theme select, falling back to browser preference
function setColorScheme() {
    const themeVal = $('#theme').val() || 'auto';
    let dark;
    if (themeVal === 'dark') {
        dark = true;
    } else if (themeVal === 'light') {
        dark = false;
    } else {
        dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    }
    $("html").attr("data-bs-theme", dark ? "dark" : "light");
    const metaThemeColor = document.querySelector("meta[name=theme-color]");
    metaThemeColor.setAttribute("content", dark ? "black" : "white");
    const metaAppleStatusBarStyle = document.querySelector("meta[name=apple-mobile-web-app-status-bar-style]");
    metaAppleStatusBarStyle.setAttribute("content", dark ? "black-translucent" : "white-translucent");
}

// Sets up a listener on the OS light-dark theme change. Only applies when theme is set to Automatic.
function listenForOSThemeChange() {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (($('#theme').val() || 'auto') === 'auto') {
            setColorScheme();
        }
    });
}
