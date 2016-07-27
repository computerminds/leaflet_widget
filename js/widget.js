/**
 * Attach functionality for modifying map markers.
 */
(function ($, Drupal, drupalSettings) {
    Drupal.behaviors.leaflet_widget = {
        attach: function (context, settings) {
            $.each(settings.leaflet_widget, function (map_id, widgetSettings) {
                $('#' + map_id, context).each(function () {
                    var map = $(this);
                    // If the attached context contains any leaflet maps with widgets, make sure we have a
                    // Drupal.leaflet_widget object.
                    if (map.data('leaflet_widget') == undefined) {
                        var lMap = drupalSettings.leaflet[map_id].lMap;
                        map.data('leaflet_widget', new Drupal.leaflet_widget(map, lMap, widgetSettings));
                    }
                    else {
                        // If we already had a widget, update map to make sure that WKT and map are synchronized.
                        map.data('leaflet_widget').update_map();
                        map.data('leaflet_widget').update_wkt_state();
                    }
                });
            });

        }
    };

    Drupal.leaflet_widget = function (map_container, lMap, widgetSettings) {
        this.settings = widgetSettings;
        this.container = $(map_container).parent();
        this.wkt_selector = this.settings.wktElement;
        this.last_value = '';
        this.default_marker_settings = {
            draggable: true
        };

        this.map = undefined;
        this.drawingLayer = undefined;
        this.drawingControl = undefined;
        this.set_leaflet_map(lMap);
        // If map is initialised (or re-initialised) then use the new instance.
        this.container.on('leaflet.map', $.proxy(function (event, _m, lMap) {
            this.set_leaflet_map(lMap);
        }, this));

        // Update map whenever the WKT input field is changed.
        this.container.on('change', this.wkt_selector, $.proxy(this.update_map, this));

        // Show, hide, mark read-only.
        this.update_wkt_state();
    };

    /**
     * Set the leaflet map object.
     */
    Drupal.leaflet_widget.prototype.set_leaflet_map = function (map) {
        if (map != undefined) {
            this.map = map;

            // Add our drawing layer.
            this.drawingLayer = new L.FeatureGroup();
            this.map.addLayer(this.drawingLayer);

            var drawingOptions = {
                edit: {
                    featureGroup: this.drawingLayer,
                    remove: false
                },
                draw: {
                    circle: false,
                }

            };
            this.drawingControl = new L.Control.Draw(drawingOptions);
            this.map.addControl(this.drawingControl);
            this.map.on('draw:created', $.proxy(function (e) {
                var layer = e.layer;
                this.drawingLayer.clearLayers();
                this.drawingLayer.addLayer(layer);
                this.update_text();

            }, this));

            this.map.on('draw:edited', $.proxy(function (e) {
                var layers = e.layers;
                this.drawingLayer.clearLayers();
                layers.eachLayer($.proxy(function (layer) {
                    this.drawingLayer.addLayer(layer);
                }, this));
                this.update_text();
            }, this));

            this.update_map();
        }
    };

    /**
     * Clear all pointers on this map.
     */
    Drupal.leaflet_widget.prototype.clear_map = function (no_text_reset) {
        var i;

        // Save and temporarily remove the update_text callback, if we do not want to update the wkt text.
        var _update_text_func = false;
        if (no_text_reset == true) {
            _update_text_func = this.update_text;
            this.update_text = function () {
            };
        }

        // Reset the remembered last string (so that we can clear
        // the map, paste the same string, and see it again).
        this.last_value = false;

        // If we currently have a map, clear all markers.
        if (this.map != undefined) {
            this.drawingLayer.clearLayers();
        }

        // Restore update_text function.
        if (_update_text_func) {
            this.update_text = _update_text_func;
        }
    };

    /**
     * Update the WKT text input field.
     */
    Drupal.leaflet_widget.prototype.update_text = function () {
        if (this.drawingLayer.getLayers().length == 0) {
            $(this.wkt_selector, this.container).val('');
        }
        else if (this.drawingLayer.getLayers().length == 1) {
            var wkt = new Wkt.Wkt();
            var layers = this.drawingLayer.getLayers();
            wkt.fromObject(layers[0].toGeoJSON().geometry);
            $(this.wkt_selector, this.container).val(wkt.write());
        }
        else {
          if (window.console) console.error("Array of " + this.drawingLayer.getLayers().length + " points? Not sure what to do.")
        }
    };

    /**
     * Set visibility and readonly attribute of the wkt input element.
     */
    Drupal.leaflet_widget.prototype.update_wkt_state = function () {
        $('.form-item', this.container).toggle(!this.settings.inputHidden);
        $(this.wkt_selector, this.container).prop('readonly', this.settings.inputReadonly);
    };

    /**
     * Update the leaflet map from text.
     */
    Drupal.leaflet_widget.prototype.add_new_marker = function (marker) {
        if (this.map != undefined) {
            this.drawingLayer.addLayer(marker);

            // Pan the map to the feature
            if (this.settings.autoCenter) {
                if (marker.getBounds !== undefined && typeof marker.getBounds === 'function') {
                    // For objects that have defined bounds or a way to get them
                    this.map.fitBounds(marker.getBounds());
                } else if (marker.getLatLng !== undefined && typeof marker.getLatLng === 'function') {
                    this.map.panTo(marker.getLatLng());
                }
            }
        }
    };

    /**
     * Update the leaflet map from text.
     */
    Drupal.leaflet_widget.prototype.update_map = function () {
        // Clear existing features.
        this.clear_map(true);

        var value = $(this.wkt_selector, this.container).val();

        // Remember the last value and do nothing if it has not changed.
        if (this.last_value === value) {
            return;
        } else {
            this.last_value = value;
        }

        // Nothing to do if we don't have any WKT.
        if (value.length == 0) {
            return;
        }

        // Translate textfield value into leaflet point(s).
        var wkt = new Wkt.Wkt();
        try {
            wkt.read(value);
        } catch (e1) {
            try {
                // Try again without newlines.
                wkt.read(value.replace('\n', '').replace('\r', '').replace('\t', ''));
            } catch (e2) {
                if (e2.name === 'WKTError') {
                    if (window.console) console.error(e2.message);
                    return;
                }
            }
        }

        obj = wkt.toObject(this.default_marker_settings);

        // Distinguish multigeometries (Arrays) from objects
        if (Wkt.isArray(obj)) {
            for (i in obj) {
                if (obj.hasOwnProperty(i) && !Wkt.isArray(obj[i])) {
                    this.add_new_marker(obj[i]);
                }
            }
        }
        else {
            this.add_new_marker(obj);
        }
    };

})(jQuery, Drupal, drupalSettings);
