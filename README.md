# Backyard Cinemas Management System

Backyard Cinemas Management System is a responsive PHP and MySQL cinema website built for the BU250 Software Project. It provides a public movie-browsing and ticket-booking experience alongside protected maintenance tools for cinema staff.

The project was developed for Backyard Cinemas Ltd., a cinema located in Portmore, Jamaica.

## Project Overview

The system supports three user roles:

1. **Patrons**
   - Browse movies and showtimes.
   - Create an account and log in.
   - Select a scheduled date and time.
   - Complete a simulated payment and receive a Purchase ID.

2. **Supervisors**
   - Access movie and schedule maintenance.
   - Add, edit, filter, and remove schedule entries.
   - Review ticket purchases.

3. **Administrators**
   - Access all supervisor features.
   - Add, remove, and reset passwords for supervisor accounts.

## Main Features

### Homepage

- Responsive cinema-themed interface.
- Featured Movies carousel with automatic sliding and manual controls.
- Featured movies appear once even when they have multiple scheduled showtimes.
- Movie posters, ratings, and plots are populated using movie APIs.
- Quick Booking flow:
  1. Select a movie.
  2. Select a date.
  3. Select a showtime.
  4. View the assigned cinema.
  5. Continue to ticket booking.
- Now Showing movie cards with consistently aligned action buttons.
- Today's Schedule groups repeat showings into one movie entry.
- Multiple showtimes appear as clickable time buttons that open the correct ticket booking.
- Mobile and tablet navigation collapses into a dropdown menu.

### Movie Details

- View movie poster, certification rating, plot, cast, year, runtime, and genre.
- View movie trailers inside the details popup.
- Close the popup without page scrolling issues.

### Schedule

- View current and upcoming showtimes only.
- Scroll through available schedule dates.
- Search schedules by movie title.
- Filter schedules using a movie dropdown.
- View poster, certification rating, date, time, and cinema.
- Open ticket booking with the selected movie and showtime.

### Patron Accounts and Ticket Booking

- Unified login page for patrons, supervisors, and administrators.
- Patron registration using name, email, and password.
- Patrons who select Buy Tickets while logged out are redirected to login.
- Ticket booking shows all upcoming showtimes for the selected movie.
- Patrons can change the selected date, time, and cinema before paying.
- Simulated card payment form.
- Ticket quantity and total cost calculation.
- Purchases are stored in MySQL.
- Every completed purchase receives a unique Purchase ID.

### Movie and Schedule Maintenance

- Add a movie using its title.
- Automatically retrieve poster and certification rating data.
- Add multiple schedule rows at once.
- Edit movie titles without manually editing API ratings.
- Edit scheduled movie, date, time, cinema, and featured status.
- Delete individual schedule entries.
- Delete movies and their related schedule entries.
- Group schedule tables by week and cinema.
- Search and filter Current Scheduled Movies.
- Disable occupied time slots after staff select a date and cinema.
- Prevent overlapping bookings in the same cinema.
- Display short confirmation messages after maintenance actions.

### Featured Movies

- Staff can mark scheduled movies as featured.
- A movie appears only once in the Featured Movies carousel.
- Featured cards show the movie plot instead of schedule details.
- Featured movies link to details, ticket booking, and schedule maintenance.

### Ticket Purchase Reports

- Staff-only Ticket Purchases page under the Maintenance menu.
- View Purchase ID, customer, email, movie, showtime, cinema, ticket quantity, total, and purchase date.
- Search by:
  - Movie title
  - Purchase ID
  - Customer name

### Employee Management

- Administrators can add supervisor accounts.
- Administrators can remove supervisor accounts.
- Administrators can reset supervisor passwords.
- Employee maintenance is restricted to administrators.

## Technologies Used

- PHP
- MySQL
- HTML5
- CSS3
- JavaScript
- Bootstrap 3
- Font Awesome
- OMDb API
- TMDb API
- XAMPP / Apache
- phpMyAdmin

## Database Tables

### `movie`

Stores movie information.

- `MovieID`
- `MovieName`
- `Rating`
- `PosterURL`
- Other movie fields used by the original database

### `schedule`

Stores scheduled showtimes.

- `ScheduleID`
- `MovieID`
- `Cinema`
- `ShowDate`
- `ShowTime`
- `IsFeatured`

### `employee`

Stores administrator and supervisor accounts.

- `EmpID`
- `EmpName`
- `Email`
- `Password`
- `Role`

Supported roles:

- `admin`
- `supervisor`

### `patron`

Stores patron accounts.

- `PatronID`
- `PatronName`
- `Email`
- `Password`
- `CreatedAt`

### `ticket_order`

Stores completed ticket purchases.

- `TicketID`
- `PatronID`
- `ScheduleID`
- `Quantity`
- `TicketPrice`
- `TotalAmount`
- `PaymentName`
- `CardLast4`
- `CreatedAt`

`TicketID` is shown to the patron as the Purchase ID.

## Key System Rules

### Role-Based Access

PHP sessions control access to protected pages.

- Visitors can browse movies and schedules.
- Patrons can purchase tickets.
- Supervisors can maintain movies and schedules and view ticket purchases.
- Administrators can also maintain employee accounts.

### Schedule Conflict Prevention

Each showtime is treated as an approximately three-hour cinema block. The system checks for overlap in the same cinema and disables occupied time choices in maintenance forms.

### Certification Ratings

Movie ratings refer to certification values such as `G`, `PG`, `PG-13`, and `R`. Star ratings and numeric review scores are not used.

## Installation

### 1. Install XAMPP

Install XAMPP and start:

- Apache
- MySQL

### 2. Copy the Project

Clone or copy the repository into the XAMPP `htdocs` folder:

```text
C:\xampp\htdocs\Backyard-Cinema-Website
```

### 3. Configure the Database

Create or import the main cinema database in phpMyAdmin. The current connection file expects:

```text
Database name: example
Username: root
Password: empty
Host: localhost
```

Update `conn.php` if your MySQL settings are different.

The main database must include the `movie`, `schedule`, and `employee` tables.

Run `patron_ticket_setup.sql` to add the patron registration and ticket purchase tables:

```sql
SOURCE patron_ticket_setup.sql;
```

The setup script creates:

- `patron`
- `ticket_order`

### 4. Configure Movie APIs

The application uses OMDb and TMDb to retrieve movie posters, plots, ratings, and trailers. Valid API keys are required for complete movie information.

### 5. Open the Website

Visit:

```text
http://localhost/Backyard-Cinema-Website/moviesphp.php
```

## Important Pages

- `moviesphp.php` - Homepage
- `movieschedule.php` - Public schedule and filters
- `login.php` - Unified patron and staff authentication
- `buyticket.php` - Showtime selection and simulated payment
- `addmovie.php` - Add movies and schedules
- `updateschedule.php` - Edit and filter schedules
- `editmovie.php` - Edit movie information
- `manageemployees.php` - Administrator employee maintenance
- `ticketpurchases.php` - Staff ticket purchase report

## Notes

- Payments are simulated for demonstration purposes. No real card processing occurs.
- Only the last four entered card digits are stored with the purchase.
- Movie information depends on the titles entered and the availability of results from OMDb or TMDb.
- The application timezone is configured for Jamaica.

## Project Context

This application was created as an academic software project and demonstrates:

- Database-backed CRUD operations
- Authentication and role-based authorization
- Responsive interface design
- Third-party API integration
- Schedule conflict validation
- Patron ticket purchasing and reporting
