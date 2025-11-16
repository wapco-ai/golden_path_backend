import React, { useState, useRef, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { toast } from 'react-toastify';
import { useIntl } from 'react-intl';
import Mpbc from '../components/map/Mpbc';
import { groups, subGroups } from '../components/groupData';
import { useRouteStore } from '../store/routeStore';
import { useLangStore } from '../store/langStore';
import { buildGeoJsonPath } from '../utils/geojsonPath.js';
import { getLocationTitleById } from '../utils/getLocationTitle';
import '../styles/MapBegin.css';

const MapBeginPage = () => {
  const navigate = useNavigate();
  const intl = useIntl();
  const language = useLangStore(state => state.language);
  const [selectedOrigin, setSelectedOrigin] = useState(null);
  const storedLat = sessionStorage.getItem('qrLat');
  const storedLng = sessionStorage.getItem('qrLng');
  const storedId = sessionStorage.getItem('qrId');
  const initialUserLocation = storedLat && storedLng
    ? {
      name: intl.formatMessage({ id: 'mapCurrentLocationName' }),
      coordinates: [parseFloat(storedLat), parseFloat(storedLng)]
    }
    : {
      name: intl.formatMessage({ id: 'defaultBabRezaName' }),
      coordinates: [36.2880, 59.6157]
    };
  const [userLocation, setUserLocation] = useState(initialUserLocation);
  const [isTracking, setIsTracking] = useState(true);
  const [mapSelectedLocation, setMapSelectedLocation] = useState(null);
  const [selectedCategory, setSelectedCategory] = useState(null);
  const [showRouting, setShowRouting] = useState(false);
  const [searchClose, setSearchClose] = useState(false);
  const searchInputRef = useRef(null);
  const [routingData, setRoutingData] = useState(null);
  const [activeTab, setActiveTab] = useState('mostVisited');
  const [showImageMarkers, setShowImageMarkers] = useState(true);
  const [selectedLocation, setSelectedLocation] = useState(null);
  const [showLocationDetails, setShowLocationDetails] = useState(false);
  const [expandedSearch, setExpandedSearch] = useState(false);
  const [isQrCodeEntry, setIsQrCodeEntry] = useState(false);
  const [touchStartY, setTouchStartY] = useState(0);
  const [isSwiping, setIsSwiping] = useState(false);
  const [isDragging, setIsDragging] = useState(false);
  const [dragStartHeight, setDragStartHeight] = useState(0);
  const [dragStartY, setDragStartY] = useState(0);
  const [velocity, setVelocity] = useState(0);
  const [lastTouchY, setLastTouchY] = useState(0);
  const [lastTouchTime, setLastTouchTime] = useState(0);
  const [currentHeight, setCurrentHeight] = useState(140);
  const [isAutoExpanding, setIsAutoExpanding] = useState(false);
  const [preventMapCentering, setPreventMapCentering] = useState(false);
  const clearMapSelection = () => {
    sessionStorage.removeItem('mapSelectedLat');
    sessionStorage.removeItem('mapSelectedLng');
    sessionStorage.removeItem('mapSelectedId');
  };

  useEffect(() => {
    if (storedLat && storedLng && storedId) {
      getLocationTitleById(storedId).then((title) => {
        if (title) {
          setUserLocation({
            name: title,
            coordinates: [parseFloat(storedLat), parseFloat(storedLng)]
          });
        }
      });
    }
  }, [storedLat, storedLng, storedId, language]);

  const setOriginStore = useRouteStore(state => state.setOrigin);

  const handleSearchToggle = () => {
    if (isDragging) return;

    // Special case: if this is a QR code entry and we're showing cultural info
    if (isQrCodeEntry && showLocationDetails && showRouting) {
      if (expandedSearch) {
        setExpandedSearch(false);
        setCurrentHeight(window.innerHeight * 0.3);
      } else if (currentHeight <= window.innerHeight * 0.3) {
        setCurrentHeight(140);
        setShowRouting(false);
        setExpandedSearch(false);
        setIsQrCodeEntry(false);
      } else {
        setExpandedSearch(true);
        setCurrentHeight(window.innerHeight);
      }
    } else if (showRouting) {
      if (expandedSearch) {
        setExpandedSearch(false);
        setCurrentHeight(window.innerHeight * 0.41);
      } else {
        // Always allow closing completely regardless of cultural info
        setCurrentHeight(140);
        setShowRouting(false);
        setExpandedSearch(false);
      }
    } else {
      // Open to show location details if available
      setShowRouting(true);
      if (showLocationDetails) {
        setCurrentHeight(window.innerHeight * 0.41);
        setExpandedSearch(false);
      } else {
        setCurrentHeight(window.innerHeight);
        setExpandedSearch(true);
      }
    }
  };

  // useEffect(() => {
  //   // If a location with image is selected, show the routing panel
  //   if (showLocationDetails && !showRouting) {
  //     setShowRouting(true);
  //     setExpandedSearch(false);
  //     setIsAutoExpanding(true);

  //     // Set height based on whether this is a QR code entry or normal selection
  //     let newHeight;
  //     if (isQrCodeEntry) {
  //       newHeight = window.innerHeight * 0.3; // 30vh for QR code entries
  //     } else {
  //       newHeight = window.innerHeight * 0.41; // 41vh for normal selections
  //     }

  //     setCurrentHeight(newHeight);

  //     // Reset auto expanding after a delay
  //     setTimeout(() => setIsAutoExpanding(false), 300);
  //   }
  // }, [showLocationDetails, showRouting, isQrCodeEntry]);

  const handleCulturalInfo = () => {
    // Check if the selected location is one of our two special places
    const isRozemonavare = selectedLocation?.value === 'rozemonavare';
    const isSaghakhaneh = selectedLocation?.value === 'saghakhaneh';

    if (isRozemonavare || isSaghakhaneh) {
      // For these two specific places, use their fixed coordinates and IDs
      let lat, lng, id;

      if (isRozemonavare) {
        lat = 36.288005181401;
        lng = 59.61569271248;
        id = 'rozemonavare_12';
      } else if (isSaghakhaneh) {
        lat = 36.288464700649;
        lng = 59.616118862511;
        id = 'saghakhaneh_15';
      }

      // Navigate with both state and URL parameters
      navigate(`/location?id=${id}&lat=${lat}&lng=${lng}`, {
        state: { location: selectedLocation }
      });
    } else {
      // For all other places, use the normal navigation
      navigate('/location', { state: { location: selectedLocation } });
    }
  };


  const handleMapClick = (latlng, feature) => {
    const locName = feature?.properties?.name || intl.formatMessage({ id: 'mapSelectedLocation' });
    const origin = {
      name: locName,
      coordinates: [latlng.lat, latlng.lng]
    };

    setSelectedOrigin(origin);

    // Update session storage with the selected origin
    sessionStorage.setItem('mapSelectedLat', latlng.lat.toString());
    sessionStorage.setItem('mapSelectedLng', latlng.lng.toString());
    if (feature?.properties?.uniqueId) {
      sessionStorage.setItem('mapSelectedId', feature.properties.uniqueId);
    }

    // CRITICAL FIX: Set flag to prevent map centering
    setPreventMapCentering(true);

    // CRITICAL FIX: Only update user location if NOT entered via QR code
    const isQrEntry = sessionStorage.getItem('qrLat') && sessionStorage.getItem('qrLng');

    if (!isQrEntry) {
      // Only update user location if this is NOT a QR code entry
      setUserLocation({
        name: locName,
        coordinates: [latlng.lat, latlng.lng]
      });

      // Update the store only for non-QR entries
      setOriginStore({
        name: origin.name,
        coordinates: origin.coordinates
      });
    } else {
      // For QR code entries, set the selected origin but don't change user location
      setOriginStore({
        name: userLocation.name, // Keep QR location name
        coordinates: userLocation.coordinates // Keep QR coordinates
      });
    }

    // Check if this feature has an image and show location details
    if (feature?.properties?.subGroupValue) {
      const subgroup = Object.values(subGroups)
        .flat()
        .find(sg => sg.value === feature.properties.subGroupValue);

      if (subgroup && subgroup.img) {
        setSelectedLocation({
          ...subgroup,
          coordinates: [latlng.lat, latlng.lng]
        });
        setShowLocationDetails(true);
        setShowRouting(true);
        setExpandedSearch(false);
      } else {
        setShowLocationDetails(false);
      }
    } else {
      setShowLocationDetails(false);
    }
  };

  // Add this useEffect after your existing QR code useEffect
  useEffect(() => {
    // Check if this is a QR code entry for Bab ol Reza (sahn-payambar-azam)
    if (storedId === 'sahn-payambar-azam_2658' && storedLat && storedLng) {
      // Find the Sahne Enghelab cultural info from subGroups
      const sahneEnghelabInfo = subGroups.sahn.find(
        item => item.value === 'sahn-payambar-azam'
      );

      if (sahneEnghelabInfo) {
        // Set the selected location with coordinates from QR code
        setSelectedLocation({
          ...sahneEnghelabInfo,
          coordinates: [parseFloat(storedLat), parseFloat(storedLng)]
        });

        // Show location details and routing panel
        setShowLocationDetails(true);
        setShowRouting(true);
        setExpandedSearch(false);

        // Set height to 30vh equivalent for the modal
        const thirtyVhHeight = window.innerHeight * 0.3;
        setCurrentHeight(thirtyVhHeight);

        // Set flag to indicate this is a QR code entry
        setIsQrCodeEntry(true);
      }
    }
  }, [storedId, storedLat, storedLng, language]);

  const handleTouchStart = (e) => {
    const touchY = e.touches[0].clientY;
    setIsDragging(true);
    setDragStartY(touchY);
    setDragStartHeight(currentHeight);
    setLastTouchY(touchY);
    setLastTouchTime(Date.now());
    setVelocity(0);
  };

  const handleTouchMove = (e) => {
    if (!isDragging) return;

    const touchY = e.touches[0].clientY;
    const currentTime = Date.now();
    const deltaTime = currentTime - lastTouchTime;

    if (deltaTime > 0) {
      const deltaY = lastTouchY - touchY;
      const newVelocity = deltaY / deltaTime;
      setVelocity(newVelocity);
    }

    const deltaY = dragStartY - touchY;
    const newHeight = dragStartHeight + deltaY;

    let resistance = 1;
    if (newHeight < 140) {
      resistance = 0.3 + (0.7 * (newHeight / 140));
    } else if (newHeight > window.innerHeight) {
      resistance = 0.3 + (0.7 * (window.innerHeight / newHeight));
    }

    const clampedHeight = Math.max(80, Math.min(newHeight * resistance, window.innerHeight * 1.1));
    setCurrentHeight(clampedHeight);

    setLastTouchY(touchY);
    setLastTouchTime(currentTime);
  };


  useEffect(() => {
    const handleResize = () => {
      if (expandedSearch) {
        setCurrentHeight(window.innerHeight);
      }
    };

    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, [expandedSearch]);

  const handleTouchEnd = () => {
    if (!isDragging) return;
    setIsDragging(false);

    const screenHeight = window.innerHeight;
    const snapThreshold = 50;
    const velocityThreshold = 0.5; 

    let targetHeight;

    if (Math.abs(velocity) > velocityThreshold) {

      if (velocity > 0) {

        targetHeight = window.innerHeight;
      } else {

        if (currentHeight < screenHeight * 0.3) {
          targetHeight = 140;
        } else {
          targetHeight = window.innerHeight * 0.41;
        }
      }
    } else {
      // No significant velocity - use position-based snapping
      if (currentHeight < 140 + snapThreshold) {
        targetHeight = 140;
      } else if (currentHeight < screenHeight * 0.35) {
        targetHeight = window.innerHeight * 0.41;
      } else if (currentHeight < screenHeight * 0.7) {
        targetHeight = window.innerHeight * 0.41;
      } else {
        targetHeight = screenHeight;
      }
    }

    // Smooth animation to target height
    setCurrentHeight(targetHeight);

    // Update UI state based on final height
    if (targetHeight <= 140) {
      setShowRouting(false);
      setExpandedSearch(false);
    } else if (targetHeight <= screenHeight * 0.41) {
      setExpandedSearch(false);
      setShowRouting(true);
    } else {
      setExpandedSearch(true);
      setShowRouting(true);
    }

    setVelocity(0);
  };

  useEffect(() => {
    setShowImageMarkers(!selectedCategory);
  }, [selectedCategory]);

  const handleCategoryClick = (category) => {
    const isSameCategory = selectedCategory && selectedCategory.value === category.value;
    setSelectedCategory(isSameCategory ? null : category);
    // Show image markers only when no category is selected
    setShowImageMarkers(isSameCategory ? true : false);
  }


  const handleSearchBlur = () => {
    setIsSearchFocused(false);
  };

  // Fetch routingData.json from public folder
  useEffect(() => {
    fetch(`./data/routing-data.json`)
      .then(res => res.json())
      .then(data => setRoutingData(data))
      .catch(err => console.error('Failed to load routing-data.json', err));
  }, []);


  const handlePlaceClick = (placeTitle, groupValue, subGroupValue) => {
    if (!geoData) return;

    let feature = geoData.features.find(
      f =>
        f.properties?.name === placeTitle ||
        f.properties?.subGroup === placeTitle ||
        (subGroupValue && f.properties?.subGroupValue === subGroupValue) ||
        f.properties?.subGroupValue === labelToValueMap[placeTitle]
    );

    if (!feature && groupValue) {
      feature = geoData.features.find(f => f.properties?.group === groupValue);
    }

    toast.error(intl.formatMessage({ id: 'noDataFound' }));
  };



  return (
    <div className="map-routing-page">
      {/* Header */}
      <header className="map-routing-header">
        <button className="map-menu-button">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            className="icon icon-tabler icons-tabler-outline icon-tabler-menu-2"
          >
            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
            <path d="M4 6l16 0" />
            <path d="M4 12l16 0" />
            <path d="M4 18l16 0" />
          </svg>
        </button>
        <h1 className="map-header-title">
          {intl.formatMessage({ id: 'mapRoutingTitle' })}
        </h1>
        <button className="map-profile-button" onClick={() => navigate('/Profile')}>
          <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="9.99984" cy="5" r="3.33333" fill="#1E2023" />
            <ellipse cx="9.99984" cy="14.1667" rx="5.83333" ry="3.33333" fill="#1E2023" />
          </svg>
        </button>
      </header>

      {/* Categories Scroll */}
      <div className="map-categories-scroll">
        <div className="map-categories-list">
          {groups.map((category) => (
            <div
              key={category.value}
              className={`map-category-item ${selectedCategory && selectedCategory.value === category.value ? 'active' : ''}`}
              onClick={() => handleCategoryClick(category)}
            >
              <div className={`map-category-icon ${category.icon} ${selectedCategory && selectedCategory.value === category.value ? 'active' : ''}`}>
                <img src={category.png} alt={category.label} width="22" height="22" />
              </div>
              <span className={`map-category-name ${selectedCategory && selectedCategory.value === category.value ? 'active' : ''}`}>
                {intl.formatMessage({ id: category.label })}
              </span>
            </div>
          ))}
        </div>
      </div>

      {/* Map Container */}
      <div className="map-routing-container">
        <Mpbc
          setUserLocation={setUserLocation}
          selectedDestination={null}
          onMapClick={handleMapClick}
          selectedCategory={selectedCategory}
          userLocation={userLocation}
          mapSelectedLocation={mapSelectedLocation}
          isTracking={isTracking}
          onUserMove={() => setIsTracking(false)}
          showImageMarkers={showImageMarkers}
          isQrCodeEntry={isQrCodeEntry}
        />
        <button
          className={`map-gps-button ${isTracking ? 'active' : ''}`}
          onClick={() => setIsTracking((t) => !t)}
        >
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
            <path d="M12 12m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" />
            <path d="M12 12m-8 0a8 8 0 1 0 16 0a8 8 0 1 0 -16 0" />
            <path d="M12 2l0 2" />
            <path d="M12 20l0 2" />
            <path d="M20 12l2 0" />
            <path d="M2 12l2 0" />
          </svg>
        </button>
      </div>

      {/* Search Bar with Integrated Routing */}
      <div
        className={`search-bar-container ${showRouting ? 'expanded' : ''} ${expandedSearch ? 'fully-expanded' : ''} ${isDragging ? 'dragging' : ''} ${isQrCodeEntry ? 'qr-code-entry' : ''}`}
        style={isDragging || isAutoExpanding ? { height: `${currentHeight}px`, transform: 'translateY(0)' } : {}}
      >
        <div
          className="search-bar-toggle"
          onClick={handleSearchToggle}
          onTouchStart={handleTouchStart}
          onTouchMove={handleTouchMove}
          onTouchEnd={handleTouchEnd}
        >
          <div className="toggle-handle"></div>
        </div>

        <form className={`search-bar ${showRouting ? 'expanded' : ''}`}>
          <input
            type="text"
            placeholder={intl.formatMessage({ id: 'searchPlaceholder' })}
            onClick={() => navigate('/mpr')}
            onBlur={handleSearchBlur}
            ref={searchInputRef}
          />
          <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path fillRule="evenodd" clipRule="evenodd" d="M9.58342 2.29163C5.55634 2.29163 2.29175 5.55622 2.29175 9.58329C2.29175 13.6104 5.55634 16.875 9.58342 16.875C13.6105 16.875 16.8751 13.6104 16.8751 9.58329C16.8751 5.55622 13.6105 2.29163 9.58342 2.29163ZM1.04175 9.58329C1.04175 4.86586 4.86598 1.04163 9.58342 1.04163C14.3008 1.04163 18.1251 4.86586 18.1251 9.58329C18.1251 11.7171 17.3427 13.6681 16.0491 15.1651L18.7754 17.8914C19.0194 18.1354 19.0194 18.5312 18.7754 18.7752C18.5313 19.0193 18.1356 19.0193 17.8915 18.7752L15.1653 16.049C13.6682 17.3426 11.7172 18.125 9.58342 18.125C4.86598 18.125 1.04175 14.3007 1.04175 9.58329Z" fill="#1E2023" />
          </svg>


        </form>

        {showRouting && showLocationDetails && selectedLocation && (
          <div className="selected-location-section">
            <div className="selected-location-images">
              <div className="location-image-scroll">
                {/* Handle both single image and multiple images */}
                {Array.isArray(selectedLocation.img) ? (
                  // Multiple images - create scrollable list
                  selectedLocation.img.map((image, index) => (
                    <div
                      key={index}
                      className="location-main-image"
                      style={{ backgroundImage: `url(${image})` }}
                    ></div>
                  ))
                ) : (
                  // Single image
                  <div
                    className="location-main-image"
                    style={{ backgroundImage: `url(${selectedLocation.img})` }}
                  ></div>
                )}
              </div>
            </div>

            <div className="selected-location-info">
              <div className="location-details7">
                <h2 className="selected-location-title">
                  {selectedLocation.label}
                </h2>
                <div className="location-meta7">
                  <span className="location-address">
                    {selectedLocation.address}
                  </span>
                  <span className="place-meta-separator">|</span>
                  <span className="place-distance">{selectedLocation.distance} {intl.formatMessage({ id: 'meter' })}</span>
                  <span className="place-meta-separator">|</span>
                  <span className="place-time">{selectedLocation.time} {intl.formatMessage({ id: 'walking' })}</span>
                </div>

                <div className="location-description">
                  <p>
                    {selectedLocation.description && selectedLocation.description.split(' ').length > 4
                      ? `${selectedLocation.description.split(' ').slice(0, 4).join(' ')} ...`
                      : selectedLocation.description
                    }
                  </p>
                  <button className="cultural-info-btn" onClick={handleCulturalInfo}>
                    {intl.formatMessage({ id: 'moreCulturalInfo' })}
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path fillRule="evenodd" clipRule="evenodd" d="M7.0203 3.64645C7.21556 3.84171 7.21556 4.15829 7.0203 4.35355L3.87385 7.5H13.3334C13.6096 7.5 13.8334 7.72386 13.8334 8C13.8334 8.27614 13.6096 8.5 13.3334 8.5H3.87385L7.0203 11.6464C7.21556 11.8417 7.21556 12.1583 7.0203 12.3536C6.82504 12.5488 6.50846 12.5488 6.31319 12.3536L2.31319 8.35355C2.11793 8.15829 2.11793 7.84171 2.31319 7.64645L6.31319 3.64645C6.50846 3.45118 6.82504 3.45118 7.0203 3.64645Z" fill="#0F71EF" />
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Shrine Events */}
        {routingData && (
          <div className="shrine-events-section">
            <div className="shrine-events-header">
              <h2 className="shrine-events-title">
                {intl.formatMessage({ id: 'shrineEventsTitle' })}
              </h2>
              <button className="view-all-events">
                {intl.formatMessage({ id: 'viewAll' })}
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path d="M15 6l-6 6l6 6" />
                </svg>
              </button>
            </div>
            <div className="shrine-events-list">
              {routingData.places.shrineEvents?.map((event, index) => (
                <div key={index} className="shrine-event-item">
                  <div
                    className="place-image-placeholder"
                    style={{ backgroundImage: `url(${event.image})` }}
                  ></div>
                  <div className="place-info">
                    <h3 className="place-title">{event.title}</h3>
                    <p className="place-description">{event.description}</p>
                    <div className="place-info2">
                      <span className="place-address">
                        <svg width="24" height="24" viewBox="0 0 25 25" fill="none" xmlns="http://www.w3.org/2000/svg">
                          <path fillRule="evenodd" clipRule="evenodd" d="M10.2073 2.9087C9.5 3.53569 9.5 4.68259 9.5 6.9764V18.0236C9.5 20.3174 9.5 21.4643 10.2073 22.0913C10.9145 22.7183 11.9955 22.5297 14.1576 22.1526L16.4864 21.7465C18.8809 21.3288 20.0781 21.12 20.7891 20.2417C21.5 19.3635 21.5 18.0933 21.5 15.5529V9.44711C21.5 6.90671 21.5 5.63652 20.7891 4.75826C20.0781 3.87999 18.8809 3.67118 16.4864 3.25354L14.1576 2.84736C11.9955 2.47026 10.9145 2.28171 10.2073 2.9087ZM12.5 10.6686C12.9142 10.6686 13.25 11.02 13.25 11.4535V13.5465C13.25 13.98 12.9142 14.3314 12.5 14.3314C12.0858 14.3314 11.75 13.98 11.75 13.5465V11.4535C11.75 11.02 12.0858 10.6686 12.5 10.6686Z" fill="#1E2023" />
                          <path d="M8.04717 5C5.98889 5.003 4.91599 5.04826 4.23223 5.73202C3.5 6.46425 3.5 7.64276 3.5 9.99979V14.9998C3.5 17.3568 3.5 18.5353 4.23223 19.2676C4.91599 19.9513 5.98889 19.9966 8.04717 19.9996C7.99985 19.3763 7.99992 18.6557 8.00001 17.8768V7.1227C7.99992 6.34388 7.99985 5.6233 8.04717 5Z" fill="#1E2023" />
                        </svg>
                        {event.location}
                      </span>
                      <div className="place-meta">
                        <span className="shrine-event-time">
                          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="black">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M17 3.34a10 10 0 1 1 -14.995 8.984l-.005 -.324l.005 -.324a10 10 0 0 1 14.995 -8.336zm-5 2.66a1 1 0 0 0 -.993 .883l-.007 .117v5l.009 .131a1 1 0 0 0 .197 .477l.087 .1l3 3l.094 .082a1 1 0 0 0 1.226 0l.094 -.083l.083 -.094a1 1 0 0 0 0 -1.226l-.083 -.094l-2.707 -2.708v-4.585l-.007 -.117a1 1 0 0 0 -.993 -.883z" />
                          </svg>
                          {event.time}
                        </span>
                      </div>
                    </div>

                    <div className="place-actions">
                      <button className="place-action-btn" onClick={() => navigate('/fs')}>
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                          <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                          <path d="M11.092 2.581a1 1 0 0 1 1.754 -.116l.062 .116l8.005 17.365c.198 .566 .05 1.196 -.378 1.615a1.53 1.53 0 0 1 -1.459 .393l-7.077 -2.398l-6.899 2.338a1.535 1.535 0 0 1 -1.52 -.231l-.112 -.1c-.398 -.386 -.556 -.954 -.393 -1.556l.047 -.15l7.97 -17.276z" />
                        </svg>
                        {intl.formatMessage({ id: 'navigate' })}
                      </button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}
        {/* Landmarks */}
        {routingData && (
          <div className="routing-places-section">
            <div className="section-header">
              <h2 className="section-title6">
                {intl.formatMessage({ id: 'landmarkPlaces' })}
              </h2>
              <button className="view-all-btn5">
                {intl.formatMessage({ id: 'viewAll' })}
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path d="M15 6l-6 6l6 6" />
                </svg>
              </button>
            </div>
            <div className="places-horizontal-list">
              {routingData.places.landmarkPlaces.map((place, index) => (
                <div key={index} className="place-card">
                  <div className="image-container">
                    <div
                      className="place-image"
                      style={{ backgroundImage: `url(${place.image})` }}
                    ></div>
                    <button className="transparent-save-btn">
                      <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M7.5 4.37508C7.15482 4.37508 6.875 4.6549 6.875 5.00008C6.875 5.34526 7.15482 5.62508 7.5 5.62508H12.5C12.8452 5.62508 13.125 5.34526 13.125 5.00008C13.125 4.6549 12.8452 4.37508 12.5 4.37508H7.5Z" fill="black" />
                        <path fillRule="evenodd" clipRule="evenodd" d="M9.95209 1.04175C8.22495 1.04174 6.8643 1.04173 5.80107 1.18622C4.70935 1.33459 3.83841 1.64565 3.15403 2.33745C2.47058 3.02831 2.1641 3.90593 2.01775 5.00626C1.87498 6.07967 1.87499 7.45393 1.875 9.20097V13.4493C1.87499 14.7056 1.87498 15.7002 1.95501 16.4491C2.03409 17.1891 2.20373 17.8568 2.6882 18.3033C3.07688 18.6615 3.56842 18.8873 4.09304 18.9473C4.74927 19.0224 5.36199 18.7091 5.96557 18.2815C6.57636 17.8489 7.3173 17.1935 8.25212 16.3667L8.28254 16.3398C8.71593 15.9564 9.00935 15.6978 9.25416 15.5187C9.49076 15.3457 9.63522 15.2832 9.75698 15.2587C9.91743 15.2263 10.0826 15.2263 10.243 15.2587C10.3648 15.2832 10.5092 15.3457 10.7458 15.5187C10.9906 15.6978 11.2841 15.9564 11.7175 16.3398L11.7479 16.3667C12.6827 17.1935 13.4237 17.8489 14.0344 18.2815C14.638 18.7091 15.2507 19.0224 15.907 18.9473C16.4316 18.8873 16.9231 18.6615 17.3118 18.3033C17.7963 17.8568 17.9659 17.1891 18.045 16.4491C18.125 15.7002 18.125 14.7056 18.125 13.4493V9.20095C18.125 7.45393 18.125 6.07966 17.9823 5.00626C17.8359 3.90593 17.5294 3.02831 16.846 2.33745C16.1616 1.64565 15.2907 1.33459 14.1989 1.18622C13.1357 1.04173 11.7751 1.04174 10.0479 1.04175H9.95209ZM4.04267 3.21655C4.45664 2.7981 5.01876 2.55403 5.9694 2.42484C6.93871 2.2931 8.21438 2.29175 10 2.29175C11.7856 2.29175 13.0613 2.2931 14.0306 2.42484C14.9812 2.55403 15.5434 2.7981 15.9573 3.21655C16.3722 3.63594 16.6149 4.20691 16.7432 5.17106C16.8737 6.15251 16.875 7.44361 16.875 9.24801V13.4092C16.875 14.7144 16.8741 15.6419 16.8021 16.3163C16.7282 17.0074 16.592 17.2668 16.4647 17.3841C16.2699 17.5636 16.0248 17.6757 15.7649 17.7054C15.5985 17.7245 15.3196 17.66 14.757 17.2615C14.2081 16.8727 13.5176 16.2632 12.5456 15.4035L12.5238 15.3842C12.1177 15.025 11.781 14.7271 11.4837 14.5097C11.1728 14.2824 10.8594 14.1077 10.4899 14.0333C10.1665 13.9681 9.83352 13.9681 9.51015 14.0333C9.14064 14.1077 8.82715 14.2824 8.51633 14.5097C8.21902 14.7271 7.88226 15.025 7.47621 15.3842L7.45439 15.4035C6.48239 16.2632 5.79189 16.8727 5.24304 17.2615C4.68038 17.66 4.40151 17.7245 4.23515 17.7054C3.97516 17.6757 3.73014 17.5636 3.53531 17.3841C3.40803 17.2668 3.27179 17.0074 3.19793 16.3163C3.12587 15.6419 3.125 14.7144 3.125 13.4092V9.24801C3.125 7.44361 3.1263 6.15251 3.25684 5.17106C3.38508 4.20691 3.62777 3.63594 4.04267 3.21655Z" fill="black" />
                      </svg>
                    </button>
                  </div>
                  <div className="place-details">
                    <h4 className="place-name">{place.title}</h4>
                    <div className="place-meta">
                      <span className="place-distance">{place.distance} {intl.formatMessage({ id: 'meter' })}</span>
                      <span className="place-meta-separator">|</span>
                      <span className="place-time">{place.time} {intl.formatMessage({ id: 'walking' })}</span>
                    </div>
                    <div className="place-rating-section">
                      <div className="place-rating-stars">
                        {[1, 2, 3, 4, 5].map((star) => (
                          <svg
                            key={star}
                            className={star <= Math.round(place.rating) ? 'filled' : ''}
                            xmlns="http://www.w3.org/2000/svg"
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            fill="currentColor"
                          >
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M12 17.75l-6.172 3.245l1.179 -6.873l-5 -4.867l6.9 -1l3.086 -6.253l3.086 6.253l6.9 1l-5 4.867l1.179 6.873z" />
                          </svg>
                        ))}
                      </div>
                      <span className="place-views">( {place.views} {intl.formatMessage({ id: 'commentsLabel' })})</span>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}
        {/* Most visited places*/}
        {routingData && (
          <div className="routing-places-section">
            <div className="section-header">
              <h2 className="section-title6">
                {intl.formatMessage({ id: 'mostVisited' })}
              </h2>
              <button className="view-all-btn5">
                {intl.formatMessage({ id: 'viewAll' })}
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path d="M15 6l-6 6l6 6" />
                </svg>
              </button>
            </div>
            <div className="places-horizontal-list">
              {routingData.places.mostVisited.map((place, index) => (
                <div key={index} className="place-card">
                  <div className="image-container">
                    <div
                      className="place-image"
                      style={{ backgroundImage: `url(${place.image})` }}
                    ></div>
                    <button className="transparent-save-btn">
                      <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M7.5 4.37508C7.15482 4.37508 6.875 4.6549 6.875 5.00008C6.875 5.34526 7.15482 5.62508 7.5 5.62508H12.5C12.8452 5.62508 13.125 5.34526 13.125 5.00008C13.125 4.6549 12.8452 4.37508 12.5 4.37508H7.5Z" fill="black" />
                        <path fillRule="evenodd" clipRule="evenodd" d="M9.95209 1.04175C8.22495 1.04174 6.8643 1.04173 5.80107 1.18622C4.70935 1.33459 3.83841 1.64565 3.15403 2.33745C2.47058 3.02831 2.1641 3.90593 2.01775 5.00626C1.87498 6.07967 1.87499 7.45393 1.875 9.20097V13.4493C1.87499 14.7056 1.87498 15.7002 1.95501 16.4491C2.03409 17.1891 2.20373 17.8568 2.6882 18.3033C3.07688 18.6615 3.56842 18.8873 4.09304 18.9473C4.74927 19.0224 5.36199 18.7091 5.96557 18.2815C6.57636 17.8489 7.3173 17.1935 8.25212 16.3667L8.28254 16.3398C8.71593 15.9564 9.00935 15.6978 9.25416 15.5187C9.49076 15.3457 9.63522 15.2832 9.75698 15.2587C9.91743 15.2263 10.0826 15.2263 10.243 15.2587C10.3648 15.2832 10.5092 15.3457 10.7458 15.5187C10.9906 15.6978 11.2841 15.9564 11.7175 16.3398L11.7479 16.3667C12.6827 17.1935 13.4237 17.8489 14.0344 18.2815C14.638 18.7091 15.2507 19.0224 15.907 18.9473C16.4316 18.8873 16.9231 18.6615 17.3118 18.3033C17.7963 17.8568 17.9659 17.1891 18.045 16.4491C18.125 15.7002 18.125 14.7056 18.125 13.4493V9.20095C18.125 7.45393 18.125 6.07966 17.9823 5.00626C17.8359 3.90593 17.5294 3.02831 16.846 2.33745C16.1616 1.64565 15.2907 1.33459 14.1989 1.18622C13.1357 1.04173 11.7751 1.04174 10.0479 1.04175H9.95209ZM4.04267 3.21655C4.45664 2.7981 5.01876 2.55403 5.9694 2.42484C6.93871 2.2931 8.21438 2.29175 10 2.29175C11.7856 2.29175 13.0613 2.2931 14.0306 2.42484C14.9812 2.55403 15.5434 2.7981 15.9573 3.21655C16.3722 3.63594 16.6149 4.20691 16.7432 5.17106C16.8737 6.15251 16.875 7.44361 16.875 9.24801V13.4092C16.875 14.7144 16.8741 15.6419 16.8021 16.3163C16.7282 17.0074 16.592 17.2668 16.4647 17.3841C16.2699 17.5636 16.0248 17.6757 15.7649 17.7054C15.5985 17.7245 15.3196 17.66 14.757 17.2615C14.2081 16.8727 13.5176 16.2632 12.5456 15.4035L12.5238 15.3842C12.1177 15.025 11.781 14.7271 11.4837 14.5097C11.1728 14.2824 10.8594 14.1077 10.4899 14.0333C10.1665 13.9681 9.83352 13.9681 9.51015 14.0333C9.14064 14.1077 8.82715 14.2824 8.51633 14.5097C8.21902 14.7271 7.88226 15.025 7.47621 15.3842L7.45439 15.4035C6.48239 16.2632 5.79189 16.8727 5.24304 17.2615C4.68038 17.66 4.40151 17.7245 4.23515 17.7054C3.97516 17.6757 3.73014 17.5636 3.53531 17.3841C3.40803 17.2668 3.27179 17.0074 3.19793 16.3163C3.12587 15.6419 3.125 14.7144 3.125 13.4092V9.24801C3.125 7.44361 3.1263 6.15251 3.25684 5.17106C3.38508 4.20691 3.62777 3.63594 4.04267 3.21655Z" fill="black" />
                      </svg>
                    </button>
                  </div>
                  <div className="place-details">
                    <h4 className="place-name">{place.title}</h4>
                    <div className="place-meta">
                      <span className="place-distance">{place.distance} {intl.formatMessage({ id: 'meter' })}</span>
                      <span className="place-meta-separator">|</span>
                      <span className="place-time">{place.time} {intl.formatMessage({ id: 'walking' })}</span>
                    </div>
                    <div className="place-rating-section">
                      <div className="place-rating-stars">
                        {[1, 2, 3, 4, 5].map((star) => (
                          <svg
                            key={star}
                            className={star <= Math.round(place.rating) ? 'filled' : ''}
                            xmlns="http://www.w3.org/2000/svg"
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            fill="currentColor"
                          >
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M12 17.75l-6.172 3.245l1.179 -6.873l-5 -4.867l6.9 -1l3.086 -6.253l3.086 6.253l6.9 1l-5 4.867l1.179 6.873z" />
                          </svg>
                        ))}
                      </div>
                      <span className="place-views">( {place.views} {intl.formatMessage({ id: 'commentsLabel' })})</span>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}
        {/* Nearest Places*/}
        {routingData && (
          <div className="routing-places-section">
            <div className="section-header">
              <h2 className="section-title6">
                {intl.formatMessage({ id: 'nearMe' })}
              </h2>
              <button className="view-all-btn5">
                {intl.formatMessage({ id: 'viewAll' })}
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path d="M15 6l-6 6l6 6" />
                </svg>
              </button>
            </div>
            <div className="places-horizontal-list">
              {routingData.places.nearest.map((place, index) => (
                <div key={index} className="place-card">
                  <div className="image-container">
                    <div
                      className="place-image"
                      style={{ backgroundImage: `url(${place.image})` }}
                    ></div>
                    <button className="transparent-save-btn">
                      <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M7.5 4.37508C7.15482 4.37508 6.875 4.6549 6.875 5.00008C6.875 5.34526 7.15482 5.62508 7.5 5.62508H12.5C12.8452 5.62508 13.125 5.34526 13.125 5.00008C13.125 4.6549 12.8452 4.37508 12.5 4.37508H7.5Z" fill="black" />
                        <path fillRule="evenodd" clipRule="evenodd" d="M9.95209 1.04175C8.22495 1.04174 6.8643 1.04173 5.80107 1.18622C4.70935 1.33459 3.83841 1.64565 3.15403 2.33745C2.47058 3.02831 2.1641 3.90593 2.01775 5.00626C1.87498 6.07967 1.87499 7.45393 1.875 9.20097V13.4493C1.87499 14.7056 1.87498 15.7002 1.95501 16.4491C2.03409 17.1891 2.20373 17.8568 2.6882 18.3033C3.07688 18.6615 3.56842 18.8873 4.09304 18.9473C4.74927 19.0224 5.36199 18.7091 5.96557 18.2815C6.57636 17.8489 7.3173 17.1935 8.25212 16.3667L8.28254 16.3398C8.71593 15.9564 9.00935 15.6978 9.25416 15.5187C9.49076 15.3457 9.63522 15.2832 9.75698 15.2587C9.91743 15.2263 10.0826 15.2263 10.243 15.2587C10.3648 15.2832 10.5092 15.3457 10.7458 15.5187C10.9906 15.6978 11.2841 15.9564 11.7175 16.3398L11.7479 16.3667C12.6827 17.1935 13.4237 17.8489 14.0344 18.2815C14.638 18.7091 15.2507 19.0224 15.907 18.9473C16.4316 18.8873 16.9231 18.6615 17.3118 18.3033C17.7963 17.8568 17.9659 17.1891 18.045 16.4491C18.125 15.7002 18.125 14.7056 18.125 13.4493V9.20095C18.125 7.45393 18.125 6.07966 17.9823 5.00626C17.8359 3.90593 17.5294 3.02831 16.846 2.33745C16.1616 1.64565 15.2907 1.33459 14.1989 1.18622C13.1357 1.04173 11.7751 1.04174 10.0479 1.04175H9.95209ZM4.04267 3.21655C4.45664 2.7981 5.01876 2.55403 5.9694 2.42484C6.93871 2.2931 8.21438 2.29175 10 2.29175C11.7856 2.29175 13.0613 2.2931 14.0306 2.42484C14.9812 2.55403 15.5434 2.7981 15.9573 3.21655C16.3722 3.63594 16.6149 4.20691 16.7432 5.17106C16.8737 6.15251 16.875 7.44361 16.875 9.24801V13.4092C16.875 14.7144 16.8741 15.6419 16.8021 16.3163C16.7282 17.0074 16.592 17.2668 16.4647 17.3841C16.2699 17.5636 16.0248 17.6757 15.7649 17.7054C15.5985 17.7245 15.3196 17.66 14.757 17.2615C14.2081 16.8727 13.5176 16.2632 12.5456 15.4035L12.5238 15.3842C12.1177 15.025 11.781 14.7271 11.4837 14.5097C11.1728 14.2824 10.8594 14.1077 10.4899 14.0333C10.1665 13.9681 9.83352 13.9681 9.51015 14.0333C9.14064 14.1077 8.82715 14.2824 8.51633 14.5097C8.21902 14.7271 7.88226 15.025 7.47621 15.3842L7.45439 15.4035C6.48239 16.2632 5.79189 16.8727 5.24304 17.2615C4.68038 17.66 4.40151 17.7245 4.23515 17.7054C3.97516 17.6757 3.73014 17.5636 3.53531 17.3841C3.40803 17.2668 3.27179 17.0074 3.19793 16.3163C3.12587 15.6419 3.125 14.7144 3.125 13.4092V9.24801C3.125 7.44361 3.1263 6.15251 3.25684 5.17106C3.38508 4.20691 3.62777 3.63594 4.04267 3.21655Z" fill="black" />
                      </svg>
                    </button>
                  </div>
                  <div className="place-details">
                    <h4 className="place-name">{place.title}</h4>
                    <div className="place-meta">
                      <span className="place-distance">{place.distance} {intl.formatMessage({ id: 'meter' })}</span>
                      <span className="place-meta-separator">|</span>
                      <span className="place-time">{place.time} {intl.formatMessage({ id: 'walking' })}</span>
                    </div>
                    <div className="place-rating-section">
                      <div className="place-rating-stars">
                        {[1, 2, 3, 4, 5].map((star) => (
                          <svg
                            key={star}
                            className={star <= Math.round(place.rating) ? 'filled' : ''}
                            xmlns="http://www.w3.org/2000/svg"
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            fill="currentColor"
                          >
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M12 17.75l-6.172 3.245l1.179 -6.873l-5 -4.867l6.9 -1l3.086 -6.253l3.086 6.253l6.9 1l-5 4.867l1.179 6.873z" />
                          </svg>
                        ))}
                      </div>
                      <span className="place-views">( {place.views} {intl.formatMessage({ id: 'commentsLabel' })})</span>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default MapBeginPage; 