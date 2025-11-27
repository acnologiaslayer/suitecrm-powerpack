/**
 * Twilio Click-to-Call for SuiteCRM 8 Angular UI
 * Adds call and SMS buttons next to phone numbers
 * v2.2.1 - Enhanced detection for list views and detail views
 */
(function() {
    "use strict";
    
    // Prevent multiple initializations
    if (window.TwilioSuite8Initialized) return;
    window.TwilioSuite8Initialized = true;
    
    var CONFIG = {
        legacyUrl: "legacy/index.php?module=TwilioIntegration",
        callAction: "&action=makecall&phone=",
        smsAction: "&action=sendsms&phone=",
        processedAttr: "data-twilio-processed",
        // Phone field patterns to match
        phoneFields: ["phone", "mobile", "fax", "phone_work", "phone_home", "phone_mobile", "phone_other", "phone_fax", "alt_phone"],
        // Phone number regex - matches international and US formats
        phoneRegex: /^[\+]?[(]?[0-9]{1,4}[)]?[-\s\.]?[(]?[0-9]{1,3}[)]?[-\s\.]?[0-9]{1,4}[-\s\.]?[0-9]{1,4}[-\s\.]?[0-9]{0,9}$/
    };
    
    function init() {
        console.log("[Twilio Suite8] Initializing click-to-call v2.2.1...");
        setTimeout(processPage, 1000);
        observeChanges();
    }
    
    function processPage() {
        console.log("[Twilio Suite8] Processing page...");
        cleanupDuplicates();
        
        // Process different view types
        processListView();
        processDetailView();
        processGenericPhoneElements();
    }
    
    function cleanupDuplicates() {
        document.querySelectorAll("td, span, div, scrm-field").forEach(function(container) {
            var buttons = container.querySelectorAll(".twilio-btn-container");
            if (buttons.length > 1) {
                for (var i = 1; i < buttons.length; i++) {
                    buttons[i].remove();
                }
            }
        });
    }
    
    // Process SuiteCRM 8 list view tables
    function processListView() {
        // SuiteCRM 8 uses scrm-list-view-table-body or standard tables
        var tables = document.querySelectorAll("table, scrm-list-view-table-body");
        
        tables.forEach(function(table) {
            var phoneColIndexes = [];
            var headers = table.querySelectorAll("th, scrm-sort-button");
            
            headers.forEach(function(th, index) {
                var text = (th.textContent || th.innerText || "").toLowerCase();
                for (var i = 0; i < CONFIG.phoneFields.length; i++) {
                    if (text.indexOf(CONFIG.phoneFields[i].replace("_", " ")) >= 0 || 
                        text.indexOf(CONFIG.phoneFields[i]) >= 0) {
                        phoneColIndexes.push(index);
                        break;
                    }
                }
            });
            
            if (phoneColIndexes.length > 0) {
                var rows = table.querySelectorAll("tbody tr, scrm-table-body-entry");
                rows.forEach(function(row) {
                    var cells = row.querySelectorAll("td, scrm-field");
                    phoneColIndexes.forEach(function(colIndex) {
                        var cell = cells[colIndex];
                        if (cell) {
                            processPhoneCell(cell);
                        }
                    });
                });
            }
        });
    }
    
    // Process SuiteCRM 8 detail/record view
    function processDetailView() {
        // Look for phone fields in detail view panels
        // SuiteCRM 8 uses scrm-field components with field names in attributes or parent elements
        
        // Method 1: Find field labels that indicate phone fields
        var labels = document.querySelectorAll("label, .field-label, scrm-label, [class*='label']");
        labels.forEach(function(label) {
            var labelText = (label.textContent || "").toLowerCase();
            var isPhoneField = false;
            
            for (var i = 0; i < CONFIG.phoneFields.length; i++) {
                var fieldName = CONFIG.phoneFields[i].replace(/_/g, " ");
                if (labelText.indexOf(fieldName) >= 0 || labelText === fieldName) {
                    isPhoneField = true;
                    break;
                }
            }
            
            if (isPhoneField) {
                // Find the associated value element
                var parent = label.closest(".form-group, .field-container, .row, scrm-field-layout, [class*='field']");
                if (parent) {
                    var valueEl = parent.querySelector("scrm-field, .field-value, input[type='tel'], [class*='value'], span:not(.twilio-btn-container)");
                    if (valueEl) {
                        processPhoneCell(valueEl);
                    }
                }
                
                // Also check next sibling
                var sibling = label.nextElementSibling;
                if (sibling && !sibling.classList.contains("twilio-btn-container")) {
                    processPhoneCell(sibling);
                }
            }
        });
        
        // Method 2: Find scrm-field elements with phone-related attributes
        var scrmFields = document.querySelectorAll("scrm-field, [class*='phone'], [class*='mobile']");
        scrmFields.forEach(function(field) {
            var fieldType = field.getAttribute("type") || field.getAttribute("ng-reflect-type") || "";
            var fieldName = field.getAttribute("field") || field.getAttribute("ng-reflect-field") || "";
            var className = field.className || "";
            
            var isPhone = fieldType.toLowerCase().indexOf("phone") >= 0 ||
                          fieldName.toLowerCase().indexOf("phone") >= 0 ||
                          className.toLowerCase().indexOf("phone") >= 0 ||
                          className.toLowerCase().indexOf("mobile") >= 0;
            
            if (isPhone) {
                processPhoneCell(field);
            }
        });
    }
    
    // Process any element that might contain a phone number
    function processGenericPhoneElements() {
        // Find inputs with tel type
        var telInputs = document.querySelectorAll("input[type='tel']");
        telInputs.forEach(function(input) {
            if (input.value && isValidPhone(input.value)) {
                var parent = input.parentElement;
                if (parent && !parent.querySelector(".twilio-btn-container")) {
                    addButtonsAfter(input, input.value);
                }
            }
        });
        
        // Find links with tel: protocol
        var telLinks = document.querySelectorAll("a[href^='tel:']");
        telLinks.forEach(function(link) {
            var phone = link.href.replace("tel:", "").trim();
            var parent = link.parentElement;
            if (parent && !parent.querySelector(".twilio-btn-container") && isValidPhone(phone)) {
                addButtonsAfter(link, phone);
            }
        });
    }
    
    function processPhoneCell(cell) {
        if (!cell || cell.getAttribute(CONFIG.processedAttr)) return;
        
        // Skip if already has buttons
        if (cell.querySelector(".twilio-btn-container")) {
            cell.setAttribute(CONFIG.processedAttr, "true");
            return;
        }
        
        var phone = extractPhoneNumber(cell);
        
        if (phone && isValidPhone(phone)) {
            console.log("[Twilio Suite8] Found phone:", phone);
            addButtons(cell, phone);
            cell.setAttribute(CONFIG.processedAttr, "true");
        }
    }
    
    function extractPhoneNumber(element) {
        if (!element) return null;
        
        // Check for tel link first
        var telLink = element.querySelector("a[href^='tel:']");
        if (telLink) {
            return telLink.href.replace("tel:", "").trim();
        }
        
        // Check for any link with phone-like content
        var link = element.querySelector("a");
        if (link && link.textContent && isValidPhone(link.textContent.trim())) {
            return link.textContent.trim();
        }
        
        // Check for input value
        var input = element.querySelector("input");
        if (input && input.value && isValidPhone(input.value.trim())) {
            return input.value.trim();
        }
        
        // Get text content excluding buttons
        var clone = element.cloneNode(true);
        var btns = clone.querySelectorAll(".twilio-btn-container, .twilio-btn, button");
        btns.forEach(function(b) { b.remove(); });
        
        var text = (clone.textContent || clone.innerText || "").trim();
        
        // Clean up the text
        text = text.replace(/\s+/g, " ").trim();
        
        if (isValidPhone(text)) {
            return text;
        }
        
        // Try to extract phone from mixed content
        var phoneMatch = text.match(/[\+]?[(]?[0-9]{1,4}[)]?[-\s\.]?[(]?[0-9]{1,3}[)]?[-\s\.]?[0-9]{1,4}[-\s\.]?[0-9]{1,4}[-\s\.]?[0-9]{0,9}/);
        if (phoneMatch && phoneMatch[0].replace(/\D/g, "").length >= 7) {
            return phoneMatch[0].trim();
        }
        
        return null;
    }
    
    function isValidPhone(str) {
        if (!str || typeof str !== "string") return false;
        var cleaned = str.replace(/[\s\-().+]/g, "");
        return cleaned.length >= 7 && cleaned.length <= 20 && /^\d+$/.test(cleaned);
    }
    
    function addButtons(element, phone) {
        if (element.querySelector(".twilio-btn-container")) return;
        
        var container = createButtonContainer(phone);
        element.appendChild(container);
    }
    
    function addButtonsAfter(element, phone) {
        var parent = element.parentElement;
        if (!parent || parent.querySelector(".twilio-btn-container")) return;
        
        var container = createButtonContainer(phone);
        
        if (element.nextSibling) {
            parent.insertBefore(container, element.nextSibling);
        } else {
            parent.appendChild(container);
        }
    }
    
    function createButtonContainer(phone) {
        var container = document.createElement("span");
        container.className = "twilio-btn-container";
        container.style.cssText = "display:inline-flex;gap:3px;margin-left:8px;vertical-align:middle;white-space:nowrap;";
        
        var callBtn = document.createElement("button");
        callBtn.type = "button";
        callBtn.className = "twilio-btn twilio-call-btn";
        callBtn.title = "Call " + phone;
        callBtn.innerHTML = "📞";
        callBtn.style.cssText = "cursor:pointer;padding:4px 8px;background:#4a90d9;color:#fff;border:none;border-radius:4px;font-size:14px;line-height:1;";
        callBtn.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            openWindow(CONFIG.legacyUrl + CONFIG.callAction + encodeURIComponent(phone), "TwilioCall", 500, 400);
        };
        
        var smsBtn = document.createElement("button");
        smsBtn.type = "button";
        smsBtn.className = "twilio-btn twilio-sms-btn";
        smsBtn.title = "SMS " + phone;
        smsBtn.innerHTML = "💬";
        smsBtn.style.cssText = "cursor:pointer;padding:4px 8px;background:#4a90d9;color:#fff;border:none;border-radius:4px;font-size:14px;line-height:1;";
        smsBtn.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            openWindow(CONFIG.legacyUrl + CONFIG.smsAction + encodeURIComponent(phone), "TwilioSMS", 500, 500);
        };
        
        container.appendChild(callBtn);
        container.appendChild(smsBtn);
        
        return container;
    }
    
    function openWindow(url, name, width, height) {
        var left = (screen.width - width) / 2;
        var top = (screen.height - height) / 2;
        window.open(url, name, "width=" + width + ",height=" + height + ",left=" + left + ",top=" + top + ",scrollbars=yes,resizable=yes");
    }
    
    function observeChanges() {
        if (typeof MutationObserver === "undefined") return;
        
        var debounceTimer;
        var observer = new MutationObserver(function(mutations) {
            // Only process if there are relevant changes
            var shouldProcess = mutations.some(function(m) {
                return m.addedNodes.length > 0 || m.type === "characterData";
            });
            
            if (shouldProcess) {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(processPage, 500);
            }
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true
        });
    }
    
    // Initialize
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        setTimeout(init, 500);
    }
})();
