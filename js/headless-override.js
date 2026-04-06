/**
 * Headless mode override for band colors
 * This ensures band colors are ALWAYS used regardless of UI state
 */

// Override qsoToColour to always use band colors in headless mode
const originalQsoToColour = qsoToColour;

function qsoToColour(d) {
    // Force band colors in headless mode
    let qsoColours = [];
    getQSOsMatchingFilter(d).forEach((qso) => {
        if (qso.band && typeof bandToColor === 'function') {
            qsoColours.push(bandToColor(qso.band));
        } else {
            qsoColours.push("#808080"); // Gray fallback
        }
    });
    
    let allEqual = qsoColours.every((val, i, arr) => val === arr[0]);
    if (allEqual && qsoColours.length > 0) {
        return qsoColours[0];
    } else if (qsoColours.length > 0) {
        return "#ffffff"; // White for multiple bands
    } else {
        return "#808080"; // Gray fallback
    }
}

console.log('Headless band color override loaded');
