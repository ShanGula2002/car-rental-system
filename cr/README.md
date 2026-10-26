# GRLE Car Rental Services

PHP and MySQL car-rental application intended to run locally with XAMPP.

## Local setup

1. Create the `car_rental_db` database and load a trusted schema/data backup. Database dumps containing personal or account data are intentionally not included in this repository.
2. Configure the local database connection in `db.php` and `index.php`.
3. Create the first admin account from a terminal with PHP CLI. In PowerShell, set `ADMIN_EMAIL`, `ADMIN_PASSWORD` (at least 12 characters), and `ADMIN_PHONE`, then run `php .\create_admin.php`. The setup script only runs through CLI and does not reset an existing account.
4. Start Apache and MySQL in XAMPP and open the project through `http://localhost/cr/`.
