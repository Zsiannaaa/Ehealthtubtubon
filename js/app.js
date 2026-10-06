/**
 * app.js
 * Tubtubon eHealth - SYNTHETIC DEMO DATA
 * Main application controller (vanilla JS, no backend).
 * All data lives in JS memory + localStorage.
 */

"use strict";

// ═══════════════════════════════════════════════════
//  STATE
// ═══════════════════════════════════════════════════
const AppState = {
  currentRole: "Administrator",
  currentPage: "dashboard",
  currentUser: "Demo Admin",
  patients:    [],
  serviceRecords: [],
  documents:   [],
  invItems:    [],
  invBatches:  [],
  invLedger:   [],
  appointments:[],
  smsLogs:     [],
  ocrRejected: [],
  auditLogs:   [],
  roleStatus:  {}, // role -> 'Active' | 'Deactivated' (BR-11 demo)

  // counters for generated IDs
  _srCounter: 9,
  _docCounter: 8,
  _aptCounter: 11,
  _smsCounter: 4,
  _ledCounter: 9,
  _audCounter: 11,
};

// ═══════════════════════════════════════════════════
//  INIT
// ═══════════════════════════════════════════════════
document.addEventListener("DOMContentLoaded", () => {
  // Deep-copy mock data into state so edits don't corrupt originals
  AppState.patients       = JSON.parse(JSON.stringify(window.MOCK_PATIENTS));
  AppState.serviceRecords = JSON.parse(JSON.stringify(window.MOCK_SERVICE_RECORDS));
  AppState.documents      = JSON.parse(JSON.stringify(window.MOCK_DOCUMENTS));
  AppState.invItems       = JSON.parse(JSON.stringify(window.MOCK_INVENTORY_ITEMS));
  AppState.invBatches     = JSON.parse(JSON.stringify(window.MOCK_INVENTORY_BATCHES));
  AppState.invLedger      = JSON.parse(JSON.stringify(window.MOCK_INVENTORY_LEDGER));
  AppState.appointments   = JSON.parse(JSON.stringify(window.MOCK_APPOINTMENTS));
  AppState.smsLogs        = JSON.parse(JSON.stringify(window.MOCK_SMS_LOGS));
  AppState.ocrRejected    = JSON.parse(JSON.stringify(window.MOCK_OCR_REJECTED));
  AppState.auditLogs      = JSON.parse(JSON.stringify(window.MOCK_AUDIT_LOGS));

  setupLoginPage();
  setupTopbarSearch();
  refreshIcons();
  setTimeout(showPreviewNotice, 600);
  console.log("[app.js] Tubtubon eHealth ready. SYNTHETIC DEMO DATA.");
});

// ═══════════════════════════════════════════════════
//  LOGIN
// ═══════════════════════════════════════════════════
function setupLoginPage() {
  const form = document.getElementById("login-form");
  if (!form) return;

  // Demo role rows fill the role picker
  document.querySelectorAll(".demo-details tr[data-role]").forEach(row => {
    row.addEventListener("click", () => {
      document.getElementById("login-role").value = row.dataset.role;
    });
  });

  form.addEventListener("submit", (e) => {
    e.preventDefault();
    const username = document.getElementById("login-username").value.trim() || "Demo User";
    const role     = document.getElementById("login-role").value;

    if (!role) {
      showToast("Please select a role.", "warning");
      return;
    }

    if (roleStatusOf(role) === "Deactivated") {
      addAuditLog(username, "LOGIN", `Blocked sign-in as deactivated role: ${role}`, "127.0.0.1", "-");
      showToast(`The <strong>${role}</strong> role is deactivated and cannot sign in.`, "error");
      return;
    }

    AppState.currentRole = role;
    AppState.currentUser = username;

    // Audit login
    addAuditLog(username, "LOGIN", `Logged in as ${role}`, "127.0.0.1", "-");

    bootApp();
  });
}

function bootApp() {
  document.getElementById("login-page").style.display  = "none";
  document.getElementById("app-shell").style.display   = "block";

  buildSidebar();
  updateTopbarRole();

  // Role-based landing page
  const roleMenus = window.ROLES[AppState.currentRole]?.menu || [];
  const landing   = AppState.currentRole === "Resident" ? "resident-portal" : (roleMenus[0] || "dashboard");
  navigateTo(landing);
  refreshIcons();
  showPreviewNotice();
}

window.doLogout = function() {
  document.getElementById("login-page").style.display = "flex";
  document.getElementById("app-shell").style.display  = "none";
  const oc = document.getElementById("mobileSidebar");
  if (oc && window.bootstrap) bootstrap.Offcanvas.getInstance(oc)?.hide();
};

// ═══════════════════════════════════════════════════
//  SIDEBAR
// ═══════════════════════════════════════════════════
// Sidebar sections grouping the 12 paper-accurate screens
const SIDEBAR_SECTIONS = [
  { label: "General",    items: ["dashboard", "patients", "service-entry", "scheduling"] },
  { label: "Management", items: ["documents", "templates", "inventory", "stock-moves", "sms", "ocr"] },
  { label: "Insights",   items: ["reports"] },
  { label: "Account",    items: ["users", "resident-portal"] }
];

function buildSidebar() {
  const allowedMenus = window.ROLES[AppState.currentRole]?.menu || [];
  const itemById = Object.fromEntries(window.MENU_ITEMS.map(m => [m.id, m]));

  ["sidebar-nav", "mobileSidebarNav"].forEach(navId => {
    const nav = document.getElementById(navId);
    if (!nav) return;
    nav.innerHTML = "";

    SIDEBAR_SECTIONS.forEach(section => {
      const visible = section.items.filter(id => allowedMenus.includes(id));
      if (!visible.length) return;

      const label = document.createElement("div");
      label.className = "nav-section-label";
      label.textContent = section.label;
      nav.appendChild(label);

      visible.forEach(id => {
        const item = itemById[id];
        const a = document.createElement("a");
        a.className = "side-link";
        a.dataset.page = id;
        a.title = item.label;
        a.innerHTML = `<i data-lucide="${item.icon}"></i><span>${item.label}</span>`;
        a.addEventListener("click", (e) => {
          e.preventDefault();
          closeMobileSidebar();
          navigateTo(id);
        });
        nav.appendChild(a);
      });
    });

    const foot = document.createElement("div");
    foot.className = "sidebar-foot";
    foot.style.cssText = "padding:14px 10px 0;font-size:.68rem;color:#575c66;";
    foot.innerHTML = `<div>Tubtubon Health Center</div><div>Brgy. Tubtubon, Negros Oriental</div>`;
    nav.appendChild(foot);
  });

  refreshIcons();
}

function updateTopbarRole() {
  const chip = document.getElementById("topbar-user-badge");
  if (chip) {
    const name = AppState.currentUser || "Demo User";
    const initials = name.trim().split(/\s+/).map(w => w[0]).join("").slice(0, 2).toUpperCase();
    chip.innerHTML = `<span class="user-avatar">${initials}</span><span class="user-meta"><span class="user-name">${name}</span><span class="user-role">${AppState.currentRole}</span></span>`;
  }

  // Demo role list inside the user dropdown (removed in final product)
  const menu = document.getElementById("roleMenu");
  if (menu) {
    menu.innerHTML =
      Object.keys(window.ROLES).map(r => {
        const off = roleStatusOf(r) === "Deactivated";
        return `<li><a class="dropdown-item${r === AppState.currentRole ? " active" : ""}${off ? " disabled" : ""}" data-role="${r}"><i data-lucide="circle-user-round"></i>${r}${off ? " (deactivated)" : ""}</a></li>`;
      }).join("") +
      `<li><div class="role-note">Demo only — role switching will be removed in the final product.</div></li>
       <li><hr class="dropdown-divider"></li>
       <li><a class="dropdown-item" id="menu-logout"><i data-lucide="log-out"></i>Sign Out</a></li>`;
    menu.querySelectorAll("[data-role]").forEach(a =>
      a.addEventListener("click", () => switchRole(a.dataset.role))
    );
    menu.querySelector("#menu-logout")?.addEventListener("click", () => doLogout());
  }

  updateBell();
  refreshIcons();
}

function updateBell() {
  const badge = document.getElementById("bellBadge");
  if (!badge) return;
  const today = new Date().toISOString().split("T")[0];
  const count = AppState.appointments.filter(a => a.scheduled_date === today && a.status === "Scheduled").length;
  badge.textContent = count;
  badge.classList.toggle("d-none", count === 0);
}

// Role switcher from user dropdown (demo only)
window.switchRole = function(role) {
  if (roleStatusOf(role) === "Deactivated") {
    showToast(`The <strong>${role}</strong> role is deactivated.`, "error");
    return;
  }
  AppState.currentRole = role;
  AppState.currentUser = `Demo ${role}`;
  addAuditLog(AppState.currentUser, "ROLE_SWITCH", `Switched to role: ${role}`, "127.0.0.1", "-");
  buildSidebar();
  updateTopbarRole();

  const roleMenus = window.ROLES[AppState.currentRole]?.menu || [];
  const landing   = AppState.currentRole === "Resident" ? "resident-portal" : (roleMenus[0] || "dashboard");
  navigateTo(landing);
  showToast(`Switched to role: <strong>${role}</strong>`, "info");
  showPreviewNotice();
};

