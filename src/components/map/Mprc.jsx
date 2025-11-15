import React, { useEffect, useState, useCallback } from 'react';
import Map, { Marker, Source, Layer } from 'react-map-gl';
import { useIntl } from 'react-intl';
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import useOfflineMapStyle from '../../hooks/useOfflineMapStyle';
import { useLangStore } from '../../store/langStore';
import { groups } from '../groupData';
import { getLocationTitleById } from '../../utils/getLocationTitle';
import { loadGeoJsonData } from '../../utils/loadGeoJsonData.js';

const groupColors = {
  sahn: '#4caf50',
  eyvan: '#2196f3',
  ravaq: '#9c27b0',
  masjed: '#ff9800',
  madrese: '#3f51b5',
  khadamat: '#607d8b',
  elmi: '#00bcd4',
  cemetery: '#795548',
  qrcode: '#607d8b',
  elevator: '#ffc107',
  other: '#757575'
};

const nodeFunctionColors = {
  door: '#e53935'
};

const getCompositeIcon = (group, nodeFunction, size = 35, opacity = 1) => {
  const color = nodeFunctionColors[nodeFunction] || groupColors[group] || '#999';
  let iconData =
    groups.find((g) => g.value === group) ||
    groups.find((g) => g.value === nodeFunction) ||
    groups.find((g) => g.value === 'other');

  return (
    <div
      style={{
        width: size,
        height: size,
        backgroundColor: color,
        opacity,
        borderRadius: '50%',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        boxShadow: '0 2px 6px rgba(0,0,0,0.3)'
      }}
    >
      <div
        className={`map-category-icon ${iconData.icon}`}
        style={{ width: '22px', height: '22px', marginTop: 0 }}
      >
        <img src={iconData.png} alt={iconData.label || 'icon'} width="22" height="22" />
      </div>
    </div>
  );
};

