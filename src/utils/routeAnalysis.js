

import appConfig from '../config/appConfig.js';
import { useLangStore } from '../store/langStore.js';

const EARTH_RADIUS_METERS = 6371000;
const GEOJSON_ROUTE_MATCH_TOLERANCE_METERS = 30;
const GEOJSON_ROUTE_NODE_SNAP_METERS = 20;
const DOOR_BOUNDARY_TOLERANCE_METERS = Number(appConfig.doorBoundaryToleranceMeters) || 4;

const COVERED_ENTRY_NAMES = {
  fa: 'ورودی مسقف',
  en: 'Covered Entrance',
  ar: 'مدخل مسقوف',
  ur: 'ڈھکا ہوا داخلہ'
};

function getCoveredEntryName() {
  const lang = useLangStore.getState().language;
  return COVERED_ENTRY_NAMES[lang] || COVERED_ENTRY_NAMES.fa;
}

function booleanPointInPolygon(point, polygon) {
  const x = point[0];
  const y = point[1];
  const coords = polygon.geometry?.coordinates[0] || polygon.coordinates[0];
  let inside = false;
  for (let i = 0, j = coords.length - 1; i < coords.length; j = i++) {
    const xi = coords[i][0];
    const yi = coords[i][1];
    const xj = coords[j][0];
    const yj = coords[j][1];
    const intersect = ((yi > y) !== (yj > y)) &&
      (x < ((xj - xi) * (y - yi)) / (yj - yi) + xi);
    if (intersect) inside = !inside;
  }
  return inside;
}

function pointsWithinPolygon(pointsFC, polygon) {
  const features = pointsFC.features.filter(pt =>
    booleanPointInPolygon(pt.geometry.coordinates, polygon)
  );
  return { type: 'FeatureCollection', features };
}

export function findNearest(coord, features) {
  if (!features || features.length === 0) return null;
  let best = null;
  let min = Infinity;
  features.forEach(f => {
    const [lng, lat] = f.geometry.coordinates;
    const d = Math.hypot(lng - coord[1], lat - coord[0]);
    if (d < min) {
      min = d;
      best = [lat, lng, f.properties, d];
    }
  });
  return best;
}

function findNearestList(coord, features, count = 2) {
  if (!features || features.length === 0) return [];
  return features
    .map(f => {
      const [lng, lat] = f.geometry.coordinates;
      const d = Math.hypot(lng - coord[1], lat - coord[0]);
      return { lat, lng, props: f.properties, distance: d, feature: f };
    })
    .sort((a, b) => a.distance - b.distance)
    .slice(0, count)
    .map(r => [r.lat, r.lng, r.props, r.distance]);
}

function findNearestByArea(coord, features, area) {
  const filtered = features.filter(f => f.properties?.subGroupValue === area);
  return findNearest(coord, filtered);
}

const normalizeGender = value => {
  if (!value && value !== 0) return [];
  if (Array.isArray(value)) return value;
  if (typeof value === 'string') {
    if (!value.trim()) return [];
    return value.split(',');
  }
  return [value];
};

export function genderAllowed(nodeGender, selected) {
  const normalizedSelected = typeof selected === 'string' ? selected.toLowerCase() : '';
  const genders = normalizeGender(nodeGender)
    .map(g => (typeof g === 'string' ? g.toLowerCase().trim() : ''))
    .filter(Boolean);

  if (!genders.length) return true;
  if (normalizedSelected === 'family') {
    return genders.includes('family');
  }
  if (genders.includes('family')) return true;
  return genders.includes(normalizedSelected);
}

function haversineDistanceMeters(coordA, coordB) {
  if (!coordA || !coordB) return Infinity;
  const toRad = deg => (deg * Math.PI) / 180;
  const [lat1, lng1] = coordA;
  const [lat2, lng2] = coordB;
  const dLat = toRad(lat2 - lat1);
  const dLng = toRad(lng2 - lng1);
  const a = Math.sin(dLat / 2) ** 2 +
    Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  return EARTH_RADIUS_METERS * c;
}

function extractRouteCoordinates(geometry) {
  if (!geometry) return [];
  if (geometry.type === 'LineString') {
    return geometry.coordinates.map(coord => [coord[1], coord[0]]);
  }
  if (geometry.type === 'MultiLineString') {
    return geometry.coordinates.flat().map(coord => [coord[1], coord[0]]);
  }
  return [];
}

function parseMinutesValue(value) {
  if (value == null) return null;
  if (typeof value === 'number' && !Number.isNaN(value)) {
    return value;
  }
  if (typeof value === 'string') {
    const iso = value.match(/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i);
    if (iso) {
      const hours = parseInt(iso[1] || '0', 10);
      const minutes = parseInt(iso[2] || '0', 10);
      const seconds = parseInt(iso[3] || '0', 10);
      const totalMinutes = hours * 60 + minutes + Math.round(seconds / 60);
      return Number.isNaN(totalMinutes) ? null : totalMinutes;
    }

    if (value.includes(':')) {
      const parts = value.split(':').map(part => parseInt(part, 10));
      if (parts.every(num => !Number.isNaN(num))) {
        const [hours, minutes, seconds] = [
          parts.length === 3 ? parts[0] : 0,
          parts.length === 3 ? parts[1] : parts[0],
          parts.length === 3 ? parts[2] : parts.length === 2 ? parts[1] : 0
        ];
        return hours * 60 + minutes + Math.round(seconds / 60);
      }
    }

    const match = value.match(/(\d+(?:\.\d+)?)/);
    if (match) {
      const parsed = parseFloat(match[1]);
      if (!Number.isNaN(parsed)) {
        return Math.round(parsed);
      }
    }
  }
  return null;
}

function computeRouteMetrics(pathCoords, properties) {
  let distanceMeters = 0;
  if (Array.isArray(pathCoords) && pathCoords.length > 1) {
    for (let i = 1; i < pathCoords.length; i += 1) {
      distanceMeters += haversineDistanceMeters(pathCoords[i - 1], pathCoords[i]);
    }
  }

  if (properties) {
    const candidates = [
      properties.estimatedTimeMinutes,
      properties.timeMinutes,
      properties.durationMinutes,
      properties.duration,
      properties.estimatedTime,
      properties.time
    ];
    for (const value of candidates) {
      const minutes = parseMinutesValue(value);
      if (minutes != null) {
        return { distanceMeters, estimatedMinutes: minutes };
      }
    }
  }

  if (distanceMeters === 0) {
    return { distanceMeters, estimatedMinutes: null };
  }

  const estimatedMinutes = Math.max(1, Math.round(distanceMeters / 60));
  return { distanceMeters, estimatedMinutes };
}

function isTransportModeAllowed(properties, mode) {
  if (!properties) return true;
  const modes = properties.transportModes;
  if (Array.isArray(modes) && modes.length > 0) {
    return modes.includes(mode);
  }
  return true;
}

