#!/bin/sh
# Build the distribution zip from .distignore via wp-cli dist-archive.
#
# The tree is first staged without .git/node_modules/build to a temp dir:
# dist-archive-command v3.1.0 (newest release installable against wp-cli
# 2.12, the latest wp-cli release — 3.2.x requires an unreleased ^2.13)
# has a path-handling bug when the source contains a .git directory, and
# the staged copy also keeps those dirs out of the archive scanner
# entirely. .distignore is part of the staged tree, so all other
# exclusions still come from it.
set -e

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

tar cf - --exclude='.git' --exclude='node_modules' --exclude='build' . | tar xf - -C "$STAGE"

wp dist-archive "$STAGE" "$(pwd)/the-another-multi-domain-global-styles.zip" \
	--plugin-dirname=the-another-multi-domain-global-styles --force --allow-root > /dev/null