// Topbar titles per screen
const PAGE_META = {
  "dashboard":     ["Dashboard", "Here's what's happening at the health center today."],
  "patients":      ["Patient Registry", "Search, filter and manage registered patients."],
  "service-entry": ["Service Record Entry", "Encode services by program."],
  "documents":     ["Document Registry", "Track filed folders and digitized scans."],
  "templates":     ["Printable Templates", "Blank forms for paper fallback."],
  "inventory":     ["Inventory Dashboard", "Stock levels, thresholds and expiry."],
  "stock-moves":   ["Stock Movements", "Stock-in, stock-out and disposals."],
  "scheduling":    ["Scheduling Calendar", "Appointments and follow-up visits."],
  "sms":           ["SMS Compose & Logs", "Privacy-safe reminders with delivery log."],
  "ocr":           ["OCR / Digitization", "Assisted draft review with verification."],
  "reports":       ["Reports & Backup", "Reports, audit trail and backup."],
  "users":         ["Users & Roles", "Demo accounts, status and deactivation."],
  "resident-portal": ["My Health Portal", "Your own health information."]
};

// ═══════════════════════════════════════════════════
//  NAVIGATION
// ═══════════════════════════════════════════════════
function navigateTo(pageId) {
  const allowedMenus = window.ROLES[AppState.currentRole]?.menu || [];

  // Topbar title + subtitle
  const meta = PAGE_META[pageId] || [pageId, ""];
  const titleEl = document.getElementById("pageTitle");
  const subEl = document.getElementById("pageSubtitle");
  if (titleEl) titleEl.textContent = meta[0];
  if (subEl) subEl.textContent = meta[1];

  // Update sidebar active state
  document.querySelectorAll(".side-link[data-page]").forEach(el => {
    el.classList.toggle("active", el.dataset.page === pageId);
  });

  // Hide all pages
  document.querySelectorAll(".page-section").forEach(el => el.classList.remove("active"));

  // Access control
  if (!allowedMenus.includes(pageId)) {
    document.getElementById("page-access-denied").classList.add("active");
    AppState.currentPage = "access-denied";
    return;
  }

  AppState.currentPage = pageId;
  const target = document.getElementById(`page-${pageId}`);
  if (target) {
    target.classList.add("active");
    renderPage(pageId);
  }

  // Close mobile sidebar
  closeMobileSidebar();
  applyRoleCaps();
  refreshIcons();
}

function renderPage(pageId) {
  switch (pageId) {
    case "dashboard":       renderDashboard(); break;
    case "patients":        renderPatients(); break;
    case "service-entry":   renderServiceEntry(); break;
    case "documents":       renderDocuments(); break;
    case "templates":       renderTemplates(); break;
    case "inventory":       renderInventory(); break;
    case "stock-moves":     renderStockMoves(); break;
    case "scheduling":      renderScheduling(); break;
    case "sms":             renderSMS(); break;
    case "ocr":             renderOCR(); break;
    case "reports":         renderReports(); break;
    case "users":           renderUsers(); break;
    case "resident-portal": renderResidentPortal(); break;
  }
}

// ═══════════════════════════════════════════════════
//  ROLE CAPABILITIES (Table 8-6: Admin view-only, Nurse sends SMS)
// ═══════════════════════════════════════════════════
function roleStatusOf(role) {
  return AppState.roleStatus[role] || "Active";
}

function applyRoleCaps() {
  const role  = AppState.currentRole;
  const admin = role === "Administrator";
  const nurse = role === "Nurse/Midwife";
  const setVis = (id, show) => {
    const el = document.getElementById(id);
    if (el) el.classList.toggle("d-none", !show);
  };
  setVis("btn-add-patient", !admin);
  setVis("btn-save-sr", !admin);
  setVis("sr-readonly-note", admin && AppState.currentPage === "service-entry");
  setVis("btn-add-doc", !admin);
  setVis("btn-add-apt", !admin);
  setVis("btn-print-template", !admin);
  const send = document.getElementById("btn-send-sms");
  if (send) send.disabled = !nurse;
  const note = document.getElementById("sms-policy-note");
  if (note) note.classList.toggle("d-none", nurse || AppState.currentPage !== "sms");
}

// ═══════════════════════════════════════════════════
//  USERS & ROLES (Admin only, FR-04 / BR-11)
// ═══════════════════════════════════════════════════
const ROLE_ACCESS = {
  "Administrator": "Users, audit, backup + view all",
  "Nurse/Midwife": "Clinical records, verify, schedules, SMS",
  "Barangay Health Worker": "Register, encode, assist scheduling",
  "Inventory/Admin Personnel": "Stock + inventory reports",
  "Resident": "Own data only"
};

function renderUsers() {
  const tbody = document.getElementById("users-tbody");
  if (!tbody) return;
  tbody.innerHTML = Object.keys(window.ROLES).map(r => {
    const st = roleStatusOf(r);
    const locked = r === "Administrator";
    return `
      <tr>
        <td><strong>${r}</strong></td>
        <td>${ROLE_ACCESS[r] || "—"}</td>
        <td>${st === "Active" ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Deactivated</span>'}</td>
        <td class="text-end">
          ${locked
            ? '<span class="text-muted" style="font-size:.78rem">Protected</span>'
            : `<button class="btn btn-sm btn-light-ghost" onclick="toggleRoleStatus('${r}')">${st === "Active" ? "Deactivate" : "Activate"}</button>`}
        </td>
      </tr>`;
  }).join("");
  refreshIcons();
}

window.toggleRoleStatus = function(role) {
  if (role === "Administrator") {
    showToast("The Administrator role cannot be deactivated in this demo.", "warning");
    return;
  }
  const next = roleStatusOf(role) === "Active" ? "Deactivated" : "Active";
  AppState.roleStatus[role] = next;
  addAuditLog(AppState.currentUser, "USER_STATUS", `Role ${role} status set to ${next}`, "127.0.0.1", role);
  showToast(`Role <strong>${role}</strong> is now <strong>${next}</strong>.`, next === "Active" ? "success" : "warning");
  renderUsers();
};

// ═══════════════════════════════════════════════════
//  TOPBAR SEARCH + MOBILE SIDEBAR
// ═══════════════════════════════════════════════════
function setupTopbarSearch() {
  const collapseBtn = document.getElementById("sidebarCollapseBtn");
  if (collapseBtn) {
    collapseBtn.addEventListener("click", () => {
      document.body.classList.toggle("nav-collapsed");
    });
  }

  const input = document.getElementById("topbar-search");
  if (!input) return;
  input.addEventListener("input", () => {
    if (AppState.currentPage !== "patients") navigateTo("patients");
    const target = document.getElementById("patient-search");
    if (target) {
      target.value = input.value;
      target.dispatchEvent(new Event("input", { bubbles: true }));
    }
  });
}

function closeMobileSidebar() {
  const oc = document.getElementById("mobileSidebar");
  if (oc && window.bootstrap) bootstrap.Offcanvas.getInstance(oc)?.hide();
}

// ═══════════════════════════════════════════════════
//  TOAST
// ═══════════════════════════════════════════════════
function showToast(message, type = "success", duration = 3500) {
  const container = document.getElementById("toast-container");
  if (!container) return;

  const icons = { success: "circle-check", error: "circle-x", info: "info", warning: "triangle-alert" };
  const toast = document.createElement("div");
  toast.className = `toast-item toast-${type}`;
  toast.innerHTML = `<i data-lucide="${icons[type] || icons.info}"></i><span>${message}</span>`;

  container.appendChild(toast);
  refreshIcons();
  setTimeout(() => {
    toast.style.opacity = "0";
    toast.style.transition = "opacity 0.4s";
    setTimeout(() => toast.remove(), 400);
  }, duration);
}

// Preview disclaimer (adviser safety: frontend mockup, subject to change)
function showPreviewNotice() {
  const el = document.getElementById("modal-preview-notice");
  if (!el || !window.bootstrap) return;
  const m = bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el);
  m.show();
}

// ═══════════════════════════════════════════════════
//  AUDIT LOG
// ═══════════════════════════════════════════════════
function addAuditLog(actor, action, description, ip, recordRef) {
  AppState._audCounter++;
  AppState.auditLogs.unshift({
    id: `AUD-${String(AppState._audCounter).padStart(3,"0")}`,
    actor: actor || AppState.currentUser,
    action,
    description,
    ip: ip || "127.0.0.1",
    record_ref: recordRef || "-",
    timestamp: new Date().toISOString()
  });
}

// ═══════════════════════════════════════════════════
//  HELPERS
// ═══════════════════════════════════════════════════
function refreshIcons() {
  if (window.lucide) lucide.createIcons();
}
function fmtDate(str) {
  if (!str) return "—";
  const d = new Date(str);
  if (isNaN(d)) return str;
  return d.toLocaleDateString("en-PH", { year:"numeric", month:"short", day:"numeric" });
}

function fmtDateTime(str) {
  if (!str) return "—";
  const d = new Date(str);
  if (isNaN(d)) return str;
  return d.toLocaleString("en-PH", { year:"numeric", month:"short", day:"numeric", hour:"2-digit", minute:"2-digit" });
}

function calcAge(birthdateStr) {
  if (!birthdateStr) return "—";
  const bd = new Date(birthdateStr);
  const today = new Date();
  let age = today.getFullYear() - bd.getFullYear();
  const m = today.getMonth() - bd.getMonth();
  if (m < 0 || (m === 0 && today.getDate() < bd.getDate())) age--;
  return age;
}

