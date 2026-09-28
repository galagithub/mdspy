#!/bin/bash
# Start the MQTT background worker in the background
php -f /var/www/html/mqtt_worker.php &
WORKER_PID=$!

# Function to gracefully stop the worker
shutdown() {
    echo "Shutting down MQTT worker (PID $WORKER_PID)..."
    kill -TERM "$WORKER_PID" 2>/dev/null
    wait "$WORKER_PID" 2>/dev/null
    echo "MQTT worker stopped."
}

# Trap termination signals to invoke shutdown
trap shutdown SIGINT SIGTERM

# Start Apache in the foreground (this replaces the container's PID 1)
exec apache2-foreground