const Mprc = ({
  setUserLocation,
  selectedDestination,
  onMapClick,
  isSelectingLocation,
  selectedCategory,
  userLocation,
  isTracking = true,
  onUserMove
}) => {
  const intl = useIntl();
  const [viewState, setViewState] = useState({
    latitude: 36.2880,  // Original shrine coordinates
    longitude: 59.6157,
    zoom: 16
  });
  const [userCoords, setUserCoords] = useState(null);
  const [destCoords, setDestCoords] = useState(null);
  const [selectedCoords, setSelectedCoords] = useState(null);
  const [geoData, setGeoData] = useState(null);
  const [routeCoords, setRouteCoords] = useState(null);
  const language = useLangStore((state) => state.language);
  const { mapStyle, handleMapError } = useOfflineMapStyle();

  const onMove = useCallback((evt) => {
    setViewState(evt.viewState);
    if (onUserMove && evt.originalEvent) {
      onUserMove();
    }
  }, [onUserMove]);

  // Initialize with shrine location or QR code location if available
  useEffect(() => {
    const storedLat = sessionStorage.getItem('qrLat');
    const storedLng = sessionStorage.getItem('qrLng');
    const storedId = sessionStorage.getItem('qrId');

    // Original shrine coordinates
    const shrineCoords = { lat: 36.2880, lng: 59.6157 };

    if (storedLat && storedLng) {
      const coords = {
        lat: parseFloat(storedLat),
        lng: parseFloat(storedLng)
      };
      setUserCoords(coords);
      (async () => {
        let name = intl.formatMessage({ id: 'mapCurrentLocationName' });
        if (storedId) {
          const title = await getLocationTitleById(storedId);

          if (title) name = title;
        }
        setUserLocation({
          name,
          coordinates: [coords.lat, coords.lng]
        });
      })();
      setViewState(v => ({
        ...v,
        latitude: coords.lat,
        longitude: coords.lng,
        zoom: 18
      }));
      return; // Skip GPS if QR code exists
    }

    // GPS tracking
    const success = (pos) => {
      if (sessionStorage.getItem('qrLat') && sessionStorage.getItem('qrLng')) {
        return; // Don't override QR code location
      }
      const c = {
        lat: pos.coords.latitude,
        lng: pos.coords.longitude
      };
      setUserCoords(c);
      setUserLocation({
        name: intl.formatMessage({ id: 'mapCurrentLocationName' }),
        coordinates: [c.lat, c.lng]
      });
    };

    const err = (e) => {
      console.error('Error getting location', e);
      // Fallback to shrine location
      setUserCoords(shrineCoords);
      setUserLocation({
        name: intl.formatMessage({ id: 'defaultBabRezaName' }),
        coordinates: [shrineCoords.lat, shrineCoords.lng]
      });
    };

    let watchId;
    if (isTracking) {
      navigator.geolocation.getCurrentPosition(success, err, {
        enableHighAccuracy: false,
        timeout: 10000,
        maximumAge: 60000
      });

      watchId = navigator.geolocation.watchPosition(success, err, {
        enableHighAccuracy: false,
        maximumAge: 0,
        timeout: 10000
      });
    }

    return () => {
      if (watchId) navigator.geolocation.clearWatch(watchId);
    };
  }, [setUserLocation, intl, isTracking]);

  // Update user location and optionally center map when it changes
  // In MapComponent.js, update the useEffect that handles userLocation changes:
  useEffect(() => {
    if (userLocation?.coordinates) {
      const [lat, lng] = userLocation.coordinates;
      const coords = { lat, lng };
      setUserCoords(coords);

      // Only center if tracking is enabled or if we're swapping
      if (isTracking || !destCoords) {
        setViewState(v => ({
          ...v,
          latitude: lat,
          longitude: lng,
          zoom: 18
        }));
      }
    }
  }, [userLocation, isTracking]);

  // Update the destination useEffect to handle swapping:
  useEffect(() => {
    if (selectedDestination?.coordinates) {
      const [lat, lng] = selectedDestination.coordinates;
      const coords = { lat, lng };
      setDestCoords(coords);

      // Only center if we don't have user coords or if we're swapping
      if (!userCoords) {
        setViewState(v => ({
          ...v,
          latitude: lat,
          longitude: lng,
          zoom: 18
        }));
      }
    } else {
      setDestCoords(null);
    }
  }, [selectedDestination]);

  // Update destination marker and center map when selection changes
  useEffect(() => {
    if (selectedDestination?.coordinates) {
      const [lat, lng] = selectedDestination.coordinates;
      const coords = { lat, lng };
      setDestCoords(coords);

      // Only center map if this is a new destination selection
      if (!destCoords || destCoords.lat !== lat || destCoords.lng !== lng) {
        setViewState(v => ({
          ...v,
          latitude: lat,
          longitude: lng,
          zoom: 18
        }));
      }
    } else {
      setDestCoords(null);
    }
  }, [selectedDestination]);

  const handleClick = (e) => {
    if (isSelectingLocation) {
      const { lng, lat } = e.lngLat;
      const c = { lat, lng };
      setSelectedCoords(c);

      let closestFeature = null;
      if (geoData) {
        let minDist = Infinity;
        geoData.features.forEach((f) => {
          if (f.geometry.type === 'Point') {
            const [flng, flat] = f.geometry.coordinates;
            const d = Math.hypot(flng - lng, flat - lat);
            if (d < minDist) {
              minDist = d;
              closestFeature = f;
            }
          }
        });
        if (minDist > 0.0005) {
          closestFeature = null;
        }
      }

      if (onMapClick) onMapClick(c, closestFeature);
    }
  };

  useEffect(() => {
    let isMounted = true;
    const controller = new AbortController();

    loadGeoJsonData({ language, signal: controller.signal })
      .then(data => {
        if (isMounted) {
          setGeoData(data);
        }
      })
      .catch(err => {
        if (err?.name === 'AbortError') return;
        console.error('failed to load geojson', err);
      });

    return () => {
      isMounted = false;
      controller.abort();
    };
  }, [language]);

  useEffect(() => {
    if (userCoords && destCoords && geoData) {
      const points = geoData.features.filter(
        (f) =>
          f.geometry.type === 'Point' &&
          ['door', 'connection'].includes(f.properties?.nodeFunction)
      );

      const nearest = (coords) => {
        let best = null;
        let dmin = Infinity;
        points.forEach((p) => {
          const [lng, lat] = p.geometry.coordinates;
          const d = Math.hypot(lng - coords.lng, lat - coords.lat);
          if (d < dmin) {
            dmin = d;
            best = { lng, lat };
          }
        });
        return best;
      };

      const start = nearest(userCoords);
      const end = nearest(destCoords);
      const coords = [
        [userCoords.lng, userCoords.lat],
        ...(start ? [[start.lng, start.lat]] : []),
        ...(end ? [[end.lng, end.lat]] : []),
        [destCoords.lng, destCoords.lat]
      ];
      setRouteCoords(coords);
    } else {
      setRouteCoords(null);
    }
  }, [userCoords, destCoords, geoData]);

  const pointFeatures = geoData
    ? geoData.features.filter(f => f.geometry.type === 'Point')
    : [];
  const polygonFeatures = geoData
    ? geoData.features.filter(
      f => f.geometry.type === 'Polygon' || f.geometry.type === 'MultiPolygon'
    )
    : [];

  return (
    <Map
      mapLib={maplibregl}
      mapStyle={mapStyle}
      style={{ width: '100%', height: '100%' }}
      {...viewState}
      onMove={onMove}
      onClick={handleClick}
      onError={handleMapError}
      interactive={true}
    >
      {/* User location marker */}
      {userCoords && (
        <Marker longitude={userCoords.lng} latitude={userCoords.lat} anchor="center">
          <div className="map-marker-origin">
            <div className="map-marker-origin-inner" />
          </div>
        </Marker>
      )}

      {/* Destination marker */}
      {destCoords && (
        <Marker longitude={destCoords.lng} latitude={destCoords.lat} anchor="center">
          <div className="map-marker-destination">
            <div className="map-marker-destination-inner" />
          </div>
        </Marker>
      )}

      {/* Temporary selection marker when choosing location */}
      {selectedCoords && isSelectingLocation && (
        <Marker longitude={selectedCoords.lng} latitude={selectedCoords.lat} anchor="center">
          <div className="map-marker-selecting">
            <div className="map-marker-selecting-inner" />
          </div>
        </Marker>
      )}

      {/* Route line */}
      {routeCoords && (
        <Source id="route" type="geojson" data={{ type: 'Feature', geometry: { type: 'LineString', coordinates: routeCoords } }}>
          <Layer id="route-line" type="line" paint={{ 'line-color': '#4285F4', 'line-width': 4, 'line-opacity': 0.7 }} />
        </Source>
      )}

      {/* Building polygons */}
      {polygonFeatures.length > 0 && (
        <Source id="polygons" type="geojson" data={{ type: 'FeatureCollection', features: polygonFeatures }}>
          <Layer id="polygon-lines" type="line" paint={{ 'line-color': '#333', 'line-width': 2 }} />
        </Source>
      )}

      {/* Point features (doors, services, etc.) */}
      // Point features (doors, services, etc.) - Only show when a category is selected
      {selectedCategory && pointFeatures.map((feature, idx) => {
        const [lng, lat] = feature.geometry.coordinates;
        const { group, nodeFunction } = feature.properties || {};
        const highlight =
          selectedCategory &&
          feature.properties &&
          feature.properties[selectedCategory.property] === selectedCategory.value;
        const hasFilter = !!selectedCategory;
        const iconSize = hasFilter ? (highlight ? 40 : 25) : 35;
        const iconOpacity = hasFilter ? (highlight ? 1 : 0.4) : 1;
        const rawId = feature.properties?.uniqueId;
        const key = rawId ? `${rawId}-${idx}` : idx;

        return (
          <Marker key={key} longitude={lng} latitude={lat} anchor="center">
            <div style={{ position: 'relative' }}>
              {getCompositeIcon(group, nodeFunction, iconSize, iconOpacity)}
              {highlight && (
                <div
                  style={{
                    position: 'absolute',
                    top: -4,
                    left: -4,
                    right: -4,
                    bottom: -4,
                    border: '2px solid #e53935',
                    borderRadius: '50%'
                  }}
                />
              )}
            </div>
          </Marker>
        );
      })}
    </Map>
  );
};

export default Mprc;