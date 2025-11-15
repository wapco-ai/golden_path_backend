import { useCallback, useRef, useState } from 'react';
import osmMapStyle, { offlineFallbackStyle } from '../services/osmMapStyle';
import { toast } from 'react-toastify';

const FALLBACK_TOAST_ID = 'map-tiles-fallback-notice';

/**
 * Provides a map style that automatically falls back to an offline-friendly version
 * whenever MapLibre fails to load raster tiles (e.g., due to blocked network access).
 */
export default function useOfflineMapStyle(initialStyle = osmMapStyle) {
  const [mapStyle, setMapStyle] = useState(initialStyle);
  const [isFallback, setIsFallback] = useState(initialStyle === offlineFallbackStyle);
  const hasSwitchedRef = useRef(initialStyle === offlineFallbackStyle);

  const handleMapError = useCallback((event) => {
    if (hasSwitchedRef.current) {
      return;
    }

    hasSwitchedRef.current = true;
    setMapStyle(offlineFallbackStyle);
    setIsFallback(true);

    const errorDetail = event?.error || event;
    console.warn('Map tile load failed. Switching to offline fallback style.', errorDetail);

    toast.warn('Map tiles are unavailable. Showing simplified map instead.', {
      toastId: FALLBACK_TOAST_ID
    });
  }, []);

  return {
    mapStyle,
    handleMapError,
    isFallback
  };
}
