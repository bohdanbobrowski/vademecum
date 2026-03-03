# PostLatLong

Simple wordpress plugin which:
- adds post meta fields for longitude, latitude and address (nothing is validated!)
- draws a map with post position at the on of post content using [leaflet-map](https://github.com/bozdoz/wp-plugin-leaflet-map) plugin which is required btw

# Shortcodes

## Draw a map with post location

    [postlatlong-map]

This is handled with [leaflet-map](https://github.com/bozdoz/wp-plugin-leaflet-map), so this plugin must be installed and configured.

## Show list of nearest posts

    [postlatlong-nearest]
    [postlatlong-nearest limit=10]