function programBadgeColor(program) {
  const map = {
    "Family Planning":       "#0E4D2B",
    "Maternal/Prenatal":     "#3565c0",
    "Child Health-ECCD":     "#6a51c2",
    "Immunization":          "#15864c",
    "Laboratory Referral":   "#b26a1b",
    "General Consultation":  "#667085"
  };
  return map[program] || "#5F6F64";
}

function getBatchItemQty(itemId) {
  return AppState.invBatches
    .filter(b => b.item_id === itemId)
    .reduce((sum, b) => sum + b.quantity, 0);
}

function isNearExpiry(expiryDateStr, daysThreshold = 90) {
  if (!expiryDateStr) return false;
  const d = new Date(expiryDateStr);
  if (isNaN(d)) return false;
  const now = new Date();
  const diff = (d - now) / (1000 * 60 * 60 * 24);
  return diff >= 0 && diff <= daysThreshold;
}

function isExpired(expiryDateStr) {
  if (!expiryDateStr) return false;
  const d = new Date(expiryDateStr);
  if (isNaN(d)) return false;
  return d < new Date();
}

function fmtExpiry(str) {
  if (!str) return "Non-expiring";
  return fmtDate(str);
}

function genId(prefix, counter) {
  return `${prefix}-${String(counter).padStart(3,"0")}`;
}

function getPatientByUpi(upi) {
  return AppState.patients.find(p => p.upi === upi);
}

function patientFullName(p) {
  if (!p) return "—";
  return `${p.last_name}, ${p.first_name} ${p.middle_name ? p.middle_name[0]+"." : ""}`.trim();
}

// ═══════════════════════════════════════════════════
//  S1 - DASHBOARD
// ═══════════════════════════════════════════════════
let dashCharts = {};

function renderDashboard() {
  const totalPatients = AppState.patients.length;
  const totalRecords  = AppState.serviceRecords.length;
  const today = new Date().toISOString().split("T")[0];
  const todayApts = AppState.appointments.filter(a => a.scheduled_date === today).length;
  const lowStockCount = AppState.invItems.filter(item => {
    return getBatchItemQty(item.id) <= item.minimum_stock_threshold;
  }).length;

  document.getElementById("dash-stat-patients").textContent  = totalPatients;
  document.getElementById("dash-stat-records").textContent   = totalRecords;
  document.getElementById("dash-stat-apts").textContent      = todayApts;
  document.getElementById("dash-stat-lowstock").textContent  = lowStockCount;

  const chip = document.getElementById("dash-lowstock-chip");
  if (chip) chip.innerHTML = lowStockCount > 0
    ? `<span class="chip-action-needed">action needed</span>`
    : `<span class="chip-allgood">all good</span>`;

  renderDashCharts();
  renderRecentActivity();
}

function renderDashCharts() {
  // Visits per program
  const programs = ["Family Planning","Maternal/Prenatal","Child Health-ECCD","Immunization","Laboratory Referral","General Consultation"];
  const visitCounts = programs.map(p => AppState.serviceRecords.filter(r => r.program_type === p).length);

  const ctx1 = document.getElementById("chart-visits");
  if (ctx1) {
    if (dashCharts.visits) dashCharts.visits.destroy();
    dashCharts.visits = new Chart(ctx1, {
      type: "bar",
      data: {
        labels: programs.map(p => p.replace(" ", "\n")),
        datasets: [{
          label: "Service Records",
          data: visitCounts,
          backgroundColor: programs.map(p => programBadgeColor(p) + "cc"),
          borderColor:     programs.map(p => programBadgeColor(p)),
          borderWidth: 2,
          borderRadius: 6
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
      }
    });
  }

  // Appointments by status
  const apStatus = ["Scheduled","Completed","Cancelled","No-Show"];
  const apCounts  = apStatus.map(s => AppState.appointments.filter(a => a.status === s).length);
  const ctx2 = document.getElementById("chart-apts");
  if (ctx2) {
    if (dashCharts.apts) dashCharts.apts.destroy();
    dashCharts.apts = new Chart(ctx2, {
      type: "doughnut",
      data: {
        labels: apStatus,
        datasets: [{
          data: apCounts,
          backgroundColor: ["#3565c0","#15864c","#8a8f98","#bb3a52"],
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: "bottom", labels: { font: { size: 12 } } } }
      }
    });
  }

  // Stock levels
  const stockLabels = AppState.invItems.map(i => i.name.length > 18 ? i.name.slice(0,18)+"…" : i.name);
  const stockQtys   = AppState.invItems.map(i => getBatchItemQty(i.id));
  const stockMin    = AppState.invItems.map(i => i.minimum_stock_threshold);

  const ctx3 = document.getElementById("chart-stock");
  if (ctx3) {
    if (dashCharts.stock) dashCharts.stock.destroy();
    dashCharts.stock = new Chart(ctx3, {
      type: "bar",
      data: {
        labels: stockLabels,
        datasets: [
          {
            label: "Current Stock",
            data: stockQtys,
            backgroundColor: stockQtys.map((q,i) => q <= stockMin[i] ? "#bb3a5233" : "#0E4D2B55"),
            borderColor:     stockQtys.map((q,i) => q <= stockMin[i] ? "#bb3a52" : "#2e9e6b"),
            borderWidth: 1,
            borderRadius: 3
          },
          {
            label: "Min. Threshold",
            data: stockMin,
            type: "line",
            borderColor: "#8A5A00",
            backgroundColor: "transparent",
            borderWidth: 2,
            borderDash: [5,3],
            pointRadius: 4,
            pointBackgroundColor: "#8A5A00"
          }
        ]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: "bottom" } },
        scales: { y: { beginAtZero: true } }
      }
    });
  }
}

function renderRecentActivity() {
  const tbody = document.getElementById("dash-recent-tbody");
  if (!tbody) return;
  const recent = AppState.auditLogs.slice(0, 8);
  tbody.innerHTML = recent.map(a => `
    <tr>
      <td>${fmtDateTime(a.timestamp)}</td>
      <td><strong>${a.actor}</strong></td>
      <td><span class="badge bg-secondary">${a.action}</span></td>
      <td>${a.description}</td>
    </tr>
  `).join("");
}

// ═══════════════════════════════════════════════════
//  S2 - PATIENT REGISTRY
// ═══════════════════════════════════════════════════
function renderPatients() {
  const searchInput = document.getElementById("patient-search");
  if (searchInput) {
    searchInput.value = "";
    searchInput.removeEventListener("input", patientSearchHandler);
    searchInput.addEventListener("input", patientSearchHandler);
  }
  displayPatientTable(AppState.patients);
}

function patientSearchHandler(e) {
  const q = e.target.value.trim().toLowerCase();
  if (!q) {
    displayPatientTable(AppState.patients);
    return;
  }
  const filtered = AppState.patients.filter(p => {
    return p.upi.toLowerCase().includes(q) ||
      p.last_name.toLowerCase().includes(q) ||
      p.first_name.toLowerCase().includes(q) ||
      (p.purok || "").toLowerCase().includes(q);
  });
  displayPatientTable(filtered);
}

function displayPatientTable(patients) {
  const tbody = document.getElementById("patient-tbody");
  if (!tbody) return;

  if (!patients.length) {
    tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-3">No patients found.</td></tr>`;
    return;
  }

  tbody.innerHTML = patients.map(p => `
    <tr onclick="showPatientDetail('${p.upi}')" style="cursor:pointer;">
      <td><span class="upi-chip">${p.upi}</span></td>
      <td><strong>${p.last_name}</strong>, ${p.first_name}</td>
      <td>${p.sex}</td>
      <td>${fmtDate(p.birthdate)} <small class="text-muted">(${calcAge(p.birthdate)}y)</small></td>
      <td>${p.purok}</td>
      <td>${p.contact}</td>
      <td>
        ${p.consent_sms ? '<span class="badge bg-success">SMS Consent</span>' : '<span class="badge bg-secondary">No SMS Consent</span>'}
      </td>
    </tr>
  `).join("");
}

