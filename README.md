# WordPress Reservations Plugin

Custom WordPress plugin developed as part of a portfolio project.

This plugin allows a restaurant website to manage table reservations directly from the site using a custom database table and a simple admin dashboard.

## Features

- Reservation form using shortcode `[ch_reservas]`
- Custom MySQL table for storing reservations
- Admin dashboard to view and manage reservations
- Input validation and sanitization
- Nonce security protection
- Delete reservations from the admin panel

## Technologies Used

- WordPress Plugin API
- PHP
- MySQL
- `$wpdb` database interface
- HTML / CSS
- Shortcodes

## Database Structure

Table created automatically on plugin activation:


Fields:

- id
- created_at
- name
- email
- phone
- guests
- res_date
- res_time
- notes
- status
- ip
- user_agent

## How It Works

1. The plugin creates a custom table on activation.
2. A shortcode `[ch_reservas]` renders the reservation form.
3. When the form is submitted, data is validated and stored in the database.
4. Reservations can be viewed and managed from the WordPress admin panel.

## Project Context

This plugin was developed as part of a restaurant website project built for portfolio purposes.

It demonstrates:

- WordPress plugin development
- Custom database table creation
- Backend form processing
- Admin dashboard integration
