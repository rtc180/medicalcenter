/* Good Samaritan Medical Center - progressive enhancement (works with CSP script-src 'self') */
(function () {
  'use strict';
  document.documentElement.classList.remove('no-js');
  var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  /* Keep the mobile menu state and button accessibility label synchronized. */
  var toggle = document.querySelector('.menu-toggle');
  var nav = document.getElementById('site-nav');
  if (toggle && nav) {
    var setMenuOpen = function (open) {
      nav.classList.toggle('open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    toggle.addEventListener('click', function () {
      setMenuOpen(!nav.classList.contains('open'));
    });
    nav.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('a')) { setMenuOpen(false); }
    });
    document.addEventListener('click', function (e) {
      if (nav.classList.contains('open') && !nav.contains(e.target) && !toggle.contains(e.target)) {
        setMenuOpen(false);
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('open')) {
        setMenuOpen(false);
        toggle.focus();
      }
    });
  }

  /* Use a collapsible icon rail on desktop and a dismissible menu on smaller screens. */
  var staffToggles = document.querySelectorAll('.staff-menu-toggle, .staff-menu-open');
  var staffSidebar = document.querySelector('.sidebar');
  if (staffToggles.length && staffSidebar) {
    var staffApp = staffSidebar.closest('.app');
    var staffNav = document.getElementById('staff-navigation');
    var compactStaffMenu = window.matchMedia('(max-width: 1100px)');
    var staffMenuOpenButton = document.querySelector('.staff-menu-open');
    var staffMenuToggle = document.querySelector('.staff-menu-toggle');
    var setStaffMenu = function (open) {
      if (compactStaffMenu.matches) {
        staffSidebar.classList.toggle('menu-open', open);
        staffSidebar.inert = !open;
        staffNav.inert = !open;
        staffNav.setAttribute('aria-hidden', open ? 'false' : 'true');
        staffToggles.forEach(function (button) {
          button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        staffMenuToggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
        staffMenuOpenButton.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
        if (staffApp) { staffApp.classList.toggle('menu-open', open); }
      } else {
        staffSidebar.classList.remove('menu-open');
        staffSidebar.inert = false;
        staffNav.inert = false;
        staffNav.removeAttribute('aria-hidden');
        if (staffApp) { staffApp.classList.remove('menu-open'); }
        var expanded = !(staffApp && staffApp.classList.contains('sidebar-collapsed'));
        staffMenuToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        staffMenuToggle.setAttribute('aria-label', expanded ? 'Collapse navigation' : 'Expand navigation');
      }
    };
    setStaffMenu(false);
    staffToggles.forEach(function (button) {
      button.addEventListener('click', function () {
        if (compactStaffMenu.matches) {
          setStaffMenu(!staffSidebar.classList.contains('menu-open'));
        } else if (staffApp) {
          staffApp.classList.toggle('sidebar-collapsed');
          setStaffMenu(false);
        }
      });
    });
    staffSidebar.querySelectorAll('nav a').forEach(function (link) {
      link.addEventListener('click', function () {
        if (compactStaffMenu.matches) { setStaffMenu(false); }
      });
    });
    document.addEventListener('click', function (e) {
      if (compactStaffMenu.matches && staffSidebar.classList.contains('menu-open') &&
          !staffSidebar.contains(e.target) &&
          !Array.from(staffToggles).some(function (button) { return button.contains(e.target); })) {
        setStaffMenu(false);
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && compactStaffMenu.matches && staffSidebar.classList.contains('menu-open')) {
        setStaffMenu(false);
        staffMenuOpenButton.focus();
      }
    });
    compactStaffMenu.addEventListener('change', function () { setStaffMenu(false); });
  }

  document.querySelectorAll('.staff-notifications').forEach(function (menu) {
    document.addEventListener('click', function (e) {
      if (menu.open && !menu.contains(e.target)) { menu.open = false; }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu.open) {
        menu.open = false;
        menu.querySelector('summary').focus();
      }
    });
  });

  document.querySelectorAll('.user-menu').forEach(function (menu) {
    document.addEventListener('click', function (e) {
      if (menu.open && !menu.contains(e.target)) { menu.open = false; }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu.open) {
        menu.open = false;
        menu.querySelector('summary').focus();
      }
    });
  });

  /* Let native anchors handle hash updates and scrolling; add keyboard focus. */
  document.querySelectorAll('a[href^="#"]').forEach(function (a) {
    a.addEventListener('click', function () {
      var id = a.getAttribute('href');
      if (id.length < 2) { return; }
      var target = document.querySelector(id);
      if (!target) { return; }
      target.setAttribute('tabindex', '-1');
      target.focus({ preventScroll: true });
    });
  });

  /* Reveal on scroll */
  var items = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && !prefersReducedMotion.matches) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
      });
    }, { threshold: 0.12 });
    items.forEach(function (el) { io.observe(el); });
  } else {
    items.forEach(function (el) { el.classList.add('in'); });
  }

  /* Active nav link while scrolling */
  var links = document.querySelectorAll('.nav a[href^="#"]');
  if (links.length && 'IntersectionObserver' in window) {
    var map = {};
    links.forEach(function (l) { map[l.getAttribute('href').slice(1)] = l; });
    var spy = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting && map[en.target.id]) {
          links.forEach(function (l) { l.classList.remove('active'); });
          map[en.target.id].classList.add('active');
        }
      });
    }, { rootMargin: '-45% 0px -50% 0px' });
    Object.keys(map).forEach(function (id) { var s = document.getElementById(id); if (s) { spy.observe(s); } });
  }

  /* Back to top */
  var top = document.querySelector('.to-top');
  if (top) {
    window.addEventListener('scroll', function () {
      top.classList.toggle('show', window.scrollY > 500);
    }, { passive: true });
  }

  /* Confirm dangerous actions (replaces inline onclick, which CSP blocks) */
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('submit', function (e) {
      if (!window.confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); }
    });
  });

  /* Print receipt (inline onclick is blocked by CSP) */
  var pb = document.getElementById('print-btn');
  if (pb) { pb.addEventListener('click', function (e) { e.preventDefault(); window.print(); }); }
  document.querySelectorAll('[data-print]').forEach(function (button) {
    button.addEventListener('click', function () {
      var target = button.getAttribute('data-print-target');
      if (target) {
        document.body.classList.add('printing-target');
        document.body.setAttribute('data-print-target', target);
        window.addEventListener('afterprint', function () {
          document.body.classList.remove('printing-target');
          document.body.removeAttribute('data-print-target');
        }, { once: true });
      }
      window.print();
    });
  });

  /* Auto-refresh live queue boards */
  var live = document.querySelector('[data-refresh]');
  if (live) {
    setTimeout(function () { window.location.reload(); }, parseInt(live.getAttribute('data-refresh'), 10) * 1000);
  }
})();
