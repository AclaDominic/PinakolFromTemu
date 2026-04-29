#!/bin/bash
set -e

# Run database migrations
echo "Running migrations..."
php artisan migrate --force

# Check if the database is empty by counting users
echo "Checking if the database is empty..."

# Use artisan tinker to safely fetch the count without relying on DB connections directly in bash
# We redirect stderr to dev/null and extract just the numeric output
USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | grep -o -E '[0-9]+' | head -n 1)

# Default to 0 if we couldn't get a valid count
if [ -z "$USER_COUNT" ]; then
    USER_COUNT=0
fi

if [ "$USER_COUNT" -eq "0" ]; then
    echo "Database appears empty (0 users). Running seeders..."
    php artisan db:seed --force
else
    echo "Database has records ($USER_COUNT users). Skipping seeders."
fi

# Optimize Laravel for production
echo "Optimizing Laravel for production..."
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Execute the main container command (e.g., apache2-foreground)
exec "$@"
