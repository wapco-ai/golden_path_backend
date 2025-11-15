export const offlineFallbackStyle = {
  version: 8,
  name: 'offline-fallback',
  sources: {},
  layers: [
    {
      id: 'offline-background',
      type: 'background',
      paint: {
        'background-color': '#0b192f'
      }
    }
  ],
  metadata: {
    description: 'Fallback style used when map tiles cannot be loaded'
  }
};

const osmMapStyle = {
  version: 8,
  name: 'osm-standard',
  sources: {
    'osm-standard': {
      type: 'raster',
      tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
      tileSize: 256,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    },
    terrain: {
      type: 'raster-dem',
      tiles: ['https://demotiles.maplibre.org/terrain-tiles/tiles/{z}/{x}/{y}.png'],
      tileSize: 256,
      maxzoom: 14,
      encoding: 'terrarium'
    }
  },
  layers: [
    {
      id: 'osm-standard',
      type: 'raster',
      source: 'osm-standard',
      minzoom: 0,
      maxzoom: 19
    }
  ],
  metadata: {
    description: 'Standard OpenStreetMap tile style used for default map rendering'
  }
};

export default osmMapStyle;
