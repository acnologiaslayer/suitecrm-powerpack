/**
 * Twilio Click-to-Call for SuiteCRM 8 Angular UI
 * Adds call and SMS buttons next to phone numbers
 * v2.3.0 - International phone number support
 */
(function() {
    "use strict";
    
    // Prevent multiple initializations
    if (window.TWILIO_CTC_INIT) return;
    window.TWILIO_CTC_INIT = true;
    
    var CONFIG = {
        callUrl: "legacy/index.php?module=TwilioIntegration&action=makecall&phone=",
        smsUrl: "legacy/index.php?module=TwilioIntegration&action=sendsms&phone=",
        // US phone pattern: (xxx) xxx-xxxx or xxx-xxx-xxxx or +1xxxxxxxxxx
        phonePattern: /^[\+]?1?[-.\s]?\(?[0-9]{3}\)?[-.\s]?[0-9]{3}[-.\s]?[0-9]{4}$/
    };
    
    console.log("[Twilio CTC] Script loaded v2.3.0");
    
    // Main function to scan and add buttons
    function scanForPhoneNumbers() {
        console.log("[Twilio CTC] Scanning for phone numbers...");
        
        // Method 1: Find ALL links in table cells and check if they look like phone numbers
        document.querySelectorAll("table tbody td a").forEach(function(link) {
            processElement(link);
        });
        
        // Method 2: Find links inside scrm-field components
        document.querySelectorAll("scrm-field a").forEach(function(link) {
            processElement(link);
        });
        
        // Method 3: Find any standalone text that looks like a phone in table cells
        document.querySelectorAll("table tbody td").forEach(function(cell) {
            // Skip if already has buttons
            if (cell.querySelector(".twilio-btns")) return;
            if (cell.getAttribute("data-twilio-done")) return;
            
            // Check if cell has no links but has phone-like text
            if (!cell.querySelector("a")) {
                var text = cell.textContent.trim();
                if (isPhoneNumber(text)) {
                    console.log("[Twilio CTC] Found phone in cell text:", text);
                    addButtonsToElement(cell, text);
                }
            }
        });
        
        // Method 4: Look for detail view phone fields
        scanDetailView();
    }
    
    function processElement(link) {
        var text = link.textContent.trim();
        
        // Skip non-phone links
        if (!isPhoneNumber(text)) return;
        
        // Skip if parent already processed
        var parent = link.closest("td") || link.parentElement;
        if (!parent) return;
        if (parent.querySelector(".twilio-btns")) return;
        if (parent.getAttribute("data-twilio-done")) return;
        
        console.log("[Twilio CTC] Found phone link:", text);
        addButtonsAfterElement(link, text);
        parent.setAttribute("data-twilio-done", "1");
    }
    
    function scanDetailView() {
        // Find phone fields in record/detail views by label
        var phoneLabels = ["phone", "mobile", "office phone", "work phone", "home phone", "fax"];
        
        document.querySelectorAll("label").forEach(function(label) {
            var labelText = label.textContent.toLowerCase().trim();
            var isPhoneLabel = phoneLabels.some(function(p) {
                return labelText.indexOf(p) !== -1;
            });
            
            if (!isPhoneLabel) return;
            
            // Find associated value - check siblings and parent containers
            var container = label.closest(".form-group") || label.closest("[class*='field']") || label.parentElement;
            if (!container) return;
            if (container.querySelector(".twilio-btns")) return;
            
            // Look for value in container
            var valueEl = container.querySelector("span:not(.twilio-btns)") || 
                          container.querySelector("a") ||
                          container.querySelector("[class*='value']");
            
            if (valueEl) {
                var phone = valueEl.textContent.trim();
                if (isPhoneNumber(phone)) {
                    console.log("[Twilio CTC] Found phone in detail view:", phone);
                    addButtonsAfterElement(valueEl, phone);
                }
            }
        });
    }
    
    function isPhoneNumber(text) {
        if (!text || typeof text !== "string") return false;
        text = text.trim();
        
        // Remove all non-digits to count
        var digits = text.replace(/\D/g, "");
        
        // International: 10-15 digits (covers most countries)
        // US: 10 digits, International with country code: 11-15 digits
        if (digits.length < 10 || digits.length > 15) return false;
        
        // Match international format (+country code) or standard formats
        return /^\+?\d{10,15}$/.test(text.replace(/[-.\s()]/g, "")) ||
               CONFIG.phonePattern.test(text) || 
               /^\d{10,15}$/.test(digits) ||
               /^\(\d{3}\)\s*\d{3}[-.]?\d{4}$/.test(text) ||
               /^\d{3}[-.\s]\d{3}[-.\s]\d{4}$/.test(text) ||
               /^\+\d{1,4}[-.\s]?\d{6,14}$/.test(text);
    }
    
    function addButtonsToElement(element, phone) {
        var btns = createButtons(phone);
        element.appendChild(btns);
    }
    
    function addButtonsAfterElement(element, phone) {
        var btns = createButtons(phone);
        if (element.nextSibling) {
            element.parentNode.insertBefore(btns, element.nextSibling);
        } else {
            element.parentNode.appendChild(btns);
        }
    }
    
    function createButtons(phone) {
        var container = document.createElement("span");
        container.className = "twilio-btns";
        container.style.cssText = "display:inline-flex;gap:4px;margin-left:8px;vertical-align:middle;";
        
        // Call button
        var callBtn = document.createElement("button");
        callBtn.type = "button";
        callBtn.title = "Call " + phone;
        callBtn.innerHTML = "📞";
        callBtn.style.cssText = "cursor:pointer;padding:3px 7px;background:#4a90d9;color:#fff;border:none;border-radius:3px;font-size:13px;line-height:1;";
        callBtn.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            var url = CONFIG.callUrl + encodeURIComponent(phone);
            window.open(url, "TwilioCall", "width=450,height=350,scrollbars=yes,resizable=yes");
        };
        
        // SMS button
        var smsBtn = document.createElement("button");
        smsBtn.type = "button";
        smsBtn.title = "SMS " + phone;
        smsBtn.innerHTML = "💬";
        smsBtn.style.cssText = "cursor:pointer;padding:3px 7px;background:#4a90d9;color:#fff;border:none;border-radius:3px;font-size:13px;line-height:1;";
        smsBtn.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            var url = CONFIG.smsUrl + encodeURIComponent(phone);
            window.open(url, "TwilioSMS", "width=450,height=400,scrollbars=yes,resizable=yes");
        };
        
        container.appendChild(callBtn);
        container.appendChild(smsBtn);
        return container;
    }
    
    // Watch for DOM changes (Angular route changes)
    function startObserver() {
        var timeout = null;
        var observer = new MutationObserver(function(mutations) {
            // Debounce - wait for DOM to settle
            clearTimeout(timeout);
            timeout = setTimeout(scanForPhoneNumbers, 500);
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
    
    // Initialize
    function init() {
        console.log("[Twilio CTC] Initializing...");
        
        // Initial scan after page loads
        setTimeout(scanForPhoneNumbers, 1500);
        
        // Re-scan periodically for Angular route changes
        setTimeout(scanForPhoneNumbers, 3000);
        setTimeout(scanForPhoneNumbers, 5000);
        
        // Watch for DOM changes
        startObserver();
    }
    
    // Start when DOM is ready
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
