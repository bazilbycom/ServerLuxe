# iOS Build Guide for ServerLuxe 📱

Complete guide to building the ServerLuxe iOS app from source code.

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Development Setup](#development-setup)
3. [Building for Testing](#building-for-testing)
4. [Building Release Build](#building-release-build)
5. [Troubleshooting](#troubleshooting)
6. [Technical Specifications](#technical-specifications)

---

## Prerequisites

Before starting, ensure you have:

### Required Software
- ✅ **Mac with Xcode** 14+ installed - [Download from App Store](https://apps.apple.com/us/app/xcode/id497799835)
- ✅ **Node.js** v14 or higher - [Download](https://nodejs.org/)
- ✅ **npm** (comes with Node.js)
- ✅ **CocoaPods** - Install with: `sudo gem install cocoapods`
- ✅ **Git** - Usually pre-installed on macOS

### System Requirements
- **Disk Space**: 15GB for Xcode and dependencies
- **RAM**: 8GB minimum (16GB recommended)
- **OS**: macOS 12.0 (Monterey) or newer
- **Device**: iPhone/iPad with iOS 13.0 or newer (for testing)

### Verify Setup

```bash
# Check Node.js
node --version          # v14 or higher

# Check npm
npm --version          # 6.0 or higher

# Check Xcode
xcode-select --print-path
# Should output: /Applications/Xcode.app/Contents/Developer

# Check CocoaPods
pod --version          # 1.0 or higher
```

---

## Development Setup

### Step 1: Clone Repository

```bash
git clone https://github.com/yourusername/mysqlweb.git
cd mysqlweb/app
```

### Step 2: Install Dependencies

```bash
# Install Node dependencies
npm install

# Verify Capacitor is installed
npx cap --version
```

Expected output: `Capacitor CLI, version 5.x.x`

### Step 3: Initialize iOS Platform

```bash
# Add iOS to Capacitor (first time only)
npx cap add ios

# Sync web files to iOS project
npx cap sync ios

# Install CocoaPods dependencies
cd ios/App
pod install
cd ../..
```

Expected directory structure:
```
app/
├── www/                # Web assets
├── ios/                # iOS native project
│   └── App/
│       ├── App.xcworkspace
│       ├── Podfile
│       └── Pods/
├── capacitor.config.json
└── package.json
```

### Step 4: Open in Xcode

```bash
# Open iOS project in Xcode
npx cap open ios
```

**IMPORTANT**: Always open `App.xcworkspace` (not `App.xcodeproj`)

Xcode will open automatically. If not:
1. Open Xcode manually
2. **File** → **Open** → Navigate to `mysqlweb/app/ios/App/App.xcworkspace`
3. Click **Open**

### Step 5: Configure App Settings

In Xcode:

1. Select **App** target (left sidebar)
2. Go to **General** tab
3. **Identity** section:
   - **Bundle Identifier**: `com.bycomsolutions.serverluxe` (or your own)
   - **Version**: 1.0 (user-visible)
   - **Build**: 1 (internal increment)
4. **Minimum Deployments**:
   - Set to **iOS 13.0**

### Step 6: Trust Development Certificate

```bash
# On your development Mac
security unlock-keychain login.keychain

# In Xcode, go to Preferences → Accounts
# Add your Apple ID (even for development)
# Xcode will automatically manage development certificate
```

---

## Building for Testing

### Development Build (For Local Testing on Simulator)

1. **Build in Xcode**
   - Select **Simulator** → **iPhone 14** (or any simulator)
   - Click **Play** button (or press `Cmd+R`)
   - Xcode builds, compiles, and launches on simulator

2. **Test the App**
   - App launches automatically
   - Tap **Grid Icon** (top right) → **+ NEW NODE**
   - Enter your server details
   - Test database and file manager functions

3. **View Console Output**
   - **View** → **Debug Area** → **Show Console** (or press `Shift+Cmd+C`)
   - Filter for app logs

### Development Build (For Testing on Physical Device)

1. **Connect Physical Device**
   - Connect iPhone/iPad via USB cable
   - Trust the device when prompted
   - Unlock device

2. **Select Device in Xcode**
   - At top of Xcode, select your device from dropdown (not simulator)

3. **Configure Code Signing**
   - Select **App** target
   - Go to **Signing & Capabilities** tab
   - Select your **Team** (Apple Developer Account)
   - Xcode auto-manages signing certificates

4. **Build and Run**
   - Click **Play** button (or press `Cmd+R`)
   - Xcode builds and installs app on device
   - App launches automatically

5. **Trust Developer Profile**
   - On device, go to **Settings** → **General** → **Device Management**
   - Trust your developer certificate

### Testing Checklist

Before proceeding, test:

- [ ] Login works with correct password
- [ ] Login fails with incorrect password
- [ ] Add node with valid URL works
- [ ] Add node with invalid URL shows error
- [ ] Browse database tables
- [ ] Execute queries
- [ ] View/edit rows
- [ ] Upload files to file manager
- [ ] Browse and delete files
- [ ] Works in landscape and portrait
- [ ] Works on iPhone and iPad
- [ ] API key is stored securely
- [ ] Can switch between multiple nodes
- [ ] Export/import nodes works
- [ ] QR code scanning works (if enabled)

---

## Building Release Build

### Step 1: Update Version Numbers

In Xcode:

1. Select **App** target
2. Go to **General** tab
3. Update:
   - **Version**: 1.0 (user-visible, follow semantic versioning)
   - **Build**: 2 (increment for each build)

### Step 2: Create Release Configuration

In Xcode:

1. Select **App** target
2. Go to **Build Settings** tab
3. Search for "optimization level"
4. Set **Optimization Level**:
   - Release: `Fastest, Smallest` (`-Oz`)
   - Debug: `None` (`-O0`)

### Step 3: Build Release for Simulator

```bash
# Build for simulator (testing)
xcodebuild -workspace ios/App/App.xcworkspace \
  -scheme App \
  -configuration Release \
  -sdk iphonesimulator \
  -derivedDataPath build
```

### Step 4: Build Release for Device

```bash
# Build for generic iOS device (for testing or archiving)
xcodebuild -workspace ios/App/App.xcworkspace \
  -scheme App \
  -configuration Release \
  -sdk iphoneos \
  -derivedDataPath build
```

### Step 5: Create Archive (for Distribution)

In Xcode:

1. Make sure **Simulator** is NOT selected - select **Generic iOS Device**
2. **Product** → **Archive**
3. Wait for build to complete
4. Archives window opens
5. Select your archive and click **Distribute App** (see next steps)

Or via command line:

```bash
xcodebuild -workspace ios/App/App.xcworkspace \
  -scheme App \
  -configuration Release \
  -archivePath build/ServerLuxe.xcarchive \
  archive
```

### Step 6: Export IPA (Optional)

```bash
# Export IPA file for distribution
xcodebuild -exportArchive \
  -archivePath build/ServerLuxe.xcarchive \
  -exportOptionsPlist ExportOptions.plist \
  -exportPath build/ipa
```

Create `ExportOptions.plist`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>destination</key>
    <string>generic/platform=iOS</string>
    <key>signingStyle</key>
    <string>automatic</string>
    <key>method</key>
    <string>development</string>
    <key>teamID</key>
    <string>YOUR_TEAM_ID</string>
</dict>
</plist>
```

---

## Testing Before Building

### Run on Simulator

```bash
# Build and run on default simulator
xcodebuild -workspace ios/App/App.xcworkspace \
  -scheme App \
  -configuration Debug \
  -sdk iphonesimulator

# Test in Xcode simulator
```

### View Logs and Debugging

In Xcode:

1. **View** → **Debug Area** → **Show Console** (Shift+Cmd+C)
2. Check for errors and warnings
3. Use breakpoints for debugging (click line number)

---

## Troubleshooting

### Setup Issues

**Error: "Pod install failed"**
```bash
cd ios/App
rm -rf Pods
rm Podfile.lock
pod install
```

**Error: "CocoaPods not found"**
```bash
# Install CocoaPods
sudo gem install cocoapods

# Update CocoaPods
sudo gem install cocoapods --upgrade
```

**Error: "Xcode command line tools not found"**
```bash
# Install Xcode CLI tools
xcode-select --install

# Or set path
sudo xcode-select --switch /Applications/Xcode.app/Contents/Developer
```

### Build Issues

**Error: "Build failed with no matching provisioning profile"**
1. In Xcode, select **App** target
2. Go to **Signing & Capabilities**
3. Select your **Team**
4. Ensure **Bundle Identifier** is unique
5. Rebuild

**Error: "No development team selected"**
1. **Xcode** → **Preferences** → **Accounts**
2. Click **+** to add Apple ID
3. In Xcode target, go to **Signing & Capabilities**
4. Select your team from dropdown

**Error: "Minimum deployment target"**
1. Select **App** target
2. Go to **Build Settings**
3. Search for "iOS Deployment Target"
4. Set to **13.0** or higher

**Error: "Swift compiler error"**
```bash
# Clean build folder
cd ios/App
rm -rf Pods
rm Podfile.lock
pod install

# Clean Xcode
Cmd+Shift+K
```

### Runtime Issues

**App crashes on startup**
1. Check Xcode console for errors
2. Verify Capacitor plugins are loaded
3. Check `capacitor.config.json` is valid JSON

**API not responding**
- Verify server URL is accessible from device/simulator
- Check firewall/proxy settings
- Ensure API key is correct
- Test with curl: `curl -H "X-API-KEY: 2026" https://server.com/db.php`

**Simulator networking issues**
```bash
# Restart simulator
xcrun simctl shutdown all
xcrun simctl erase all

# Or from Xcode: Device > Erase All Content and Settings...
```

---

## Technical Specifications

### Build Configuration

```
Xcode Version: 14+
Swift Version: 5.7+
iOS Minimum: 13.0
iOS Target: 14.0+
```

### Capacitor Configuration

```json
{
  "appId": "com.bycomsolutions.bazilbycom.serverluxe.github",
  "appName": "ServerLuxe",
  "webDir": "www",
  "plugins": {
    "StatusBar": {
      "overlaysWebView": false,
      "style": "DARK",
      "backgroundColor": "#1E293B"
    }
  }
}
```

### Supported iOS Versions

- **Minimum**: iOS 13.0
- **Target**: iOS 14.0+
- **Recommended Testing**: iOS 14, 15, 16, 17

### App Size

- **Installed Size**: ~80-120 MB
- **Download Size**: ~30-50 MB (with compression)

---

## Quick Reference Commands

```bash
# Initial setup
npm install
npx cap add ios
cd ios/App && pod install && cd ../..

# Development workflow
npx cap copy ios           # After changing www/
# Then click Play in Xcode

# Build for simulator
xcodebuild -workspace ios/App/App.xcworkspace \
  -scheme App \
  -configuration Debug \
  -sdk iphonesimulator

# Build for device
xcodebuild -workspace ios/App/App.xcworkspace \
  -scheme App \
  -configuration Release \
  -sdk iphoneos

# Create archive
xcodebuild -workspace ios/App/App.xcworkspace \
  -scheme App \
  -configuration Release \
  -archivePath build/ServerLuxe.xcarchive \
  archive

# View logs in Xcode
# View > Debug Area > Show Console (Shift+Cmd+C)
```

---

## File Structure

```
app/
├── www/
│   ├── index.html           # Database UI
│   ├── fm.html              # File Manager UI
│   ├── ui.js                # Shared components
│   └── styles/
├── ios/
│   └── App/
│       ├── App.xcworkspace  # ← Always open this
│       ├── App.xcodeproj
│       ├── App/
│       │   ├── AppDelegate.swift
│       │   └── ViewController.swift
│       ├── Podfile
│       └── Pods/
├── package.json
└── capacitor.config.json
```

---

## Development Workflow

1. **Make changes to web files** (`www/`)
   ```bash
   npx cap copy ios
   ```

2. **Open in Xcode**
   ```bash
   npx cap open ios
   ```

3. **Select simulator/device** from Xcode dropdown

4. **Click Play** button to build and run (Cmd+R)

5. **Test changes** on simulator or device

6. **Repeat** for next feature

---

## Next Steps

1. Complete setup (Steps 1-6)
2. Build debug version and test on simulator/device
3. Test all features thoroughly
4. Build release version when ready
5. For distribution, follow your own process (TestFlight, App Store, etc.)

---

## Support

- **Xcode Help**: Help menu in Xcode
- **Capacitor Docs**: [capacitorjs.com](https://capacitorjs.com)
- **Apple Developer**: [developer.apple.com](https://developer.apple.com)
- **Contact**: support@bycomsolutions.com

---

**Last Updated**: February 2025
**Xcode Version**: 14+
**Capacitor Version**: 5.0.0
**Min iOS**: 13.0
