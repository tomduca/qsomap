/////////////////////////////
//    UTILITY FUNCTIONS    //
/////////////////////////////

// Converts an HTML hex colour to an array of [R, G, B] where each is 0-255.
function hexToRGB(hex) {
    return hex.replace(/^#?([a-f\d])([a-f\d])([a-f\d])$/i
             ,(m, r, g, b) => '#' + r + r + g + g + b + b)
    .substring(1).match(/.{2}/g)
    .map(x => parseInt(x, 16));
}

// Format a duration in a user-friendly way
function formatDurationText(duration) {
    let durationMins = duration.as('minutes');
    let durationHours = Math.floor(durationMins / 60.0);
    durationMins = durationMins - (durationHours * 60.0);
    if (durationHours === 0) {
        return durationMins + " minute" + (durationMins === 1 ? "" : "s");
    } else if (durationHours === 1) {
        return durationHours + " hour " + durationMins + " min" + (durationMins === 1 ? "" : "s");
    } else if (durationHours < 72) {
        return durationHours + " hours " + durationMins + " min" + (durationMins === 1 ? "" : "s");
    } else {
        let durationDays = Math.ceil(durationHours / 24.0);
        return durationDays + " days ";
    }
}

// Set the QTH location to the provided grid
function setQTH(newPos) {
    // Store position
    qthPos = newPos;
    // Add or replace the marker
    createOwnPosMarker(newPos);
}

// Create and apply the own position marker
function createOwnPosMarker(newPos) {
    const qthMarker = $('#qthMarker').is(':checked');
    const circleMarkers = $('#circleMarkers').is(':checked');
    const markerSize = parseFloat($('#markerSize').val());
    const outlineMarkers = $('#outlineMarkers').is(':checked');

    if (ownPosMarker != null) {
        ownPosLayer.removeLayer(ownPosMarker);
    }

    if (qthMarker && newPos != null) {
        if (circleMarkers) {
            ownPosMarker = L.circleMarker(newPos, { radius: 5 * markerSize, fillOpacity: 1.0, opacity: 1.0, weight: 1, fill: true, color: "black", fillColor: "grey", stroke: outlineMarkers });
        } else {
            ownPosMarker = L.marker(newPos, {
                icon: L.ExtraMarkers.icon({
                    icon: (markerSize >= 0.75) ? 'fa-tower-cell' : 'fa-none',
                    iconColor: 'white',
                    markerColor: 'grey',
                    shape: 'circle',
                    prefix: 'fa',
                    svg: true
                }),
                autoPan: true
            });
        }

        let tooltipText = getOwnQTHTooltipText();
        if (tooltipText) {
            ownPosMarker.bindTooltip(tooltipText, {permanent: true, direction: 'bottom', offset: L.point(0, -10)});
        }

        ownPosLayer.addLayer(ownPosMarker);

        // Adjust marker size (if we're using real markers not circles, circles already have their radius set at
        // creation, whereas markers need CSS applied here)
        if (!circleMarkers) {
            $(ownPosMarker._icon).find("svg").css("width", (32 * markerSize) + "px");
            $(ownPosMarker._icon).find("svg").css("height", (44 * markerSize) + "px");
            $(ownPosMarker._icon).find("svg").css("margin-top", ((1 - markerSize) * 40) + "px");
            $(ownPosMarker._icon).find("svg").css("margin-left", ((1 - markerSize) * 8) + "px");
            $(ownPosMarker._icon).find("i").css("font-size", (14 + (markerSize - 1) * 10) + "px");
            $(ownPosMarker._icon).find("i").css("margin-top", (10 + (1 - markerSize) * 28) + "px");
            $(ownPosMarker._icon).find("i").css("position", "absolute");
            $(ownPosMarker._icon).find("i").css("top", "0px");
            let ml = 0;
            if (markerSize > 1.4) {
                ml = 3;
            } else if (markerSize < 0.9 || (markerSize > 1.1 && markerSize < 1.4)) {
                ml = 1;
            }
            $(ownPosMarker._icon).find("i").css("margin-left", ml + "px");
        }

        // Use outlined icons if requested (standard markers version, needs doing after adding to layer)
        if (!circleMarkers && outlineMarkers) {
            $(ownPosMarker._icon).addClass("outlinedmarker");
        }
    } else {
        ownPosMarker = null;
    }
}

// Returns a colour based on data item's QSOs' band or mode, if enabled, otherwise returns neutral blue.
// If there would be multiple colours, purple is used.
function qsoToColour(d) {
    const bandColours = $('#bandColours').is(':checked');
    const modeColours = $('#modeColours').is(':checked');
    let qsoColours = [];
    getQSOsMatchingFilter(d).forEach((qso) => {
        if (bandColours) {
            qsoColours.push(bandToColor(qso.band));

        } else if (modeColours) {
            if (qso.mode) {
                qsoColours.push(modeTypeToColor(getModeFamily(qso.mode)));
            } else {
                qsoColours.push("#808080");
            }

        } else {
            qsoColours.push($('#fixedMarkerColour').val());
        }
    });
    let allEqual = qsoColours.every( (val, i, arr) => val === arr[0] );
    if (allEqual) {
        return qsoColours[0];
    } else {
        return "#ffffff";
    }
}

