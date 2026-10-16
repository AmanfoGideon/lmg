document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.querySelector('.sidebar');
  const toggleBtn = document.getElementById('btnSidebarToggle');
  const BREAKPOINT = 992;

  const navOverlay = document.createElement('div');
  navOverlay.className = 'nav-overlay';
  document.body.appendChild(navOverlay);
  navOverlay.addEventListener('click', () => {
    if (sidebar) sidebar.classList.remove('temporary-open');
    navOverlay.classList.remove('show');
    if (toggleBtn) toggleBtn.innerHTML = '<i class="bi bi-list"></i>';
    document.body.classList.remove('no-scroll');
  });

  function applySidebarState() {
    if (!sidebar) return;
    const isMobile = window.innerWidth <= BREAKPOINT;
    if (isMobile) {
      sidebar.classList.remove('collapsed');
      sidebar.classList.remove('temporary-open');
      navOverlay.classList.remove('show');
      if (toggleBtn) {
        toggleBtn.innerHTML = '<i class="bi bi-list"></i>';
        toggleBtn.title = 'Menu';
        toggleBtn.setAttribute('aria-expanded', 'false');
      }
      document.body.classList.remove('no-scroll');
      return;
    }

    const collapsed = localStorage.getItem('sidebarCollapsed') === '1';
    sidebar.classList.toggle('collapsed', collapsed);
    
    // Handle tooltips for collapsed state
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
      const text = link.querySelector('.nav-text')?.textContent;
      if (collapsed) {
        link.setAttribute('title', text || '');
      } else {
        link.removeAttribute('title');
      }
    });

    if (toggleBtn) {
      toggleBtn.innerHTML = collapsed ? '<i class="bi bi-chevron-right"></i>' : '<i class="bi bi-chevron-left"></i>';
      toggleBtn.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
      toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }
  }

  if (toggleBtn) {
    toggleBtn.addEventListener('click', () => {
      const isMobile = window.innerWidth <= BREAKPOINT;
      if (isMobile) {
        const willOpen = !sidebar.classList.contains('temporary-open');
        sidebar.classList.toggle('temporary-open', willOpen);
        navOverlay.classList.toggle('show', willOpen);
        toggleBtn.innerHTML = willOpen ? '<i class="bi bi-x-lg"></i>' : '<i class="bi bi-list"></i>';
        toggleBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        document.body.classList.toggle('no-scroll', willOpen);
        return;
      }
      const current = localStorage.getItem('sidebarCollapsed') === '1';
      localStorage.setItem('sidebarCollapsed', current ? '0' : '1');
      applySidebarState();
    });
  }

  applySidebarState();

  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(applySidebarState, 120);
  });

  document.querySelectorAll('.menu-group-toggle').forEach(btn => {
    const group = btn.closest('.menu-group');
    const key = 'sidebarGroup:' + (btn.dataset.group || '');
    if (key && localStorage.getItem(key) === '1') group.classList.add('open');
    btn.addEventListener('click', () => {
      const willOpen = !group.classList.contains('open');
      group.classList.toggle('open', willOpen);
      if (key) localStorage.setItem(key, willOpen ? '1' : '0');
    });
  });

  const overlay = document.createElement('div');
  overlay.className = 'loader-overlay';
  overlay.innerHTML = '<div class="spinner-ring"></div>';
  document.body.appendChild(overlay);

  function showLoader() { overlay.classList.add('show'); }
  function hideLoader() { overlay.classList.remove('show'); }
  window.showLoader = showLoader;
  window.hideLoader = hideLoader;

  document.addEventListener('submit', () => showLoader());
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a');
    if (!a) return;
    const href = a.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript')) return;
    if (a.getAttribute('target') === '_blank') return;
    if (a.dataset.noLoader === 'true') return;
    showLoader();
  });

  window.addEventListener('pageshow', () => hideLoader());
  
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').then(()=>{
      window.addEventListener('online', () => {
        navigator.serviceWorker.ready.then(()=>{
          if (navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({ type: 'FLUSH_SMS_QUEUE' });
          }
        });
      });
    }).catch(()=>{});
  }
  let deferredPrompt;
  const installBtn = document.getElementById('btnInstallApp');
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    if (installBtn) installBtn.style.display = '';
  });
  window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    if (installBtn) installBtn.style.display = 'none';
  });
  if (installBtn) {
    installBtn.addEventListener('click', async () => {
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      try { await deferredPrompt.userChoice; } catch(_e){}
      deferredPrompt = null;
      installBtn.style.display = 'none';
    });
  }
});