// استفاده از turf.js برای تشخیص دقیق نقطه در polygon
function pointInPolygon(point, polygon) {
  try {
    return booleanPointInPolygon(point, polygon);
  } catch (error) {
    console.warn('Error in pointInPolygon:', error);
    return false;
  }
}

function getArea(coord, sahns) {
  const point = [coord[1], coord[0]]; // Convert to [lng, lat]
  const match = sahns.find(p => pointInPolygon(point, p));
  return match ? match.properties?.subGroupValue : null;
}

// Get the polygon object that contains a coordinate
function getPolygonContaining(coord, polygons) {
  const point = [coord[1], coord[0]]; // Convert to [lng, lat]
  return polygons.find(p => pointInPolygon(point, p));
}

// Extract ordered list of unique sahn names along a path
function extractSahnSequence(path, polygons) {
  const seq = [];
  path.forEach(coord => {
    const poly = getPolygonContaining(coord, polygons);
    const sahnName = poly ? poly.properties?.subGroup : null;
    if (sahnName && seq[seq.length - 1] !== sahnName) {
      seq.push(sahnName);
    }
  });
  return seq;
}

// Check if polygons are adjacent (share a border)
function arePolygonsAdjacent(poly1, poly2) {
  if (!poly1 || !poly2) return false;
  if (poly1 === poly2) return false;
  
  const coords1 = poly1.geometry.coordinates[0];
  const coords2 = poly2.geometry.coordinates[0];
  
  // Check if any edge from poly1 is very close to any edge from poly2
  for (let i = 0; i < coords1.length - 1; i++) {
    for (let j = 0; j < coords2.length - 1; j++) {
      const dist = Math.min(
        Math.hypot(coords1[i][0] - coords2[j][0], coords1[i][1] - coords2[j][1]),
        Math.hypot(coords1[i+1][0] - coords2[j][0], coords1[i+1][1] - coords2[j][1]),
        Math.hypot(coords1[i][0] - coords2[j+1][0], coords1[i][1] - coords2[j+1][1]),
        Math.hypot(coords1[i+1][0] - coords2[j+1][0], coords1[i+1][1] - coords2[j+1][1])
      );
      if (dist < 0.00001) return true; // Very close = adjacent
    }
  }
  return false;
}

// Find nodes in a specific polygon using turf.js
function getNodesInPolygon(nodes, polygon) {
  if (!polygon) return [];
  
  try {
    // Create points FeatureCollection from nodes
    const pointsFC = {
      type: 'FeatureCollection',
      features: nodes.map((node, index) => ({
        type: 'Feature',
        geometry: {
          type: 'Point',
          coordinates: [node[1], node[0]] // [lng, lat]
        },
        properties: { index }
      }))
    };
    
    // Use turf pointsWithinPolygon
    const within = pointsWithinPolygon(pointsFC, polygon);
    
    // Return indices of nodes within polygon
    return within.features.map(f => f.properties.index);
    
  } catch (error) {
    console.warn('Error in getNodesInPolygon:', error);
    return [];
  }
}

// Find connection nodes between two polygons
function findConnectionNodesBetweenPolygons(nodes, poly1, poly2) {
  const connectionNodes = [];
  
  for (let i = 0; i < nodes.length; i++) {
    const node = nodes[i];
    const nodeType = node[2]?.nodeFunction;
    
    if (nodeType === 'connection') {
      const nodeCoord = [node[0], node[1]]; // [lat, lng]
      const nodePolygon = getPolygonContaining(nodeCoord, [poly1, poly2]);
      
      // If connection is in either polygon or between them
      if (nodePolygon === poly1 || nodePolygon === poly2) {
        // Check distance to both polygons
        const nodes1 = getNodesInPolygon(nodes, poly1);
        const nodes2 = getNodesInPolygon(nodes, poly2);
        
        if (nodes1.length > 0 && nodes2.length > 0) {
          connectionNodes.push({
            index: i,
            node: node,
            polygon: nodePolygon
          });
        }
      }
    }
  }
  
  return connectionNodes;
}

// Find closest door pairs between two polygons
function findClosestDoorsBetweenPolygons(nodes, poly1, poly2) {
  const nodes1 = getNodesInPolygon(nodes, poly1);
  const nodes2 = getNodesInPolygon(nodes, poly2);
  
  if (nodes1.length === 0 || nodes2.length === 0) return [];
  
  const doorPairs = [];
  for (const n1 of nodes1) {
    for (const n2 of nodes2) {
      const distance = Math.hypot(
        nodes[n1][0] - nodes[n2][0], 
        nodes[n1][1] - nodes[n2][1]
      );
      doorPairs.push({ from: n1, to: n2, distance });
    }
  }
  
  // Return closest pairs (up to 2)
  return doorPairs.sort((a, b) => a.distance - b.distance).slice(0, 2);
}

// Helper function to check if a line segment intersects with another
function segmentsIntersect(p1, p2, p3, p4) {
  function orientation(p, q, r) {
    const val = (q[0] - p[0]) * (r[1] - q[1]) - (q[1] - p[1]) * (r[0] - q[0]);
    if (Math.abs(val) < 1e-10) return 0;
    return (val > 0) ? 1 : 2;
  }

  function onSegment(p, q, r) {
    return (q[1] <= Math.max(p[1], r[1]) && q[1] >= Math.min(p[1], r[1]) &&
            q[0] <= Math.max(p[0], r[0]) && q[0] >= Math.min(p[0], r[0]));
  }

  const o1 = orientation(p1, p2, p3);
  const o2 = orientation(p1, p2, p4);
  const o3 = orientation(p3, p4, p1);
  const o4 = orientation(p3, p4, p2);

  if (o1 !== o2 && o3 !== o4) return true;

  if (o1 === 0 && onSegment(p1, p3, p2)) return true;
  if (o2 === 0 && onSegment(p1, p4, p2)) return true;
  if (o3 === 0 && onSegment(p3, p1, p4)) return true;
  if (o4 === 0 && onSegment(p3, p2, p4)) return true;

  return false;
}

function extractLineStrings(geometry) {
  if (!geometry) return [];
  if (geometry.type === 'LineString') return [geometry.coordinates];
  if (geometry.type === 'MultiLineString') return geometry.coordinates;
  return [];
}

