L.CQzonesWorked = L.LayerGroup.extend({

    options: {
        color: 'rgba(0, 200, 0, 0.5)',
        workedZones: []
    },

    initialize: function (options) {
        L.LayerGroup.prototype.initialize.call(this);
        L.Util.setOptions(this, options);
    },

    onAdd: function (map) {
        this._map = map;
        this._zoomHandler = this._onZoom.bind(this);
        map.on('zoomend', this._zoomHandler);
        this.draw();
    },

    onRemove: function (map) {
        map.off('zoomend', this._zoomHandler);
        L.LayerGroup.prototype.onRemove.call(this, map);
    },

    _onZoom: function () {
        this.clearLayers();
        this.draw();
    },

    setWorkedZones: function (zones) {
        this.options.workedZones = zones.map(String);
        this.clearLayers();
        if (this._map) {
            this.draw();
        }
    },

    draw: function () {
        if (!CQ_ZONES_POLYGONS) { return; }

        var color = this.options.color;
        var workedZones = this.options.workedZones;

        var polygonFeatures = CQ_ZONES_POLYGONS.features
            .filter(function (f) {
                return f.geometry.type === "LineString" &&
                    workedZones.includes(f.properties.name);
            })
            .map(function (f) {
                return {
                    type: "Feature",
                    geometry: {
                        type: "Polygon",
                        coordinates: [f.geometry.coordinates]
                    },
                    properties: f.properties
                };
            });

        if (polygonFeatures.length === 0) { return; }

        var geoLayer = L.geoJSON(
            { type: "FeatureCollection", features: polygonFeatures },
            {
                style: {
                    color: color,
                    fillColor: color,
                    fillOpacity: 0.35,
                    stroke: true,
                    weight: 2
                }
            }
        );

        var zoom = this._map.getZoom();
        var labelFeatures = CQ_ZONES_NUMBERS.features.filter(function (f) {
            return workedZones.includes(f.properties.name);
        });
        var labelLayer = L.geoJSON(
            { type: "FeatureCollection", features: labelFeatures },
            {
                pointToLayer: function (feature, latlng) {
                    var zone = parseInt(feature.properties.name);
                    var style = { color: color, size: 56 };
                    var img = TextImage(style).toDataURL(feature.properties.name);
                    var x = zoom * 12;
                    if (zone < 10) { x = x / 1.8; }
                    return L.marker(latlng, {
                        icon: L.icon({ iconUrl: img, iconSize: [x, x] })
                    });
                }
            }
        );

        this.addLayer(geoLayer);
        this.addLayer(labelLayer);
    }
});

L.cqzonesWorked = function (options) {
    return new L.CQzonesWorked(options);
};