// Returns a colour to contrast with the result of qsoToColor, based on data item QSOs' bands or modes, if enabled,
// otherwise returns white.
function qsoToContrastColor(d) {
    const rgb = hexToRGB(qsoToColour(d));
    const lum = 0.2126*rgb[0] + 0.7152*rgb[1] + 0.0722*rgb[2];
    return (lum > 128) ? "#000000" : "#ffffff";
}

// Get an icon for a data item, based on its band, using PSK Reporter colours, its program etc.
function getIcon(d, thisMarkerSize) {
    return L.ExtraMarkers.icon({
        // If marker scale is less than 75%, there's no room for an icon
        icon: (thisMarkerSize >= 0.75) ? getIconName(d) : "fa-none",
        iconColor: qsoToContrastColor(d),
        markerColor: qsoToColour(d),
        shape: 'circle',
        prefix: 'fa',
        svg: true
    });
}

// Get Font Awesome icon name for the data item. If multiple icons would be used, a star is used instead.
function getIconName(d) {
    const outdoorSymbols = $('#outdoorSymbols').is(':checked');
    const inferOutdoorActivitiesFromComments = $('#inferOutdoorActivitiesFromComments').is(':checked');
    let chosenIcon;
    if (outdoorSymbols) {
        // Outdoor activity symbols in use, so figure out what they are for each QSO.
        let qsoIcons = [];
        getQSOsMatchingFilter(d).forEach((qso) => {
            // First, see if the QSO has any Special Interest Group (e.g. xOTA) references set.
            if (qso.sigRefs && qso.sigRefs.length > 0) {
                qso.sigRefs.forEach(p => {
                    if (p.program === "POTA") {
                        qsoIcons.push("fa-tree");
                    } else if (p.program === "SOTA") {
                        qsoIcons.push("fa-mountain-sun");
                    } else if (p.program === "WWFF") {
                        qsoIcons.push("fa-seedling");
                    } else if (p.program === "GMA") {
                        qsoIcons.push("fa-person-hiking");
                    } else if (p.program === "HEMA") {
                        qsoIcons.push("fa-mound");
                    } else if (p.program === "WWBOTA" || p.program === "UKBOTA") {
                        qsoIcons.push("fa-radiation");
                    } else if (p.program === "IOTA") {
                        qsoIcons.push("fa-book-atlas");
                    } else if (p.program === "BOTA") {
                        qsoIcons.push("fa-umbrella-beach");
                    } else if (p.program === "WCA") {
                        qsoIcons.push("fa-chess-rook");
                    } else if (p.program === "ARLHS" || p.program === "ILLW") {
                        qsoIcons.push("fa-house-flood-water");
                    } else if (p.program === "WWTOTA") {
                        qsoIcons.push("fa-tower-observation");
                    } else if (p.program === "LLOTA") {
                        qsoIcons.push("fa-water");
                    } else if (p.program === "MOTA") {
                        qsoIcons.push("fa-fan");
                    } else if (p.program === "SIOTA") {
                        qsoIcons.push("fa-wheat-awn");
                    } else if (p.program === "WOTA") {
                        qsoIcons.push("fa-w");
                    } else if (p.program === "ZLOTA") {
                        qsoIcons.push("fa-kiwi-bird");
                    } else if (p.program === "KRMNPA") {
                        qsoIcons.push("fa-earth-oceania");
                    } else if (p.program === "WAB" || p.program === "WAI") {
                        qsoIcons.push("fa-table-cells-large");
                    } else if (p.program === "TOTA") {
                        qsoIcons.push("fa-toilet");
                    } else {
                        // A program was set but not one we recognise, so show a question mark
                        qsoIcons.push("fa-question");
                    }
                });

            } else if (inferOutdoorActivitiesFromComments && qso.comment && qso.comment.length > 0) {
                // Now if we are allowed to infer outdoor activities from comments, parse the comments for anything useful
                let comment = qso.comment.toUpperCase();
                if (comment.includes("POTA") || comment.includes("P2P") || comment.includes("PARK")) {
                    qsoIcons.push("fa-tree");
                } else if (comment.includes("SOTA") || comment.includes("S2S") || comment.includes("SUMMIT")) {
                    qsoIcons.push("fa-mountain-sun");
                } else if (comment.includes("WWFF")) {
                    qsoIcons.push("fa-seedling");
                } else if (comment.includes("GMA")) {
                    qsoIcons.push("fa-person-hiking");
                } else if (comment.includes("HEMA")) {
                    qsoIcons.push("fa-mound");
                } else if (comment.includes("BOTA") || comment.includes("BUNKER")) {
                    qsoIcons.push("fa-radiation");
                } else if (comment.includes("IOTA") || comment.includes("ISLAND")) {
                    qsoIcons.push("fa-book-atlas");
                } else if (comment.includes("WCA") || comment.includes("CASTLE")) {
                    qsoIcons.push("fa-chess-rook");
                } else if (comment.includes("ALHRS") || comment.includes("ILLW") || comment.includes("LIGHTHOUSE")) {
                    qsoIcons.push("fa-house-flood-water");
                } else if (comment.includes("MOTA") || comment.includes("MILL")) {
                    qsoIcons.push("fa-fan");
                } else if (comment.includes("SIOTA") || comment.includes("SILO")) {
                    qsoIcons.push("fa-wheat-awn");
                } else if (comment.includes("WOTA")) {
                    qsoIcons.push("fa-w");
                } else if (comment.includes("WWTOTA")) {
                    qsoIcons.push("fa-tower-observation");
                } else if (comment.includes("LLOTA")) {
                    qsoIcons.push("fa-water");
                } else if (comment.includes("ZLOTA")) {
                    qsoIcons.push("fa-kiwi-bird");
                } else if (comment.includes("KRMNPA")) {
                    qsoIcons.push("fa-earth-oceania");
                } else if (comment.includes("WAB") || comment.includes("WAI")) {
                    qsoIcons.push("fa-table-cells-large");
                } else if (comment.includes("TOTA")) {
                    qsoIcons.push("fa-toilet");
                } else {
                    qsoIcons.push("fa-crosshairs");
                }
            } else {
                // No outdoor activity program could be inferred. Since "show activity symbols" is on, we assume we were portable, so
                // this is a QSO with a hunter, and we don't have hybrid size on, so use crosshairs symbol.
                qsoIcons.push("fa-crosshairs");
            }
        });
        let allEqual = qsoIcons.every( (val, i, arr) => val === arr[0] );
        if (allEqual) {
            // All QSOs with this callsign + grid have the same icon, so use it
            chosenIcon = qsoIcons[0];
        } else {
            // Multiple different icons are specified by this QSO set, so show a star
            chosenIcon = "fa-star";
        }
    } else {
        // Outdoor activity icons not in use, and normal or larger size icons in use, so use a circle symbol like a
        // "standard" map marker.
        chosenIcon = "fa-circle"
    }
    return chosenIcon;
}