function buildDoorLineNodes(lineFeatures) {
  const nodes = [];
  const seen = new Set();

  lineFeatures.forEach(feature => {
    const lines = extractLineStrings(feature.geometry);
    lines.forEach(lineCoords => {
      if (!Array.isArray(lineCoords) || lineCoords.length === 0) return;

      for (let i = 0; i < lineCoords.length; i++) {
        const coord = lineCoords[i];
        addLineNode(coord, feature);

        if (i < lineCoords.length - 1) {
          const nextCoord = lineCoords[i + 1];
          const mid = [
            (coord[0] + nextCoord[0]) / 2,
            (coord[1] + nextCoord[1]) / 2
          ];
          addLineNode(mid, feature);
        }
      }
    });
  });

  function addLineNode(coord, feature) {
    if (!coord || coord.length < 2) return;
    const [lng, lat] = coord;
    const key = `${lat.toFixed(8)}:${lng.toFixed(8)}`;
    if (seen.has(key)) return;
    seen.add(key);
    const props = { ...(feature.properties || {}), isDoorLineNode: true };
    nodes.push([lat, lng, props, 0, feature]);
  }

  return nodes;
}

function getDoorLineSegments(lineFeatures) {
  const segments = [];
  lineFeatures.forEach(feature => {
    const lines = extractLineStrings(feature.geometry);
    lines.forEach(lineCoords => {
      if (!Array.isArray(lineCoords) || lineCoords.length < 2) return;
      for (let i = 0; i < lineCoords.length - 1; i++) {
        const start = lineCoords[i];
        const end = lineCoords[i + 1];
        segments.push([
          [start[0], start[1]],
          [end[0], end[1]]
        ]);
      }
    });
  });
  return segments;
}

function projectPointToMeters(point, refLat) {
  const latRad = (point[1] * Math.PI) / 180;
  const lngRad = (point[0] * Math.PI) / 180;
  const refLatRad = (refLat * Math.PI) / 180;
  return {
    x: EARTH_RADIUS_METERS * lngRad * Math.cos(refLatRad),
    y: EARTH_RADIUS_METERS * latRad
  };
}

function pointToSegmentDistanceMeters(point, segStart, segEnd) {
  const vx = segEnd.x - segStart.x;
  const vy = segEnd.y - segStart.y;
  const wx = point.x - segStart.x;
  const wy = point.y - segStart.y;

  const c1 = vx * wx + vy * wy;
  if (c1 <= 0) {
    return Math.hypot(point.x - segStart.x, point.y - segStart.y);
  }

  const c2 = vx * vx + vy * vy;
  if (c2 <= c1) {
    return Math.hypot(point.x - segEnd.x, point.y - segEnd.y);
  }

  const b = c1 / c2;
  const projX = segStart.x + b * vx;
  const projY = segStart.y + b * vy;
  return Math.hypot(point.x - projX, point.y - projY);
}

function segmentsWithinTolerance(seg1Start, seg1End, seg2Start, seg2End, toleranceMeters) {
  if (segmentsIntersect(seg1Start, seg1End, seg2Start, seg2End)) {
    return true;
  }

  const refLat = (seg1Start[1] + seg1End[1] + seg2Start[1] + seg2End[1]) / 4;
  const a1 = projectPointToMeters(seg1Start, refLat);
  const a2 = projectPointToMeters(seg1End, refLat);
  const b1 = projectPointToMeters(seg2Start, refLat);
  const b2 = projectPointToMeters(seg2End, refLat);

  const distances = [
    pointToSegmentDistanceMeters(a1, b1, b2),
    pointToSegmentDistanceMeters(a2, b1, b2),
    pointToSegmentDistanceMeters(b1, a1, a2),
    pointToSegmentDistanceMeters(b2, a1, a2)
  ];

  return distances.some(d => d <= toleranceMeters);
}

function isEdgePassable(edgeStart, edgeEnd, doorSegments, toleranceMeters) {
  if (!doorSegments || doorSegments.length === 0) return false;
  if (!toleranceMeters || toleranceMeters <= 0) return false;
  return doorSegments.some(segment =>
    segmentsWithinTolerance(edgeStart, edgeEnd, segment[0], segment[1], toleranceMeters)
  );
}

// Check if a direct line crosses any polygon WITHOUT nodes
function crossesEmptyPolygons(coord1, coord2, polygons, nodes, doorSegments = [], toleranceMeters = 0) {
  const p1 = [coord1[1], coord1[0]]; // Convert to [lng, lat]
  const p2 = [coord2[1], coord2[0]];

  const coveredName = getCoveredEntryName();
  for (const polygon of polygons) {
    if (polygon.properties?.name === coveredName) continue;
    // Check if this polygon has any nodes
    const nodesInPoly = getNodesInPolygon(nodes, polygon);
    if (nodesInPoly.length > 0) continue; // Has nodes, OK to cross
    
    const vertices = polygon.geometry.coordinates[0];
    
    // Check if line intersects this empty polygon
    for (let i = 0; i < vertices.length - 1; i++) {
      const p3 = vertices[i];
      const p4 = vertices[i + 1];
      if (segmentsIntersect(p1, p2, p3, p4)) {
        if (isEdgePassable(p3, p4, doorSegments, toleranceMeters)) {
          continue;
        }
        console.log(`Line blocked by empty polygon: ${polygon.properties?.subGroupValue}`);
        return true;
      }
    }
  }
  return false;
}

// Compute a simple centroid for a polygon
function polygonCentroid(polygon) {
  const coords = polygon.geometry.coordinates[0];
  let area = 0;
  let cx = 0;
  let cy = 0;
  for (let i = 0, j = coords.length - 1; i < coords.length; j = i++) {
    const x0 = coords[j][0];
    const y0 = coords[j][1];
    const x1 = coords[i][0];
    const y1 = coords[i][1];
    const a = x0 * y1 - x1 * y0;
    area += a;
    cx += (x0 + x1) * a;
    cy += (y0 + y1) * a;
  }
  area *= 0.5;
  if (Math.abs(area) < 1e-12) {
    return coords[0];
  }
  cx /= (6 * area);
  cy /= (6 * area);
  return [cx, cy];
}

// If a line inside a polygon is obstructed, return a curved path via centroid
function adjustSegmentInsidePolygon(start, end, polygons, doorSegments = [], toleranceMeters = 0) {
  const poly = getPolygonContaining(start, polygons);
  if (!poly) return [end];
  if (getPolygonContaining(end, polygons) !== poly) return [end];
  const coveredName = getCoveredEntryName();
  if (poly.properties?.name === coveredName) return [end];
  if (!isLineObstructed(start, end, [poly], doorSegments, toleranceMeters)) return [end];

  const centroid = polygonCentroid(poly);
  const mid = [centroid[1], centroid[0]];
  if (
    !isLineObstructed(start, mid, [poly], doorSegments, toleranceMeters) &&
    !isLineObstructed(mid, end, [poly], doorSegments, toleranceMeters)
  ) {
    return [mid, end];
  }
  return [end];
}

