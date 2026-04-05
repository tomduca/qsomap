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

        this.addLayer(geoLayer);
    }
});

L.cqzonesWorked = function (options) {
    return new L.CQzonesWorked(options);
};