window.showPatientDetail = function(upi) {
  const p = getPatientByUpi(upi);
  if (!p) return;

  // Unified history
  const records = AppState.serviceRecords.filter(r => r.upi === upi);
  const docs    = AppState.documents.filter(d => d.upi === upi);
  const apts    = AppState.appointments.filter(a => a.upi === upi);

  const historyHtml = records.length === 0 && docs.length === 0 && apts.length === 0
    ? `<p class="text-muted">No records found for this patient.</p>`
    : [
      ...records.map(r => `
        <div class="history-entry">
          <span class="program-tag" style="background:${programBadgeColor(r.program_type)}22;color:${programBadgeColor(r.program_type)}">${r.program_type}</span>
          <div class="fw-700">${fmtDate(r.service_date)} &mdash; Ref: ${r.form_reference_no}</div>
          <div class="text-muted" style="font-size:0.9rem;">${r.findings_notes}</div>
          <div style="font-size:0.82rem;color:#6c757d;margin-top:3px;">Recorded by: ${r.recorded_by} &bull; Verified: ${r.verified_by}</div>
        </div>
      `),
      ...docs.map(d => `
        <div class="history-entry" style="border-left-color:#8A5A00;">
          <span class="program-tag">Document</span>
          <div class="fw-700">${d.title}</div>
          <div>Status: <strong>${d.status}</strong> &bull; Location: ${d.cabinet_location} / ${d.box_location} / ${d.folder_location}</div>
          ${d.file_path ? `<div style="font-size:0.82rem;color:#198754;">File: ${d.file_path}</div>` : ""}
        </div>
      `),
      ...apts.map(a => `
        <div class="history-entry" style="border-left-color:#2456A6;">
          <span class="program-tag">Appointment</span>
          <div class="fw-700">${fmtDate(a.scheduled_date)} ${a.scheduled_time} &mdash; ${a.program_type}</div>
          <div>Status: <strong>${a.status}</strong></div>
        </div>
      `)
    ].join("");

  const guardian = p.guardian ? `<tr><th>Guardian</th><td>${p.guardian} &mdash; ${p.guardian_contact}</td></tr>` : "";

  document.getElementById("modal-patient-detail-body").innerHTML = `
    <div class="d-flex align-items-start gap-3 mb-3">
      <div style="width:56px;height:56px;border-radius:50%;background:var(--green-dark);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.6rem;flex-shrink:0;">
        <i data-lucide="user"></i>
      </div>
      <div>
        <div style="font-size:1.3rem;font-weight:700;">${p.last_name}, ${p.first_name} ${p.middle_name || ""}</div>
        <span class="upi-chip">${p.upi}</span>
        ${p.notes ? `<span class="badge bg-warning text-dark ms-1">${p.notes}</span>` : ""}
      </div>
    </div>
    <table class="table table-sm table-bordered mb-3">
      <tr><th width="35%">Sex</th><td>${p.sex}</td></tr>
      <tr><th>Birthdate</th><td>${fmtDate(p.birthdate)} (Age: ${calcAge(p.birthdate)})</td></tr>
      <tr><th>Purok / Address</th><td>${p.purok}<br><small>${p.address}</small></td></tr>
      <tr><th>Contact</th><td>${p.contact}</td></tr>
      ${guardian}
      <tr><th>PhilHealth</th><td>${p.philhealth || "—"}</td></tr>
      <tr><th>SMS Consent</th><td>${p.consent_sms ? '<span class="badge bg-success">YES</span>' : '<span class="badge bg-secondary">NO</span>'}</td></tr>
    </table>
    <h6 class="fw-700 text-green mb-2"><i data-lucide="history" class="me-1"></i>Unified Health History</h6>
    ${historyHtml}
  `;

  document.getElementById("modal-patient-title").textContent = `${p.last_name}, ${p.first_name}`;
  const modal = new bootstrap.Modal(document.getElementById("modal-patient-detail"));
  modal.show();
  refreshIcons();
};

// ═══════════════════════════════════════════════════
//  S3 - SERVICE RECORD ENTRY
// ═══════════════════════════════════════════════════
function renderServiceEntry() {
  const patientSelect = document.getElementById("sr-patient-select");
  if (!patientSelect) return;

  // Populate patient dropdown
  patientSelect.innerHTML = `<option value="">-- Select Patient --</option>` +
    AppState.patients.map(p => `<option value="${p.upi}">${p.last_name}, ${p.first_name} &mdash; ${p.upi}</option>`).join("");

  renderServiceRecordsTable();
}

function renderServiceRecordsTable() {
  const tbody = document.getElementById("sr-tbody");
  if (!tbody) return;
  const records = [...AppState.serviceRecords].reverse().slice(0, 20);
  tbody.innerHTML = records.map(r => {
    const p = getPatientByUpi(r.upi);
    return `
      <tr>
        <td><span class="upi-chip" style="font-size:0.78rem;">${r.upi}</span><br><small>${p ? patientFullName(p) : r.upi}</small></td>
        <td><span class="badge" style="background:${programBadgeColor(r.program_type)}22;color:${programBadgeColor(r.program_type)};font-size:0.8rem;">${r.program_type}</span></td>
        <td>${fmtDate(r.service_date)}</td>
        <td>${r.form_reference_no}</td>
        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${r.findings_notes}</td>
        <td>${r.recorded_by}</td>
      </tr>
    `;
  }).join("");
}

window.saveServiceRecord = function() {
  const upi      = document.getElementById("sr-patient-select").value;
  const program  = document.getElementById("sr-program-type").value;
  const date     = document.getElementById("sr-service-date").value;
  const refNo    = document.getElementById("sr-form-ref").value.trim();
  const notes    = document.getElementById("sr-findings").value.trim();
  const recBy    = document.getElementById("sr-recorded-by").value.trim();
  const verBy    = document.getElementById("sr-verified-by").value.trim();

  if (!upi || !program || !date || !refNo || !notes || !recBy) {
    showToast("Please fill all required fields.", "warning");
    return;
  }

  AppState._srCounter++;
  const newRecord = {
    id: `SR-${String(AppState._srCounter).padStart(3,"0")}`,
    upi, program_type: program, service_date: date,
    form_reference_no: refNo, findings_notes: notes,
    recorded_by: recBy, verified_by: verBy,
    created_at: new Date().toISOString()
  };

  AppState.serviceRecords.push(newRecord);
  addAuditLog(AppState.currentUser, "CREATE", `Created service record ${newRecord.id} for ${upi}`, "127.0.0.1", newRecord.id);
  showToast(`Service record <strong>${newRecord.id}</strong> saved successfully.`, "success");

  // Reset form
  document.getElementById("sr-form").reset();
  renderServiceRecordsTable();
};

// ═══════════════════════════════════════════════════
//  S4 - DOCUMENT REGISTRY
// ═══════════════════════════════════════════════════
const DOC_STATUSES   = ["Filed","Pending Digitization","Scanned/Attached","Unavailable/Archived"];
const DOC_STATUS_MAP = {
  "Filed": "badge-status-filed",
  "Pending Digitization": "badge-status-pending",
  "Scanned/Attached": "badge-status-scanned",
  "Unavailable/Archived": "badge-status-unavailable"
};

function renderDocuments() {
  const filterStatus = document.getElementById("doc-filter-status")?.value || "all";
  let docs = [...AppState.documents];
  if (filterStatus !== "all") docs = docs.filter(d => d.status === filterStatus);

  const tbody = document.getElementById("doc-tbody");
  if (!tbody) return;

  tbody.innerHTML = docs.map(d => `
    <tr>
      <td><strong>${d.title}</strong><br><small class="text-muted">${d.document_type}</small></td>
      <td><span class="upi-chip" style="font-size:0.76rem;">${d.upi}</span><br><small>${d.patient_name}</small></td>
      <td><span class="badge ${DOC_STATUS_MAP[d.status] || ''}">${d.status}</span></td>
      <td>${d.cabinet_location} / ${d.box_location} / ${d.folder_location}</td>
      <td>${d.file_path ? `<span class="text-success"><i data-lucide="paperclip"></i> ${d.file_path.split("/").pop()}</span>` : '<span class="text-muted">None</span>'}</td>
      <td>${fmtDateTime(d.updated_at)}</td>
      <td>
        ${AppState.currentRole === "Administrator" ? '<span class="text-muted">—</span>' : `
        <button class="btn btn-sm btn-outline-primary me-1" onclick="showDocStatusModal('${d.id}')"><i data-lucide="refresh-ccw"></i> Status</button>
        <label class="btn btn-sm btn-outline-secondary" title="Attach file (name only stored)">
          <i data-lucide="paperclip"></i>
          <input type="file" style="display:none;" onchange="attachDocFile('${d.id}', this)">
        </label>`}
      </td>
    </tr>
  `).join("") || `<tr><td colspan="7" class="text-center text-muted py-3">No documents found.</td></tr>`;
  refreshIcons();
}

window.showDocStatusModal = function(docId) {
  const doc = AppState.documents.find(d => d.id === docId);
  if (!doc) return;

  document.getElementById("doc-status-modal-title").textContent  = doc.title;
  document.getElementById("doc-status-current").textContent      = doc.status;
  document.getElementById("doc-status-docId").value             = docId;

  const sel = document.getElementById("doc-status-new");
  sel.innerHTML = DOC_STATUSES.map(s => `<option value="${s}" ${s === doc.status ? "selected" : ""}>${s}</option>`).join("");

  const modal = new bootstrap.Modal(document.getElementById("modal-doc-status"));
  modal.show();
};

window.saveDocStatus = function() {
  const docId  = document.getElementById("doc-status-docId").value;
  const newSt  = document.getElementById("doc-status-new").value;
  const doc    = AppState.documents.find(d => d.id === docId);
  if (!doc) return;

  const old = doc.status;
  doc.status     = newSt;
  doc.updated_at = new Date().toISOString();

  addAuditLog(AppState.currentUser, "DOC_STATUS", `Document ${docId} status changed: "${old}" → "${newSt}"`, "127.0.0.1", docId);
  showToast(`Document status updated to <strong>${newSt}</strong>.`, "success");

  bootstrap.Modal.getInstance(document.getElementById("modal-doc-status"))?.hide();
  renderDocuments();
};

window.attachDocFile = function(docId, input) {
  if (!input.files || !input.files[0]) return;
  const fileName = input.files[0].name;
  const hash     = Math.random().toString(36).substring(2, 10);
  const fakePath = `/uploads/hashed/${hash}_${fileName.replace(/\s/g,"_")}`;

  const doc = AppState.documents.find(d => d.id === docId);
  if (doc) {
    doc.file_path  = fakePath;
    doc.status     = "Scanned/Attached";
    doc.updated_at = new Date().toISOString();
    addAuditLog(AppState.currentUser, "DOC_ATTACH", `File attached to ${docId}: ${fakePath}`, "127.0.0.1", docId);
    showToast(`File <strong>${fileName}</strong> attached (filename stored only).`, "success");
    renderDocuments();
  }
};

