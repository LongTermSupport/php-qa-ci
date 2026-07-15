# ============================================================================
# php-qa-ci — custom CCY container
# ============================================================================
# This project is a bash-orchestrated PHP QA pipeline (composer library).
# composer.json requires PHP ^8.3; the php8.4 branch targets PHP 8.4, so the
# container installs PHP 8.4 from the Sury repo (Debian bookworm ships 8.2,
# which cannot even run `composer install` here).
#
# Installed on top of claude-yolo:latest:
#   - PHP 8.4 CLI + extensions the pipeline/tools need
#     (xml, mbstring, curl, zip, intl) and Xdebug (coverage + Infection)
#   - Composer (checksum-verified installer)
#   - PHIVE (GPG-verified) — scripts/tool-install.bash uses it for PHAR tools
#
# After changing this file: run `ccy --rebuild` on the host.
# Inside the container: `composer install` then `bin/phpunit` / `bin/qa`.
# ============================================================================

FROM claude-yolo:latest

# ----------------------------------------------------------------------------
# PHP 8.4 from the Sury repo (keyring verified via packages.sury.org deb)
# ----------------------------------------------------------------------------
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && \
    apt-get install -y --no-install-recommends curl ca-certificates gnupg && \
    curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb && \
    dpkg -i /tmp/debsuryorg-archive-keyring.deb && \
    rm /tmp/debsuryorg-archive-keyring.deb && \
    echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ bookworm main" \
        > /etc/apt/sources.list.d/sury-php.list && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
        php8.4-cli \
        php8.4-xml \
        php8.4-mbstring \
        php8.4-curl \
        php8.4-zip \
        php8.4-intl \
        php8.4-xdebug \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# Xdebug only when explicitly requested (XDEBUG_MODE env) — keeps the default
# CLI fast; bin/qa exports XDEBUG_MODE=coverage itself when generating coverage.
ENV XDEBUG_MODE=off

# ----------------------------------------------------------------------------
# Composer (installer verified against the published SHA-384 signature)
# ----------------------------------------------------------------------------
RUN EXPECTED_SIG="$(curl -fsSL https://composer.github.io/installer.sig)" && \
    curl -fsSLo /tmp/composer-setup.php https://getcomposer.org/installer && \
    echo "${EXPECTED_SIG}  /tmp/composer-setup.php" | sha384sum -c - && \
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
    rm /tmp/composer-setup.php && \
    composer --version

# ----------------------------------------------------------------------------
# PHIVE (GPG-verified) — used by scripts/tool-install.bash for PHAR tools
# ----------------------------------------------------------------------------
RUN curl -fsSLo /usr/local/bin/phive https://phar.io/releases/phive.phar && \
    curl -fsSLo /tmp/phive.phar.asc https://phar.io/releases/phive.phar.asc && \
    gpg --keyserver hkps://keys.openpgp.org --recv-keys 0x6AF725270AB81E04D79442549D8A98B29B2D5D79 && \
    gpg --verify /tmp/phive.phar.asc /usr/local/bin/phive && \
    rm /tmp/phive.phar.asc && \
    chmod 755 /usr/local/bin/phive && \
    phive version

# Sanity check the toolchain the QA pipeline expects
RUN php --version && php -m | grep -E 'xdebug|mbstring|intl' && composer --version
