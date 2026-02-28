# Android Build Guide for ServerLuxe 📱

Complete guide to building the ServerLuxe Android app locally.

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Development Setup](#development-setup)
3. [Building for Testing](#building-for-testing)
4. [Debug Build](#debug-build)
5. [Release Build](#release-build)
6. [Troubleshooting](#troubleshooting)
7. [Technical Specifications](#technical-specifications)

---

## Prerequisites

Before starting, ensure you have:

### Required Software
- ✅ **Node.js** v14 or higher - [Download](https://nodejs.org/)
- ✅ **npm** (comes with Node.js)
- ✅ **Java Development Kit (JDK)** 11+ - [Download](https://www.oracle.com/java/technologies/downloads/)
- ✅ **Android SDK** - Via Android Studio
- ✅ **Android Studio** 2021.3 or newer - [Download](https://developer.android.com/studio)
- ✅ **Gradle** 8.2.1+ (usually auto-configured by Android Studio)

### System Requirements
- **Disk Space**: 10GB for SDK and tools
- **RAM**: 8GB minimum (16GB recommended)
- **OS**: Windows, macOS, or Linux

### Environment Variables

**Windows:**
```batch
JAVA_HOME=C:\Program Files\Java\jdk-11
ANDROID_SDK_ROOT=C:\Users\YourUsername\AppData\Local\Android\Sdk
ANDROID_HOME=C:\Users\YourUsername\AppData\Local\Android\Sdk
```

**macOS/Linux:**
```bash
export JAVA_HOME=$(/usr/libexec/java_home -v 11)
export ANDROID_SDK_ROOT=$HOME/Library/Android/sdk
export ANDROID_HOME=$HOME/Library/Android/sdk
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

### Step 3: Initialize Android Platform

```bash
# Add Android to Capacitor (first time only)
npx cap add android

# Sync web files to Android project
npx cap sync android
```

Expected directory structure:
```
app/
├── www/                # Web assets
├── android/            # Android native project
│   ├── app/
│   ├── build.gradle
│   └── gradle.properties
├── capacitor.config.json
└── package.json
```

### Step 4: Open in Android Studio

```bash
# Open Android project in Android Studio
npx cap open android
```

Or manually:
1. Open Android Studio
2. **File** → **Open** → Navigate to `mysqlweb/app/android`
3. Click **Open**

### Step 5: Configure Build Environment

In Android Studio:

1. **File** → **Project Structure**
2. **SDK Location** tab - Verify SDK path is correct
3. Click **Next** and wait for Gradle sync

---

## Building for Testing

### Development Build (For Local Testing)

1. **Connect Device or Start Emulator**
   ```bash
   # List connected devices
   adb devices

   # Create/start emulator from Android Studio:
   # Tools → AVD Manager → Create Virtual Device
   ```

2. **Sync Latest Changes** (if you modified www/)
   ```bash
   npx cap copy android
   ```

3. **Build in Android Studio**
   - Select target device/emulator from dropdown
   - Click **Run** button (green play icon)
   - Or press `Shift + F10`
   - Wait for build and install (~2-3 minutes first time)

4. **Access App**
   - App launches automatically on device
   - Tap **Grid Icon** (top right) → **+ NEW NODE**
   - Enter your server details

### Testing Checklist

Before proceeding to distribution, test:

- [ ] Login works with correct password
- [ ] Login fails with incorrect password
- [ ] Add node with valid URL works
- [ ] Add node with invalid URL shows error
- [ ] Browse database tables
- [ ] Execute queries
- [ ] View/edit rows
- [ ] Upload files to file manager
- [ ] Browse and delete files
- [ ] Works on both landscape and portrait
- [ ] Works on Android 5.1+ (various Android versions)
- [ ] API key is stored securely
- [ ] Can switch between multiple nodes
- [ ] Export/import nodes works
- [ ] QR code scanning works (if enabled)

---

## Debug Build

### Build Debug APK

```bash
cd app/android

# Build debug APK
./gradlew assembleDebug

# APK location: app/build/outputs/apk/debug/app-debug.apk
```

### Install Debug APK

```bash
# Install on connected device
adb install -r app/build/outputs/apk/debug/app-debug.apk

# Or via Android Studio: Run > Run... > Select APK
```

### View Debug Logs

```bash
# View all logs
adb logcat

# Filter for app logs
adb logcat | grep "ServerLuxe"

# Save logs to file
adb logcat > logs.txt
```

---

## Release Build

### Step 1: Update Version Numbers

Edit `app/android/app/build.gradle`:

```gradle
android {
    defaultConfig {
        versionCode 1          // Increment by 1 for each new build
        versionName "1.0.0"    // User-visible version (semantic versioning)
    }
}
```

**Important**:
- `versionCode` MUST increase with each build
- `versionName` is user-visible (e.g., 1.0.0, 1.1.0)

### Step 2: Build Release APK (Unsigned)

```bash
cd app/android

# Clean previous builds
./gradlew clean

# Build release APK
./gradlew assembleRelease

# APK location: app/build/outputs/apk/release/app-release-unsigned.apk
```

### Step 3: Sign Release APK

**Create Signing Key** (First Time Only):

```bash
# Generate keystore
keytool -genkey -v -keystore serverluxe.keystore \
  -keyalg RSA \
  -keysize 2048 \
  -validity 10000 \
  -alias serverluxe
```

When prompted, enter your information. Remember the passwords!

**Sign APK:**

```bash
# Sign the APK
jarsigner -verbose -sigalg SHA256withRSA -digestalg SHA-256 \
  -keystore serverluxe.keystore \
  app/build/outputs/apk/release/app-release-unsigned.apk \
  serverluxe
```

**Optimize with ZipAlign:**

```bash
# Optimize APK file
zipalign -v 4 app/build/outputs/apk/release/app-release-unsigned.apk \
  app-release-signed.apk
```

### Step 4: Verify Signed APK

```bash
# Check APK signature
jarsigner -verify -verbose app-release-signed.apk

# Expected output: jar verified OK
```

### Step 5: Install & Test Release APK

```bash
# Install on device
adb install -r app-release-signed.apk

# Test thoroughly on multiple devices
```

---

## Faster Release Build (Automated Signing)

For faster builds, configure automatic signing in Gradle:

Edit `app/android/app/build.gradle`:

```gradle
signingConfigs {
    release {
        storeFile file('serverluxe.keystore')
        storePassword 'your_keystore_password'
        keyAlias 'serverluxe'
        keyPassword 'your_key_password'
    }
}

buildTypes {
    release {
        signingConfig signingConfigs.release
    }
}
```

Then build with one command:

```bash
./gradlew assembleRelease

# APK will be automatically signed at:
# app/build/outputs/apk/release/app-release.apk
```

**⚠️ Security Note**: Store keystore file safely and add to `.gitignore`:
```
*.keystore
*.jks
signing.properties
```

---

## Troubleshooting

### Build Issues

**Error: "Build failed with an exception"**
```bash
# Solution: Clean build
./gradlew clean
./gradlew assembleRelease
```

**Error: "Gradle sync failed"**
```bash
# Solution 1: Update Gradle
./gradlew wrapper --gradle-version 8.2.1

# Solution 2: Invalidate cache in Android Studio
File → Invalidate Caches → Invalidate and Restart
```

**Error: "Java version mismatch"**
```bash
# Check Java version
java -version

# Should be 11+
# Set JAVA_HOME if needed
```

**Error: "SDK not found"**
```bash
# In Android Studio: Tools → SDK Manager
# Install:
# - Android SDK Platform 34
# - Android SDK Build Tools 34.0.0
# - Android SDK Cmdline Tools
```

### Signing Issues

**Error: "Keystore was tampered with, or password was incorrect"**
```bash
# Verify keystore
keytool -list -v -keystore serverluxe.keystore

# Check password is correct
```

**Error: "Key was created with an older SDK"**
```bash
# Regenerate keystore
rm serverluxe.keystore
keytool -genkey -v -keystore serverluxe.keystore \
  -keyalg RSA -keysize 2048 -validity 10000 -alias serverluxe
```

### Runtime Issues

**App crashes on startup**
```bash
# Check device logs
adb logcat | grep -A 10 "ServerLuxe"
```

**API not responding in app**
- Verify server URL is accessible from device
- Check firewall/proxy settings
- Ensure API key is correct
- Test API: `curl -H "X-API-KEY: 2026" https://server.com/db.php`

**APK won't install**
```bash
# Check device compatibility
adb install -r app-release-signed.apk

# View detailed error
adb install -r app-release-signed.apk -d  # Allow downgrade
```

---

## Technical Specifications

### Build Configuration

```gradle
// app/android/variables.gradle
ext {
    minSdkVersion = 22          // Android 5.1
    compileSdkVersion = 34      // Android 14
    targetSdkVersion = 34       // Latest

    androidxActivityVersion = '1.7.0'
    androidxAppCompatVersion = '1.6.1'
    androidxCoordinatorLayoutVersion = '1.2.0'
    androidxCoreVersion = '1.10.0'
}
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

### Build Output Sizes

- **Debug APK**: ~80-120 MB
- **Release APK**: ~40-60 MB (optimized)

### Supported Android Versions

- **Minimum**: Android 5.1 (API 22)
- **Target**: Android 14 (API 34)
- **Recommended Testing**: Android 6.0, 10, 11, 12, 13, 14

---

## Quick Reference Commands

```bash
# Initial setup
npm install
npx cap add android
npx cap sync android

# Development workflow
npx cap copy android          # After changing www/
# Then click Run in Android Studio

# Debug build
./gradlew assembleDebug
adb install -r app/build/outputs/apk/debug/app-debug.apk

# Release build
./gradlew clean
./gradlew assembleRelease
jarsigner -verbose -sigalg SHA256withRSA -digestalg SHA-256 \
  -keystore serverluxe.keystore \
  app/build/outputs/apk/release/app-release-unsigned.apk \
  serverluxe
zipalign -v 4 app/build/outputs/apk/release/app-release-unsigned.apk \
  app-release-signed.apk
jarsigner -verify -verbose app-release-signed.apk
adb install -r app-release-signed.apk

# View logs
adb logcat | grep "ServerLuxe"
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
├── android/
│   ├── app/
│   │   ├── build.gradle     # App build config
│   │   └── src/
│   ├── build.gradle         # Root gradle
│   └── variables.gradle     # SDK versions
├── package.json
├── capacitor.config.json
└── serverluxe.keystore      # Signing key (keep safe!)
```

---

## Next Steps

1. Complete setup (Steps 1-5)
2. Build debug APK and test on device
3. Test all features thoroughly
4. Build release APK when ready
5. Keep keystore file safely backed up
6. For distribution, use built APK with your own process

---

## Support

- **Android Studio Help**: [developer.android.com](https://developer.android.com)
- **Capacitor Docs**: [capacitorjs.com](https://capacitorjs.com)
- **Contact**: support@bycomsolutions.com

---

**Last Updated**: February 2025
**Gradle Version**: 8.2.1
**Capacitor Version**: 5.0.0
**Min SDK**: 22 (Android 5.1)
