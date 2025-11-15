import appConfig from '../config/appConfig.js';

const SUPPORTED_LANGUAGES = new Set(['fa', 'en', 'ar', 'ur']);
const SUPPORTED_FLOORS = new Set([0, -1]);
const DEFAULT_LANGUAGE = 'fa';
const DEFAULT_FLOOR = 0;

const normalizeLanguage = (language) => {
  if (!language) return DEFAULT_LANGUAGE;
  const lowered = String(language).toLowerCase();
  return SUPPORTED_LANGUAGES.has(lowered) ? lowered : DEFAULT_LANGUAGE;
};

const normalizeFloor = (floor) => {
  if (typeof floor === 'number' && SUPPORTED_FLOORS.has(floor)) {
    return floor;
  }
  const parsed = Number(floor);
  return SUPPORTED_FLOORS.has(parsed) ? parsed : DEFAULT_FLOOR;
};

const buildGeojsonEndpoint = () => {
  const base = (appConfig.apiBaseUrl || '').replace(/\/$/, '');

  if (!base) {
    return '/api/v1/maps/geojson';
  }

  if (/\/api\/v1$/i.test(base) || /\/v1$/i.test(base)) {
    return `${base}/maps/geojson`;
  }

  if (/\/api$/i.test(base)) {
    return `${base}/v1/maps/geojson`;
  }

  return `${base}/api/v1/maps/geojson`;
};

export async function fetchMapGeojson({ language = DEFAULT_LANGUAGE, floor = DEFAULT_FLOOR, signal } = {}) {
  const normalizedLanguage = normalizeLanguage(language);
  const normalizedFloor = normalizeFloor(floor);

  const endpoint = buildGeojsonEndpoint();
  const params = new URLSearchParams({
    language: normalizedLanguage,
    floor: normalizedFloor.toString()
  });

  const response = await fetch(`${endpoint}?${params.toString()}`, {
    method: 'GET',
    headers: { Accept: 'application/json' },
    signal
  });

  if (!response.ok) {
    throw new Error(`GeoJSON service request failed with status ${response.status}`);
  }

  return response.json();
}

export default fetchMapGeojson;