// ═══════════════════════════════════════════════════
//  S5 - PRINTABLE TEMPLATES
// ═══════════════════════════════════════════════════
function renderTemplates() {
  // Content is static HTML; just ensure correct template is shown
  showTemplate("maternal");
}

window.showTemplate = function(type) {
  document.querySelectorAll(".template-panel").forEach(el => el.classList.add("d-none"));
  document.getElementById(`template-${type}`)?.classList.remove("d-none");

  document.querySelectorAll(".template-tab").forEach(el => {
    el.classList.toggle("btn-primary", el.dataset.type === type);
    el.classList.toggle("btn-outline-primary", el.dataset.type !== type);
  });
};

window.printTemplate = function() {
  addAuditLog(AppState.currentUser, "PRINT", "Blank template printed", "127.0.0.1", "-");
  window.print();
};

// ═══════════════════════════════════════════════════
//  S6 - INVENTORY DASHBOARD
// ═══════════════════════════════════════════════════
function renderInventory() {
  const container = document.getElementById("inventory-cards");
  if (!container) return;

  const today = new Date();
  container.innerHTML = AppState.invItems.map(item => {
    const batches = AppState.invBatches.filter(b => b.item_id === item.id);
    const qty     = batches.reduce((s, b) => s + b.quantity, 0);
    const pct     = Math.min(100, Math.round(qty / (item.minimum_stock_threshold * 3) * 100));
    const isLow   = qty <= item.minimum_stock_threshold;

    // expiry badges
    const expiryAlerts = batches
      .filter(b => b.quantity > 0 && (isNearExpiry(b.expiry_date) || isExpired(b.expiry_date)))
      .map(b => {
        const expired = isExpired(b.expiry_date);
        return `<span class="badge ${expired ? 'bg-danger' : 'bg-warning text-dark'} me-1">
          ${expired ? 'Expired' : 'Near expiry'}: ${b.batch_no} (${fmtExpiry(b.expiry_date)})
        </span>`;
      }).join("");

    return `
      <div class="col-12 col-md-6 col-xl-4">
        <div class="inv-card ${isLow ? 'low-stock' : ''}">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="fw-700" style="font-size:1.05rem;">${item.name}</div>
              <div class="text-muted" style="font-size:0.83rem;">${item.category} &bull; Unit: ${item.unit}</div>
            </div>
            <div class="text-end">
              ${isLow ? '<span class="badge bg-warning text-dark">Low stock</span>' : '<span class="badge bg-success">OK</span>'}
            </div>
          </div>
          <div class="my-2">
            <div class="d-flex justify-content-between" style="font-size:0.85rem;">
              <span>Qty: <strong style="font-size:1.2rem;">${qty}</strong> ${item.unit}</span>
              <span class="text-muted">Min: ${item.minimum_stock_threshold}</span>
            </div>
            <div class="inv-qty-bar">
              <div class="inv-qty-bar-fill" style="width:${pct}%;background:${isLow ? '#d97706' : 'var(--green)'}"></div>
            </div>
          </div>
          ${expiryAlerts ? `<div class="mt-1">${expiryAlerts}</div>` : ""}
          <div class="mt-2" style="font-size:0.8rem;color:#6c757d;">
            ${batches.length} batch(es) &bull;
            <a href="#" onclick="showBatchDetail('${item.id}');return false;" class="text-green">View Batches</a>
          </div>
        </div>
      </div>
    `;
  }).join("");
}

window.showBatchDetail = function(itemId) {
  const item    = AppState.invItems.find(i => i.id === itemId);
  const batches = AppState.invBatches.filter(b => b.item_id === itemId);

  document.getElementById("modal-batch-title").textContent = `Batches — ${item?.name || itemId}`;
  document.getElementById("modal-batch-body").innerHTML = `
    <table class="table table-sm table-bordered">
      <thead>
        <tr>
          <th>Batch No.</th><th>Qty (${item?.unit})</th><th>Expiry</th><th>Received</th><th>Supplier</th>
        </tr>
      </thead>
      <tbody>
        ${batches.map(b => {
          const expired = isExpired(b.expiry_date);
          const nearExp = isNearExpiry(b.expiry_date);
          return `<tr class="${expired ? 'table-danger' : nearExp ? 'table-warning' : ''}">
            <td><code>${b.batch_no}</code></td>
            <td><strong>${b.quantity}</strong></td>
            <td>${fmtExpiry(b.expiry_date)} ${expired ? '<span class="badge bg-danger">Expired</span>' : nearExp ? '<span class="badge bg-warning text-dark">Near expiry</span>' : ''}</td>
            <td>${fmtDate(b.date_received)}</td>
            <td>${b.supplier}</td>
          </tr>`;
        }).join("")}
      </tbody>
    </table>
  `;
  new bootstrap.Modal(document.getElementById("modal-batch-detail")).show();
};

// ═══════════════════════════════════════════════════
//  S7 - STOCK MOVES
// ═══════════════════════════════════════════════════
function renderStockMoves() {
  // Populate item selects
  ["sm-item-in","sm-item-out","sm-item-dispose"].forEach(id => {
    const sel = document.getElementById(id);
    if (!sel) return;
    sel.innerHTML = `<option value="">-- Select Item --</option>` +
      AppState.invItems.map(i => `<option value="${i.id}">${i.name}</option>`).join("");
  });
  renderLedgerTable();
}

function renderLedgerTable(filterType = "all") {
  const tbody = document.getElementById("ledger-tbody");
  if (!tbody) return;

  let ledger = [...AppState.invLedger].reverse();
  if (filterType !== "all") ledger = ledger.filter(l => l.move_type === filterType);

  tbody.innerHTML = ledger.slice(0, 30).map(l => {
    const item = AppState.invItems.find(i => i.id === l.item_id);
    const colorClass = l.move_type === "Stock-In" ? "move-stockin" : l.move_type === "Stock-Out" ? "move-stockout" : "move-disposal";
    return `
      <tr>
        <td>${fmtDate(l.date)}</td>
        <td>${item?.name || l.item_id}</td>
        <td><span class="${colorClass}">${l.move_type}</span></td>
        <td><strong>${l.quantity}</strong> ${item?.unit || ""}</td>
        <td><code>${l.batch_no}</code></td>
        <td>${l.reason || "—"}</td>
        <td>${l.recorded_by}</td>
        <td>${l.notes}</td>
      </tr>
    `;
  }).join("") || `<tr><td colspan="8" class="text-center text-muted py-3">No movements yet.</td></tr>`;
}

window.stockIn = function() {
  const itemId    = document.getElementById("sm-item-in").value;
  const batchNo   = document.getElementById("sm-batch-in").value.trim();
  const qty       = parseInt(document.getElementById("sm-qty-in").value);
  const expiry    = document.getElementById("sm-expiry-in").value;
  const received  = document.getElementById("sm-received-in").value;
  const supplier  = document.getElementById("sm-supplier-in").value.trim() || "MHO Supply";

  if (!itemId || !batchNo || !qty || qty <= 0 || !received) {
    showToast("Please fill all Stock-In fields.", "warning"); return;
  }

  const item = AppState.invItems.find(i => i.id === itemId);

  // Add or update batch
  const existBatch = AppState.invBatches.find(b => b.item_id === itemId && b.batch_no === batchNo);
  if (existBatch) {
    existBatch.quantity += qty;
  } else {
    AppState.invBatches.push({
      id: `BATCH-${Date.now()}`, item_id: itemId, batch_no: batchNo,
      quantity: qty, expiry_date: expiry || null, date_received: received, supplier
    });
  }

  AppState._ledCounter++;
  AppState.invLedger.push({
    id: `LED-${String(AppState._ledCounter).padStart(3,"0")}`,
    item_id: itemId, batch_id: batchNo, move_type: "Stock-In",
    quantity: qty, date: received, batch_no: batchNo, reason: "",
    recorded_by: AppState.currentUser, notes: ""
  });

  addAuditLog(AppState.currentUser, "STOCK_IN", `Stock-In ${qty} ${item?.unit} of ${item?.name} batch ${batchNo}`, "127.0.0.1", batchNo);
  showToast(`<strong>Stock-In</strong> recorded: ${qty} ${item?.unit} of <strong>${item?.name}</strong>.`, "success");
  document.getElementById("sm-form-in").reset();
  renderLedgerTable();
};

