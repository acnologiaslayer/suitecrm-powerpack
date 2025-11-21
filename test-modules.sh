#!/bin/bash
# Test script to verify module structure and installation readiness

echo "=========================================="
echo "Module Installation Verification Test"
echo "=========================================="
echo ""

MODULES=("TwilioIntegration" "LeadJourney" "FunnelDashboard")
FAILED=0

for MODULE in "${MODULES[@]}"; do
    echo "Testing $MODULE..."
    MODULE_PATH="custom-modules/$MODULE"
    
    # Check essential files
    if [ ! -f "$MODULE_PATH/$MODULE.php" ]; then
        echo "  ✗ Missing main class file: $MODULE.php"
        FAILED=$((FAILED + 1))
    else
        echo "  ✓ Main class file exists"
    fi
    
    if [ ! -f "$MODULE_PATH/manifest.php" ]; then
        echo "  ✗ Missing manifest.php"
        FAILED=$((FAILED + 1))
    else
        echo "  ✓ Manifest exists"
        # Check manifest syntax
        php -l "$MODULE_PATH/manifest.php" > /dev/null 2>&1
        if [ $? -eq 0 ]; then
            echo "  ✓ Manifest syntax valid"
        else
            echo "  ✗ Manifest has syntax errors"
            FAILED=$((FAILED + 1))
        fi
    fi
    
    if [ ! -f "$MODULE_PATH/vardefs.php" ]; then
        echo "  ✗ Missing vardefs.php"
        FAILED=$((FAILED + 1))
    else
        echo "  ✓ Vardefs exists"
    fi
    
    if [ ! -f "$MODULE_PATH/Menu.php" ]; then
        echo "  ✗ Missing Menu.php"
        FAILED=$((FAILED + 1))
    else
        echo "  ✓ Menu.php exists"
    fi
    
    if [ ! -d "$MODULE_PATH/language" ]; then
        echo "  ✗ Missing language directory"
        FAILED=$((FAILED + 1))
    else
        if [ ! -f "$MODULE_PATH/language/en_us.lang.php" ]; then
            echo "  ✗ Missing en_us.lang.php"
            FAILED=$((FAILED + 1))
        else
            echo "  ✓ Language file exists"
        fi
    fi
    
    if [ ! -d "$MODULE_PATH/views" ]; then
        echo "  ⚠  No views directory (may be optional)"
    else
        echo "  ✓ Views directory exists"
    fi
    
    echo ""
done

# Check for Extensions
echo "Checking Extensions..."
if [ -d "custom-modules/TwilioIntegration/Extensions" ]; then
    echo "  ✓ TwilioIntegration Extensions exist"
    if [ -f "custom-modules/TwilioIntegration/Extensions/modules/Contacts/Ext/Vardefs/twilio_js.php" ]; then
        echo "  ✓ Contacts vardef extension exists"
    else
        echo "  ✗ Missing Contacts vardef extension"
        FAILED=$((FAILED + 1))
    fi
    if [ -f "custom-modules/TwilioIntegration/Extensions/modules/Leads/Ext/Vardefs/twilio_js.php" ]; then
        echo "  ✓ Leads vardef extension exists"
    else
        echo "  ✗ Missing Leads vardef extension"
        FAILED=$((FAILED + 1))
    fi
else
    echo "  ✗ TwilioIntegration Extensions directory missing"
    FAILED=$((FAILED + 1))
fi

if [ -d "custom-modules/LeadJourney/Extensions" ]; then
    echo "  ✓ LeadJourney Extensions exist"
    if [ -f "custom-modules/LeadJourney/Extensions/modules/Contacts/Ext/Vardefs/journey_button.php" ]; then
        echo "  ✓ Contacts journey button exists"
    else
        echo "  ✗ Missing Contacts journey button"
        FAILED=$((FAILED + 1))
    fi
    if [ -f "custom-modules/LeadJourney/Extensions/modules/Leads/Ext/Vardefs/journey_button.php" ]; then
        echo "  ✓ Leads journey button exists"
    else
        echo "  ✗ Missing Leads journey button"
        FAILED=$((FAILED + 1))
    fi
else
    echo "  ✗ LeadJourney Extensions directory missing"
    FAILED=$((FAILED + 1))
fi

echo ""
echo "Checking installation scripts..."
if [ -f "install-scripts/install-modules.sh" ]; then
    echo "  ✓ install-modules.sh exists"
    if [ -x "install-scripts/install-modules.sh" ] || grep -q "^#!/bin/bash" "install-scripts/install-modules.sh"; then
        echo "  ✓ install-modules.sh is executable/has shebang"
    else
        echo "  ⚠  install-modules.sh may need execute permissions"
    fi
else
    echo "  ✗ Missing install-modules.sh"
    FAILED=$((FAILED + 1))
fi

if [ -f "install-scripts/enable-modules-suite8.sh" ]; then
    echo "  ✓ enable-modules-suite8.sh exists"
else
    echo "  ✗ Missing enable-modules-suite8.sh"
    FAILED=$((FAILED + 1))
fi

echo ""
echo "=========================================="
if [ $FAILED -eq 0 ]; then
    echo "✓ All checks passed! Modules ready for installation."
    echo "=========================================="
    exit 0
else
    echo "✗ $FAILED checks failed. Please fix issues above."
    echo "=========================================="
    exit 1
fi
