const appConfig = {
  apiBaseUrl: import.meta?.env?.VITE_API_BASE_URL?.trim() || 'http://localhost:8080',
  doorBoundaryToleranceMeters: Number(import.meta?.env?.VITE_DOOR_BOUNDARY_TOLERANCE ?? '') || 4
};

export default appConfig;
