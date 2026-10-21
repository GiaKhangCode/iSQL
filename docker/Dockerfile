FROM php:8.3-fpm-bookworm

# Install system dependencies (build-essential / g++ is required for compiling sqlsrv & pdo_sqlsrv)
RUN apt-get update && apt-get install -y \
    build-essential \
    gnupg2 \
    curl \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev

# Install Microsoft ODBC driver for SQL Server (msodbcsql18)
RUN curl -fsSL --retry 5 --retry-delay 3 https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl -fsSL --retry 5 --retry-delay 3 https://packages.microsoft.com/config/debian/12/prod.list > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc-dev

# Install PHP extensions: pdo_mysql, pdo_sqlsrv, sqlsrv, redis, zip, mbstring
RUN docker-php-ext-install pdo pdo_mysql mbstring zip exif pcntl bcmath gd \
    && pecl install sqlsrv \
    && pecl install pdo_sqlsrv \
    && pecl install redis \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
