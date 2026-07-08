# Bingwa Sokoni

**Bingwa Sokoni** is a mobile application built on Laravel and NativePHP Mobile that automates the sale of data bundles, SMS, and minutes via USSD automation and M-Pesa integration. It allows agents to buy and sell airtime at wholesale rates and automate Safaricom Bingwa Sokoni offers (supporting purchases both with and without active Okoa Jahazi balances).

---

## ⚡ Key Features

- **Automated Data Bundle Sales:** Fully automates Safaricom & Airtel bundle purchases using background USSD execution and M-Pesa API integration.
- **Bingwa Sokoni Offers:** Purchase cheap Safaricom Bingwa Sokoni offers automatically (with options to bypass or clear Okoa Jahazi).
- **Wholesale Airtime:** Buy and sell airtime at discounted wholesale rates.
- **Multiturn Packages:** Cheap data bundles, SMS, and minutes for Safaricom and Airtel—available 24/7 and valid for 24 hours.
- **Automated Renewals & Replies:** Set up automatic renewals for expired offers and automatic SMS/USSD replies.
- **Native Device Operations:** Utilizes NativePHP Mobile features like contacts list, background scheduling, local SQLite, push notifications, and device hardware (vibration, battery info, etc.).

---

## 🛠️ Technology Stack

- **Backend Framework:** Laravel 13 & PHP 8.5
- **Frontend Stack:** Livewire 4, Alpine.js, Flux UI, and TailwindCSS v4
- **Native Runtime:** [NativePHP Mobile](https://nativephp.com/docs/mobile/3/) (allows running full PHP + SQLite on iOS and Android devices without a web server)
- **Database:** SQLite (local device persistence)
- **Authentication:** Laravel Fortify (persistent authentication on mobile)
- **Background Tasks:** `nativephp/mobile-background-tasks` & `statum/native-scheduler`

---

## 📁 Project Structure

The project follows a standard Laravel application structure customized for NativePHP Mobile:

- **`app/Livewire/`**: Contains Livewire Action components.
- **`app/Models/`**: Database models including `Offer.php`, `Plan.php`, `Transaction.php`, `AutoRenewal.php`, `AutoReply.php`, and `BingwaDeviceRegistration.php`.
- **`config/nativephp.php`**: NativePHP mobile configuration, runtime mode, and build settings for iOS & Android.
- **`resources/views/components/`**: Layouts and pages prefixed with `⚡` (e.g., `⚡dashboard.blade.php`, `⚡plans.blade.php`, `⚡offers.blade.php`) containing the main application user interfaces.
- **`routes/web.php` & `routes/settings.php`**: Routing declarations for application views and settings pages.

---

## 🚀 Installation & Local Development

### 1. Prerequisites

- PHP 8.3 or 8.5
- Composer
- Node.js & npm
- CocoaPods (for iOS build) or Android SDK (for Android build)

### 2. Setup Project

Clone the repository and run the setup script:

```bash
composer run setup
```

The setup script automatically:
1. Installs composer dependencies
2. Copies `.env.example` to `.env`
3. Generates the application key
4. Runs database migrations
5. Installs npm packages
6. Builds the frontend assets

### 3. Running Local Web Server

To run the application inside a browser developer environment:

```bash
composer run dev
```

This starts:
- Laravel HTTP server (`php artisan serve`)
- Queue listener (`php artisan queue:listen`)
- Vite dev server (`npm run dev`)
- Laravel Pail log stream (`php artisan pail`)

---

## 📱 Build & Run on Mobile (iOS / Android)

> [!IMPORTANT]
> Always build frontend assets using the correct mode before running compiler builds.

### Build Commands

| Command | Purpose |
|---|---|
| `npm run build -- --mode=ios` | Build frontend assets for iOS |
| `npm run build -- --mode=android` | Build frontend assets for Android |
| `php artisan native:run ios` | Compile and run on iOS simulator/device |
| `php artisan native:run android` | Compile and run on Android emulator/device |
| `php artisan native:run ios --watch` | Build, deploy, then start hot reload (all-in-one) |
| `php artisan native:watch` | Hot reload (watch for PHP file changes) |
| `php artisan native:open` | Open the native project wrapper in Xcode or Android Studio |

---

## 🧪 Testing & Code Styling

To run test suites and code validation:

```bash
# Run Pint to format modified PHP files
vendor/bin/pint --format agent

# Run Pint and run Pest test suites
composer test
```
