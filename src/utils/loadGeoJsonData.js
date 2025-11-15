import { fetchMapGeojson } from '../services/geojsonService.js';

const DEFAULT_FLOOR = 0;

export async function loadGeoJsonData({ language = 'fa', floor = DEFAULT_FLOOR, signal } = {}) {
  try {
    return await fetchMapGeojson({ language, floor, signal });
  } catch (error) {
    if (error?.name === 'AbortError') {
      throw error;
    }
    console.error('failed to load geojson from api service', error);
    throw error;
  }
}

export default loadGeoJsonData;