// Utility function to get the distance to a QSO's grid from your grid, in a format for displaying on screen.
// If there is insufficient data, a blank string is returned.
function getDistanceString(d) {
    let ret = "";
    if (d.grid && qthPos) {
        let iconPos = getIconPosition(d);
        let distanceMetres = L.GeometryUtil.length([new L.LatLng(qthPos[0], qthPos[1]), new L.LatLng(iconPos[0], iconPos[1])]);
        if ($('#distanceUnit').val() === "mi") {
            ret = (distanceMetres / 1609.0).toFixed(0) + "&nbsp;mi";
        } else {
            ret = (distanceMetres / 1000.0).toFixed(0) + "&nbsp;km";
        }
    }
    return ret;
}

// Return true if the QSO matches the current filter, false otherwise.
function qsoMatchesFilter(qso) {
    const filterYear = $('#filter-year').val() || '*';
    const filterMode = $('#filter-mode').val() || '*';
    const filterBand = $('#filter-band').val() || '*';
    return (filterYear === "*" ||  qso.year === parseInt(filterYear)) && (filterMode === "*" || qso.mode === filterMode)
        && (filterBand === "*" || qso.band === filterBand);
}

// Return any QSOs in this data object that match the current filter.
function getQSOsMatchingFilter(d) {
    return d.qsos.filter(q => qsoMatchesFilter(q));
}

// Return true if any QSO in this data object matches the current filter, false otherwise.
function anyQSOMatchesFilter(d) {
    return getQSOsMatchingFilter(d).length > 0;
}

// Given a QSO, list any SIG/xOTA references that the QSO partner was logged at, as a string in a deterministic order,
// including HTML links to each reference. If there are none, a blank string will be returned.
function listSIGRefsWithLinks(q) {
    if (q.sigRefs != null) {
        return "" + q.sigRefs.map(p => sigRefToHTMLLink(p)).sort().join(", ");
    } else {
        return "";
    }
}

// For a given SIG/xOTA reference, produce an HTML link to it in the relevant programme.
function sigRefToHTMLLink(p) {
    let url = getURLforReference(p.program, p.ref);
    if (url) {
        return "<a href='" + url + "' target='_blank'>" + p.ref + "</a>";
    } else {
        return p.ref;
    }
}

// Sets the band colour scheme for the QSO Map, delegating to the util method but also updating the band colour samples in the menu
function setBandColorSchemeQSOMap(scheme) {
    setBandColorScheme(scheme);
    $(".bandColorSample").each(function() {
        $(this).css('background-color', bandToColor($(this).attr("id").replace("bandColorSample-", "").replace("_", ".")));
    });
}
