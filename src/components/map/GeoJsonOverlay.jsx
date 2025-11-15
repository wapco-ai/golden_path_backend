import React, { useEffect, useState } from 'react';
import { Marker, Source, Layer } from 'react-map-gl';
import { useLangStore } from '../../store/langStore';
import { loadGeoJsonData } from '../../utils/loadGeoJsonData.js';
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
    let isMounted = true;
    const controller = new AbortController();

    loadGeoJsonData({ language, signal: controller.signal })
      .then(data => {
        if (isMounted) {
          setFeatures(data?.features || []);
        }
      })
      .catch(err => {
        if (err?.name === 'AbortError') return;
        console.error('unable to load geojson data for overlay', err);
      });

    return () => {
      isMounted = false;
      controller.abort();
    };
  }, [language]);

  if (!features) return null;

  const pointFeatures = features.filter(
    f => f.geometry.type === 'Point' && f.properties?.nodeFunction !== 'connection'
  );
  const polygonFeatures = features.filter(
    f => f.geometry.type === 'Polygon' || f.geometry.type === 'MultiPolygon'
  );

  const polygonFillPaint = {
    'fill-color': [
      'case',
      ['has', 'group'],
      [
        'match',
        ['get', 'group'],
        'sahn', '#a5d6a7',
        'eyvan', '#90caf9',
        'ravaq', '#ce93d8',
        'masjed', '#ffcc80',
        'madrese', '#9fa8da',
        'khadamat', '#b0bec5',
        'elmi', '#80deea',
        'cemetery', '#bcaaa4',
        '#e0e0e0'
      ],
      '#e0e0e0'
    ],
    'fill-opacity': 0.35
  };

  const polygonOutlinePaint = {
    'line-color': [
      'case',
      ['has', 'group'],
      [
        'match',
        ['get', 'group'],
        'sahn', '#4caf50',
        'eyvan', '#2196f3',
        'ravaq', '#9c27b0',
        'masjed', '#ff9800',
        'madrese', '#3f51b5',
        'khadamat', '#607d8b',
        'elmi', '#00bcd4',
        'cemetery', '#795548',
        '#757575'
      ],
      '#757575'
    ],
    'line-width': 2,
    'line-opacity': 0.85
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
            id="overlay-lines"
            type="line"
            paint={polygonOutlinePaint}
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
