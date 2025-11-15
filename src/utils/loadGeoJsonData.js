import { buildGeoJsonPath } from './geojsonPath.js';
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
  }

  const file = buildGeoJsonPath(language);
  try {
    const response = await fetch(file, { signal });
    if (!response.ok) {
      throw new Error(`GeoJSON fallback failed with status ${response.status}`);
    }
    return await response.json();
  } catch (fallbackError) {
    if (fallbackError?.name === 'AbortError') {
      throw fallbackError;
    }
    console.error('failed to load geojson fallback', fallbackError);
    throw fallbackError;
  }
}

export default loadGeoJsonData;
