# SQLuxe Android App Deployment Guide 🚀

To get your app running on Android **ASAP**, follow these steps. 

## 1. Prerequisites
- **Node.js** installed.
- **Android Studio** installed and configured with an Emulator or physical device.

## 2. Fast Build & Deploy (The "Turbo" Way)
Run these commands from the `/app` directory:

```bash
# 1. Install dependencies (First time only)
npm install

# 2. Initialize the Android platform
npx cap add android

# 3. Sync your web code to the Android project
npx cap sync android

# 4. Open the project in Android Studio
npx cap open android
```

## 3. Deployment Cycles
Whenever you change the UI in `www/`, just run:
```bash
npx cap copy android
```
Then hit **Run** in Android Studio. It’s nearly instant.

## 4. Connecting Servers
- Launch the app.
- Click the **Grid Icon** (top right) -> **Add Server**.
- Use the `API_KEY` defined in `db.php` (Default: `sqluxe_secret_key_2026`).
- Your endpoint is the full URL to your `db.php` file.

## 5. Security Note
> [!IMPORTANT]
> For production, change the `API_KEY` in both `server/db.php` and your mobile app server configuration.
