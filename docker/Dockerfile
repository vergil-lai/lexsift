ARG PHP_VERSION=8.5
FROM rust:1.96.1-bookworm AS rust

FROM php:${PHP_VERSION}-cli-bookworm AS build
COPY --from=rust /usr/local/cargo /usr/local/cargo
COPY --from=rust /usr/local/rustup /usr/local/rustup
COPY --from=ghcr.io/mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
ENV CARGO_HOME=/usr/local/cargo RUSTUP_HOME=/usr/local/rustup
ENV PATH="/usr/local/cargo/bin:${PATH}"
RUN apt-get update && apt-get install -y --no-install-recommends clang libclang-dev \
    && rm -rf /var/lib/apt/lists/*
COPY package.xml Cargo.toml Cargo.lock config.m4 Makefile.frag LICENSE README.md /usr/src/lexsift/
COPY .cargo /usr/src/lexsift/.cargo
COPY src /usr/src/lexsift/src
ARG LEXSIFT_SOURCE=/usr/src/lexsift
RUN install-php-extensions "${LEXSIFT_SOURCE}" && php --ri lexsift

FROM php:${PHP_VERSION}-cli-bookworm
COPY --from=build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=build /usr/local/etc/php/conf.d/docker-php-ext-lexsift.ini /usr/local/etc/php/conf.d/
RUN php --ri lexsift
