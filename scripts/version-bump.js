#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

// Configuration
const MAIN_PLUGIN_FILE = 'the-another-multi-domain-global-styles.php';
const VERSION_CONSTANT_NAME = 'THE_ANOTHER_MULTI_DOMAIN_GLOBAL_STYLES_VERSION';

// Get version type argument (patch, minor, major)
const versionType = process.argv[2];
const validTypes = ['patch', 'minor', 'major'];

// Load package.json
const packageJsonPath = path.join(__dirname, '../package.json');
const packageJson = require(packageJsonPath);
let newVersion = packageJson.version;

// If version type is provided, increment the version
if (versionType && validTypes.includes(versionType)) {
  const [major, minor, patch] = newVersion.split('.').map(Number);

  switch (versionType) {
    case 'major':
      newVersion = `${major + 1}.0.0`;
      break;
    case 'minor':
      newVersion = `${major}.${minor + 1}.0`;
      break;
    case 'patch':
      newVersion = `${major}.${minor}.${patch + 1}`;
      break;
  }

  // Update package.json with new version
  packageJson.version = newVersion;
  fs.writeFileSync(packageJsonPath, JSON.stringify(packageJson, null, 2) + '\n', 'utf8');
  console.log(`✓ Bumped version to ${newVersion} (${versionType})`);
} else if (versionType) {
  console.error(`Invalid version type: ${versionType}. Use: patch, minor, or major`);
  process.exit(1);
}

console.log(`Updating version to ${newVersion}...`);

// Update composer.json if it exists
const composerJsonPath = path.join(__dirname, '../composer.json');
if (fs.existsSync(composerJsonPath)) {
  const composerJson = JSON.parse(fs.readFileSync(composerJsonPath, 'utf8'));
  composerJson.version = newVersion;
  fs.writeFileSync(composerJsonPath, JSON.stringify(composerJson, null, '\t') + '\n', 'utf8');
  console.log('✓ Updated composer.json');
}

// Update main plugin file
const pluginFile = path.join(__dirname, '..', MAIN_PLUGIN_FILE);
let pluginContent = fs.readFileSync(pluginFile, 'utf8');

// Update Version in header comment
pluginContent = pluginContent.replace(
  /(\* Version:\s+)[\d.]+/,
  `$1${newVersion}`
);

// Update version constant
pluginContent = pluginContent.replace(
  new RegExp(`(define\\(\\s*'${VERSION_CONSTANT_NAME}',\\s*')[\\d.]+('\\s*\\);)`),
  `$1${newVersion}$2`
);

fs.writeFileSync(pluginFile, pluginContent, 'utf8');
console.log(`✓ Updated ${MAIN_PLUGIN_FILE}`);

// Update readme.txt
const readmeFile = path.join(__dirname, '../readme.txt');
let readmeContent = fs.readFileSync(readmeFile, 'utf8');

// Update Stable tag
readmeContent = readmeContent.replace(
  /(Stable tag:\s+)[\d.]+/,
  `$1${newVersion}`
);

// Add changelog entry
const today = new Date().toISOString().split('T')[0];
const changelogEntry = `= ${newVersion} - ${today} =\n* Version bump\n\n`;

// Find the changelog section and add the new entry
readmeContent = readmeContent.replace(
  /(== Changelog ==\s*\n)/,
  `$1\n${changelogEntry}`
);

fs.writeFileSync(readmeFile, readmeContent, 'utf8');
console.log('✓ Updated readme.txt');

// Sync lock files with updated versions
const rootDir = path.join(__dirname, '..');

console.log('\nSyncing lock files...');

try {
  execSync('npm install --package-lock-only', { cwd: rootDir, stdio: 'inherit' });
  console.log('✓ Updated package-lock.json');
} catch {
  console.warn('⚠ Failed to update package-lock.json');
}

try {
  execSync('composer update --lock', { cwd: rootDir, stdio: 'inherit' });
  console.log('✓ Updated composer.lock');
} catch {
  console.warn('⚠ Failed to update composer.lock');
}

console.log(`\nVersion ${newVersion} update complete!`);
