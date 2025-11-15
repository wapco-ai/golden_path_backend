import React, { useEffect, useState, useCallback } from 'react';
import Map, { Marker, Source, Layer } from 'react-map-gl';
import { useIntl } from 'react-intl';
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import esriImageryStyle from '../../services/esriImageryStyle';
import { useLangStore } from '../../store/langStore';
import { buildGeoJsonPath } from '../../utils/geojsonPath.js';
import { fetchMapGeojson } from '../../services/geojsonService.js';
import { groups, subGroups } from '../groupData';
import { getLocationTitleById } from '../../utils/getLocationTitle';

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

const getCompositeIcon = (group, nodeFunction, size = 35, opacity = 1) => {
  const color = groupColors[group] || '#999';
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

const Mpbc = ({
  setUserLocation,
  selectedDestination = null,
  onMapClick,
  selectedCategory,
  userLocation,
  isTracking = true,
  onUserMove,
  showImageMarkers = true,
  isQrCodeEntry = false
}) => {
  const intl = useIntl();
  const [viewState, setViewState] = useState({
    latitude: 36.2880,
    longitude: 59.6157,
    zoom: 16
  });
  const [userCoords, setUserCoords] = useState(null);
  const [destCoords, setDestCoords] = useState(null);
  const [geoData, setGeoData] = useState(null);
  const [routeCoords, setRouteCoords] = useState(null);
  const language = useLangStore((state) => state.language);
  const [selectedFeatureForBubble, setSelectedFeatureForBubble] = useState(null);

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

    // Holy shrine coordinates as default
    const shrineCoords = { lat: 36.2880, lng: 59.6157 };

    // Priority 1: QR code location
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
        latitude: coords.lat - 0.0004,
        longitude: coords.lng,
        zoom: 18
      }));
      return;
    }

    // Priority 2: Always use shrine location as default, even if GPS is available
    setUserCoords(shrineCoords);
    setUserLocation({
      name: intl.formatMessage({ id: 'defaultBabRezaName' }),
      coordinates: [shrineCoords.lat, shrineCoords.lng]
    });

    // Optional: Still get GPS for tracking but don't use it as primary location
    if (isTracking) {
      const success = (pos) => {
        // We get GPS but don't update the main user location
        // This can be used for other purposes like tracking movement
        console.log('GPS location available but not used as primary:', pos.coords);
      };

      const err = (e) => {
        console.error('Error getting GPS location', e);
      };

      navigator.geolocation.getCurrentPosition(success, err, {
        enableHighAccuracy: false,
        timeout: 10000,
        maximumAge: 60000
      });

      const watchId = navigator.geolocation.watchPosition(success, err, {
        enableHighAccuracy: false,
        maximumAge: 0,
        timeout: 10000
      });

      return () => {
        if (watchId) navigator.geolocation.clearWatch(watchId);
      };
    }
  }, [setUserLocation, intl, isTracking]);

  useEffect(() => {
    if (userLocation?.coordinates) {
      const [lat, lng] = userLocation.coordinates;
      const coords = { lat, lng };
      setUserCoords(coords);

      // CRITICAL FIX: Only center if tracking is enabled AND we're not handling a map selection
      const isMapSelection = sessionStorage.getItem('mapSelectedLat') &&
        sessionStorage.getItem('mapSelectedLng');

      if (isTracking && !isMapSelection) {
        setViewState(v => ({
          ...v,
          latitude: coords.lat - 0.0004,
          longitude: coords.lng - 0.000,
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

  const handleClick = (e) => {
    const { lng, lat } = e.lngLat;
    const c = { lat, lng };

    let closestFeature = null;
    if (geoData) {
      let minDist = Infinity;
      geoData.features.forEach((f) => {
        if (f.geometry.type === 'Point') {
          const [flng, flat] = f.geometry.coordinates;
          const d = Math.hypot(flng - lng, flat - lat);

          // CRITICAL FIX: Only consider features that should be selectable
          const { group, subGroupValue } = f.properties || {};
          const subgroup = subGroups[group]?.find(sg => sg.value === subGroupValue);
          const hasImage = subgroup && subgroup.img;

          // When no category is selected, only allow selection of features with images
          if (!selectedCategory && !hasImage) {
            return; // Skip this feature - not selectable
          }

          // When category is selected, only allow selection of features from that category
          if (selectedCategory && group !== selectedCategory.value) {
            return; // Skip this feature - not in selected category
          }

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

    // Set the selected feature for the bubble
    setSelectedFeatureForBubble(closestFeature);

    if (onMapClick) onMapClick(c, closestFeature);
  };

  useEffect(() => {
    let isMounted = true;
    const controller = new AbortController();

    const loadGeojson = async () => {
      try {
        const data = await fetchMapGeojson({ language, floor: 0, signal: controller.signal });
        if (isMounted) {
          setGeoData(data);
          return;
        }
      } catch (err) {
        if (err?.name === 'AbortError') {
          return;
        }
        console.error('failed to load geojson from api service', err);
      }

      const file = buildGeoJsonPath(language);
      try {
        const response = await fetch(file, { signal: controller.signal });
        if (!response.ok) {
          throw new Error(`GeoJSON fallback failed with status ${response.status}`);
        }
        const data = await response.json();
        if (isMounted) {
          setGeoData(data);
        }
      } catch (fallbackErr) {
        if (fallbackErr?.name === 'AbortError') {
          return;
        }
        console.error('failed to load geojson fallback', fallbackErr);
      }
    };

    loadGeojson();

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

  // Filter point features - only show icon markers when a category is selected
  const pointFeatures = geoData
    ? geoData.features.filter(f => {
      if (f.geometry.type !== 'Point') return false;

      const { group, subGroupValue } = f.properties || {};
      const subgroup = subGroups[group]?.find(sg => sg.value === subGroupValue);
      const hasImage = subgroup && subgroup.img;

      // If no category is selected, don't show any icon markers (only image markers)
      if (!selectedCategory) return false;

      // If a category is selected, only show features from that category
      if (selectedCategory && group !== selectedCategory.value) return false;

      // Don't show features that have images (they're handled separately)
      if (hasImage) return false;

      return true;
    })
    : [];

  const polygonFeatures = geoData
    ? geoData.features.filter(
      f => f.geometry.type === 'Polygon' || f.geometry.type === 'MultiPolygon'
    )
    : [];

  // Function to render image markers for subgroups with images
  const renderImageMarkers = () => {
    if (!geoData) return null;

    const seenSubgroups = new Set();

    return geoData.features
      .filter(feature => {
        if (feature.geometry.type !== 'Point') return false;

        const { group, subGroupValue } = feature.properties || {};
        const subgroup = subGroups[group]?.find(sg => sg.value === subGroupValue);

        const hasImage = subgroup && subgroup.img &&
          (Array.isArray(subgroup.img) ? subgroup.img.length > 0 : true);

        if (!hasImage) return false;

        // Skip if we've already seen this subgroup
        if (seenSubgroups.has(subGroupValue)) return false;

        seenSubgroups.add(subGroupValue);

        if (!selectedCategory || group === selectedCategory.value) {
          return true;
        }

        return false;
      })
      .map((feature, idx) => {
        const [lng, lat] = feature.geometry.coordinates;
        const { group, subGroupValue } = feature.properties || {};
        const subgroup = subGroups[group]?.find(sg => sg.value === subGroupValue);

        // Get the first image if it's an array, otherwise use the string
        const imageUrl = Array.isArray(subgroup.img) ? subgroup.img[0] : subgroup.img;

        return (
          <Marker key={`image-${idx}`} longitude={lng} latitude={lat} anchor="center">
            <div className="image-marker-container">
              <svg width="55" height="63" viewBox="0 0 55 63" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M54.6562 27.3281C54.6562 39.6299 46.5275 50.0319 35.3486 53.459C35.1079 53.8493 34.8535 54.2605 34.585 54.6924L33.1699 56.9687C30.7353 60.8845 29.5175 62.8418 27.7412 62.8418C25.9651 62.8417 24.7479 60.8842 22.3135 56.9687L20.8975 54.6924C20.6938 54.3648 20.4993 54.0485 20.3115 53.7451C8.61859 50.6476 8.59898e-05 39.9953 -1.19455e-06 27.3281C-5.34814e-07 12.2351 12.2351 -1.85429e-06 27.3281 -1.19455e-06C42.4211 0.000106671 54.6562 12.2352 54.6562 27.3281Z" fill="white" />
              </svg>
              <div
                className="image-marker-content"
                style={{ backgroundImage: `url(${imageUrl})` }}
              />
            </div>
          </Marker>
        );
      });
  };

  return (
    <Map
      mapLib={maplibregl}
      mapStyle={esriImageryStyle}
      style={{ width: '100%', height: '100%' }}
      {...viewState}
      onMove={onMove}
      onClick={handleClick}
      interactive={true}
    >
      {/* User location marker */}
      {userCoords && isQrCodeEntry && (
        <Marker longitude={userCoords.lng} latitude={userCoords.lat} anchor="center">
          <div className="map-marker-origin">
            <div className="map-marker-origin-inner" />
          </div>
        </Marker>
      )}

      {/* Bubble name*/}
      {selectedFeatureForBubble && selectedFeatureForBubble.geometry.type === 'Point' && (
        <Marker
          longitude={selectedFeatureForBubble.geometry.coordinates[0]}
          latitude={selectedFeatureForBubble.geometry.coordinates[1]}
          anchor="bottom"
          offset={[0, 75]}
        >
          <div className="location-bubble">
            <svg width="140" height="40" viewBox="0 0 140 40" fill="none" xmlns="http://www.w3.org/2000/svg">
              <rect x="0.5" y="0.5" width="139" height="39" rx="19.5" fill="white" />
              <rect x="0.5" y="0.5" width="139" height="39" rx="19.5" stroke="#0F71EF" />
              <text
                x="70"
                y="22"
                textAnchor="middle"
                dominantBaseline="middle"
                fill="#0F71EF"
                fontFamily="Vazir, Tahoma, sans-serif"
                fontSize="12"
                fontWeight="600"
              >
                {selectedFeatureForBubble.properties?.name ||
                  selectedFeatureForBubble.properties?.subGroup}
              </text>
            </svg>
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

      {/* Image markers for subgroups with images */}
      {renderImageMarkers()}

      {/* Point features (doors, services, etc.) - Only show when a category is selected */}
      {pointFeatures.map((feature, idx) => {
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

export default Mpbc; 