window.stockOut = function() {
  const itemId = document.getElementById("sm-item-out").value;
  const qty    = parseInt(document.getElementById("sm-qty-out").value);
  const date   = document.getElementById("sm-date-out").value;

  if (!itemId || !qty || qty <= 0 || !date) {
    showToast("Please fill all Stock Out fields.", "warning"); return;
  }

  const item = AppState.invItems.find(i => i.id === itemId);

  // FEFO: earliest expiry first; non-expiring (null) issues last
  const batches = AppState.invBatches
    .filter(b => b.item_id === itemId && b.quantity > 0 && !isExpired(b.expiry_date))
    .sort((a, b) => {
      if (!a.expiry_date && !b.expiry_date) return 0;
      if (!a.expiry_date) return 1;
      if (!b.expiry_date) return -1;
      return new Date(a.expiry_date) - new Date(b.expiry_date);
    });

  const totalAvail = batches.reduce((s, b) => s + b.quantity, 0);
  if (totalAvail < qty) {
    showToast(`Insufficient stock. Only <strong>${totalAvail}</strong> ${item?.unit} available.`, "error"); return;
  }

  let remaining = qty;
  const pickedBatches = [];

  for (const batch of batches) {
    if (remaining <= 0) break;
    const take = Math.min(batch.quantity, remaining);
    batch.quantity -= take;
    remaining -= take;
    pickedBatches.push({ batch_no: batch.batch_no, qty: take, expiry: batch.expiry_date });

    AppState._ledCounter++;
    AppState.invLedger.push({
      id: `LED-${String(AppState._ledCounter).padStart(3,"0")}`,
      item_id: itemId, batch_id: batch.id, move_type: "Stock-Out",
      quantity: take, date: date, batch_no: batch.batch_no, reason: "",
      recorded_by: AppState.currentUser, notes: "FEFO auto-pick"
    });
  }

  const pickedSummary = pickedBatches.map(b => `<br>&bull; Batch <code>${b.batch_no}</code> (exp: ${fmtExpiry(b.expiry)}) — ${b.qty} ${item?.unit}`).join("");
  addAuditLog(AppState.currentUser, "STOCK_OUT", `Stock-Out ${qty} ${item?.unit} of ${item?.name} (FEFO)`, "127.0.0.1", "-");
  showToast(`<strong>Stock-Out</strong> recorded. FEFO batches picked:${pickedSummary}`, "success", 6000);

  document.getElementById("sm-form-out").reset();
  document.getElementById("fefo-result").innerHTML = `
    <div class="alert alert-info mt-2">
      <strong>FEFO Result:</strong> Deducted ${qty} ${item?.unit} of ${item?.name}${pickedSummary}
    </div>`;
  renderLedgerTable();
};

window.stockDispose = function() {
  const itemId  = document.getElementById("sm-item-dispose").value;
  const batchNo = document.getElementById("sm-batch-dispose").value.trim();
  const qty     = parseInt(document.getElementById("sm-qty-dispose").value);
  const reason  = document.getElementById("sm-reason-dispose").value.trim();
  const date    = document.getElementById("sm-date-dispose").value;

  if (!itemId || !batchNo || !qty || qty <= 0 || !reason || !date) {
    showToast("Please fill all Disposal fields including reason.", "warning"); return;
  }

  const item  = AppState.invItems.find(i => i.id === itemId);
  const batch = AppState.invBatches.find(b => b.item_id === itemId && b.batch_no === batchNo);

  if (!batch) {
    showToast(`Batch <strong>${batchNo}</strong> not found for this item.`, "error"); return;
  }

  if (batch.quantity < qty) {
    showToast(`Insufficient quantity in batch. Available: ${batch.quantity}.`, "error"); return;
  }

  batch.quantity -= qty;
  AppState._ledCounter++;
  AppState.invLedger.push({
    id: `LED-${String(AppState._ledCounter).padStart(3,"0")}`,
    item_id: itemId, batch_id: batch.id, move_type: "Disposal/Expired-Removal",
    quantity: qty, date: date, batch_no: batchNo, reason: reason,
    recorded_by: AppState.currentUser, notes: ""
  });

  addAuditLog(AppState.currentUser, "DISPOSAL", `Disposed ${qty} ${item?.unit} of ${item?.name} batch ${batchNo}: ${reason}`, "127.0.0.1", batchNo);
  showToast(`<strong>Disposal</strong> recorded: ${qty} ${item?.unit} of ${item?.name}.`, "success");
  document.getElementById("sm-form-dispose").reset();
  renderLedgerTable();
};

// ═══════════════════════════════════════════════════
//  S8 - SCHEDULING CALENDAR
// ═══════════════════════════════════════════════════
let calCurrentDate = new Date();

function renderScheduling() {
  // Populate patient select for new appointment
  const sel = document.getElementById("apt-patient-select");
  if (sel) {
    sel.innerHTML = `<option value="">-- Select Patient --</option>` +
      AppState.patients.map(p => `<option value="${p.upi}">${p.last_name}, ${p.first_name} — ${p.upi}</option>`).join("");
  }
  renderCalendar(calCurrentDate);
  renderAppointmentList();
}

function renderCalendar(date) {
  const year  = date.getFullYear();
  const month = date.getMonth();
  const today = new Date();

  document.getElementById("cal-month-label").textContent =
    date.toLocaleString("en-PH", { month: "long", year: "numeric" });

  const firstDay  = new Date(year, month, 1).getDay(); // 0 = Sun
  const daysInMonth = new Date(year, month + 1, 0).getDate();

  let html = `<table class="cal-table">
    <thead><tr>${["Sun","Mon","Tue","Wed","Thu","Fri","Sat"].map(d => `<th>${d}</th>`).join("")}</tr></thead>
    <tbody>`;

  let dayCount = 1;
  let rows = Math.ceil((firstDay + daysInMonth) / 7);

  for (let r = 0; r < rows; r++) {
    html += "<tr>";
    for (let c = 0; c < 7; c++) {
      const cellIndex = r * 7 + c;
      if (cellIndex < firstDay || dayCount > daysInMonth) {
        html += "<td class='other-month'></td>";
      } else {
        const cellDate = `${year}-${String(month+1).padStart(2,"0")}-${String(dayCount).padStart(2,"0")}`;
        const isToday  = (today.getFullYear() === year && today.getMonth() === month && today.getDate() === dayCount);
        const dayApts  = AppState.appointments.filter(a => a.scheduled_date === cellDate);

        const apts = dayApts.map(a => {
          const cls = `apt-pill-${a.status.toLowerCase().replace(/\s+/g, "")}`;
          return `<span class="cal-apt-pill ${cls}" onclick="showAptDetail('${a.id}');event.stopPropagation();" title="${a.patient_name} - ${a.program_type}">${a.scheduled_time} ${a.patient_name.split(",")[0]}</span>`;
        }).join("");

        html += `<td class="${isToday ? 'today' : ''}">
          <span class="cal-day-num ${isToday ? 'text-success' : ''}">${dayCount}</span>
          ${apts}
        </td>`;
        dayCount++;
      }
    }
    html += "</tr>";
  }

  html += "</tbody></table>";
  document.getElementById("cal-grid").innerHTML = html;
}

window.calPrev = function() {
  calCurrentDate = new Date(calCurrentDate.getFullYear(), calCurrentDate.getMonth() - 1, 1);
  renderCalendar(calCurrentDate);
};

window.calNext = function() {
  calCurrentDate = new Date(calCurrentDate.getFullYear(), calCurrentDate.getMonth() + 1, 1);
  renderCalendar(calCurrentDate);
};

window.showAptDetail = function(aptId) {
  const apt = AppState.appointments.find(a => a.id === aptId);
  if (!apt) return;
  const p = getPatientByUpi(apt.upi);

  document.getElementById("apt-detail-body").innerHTML = `
    <table class="table table-sm table-bordered">
      <tr><th>Patient</th><td>${apt.patient_name} <span class="upi-chip" style="font-size:0.76rem;">${apt.upi}</span></td></tr>
      <tr><th>Program</th><td>${apt.program_type}</td></tr>
      <tr><th>Date & Time</th><td>${fmtDate(apt.scheduled_date)} at ${apt.scheduled_time}</td></tr>
      <tr><th>Status</th><td><span class="badge badge-sched-${apt.status.toLowerCase().replace(/\s+/g, "-")}">${apt.status}</span></td></tr>
      <tr><th>SMS Reminder Sent</th><td>${apt.reminder_sent ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'}</td></tr>
      <tr><th>Notes</th><td>${apt.notes || "—"}</td></tr>
    </table>
    <div class="d-flex gap-2 flex-wrap mt-2">
      ${AppState.currentRole === "Administrator" ? '<span class="text-muted" style="font-size:.82rem">View-only for your role.</span>' : ["Scheduled","Completed","Cancelled","No-Show"].map(s =>
        `<button class="btn btn-sm ${apt.status===s?'btn-primary':'btn-outline-secondary'}" onclick="setAptStatus('${aptId}','${s}');bootstrap.Modal.getInstance(document.getElementById('modal-apt-detail')).hide();">${s}</button>`
      ).join("")}
    </div>
  `;
  new bootstrap.Modal(document.getElementById("modal-apt-detail")).show();
};

window.setAptStatus = function(aptId, status) {
  const apt = AppState.appointments.find(a => a.id === aptId);
  if (!apt) return;
  const old = apt.status;
  apt.status = status;
  addAuditLog(AppState.currentUser, "APT_STATUS", `Appointment ${aptId} status: "${old}" → "${status}"`, "127.0.0.1", aptId);
  showToast(`Appointment status updated to <strong>${status}</strong>.`, "success");
  renderCalendar(calCurrentDate);
  renderAppointmentList();
};

function renderAppointmentList() {
  const tbody = document.getElementById("apt-list-tbody");
  if (!tbody) return;
  const upcoming = AppState.appointments
    .filter(a => a.status === "Scheduled")
    .sort((a, b) => `${a.scheduled_date}${a.scheduled_time}` > `${b.scheduled_date}${b.scheduled_time}` ? 1 : -1);

  tbody.innerHTML = upcoming.map(a => `
    <tr onclick="showAptDetail('${a.id}')" style="cursor:pointer;">
      <td>${fmtDate(a.scheduled_date)} ${a.scheduled_time}</td>
      <td>${a.patient_name}</td>
      <td>${a.program_type}</td>
      <td><span class="badge badge-sched-scheduled">Scheduled</span></td>
      <td>${a.reminder_sent ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'}</td>
    </tr>
  `).join("") || `<tr><td colspan="5" class="text-center text-muted py-3">No upcoming appointments.</td></tr>`;
}

