import React, { useEffect, useState } from 'react';
import Map, { Marker, Source, Layer } from 'react-map-gl';
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import useOfflineMapStyle from '../../hooks/useOfflineMapStyle';
import GeoJsonOverlay from './GeoJsonOverlay';
import useLocaleDigits from '../../utils/useLocaleDigits';


const Routing = ({ userLocation, routeSteps, currentStep }) => {
  const formatDigits = useLocaleDigits();
  const { mapStyle, handleMapError } = useOfflineMapStyle();
  const initial = routeSteps && routeSteps.length > 0 ? routeSteps[0].coordinates : [36.2880, 59.6157];
  const [viewState, setViewState] = useState({ latitude: initial[0], longitude: initial[1], zoom: 18 });

  useEffect(() => {
    if (currentStep != null && routeSteps && routeSteps[currentStep]) {
      const coord = routeSteps[currentStep].coordinates;
      setViewState(v => ({ ...v, latitude: coord[0], longitude: coord[1] }));
    }
  }, [currentStep, routeSteps]);

  const routePath = routeSteps ? routeSteps.map(s => [s.coordinates[1], s.coordinates[0]]) : [];

  const currentSegment =
    routeSteps && routeSteps[currentStep]
      ? {
          type: 'Feature',
          geometry: { type: 'LineString', coordinates: routeSteps[currentStep].coordinates.map(p => [p[1], p[0]]) }
        }
      : null;

  const fullGeo = { type: 'Feature', geometry: { type: 'LineString', coordinates: routePath } };

  return (
    <div ref={null} className="route-map">
      <Map
        mapLib={maplibregl}
        mapStyle={mapStyle}
        style={{ width: '100%', height: '100%' }}
        viewState={viewState}
        onError={handleMapError}
      >
        {userLocation && (
          <Marker longitude={userLocation[1]} latitude={userLocation[0]} anchor="bottom">
            <div>👤</div>
          </Marker>
        )}
        {routeSteps &&
          routeSteps.map((step, idx) => (
            <Marker key={idx} longitude={step.coordinates[1]} latitude={step.coordinates[0]} anchor="bottom">
              <div className={`custom-marker ${idx === currentStep ? 'active' : ''}`}>{formatDigits(idx + 1)}</div>
            </Marker>
          ))}
        {routeSteps && (
          <Source id="route" type="geojson" data={fullGeo}>
            <Layer id="route-line" type="line" paint={{ 'line-color': '#3498db', 'line-width': 4, 'line-dasharray': [10, 10] }} />
          </Source>
        )}
        {currentSegment && (
          <Source id="segment" type="geojson" data={currentSegment}>
            <Layer id="segment-line" type="line" paint={{ 'line-color': '#e74c3c', 'line-width': 6 }} />
          </Source>
        )}

        <GeoJsonOverlay routeCoords={routePath} />
      </Map>
    </div>
  );
};

export default Routing;