// Check if a direct line between two coordinates is obstructed by polygon walls
function isLineObstructed(coord1, coord2, polygons, doorSegments = [], toleranceMeters = 0) {
  const p1 = [coord1[1], coord1[0]]; // Convert to [lng, lat]
  const p2 = [coord2[1], coord2[0]];

  const coveredName = getCoveredEntryName();
  for (const polygon of polygons) {
    if (polygon.properties?.name === coveredName) continue;
    const vertices = polygon.geometry.coordinates[0];
    
    // Check if both points are inside the same polygon (allowed)
    const point1 = [coord1[1], coord1[0]]; // [lng, lat]
    const point2 = [coord2[1], coord2[0]]; // [lng, lat]
    const p1Inside = pointInPolygon(point1, polygon);
    const p2Inside = pointInPolygon(point2, polygon);
    
    if (p1Inside && p2Inside) continue; // Both inside same polygon = OK
    
    // Check for edge intersections
    for (let i = 0; i < vertices.length - 1; i++) {
      const p3 = vertices[i];
      const p4 = vertices[i + 1];
      if (segmentsIntersect(p1, p2, p3, p4)) {
        if (isEdgePassable(p3, p4, doorSegments, toleranceMeters)) {
          continue;
        }
        return true;
      }
    }
  }
  return false;
}