window.saveAppointment = function() {
  const upi     = document.getElementById("apt-patient-select").value;
  const prog    = document.getElementById("apt-program").value;
  const date    = document.getElementById("apt-date").value;
  const time    = document.getElementById("apt-time").value;
  const notes   = document.getElementById("apt-notes").value.trim();

  if (!upi || !prog || !date || !time) {
    showToast("Please fill all required fields.", "warning"); return;
  }

  const p = getPatientByUpi(upi);
  AppState._aptCounter++;
  const newApt = {
    id: `APT-${String(AppState._aptCounter).padStart(3,"0")}`,
    upi,
    patient_name: `${p?.last_name || "?"}, ${p?.first_name || "?"}`,
    program_type: prog, scheduled_date: date, scheduled_time: time,
    status: "Scheduled", reminder_sent: 0, notes
  };

  AppState.appointments.push(newApt);
  addAuditLog(AppState.currentUser, "APT_CREATE", `Appointment ${newApt.id} created for ${upi} on ${date}`, "127.0.0.1", newApt.id);
  showToast(`Appointment <strong>${newApt.id}</strong> scheduled.`, "success");
  document.getElementById("apt-form").reset();
  renderCalendar(calCurrentDate);
  renderAppointmentList();
};

// ═══════════════════════════════════════════════════
//  S9 - SMS COMPOSE & LOGS
// ═══════════════════════════════════════════════════
function renderSMS() {
  // Patient select
  const sel = document.getElementById("sms-patient-select");
  if (sel) {
    sel.innerHTML = `<option value="">-- Select Patient --</option>` +
      AppState.patients.map(p => `<option value="${p.upi}">${p.last_name}, ${p.first_name} — ${p.upi}</option>`).join("");
  }

  // Template select
  const tplSel = document.getElementById("sms-template-select");
  if (tplSel) {
    tplSel.innerHTML = `<option value="">-- Select Template --</option>` +
      window.SMS_TEMPLATES.map(t => `<option value="${t.id}">${t.label}</option>`).join("");
    tplSel.addEventListener("change", updateSMSPreview);
  }

  // Patient change → show consent warning
  if (sel) {
    sel.addEventListener("change", () => {
      const p = getPatientByUpi(sel.value);
      const warn = document.getElementById("sms-consent-warning");
      if (warn) {
        warn.classList.toggle("d-none", !p || p.consent_sms);
      }
      updateSMSPreview();
    });
  }

  renderSMSLogs();
}

function updateSMSPreview() {
  const tplId = document.getElementById("sms-template-select")?.value;
  const date  = document.getElementById("sms-date-fill")?.value || "[date]";
  const tpl   = window.SMS_TEMPLATES.find(t => t.id === tplId);
  const preview = document.getElementById("sms-preview");
  if (preview) {
    preview.textContent = tpl ? tpl.body.replace("[date]", date || "[date]") : "(select a template above)";
  }
}

window.sendSMS = function() {
  const upi    = document.getElementById("sms-patient-select").value;
  const tplId  = document.getElementById("sms-template-select").value;
  const date   = document.getElementById("sms-date-fill").value;
  const consent= document.getElementById("sms-consent-check").checked;

  if (!upi || !tplId) { showToast("Select patient and template.", "warning"); return; }
  if (!consent)        { showToast("Please verify consent before sending.", "warning"); return; }
  if (AppState.currentRole !== "Nurse/Midwife") {
    showToast("Sending is performed by the nurse per clinic policy.", "warning");
    return;
  }

  const p   = getPatientByUpi(upi);
  const tpl = window.SMS_TEMPLATES.find(t => t.id === tplId);

  if (!p) { showToast("Patient not found.", "error"); return; }
  if (!p.consent_sms) { showToast("Patient has NOT given SMS consent. Cannot send.", "error"); return; }

  const msgBody = tpl.body.replace("[date]", date || "—");

  showToast("Sending SMS... simulating gateway delay.", "info");

  // Simulate gateway delay
  setTimeout(() => {
    AppState._smsCounter++;
    const newLog = {
      id: `SMS-${String(AppState._smsCounter).padStart(3,"0")}`,
      upi, recipient_name: patientFullName(p),
      recipient_number: p.contact,
      message: msgBody,
      template_used: tpl.name,
      gateway_used: "Semaphore Mock",
      delivery_status: "Sent",
      sent_at: new Date().toISOString(),
      consent_verified: true
    };
    AppState.smsLogs.unshift(newLog);
    addAuditLog(AppState.currentUser, "SMS_SEND", `SMS sent to ${patientFullName(p)} (${p.contact}) via Semaphore Mock`, "127.0.0.1", newLog.id);
    showToast(`SMS <strong>${newLog.id}</strong> sent to <strong>${patientFullName(p)}</strong>!`, "success");
    document.getElementById("sms-form").reset();
    document.getElementById("sms-preview").textContent = "(select a template above)";
    renderSMSLogs();
  }, 2000);
};

function renderSMSLogs() {
  const tbody = document.getElementById("sms-log-tbody");
  if (!tbody) return;
  tbody.innerHTML = AppState.smsLogs.map(s => `
    <tr>
      <td>${fmtDateTime(s.sent_at)}</td>
      <td>${s.recipient_name}<br><code style="font-size:0.78rem;">${s.recipient_number}</code></td>
      <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${s.message}">${s.message}</td>
      <td><span class="badge bg-info text-dark">${s.gateway_used}</span></td>
      <td><span class="badge bg-success">${s.delivery_status}</span></td>
      <td>${s.consent_verified ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-danger">No</span>'}</td>
    </tr>
  `).join("") || `<tr><td colspan="6" class="text-center text-muted py-3">No SMS logs yet.</td></tr>`;
}

// ═══════════════════════════════════════════════════
//  S10 - OCR / DIGITIZATION
// ═══════════════════════════════════════════════════
function renderOCR() {
  // Populate patient select for OCR assignment
  const sel = document.getElementById("ocr-patient-select");
  if (sel) {
    sel.innerHTML = `<option value="">-- Select Patient --</option>` +
      AppState.patients.map(p => `<option value="${p.upi}">${p.last_name}, ${p.first_name} — ${p.upi}</option>`).join("");
  }
  renderOCRRejectedList();
}

window.handleOCRUpload = function(input) {
  if (!input.files || !input.files[0]) return;
  const fileName = input.files[0].name;

  document.getElementById("ocr-filename").textContent = fileName;
  document.getElementById("ocr-upload-section").classList.add("d-none");
  document.getElementById("ocr-progress-section").classList.remove("d-none");
  document.getElementById("ocr-draft-section").classList.add("d-none");

  // Simulate scanning progress
  let pct = 0;
  const bar = document.getElementById("ocr-progress-bar");
  const interval = setInterval(() => {
    pct += Math.random() * 15 + 5;
    if (pct >= 100) { pct = 100; clearInterval(interval); showOCRDraft(fileName); }
    bar.style.width = pct + "%";
    bar.textContent  = Math.round(pct) + "%";
  }, 200);
};

function showOCRDraft(fileName) {
  setTimeout(() => {
    document.getElementById("ocr-progress-section").classList.add("d-none");
    document.getElementById("ocr-draft-section").classList.remove("d-none");

    // Generate fake OCR results
    const fakeFields = [
      { label: "Patient Name",       value: "DELA CRUZ, JUANA S.",   confidence: 92 },
      { label: "Date of Service",    value: "2026-09-27",            confidence: 88 },
      { label: "Form Reference No.", value: "MAT-2026-DRAFT",        confidence: 75 },
      { label: "BHW / Recorder",     value: "BHW L|M (unclear)",     confidence: 62 },
      { label: "AOG (weeks)",        value: "28",                     confidence: 95 },
      { label: "Blood Pressure",     value: "110/70",                 confidence: 90 },
      { label: "Remarks",            value: "Normal prenatal visit",  confidence: 85 }
    ];

    const rawText = fakeFields.map(f => `${f.label}: ${f.value}`).join("\n");
    document.getElementById("ocr-raw-text").textContent = rawText;

    const fieldsHtml = fakeFields.map((f, i) => {
      const isLow = f.confidence < 80;
      return `
        <div class="ocr-field-row ${isLow ? 'ocr-needs-review' : ''}">
          <label style="min-width:170px;font-size:0.9rem;font-weight:600;">${f.label}</label>
          <input type="text" class="form-control form-control-sm" id="ocr-field-${i}" value="${f.value}">
          <span class="conf-badge ${isLow ? 'conf-low' : 'conf-high'}">${f.confidence}%</span>
          ${isLow ? '<span class="badge bg-warning text-dark">Review</span>' : ''}
        </div>
      `;
    }).join("");

    document.getElementById("ocr-fields-container").innerHTML = fieldsHtml;
    document.getElementById("ocr-source-file").textContent    = fileName;

    showToast("OCR scan complete. Please review and verify fields.", "info");
  }, 500);
}

