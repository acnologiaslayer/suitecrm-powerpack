/**
 * Verbacall Integration for SuiteCRM 8 Angular UI
 * Adds Sign-up Link and Payment Link buttons to Lead detail pages
 * v1.0.5 - Fixed to only show in Lead record actions (with Delete), not global user menu
 */
(function() {
    "use strict";

    // Prevent multiple initializations
    if (window.VERBACALL_INIT) return;
    window.VERBACALL_INIT = true;

    var CONFIG = {
        signupUrl: "/legacy/index.php?module=VerbacallIntegration&action=signuplink&lead_id=",
        paymentUrl: "/legacy/index.php?module=VerbacallIntegration&action=paymentlink&lead_id="
    };

    var STYLES = {
        panel: "background:#fff;padding:12px 16px;border-radius:8px;margin:0 20px 16px 20px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;box-shadow:0 2px 8px rgba(0,0,0,0.1);border:1px solid #e0e0e0;",
        panelTitle: "color:#333;font-weight:600;font-size:14px;margin-right:auto;display:flex;align-items:center;gap:8px;",
        btnSignup: "cursor:pointer;padding:8px 16px;background:#28a745;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:500;display:inline-flex;align-items:center;gap:6px;transition:background 0.2s;",
        btnPayment: "cursor:pointer;padding:8px 16px;background:#fd7e14;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:500;display:inline-flex;align-items:center;gap:6px;transition:background 0.2s;",
        dropdownItem: "display:flex;align-items:center;gap:8px;padding:8px 16px;cursor:pointer;color:#333;font-size:14px;transition:background 0.2s;",
        dropdownIcon: "width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;"
    };

    console.log("[Verbacall] Script loaded v1.0.3");

    function getLeadIdFromUrl() {
        var hash = window.location.hash;
        // Match: #/leads/record/{uuid} or #/leads/detail/{uuid}
        var match = hash.match(/^#\/leads\/(?:record|detail)\/([a-f0-9-]+)/i);
        if (match) {
            return match[1];
        }
        return null;
    }

    function isLeadDetailPage() {
        return getLeadIdFromUrl() !== null;
    }

    function openSignupPopup(leadId) {
        window.open(
            CONFIG.signupUrl + encodeURIComponent(leadId),
            "VerbacallSignup",
            "width=550,height=550,scrollbars=yes,resizable=yes"
        );
    }

    function openPaymentPopup(leadId) {
        window.open(
            CONFIG.paymentUrl + encodeURIComponent(leadId),
            "VerbacallPayment",
            "width=550,height=600,scrollbars=yes,resizable=yes"
        );
    }

    function createVerbacallPanel(leadId) {
        var panel = document.createElement("div");
        panel.id = "verbacall-panel";
        panel.style.cssText = STYLES.panel;

        // Title with icon
        var title = document.createElement("span");
        title.style.cssText = STYLES.panelTitle;
        title.innerHTML = '📞 Verbacall';
        panel.appendChild(title);

        // Sign-up Link Button
        var signupBtn = document.createElement("button");
        signupBtn.type = "button";
        signupBtn.style.cssText = STYLES.btnSignup;
        signupBtn.innerHTML = '✉️ Send Sign-up Link';
        signupBtn.title = "Generate and email Verbacall signup link to this lead";
        signupBtn.onmouseover = function() { this.style.background = "#218838"; };
        signupBtn.onmouseout = function() { this.style.background = "#28a745"; };
        signupBtn.onclick = function(e) {
            e.preventDefault();
            openSignupPopup(leadId);
        };
        panel.appendChild(signupBtn);

        // Payment Link Button
        var paymentBtn = document.createElement("button");
        paymentBtn.type = "button";
        paymentBtn.style.cssText = STYLES.btnPayment;
        paymentBtn.innerHTML = '💳 Generate Payment Link';
        paymentBtn.title = "Generate Verbacall payment/discount link for this lead";
        paymentBtn.onmouseover = function() { this.style.background = "#e96b00"; };
        paymentBtn.onmouseout = function() { this.style.background = "#fd7e14"; };
        paymentBtn.onclick = function(e) {
            e.preventDefault();
            openPaymentPopup(leadId);
        };
        panel.appendChild(paymentBtn);

        return panel;
    }

    function addPanelToPage(leadId) {
        // Don't add if already exists
        if (document.getElementById("verbacall-panel")) return;

        console.log("[Verbacall] Looking for insertion point...");

        // Strategy 1: Insert at start of .record-view-container (inside the content area)
        var recordViewContainer = document.querySelector(".record-view-container");
        if (recordViewContainer) {
            console.log("[Verbacall] Found .record-view-container");
            var panel = createVerbacallPanel(leadId);
            if (recordViewContainer.firstChild) {
                recordViewContainer.insertBefore(panel, recordViewContainer.firstChild);
            } else {
                recordViewContainer.appendChild(panel);
            }
            console.log("[Verbacall] Panel added to record-view-container");
            return;
        }

        // Strategy 2: Insert after the record-view-hr-container
        var hrContainer = document.querySelector(".record-view-hr-container");
        if (hrContainer) {
            console.log("[Verbacall] Found .record-view-hr-container");
            var panel = createVerbacallPanel(leadId);
            hrContainer.parentNode.insertBefore(panel, hrContainer.nextSibling);
            console.log("[Verbacall] Panel added after hr-container");
            return;
        }

        // Strategy 3: Insert inside .record-view
        var recordView = document.querySelector(".record-view");
        if (recordView) {
            console.log("[Verbacall] Found .record-view");
            var panel = createVerbacallPanel(leadId);
            var stickyHeader = recordView.querySelector(".record-view-position-sticky");
            if (stickyHeader && stickyHeader.nextSibling) {
                recordView.insertBefore(panel, stickyHeader.nextSibling.nextSibling);
            } else {
                recordView.appendChild(panel);
            }
            console.log("[Verbacall] Panel added to record-view");
            return;
        }

        console.log("[Verbacall] Could not find insertion point, will retry...");
    }

    function addActionsDropdownItems(leadId) {
        // Find all dropdown menus
        var dropdownMenus = document.querySelectorAll(".dropdown-menu");

        dropdownMenus.forEach(function(menu) {
            // Skip if already processed
            if (menu.querySelector(".verbacall-action-item")) return;

            // Skip global user menu (check for specific user menu indicators)
            if (menu.classList.contains("global-links-dropdown")) return;
            if (menu.querySelector(".global-user-name")) return;
            if (menu.querySelector("scrm-logout-ui")) return;
            if (menu.querySelector('a[href*="users/edit"]')) return;

            // Check if this looks like an actions menu (has edit or delete)
            var menuText = menu.textContent.toLowerCase();
            if (menuText.indexOf("edit") === -1 && menuText.indexOf("delete") === -1) return;

            console.log("[Verbacall] Found record actions dropdown, adding items...");

            // Add divider
            var divider = document.createElement("div");
            divider.className = "dropdown-divider verbacall-action-item";
            menu.appendChild(divider);

            // Add Sign-up Link item
            var signupItem = document.createElement("a");
            signupItem.className = "dropdown-item verbacall-action-item";
            signupItem.href = "#";
            signupItem.style.cssText = STYLES.dropdownItem;
            signupItem.innerHTML = '<span style="' + STYLES.dropdownIcon + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#4ecca3" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg></span> Send Verbacall Sign-up Link';
            signupItem.onclick = function(e) {
                e.preventDefault();
                openSignupPopup(leadId);
            };
            menu.appendChild(signupItem);

            // Add Payment Link item
            var paymentItem = document.createElement("a");
            paymentItem.className = "dropdown-item verbacall-action-item";
            paymentItem.href = "#";
            paymentItem.style.cssText = STYLES.dropdownItem;
            paymentItem.innerHTML = '<span style="' + STYLES.dropdownIcon + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f39c12" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg></span> Generate Payment Link';
            paymentItem.onclick = function(e) {
                e.preventDefault();
                openPaymentPopup(leadId);
            };
            menu.appendChild(paymentItem);
        });
    }

    function scanAndInject() {
        var hash = window.location.hash;
        console.log("[Verbacall] Current hash:", hash);

        if (!isLeadDetailPage()) {
            // Remove panel if navigated away from lead detail
            var existingPanel = document.getElementById("verbacall-panel");
            if (existingPanel) {
                existingPanel.remove();
            }
            console.log("[Verbacall] Not on lead detail page");
            return;
        }

        var leadId = getLeadIdFromUrl();
        console.log("[Verbacall] On lead detail page, lead ID:", leadId);

        // Debug: Log available containers
        console.log("[Verbacall] .record-view exists:", !!document.querySelector(".record-view"));
        console.log("[Verbacall] .record-view-container exists:", !!document.querySelector(".record-view-container"));
        console.log("[Verbacall] .record-view-hr-container exists:", !!document.querySelector(".record-view-hr-container"));

        // Add visible panel
        addPanelToPage(leadId);

        // Add to actions dropdown (when opened)
        addActionsDropdownItems(leadId);
    }

    function startObserver() {
        var lastHash = window.location.hash;
        var timeout = null;

        var observer = new MutationObserver(function() {
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                // Check if hash changed (SPA navigation)
                if (window.location.hash !== lastHash) {
                    lastHash = window.location.hash;
                    console.log("[Verbacall] Hash changed, rescanning...");
                }
                scanAndInject();
            }, 300);
        });

        observer.observe(document.body, { childList: true, subtree: true });

        // Also listen for hashchange
        window.addEventListener("hashchange", function() {
            console.log("[Verbacall] Hash change event");
            lastHash = window.location.hash;
            setTimeout(scanAndInject, 500);
        });
    }

    function init() {
        console.log("[Verbacall] Initializing...");

        // Initial scans with delays for Angular loading
        setTimeout(scanAndInject, 1000);
        setTimeout(scanAndInject, 2000);
        setTimeout(scanAndInject, 4000);

        // Start observing for changes
        startObserver();
    }

    // Start initialization
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