function attachLandmarks(path, steps, pois) {
  const toRad = deg => (deg * Math.PI) / 180;
  const toDeg = rad => (rad * 180) / Math.PI;
  const bearing = (from, to) => {
    const [lng1, lat1] = from;
    const [lng2, lat2] = to;
    const y = Math.sin(toRad(lng2 - lng1)) * Math.cos(toRad(lat2));
    const x =
      Math.cos(toRad(lat1)) * Math.sin(toRad(lat2)) -
      Math.sin(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.cos(toRad(lng2 - lng1));
    return (toDeg(Math.atan2(y, x)) + 360) % 360;
  };

  const distLimit = 0.0006; // ~30m in degrees
  for (let i = 1; i < path.length && i - 1 < steps.length; i++) {
    const start = [path[i - 1][1], path[i - 1][0]];
    const end = [path[i][1], path[i][0]];
    const segBearing = bearing(start, end);
    let best = null;
    pois.forEach(poi => {
      const poiCoord = poi.geometry.coordinates;
      const poiBearing = bearing(start, poiCoord);
      let diff = Math.abs(segBearing - poiBearing);
      if (diff > 180) diff = 360 - diff;
      const dist = Math.hypot(poiCoord[0] - end[0], poiCoord[1] - end[1]);
      if (diff <= 20 && dist <= distLimit) {
        if (!best || diff < best.diff || (diff === best.diff && dist < best.distance)) {
          best = { name: poi.properties?.name || '', distance: dist, diff };
        }
      }
    });
    if (best) {
      steps[i - 1].landmark = best.name;
      steps[i - 1].landmarkDistance = Math.round(best.distance * 100000);
    }
  }
}

// Enhanced connection logic with PRIORITY for connection nodes
function findValidNeighbors(nodeIndex, nodes, navigablePolygons, doorSegments = [], toleranceMeters = 0) {
  const currentNode = nodes[nodeIndex];
  const currentCoord = [currentNode[0], currentNode[1]]; // [lat, lng]
  const currentPolygon = getPolygonContaining(currentCoord, navigablePolygons);
  const currentType = currentNode[2]?.nodeFunction;
  const neighbors = [];
  
  // Maximum straight-line distance allowed when linking nodes across
  // polygons. 0.0008 degrees is roughly equal to 80 metres.
  const MAX_CROSS_POLYGON_DISTANCE = 0.0004;

  for (let i = 0; i < nodes.length; i++) {
    if (i === nodeIndex) continue;

    const otherNode = nodes[i];
    const otherCoord = [otherNode[0], otherNode[1]];
    const otherPolygon = getPolygonContaining(otherCoord, navigablePolygons);
    const otherType = otherNode[2]?.nodeFunction;
    const distance = Math.hypot(currentCoord[0] - otherCoord[0], currentCoord[1] - otherCoord[1]);
    
    let isValid = false;
    let connectionReason = '';
    let weight = distance;

    // PRIORITY RULE 1: Connection nodes get highest priority
    if (currentType === 'connection' || otherType === 'connection') {
      if (distance <= MAX_CROSS_POLYGON_DISTANCE ) { // Larger range for connections
        isValid = true;
        connectionReason = 'connection-priority';
        weight = distance * 1; // Strong preference for connections
      }
    }
    // PRIORITY RULE 2: Both nodes are inside the SAME polygon
    else if (currentPolygon && otherPolygon && currentPolygon === otherPolygon) {
      isValid = true;
      connectionReason = 'same-polygon';
      weight = distance;
    }
    // RULE 3: Cross-polygon connections through connection nodes
    else if (distance <= MAX_CROSS_POLYGON_DISTANCE) {
      // Check if line crosses any empty polygons (forbidden)
      if (crossesEmptyPolygons(currentCoord, otherCoord, navigablePolygons, nodes, doorSegments, toleranceMeters)) {
        isValid = false;
        connectionReason = 'blocked-by-empty-polygon';
      }
      // If different polygons, prefer connection nodes as intermediate
      else if (currentPolygon && otherPolygon && currentPolygon !== otherPolygon) {
        // Look for connection nodes between these polygons
        const connectionNodes = findConnectionNodesBetweenPolygons(nodes, currentPolygon, otherPolygon);
        
        if (connectionNodes.length > 0) {
          // If there are connection nodes available, penalize direct cross-polygon connections
          isValid = true;
          connectionReason = 'cross-polygon-penalized';
          weight = distance * 2.0; // Heavy penalty to discourage direct crossing
        } else {
          // No connection nodes available, allow direct connection
          isValid = true;
          connectionReason = 'cross-polygon-80m';
          weight = distance * 1.2;
        }
      }
      // If polygons are adjacent, prefer closest door pairs
      else if (currentPolygon && otherPolygon && arePolygonsAdjacent(currentPolygon, otherPolygon)) {
        const closestPairs = findClosestDoorsBetweenPolygons(nodes, currentPolygon, otherPolygon);
        const isPreferredPair = closestPairs.some(pair => 
          (pair.from === nodeIndex && pair.to === i) || 
          (pair.from === i && pair.to === nodeIndex)
        );
        
        if (isPreferredPair) {
          isValid = true;
          connectionReason = 'closest-door-between-polygons';
          weight = distance * 0.8; // Slight preference for closest doors
        } else {
          isValid = true;
          connectionReason = 'cross-polygon-80m';
          weight = distance * 1.2;
        }
      }
      else {
        // Regular cross-polygon connection or outside polygons
        isValid = true;
        connectionReason = 'cross-polygon-80m';
        weight = distance;
      }
    }
    // RULE 4: Check if direct line is not obstructed (backup rule)
    else if (!isLineObstructed(currentCoord, otherCoord, navigablePolygons, doorSegments, toleranceMeters) &&
             !crossesEmptyPolygons(currentCoord, otherCoord, navigablePolygons, nodes, doorSegments, toleranceMeters)) {
      // Long distance but unobstructed - only within same polygon
      if (currentPolygon && otherPolygon && currentPolygon === otherPolygon) {
        isValid = true;
        connectionReason = 'unobstructed-same-polygon';
        weight = distance;
      }
    }

    if (isValid) {
      neighbors.push({
        index: i,
        weight: weight,
        reason: connectionReason
      });
    }
  }

  return neighbors;
}

// Dijkstra's algorithm for finding shortest path
function dijkstraShortestPath(nodes, startNodeIndex, endNodeIndex, navigablePolygons, doorSegments = [], toleranceMeters = 0) {
  console.log(`Starting Enhanced Dijkstra with Connection Priority from node ${startNodeIndex} to ${endNodeIndex}`);
  console.log(`Start node: [${nodes[startNodeIndex][0]}, ${nodes[startNodeIndex][1]}] - ${nodes[startNodeIndex][2]?.name || nodes[startNodeIndex][2]?.nodeFunction}`);
  console.log(`End node: [${nodes[endNodeIndex][0]}, ${nodes[endNodeIndex][1]}] - ${nodes[endNodeIndex][2]?.name || nodes[endNodeIndex][2]?.nodeFunction}`);
  
  // Debug: Check start node connections
  const startCoord = [nodes[startNodeIndex][0], nodes[startNodeIndex][1]];
  const startPolygon = getPolygonContaining(startCoord, navigablePolygons);
  const startArea = startPolygon ? startPolygon.properties?.subGroupValue : 'outside';
  const startType = nodes[startNodeIndex][2]?.nodeFunction;
  console.log(`Start node: area=${startArea}, type=${startType}`);
  
  const startNeighbors = findValidNeighbors(startNodeIndex, nodes, navigablePolygons, doorSegments, toleranceMeters);
  console.log(`Start node has ${startNeighbors.length} neighbors with connection priority:`);
  
  // Sort neighbors by priority (connection nodes first)
  const sortedNeighbors = startNeighbors.sort((a, b) => {
    const aIsConnection = nodes[a.index][2]?.nodeFunction === 'connection';
    const bIsConnection = nodes[b.index][2]?.nodeFunction === 'connection';
    
    if (aIsConnection && !bIsConnection) return -1;
    if (!aIsConnection && bIsConnection) return 1;
    return a.weight - b.weight;
  });
  
  sortedNeighbors.slice(0, 10).forEach(n => {
    const neighborCoord = [nodes[n.index][0], nodes[n.index][1]];
    const neighborPolygon = getPolygonContaining(neighborCoord, navigablePolygons);
    const neighborArea = neighborPolygon ? neighborPolygon.properties?.subGroupValue : 'outside';
    const neighborType = nodes[n.index][2]?.nodeFunction;
    console.log(`  -> Node ${n.index} (${nodes[n.index][2]?.name || 'unnamed'}) area=${neighborArea}, type=${neighborType}, weight=${n.weight.toFixed(6)}, reason=${n.reason}`);
  });
  
  // Continue with standard Dijkstra
  const distances = {};
  const previous = {};
  const unvisited = new Set();

  // Initialize
  nodes.forEach((_, index) => {
    distances[index] = Infinity;
    previous[index] = null;
    unvisited.add(index);
  });
  distances[startNodeIndex] = 0;

  let iterations = 0;
  const maxIterations = nodes.length * 2;

  while (unvisited.size > 0 && iterations < maxIterations) {
    iterations++;
    
    // Find unvisited node with minimum distance
    let currentIndex = null;
    let minDist = Infinity;
    for (const index of unvisited) {
      if (distances[index] < minDist) {
        minDist = distances[index];
        currentIndex = index;
      }
    }

    if (currentIndex === null || minDist === Infinity) {
      console.log(`Dijkstra stopped: no more reachable nodes (iteration ${iterations})`);
      break;
    }

    if (currentIndex === endNodeIndex) {
      console.log(`Dijkstra reached destination in ${iterations} iterations`);
      break;
    }

    unvisited.delete(currentIndex);

    const neighbors = findValidNeighbors(currentIndex, nodes, navigablePolygons, doorSegments, toleranceMeters);

    for (const neighbor of neighbors) {
      if (!unvisited.has(neighbor.index)) continue;
      
      const alt = distances[currentIndex] + neighbor.weight;
      if (alt < distances[neighbor.index]) {
        distances[neighbor.index] = alt;
        previous[neighbor.index] = currentIndex;
        
        if (neighbor.index === endNodeIndex) {
          console.log(`Found path to destination! Distance: ${alt}, via ${neighbor.reason}`);
        }
      }
    }
  }

  console.log(`Final distance to destination: ${distances[endNodeIndex]}`);

  // Reconstruct path
  const path = [];
  let currentIndex = endNodeIndex;
  
  if (distances[endNodeIndex] === Infinity) {
    console.log('Destination is not reachable from start node');
    return [];
  }
  
  while (currentIndex !== null) {
    path.unshift(nodes[currentIndex]);
    currentIndex = previous[currentIndex];
  }

  console.log(`Enhanced Dijkstra found path with ${path.length} nodes`);
  
  // Log path details
  console.log('Path details:');
  path.forEach((node, index) => {
    const nodeType = node[2]?.nodeFunction;
    const nodeName = node[2]?.name || 'unnamed';
    console.log(`  ${index}: ${nodeName} (${nodeType})`);
  });
  
  return path.length > 0 && path[0] === nodes[startNodeIndex] ? path : [];
}

// A* algorithm using Euclidean heuristic
function aStarShortestPath(nodes, startNodeIndex, endNodeIndex, navigablePolygons, doorSegments = [], toleranceMeters = 0) {
  const heuristic = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1]);

  const openSet = new Set([startNodeIndex]);
  const cameFrom = {};
  const gScore = {};
  const fScore = {};

  nodes.forEach((_, idx) => {
    gScore[idx] = Infinity;
    fScore[idx] = Infinity;
  });
  gScore[startNodeIndex] = 0;
  fScore[startNodeIndex] = heuristic(nodes[startNodeIndex], nodes[endNodeIndex]);

  while (openSet.size > 0) {
    let current = null;
    let minF = Infinity;
    for (const idx of openSet) {
      if (fScore[idx] < minF) {
        minF = fScore[idx];
        current = idx;
      }
    }

    if (current === endNodeIndex) {
      const path = [];
      let ci = current;
      while (ci !== undefined) {
        path.unshift(nodes[ci]);
        ci = cameFrom[ci];
      }
      return path;
    }

    openSet.delete(current);

    const neighbors = findValidNeighbors(current, nodes, navigablePolygons, doorSegments, toleranceMeters);
    for (const neighbor of neighbors) {
      const tentativeG = gScore[current] + neighbor.weight;
      if (tentativeG < gScore[neighbor.index]) {
        cameFrom[neighbor.index] = current;
        gScore[neighbor.index] = tentativeG;
        fScore[neighbor.index] = tentativeG + heuristic(nodes[neighbor.index], nodes[endNodeIndex]);
        openSet.add(neighbor.index);
      }
    }
  }

  return [];
}

