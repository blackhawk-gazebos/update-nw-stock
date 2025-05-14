# Use official PHP CLI image
FROM php:8.2-cli

# Set working dir
WORKDIR /app

# Copy in your code
COPY . .

# Expose the port Render will route to
EXPOSE 8000

# Use the built-in PHP server on the Render-provided PORT
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8000} -t ."]
