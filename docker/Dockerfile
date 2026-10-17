FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    gnupg2 \
    curl \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev

# Install Microsoft ODBC driver for SQL Server (msodbcsql18)
RUN curl https://packages.microsoft.com/keys/microsoft.asc | apt-key add - \
    && curl https://packages.microsoft.com/config/debian/11/prod.list > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc-dev

# Install PHP extensions: pdo_mysql, pdo_sqlsrv, sqlsrv, redis, zip, mbstring
RUN docker-php-ext-install pdo pdo_mysql mbstring zip exif pcntl bcmath gd \
    && pecl install sqlsrv pdo_sqlsrv redis \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
