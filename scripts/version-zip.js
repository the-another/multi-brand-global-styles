#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

// Load package.json to get current version and package name
const packageJsonPath = path.join(__dirname, '../package.json');
const packageJson = require(packageJsonPath);
const version = packageJson.version;
const packageName = packageJson.name;

// Define paths
const rootDir = path.join(__dirname, '/../');
const buildDir = path.join(rootDir, 'build');
const sourceZip = path.join(rootDir, `${packageName}.zip`);
const versionedZip = path.join(buildDir, `${packageName}-${version}.zip`);
const latestZip = path.join(buildDir, `${packageName}.zip`);

// Check if source zip exists
if (!fs.existsSync(sourceZip)) {
  console.error(`Error: ${sourceZip} not found`);
  process.exit(1);
}

// Create build directory if it doesn't exist
if (!fs.existsSync(buildDir)) {
  fs.mkdirSync(buildDir, { recursive: true });
  console.log(`✓ Created build directory`);
}

// Copy to versioned zip in build directory
fs.copyFileSync(sourceZip, versionedZip);
console.log(`✓ Created ${path.basename(versionedZip)} in build directory`);

// Copy to latest zip in build directory (overwrite if exists)
fs.copyFileSync(sourceZip, latestZip);
console.log(`✓ Updated ${path.basename(latestZip)} in build directory (latest version)`);

// Remove source zip from root
fs.unlinkSync(sourceZip);
console.log(`✓ Removed temporary ${path.basename(sourceZip)} from root`);