function angleBetween(p1, p2, p3) {
  const a1 = Math.atan2(p2[0] - p1[0], p2[1] - p1[1]);
  const a2 = Math.atan2(p3[0] - p2[0], p3[1] - p2[1]);
  let deg = ((a2 - a1) * 180) / Math.PI;
  if (deg > 180) deg -= 360;
  if (deg < -180) deg += 360;
  return Math.round(deg);
}

export function analyzeRoute(origin, destination, geoData, transportMode = 'walking', gender = 'male') {
  console.log('analyzeRoute called with Connection Priority Logic');

  const serviceKeyMap = {
    'electric-car': ['electric-car', 'electricCar', 'electricVan']
  };

  const serviceAllowed = (services, mode) => {
    if (!services) return true;
    const keys = serviceKeyMap[mode] || [mode];
    for (const key of keys) {
      if (Object.prototype.hasOwnProperty.call(services, key)) {
        return services[key] !== false;
      }
    }
    return true;
  };

  if (!geoData) {
    return null;
  }
  
  const doorPoints = geoData.features.filter(
    f =>
      f.geometry.type === 'Point' &&
      f.properties?.nodeFunction === 'door' &&
      serviceAllowed(f.properties?.services, transportMode) &&
      genderAllowed(f.properties?.gender, gender)
  );
  const doorLines = geoData.features.filter(
    f =>
      (f.geometry.type === 'LineString' || f.geometry.type === 'MultiLineString') &&
      f.properties?.nodeFunction === 'door' &&
      serviceAllowed(f.properties?.services, transportMode) &&
      genderAllowed(f.properties?.gender, gender)
  );
  const connections = geoData.features.filter(
    f =>
      f.geometry.type === 'Point' &&
      f.properties?.nodeFunction === 'connection' &&
      serviceAllowed(f.properties?.services, transportMode) &&
      genderAllowed(f.properties?.gender, gender)
  );
  const pois = geoData.features.filter(
    f =>
      f.geometry.type === 'Point' &&
      f.properties?.nodeFunction === 'poi'
  );
  // Collect all polygon features. Any polygon that contains at least
  // one door or connection node is considered traversable.
  const allPolygons = geoData.features.filter(f => f.geometry.type === 'Polygon');

  const doorLineNodes = buildDoorLineNodes(doorLines);
  const doorLineSegments = getDoorLineSegments(doorLines);

  // Create a unified list of all navigation nodes
  const allNodes = [];

  // Add all doors
  doorPoints.forEach(door => {
    const [lng, lat] = door.geometry.coordinates;
    allNodes.push([lat, lng, door.properties, 0, door]);
  });
  doorLineNodes.forEach(node => {
    allNodes.push(node);
  });

  // Add all connections
  connections.forEach(conn => {
    const [lng, lat] = conn.geometry.coordinates;
    allNodes.push([lat, lng, conn.properties, 0, conn]);
  });

  // Polygons are traversable if they contain at least one accessible door or
  // connection node. Polygons named "ورودی مسقف" are always allowed.
  const coveredName = getCoveredEntryName();
  const navigablePolygons = allPolygons.filter(poly => {
    if (poly.properties?.name === coveredName) return true;

    const nodesInPoly = allNodes.filter(node =>
      pointInPolygon([node[1], node[0]], poly)
    );

    return nodesInPoly.some(n =>
      n[2]?.nodeFunction === 'door' || n[2]?.nodeFunction === 'connection'
    );
  });

  function findNearestNodeForCoordinate(coord, toleranceMeters = GEOJSON_ROUTE_NODE_SNAP_METERS) {
    let match = null;
    let minDistance = Infinity;
    for (let i = 0; i < allNodes.length; i++) {
      const node = allNodes[i];
      const nodeCoord = [node[0], node[1]];
      const dist = haversineDistanceMeters(coord, nodeCoord);
      if (dist < minDistance) {
        minDistance = dist;
        match = { node, index: i, distance: dist };
      }
    }
    if (!match || match.distance > toleranceMeters) {
      return null;
    }
    return match;
  }

  function tryFindPredefinedRoutes() {
    const routeFeatures = geoData.features.filter(f => {
      const type = f.geometry?.type;
      return type === 'LineString' || type === 'MultiLineString';
    });

    const candidates = [];

    for (const feature of routeFeatures) {
      if (!serviceAllowed(feature.properties?.services, transportMode)) continue;
      if (!genderAllowed(feature.properties?.gender, gender)) continue;
      if (!isTransportModeAllowed(feature.properties, transportMode)) continue;

      const coords = extractRouteCoordinates(feature.geometry);
      if (coords.length < 2) continue;

      const forwardStart = haversineDistanceMeters(origin.coordinates, coords[0]);
      const forwardEnd = haversineDistanceMeters(destination.coordinates, coords[coords.length - 1]);
      const reverseStart = haversineDistanceMeters(origin.coordinates, coords[coords.length - 1]);
      const reverseEnd = haversineDistanceMeters(destination.coordinates, coords[0]);

      let orderedCoords = null;
      if (forwardStart <= GEOJSON_ROUTE_MATCH_TOLERANCE_METERS && forwardEnd <= GEOJSON_ROUTE_MATCH_TOLERANCE_METERS) {
        orderedCoords = coords.slice();
      } else if (reverseStart <= GEOJSON_ROUTE_MATCH_TOLERANCE_METERS && reverseEnd <= GEOJSON_ROUTE_MATCH_TOLERANCE_METERS) {
        orderedCoords = coords.slice().reverse();
      }

      if (!orderedCoords) continue;

      const pathCoords = orderedCoords.map(coord => coord.slice());
      pathCoords[0] = [...origin.coordinates];
      pathCoords[pathCoords.length - 1] = [...destination.coordinates];

      const steps = [];
      const usedNodes = new Set();

      for (let i = 0; i < pathCoords.length - 1; i++) {
        const match = findNearestNodeForCoordinate(pathCoords[i]);
        if (!match) continue;
        if (usedNodes.has(match.index)) continue;
        usedNodes.add(match.index);

        const node = match.node;
        const props = node[2] || {};
        if (props.nodeFunction === 'door') {
          steps.push({
            coordinates: [node[0], node[1]],
            type: 'stepPassDoor',
            name: props.name || '',
            services: props.services || {}
          });
        } else if (props.nodeFunction === 'connection') {
          steps.push({
            coordinates: [node[0], node[1]],
            type: 'stepPassConnection',
            title: props.subGroup || props.name || '',
            services: props.services || {}
          });
        }
      }

      steps.push({
        coordinates: destination.coordinates,
        type: 'stepArriveDestination',
        name: destination.name || ''
      });

      attachLandmarks(pathCoords, steps, pois);

      const geo = {
        type: 'Feature',
        geometry: {
          type: 'LineString',
          coordinates: pathCoords.map(p => [p[1], p[0]])
        }
      };

      const { distanceMeters, estimatedMinutes } = computeRouteMetrics(pathCoords, feature.properties || {});
      const sahns = extractSahnSequence(pathCoords, navigablePolygons);

      candidates.push({
        route: {
          path: pathCoords,
          geo,
          steps,
          sahns,
          estimatedMinutes,
          distanceMeters,
          source: 'predefined'
        },
        estimatedMinutes: estimatedMinutes ?? Infinity,
        distanceMeters
      });
    }

    if (candidates.length === 0) {
      return [];
    }

    candidates.sort((a, b) => {
      if (a.estimatedMinutes !== b.estimatedMinutes) {
        return a.estimatedMinutes - b.estimatedMinutes;
      }
      return (a.distanceMeters || Infinity) - (b.distanceMeters || Infinity);
    });

    const routes = candidates.map(c => c.route);
    console.log(
      'Using predefined geojson routes with distances',
      routes.map(r => r.distanceMeters || 0)
    );
    return routes;
  }

  console.log(
    `Found ${doorPoints.length} point doors, ${doorLines.length} line doors, ${connections.length} connections, ${navigablePolygons.length} navigable polygons`
  );

  console.log(`Total nodes available: ${allNodes.length}`);

  // Analyze polygons and their nodes using turf.js
  console.log('Polygon analysis with turf.js:');
  const emptyPolygons = [];
  navigablePolygons.forEach(poly => {
    const nodesInPoly = getNodesInPolygon(allNodes, poly);
    console.log(`  ${poly.properties?.subGroupValue}: ${nodesInPoly.length} nodes`);
    if (nodesInPoly.length === 0) {
      emptyPolygons.push(poly);
    }
  });
  
  console.log(`Found ${emptyPolygons.length} empty polygons:`);
  emptyPolygons.forEach(poly => {
    console.log(`  - ${poly.properties?.subGroupValue}`);
  });

  // Find unobstructed entry points
  function findUnobstructedEntries(coord, count = 2) {
    const validNodes = [];

    for (let i = 0; i < allNodes.length; i++) {
      const node = allNodes[i];
      const nodeCoord = [node[0], node[1]];

      if (
        !isLineObstructed(coord, nodeCoord, navigablePolygons, doorLineSegments, DOOR_BOUNDARY_TOLERANCE_METERS) &&
        !crossesEmptyPolygons(
          coord,
          nodeCoord,
          navigablePolygons,
          allNodes,
          doorLineSegments,
          DOOR_BOUNDARY_TOLERANCE_METERS
        )
      ) {
        const distance = Math.hypot(coord[0] - nodeCoord[0], coord[1] - nodeCoord[1]);
        const poly = getPolygonContaining(nodeCoord, navigablePolygons);
        const polyId = poly ? poly.properties?.subGroupValue : 'none';
        validNodes.push({
          index: i,
          node: node,
          distance: distance,
          polyId
        });
      }
    }

    validNodes.sort((a, b) => a.distance - b.distance);
    console.log(`Found ${validNodes.length} unobstructed nodes from [${coord[0]}, ${coord[1]}]`);

    const unique = [];
    const seen = new Set();
    for (const v of validNodes) {
      if (!seen.has(v.polyId)) {
        unique.push(v);
        seen.add(v.polyId);
      }
      if (unique.length >= count) break;
    }

    if (unique.length < count) {
      for (const v of validNodes) {
        if (!unique.includes(v)) {
          unique.push(v);
        }
        if (unique.length >= count) break;
      }
    }

    return unique.slice(0, count);
  }

  // Find entry and exit points
  const startEntries = findUnobstructedEntries(origin.coordinates, 3);
  const endEntries = findUnobstructedEntries(destination.coordinates, 3);

  const startEntry = startEntries[0];
  const endEntry = endEntries[0];

  console.log('Start entry:', startEntry ? `Node ${startEntry.index}` : 'None');
  console.log('End entry:', endEntry ? `Node ${endEntry.index}` : 'None');

  const predefinedRoutes = tryFindPredefinedRoutes();

  function formatAlternatives(routeList) {
    return routeList.map(route => {
      const via = route.steps
        .filter(st => st.type !== 'stepArriveDestination')
        .map(st => st.name || st.title)
        .filter(Boolean);
      const sahns = route.sahns || extractSahnSequence(route.path, navigablePolygons);
      return {
        steps: route.steps,
        geo: route.geo,
        from: origin.name || '',
        to: destination.name || '',
        via,
        sahns,
        estimatedMinutes: route.estimatedMinutes,
        distanceMeters: route.distanceMeters,
        source: route.source || 'predefined'
      };
    });
  }

  if (!startEntry || !endEntry) {
    console.log('Could not find valid entry/exit points');
    if (predefinedRoutes.length > 0) {
      const [primary, ...rest] = predefinedRoutes;
      const alternatives = formatAlternatives(rest);
      return {
        path: primary.path,
        geo: primary.geo,
        steps: primary.steps,
        sahns: primary.sahns || extractSahnSequence(primary.path, navigablePolygons),
        alternatives,
        estimatedMinutes: primary.estimatedMinutes,
        distanceMeters: primary.distanceMeters,
        source: primary.source || 'predefined'
      };
    }
    return null;
  }

  // Use connection-priority A* to find the shortest path between entry points
  const nodePath = aStarShortestPath(
    allNodes,
    startEntry.index,
    endEntry.index,
    navigablePolygons,
    doorLineSegments,
    DOOR_BOUNDARY_TOLERANCE_METERS
  );
  
  if (nodePath.length === 0 || nodePath.length === 1) {
    console.log('No valid path found between entry points');
    if (predefinedRoutes.length > 0) {
      const [primary, ...rest] = predefinedRoutes;
      const alternatives = formatAlternatives(rest);
      return {
        path: primary.path,
        geo: primary.geo,
        steps: primary.steps,
        sahns: primary.sahns || extractSahnSequence(primary.path, navigablePolygons),
        alternatives,
        estimatedMinutes: primary.estimatedMinutes,
        distanceMeters: primary.distanceMeters,
        source: primary.source || 'predefined'
      };
    }
    return null;
  }

  function buildRoute(nodeList) {
    const rPath = [origin.coordinates];
    const rSteps = [];

    nodeList.forEach(node => {
      const coord = [node[0], node[1]];
      const prev = rPath[rPath.length - 1];
      const seg = adjustSegmentInsidePolygon(
        prev,
        coord,
        navigablePolygons,
        doorLineSegments,
        DOOR_BOUNDARY_TOLERANCE_METERS
      );
      seg.forEach(p => rPath.push(p));

      if (node[2].nodeFunction === 'door') {
        rSteps.push({
          coordinates: coord,
          type: 'stepPassDoor',
          name: node[2].name || '',
          services: node[2].services || {}
        });
      } else if (node[2].nodeFunction === 'connection') {
        rSteps.push({
          coordinates: coord,
          type: 'stepPassConnection',
          title: node[2].subGroup || node[2].name || '',
          services: node[2].services || {}
        });
      }
    });

    const last = rPath[rPath.length - 1];
    const destSeg = adjustSegmentInsidePolygon(
      last,
      destination.coordinates,
      navigablePolygons,
      doorLineSegments,
      DOOR_BOUNDARY_TOLERANCE_METERS
    );
    destSeg.forEach(p => rPath.push(p));
    rSteps.push({
      coordinates: destination.coordinates,
      type: 'stepArriveDestination',
      name: destination.name || ''
    });

    const rGeo = {
      type: 'Feature',
      geometry: { type: 'LineString', coordinates: rPath.map(p => [p[1], p[0]]) }
    };

    attachLandmarks(rPath, rSteps, pois);

    const metrics = computeRouteMetrics(rPath, null);

    return {
      path: rPath,
      steps: rSteps,
      geo: rGeo,
      distanceMeters: metrics.distanceMeters,
      estimatedMinutes: metrics.estimatedMinutes,
      source: 'computed'
    };
  }

  const computedRoute = buildRoute(nodePath);

  let mainRoute = predefinedRoutes[0] || computedRoute;
  if (!mainRoute) {
    return null;
  }

  const mainSahnSet = new Set();
  mainRoute.path.forEach(coord => {
    const poly = getPolygonContaining(coord, navigablePolygons);
    if (poly) {
      mainSahnSet.add(poly.properties?.subGroupValue);
    }
  });
  const mainSahns = extractSahnSequence(mainRoute.path, navigablePolygons);

  if (mainRoute.estimatedMinutes == null || mainRoute.distanceMeters == null) {
    const mainMetrics = computeRouteMetrics(mainRoute.path, null);
    if (mainRoute.distanceMeters == null) {
      mainRoute.distanceMeters = mainMetrics.distanceMeters;
    }
    if (mainRoute.estimatedMinutes == null) {
      mainRoute.estimatedMinutes = mainMetrics.estimatedMinutes;
    }
  }

  const coordinateKey = coord => `${coord[0].toFixed(6)}:${coord[1].toFixed(6)}`;
  const mainNodeKeys = new Set(mainRoute.path.map(coordinateKey));

  const altStartEntries = startEntries.slice(0);
  const altEndEntries = endEntries.slice(0);

  const altCandidates = [];
  if (predefinedRoutes.length > 1) {
    altCandidates.push(...predefinedRoutes.slice(1));
  }

  function collectCandidates(requireDifferentSahn = true) {
    altStartEntries.forEach(s => {
      altEndEntries.forEach(e => {
        if (predefinedRoutes.length === 0 && s.index === startEntry.index && e.index === endEntry.index) return;
        const altNodePath = aStarShortestPath(
          allNodes,
          s.index,
          e.index,
          navigablePolygons,
          doorLineSegments,
          DOOR_BOUNDARY_TOLERANCE_METERS
        );
        if (altNodePath.length === 0 || altNodePath.length === 1) return;
        const route = buildRoute(altNodePath);

        // Skip candidate if it doesn't pass through a different sahn when required
        if (requireDifferentSahn && navigablePolygons.length > 0) {
          const altSahnSet = new Set();
          route.path.forEach(c => {
            const p = getPolygonContaining(c, navigablePolygons);
            if (p) {
              altSahnSet.add(p.properties?.subGroupValue);
            }
          });
          const hasDifferentSahn = [...altSahnSet].some(p => !mainSahnSet.has(p));
          if (!hasDifferentSahn) return;
        }

        const isSame = (coords1, coords2) => {
          if (coords1.length !== coords2.length) return false;
          for (let i = 0; i < coords1.length; i++) {
            if (
              Math.abs(coords1[i][0] - coords2[i][0]) > 1e-6 ||
              Math.abs(coords1[i][1] - coords2[i][1]) > 1e-6
            ) {
              return false;
            }
          }
          return true;
        };

        if (isSame(route.geo.geometry.coordinates, mainRoute.geo.geometry.coordinates)) return;
        if (altCandidates.some(r => isSame(r.geo.geometry.coordinates, route.geo.geometry.coordinates))) return;

        const uniqueAltNodes = new Set();
        route.path.forEach(c => {
          const key = coordinateKey(c);
          if (!mainNodeKeys.has(key)) {
            uniqueAltNodes.add(key);
          }
        });
        if (uniqueAltNodes.size < 4) return;

        altCandidates.push(route);
      });
    });
  }

  if (altCandidates.length === 0) {
    // First attempt: enforce different sahn to encourage diverse routes
    collectCandidates(true);

    // Fallback: if no alternatives found, relax the sahn restriction
    if (altCandidates.length === 0) {
      collectCandidates(false);
    }
  }

  altCandidates.sort((a, b) => {
    const aMinutes = a.estimatedMinutes ?? Infinity;
    const bMinutes = b.estimatedMinutes ?? Infinity;
    if (aMinutes !== bMinutes) {
      return aMinutes - bMinutes;
    }
    const aDistance = a.distanceMeters ?? Infinity;
    const bDistance = b.distanceMeters ?? Infinity;
    return aDistance - bDistance;
  });

  const alternatives = formatAlternatives(altCandidates);

  console.log(`Final path has ${mainRoute.path.length} points`);

  return {
    path: mainRoute.path,
    geo: mainRoute.geo,
    steps: mainRoute.steps,
    sahns: mainSahns,
    alternatives,
    estimatedMinutes: mainRoute.estimatedMinutes,
    distanceMeters: mainRoute.distanceMeters,
    source: mainRoute.source || 'computed'
  };
}

export function computeShortestPath(origin, destination, features) {
  const path = [origin.coordinates];
  features.forEach(f => {
    const [lng, lat] = f.geometry.coordinates;
    path.push([lat, lng]);
  });
  path.push(destination.coordinates);
  const geo = {
    type: 'Feature',
    geometry: { type: 'LineString', coordinates: path.map(p => [p[1], p[0]]) }
  };
  return { path, geo };
}
