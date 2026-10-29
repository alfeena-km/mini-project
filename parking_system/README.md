# Parking Slot Booking System
### College Mini Project — PHP, MySQL, Bootstrap 5

A web application that lets users search, book, and manage parking slot
reservations in advance, with a full admin panel for managing locations,
slots, users, bookings, and reports.

## Tech Stack
- Frontend: HTML5, CSS3, JavaScript, Bootstrap 5
- Backend: PHP (PDO + MySQL)
- Database: MySQL
- Server: XAMPP (Apache + MySQL)

## Folder Structure
```
parking_system/
├── admin/                  Admin panel (dashboard, CRUD, reports)
├── includes/                Shared header, footer, auth checks
├── config.php                Database connection settings
├── database.sql               Import this to create the database
├── index.php                  Landing page
├── register.php / login.php / logout.php
├── dashboard.php              Search parking slots (user)
├── book_slot.php               Booking logic + fee calculation
├── booking_confirmation.php
├── my_bookings.php             View / modify / cancel / history
├── vehicles.php                Add, edit, and remove owned vehicles
├── profile.php                 View and edit user account details
├── payment.php                 Simulated demo payment
├── payment_success.php         Demo payment receipt
├── notifications.php           User notification inbox
```

## Setup Instructions (XAMPP)

1. **Install XAMPP** and start the **Apache** and **MySQL** services from
   the XAMPP Control Panel.

2. **Copy the project folder** `parking_system` into your XAMPP
   `htdocs` directory, e.g.:
   - Windows: `C:\xampp\htdocs\parking_system`
   - macOS: `/Applications/XAMPP/htdocs/parking_system`

3. **Create the database**:
   - Open `http://localhost/phpmyadmin` in your browser.
   - For a new installation, click **Import**, choose `database.sql`,
     and click **Go**. It creates the `parking_system` database, tables,
     and sample data.
   - The SQL no longer drops an existing database. Back up any existing
     project data before changing schemas; this seed file is for a fresh
     database, not an upgrade script.

4. **Check the database config** in `config.php` if your MySQL username/
   password differ from the XAMPP defaults (`root` / no password).

5. **Run the project**:
   - Open `http://localhost/parking_system/` in your browser.

## Demo Logins

| Role  | Email               | Password |
|-------|---------------------|----------|
| Admin | admin@gmail.com     | admin123 |
| User  | john@gmail.com, anu@gmail.com | user123 |
| User  | Register your own account via the "Register" page | Your chosen password |

The included credentials are for local demonstration only. Change or
remove them before deploying the project outside a local environment.

## Main Modules (mapped to project abstract)

| Module in Abstract              | Implementation |
|----------------------------------|----------------|
| User Registration & Login        | `register.php`, `login.php`, `logout.php` |
| Search Parking Slots              | `dashboard.php` (search form) |
| Real-Time Slot Availability       | `dashboard.php` (excludes slots with overlapping confirmed bookings) |
| Parking Slot Booking               | `book_slot.php`, `booking_confirmation.php` |
| Booking Management                 | `my_bookings.php` (view / modify / cancel / history) |
| Vehicle Management                 | `vehicles.php` (user-owned CRUD) |
| Profile Management                 | `profile.php` (user-owned account details) |
| Parking Fee Management              | Calculated server-side in `book_slot.php`: exact duration × hourly price |
| Demo Payments                       | `payment.php`, `payment_success.php`, `admin/manage_payments.php` |
| Notifications                       | `notifications.php`, `admin/notifications.php` |
| Admin Dashboard                     | `admin/admin_dashboard.php` |
| Admin: Locations / Slots / Users / Bookings | `admin/manage_locations.php`, `manage_slots.php`, `manage_users.php`, `manage_bookings.php` (status mutations use CSRF-protected POST) |
| Reports & Analytics                  | `admin/reports.php` supports date-range filtering, CSV export, printing, and successful-payment revenue; dashboard charts are database-fed |

## Notes for your Viva / Demo
- Passwords are stored using PHP's `password_hash()` (bcrypt) — never
  in plain text, which you can mention when asked about security.
- Slot availability is checked with a SQL query that excludes any slot
  with an overlapping confirmed booking for the chosen date/time range,
  which is the "real-time availability" logic.
- The fee is recalculated automatically whenever a booking's date/time
  is modified.
- Payments are simulated locally; the project does not collect card or
  bank credentials and does not make real financial transactions.
- Notifications are stored in MySQL and can be marked read in the user
  inbox.
- Additional future work could include a real payment gateway, email/SMS
  delivery, expanded date-range exports, and map-based search.
