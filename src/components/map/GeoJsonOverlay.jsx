import React, { useEffect, useState } from 'react';
import { Marker, Source, Layer } from 'react-map-gl';
import { useLangStore } from '../../store/langStore';
import { buildGeoJsonPath } from '../../utils/geojsonPath.js';
import { groups } from '../groupData';

const groupColors = {
  sahn: '#4caf50',
  eyvan: '#2196f3',
  ravaq: '#9c27b0',
  masjed: '#ff9800',
  madrese: '#3f51b5',
  khadamat: '#607d8b',
  elmi: '#00bcd4',
  cemetery: '#795548',
  other: '#757575'
};

const groupFillColors = {
  sahn: '#c8e6c9',
  eyvan: '#bbdefb',
  ravaq: '#e1bee7',
  masjed: '#ffe0b2',
  madrese: '#c5cae9',
  khadamat: '#cfd8dc',
  elmi: '#b2ebf2',
  cemetery: '#d7ccc8',
  other: '#e0e0e0'
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
        className={`map-category-icon3 ${iconData.icon}`}
        style={{ width: '22px', height: '22px', marginTop: 0 }}
      >
        <img src={iconData.png} alt={iconData.label || 'icon'} width="18" height="18" />
      </div>
    </div>
  );
};

const GeoJsonOverlay = ({ selectedCategory, routeCoords = null }) => {
  const [features, setFeatures] = useState(null);
  const language = useLangStore((state) => state.language);

  useEffect(() => {
    const file = buildGeoJsonPath(language);

    fetch(file)
      .then(res => res.json())
      .then(data => setFeatures(data.features || []))
      .catch(err => console.error('failed to load geojson', err));
  }, [language]);

  if (!features) return null;

  const pointFeatures = features.filter(
    f => f.geometry.type === 'Point' && f.properties?.nodeFunction !== 'connection'
  );
  const polygonFeatures = features.filter(
    f => f.geometry.type === 'Polygon' || f.geometry.type === 'MultiPolygon'
  );
  const lineFeatures = features.filter(f => {
    const type = f.geometry.type;
    return type === 'LineString' || type === 'MultiLineString';
  });

  const polygonFillPaint = {
    'fill-color': [
      'case',
      ['has', 'group'],
      [
        'match',
        ['get', 'group'],
        'sahn', groupFillColors.sahn,
        'eyvan', groupFillColors.eyvan,
        'ravaq', groupFillColors.ravaq,
        'masjed', groupFillColors.masjed,
        'madrese', groupFillColors.madrese,
        'khadamat', groupFillColors.khadamat,
        'elmi', groupFillColors.elmi,
        'cemetery', groupFillColors.cemetery,
        groupFillColors.other
      ],
      groupFillColors.other
    ],
    'fill-opacity': 0.45,
    'fill-outline-color': '#ffffff'
  };

  const polygonOutlinePaint = {
    'line-color': [
      'case',
      ['has', 'group'],
      [
        'match',
        ['get', 'group'],
        'sahn', groupColors.sahn,
        'eyvan', groupColors.eyvan,
        'ravaq', groupColors.ravaq,
        'masjed', groupColors.masjed,
        'madrese', groupColors.madrese,
        'khadamat', groupColors.khadamat,
        'elmi', groupColors.elmi,
        'cemetery', groupColors.cemetery,
        groupColors.other
      ],
      groupColors.other
    ],
    'line-width': 2,
    'line-opacity': 0.85
  };

  const linePaint = {
    'line-color': [
      'case',
      ['has', 'group'],
      [
        'match',
        ['get', 'group'],
        'sahn', groupColors.sahn,
        'eyvan', groupColors.eyvan,
        'ravaq', groupColors.ravaq,
        'masjed', groupColors.masjed,
        'madrese', groupColors.madrese,
        'khadamat', groupColors.khadamat,
        'elmi', groupColors.elmi,
        'cemetery', groupColors.cemetery,
        groupColors.other
      ],
      groupColors.other
    ],
    'line-width': [
      'interpolate',
      ['linear'],
      ['zoom'],
      13, 1.5,
      16, 3,
      19, 6
    ],
    'line-opacity': 0.95,
    'line-cap': 'round',
    'line-join': 'round'
  };

  const lineGlowPaint = {
    'line-color': 'rgba(255,255,255,0.65)',
    'line-width': [
      'interpolate',
      ['linear'],
      ['zoom'],
      13, 2.5,
      16, 5,
      19, 9
    ],
    'line-opacity': 0.35,
    'line-blur': 1.5
  };

  return (
    <>
      {polygonFeatures.length > 0 && (
        <Source
          id="overlay-polygons"
          type="geojson"
          data={{ type: 'FeatureCollection', features: polygonFeatures }}
        >
          <Layer
            id="overlay-polygon-fill"
            type="fill"
            paint={polygonFillPaint}
          />
          <Layer
            id="overlay-polygon-outline"
            type="line"
            paint={polygonOutlinePaint}
          />
        </Source>
      )}
      {lineFeatures.length > 0 && (
        <Source
          id="overlay-lines"
          type="geojson"
          data={{ type: 'FeatureCollection', features: lineFeatures }}
        >
          <Layer
            id="overlay-line-glow"
            type="line"
            paint={lineGlowPaint}
          />
          <Layer
            id="overlay-line-features"
            type="line"
            paint={linePaint}
          />
        </Source>
      )}
      {(routeCoords && routeCoords.length > 0
        ? pointFeatures.filter(f =>
          routeCoords.some(c =>
            c[0].toFixed(6) === f.geometry.coordinates[0].toFixed(6) &&
            c[1].toFixed(6) === f.geometry.coordinates[1].toFixed(6)
          )
        )
        : pointFeatures
      ).map((feature, idx) => {
        const [lng, lat] = feature.geometry.coordinates;
        const { group, nodeFunction } = feature.properties || {};
        const highlight =
          selectedCategory &&
          feature.properties &&
          feature.properties[selectedCategory.property] === selectedCategory.value;
        const hasFilter = !!selectedCategory;
        const iconSize = hasFilter ? (highlight ? 30 : 15) : 20;
        const iconOpacity = hasFilter ? (highlight ? 1 : 0.4) : 1;
        const rawId = feature.properties?.uniqueId;
        const key = rawId ? `${rawId}-${idx}` : idx;
        return (
          <Marker key={key} longitude={lng} latitude={lat} anchor="center">
            {getCompositeIcon(group, nodeFunction, iconSize, iconOpacity)}
          </Marker>
        );
      })}
    </>
  );
};

export default GeoJsonOverlay;