window.verifyAndSaveOCR = function() {
  // Collect edited field values
  const patient = document.getElementById("ocr-patient-select")?.value;
  const program = document.getElementById("ocr-program-select")?.value;

  // Save as service record
  AppState._srCounter++;
  const upi = patient || "TUB-2026-00001";
  const newRecord = {
    id: `SR-${String(AppState._srCounter).padStart(3,"0")}`,
    upi,
    program_type: program || "Maternal/Prenatal",
    service_date: document.getElementById("ocr-field-1")?.value || new Date().toISOString().split("T")[0],
    form_reference_no: document.getElementById("ocr-field-2")?.value || "OCR-DRAFT",
    findings_notes: `[OCR verified] ${document.getElementById("ocr-field-6")?.value || ""} `,
    recorded_by: AppState.currentUser,
    verified_by: AppState.currentUser,
    created_at: new Date().toISOString()
  };

  AppState.serviceRecords.push(newRecord);
  addAuditLog(AppState.currentUser, "OCR_VERIFY", `OCR draft verified and saved as ${newRecord.id} for ${upi}`, "127.0.0.1", newRecord.id);
  showToast(`OCR data saved as service record <strong>${newRecord.id}</strong>.`, "success");

  resetOCRPanel();
};

window.rejectOCR = function() {
  const fileName = document.getElementById("ocr-source-file")?.textContent || "unknown";
  AppState.ocrRejected.push({
    id: `OCR-REJ-${Date.now()}`,
    upload_filename: fileName,
    uploaded_at: new Date().toISOString(),
    rejected_at: new Date().toISOString(),
    rejected_by: AppState.currentUser,
    reject_reason: "Manual rejection",
    ocr_raw: document.getElementById("ocr-raw-text")?.textContent || ""
  });

  addAuditLog(AppState.currentUser, "OCR_REJECT", `OCR draft rejected: ${fileName}`, "127.0.0.1", "-");
  showToast("OCR draft rejected and added to rejected list.", "warning");
  resetOCRPanel();
  renderOCRRejectedList();
};

function resetOCRPanel() {
  document.getElementById("ocr-upload-section").classList.remove("d-none");
  document.getElementById("ocr-progress-section").classList.add("d-none");
  document.getElementById("ocr-draft-section").classList.add("d-none");
  document.getElementById("ocr-progress-bar").style.width = "0%";
  document.getElementById("ocr-progress-bar").textContent = "0%";
  document.getElementById("ocr-upload-input").value = "";
}

function renderOCRRejectedList() {
  const tbody = document.getElementById("ocr-rejected-tbody");
  if (!tbody) return;
  tbody.innerHTML = AppState.ocrRejected.map(r => `
    <tr>
      <td>${fmtDateTime(r.uploaded_at)}</td>
      <td>${r.upload_filename}</td>
      <td>${fmtDateTime(r.rejected_at)}</td>
      <td>${r.rejected_by}</td>
      <td>${r.reject_reason}</td>
    </tr>
  `).join("") || `<tr><td colspan="5" class="text-center text-muted py-3">No rejected OCR drafts.</td></tr>`;
}

// ═══════════════════════════════════════════════════
//  S11 - REPORTS & BACKUP
// ═══════════════════════════════════════════════════
let reportCharts = {};

function renderReports() {
  renderReportCharts();
  renderAuditTable();
}

function renderReportCharts() {
  // Visits per program (same as dashboard)
  const programs = ["Family Planning","Maternal/Prenatal","Child Health-ECCD","Immunization","Laboratory Referral","General Consultation"];
  const visitCounts = programs.map(p => AppState.serviceRecords.filter(r => r.program_type === p).length);

  const ctx1 = document.getElementById("report-chart-visits");
  if (ctx1) {
    if (reportCharts.visits) reportCharts.visits.destroy();
    reportCharts.visits = new Chart(ctx1, {
      type: "bar",
      data: {
        labels: programs,
        datasets: [{
          label: "Records",
          data: visitCounts,
          backgroundColor: programs.map(p => programBadgeColor(p) + "bb"),
          borderColor: programs.map(p => programBadgeColor(p)),
          borderWidth: 2, borderRadius: 5
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false }, title: { display: true, text: "Service Records by Program" } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
      }
    });
  }

  // Doc status pie
  const docStatuses = ["Filed","Pending Digitization","Scanned/Attached","Unavailable/Archived"];
  const docCounts = docStatuses.map(s => AppState.documents.filter(d => d.status === s).length);
  const ctx2 = document.getElementById("report-chart-docs");
  if (ctx2) {
    if (reportCharts.docs) reportCharts.docs.destroy();
    reportCharts.docs = new Chart(ctx2, {
      type: "pie",
      data: {
        labels: docStatuses,
        datasets: [{
          data: docCounts,
          backgroundColor: ["#3565c0","#d97a06","#15864c","#8a8f98"],
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { position: "bottom" },
          title: { display: true, text: "Documents by Status" }
        }
      }
    });
  }
}

function renderAuditTable() {
  const tbody = document.getElementById("audit-tbody");
  if (!tbody) return;
  tbody.innerHTML = AppState.auditLogs.slice(0, 30).map(a => `
    <tr>
      <td style="white-space:nowrap;">${fmtDateTime(a.timestamp)}</td>
      <td><strong>${a.actor}</strong></td>
      <td><span class="badge bg-secondary">${a.action}</span></td>
      <td>${a.description}</td>
      <td><code>${a.ip}</code></td>
      <td>${a.record_ref}</td>
    </tr>
  `).join("");
}

window.simulateBackup = function() {
  showToast("Backup export started...", "info");
  const btn = document.getElementById("backup-btn");
  if (btn) { btn.disabled = true; btn.innerHTML = '<i data-lucide="hourglass"></i> Backing up...'; refreshIcons(); }

  setTimeout(() => {
    const backupData = {
      exported_at: new Date().toISOString(),
      patients: AppState.patients.length,
      service_records: AppState.serviceRecords.length,
      documents: AppState.documents.length,
      appointments: AppState.appointments.length,
      sms_logs: AppState.smsLogs.length,
      note: "Tubtubon eHealth backup export"
    };

    // Simulate file creation
    const dataStr = JSON.stringify(backupData, null, 2);
    const blob = new Blob([dataStr], { type: "application/json" });
    const url  = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href  = url;
    link.download = `tubtubon_ehealth_backup_${new Date().toISOString().slice(0,10)}.json`;
    link.click();
    URL.revokeObjectURL(url);

    addAuditLog(AppState.currentUser, "BACKUP", "Backup export completed (simulated)", "127.0.0.1", "-");
    showToast("Backup export complete. File downloaded.", "success", 5000);

    if (btn) { btn.disabled = false; btn.innerHTML = '<i data-lucide="database-backup"></i> Export Backup'; refreshIcons(); }
  }, 2000);
};

// ═══════════════════════════════════════════════════
//  S12 - RESIDENT PORTAL
// ═══════════════════════════════════════════════════
function renderResidentPortal() {
  // Resident always sees only Juana Dela Cruz (UPI TUB-2026-00001)
  const RESIDENT_UPI = "TUB-2026-00001";
  const p = getPatientByUpi(RESIDENT_UPI);
  if (!p) { document.getElementById("page-resident-portal").innerHTML = "<p class='text-danger'>Resident data not found.</p>"; return; }

  const records = AppState.serviceRecords.filter(r => r.upi === RESIDENT_UPI);
  const apts    = AppState.appointments.filter(a => a.upi === RESIDENT_UPI);
  const upcomingApts = apts.filter(a => a.status === "Scheduled");

  document.getElementById("resident-name-display").textContent = `${p.last_name}, ${p.first_name}`;
  document.getElementById("resident-upi-display").textContent  = p.upi;

  // Info table
  document.getElementById("resident-info-body").innerHTML = `
    <tr><th>Name</th><td>${p.last_name}, ${p.first_name} ${p.middle_name || ""}</td></tr>
    <tr><th>Sex</th><td>${p.sex}</td></tr>
    <tr><th>Birthdate</th><td>${fmtDate(p.birthdate)} (Age ${calcAge(p.birthdate)})</td></tr>
    <tr><th>Purok</th><td>${p.purok}</td></tr>
    <tr><th>Contact</th><td>${p.contact}</td></tr>
    <tr><th>PhilHealth</th><td>${p.philhealth || "—"}</td></tr>
    <tr><th>SMS Consent</th><td>${p.consent_sms ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'}</td></tr>
  `;

  // Schedule
  const schedTbody = document.getElementById("resident-sched-tbody");
  if (schedTbody) {
    schedTbody.innerHTML = apts.map(a => `
      <tr>
        <td>${fmtDate(a.scheduled_date)} ${a.scheduled_time}</td>
        <td>${a.program_type}</td>
        <td><span class="badge badge-sched-${a.status.toLowerCase().replace(" ","-")}">${a.status}</span></td>
        <td>${a.notes || "—"}</td>
      </tr>
    `).join("") || `<tr><td colspan="4" class="text-center text-muted py-2">No appointments.</td></tr>`;
  }

  // Health records (summary only, no diagnosis details)
  const recTbody = document.getElementById("resident-records-tbody");
  if (recTbody) {
    recTbody.innerHTML = records.map(r => `
      <tr>
        <td>${fmtDate(r.service_date)}</td>
        <td>${r.program_type}</td>
        <td>${r.form_reference_no}</td>
        <td>${r.recorded_by}</td>
      </tr>
    `).join("") || `<tr><td colspan="4" class="text-center text-muted py-2">No records found.</td></tr>`;
  }
}

// ═══════════════════════════════════════════════════
//  EXPOSE GLOBALLY for HTML event handlers
// ═══════════════════════════════════════════════════
window.navigateTo     = navigateTo;
window.updateSMSPreview = updateSMSPreview;
window.filterLedger   = (type) => renderLedgerTable(type);
window.filterDocs     = () => renderDocuments();
