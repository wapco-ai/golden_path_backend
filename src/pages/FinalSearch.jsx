// src/pages/FinalSearch.jsx
import React, { useState, useEffect, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { FormattedMessage, useIntl } from 'react-intl';
import Map, { Marker, Source, Layer, Popup } from 'react-map-gl';
import GeoJsonOverlay from '../components/map/GeoJsonOverlay';
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import useOfflineMapStyle from '../hooks/useOfflineMapStyle';
import '../styles/FinalSearch.css';
import ModeSelector from '../components/common/ModeSelector';
import { useRouteStore } from '../store/routeStore';
import { useLangStore } from '../store/langStore';
import { loadGeoJsonData } from '../utils/loadGeoJsonData.js';
import { analyzeRoute } from '../utils/routeAnalysis';
import useLocaleDigits from '../utils/useLocaleDigits';
import { toast } from 'react-toastify';

const FinalSearch = () => {
  const [isSwapping, setIsSwapping] = useState(false);
  const [isSwapButton, setSwapButton] = useState(true);
  const mapRef = useRef(null);
  const navigate = useNavigate();
  const intl = useIntl();
  const formatDigits = useLocaleDigits();
  const { mapStyle, handleMapError } = useOfflineMapStyle();
  const {
    origin: storedOrigin,
    destination: storedDestination,
    routeGeo: storedRouteGeo,
    routeSteps: storedRouteSteps,
    alternativeRoutes: storedAlternativeRoutes,
    // sahn sequence for the current route isn't stored in the store,
    // so fetch it from session below if available
    gender: storedGender,
    setOrigin: storeSetOrigin,
    setDestination: storeSetDestination,
    setRouteGeo: storeSetRouteGeo,
    setRouteSteps: storeSetRouteSteps,
    setAlternativeRoutes: storeSetAlternativeRoutes,
    setGender: storeSetGender
  } = useRouteStore();
  const language = useLangStore((state) => state.language);
  const qrLat = sessionStorage.getItem('qrLat');
  const qrLng = sessionStorage.getItem('qrLng');
  const storedRouteSahns = sessionStorage.getItem('routeSahns')
    ? JSON.parse(sessionStorage.getItem('routeSahns'))
    : [];
  const [origin, setOrigin] = useState(
    storedOrigin ||
    (qrLat && qrLng
      ? {
        name: intl.formatMessage({ id: 'mapCurrentLocationName' }),
        coordinates: [parseFloat(qrLat), parseFloat(qrLng)]
      }
      : {
        name: intl.formatMessage({ id: 'defaultBabRezaName' }),
        coordinates: [36.2970, 59.6069]
      })
  );
  const [destination, setDestination] = useState(
    storedDestination || {
      name: intl.formatMessage({ id: 'destSahnEnqelabName' }),
      coordinates: [36.2975, 59.6072]
    }
  );
  const routeGeo = storedRouteGeo;
  useEffect(() => {
    storeSetOrigin(origin);
  }, [origin, storeSetOrigin]);
  useEffect(() => {
    storeSetDestination(destination);
  }, [destination, storeSetDestination]);
  const { transportMode } = useRouteStore();
  const [selectedGender, setSelectedGender] = useState(storedGender || 'family');
  const [routeInfo, setRouteInfo] = useState({
    time: '9',
    distance: '75',
    mode: transportMode
  });
  const [popupCoord, setPopupCoord] = useState(null);
  const [popupMinutes, setPopupMinutes] = useState(null);
  const [altPopupCoords, setAltPopupCoords] = useState([]);
  const [altPopupMinutes, setAltPopupMinutes] = useState([]);

  const [menuOpen, setMenuOpen] = useState(false);
  const [geoData, setGeoData] = useState(null);

  useEffect(() => {
    storeSetGender(selectedGender);
  }, [selectedGender, storeSetGender]);

  React.useEffect(() => {
    // Scroll to top when component mounts
    window.scrollTo(0, 0);
  }, []);

  React.useEffect(() => {
    let info;
    if (routeGeo) {
      const coords = routeGeo.geometry?.coordinates || [];
      const dist = coords.slice(1).reduce((acc, c, i) => {
        const prev = coords[i];
        return acc + Math.hypot(c[0] - prev[0], c[1] - prev[1]) * 100000;
      }, 0);
      info = {
        time: `${Math.max(1, Math.round(dist / 60))}`,
        distance: `${Math.round(dist)}`,
        mode: transportMode
      };
    } else if (transportMode === 'walking') {
      info = { time: '0', distance: '0', mode: 'walking' };
    } else if (transportMode === 'electric-car') {
      info = { time: '0', distance: '0', mode: 'electric-car' };
    } else if (transportMode === 'wheelchair') {
      info = { time: '0', distance: '0', mode: 'wheelchair' };
    }

    if (info) {
      setRouteInfo(info);
      try {
        sessionStorage.setItem('routeSummaryData', JSON.stringify(info));
      } catch (err) {
        console.warn('failed to persist route summary', err);
      }
    }
  }, [transportMode, routeGeo]);

  // Fallback to current GPS coordinates if origin coords not provided and no QR data
  useEffect(() => {
    if (!origin.coordinates && !(qrLat && qrLng)) {
      navigator.geolocation.getCurrentPosition(
        (pos) =>
          setOrigin((o) => ({
            ...o,
            coordinates: [pos.coords.latitude, pos.coords.longitude]
          })),
        (err) => console.error('gps error', err)
      )
    }
  }, [origin.coordinates, qrLat, qrLng])

  useEffect(() => {
    const checkForUpdates = () => {
      const updatedOrigin = sessionStorage.getItem('updatedOrigin');
      const updatedDestination = sessionStorage.getItem('updatedDestination');

      if (updatedOrigin) {
        setOrigin(JSON.parse(updatedOrigin));
        sessionStorage.removeItem('updatedOrigin');
      }

      if (updatedDestination) {
        setDestination(JSON.parse(updatedDestination));
        sessionStorage.removeItem('updatedDestination');
      }
    };

    checkForUpdates();
  }, []);

  const handleOriginClick = () => {
    sessionStorage.setItem('returnToFinalSearch', 'true');
    sessionStorage.setItem('activeInput', 'origin');
    sessionStorage.setItem('currentOrigin', JSON.stringify(origin));
    sessionStorage.setItem('currentDestination', JSON.stringify(destination));
    navigate('/mpr', {
      state: {
        showOriginModal: true,
        fromFinalSearch: true  // Add this flag
      }
    });
  };

  const handleDestinationClick = () => {
    sessionStorage.setItem('returnToFinalSearch', 'true');
    sessionStorage.setItem('activeInput', 'destination');
    sessionStorage.setItem('currentOrigin', JSON.stringify(origin));
    navigate('/mpr', {
      state: {
        showDestinationModal: true,
        fromFinalSearch: true  // Add this flag
      }
    });
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
    if (!destination.coordinates) {
      // fallback to a default location if none provided
      setDestination((d) => ({
        ...d,
        coordinates: [36.2975, 59.6072]
      }));
    }
  }, [destination.coordinates]);

  useEffect(() => {
    if (!geoData) return;
    const result = analyzeRoute(origin, destination, geoData, transportMode, selectedGender);
    if (!result) {
      toast.error(intl.formatMessage({ id: 'noRouteFound' }));
      storeSetRouteGeo(null);
      storeSetRouteSteps([]);
      storeSetAlternativeRoutes([]);
      sessionStorage.removeItem('routeGeo');
      sessionStorage.removeItem('routeSteps');
      sessionStorage.removeItem('alternativeRoutes');
      sessionStorage.removeItem('routeSahns');
      return;
    }
    const { geo, steps, alternatives, sahns } = result;
    console.log('analyzeRoute result:', {
      geo,
      steps,
      alternatives
    });
    storeSetRouteGeo(geo);
    storeSetRouteSteps(steps);
    storeSetAlternativeRoutes(alternatives);
    sessionStorage.setItem('routeGeo', JSON.stringify(geo));
    sessionStorage.setItem('routeSteps', JSON.stringify(steps));
    sessionStorage.setItem('alternativeRoutes', JSON.stringify(alternatives));
    sessionStorage.setItem('routeSahns', JSON.stringify(sahns));
    sessionStorage.setItem('origin', JSON.stringify(origin));
    sessionStorage.setItem('destination', JSON.stringify(destination));
  }, [geoData, origin, destination, transportMode, selectedGender, storeSetRouteGeo, storeSetRouteSteps, storeSetAlternativeRoutes, intl]);

  const alternativeSummaries = React.useMemo(() => {
    if (!storedAlternativeRoutes) return [];
    return storedAlternativeRoutes.map((alt, idx) => {
      const coords = alt.geo?.geometry?.coordinates || [];
      const dist = coords.slice(1).reduce((acc, c, i) => {
        const prev = coords[i];
        return acc + Math.hypot(c[0] - prev[0], c[1] - prev[1]) * 100000;
      }, 0);
      return {
        id: idx + 1,
        from: alt.from,
        to: alt.to,
        via: alt.sahns || [],
        totalTime: `${Math.max(1, Math.round(dist / 60))} ${intl.formatMessage({ id: 'minutesUnit' })}`,
        totalDistance: `${Math.round(dist)} ${intl.formatMessage({ id: 'meters' })}`
      };
    });
  }, [storedAlternativeRoutes, intl]);

  const altLayerIds = React.useMemo(
    () =>
      (storedAlternativeRoutes || []).flatMap((_, idx) => [
        `alt-route-line-${idx}`,
        `alt-route-border-${idx}`
      ]),
    [storedAlternativeRoutes]
  );

  // Zoom map to route bounds when a new route is loaded
  useEffect(() => {
    if (mapRef.current && routeGeo) {
      const coords = routeGeo.geometry?.coordinates || [];
      if (coords.length > 0) {
        const bounds = new maplibregl.LngLatBounds(
          [coords[0][0], coords[0][1]],
          [coords[0][0], coords[0][1]]
        );
        coords.forEach(([lng, lat]) => bounds.extend([lng, lat]));
        mapRef.current.fitBounds(bounds, { padding: 80, duration: 700 });
      }
    }
  }, [routeGeo]);

  // Clear popup information when no route is available
  useEffect(() => {
    if (!routeGeo || !(routeGeo.geometry?.coordinates?.length > 0)) {
      setPopupCoord(null);
      setPopupMinutes(null);
    }
  }, [routeGeo]);

  // Determine popup location and total minutes for main route
  useEffect(() => {
    if (!routeGeo) return;
    const coords = routeGeo.geometry?.coordinates || [];
    if (coords.length === 0) return;

    const dist = coords.slice(1).reduce((acc, c, i) => {
      const prev = coords[i];
      return acc + Math.hypot(c[0] - prev[0], c[1] - prev[1]) * 100000;
    }, 0);
    setPopupMinutes(Math.max(1, Math.round(dist / 60)));

    let chosen = null;
    for (let i = 0; i < coords.length; i++) {
      const [lng, lat] = coords[i];
      const conflict = (storedAlternativeRoutes || []).some((alt) =>
        alt.geo.geometry.coordinates.some(
          ([alng, alat]) =>
            Math.abs(alng - lng) < 1e-6 && Math.abs(alat - lat) < 1e-6
        )
      );
      if (!conflict) {
        chosen = [lat, lng];
        break;
      }
    }
    if (!chosen) {
      const mid = Math.floor(coords.length / 2);
      chosen = [coords[mid][1], coords[mid][0]];
    }
    setPopupCoord(chosen);
  }, [routeGeo, storedAlternativeRoutes]);

  // Determine popup locations and minutes for alternative routes
  useEffect(() => {
    if (!storedAlternativeRoutes || storedAlternativeRoutes.length === 0 || !routeGeo) {
      setAltPopupCoords([]);
      setAltPopupMinutes([]);
      return;
    }

    const coordsArr = [];
    const minutesArr = [];

    storedAlternativeRoutes.forEach((alt) => {
      const coords = alt.geo?.geometry?.coordinates || [];
      if (coords.length === 0) {
        coordsArr.push(null);
        minutesArr.push(null);
        return;
      }

      const dist = coords.slice(1).reduce((acc, c, i) => {
        const prev = coords[i];
        return acc + Math.hypot(c[0] - prev[0], c[1] - prev[1]) * 100000;
      }, 0);
      minutesArr.push(Math.max(1, Math.round(dist / 60)));

      let chosen = null;
      for (let i = 0; i < coords.length; i++) {
        const [lng, lat] = coords[i];
        const conflict = routeGeo?.geometry?.coordinates?.some(
          ([mlng, mlat]) =>
            Math.abs(mlng - lng) < 1e-6 && Math.abs(mlat - lat) < 1e-6
        );
        if (!conflict) {
          chosen = [lat, lng];
          break;
        }
      }
      if (!chosen) {
        const mid = Math.floor(coords.length / 2);
        chosen = [coords[mid][1], coords[mid][0]];
      }
      coordsArr.push(chosen);
    });

    setAltPopupCoords(coordsArr);
    setAltPopupMinutes(minutesArr);
  }, [storedAlternativeRoutes, routeGeo]);

  const swapLocations = () => {
    setIsSwapping(true); // This will trigger the rotation
    setSwapButton(!isSwapButton);
    const newOrigin = destination;
    const newDestination = origin;
    setOrigin(newOrigin);
    setDestination(newDestination);
    storeSetOrigin(newOrigin);
    storeSetDestination(newDestination);
    sessionStorage.setItem('origin', JSON.stringify(newOrigin));
    sessionStorage.setItem('destination', JSON.stringify(newDestination));
    sessionStorage.setItem('qrLat', String(newOrigin.coordinates[0]));
    sessionStorage.setItem('qrLng', String(newOrigin.coordinates[1]));
    // Immediately rebuild the route so session data stays in sync
    if (geoData) {
      const result = analyzeRoute(
        newOrigin,
        newDestination,
        geoData,
        transportMode,
        selectedGender
      );
      if (result) {
        const { geo, steps, alternatives, sahns } = result;
        storeSetRouteGeo(geo);
        storeSetRouteSteps(steps);
        storeSetAlternativeRoutes(alternatives);
        sessionStorage.setItem('routeGeo', JSON.stringify(geo));
        sessionStorage.setItem('routeSteps', JSON.stringify(steps));
        sessionStorage.setItem('alternativeRoutes', JSON.stringify(alternatives));
        sessionStorage.setItem('routeSahns', JSON.stringify(sahns));
      }
    }
  };

  const handleSelectAlternativeRoute = (route) => {
    if (!route?.geo || !route?.steps) {
      console.warn('Selected alternative route is missing geo or steps');
      return;
    }

    const currentRoute = {
      geo: storedRouteGeo,
      steps: storedRouteSteps,
      from: storedOrigin?.name || origin.name,
      to: storedDestination?.name || destination.name,
      via: route.via || [],
      sahns: storedRouteSahns
    };
    const newAlternatives = storedAlternativeRoutes.filter(alt => alt !== route);

    if (currentRoute.geo && currentRoute.steps) {
      newAlternatives.push(currentRoute);
    }

    storeSetRouteGeo(route.geo);
    storeSetRouteSteps(route.steps);
    storeSetAlternativeRoutes(newAlternatives);
    sessionStorage.setItem('routeGeo', JSON.stringify(route.geo));
    sessionStorage.setItem('routeSteps', JSON.stringify(route.steps));
    sessionStorage.setItem('alternativeRoutes', JSON.stringify(newAlternatives));
    sessionStorage.setItem('routeSahns', JSON.stringify(route.sahns || []));
  };

  const getTransportIcon = () => {
    switch (transportMode) {
      case 'walking':
        return (
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#181717" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
            <path d="M13 4m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
            <path d="M7 21l3 -4" />
            <path d="M16 21l-2 -4l-3 -3l1 -6" />
            <path d="M6 12l2 -3l4 -1l3 3l3 1" />
          </svg>
        );
      case 'electric-car':
        return (
          <svg
            width="25"
            height="25"
            viewBox="0 0 25 25"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
          >
            <path
              fillRule="evenodd"
              clipRule="evenodd"
              d="M12.5 2.5C8.72876 2.5 6.84315 2.5 5.67157 3.67157C4.60848 4.73467 4.51004 6.3857 4.50093 9.5H3.5C2.94772 9.5 2.5 9.94772 2.5 10.5V11.5C2.5 11.8148 2.64819 12.1111 2.9 12.3L4.5 13.5C4.50911 16.6143 4.60848 18.2653 5.67157 19.3284C5.91375 19.5706 6.18645 19.7627 6.5 19.9151V21.4999C6.5 22.0522 6.94772 22.4999 7.5 22.4999H9C9.55228 22.4999 10 22.0522 10 21.4999V20.4815C10.7271 20.5 11.5542 20.5 12.5 20.5C13.4458 20.5 14.2729 20.5 15 20.4815V21.4999C15 22.0522 15.4477 22.4999 16 22.4999H17.5C18.0523 22.4999 18.5 22.0522 18.5 21.4999V19.9151C18.8136 19.7627 19.0862 19.5706 19.3284 19.3284C20.3915 18.2653 20.4909 16.6143 20.5 13.5L22.1 12.3C22.3518 12.1111 22.5 11.8148 22.5 11.5V10.5C22.5 9.94772 22.0523 9.5 21.5 9.5H20.4991C20.49 6.3857 20.3915 4.73467 19.3284 3.67157C18.1569 2.5 16.2712 2.5 12.5 2.5ZM6 10C6 11.4142 6 12.1213 6.43934 12.5607C6.87868 13 7.58579 13 9 13H12.5H16C17.4142 13 18.1213 13 18.5607 12.5607C19 12.1213 19 11.4142 19 10V7.5C19 6.08579 19 5.37868 18.5607 4.93934C18.1213 4.5 17.4142 4.5 16 4.5H12.5H9C7.58579 4.5 6.87868 4.5 6.43934 4.93934C6 5.37868 6 6.08579 6 7.5V10ZM6.75 16.5C6.75 16.0858 7.08579 15.75 7.5 15.75H9C9.41421 15.75 9.75 16.0858 9.75 16.5C9.75 16.9142 9.41421 17.25 9 17.25H7.5C7.08579 17.25 6.75 16.9142 6.75 16.5ZM18.25 16.5C18.25 16.0858 17.9142 15.75 17.5 15.75H16C15.5858 15.75 15.25 16.0858 15.25 16.5C15.25 16.9142 15.5858 17.25 16 17.25H17.5C17.9142 17.25 18.25 16.9142 18.25 16.5Z"
              fill="#1E2023"
            />
          </svg>

        );
      case 'wheelchair':
        return (
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#181717" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
            <path d="M11 5m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
            <path d="M11 7l0 8l4 0l4 5" />
            <path d="M11 11l5 0" />
            <path d="M7 11.5a5 5 0 1 0 6 7.5" />
          </svg>
        );
      default:
        return null;
    }
  };

  const handleNavigate = () => {
    navigate('/rng');
  };

  const handleRouteOverview = () => {
    navigate('/rop');
  };

  const handleSaveDestination = () => {
    setMenuOpen(false);
  };

  const handleShareRoute = () => {
    setMenuOpen(false);
    if (!origin.coordinates || !destination.coordinates) return;
    const originCoords = `${origin.coordinates[0]},${origin.coordinates[1]}`;
    const destCoords = `${destination.coordinates[0]},${destination.coordinates[1]}`;
    const travel = transportMode === 'electric-car' ? 'driving' : 'walking';
    const mapsUrl =
      `https://www.google.com/maps/dir/?api=1&origin=${originCoords}&destination=${destCoords}&travelmode=${travel}`;

    const shareData = {
      title: intl.formatMessage({ id: 'shareRoute' }),
      text: intl.formatMessage(
        { id: 'routeSummary' },
        { origin: origin.name, destination: destination.name }
      ),
      url: mapsUrl
    };

    if (navigator.share) {
      navigator.share(shareData).catch(err => console.error('share failed', err));
    } else {
      window.open(mapsUrl, '_blank');
    }
  };

  return (
    <div className="final-search-page" lang={language}>

      {/* Map Section */}
      <div className="mpr">
        <div className="header">
          <button className="back-btn" onClick={() => navigate(-1)}>
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path fillRule="evenodd" clipRule="evenodd" d="M11.2244 4.55806C11.4685 4.31398 11.8642 4.31398 12.1083 4.55806L17.1083 9.55806C17.3524 9.80214 17.3524 10.1979 17.1083 10.4419L12.1083 15.4419C11.8642 15.686 11.4685 15.686 11.2244 15.4419C10.9803 15.1979 10.9803 14.8021 11.2244 14.5581L15.1575 10.625H3.33301C2.98783 10.625 2.70801 10.3452 2.70801 10C2.70801 9.65482 2.98783 9.375 3.33301 9.375H15.1575L11.2244 5.44194C10.9803 5.19786 10.9803 4.80214 11.2244 4.55806Z" fill="#1E2023" />
            </svg>

          </button>

          <div className="menu-container">
            <button className={`menu-btn ${menuOpen ? 'active' : ''}`} onClick={() => setMenuOpen(!menuOpen)}>
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path d="M5 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                <path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                <path d="M19 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
              </svg>
            </button>

            <div className={`menu-dropdown ${menuOpen ? 'open' : ''}`}>
              <button className="menu-item" onClick={handleSaveDestination}>
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path d="M17.286 21.09q -1.69 .001 -5.288 -2.615q -3.596 2.617 -5.288 2.616q -2.726 0 -.495 -6.8q -9.389 -6.775 2.135 -6.775h.076q 1.785 -5.516 3.574 -5.516q 1.785 0 3.574 5.516h.076q 11.525 0 2.133 6.774q 2.23 6.802 -.497 6.8" />
                </svg>
                <FormattedMessage id="saveDestination" />
              </button>
              <button className="menu-item" onClick={handleShareRoute}>
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path d="M6 12m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" />
                  <path d="M18 6m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" />
                  <path d="M18 18m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" />
                  <path d="M8.7 10.7l6.6 -3.4" />
                  <path d="M8.7 13.3l6.6 3.4" />
                </svg>
                <FormattedMessage id="shareRoute" />
              </button>
            </div>
          </div>
        </div>
        <Map
          ref={mapRef}
          mapLib={maplibregl}
          mapStyle={mapStyle}
          style={{ width: '100%', height: '100%' }}
          initialViewState={{
            longitude:
              ((origin.coordinates?.[1] ?? 0) + (destination.coordinates?.[1] ?? 0)) /
              2,
            latitude:
              ((origin.coordinates?.[0] ?? 0) + (destination.coordinates?.[0] ?? 0)) /
              2,
            zoom: 18
          }}
          attributionControl={false}
          interactiveLayerIds={altLayerIds}
          onError={handleMapError}
          onClick={(e) => {
            const feature = e.features && e.features[0];
            if (
              feature &&
              feature.layer &&
              (feature.layer.id.startsWith('alt-route-line-') ||
                feature.layer.id.startsWith('alt-route-border-'))
            ) {
              const idx = parseInt(
                feature.layer.id.replace(/alt-route-(?:line|border)-/, '')
              );
              if (!Number.isNaN(idx) && storedAlternativeRoutes[idx]) {
                handleSelectAlternativeRoute(storedAlternativeRoutes[idx]);
              }
            }
          }}
        >
          {origin.coordinates && (
            <Marker
              longitude={origin.coordinates[1]}
              latitude={origin.coordinates[0]}
              anchor="bottom"
            >
              <div className="marker-circle"></div>
            </Marker>
          )}
          {destination.coordinates && (
            <Marker
              longitude={destination.coordinates[1]}
              latitude={destination.coordinates[0]}
              anchor="bottom"
            >
              <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="#F44336">
                <path d="M18.364 4.636a9 9 0 0 1 .203 12.519l-.203 .21l-4.243 4.242a3 3 0 0 1 -4.097 .135l-.144 -.135l-4.244 -4.243a9 9 0 0 1 12.728 -12.728zm-6.364 3.364a3 3 0 1 0 0 6a3 3 0 1 0 0 -6z" />
              </svg>
            </Marker>
          )}


          {storedAlternativeRoutes &&
            storedAlternativeRoutes.map((alt, idx) => (
              <React.Fragment key={idx}>
                <Source id={`alt-route-${idx}`} type="geojson" data={alt.geo}>
                  <Layer
                    id={`alt-route-border-${idx}`}
                    type="line"
                    paint={{ 'line-color': '#0f71ef', 'line-width': 10 }}
                    layout={{ 'line-cap': 'round', 'line-join': 'round' }}
                  />
                  <Layer
                    id={`alt-route-line-${idx}`}
                    type="line"
                    paint={{
                      'line-color': '#D5E4F6',
                      'line-width': 8,
                    }}
                    layout={{ 'line-cap': 'round', 'line-join': 'round' }}
                  />
                </Source>
                {altPopupCoords[idx] && altPopupMinutes[idx] !== null && (
                  <Popup
                    className="alt-popup-container"
                    longitude={altPopupCoords[idx][1]}
                    latitude={altPopupCoords[idx][0]}
                    closeButton={false}
                    closeOnClick={false}
                    anchor="bottom"
                  >
                    <div className="time-popup alt-popup">
                      {formatDigits(altPopupMinutes[idx])} {intl.formatMessage({ id: 'minutesUnit' })}
                    </div>
                  </Popup>
                )}
              </React.Fragment>
            ))}
          {routeGeo && (
            <Source id="main-route" type="geojson" data={routeGeo}>
              <Layer id="main-line" type="line" paint={{ 'line-color': '#0f71ef', 'line-width': 10 }} />
            </Source>
          )}
          {popupCoord && popupMinutes !== null && (
            <Popup
              className="main-popup-container"
              longitude={popupCoord[1]}
              latitude={popupCoord[0]}
              closeButton={false}
              closeOnClick={false}
              anchor="bottom"
            >
              <div className="time-popup main-popup">
                {formatDigits(popupMinutes)} {intl.formatMessage({ id: 'minutesUnit' })}
              </div>
            </Popup>
          )}
          <GeoJsonOverlay routeCoords={routeGeo?.geometry?.coordinates} />
        </Map>
        <div className="map-fade"></div>
      </div>

      {/* Location Inputs Section */}
      <div className="location-section-container">
        <div className="location-icons-container">
          <div className="location-icon origin-icon">
            <div className="n-circle"></div>
          </div>
          <div className="tdots">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="22"
              height="22"
              viewBox="0 0 24 24"
              fill="none"
              stroke="#e0e0e0"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
              className="icon icon-tabler icons-tabler-outline icon-tabler-dots-vertical"
            >
              <path stroke="none" d="M0 0h24v24H0z" fill="none" />
              <path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
              <path d="M12 19m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
              <path d="M12 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
            </svg>
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="22"
              height="22"
              viewBox="0 0 24 24"
              fill="none"
              stroke="#e0e0e0"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
              className="icon icon-tabler icons-tabler-outline icon-tabler-dots-vertical"
            >
              <path stroke="none" d="M0 0h24v24H0z" fill="none" />
              <path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
              <path d="M12 19m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
              <path d="M12 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
            </svg>
          </div>
          <div className="location-icon destination-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="#F44336">
              <path d="M18.364 4.636a9 9 0 0 1 .203 12.519l-.203 .21l-4.243 4.242a3 3 0 0 1 -4.097 .135l-.144 -.135l-4.244 -4.243a9 9 0 0 1 12.728 -12.728zm-6.364 3.364a3 3 0 1 0 0 6a3 3 0 1 0 0 -6z" />
            </svg>
          </div>
        </div>

        <div className="location-section">
          <div
            className="location-input origin-input"
            onClick={handleOriginClick} // Add this onClick handler
          >
            <div className="location-details">
              <div className="location-name">{origin.name}</div>
            </div>
            <div className={`current-location-label ${isSwapButton ? 'visible' : 'hidden'}`}>
              {/* <FormattedMessage id="mapCurrentLocationName" /> */}
            </div>
          </div>

          <div className="swap-container">
            <button className="swap-btn" onClick={swapLocations}>
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="23"
                height="23"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                className={`swap-icon ${isSwapButton ? '' : 'rotated'}`}
              >
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path d="M3 9l4 -4l4 4m-4 -4v14" />
                <path d="M21 15l-4 4l-4 -4m4 4v-14" />
              </svg>
            </button>
          </div>

          <div
            className="location-input destination-input"
            onClick={handleDestinationClick} // Add this onClick handler
          >
            <div className="location-details">
              <div className="location-name">{destination.name}</div>
            </div>
          </div>
        </div>
      </div>

      {/* Options Section */}
      <div className="options-section">
        <ModeSelector />

        <div className="options-divider"></div>

        <div className="options-row">
          <button
            className={`gender-btn ${selectedGender === 'family' ? 'active' : ''}`}
            onClick={() => setSelectedGender('family')}
          >
            <div className="gender-circle">
              {selectedGender === 'family' && <div className="gender-circle-fill"></div>}
            </div>
            <FormattedMessage id="routeForFamily" />
          </button>

          <button
            className={`gender-btn ${selectedGender === 'male' ? 'active' : ''}`}
            onClick={() => setSelectedGender('male')}
          >
            <div className="gender-circle">
              {selectedGender === 'male' && <div className="gender-circle-fill"></div>}
            </div>
            <FormattedMessage id="routeForMen" />
          </button>

          <button
            className={`gender-btn ${selectedGender === 'female' ? 'active' : ''}`}
            onClick={() => setSelectedGender('female')}
          >
            <div className="gender-circle">
              {selectedGender === 'female' && <div className="gender-circle-fill"></div>}
            </div>
            <FormattedMessage id="routeForWomen" />
          </button>

        </div>
      </div>

      {/* Route Info */}
      <div className="route-info">
        <div className="route-summary">
          <FormattedMessage
            id="routeSummary"
            values={{ origin: origin.name, destination: destination.name }}
          />
        </div>
        <div className="info-details">
          {/* <div className="info-time">
            {getTransportIcon()}
            <span>
              {formatDigits(routeInfo.time)} <FormattedMessage id="minutesUnit" />
            </span>
          </div> */}
          <div className="info-distance">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#181717" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path stroke="none" d="M0 0h24v24H0z" fill="none" />
              <path d="M3 19a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
              <path d="M19 7a2 2 0 1 0 0 -4a2 2 0 0 0 0 4z" />
              <path d="M11 19h5.5a3.5 3.5 0 0 0 0 -7h-8a3.5 3.5 0 0 1 0 -7h4.5" />
            </svg>
            <span>
              {formatDigits(routeInfo.distance)} <FormattedMessage id="meters" />
            </span>
          </div>
        </div>
      </div>

      <div className="action-gap"></div>

      {/* Action Buttons */}
      <div className="action-buttons2">
        <button className="navigate-btn" onClick={handleNavigate}>
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="#fff">
            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
            <path d="M11.092 2.581a1 1 0 0 1 1.754 -.116l.062 .116l8.005 17.365c.198 .566 .05 1.196 -.378 1.615a1.53 1.53 0 0 1 -1.459 .393l-7.077 -2.398l-6.899 2.338a1.535 1.535 0 0 1 -1.52 -.231l-.112 -.1c-.398 -.386 -.556 -.954 -.393 -1.556l.047 -.15l7.97 -17.276z" />
          </svg>
          <FormattedMessage id="navigate" />
        </button>
        <button className="overview-btn" onClick={handleRouteOverview}>
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2196F3" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
            <path d="M3 19a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
            <path d="M19 7a2 2 0 1 0 0 -4a2 2 0 0 0 0 4z" />
            <path d="M11 19h5.5a3.5 3.5 0 0 0 0 -7h-8a3.5 3.5 0 0 1 0 -7h4.5" />
          </svg>
          <FormattedMessage id="routeOverview" />
        </button>
      </div>
    </div>
  );
};

export default FinalSearch;
