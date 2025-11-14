import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { FormattedMessage, useIntl } from 'react-intl';
import { useLangStore } from '../store/langStore';
import { fetchLanguages } from '../services/languageService';
import '../styles/Plang.css';

const Plang = () => {
  const [languages, setLanguages] = useState([]);
  const [selectedLanguage, setSelectedLanguage] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const navigate = useNavigate();

  const setLanguage = useLangStore((state) => state.setLanguage);
  const intl = useIntl();

  useEffect(() => {
    let isMounted = true;

    const loadLanguages = async () => {
      try {
        const result = await fetchLanguages();
        if (!isMounted) return;
        const fetchedLanguages = result?.data || [];
        setLanguages(fetchedLanguages);
        const defaultId = result?.meta?.defaultLanguageId || fetchedLanguages[0]?.id || null;
        setSelectedLanguage(defaultId);
      } catch (err) {
        if (isMounted) {
          setError('خطا در دریافت زبان‌ها');
        }
      } finally {
        if (isMounted) {
          setLoading(false);
        }
      }
    };

    loadLanguages();

    return () => {
      isMounted = false;
    };
  }, []);

  const handleConfirm = () => {
    if (selectedLanguage) {
      const selected = languages.find((l) => l.id === selectedLanguage);
      if (selected) {
        setLanguage(selected.code);
      }
      // Navigate back to profile page
      navigate('/profile');
    }
  };

  return (
    <div className="plang-container">
      {/* Back Button */}

      {/* Header Text */}
      <div className="plang-header">
        <button className="back-arrow11 " onClick={() => navigate(-1)}>
          <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M3.33301 10H16.6663M16.6663 10L11.6663 5M16.6663 10L11.6663 15" stroke="#1E2023" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
        </button>
        <h1 className="plang-heading"> <FormattedMessage id="plang.title" /> </h1>
        <div className="plang-description">
          <div>
            <h2> <FormattedMessage id="plang.subtitle" /> </h2>
            <p><FormattedMessage id="plang.description" /></p>
          </div>
        </div>
      </div>

      {/* Language Options */}
      <div className="lang-options-list2">
        {loading && (
          <div className="lang-option">
            <div className="lang-text-container2">
              <span className="lang-name">
                <FormattedMessage id="loading" defaultMessage="Loading..." />
              </span>
            </div>
          </div>
        )}
        {!loading && error && (
          <div className="lang-option">
            <div className="lang-text-container2">
              <span className="lang-name">{error}</span>
            </div>
          </div>
        )}
        {!loading && !error && languages.map((lang) => (
          <div
            key={lang.id}
            className={`lang-option ${selectedLanguage === lang.id ? 'selected' : ''}`}
            onClick={() => setSelectedLanguage(lang.id)}
          >
            <div className="selection-circle">
              {selectedLanguage === lang.id && <div className="inner-circle" />}
            </div>
            <div className="lang-text-container2">
              <span className={`lang-code lang-code-${lang.code?.toLowerCase()}`}>
                ({lang.code?.toUpperCase()})
              </span>
              <span className={`lang-name ${lang.code === 'en' ? 'force-rtl' : ''}`}>
                {intl.locale === 'en' ? lang.english_name : lang.name}
              </span>
            </div>
          </div>
        ))}
      </div>

      {/* Confirm Button */}
      <button
        id="confirmContinue"
        className={`plang-confirm-btn ${!selectedLanguage ? 'disabled' : ''}`}
        disabled={!selectedLanguage}
        onClick={handleConfirm}
      >
        <FormattedMessage id="confirmContinue" />
      </button>
    </div>
  );
};

export default Plang;