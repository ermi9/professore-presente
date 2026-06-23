#!/bin/bash

# start.sh — Manage the Professore Presente backend
# Usage: ./start.sh        → start the app
#        ./start.sh stop   → stop the app



# Handle stop command
if [ "$1" = "stop" ]; then
    echo ""
    echo "   Professore Presente — Stopping backend..."
    echo ""
    echo ""
    docker-compose down
    echo ""
    echo -e "${GREEN}All containers stopped.${NC}"
    echo ""
    exit 0
fi

echo ""
echo "   Professore Presente — Starting backend..."
echo ""
echo ""

#   Start the Docker containers 
echo "Starting containers..."
docker-compose up -d
if [ $? -ne 0 ]; then
    echo -e "Failed to start containers. Is Docker running?"
    exit 1
fi

# ── Step 2: Wait for the database to be healthy 
echo ""
echo -n "Waiting for database to be ready"
until docker inspect professore_presente_db --format='{{.State.Health.Status}}' 2>/dev/null | grep -q "healthy"; do
    echo -n "."
    sleep 2
done
echo ""
echo -e "Database is ready."

#  Step 3: Apply schema on first run 
# Check if the 'users' table already exists — if not, this is a fresh start
TABLES_EXIST=$(docker exec professore_presente_db \
    psql -U professor -d professore_presente -tAc \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='users';" 2>/dev/null)

if [ "$TABLES_EXIST" = "0" ] || [ -z "$TABLES_EXIST" ]; then
    echo ""
    echo "First time setup — applying database schema..."
    docker exec -i professore_presente_db \
        psql -U professor -d professore_presente < src/config/schema.sql
    if [ $? -eq 0 ]; then
        echo -e "Schema applied successfully."
    else
        echo -e "Failed to apply schema. Check src/config/schema.sql"
        exit 1
    fi
else
    echo "Schema already exists — skipping."
fi

#  Step 4: Quick connection test 
echo ""
echo "Testing database connection..."
docker exec professore_presente_php php /var/www/html/test_db_connection.php

#  Step 5: Done 
echo ""
echo ""
echo -e "Backend is up and running!"
echo ""
echo -e "  Open: http://localhost:8080"
echo ""
echo "  Useful commands:"
echo "    Stop:        docker-compose down"
echo "    View logs:   docker-compose logs -f php"
echo "    DB shell:    docker exec -it professore_presente_db psql -U professor -d professore_presente"
echo ""
