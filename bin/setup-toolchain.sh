#!/usr/bin/env bash
# Recreate the local PHP/Composer toolchain in tools/ (Windows, MSYS bash).
# The toolchain is git-ignored; run this once on a fresh clone.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ -x tools/php/php.exe ] && [ -f tools/composer.phar ]; then
	echo "Toolchain already present."
	exit 0
fi

mkdir -p tools
echo "Downloading PHP 8.3 (NTS, x64)..."
curl -sL "https://windows.php.net/downloads/releases/latest/php-8.3-nts-Win32-vs16-x64-latest.zip" -o tools/php.zip

echo "Extracting..."
mkdir -p tools/php-tmp
unzip -oq tools/php.zip -d tools/php-tmp
rm -rf tools/php
if [ -d tools/php-tmp/php-8.3* ] 2>/dev/null; then
	mv tools/php-tmp/php-8.3* tools/php
else
	mv tools/php-tmp tools/php
fi
printf 'extension_dir=ext\nextension=php_mbstring.dll\nextension=php_openssl.dll\nextension=php_curl.dll\nextension=php_zip.dll\n' > tools/php/php.ini

echo "Downloading Composer..."
curl -sL https://getcomposer.org/download/latest-stable/composer.phar -o tools/composer.phar

echo "Installing dependencies..."
tools/php/php.exe tools/composer.phar install --no-interaction --no-progress

echo "Toolchain ready: tools/php/php.exe, tools/composer.phar"
