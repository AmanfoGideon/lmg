// Shared responsive sidebar controller for the SPA shell.
// Desktop: toggles a persisted collapsed (icon-only) rail.
// Mobile:  opens an off-canvas drawer with a backdrop.
const BREAKPOINT = 992;

export function initSidebar() {
  const sidebar = document.getElementById("appSidebar");
  const toggleBtn = document.getElementById("btnSidebarToggle");
  const overlay = document.querySelector(".nav-overlay");
  if (!sidebar || !toggleBtn) return;

  const isMobile = () => window.innerWidth <= BREAKPOINT;

  const applyTooltips = (collapsed) => {
    sidebar.querySelectorAll(".nav-link").forEach((link) => {
      const text = link.querySelector(".nav-text")?.textContent?.trim();
      if (collapsed && text) link.title = text;
      else link.removeAttribute("title");
    });
  };

  const closeDrawer = () => {
    sidebar.classList.remove("temporary-open");
    overlay?.classList.remove("show");
    document.body.classList.remove("no-scroll");
  };

  const applyDesktopState = () => {
    const collapsed = localStorage.getItem("sidebarCollapsed") === "1";
    sidebar.classList.toggle("collapsed", collapsed);
    applyTooltips(collapsed);
    toggleBtn.innerHTML = collapsed ? '<i class="bi bi-chevron-right"></i>' : '<i class="bi bi-chevron-left"></i>';
    toggleBtn.title = collapsed ? "Expand sidebar" : "Collapse sidebar";
    toggleBtn.setAttribute("aria-expanded", collapsed ? "false" : "true");
  };

  const applyState = () => {
    if (isMobile()) {
      sidebar.classList.remove("collapsed");
      closeDrawer();
      toggleBtn.innerHTML = '<i class="bi bi-list"></i>';
      toggleBtn.title = "Menu";
      toggleBtn.setAttribute("aria-expanded", "false");
      return;
    }
    document.body.classList.remove("no-scroll");
    applyDesktopState();
  };

  toggleBtn.addEventListener("click", () => {
    if (isMobile()) {
      const willOpen = !sidebar.classList.contains("temporary-open");
      sidebar.classList.toggle("temporary-open", willOpen);
      overlay?.classList.toggle("show", willOpen);
      document.body.classList.toggle("no-scroll", willOpen);
      toggleBtn.innerHTML = willOpen ? '<i class="bi bi-x-lg"></i>' : '<i class="bi bi-list"></i>';
      toggleBtn.setAttribute("aria-expanded", willOpen ? "true" : "false");
      return;
    }
    localStorage.setItem("sidebarCollapsed", localStorage.getItem("sidebarCollapsed") === "1" ? "0" : "1");
    applyDesktopState();
  });

  overlay?.addEventListener("click", closeDrawer);

  // Collapse the mobile drawer after a navigation choice.
  sidebar.querySelectorAll("[data-view-link]").forEach((link) => {
    link.addEventListener("click", () => {
      if (isMobile()) closeDrawer();
    });
  });

  let resizeTimer;
  window.addEventListener("resize", () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(applyState, 120);
  });

  applyState();
}
