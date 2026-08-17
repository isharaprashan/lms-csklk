(function () {
  const urlParams = new URLSearchParams(window.location.search);
  let currentSid = urlParams.get('sid');
  let isAdminPreview = urlParams.has('admin_preview');

  if (isAdminPreview) {
    sessionStorage.setItem('lms_admin_preview', '1');
  } else if (sessionStorage.getItem('lms_admin_preview') === '1') {
    isAdminPreview = true;
  }

  // Handle logout page
  if (window.location.pathname.endsWith('logout.php')) {
    sessionStorage.removeItem('lms_sid');
    sessionStorage.removeItem('lms_admin_preview');
  } else {
    if (currentSid) {
      sessionStorage.setItem('lms_sid', currentSid);
    } else {
      currentSid = sessionStorage.getItem('lms_sid');
      if (currentSid && !window.location.search.includes('sid=')) {
        const url = new URL(window.location.href);
        url.searchParams.set('sid', currentSid);
        if (isAdminPreview) url.searchParams.set('admin_preview', '1');
        window.history.replaceState({}, '', url.toString());
      }
    }
  }

  function getActiveSid() {
    return sessionStorage.getItem('lms_sid') || '';
  }

  // Intercept internal link clicks to append sid and admin_preview
  document.addEventListener('click', function (e) {
    const anchor = e.target.closest('a');
    if (!anchor) return;

    const href = anchor.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('http://') || href.startsWith('https://') || href.startsWith('mailto:')) {
      return;
    }

    const sid = getActiveSid();
    const adminPrev = sessionStorage.getItem('lms_admin_preview') === '1';

    try {
      const targetUrl = new URL(anchor.href, window.location.origin);
      if (targetUrl.origin === window.location.origin) {
        if (sid && !targetUrl.searchParams.has('sid')) {
          targetUrl.searchParams.set('sid', sid);
        }
        if (adminPrev && !targetUrl.searchParams.has('admin_preview')) {
          targetUrl.searchParams.set('admin_preview', '1');
        }
        anchor.href = targetUrl.toString();
      }
    } catch (err) {}
  }, true);

  // Intercept form submissions to inject sid and admin_preview hidden inputs
  document.addEventListener('submit', function (e) {
    const form = e.target;
    const sid = getActiveSid();
    const adminPrev = sessionStorage.getItem('lms_admin_preview') === '1';

    if (sid && !form.querySelector('input[name="sid"]')) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'sid';
      input.value = sid;
      form.appendChild(input);
    }
    if (adminPrev && !form.querySelector('input[name="admin_preview"]')) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'admin_preview';
      input.value = '1';
      form.appendChild(input);
    }
  }, true);

  // Intercept window.fetch to automatically include sid and admin_preview in API calls
  const originalFetch = window.fetch;
  window.fetch = function (resource, config) {
    const sid = getActiveSid();
    const adminPrev = sessionStorage.getItem('lms_admin_preview') === '1';

    if (sid || adminPrev) {
      config = config || {};
      config.headers = config.headers || {};
      
      if (sid) {
        if (config.headers instanceof Headers) {
          config.headers.set('X-Session-ID', sid);
        } else {
          config.headers['X-Session-ID'] = sid;
        }
      }

      if (typeof resource === 'string') {
        try {
          const fetchUrl = new URL(resource, window.location.href);
          if (fetchUrl.origin === window.location.origin) {
            if (sid && !fetchUrl.searchParams.has('sid')) {
              fetchUrl.searchParams.set('sid', sid);
            }
            if (adminPrev && !fetchUrl.searchParams.has('admin_preview')) {
              fetchUrl.searchParams.set('admin_preview', '1');
            }
            resource = fetchUrl.toString();
          }
        } catch (err) {}
      } else if (resource instanceof Request) {
        try {
          const fetchUrl = new URL(resource.url, window.location.href);
          if (fetchUrl.origin === window.location.origin) {
            if (sid && !fetchUrl.searchParams.has('sid')) {
              fetchUrl.searchParams.set('sid', sid);
            }
            if (adminPrev && !fetchUrl.searchParams.has('admin_preview')) {
              fetchUrl.searchParams.set('admin_preview', '1');
            }
            resource = new Request(fetchUrl.toString(), resource);
          }
        } catch (err) {}
      }
    }
    return originalFetch.call(this, resource, config);
  };
})();
