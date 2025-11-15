import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { FormattedMessage, useIntl } from 'react-intl';
import Map, { Marker } from 'react-map-gl';
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { useLangStore } from '../store/langStore';
import { groups } from '../components/groupData';
import { toast } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';
import '../styles/Pmap.css';
import useOfflineMapStyle from '../hooks/useOfflineMapStyle';
import { loadGeoJsonData } from '../utils/loadGeoJsonData.js';

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

const Pmap = () => {
  const navigate = useNavigate();
  const intl = useIntl();
  const language = useLangStore(state => state.language);
  const { mapStyle, handleMapError } = useOfflineMapStyle();

  const [viewState, setViewState] = useState({
    latitude: 36.2880,
    longitude: 59.6157,
    zoom: 16
  });

  const [selectedPlace, setSelectedPlace] = useState(null);
  const [showSearchModal, setShowSearchModal] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [geoData, setGeoData] = useState(null);
  const [geoResults, setGeoResults] = useState([]);
  const [recentSearches, setRecentSearches] = useState([]);
  const [mapLoaded, setMapLoaded] = useState(false);
  const [showBackButton, setShowBackButton] = useState(true);

  const searchInputRef = useRef(null);
  const modalRef = useRef(null);

  // Load geo data
  useEffect(() => {
    let isMounted = true;
    const controller = new AbortController();

    loadGeoJsonData({ language, signal: controller.signal })
      .then(data => {
        if (!isMounted) return;
        setGeoData(data);
        console.log('GeoJSON loaded successfully:', data.features?.length, 'features');
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

  // Filter search results
  useEffect(() => {
    if (geoData && searchQuery) {
      const query = searchQuery.toLowerCase();
      const results = geoData.features.filter((f) => {
        const name = (f.properties?.name || '').toLowerCase();
        const subGroup = (f.properties?.subGroup || '').toLowerCase();
        return name.includes(query) || subGroup.includes(query);
      });
      setGeoResults(results);
    } else {
      setGeoResults([]);
    }
  }, [searchQuery, geoData]);

  // Handle map click for location selection
  const handleMapClick = useCallback((e) => {
    const { lng, lat } = e.lngLat;
    console.log('Map clicked at:', lat, lng);

    let closestFeature = null;
    let closestName = intl.formatMessage({ id: 'mapSelectedLocation' });

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

      if (minDist <= 0.0005 && closestFeature?.properties?.name) {
        closestName = closestFeature.properties.name;
        console.log('Found closest feature:', closestName);
      }
    }

    const location = {
      name: closestName,
      coordinates: [lat, lng],
      feature: closestFeature
    };

    setSelectedPlace(location);

    // Add to recent searches
    setRecentSearches(prev => {
      const newSearch = {
        id: Date.now().toString(),
        name: location.name,
        location: closestFeature?.properties?.subGroup || '',
        coordinates: [lat, lng]
      };

      const filtered = prev.filter(item => item.name !== location.name);
      return [newSearch, ...filtered].slice(0, 10);
    });

  }, [geoData, intl]);

  const handleSearchInputClick = () => {
    setShowBackButton(false);
    setShowSearchModal(true);
  };

  const handlePlaceSelectFromSearch = (place) => {
    setSelectedPlace({
      name: place.name,
      coordinates: place.coordinates,
      location: place.location
    });

    setShowBackButton(true);
    setShowSearchModal(false);
    setSearchQuery('');

    // Center map on selected place
    if (place.coordinates) {
      setViewState(v => ({
        ...v,
        latitude: place.coordinates[0],
        longitude: place.coordinates[1],
        zoom: 18
      }));
    }

    // Add to recent searches
    setRecentSearches(prev => {
      const filtered = prev.filter(item => item.id !== place.id);
      return [place, ...filtered].slice(0, 10);
    });
  };

  const handleSelectFromMap = () => {
    setShowBackButton(true);
    setShowSearchModal(false);
  };

  const handleModalClose = () => {
    setShowBackButton(true);
    setShowSearchModal(false);
    setSearchQuery('');
  };

  const handleClearSearch = () => {
    setSearchQuery('');
  };

  const handleInputChange = (e) => {
    setSearchQuery(e.target.value);
  };

  const getFeatureCenter = (feature) => {
    if (!feature) return null;
    const { geometry } = feature;
    if (geometry.type === 'Point') return geometry.coordinates;
    return null;
  };

  // Replace the current handleConfirmLocation functionality
  const handleConfirmLocation = () => {
    if (!selectedPlace) {
      console.log('No location selected');
      return;
    }

    // Get the location name and address
    const locationName = selectedPlace.name;
    const locationAddress = selectedPlace.feature?.properties?.subGroup ||
      selectedPlace.location ||
      'حرم مطهر رضوی'; // Default address

    // Create location object
    const newLocation = {
      id: Date.now().toString(),
      name: locationName,
      address: locationAddress
    };

    // Get existing locations from localStorage
    const existingLocations = JSON.parse(localStorage.getItem('savedLocations') || '[]');

    // Add new location
    const updatedLocations = [newLocation, ...existingLocations];

    // Save to localStorage
    localStorage.setItem('savedLocations', JSON.stringify(updatedLocations));

    // Navigate back to pfp page
    navigate('/pfp');
  };

  const filteredResults = searchQuery
    ? geoResults.map((f) => {
      const center = getFeatureCenter(f);
      return {
        id: f.properties?.uniqueId || f.id,
        name: f.properties?.name || '',
        location: f.properties?.subGroup || '',
        coordinates: center ? [center[1], center[0]] : null
      };
    })
    : recentSearches;

  // Close modal when clicking outside
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (modalRef.current && !modalRef.current.contains(event.target)) {
        setShowSearchModal(false);
        setSearchQuery('');
      }
    };

    if (showSearchModal) {
      document.addEventListener('mousedown', handleClickOutside);
    }

    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [showSearchModal]);

  // Focus search input when modal opens
  useEffect(() => {
    if (showSearchModal && searchInputRef.current) {
      searchInputRef.current.focus();
    }
  }, [showSearchModal]);

  const onMove = useCallback((evt) => {
    setViewState(evt.viewState);
  }, []);

  const handleMapLoad = useCallback(() => {
    setMapLoaded(true);
    console.log('Map loaded successfully');
  }, []);

  const pointFeatures = geoData
    ? geoData.features.filter(f => f.geometry.type === 'Point')
    : [];

  console.log('Map features:', pointFeatures.length);
  console.log('Selected place:', selectedPlace);

  return (
    <div className="pmap-page">
      {showBackButton && (
        <button className="pmap-back-button" onClick={() => navigate(-1)}>
          <svg width="22" height="22" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M3.33301 10H16.6663M16.6663 10L11.6663 5M16.6663 10L11.6663 15" stroke="#1E2023" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
        </button>
      )}

      {/* Map Container */}
      <div className="pmap-container">
        <Map
          mapLib={maplibregl}
          mapStyle={mapStyle}
          style={{ width: '100%', height: '100%' }}
          {...viewState}
          onMove={onMove}
          onClick={handleMapClick}
          onLoad={handleMapLoad}
          onError={handleMapError}
          interactive={true}
        >
          {/* Selected place marker */}
          {selectedPlace && selectedPlace.coordinates && (
            <>
              {/* Blue circle marker */}
              <Marker
                longitude={selectedPlace.coordinates[1]}
                latitude={selectedPlace.coordinates[0]}
                anchor="center"
              >
                <svg width="18" height="18" viewBox="0 0 15 15" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <circle cx="7.5" cy="7.5" r="5.6775" transform="matrix(-1 0 0 1 15 0)" fill="white" stroke="#0F71EF" strokeWidth="3.645" />
                </svg>
              </Marker>

              {/* White pointer marker */}
              <Marker
                longitude={selectedPlace.coordinates[1]}
                latitude={selectedPlace.coordinates[0]}
                anchor="bottom"
              >
                <div className="pmap-combined-marker">
                  <svg width="53" height="62" viewBox="0 0 53 62" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M26.4102 0C40.9961 0 52.8203 11.8242 52.8203 26.4102C52.8203 38.7442 44.3649 49.1022 32.9346 52.0068C32.7025 52.383 32.4579 52.7793 32.1992 53.1953L30.832 55.3955C28.4792 59.1797 27.3024 61.0712 25.5859 61.0713C23.8694 61.0713 22.6927 59.1797 20.3398 55.3955L18.9717 53.1953C18.5716 52.5518 18.2034 51.9573 17.8682 51.4072C7.4744 47.8564 0 38.0066 0 26.4102C0 11.8242 11.8242 0 26.4102 0Z" fill="white" />
                  </svg>
                  <div className="pmap-marker-star">
                    <svg width="21" height="22" viewBox="0 0 21 22" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path d="M8.00902 5.23233C9.11734 3.24411 9.6715 2.25 10.5 2.25C11.3285 2.25 11.8827 3.24411 12.991 5.23233L13.2777 5.7467C13.5927 6.31169 13.7501 6.59419 13.9957 6.78058C14.2412 6.96697 14.547 7.03616 15.1586 7.17454L15.7154 7.30052C17.8676 7.78749 18.9437 8.03097 19.1998 8.85426C19.4558 9.67756 18.7222 10.5354 17.2549 12.2512L16.8753 12.6951C16.4584 13.1826 16.2499 13.4264 16.1561 13.728C16.0623 14.0296 16.0939 14.3548 16.1569 15.0054L16.2143 15.5976C16.4361 17.8868 16.547 19.0314 15.8767 19.5402C15.2065 20.049 14.1989 19.5851 12.1838 18.6573L11.6625 18.4172C11.0898 18.1536 10.8035 18.0217 10.5 18.0217C10.1965 18.0217 9.91019 18.1536 9.33755 18.4172L8.81621 18.6573C6.80109 19.5851 5.79353 20.049 5.12325 19.5402C4.45298 19.0314 4.56389 17.8868 4.78572 15.5976L4.84311 15.0054C4.90615 14.3548 4.93767 14.0296 4.84388 13.728C4.75009 13.4264 4.54162 13.1826 4.12468 12.6951L3.74509 12.2512C2.27784 10.5354 1.54422 9.67756 1.80024 8.85426C2.05627 8.03097 3.13237 7.78749 5.28459 7.30053L5.8414 7.17454C6.45299 7.03616 6.75879 6.96697 7.00433 6.78058C7.24986 6.59419 7.40733 6.31169 7.72228 5.7467L8.00902 5.23233Z" fill="#FFFFFF" />
                    </svg>
                  </div>
                </div>

              </Marker>
            </>
          )}

          {/* All point features - Always show them */}
          {pointFeatures.map((feature, idx) => {
            const [lng, lat] = feature.geometry.coordinates;
            const { group, nodeFunction } = feature.properties || {};
            const rawId = feature.properties?.uniqueId;
            const key = rawId ? `${rawId}-${idx}` : idx;

            return (
              <Marker key={key} longitude={lng} latitude={lat} anchor="center">
                {getCompositeIcon(group, nodeFunction, 25, 0.8)}
              </Marker>
            );
          })}
        </Map>
      </div>

      {/* Search Input Box */}
      <div className="pmap-search-container">
        <div className="pmap-search-box">
          <div className="pmap-search-input-wrapper" onClick={handleSearchInputClick}>
            <button className="pmap-star-icon">
              <svg width="22" height="22" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M7.62796 4.50731C8.6835 2.61376 9.21127 1.66699 10.0003 1.66699C10.7894 1.66699 11.3171 2.61376 12.3727 4.5073L12.6458 4.99719C12.9457 5.53527 13.0957 5.80431 13.3295 5.98183C13.5634 6.15935 13.8546 6.22524 14.4371 6.35703L14.9674 6.47702C17.0171 6.94079 18.042 7.17267 18.2858 7.95677C18.5296 8.74086 17.831 9.55788 16.4336 11.1919L16.0721 11.6147C15.675 12.079 15.4764 12.3112 15.3871 12.5984C15.2978 12.8857 15.3278 13.1954 15.3878 13.815L15.4425 14.379C15.6538 16.5592 15.7594 17.6492 15.121 18.1338C14.4827 18.6184 13.5231 18.1766 11.6039 17.293L11.1074 17.0644C10.5621 16.8133 10.2894 16.6877 10.0003 16.6877C9.71128 16.6877 9.4386 16.8133 8.89323 17.0644L8.39672 17.293C6.47755 18.1766 5.51797 18.6184 4.87962 18.1338C4.24126 17.6492 4.34689 16.5592 4.55816 14.379L4.61281 13.815C4.67285 13.1954 4.70286 12.8857 4.61354 12.5984C4.52423 12.3112 4.32568 12.079 3.92859 11.6147L3.56707 11.1919C2.1697 9.55789 1.47101 8.74086 1.71484 7.95677C1.95867 7.17267 2.98354 6.94079 5.03327 6.47702L5.56356 6.35703C6.14603 6.22524 6.43727 6.15935 6.67111 5.98183C6.90495 5.80431 7.05493 5.53527 7.35488 4.99719L7.62796 4.50731Z" fill="#0F71EF" />
              </svg>
            </button>
            <input
              type="text"
              placeholder={intl.formatMessage({ id: 'pmapSearchPlaceholder' })}
              value={selectedPlace ? selectedPlace.name : ''}
              readOnly
              className="pmap-search-input"
            />
            <div className="pmap-search-icons">
              <div className="pmap-search-icon">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path fillRule="evenodd" clipRule="evenodd" d="M10.4163 2.2915C14.4434 2.2915 17.708 5.55609 17.708 9.58317C17.708 13.6102 14.4434 16.8748 10.4163 16.8748C6.38926 16.8748 3.12467 13.6102 3.12467 9.58317C3.12467 5.55609 6.38926 2.2915 10.4163 2.2915ZM18.958 9.58317C18.958 4.86574 15.1338 1.0415 10.4163 1.0415C5.69891 1.0415 1.87467 4.86574 1.87467 9.58317C1.87467 11.7169 2.65707 13.668 3.95062 15.165L1.2244 17.8912C0.980322 18.1353 0.980322 18.531 1.2244 18.7751C1.46848 19.0192 1.8642 19.0192 2.10828 18.7751L4.8345 16.0489C6.33156 17.3424 8.28258 18.1248 10.4163 18.1248C15.1338 18.1248 18.958 14.3006 18.958 9.58317Z" fill="#1E2023" />
                </svg>
              </div>
            </div>
          </div>

          <button className="pmap-confirm-button" onClick={handleConfirmLocation}>
            <FormattedMessage id="confirmLocation" />
          </button>
        </div>
      </div>

      {/* Search Modal */}
      {showSearchModal && (
        <div className="pmap-search-modal fade-in" ref={modalRef}>
          <div className="pmap-search-header">
            <form className="pmap-search-form">
              <button
                type="button"
                className="pmap-modal-back-button"
                onClick={handleModalClose}
              >
                <svg width="22" height="22" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path fillRule="evenodd" clipRule="evenodd" d="M11.2244 4.55806C11.4685 4.31398 11.8642 4.31398 12.1083 4.55806L17.1083 9.55806C17.3524 9.80214 17.3524 10.1979 17.1083 10.4419L12.1083 15.4419C11.8642 15.686 11.4685 15.686 11.2244 15.4419C10.9803 15.1979 10.9803 14.8021 11.2244 14.5581L15.1575 10.625H3.33301C2.98783 10.625 2.70801 10.3452 2.70801 10C2.70801 9.65482 2.98783 9.375 3.33301 9.375H15.1575L11.2244 5.44194C10.9803 5.19786 10.9803 4.80214 11.2244 4.55806Z" fill="black" />
                </svg>
              </button>

              <input
                type="text"
                placeholder={intl.formatMessage({ id: 'pmapSearchPlaceholder' })}
                value={searchQuery}
                onChange={handleInputChange}
                autoFocus
                ref={searchInputRef}
              />

              {searchQuery && (
                <button type="button" className="pmap-clear-search" onClick={handleClearSearch}>
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="black" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path d="M18 6l-12 12" />
                    <path d="M6 6l12 12" />
                  </svg>
                </button>
              )}
            </form>
          </div>

          <div className="pmap-options-section">
            <div className="pmap-option-item" onClick={handleSelectFromMap}>
              <div className="pmap-option-icon">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path fillRule="evenodd" clipRule="evenodd" d="M13.4885 1.43864C12.4052 1.29299 10.9817 1.29166 8.99984 1.29166C7.01798 1.29166 5.59447 1.29299 4.51115 1.43864C3.44582 1.58187 2.80355 1.85428 2.32883 2.32899C1.85412 2.80371 1.58171 3.44598 1.43848 4.51131C1.29283 5.59463 1.2915 7.01814 1.2915 9C1.2915 10.9819 1.29283 12.4054 1.43848 13.4887C1.58171 14.554 1.85412 15.1963 2.32883 15.671C2.71852 16.0607 3.22112 16.3141 3.97797 16.4715L16.4714 3.97813C16.3139 3.22128 16.0605 2.71868 15.6708 2.32899C15.1961 1.85428 14.5539 1.58187 13.4885 1.43864ZM16.6559 5.56137L11.5508 10.6665L16.0568 15.1726C16.3023 14.7565 16.4624 14.2232 16.5612 13.4887C16.7068 12.4054 16.7082 10.9819 16.7082 9C16.7082 7.60122 16.7075 6.48057 16.6559 5.56137ZM15.173 16.0566L10.6669 11.5504L5.56121 16.6561C6.48041 16.7077 7.60106 16.7083 8.99984 16.7083C10.9817 16.7083 12.4052 16.707 13.4885 16.5614C14.2234 16.4626 14.7569 16.3023 15.173 16.0566ZM13.6551 0.199786C14.859 0.361652 15.809 0.699436 16.5547 1.44511C17.3004 2.19079 17.6382 3.14081 17.8 4.34475C17.9582 5.52099 17.9582 7.02852 17.9582 8.95219V9.04781C17.9582 10.9715 17.9582 12.479 17.8 13.6552C17.6382 14.8592 17.3004 15.8092 16.5547 16.5549C15.809 17.3006 14.859 17.6383 13.6551 17.8002C12.4788 17.9583 10.9713 17.9583 9.04765 17.9583H8.95203C7.02836 17.9583 5.52083 17.9583 4.34459 17.8002C3.14065 17.6383 2.19063 17.3006 1.44495 16.5549C0.699276 15.8092 0.361493 14.8592 0.199626 13.6552C0.0414851 12.479 0.0414935 10.9715 0.0415041 9.04781V8.95218C0.0414933 7.02852 0.0414847 5.52099 0.199626 4.34475C0.361492 3.14081 0.699276 2.19079 1.44495 1.44511C2.19063 0.699437 3.14065 0.361653 4.34459 0.199786C5.52083 0.0416453 7.02836 0.0416537 8.95202 0.0416643H9.04765C10.9713 0.0416535 12.4788 0.0416449 13.6551 0.199786ZM2.95817 6.29768C2.95817 4.41182 4.58689 2.95825 6.49984 2.95825C8.41279 2.95825 10.0415 4.41182 10.0415 6.29768C10.0415 7.98624 9.00333 9.97915 7.2788 10.7161C6.78335 10.9278 6.21633 10.9278 5.72087 10.7161C3.99634 9.97915 2.95817 7.98624 2.95817 6.29768ZM6.49984 4.20825C5.19113 4.20825 4.20817 5.18526 4.20817 6.29768C4.20817 7.5839 5.0318 9.06229 6.21208 9.56668C6.39379 9.64433 6.60589 9.64433 6.78759 9.56668C7.96788 9.06229 8.7915 7.5839 8.7915 6.29768C8.7915 5.18526 7.80855 4.20825 6.49984 4.20825Z" fill="#0F71EF" />
                  <path d="M7.33317 6.5C7.33317 6.96024 6.96007 7.33333 6.49984 7.33333C6.0396 7.33333 5.6665 6.96024 5.6665 6.5C5.6665 6.03976 6.0396 5.66666 6.49984 5.66666C6.96007 5.66666 7.33317 6.03976 7.33317 6.5Z" fill="#0F71EF" />
                </svg>
              </div>
              <span className="pmap-option-text">
                <FormattedMessage id="chooseFromMap" />
              </span>
            </div>
          </div>

          {!searchQuery && (
            <>
              <h2 className="pmap-recent-title">
                <FormattedMessage id="recentSearches" />
              </h2>
              {recentSearches.length === 0 ? (
                <p className="pmap-no-recent">
                  <FormattedMessage id="noRecentSearches" />
                </p>
              ) : (
                <ul className="pmap-destination-list">
                  {recentSearches.map((destination) => (
                    <li key={destination.id} onClick={() => handlePlaceSelectFromSearch(destination)}>
                      <div className="pmap-recent-icon">
                        <svg width="15" height="15" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                          <path fillRule="evenodd" clipRule="evenodd" d="M2.96259 2.95683C5.17657 0.745974 8.77558 0.769482 11.0031 2.997C13.2316 5.22548 13.2541 8.82663 11.0404 11.0404C8.82667 13.2541 5.22552 13.2315 2.99704 11.0031C1.67644 9.68246 1.1311 7.88082 1.36528 6.17239C1.39809 5.933 1.61875 5.76554 1.85814 5.79835C2.09753 5.83117 2.265 6.05183 2.23218 6.29122C2.03386 7.73809 2.49541 9.26398 3.61577 10.3843C5.50841 12.277 8.5555 12.2878 10.4217 10.4216C12.2878 8.55546 12.277 5.50837 10.3844 3.61573C8.49269 1.72405 5.44775 1.71226 3.58132 3.57556L4.01749 3.57775C4.25911 3.57896 4.454 3.77582 4.45279 4.01745C4.45157 4.25907 4.25471 4.45396 4.01309 4.45275L2.52816 4.44529C2.28825 4.44408 2.09406 4.2499 2.09286 4.00999L2.0854 2.52506C2.08418 2.28343 2.27907 2.08657 2.5207 2.08536C2.76232 2.08414 2.95918 2.27904 2.9604 2.52066L2.96259 2.95683ZM7.00002 4.2291C7.24164 4.2291 7.43752 4.42498 7.43752 4.66661V6.81876L8.76773 8.14897C8.93859 8.31983 8.93859 8.59684 8.76773 8.7677C8.59688 8.93855 8.31986 8.93855 8.14901 8.7677L6.56251 7.1812V4.66661C6.56251 4.42498 6.75839 4.2291 7.00002 4.2291Z" fill="#858585" />
                        </svg>
                      </div>
                      <div className="pmap-destination-info">
                        <span className="pmap-destination-name">{destination.name}</span>
                        <span className="pmap-destination-location">{destination.location}</span>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}

          {searchQuery && filteredResults.length > 0 && (
            <ul className="pmap-destination-list">
              {filteredResults.map((destination) => (
                <li key={destination.id} onClick={() => handlePlaceSelectFromSearch(destination)}>
                  <div className="pmap-recent-icon">
                    <svg width="15" height="15" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path fillRule="evenodd" clipRule="evenodd" d="M2.96259 2.95683C5.17657 0.745974 8.77558 0.769482 11.0031 2.997C13.2316 5.22548 13.2541 8.82663 11.0404 11.0404C8.82667 13.2541 5.22552 13.2315 2.99704 11.0031C1.67644 9.68246 1.1311 7.88082 1.36528 6.17239C1.39809 5.933 1.61875 5.76554 1.85814 5.79835C2.09753 5.83117 2.265 6.05183 2.23218 6.29122C2.03386 7.73809 2.49541 9.26398 3.61577 10.3843C5.50841 12.277 8.5555 12.2878 10.4217 10.4216C12.2878 8.55546 12.277 5.50837 10.3844 3.61573C8.49269 1.72405 5.44775 1.71226 3.58132 3.57556L4.01749 3.57775C4.25911 3.57896 4.454 3.77582 4.45279 4.01745C4.45157 4.25907 4.25471 4.45396 4.01309 4.45275L2.52816 4.44529C2.28825 4.44408 2.09406 4.2499 2.09286 4.00999L2.0854 2.52506C2.08418 2.28343 2.27907 2.08657 2.5207 2.08536C2.76232 2.08414 2.95918 2.27904 2.9604 2.52066L2.96259 2.95683ZM7.00002 4.2291C7.24164 4.2291 7.43752 4.42498 7.43752 4.66661V6.81876L8.76773 8.14897C8.93859 8.31983 8.93859 8.59684 8.76773 8.7677C8.59688 8.93855 8.31986 8.93855 8.14901 8.7677L6.56251 7.1812V4.66661C6.56251 4.42498 6.75839 4.2291 7.00002 4.2291Z" fill="#858585" />
                    </svg>
                  </div>
                  <div className="pmap-destination-info">
                    <span className="pmap-destination-name">{destination.name}</span>
                    <span className="pmap-destination-location">{destination.location}</span>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
};

export default Pmap;