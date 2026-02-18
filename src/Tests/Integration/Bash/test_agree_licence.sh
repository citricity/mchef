#!/bin/bash

set -e  # Exit on any error

echo "Testing --agree-license flag functionality"
echo "=========================================="

# Remove any existing terms agreement to test the flag
# Get the actual config directory from mchef
CONFIG_DIR=$(php mchef.php config --get-config-dir 2>/dev/null | sed 's/.*Config directory: //')
TERMS_FILE="$CONFIG_DIR/TERMSAGREED.txt"
echo "Config directory: $CONFIG_DIR"
echo "Removing existing terms file: $TERMS_FILE"
rm -f "$TERMS_FILE"

echo ""
echo "Test 1: Running --agree-license without command should succeed"
php mchef.php --agree-license
if [ $? -eq 0 ]; then
    echo "✅ --agree-license completed successfully"
else
    echo "❌ --agree-license failed"
    exit 1
fi

echo ""
echo "Test 2: Checking that terms file was created"
if [ -f "$TERMS_FILE" ]; then
    echo "✅ Terms agreement file created at: $TERMS_FILE"
else
    echo "❌ Terms agreement file not found"
    exit 1
fi

echo ""
echo "Test 3: Running command after terms agreed should work silently"
php mchef.php --version > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo "✅ Commands work after terms agreement"
else
    echo "❌ Commands failed after terms agreement"
    exit 1
fi

echo ""
echo "🎉 All --agree-license tests passed!"
echo ""
echo "Usage examples:"
echo "  mchef --agree-license list"
echo "  mchef --agree-license recipe.json"
echo "  mchef --agree-license --version"