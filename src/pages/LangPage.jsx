import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { FormattedMessage, useIntl } from 'react-intl';
import logo from '../assets/images/logo.png';
import { useLangStore } from '../store/langStore';
import { fetchLanguages } from '../services/languageService';
import '../styles/LangPage.css';

const LangPage = () => {
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

  const handleLogin = () => {
    if (selectedLanguage) {
      const selected = languages.find((l) => l.id === selectedLanguage);
      if (selected) {
        const langCode = selected.code || selected.locale?.split('_')[0];
        if (langCode) {
          setLanguage(langCode);
        }
      }

      navigate('/mpb');
    }
  };

  return (
    <div className="lang-page-container">
      <img src={logo} alt={intl.formatMessage({ id: 'logoAlt' })} className="lang-logo" />

      <div className="lang-welcome-text">
        <h1 className="welcome-heading"><FormattedMessage id="welcome" /></h1>
        <p
          className="welcome-paragraph"
          dangerouslySetInnerHTML={{
            __html: intl.formatMessage({ id: 'selectLanguage' })
          }}
        />
      </div>

      <div className="lang-options-list">
        {loading && (
          <div className="lang-option">
            <div className="lang-text-container">
              <span className="lang-name">
                <FormattedMessage id="loading" defaultMessage="Loading..." />
              </span>
            </div>
          </div>
        )}
        {!loading && error && (
          <div className="lang-option">
            <div className="lang-text-container">
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
            <div className="lang-text-container">
              <span className={`lang-code lang-code-${lang.code?.toLowerCase()}`}>
                ({lang.code?.toUpperCase()})
              </span>
              <span className={`lang-name ${lang.direction === 'ltr' ? 'force-rtl' : ''}`}>
                {intl.locale === 'en' ? lang.english_name : lang.name}
              </span>
            </div>
          </div>
        ))}
      </div>

      <button
        className={`lang-login-btn ${!selectedLanguage ? 'disabled' : ''}`}
        disabled={!selectedLanguage}
        onClick={handleLogin}
      >
        <FormattedMessage id="loginButton" />
      </button>
    </div>
  );
};

export default LangPage;
