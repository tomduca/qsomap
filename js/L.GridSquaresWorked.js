L.GridSquaresWorked = L.LayerGroup.extend({

    options: {
        color: 'blue',
        pane: 'overlaysPane'
    },

    initialize: function (options) {
        L.LayerGroup.prototype.initialize.call(this);
        L.Util.setOptions(this, options);
        this._grids = new Map(); // grid → showLabel
    },

    onAdd: function (map) {
        this._map = map;
        this._zoomHandler = this._onZoom.bind(this);
        map.on('zoomend', this._zoomHandler);
        this._redrawAll();
    },

    onRemove: function (map) {
        map.off('zoomend', this._zoomHandler);
        L.LayerGroup.prototype.onRemove.call(this, map);
    },

    _onZoom: function () {
        this.clearLayers();
        this._redrawAll();
    },

    _redrawAll: function () {
        var self = this;
        this._grids.forEach(function (showLabel, grid) {
            self._drawOne(grid, showLabel);
        });
    },

    _drawOne: function (grid, showLabel) {
        var swCorner = this._latLonForGridSWCorner(grid);
        var neCorner = this._latLonForGridNECorner(grid);
        if (!swCorner || !neCorner) { return; }

        this.addLayer(L.rectangle([swCorner, neCorner], {
            color: this.options.color,
            pane: this.options.pane
        }));

        if (showLabel) {
            var centre = this._latLonForGridCentre(grid);
            if (centre) {
                var zoom = this._map.getZoom();
                var style = { color: this.options.color, size: 20 };
                var img = TextImage(style).toDataURL(grid);
                var x = zoom * 12;
                this.addLayer(L.marker(centre, {
                    pane: this.options.pane,
                    icon: L.icon({ iconUrl: img, iconSize: [x / 2, x / 4] })
                }));
            }
        }
    },

    // Add a single worked grid square (4-digit Maidenhead reference).
    // If showLabel is true, a text label is also drawn at the centre.
    // Has no effect if the square has already been drawn.
    addGridSquare: function (grid, showLabel) {
        if (this._grids.has(grid)) { return; }
        this._grids.set(grid, showLabel);
        this._drawOne(grid, showLabel);
    },

    // Remove all drawn grid squares and labels.
    clearGridSquares: function () {
        this.clearLayers();
        this._grids = new Map();
    },

    // Inlined from geo.js (misc.ianrenton.com/jsutils/geo.js)
    _latLonForGridSWCornerPlusSize: function (grid) {
        grid = grid.toUpperCase();
        var len = grid.length;
        if (len <= 0 || (len % 2) !== 0) { return [null, null, null, null]; }

        var lat = 0.0;
        var lon = 0.0;
        var latCellSize = 10;
        var lonCellSize = 20;
        var latCellNo, lonCellNo;

        for (var block = 0; block * 2 < len; block += 1) {
            if (block % 2 === 0) {
                lonCellNo = grid.charCodeAt(block * 2) - 'A'.charCodeAt(0);
                latCellNo = grid.charCodeAt(block * 2 + 1) - 'A'.charCodeAt(0);
                var maxCellNo = (block === 0) ? 17 : 23;
                if (latCellNo < 0 || latCellNo > maxCellNo || lonCellNo < 0 || lonCellNo > maxCellNo) {
                    return [null, null, null, null];
                }
            } else {
                lonCellNo = parseInt(grid.charAt(block * 2));
                latCellNo = parseInt(grid.charAt(block * 2 + 1));
                if (latCellNo < 0 || latCellNo > 9 || lonCellNo < 0 || lonCellNo > 9) {
                    return [null, null, null, null];
                }
            }
            lat += latCellNo * latCellSize;
            lon += lonCellNo * lonCellSize;
            if (block * 2 < len - 2) {
                if (block % 2 === 0) {
                    latCellSize = latCellSize / 10.0;
                    lonCellSize = lonCellSize / 10.0;
                } else {
                    latCellSize = latCellSize / 24.0;
                    lonCellSize = lonCellSize / 24.0;
                }
            }
        }
        lon -= 180.0;
        lat -= 90.0;
        if (isNaN(lat) || isNaN(lon) || isNaN(latCellSize) || isNaN(lonCellSize)) {
            return [null, null, null, null];
        }
        return [lat, lon, latCellSize, lonCellSize];
    },

    _latLonForGridSWCorner: function (grid) {
        var r = this._latLonForGridSWCornerPlusSize(grid);
        return (r[0] != null && r[1] != null) ? [r[0], r[1]] : null;
    },

    _latLonForGridNECorner: function (grid) {
        var r = this._latLonForGridSWCornerPlusSize(grid);
        return (r[0] != null && r[2] != null) ? [r[0] + r[2], r[1] + r[3]] : null;
    },

    _latLonForGridCentre: function (grid) {
        var r = this._latLonForGridSWCornerPlusSize(grid);
        return (r[0] != null && r[2] != null) ? [r[0] + r[2] / 2.0, r[1] + r[3] / 2.0] : null;
    }
});

L.gridSquaresWorked = function (options) {
    return new L.GridSquaresWorked(options);
};
