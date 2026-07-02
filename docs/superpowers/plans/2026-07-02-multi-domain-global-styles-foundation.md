# Multi-Domain Global Styles — Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a standalone WordPress plugin that lets admins register domains, group them into per-domain "Website" style/variable profiles, override global styles per domain without touching the theme, and substitute `%%website.*%%` content variables — with an interim raw-JSON style editor. (Full native-Site-Editor-parity style editing is a separate follow-up plan; see "Relationship to Plan 2" below.)

**Architecture:** Container-based DI (mirrors `aucteeno-nexus`/`globalag-router` house style) with code organized by domain/bounded-context (`Website`, `GlobalStyles`, `ContentVariables`) rather than technical layer — see "File Structure" below. The `mdgs_website` custom post type is the aggregate root: the entity for domains, variables, and a pointer to a dedicated `wp_global_styles` post per Website. Domain routing resolves `HTTP_HOST` → Website via a cached map. Frontend rendering hooks `wp_theme_json_data_user` (style override) and a `template_redirect`-started output buffer (text variable substitution). Throughout, the implementation stays WordPress-idiomatic: the CPT is the aggregate, hooks (not a custom event bus) are the extensibility mechanism, `get_post_meta`/`wp_insert_post`/core filters are used directly rather than wrapped in additional abstraction layers.

**Tech Stack:** PHP 8.3+, WordPress 6.9+, Composer (PSR-4 autoload for both `includes/` and `tests/`), PHPUnit 11 + Brain Monkey + Mockery (unit tests only, no WP test suite / DB).

## Global Constraints

- PHP 8.3+ (spec: "PHP 8.3+, WP 6.9+" — Code conventions section)
- WordPress 6.9+
- Namespace root: `The_Another\Plugin\Multi_Domain_Global_Styles` (spec: "Code conventions")
- Standalone plugin — no dependency on other Aucteeno plugins (spec: "Code conventions")
- Container-based DI (`Container` singleton + `HookManager`), not scattered `add_action` calls (spec: "Code conventions")
- Duplicate domain registration across Websites must be rejected at save time with an admin-visible error (spec: "Error handling & edge cases")
- Undefined `%%token%%` values are left literal, never silently blanked (spec: "Variable substitution")
- Variable values are escaped via `esc_html()` before substitution — plain text only, no HTML injection (spec: "Variable substitution" / "Assumptions")
- Output buffer runs on frontend HTML responses only — skipped for REST/admin-ajax/feeds (spec: "Variable substitution" / "Assumptions")
- Theme's own `theme.json` file is never modified; no child theme is created (spec: "Global styles override")

## Relationship to Plan 2

This plan ships a complete, independently useful plugin: domain management, per-domain style overrides, and variable substitution all work end-to-end. The one deliberate gap is the style-editing UX — Task 10 below adds a **raw JSON textarea** against the same `wp_global_styles` post structure that the real Site Editor uses, as an interim editing surface. A second plan will replace that textarea with a redirect into WordPress's actual native Styles UI (full per-block-type parity, per the approved spec). Because both plans write to the exact same `wp_global_styles` post format, Plan 2 is a pure UX swap — no data migration, no changes to the override/substitution mechanisms built here.

---

## File Structure

```
the-another-multi-domain-global-styles/
├── the-another-multi-domain-global-styles.php   # Bootstrap: constants, version checks, autoload, Plugin::start()
├── composer.json
├── phpunit.xml.dist
├── .phpcs.xml.dist
├── includes/
│   ├── Container.php                    # DI container (copied pattern from aucteeno-nexus) — infrastructure, not a domain
│   ├── HookManager.php                  # Hook registration/tracking (copied pattern) — infrastructure
│   ├── Plugin.php                       # Orchestrator: registers services + hooks — infrastructure
│   ├── Website/                         # Bounded context: the Website entity + domain routing
│   │   ├── WebsitePostType.php          # `mdgs_website` CPT (aggregate root), meta boxes, save glue
│   │   ├── DomainRegistry.php           # normalize/parse/dedupe domains, conflict detection, cached map
│   │   ├── WebsiteRepository.php        # read helpers: domains, variables, default, global-styles-post-id
│   │   ├── DomainResolver.php           # HTTP_HOST → Website ID
│   │   └── AdminNotices.php             # duplicate-domain rejection notice
│   ├── GlobalStyles/                    # Bounded context: per-Website style override mechanism
│   │   ├── GlobalStylesPostService.php  # create/read the per-Website wp_global_styles post
│   │   └── GlobalStylesOverride.php     # wp_theme_json_data_user filter (frontend)
│   └── ContentVariables/                # Bounded context: %%website.*%% token substitution
│       ├── VariableParser.php               # "key = value" textarea → assoc array
│       └── VariableSubstitutionService.php  # output buffer + %%website.*%% substitution
└── tests/
    ├── bootstrap.php
    ├── ContainerTest.php
    ├── Website/
    │   ├── WebsitePostTypeTest.php
    │   ├── DomainRegistryTest.php
    │   ├── WebsiteRepositoryTest.php
    │   ├── DomainResolverTest.php
    │   └── AdminNoticesTest.php
    ├── GlobalStyles/
    │   ├── GlobalStylesPostServiceTest.php
    │   └── GlobalStylesOverrideTest.php
    └── ContentVariables/
        ├── VariableParserTest.php
        └── VariableSubstitutionServiceTest.php
```

Each bounded context maps 1:1 onto a domain concept from the spec, not a technical role — `Website` is the aggregate root (the `mdgs_website` CPT) plus everything needed to identify and validate one; `GlobalStyles` and `ContentVariables` are the two independent capabilities a Website exposes. `Container`/`HookManager`/`Plugin` stay at the root as shared infrastructure, consistent with how WordPress plugins conventionally keep bootstrapping concerns separate from domain logic. Nothing about the WordPress-facing API changes — CPT registration, hooks, meta keys, and `get_post_meta`/`wp_insert_post` usage are unchanged from the original design; this is a pure reorganization of where the same code lives.

---

### Task 1: Plugin scaffold — Container, HookManager, bootstrap

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `.phpcs.xml.dist`
- Create: `the-another-multi-domain-global-styles.php`
- Create: `includes/Container.php`
- Create: `includes/HookManager.php`
- Create: `includes/Plugin.php`
- Test: `tests/bootstrap.php`
- Test: `tests/ContainerTest.php`

**Interfaces:**
- Produces: `The_Another\Plugin\Multi_Domain_Global_Styles\Container::get_instance(): Container`, `->register(string $key, callable $factory, bool $singleton = true): void`, `->get(string $key): mixed`, `->has(string $key): bool`, `->get_hook_manager(): HookManager`
- Produces: `The_Another\Plugin\Multi_Domain_Global_Styles\HookManager::register_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void`, `->register_filter(...)` (same signature)
- Produces: `The_Another\Plugin\Multi_Domain_Global_Styles\Plugin::get_instance(): Plugin`, `->start(): void` (body filled in as later tasks wire services in; empty for this task)

- [ ] **Step 1: Create composer.json**

```json
{
  "name": "theanother/the-another-multi-domain-global-styles",
  "description": "Manage additional domains, per-domain global style overrides, and content variables from a single WordPress install.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "version": "0.1.0",
  "author": {
    "name": "The Another",
    "email": "hello@theanother.org",
    "url": "https://theanother.org"
  },
  "keywords": [
    "wordpress",
    "plugin",
    "multi-domain",
    "global-styles"
  ],
  "homepage": "https://theanother.org/plugin/the-another-multi-domain-global-styles/",
  "require": {
    "php": ">=8.3"
  },
  "require-dev": {
    "automattic/vipwpcs": "^3.0",
    "brain/monkey": "^2.6",
    "johnpbloch/wordpress-core": "^6.9",
    "mockery/mockery": "^1.6",
    "php-stubs/wordpress-stubs": "^6.9",
    "phpunit/phpunit": "^11.0",
    "squizlabs/php_codesniffer": "^3.9",
    "wp-coding-standards/wpcs": "^3.0"
  },
  "autoload": {
    "psr-4": {
      "The_Another\\Plugin\\Multi_Domain_Global_Styles\\": "includes/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "The_Another\\Plugin\\Multi_Domain_Global_Styles\\Tests\\": "tests/"
    }
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true
    },
    "sort-packages": true
  },
  "scripts": {
    "phpcs": "phpcs",
    "phpcbf": "phpcbf",
    "test": "phpunit"
  }
}
```

- [ ] **Step 2: Create phpunit.xml.dist**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
		 xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.0/phpunit.xsd"
		 bootstrap="tests/bootstrap.php"
		 colors="true"
		 cacheDirectory=".phpunit.cache"
		 executionOrder="depends,defects"
		 failOnRisky="true"
		 failOnWarning="true"
		 requireCoverageMetadata="false"
		 beStrictAboutCoverageMetadata="true"
		 beStrictAboutOutputDuringTests="true"
		 processIsolation="false"
		 stopOnFailure="false">
	<testsuites>
		<testsuite name="Multi-Domain Global Styles Test Suite">
			<directory>./tests</directory>
		</testsuite>
	</testsuites>
	<source>
		<include>
			<directory suffix=".php">./includes</directory>
		</include>
		<exclude>
			<directory>./vendor</directory>
		</exclude>
	</source>
	<php>
		<ini name="error_reporting" value="E_ALL"/>
		<ini name="display_errors" value="1"/>
		<ini name="display_startup_errors" value="1"/>
	</php>
</phpunit>
```

- [ ] **Step 3: Create .phpcs.xml.dist**

```xml
<?xml version="1.0"?>
<ruleset name="MultiDomainGlobalStyles">
	<description>WordPress Coding Standards and Automattic VIP Coding Standards for Multi-Domain Global Styles plugin</description>

	<file>./includes</file>
	<file>./the-another-multi-domain-global-styles.php</file>
	<exclude-pattern>*/vendor/*</exclude-pattern>
	<exclude-pattern>*/node_modules/*</exclude-pattern>
	<exclude-pattern>*/.git/*</exclude-pattern>

	<arg value="sp"/>
	<arg name="basepath" value="./"/>
	<arg name="colors"/>
	<arg name="extensions" value="php"/>
	<arg name="parallel" value="8"/>

	<config name="testVersion" value="8.3-"/>
	<config name="minimum_supported_wp_version" value="6.9"/>

	<rule ref="WordPress">
		<exclude name="WordPress.Files.FileName"/>
		<exclude name="WordPress.NamingConventions.PrefixAllGlobals"/>
	</rule>

	<rule ref="WordPress-VIP-Go">
		<exclude name="WordPressVIPMinimum.Functions.RestrictedFunctions"/>
	</rule>

	<rule ref="WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid">
		<severity>5</severity>
	</rule>
</ruleset>
```

- [ ] **Step 4: Create includes/Container.php**

```php
<?php
/**
 * Container Class
 *
 * Dependency injection container with lazy loading and hook management.
 *
 * @package The_Another_Multi_Domain_Global_Styles
 * @since 1.0.0
 */

namespace The_Another\Plugin\Multi_Domain_Global_Styles;

use Exception;

/**
 * Class Container
 *
 * Service container following WooCommerce-style patterns with lazy loading.
 */
class Container {

	/**
	 * Container instance.
	 *
	 * @var Container|null
	 */
	private static ?Container $instance = null;

	/**
	 * Registered services (factories or instances).
	 *
	 * @var array<string, mixed>
	 */
	private array $services = array();

	/**
	 * Service factories for lazy instantiation.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Instantiated singleton services.
	 *
	 * @var array<string, object>
	 */
	private array $singletons = array();

	/**
	 * Hook manager instance.
	 *
	 * @var HookManager
	 */
	private HookManager $hook_manager;

	/**
	 * Private constructor to prevent direct instantiation.
	 */
	private function __construct() {
		$this->hook_manager = new HookManager();
	}

	/**
	 * Get the container instance.
	 *
	 * @return Container Container instance.
	 */
	public static function get_instance(): Container {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get a service from the container.
	 *
	 * @param string $key Service key.
	 * @return mixed Service instance.
	 *
	 * @throws Exception If service not found.
	 */
	public function get( string $key ): mixed {
		if ( isset( $this->singletons[ $key ] ) ) {
			return $this->singletons[ $key ];
		}

		if ( isset( $this->factories[ $key ] ) ) {
			$instance = call_user_func( $this->factories[ $key ], $this );

			if ( isset( $this->services[ $key ]['singleton'] ) && $this->services[ $key ]['singleton'] ) {
				$this->singletons[ $key ] = $instance;
			}

			return $instance;
		}

		if ( isset( $this->services[ $key ] ) ) {
			return $this->services[ $key ];
		}

		throw new Exception( esc_html( sprintf( 'Service %s not found in container', $key ) ) );
	}

	/**
	 * Check if a service exists in the container.
	 *
	 * @param string $key Service key.
	 * @return bool True if service exists, false otherwise.
	 */
	public function has( string $key ): bool {
		return isset( $this->services[ $key ] ) || isset( $this->factories[ $key ] );
	}

	/**
	 * Register a service factory.
	 *
	 * @param string   $key       Service key.
	 * @param callable $factory   Factory function that receives Container as parameter.
	 * @param bool     $singleton Whether to treat as singleton (default: true).
	 * @return void
	 */
	public function register( string $key, callable $factory, bool $singleton = true ): void {
		$this->factories[ $key ] = $factory;
		$this->services[ $key ]  = array( 'singleton' => $singleton );
		unset( $this->singletons[ $key ] );
	}

	/**
	 * Register a direct service instance.
	 *
	 * @param string $key      Service key.
	 * @param mixed  $instance Service instance.
	 * @return void
	 */
	public function set( string $key, mixed $instance ): void {
		$this->services[ $key ] = $instance;
	}

	/**
	 * Get the hook manager instance.
	 *
	 * @return HookManager Hook manager instance.
	 */
	public function get_hook_manager(): HookManager {
		return $this->hook_manager;
	}

	/**
	 * Deregister all hooks managed by the container.
	 *
	 * @return void
	 */
	public function deregister_all_hooks(): void {
		$this->hook_manager->deregister_all();
	}

	/**
	 * Prevent cloning of the instance.
	 */
	private function __clone() {
	}

	/**
	 * Prevent unserialization of the instance.
	 *
	 * @throws Exception Always, to prevent unserialization.
	 */
	public function __wakeup() {
		throw new Exception( 'Cannot unserialize singleton' );
	}
}
```

- [ ] **Step 5: Create includes/HookManager.php**

```php
<?php
/**
 * Hook Manager Class
 *
 * Manages WordPress hook registration and deregistration with tracking.
 *
 * @package The_Another_Multi_Domain_Global_Styles
 * @since 1.0.0
 */

namespace The_Another\Plugin\Multi_Domain_Global_Styles;

/**
 * Class HookManager
 *
 * Tracks and manages WordPress hooks for easy registration and deregistration.
 */
class HookManager {

	/**
	 * Registered hooks tracking.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $registered_hooks = array();

	/**
	 * Register a WordPress action hook.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback function.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 * @return void
	 */
	public function register_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		if ( has_action( $hook, $callback ) !== false ) {
			return;
		}

		add_action( $hook, $callback, $priority, $accepted_args );

		$this->registered_hooks[] = array(
			'type'          => 'action',
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}

	/**
	 * Register a WordPress filter hook.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback function.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 * @return void
	 */
	public function register_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		if ( has_filter( $hook, $callback ) !== false ) {
			return;
		}

		add_filter( $hook, $callback, $priority, $accepted_args );

		$this->registered_hooks[] = array(
			'type'          => 'filter',
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}

	/**
	 * Deregister all hooks tracked by this manager.
	 *
	 * @return void
	 */
	public function deregister_all(): void {
		foreach ( $this->registered_hooks as $hook_data ) {
			if ( 'action' === $hook_data['type'] ) {
				remove_action( $hook_data['hook'], $hook_data['callback'], $hook_data['priority'] );
			} else {
				remove_filter( $hook_data['hook'], $hook_data['callback'], $hook_data['priority'] );
			}
		}
		$this->registered_hooks = array();
	}

	/**
	 * Get all registered hooks.
	 *
	 * @return array<int, array<string, mixed>> Registered hooks.
	 */
	public function get_registered_hooks(): array {
		return $this->registered_hooks;
	}
}
```

- [ ] **Step 6: Create includes/Plugin.php (empty start(), filled in by later tasks)**

```php
<?php
/**
 * Plugin Orchestrator Class
 *
 * @package The_Another_Multi_Domain_Global_Styles
 * @since 1.0.0
 */

namespace The_Another\Plugin\Multi_Domain_Global_Styles;

/**
 * Class Plugin
 *
 * Registers services and wires hooks. Task 12 fills in register_services()
 * and start() with the full service graph; this task only establishes the
 * singleton shape so Container/HookManager have a real consumer to test against.
 */
class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Container instance.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->container = Container::get_instance();
	}

	/**
	 * Get the plugin instance.
	 *
	 * @return Plugin Plugin instance.
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Start the plugin: register services and hooks.
	 *
	 * @return void
	 */
	public function start(): void {
		// Filled in by Task 12.
	}
}
```

- [ ] **Step 7: Create the-another-multi-domain-global-styles.php**

```php
<?php
/**
 * Plugin Name: The Another Multi-Domain Global Styles
 * Plugin URI: https://theanother.org/plugin/the-another-multi-domain-global-styles/
 * Description: Manage additional domains, per-domain global style overrides, and content variables from a single WordPress install.
 * Version: 0.1.0
 * Author: The Another
 * Author URI: https://theanother.org
 * Requires at least: 6.9
 * Requires PHP: 8.3
 * Text Domain: the-another-multi-domain-global-styles
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package The_Another_Multi_Domain_Global_Styles
 * @since 1.0.0
 */

namespace The_Another\Plugin\Multi_Domain_Global_Styles;

use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MDGS_VERSION', '0.1.0' );
define( 'MDGS_PLUGIN_FILE', __FILE__ );
define( 'MDGS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MDGS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MDGS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'The Another Multi-Domain Global Styles requires PHP 8.3 or higher. Please upgrade your PHP version.', 'the-another-multi-domain-global-styles' ); ?></p>
			</div>
			<?php
		}
	);
	return;
}

if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'The Another Multi-Domain Global Styles requires WordPress 6.9 or higher. Please upgrade WordPress.', 'the-another-multi-domain-global-styles' ); ?></p>
			</div>
			<?php
		}
	);
	return;
}

if ( file_exists( MDGS_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once MDGS_PLUGIN_DIR . 'vendor/autoload.php';
}

add_action(
	'plugins_loaded',
	function () {
		try {
			Plugin::get_instance()->start();
		} catch ( Exception $e ) {
			wp_die(
				esc_html( $e->getMessage() ),
				'Multi-Domain Global Styles Error',
				array( 'response' => 500 )
			);
		}
	}
);
```

- [ ] **Step 8: Create tests/bootstrap.php**

```php
<?php
/**
 * PHPUnit bootstrap file for Multi-Domain Global Styles plugin tests.
 *
 * @package The_Another\Plugin\Multi_Domain_Global_Styles\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/vendor/brain/monkey/inc/patchwork-loader.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors     = array();
		public $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( empty( $code ) ) {
				return;
			}
			$this->errors[ $code ][]   = $message;
			$this->error_data[ $code ] = $data;
		}

		public function get_error_message( $code = '' ) {
			if ( empty( $code ) ) {
				$codes = array_keys( $this->errors );
				$code  = $codes[0] ?? '';
			}
			return isset( $this->errors[ $code ] ) ? $this->errors[ $code ][0] : '';
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return ( $thing instanceof WP_Error );
	}
}
```

- [ ] **Step 9: Create tests/ContainerTest.php**

```php
<?php
declare(strict_types=1);

namespace The_Another\Plugin\Multi_Domain_Global_Styles\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use The_Another\Plugin\Multi_Domain_Global_Styles\Container;

#[CoversClass( Container::class )]
class ContainerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$reflection = new \ReflectionClass( Container::class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_instance_returns_same_instance(): void {
		$first  = Container::get_instance();
		$second = Container::get_instance();

		$this->assertSame( $first, $second );
	}

	public function test_register_and_get_resolves_factory(): void {
		$container = Container::get_instance();

		$container->register( 'greeting', fn() => 'hello' );

		$this->assertSame( 'hello', $container->get( 'greeting' ) );
	}

	public function test_singleton_factory_returns_same_instance_on_repeat_calls(): void {
		$container = Container::get_instance();

		$container->register( 'object_service', fn() => new \stdClass() );

		$this->assertSame( $container->get( 'object_service' ), $container->get( 'object_service' ) );
	}

	public function test_non_singleton_factory_returns_new_instance_each_call(): void {
		$container = Container::get_instance();

		$container->register( 'object_service', fn() => new \stdClass(), false );

		$this->assertNotSame( $container->get( 'object_service' ), $container->get( 'object_service' ) );
	}

	public function test_has_reflects_registered_services(): void {
		$container = Container::get_instance();

		$this->assertFalse( $container->has( 'missing' ) );

		$container->register( 'present', fn() => true );

		$this->assertTrue( $container->has( 'present' ) );
	}

	public function test_get_throws_for_unknown_service(): void {
		$container = Container::get_instance();

		$this->expectException( \Exception::class );

		$container->get( 'does_not_exist' );
	}

	public function test_get_hook_manager_returns_hook_manager_instance(): void {
		$container = Container::get_instance();

		$this->assertInstanceOf(
			\The_Another\Plugin\Multi_Domain_Global_Styles\HookManager::class,
			$container->get_hook_manager()
		);
	}
}
```

- [ ] **Step 10: Run tests to verify they pass**

Run: `composer install && composer test`
Expected: All `ContainerTest` tests PASS (this task has no failing-first-test step since it establishes scaffolding rather than a new behavior — the Container code is a direct, verified copy of the working `aucteeno-nexus` implementation).

- [ ] **Step 11: Run phpcs**

Run: `composer phpcs`
Expected: No errors (warnings acceptable) against the files created in this task.

- [ ] **Step 12: Commit**

```bash
git add composer.json phpunit.xml.dist .phpcs.xml.dist the-another-multi-domain-global-styles.php includes/Container.php includes/HookManager.php includes/Plugin.php tests/bootstrap.php tests/ContainerTest.php
git commit -m "feat: scaffold plugin with DI container and hook manager"
```

---

### Task 2: DomainRegistry — normalization and input parsing

**Files:**
- Create: `includes/Website/DomainRegistry.php`
- Test: `tests/Website/DomainRegistryTest.php`

**Interfaces:**
- Consumes: nothing (pure logic + WP core functions `wp_parse_url`)
- Produces: `The_Another\Plugin\Multi_Domain_Global_Styles\Website\DomainRegistry::normalize(string $raw): string`, `->parse_domains_input(string $raw): array` (returns `array<int, string>` of normalized, deduped, non-empty hostnames)

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace The_Another\Plugin\Multi_Domain_Global_Styles\Tests\Website;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\DomainRegistry;

#[CoversClass( DomainRegistry::class )]
class DomainRegistryTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private DomainRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->registry = new DomainRegistry();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public static function normalize_cases(): array {
		return array(
			'plain host'            => array( 'example.com', 'example.com' ),
			'uppercase'              => array( 'EXAMPLE.com', 'example.com' ),
			'leading www'            => array( 'www.example.com', 'example.com' ),
			'with port'              => array( 'example.com:8080', 'example.com' ),
			'www and port'           => array( 'WWW.Example.com:443', 'example.com' ),
			'full https url'         => array( 'https://example.com/path', 'example.com' ),
			'full http url with www' => array( 'http://www.example.com', 'example.com' ),
			'surrounding whitespace' => array( '  example.com  ', 'example.com' ),
			'empty string'           => array( '', '' ),
		);
	}

	#[DataProvider( 'normalize_cases' )]
	public function test_normalize( string $input, string $expected ): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$this->assertSame( $expected, $this->registry->normalize( $input ) );
	}

	public function test_parse_domains_input_splits_dedupes_and_normalizes(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$raw = "example.com\nWWW.Example.com\n\nother.test\nexample.com";

		$this->assertSame(
			array( 'example.com', 'other.test' ),
			$this->registry->parse_domains_input( $raw )
		);
	}

	public function test_parse_domains_input_ignores_blank_lines(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$this->assertSame( array(), $this->registry->parse_domains_input( "\n \n  \n" ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter DomainRegistryTest`
Expected: FAIL with "Class ... DomainRegistry not found" (class doesn't exist yet).

- [ ] **Step 3: Write implementation**

```php
<?php
/**
 * Domain Registry Service
 *
 * @package The_Another_Multi_Domain_Global_Styles
 * @since 1.0.0
 */

namespace The_Another\Plugin\Multi_Domain_Global_Styles\Website;

/**
 * Class DomainRegistry
 *
 * Normalizes hostnames and maintains the cached domain -> Website map.
 */
class DomainRegistry {

	/**
	 * Transient key for the cached domain -> Website ID map.
	 *
	 * @var string
	 */
	private const CACHE_KEY = 'mdgs_domain_map';

	/**
	 * Normalize a hostname: lowercase, strip scheme/path, strip port, strip leading www.
	 *
	 * @param string $raw Raw hostname or URL as entered by an admin.
	 * @return string Normalized hostname, or empty string if nothing usable was found.
	 */
	public function normalize( string $raw ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $raw ) || str_starts_with( $raw, '//' ) ) {
			$parsed = wp_parse_url( $raw );
			$raw    = $parsed['host'] ?? '';
		} else {
			// Bare hostname (optionally with a port or trailing path) — take
			// just the leading host-shaped portion.
			preg_match( '#^[a-z0-9.-]+#i', $raw, $matches );
			$raw = $matches[0] ?? '';
		}

		if ( '' === $raw ) {
			return '';
		}

		$raw = strtolower( $raw );
		$raw = preg_replace( '/:\d+$/', '', $raw );
		$raw = preg_replace( '/^www\./', '', $raw );

		return $raw;
	}

	/**
	 * Parse a textarea of one-hostname-per-line input into a deduped, normalized list.
	 *
	 * @param string $raw Raw textarea contents.
	 * @return array<int, string> Normalized hostnames, in first-seen order.
	 */
	public function parse_domains_input( string $raw ): array {
		$domains = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$normalized = $this->normalize( $line );
			if ( '' !== $normalized ) {
				$domains[ $normalized ] = true;
			}
		}

		return array_keys( $domains );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter DomainRegistryTest`
Expected: PASS (all data provider cases + both parse_domains_input tests).

- [ ] **Step 5: Commit**

```bash
git add includes/Website/DomainRegistry.php tests/Website/DomainRegistryTest.php
git commit -m "feat: add domain normalization and input parsing"
```

---

### Task 3: DomainRegistry — cached map and conflict detection

**Files:**
- Modify: `includes/Website/DomainRegistry.php`
- Modify: `tests/Website/DomainRegistryTest.php`

**Interfaces:**
- Consumes: `get_transient()`, `set_transient()`, `delete_transient()`, `get_posts()`, `get_post_meta()` (WP core, mocked in tests via Brain Monkey)
- Produces: `DomainRegistry::get_domain_map(): array<string, int>`, `->find_conflicting_website(string $normalized_domain, int $exclude_post_id = 0): ?int`, `->invalidate_cache(): void`

- [ ] **Step 1: Write the failing test (append to DomainRegistryTest.php)**

```php
	public function test_get_domain_map_returns_cached_value_when_present(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( 'mdgs_domain_map' )
			->andReturn( array( 'example.com' => 5 ) );

		$this->assertSame( array( 'example.com' => 5 ), $this->registry->get_domain_map() );
	}

	public function test_get_domain_map_builds_and_caches_when_absent(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array( 5, 7 ) );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_mdgs_domains', true )
			->andReturn( array( 'example.com', 'example.org' ) );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 7, '_mdgs_domains', true )
			->andReturn( array( 'other.test' ) );
		Functions\expect( 'set_transient' )
			->once()
			->with(
				'mdgs_domain_map',
				array(
					'example.com' => 5,
					'example.org' => 5,
					'other.test'  => 7,
				),
				0
			);

		$map = $this->registry->get_domain_map();

		$this->assertSame(
			array(
				'example.com' => 5,
				'example.org' => 5,
				'other.test'  => 7,
			),
			$map
		);
	}

	public function test_get_domain_map_skips_posts_with_no_domains_meta(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'get_posts' )->once()->andReturn( array( 9 ) );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 9, '_mdgs_domains', true )
			->andReturn( '' );
		Functions\expect( 'set_transient' )->once()->with( 'mdgs_domain_map', array(), 0 );

		$this->assertSame( array(), $this->registry->get_domain_map() );
	}

	public function test_find_conflicting_website_returns_null_when_domain_unused(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( array( 'example.com' => 5 ) );

		$this->assertNull( $this->registry->find_conflicting_website( 'other.test' ) );
	}

	public function test_find_conflicting_website_returns_null_for_self(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( array( 'example.com' => 5 ) );

		$this->assertNull( $this->registry->find_conflicting_website( 'example.com', 5 ) );
	}

	public function test_find_conflicting_website_returns_owning_id_for_other_post(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( array( 'example.com' => 5 ) );

		$this->assertSame( 5, $this->registry->find_conflicting_website( 'example.com', 9 ) );
	}

	public function test_invalidate_cache_deletes_transient(): void {
		Functions\expect( 'delete_transient' )->once()->with( 'mdgs_domain_map' );

		$this->registry->invalidate_cache();
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter DomainRegistryTest`
Expected: FAIL — `Call to undefined method DomainRegistry::get_domain_map()` (and similar for the other two new methods).

- [ ] **Step 3: Add methods to DomainRegistry**

Add to `includes/Website/DomainRegistry.php`, inside the `DomainRegistry` class:

```php
	/**
	 * Get the cached domain -> Website ID map, rebuilding it if not cached.
	 *
	 * @return array<string, int> Normalized domain => Website post ID.
	 */
	public function get_domain_map(): array {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map      = array();
		$websites = get_posts(
			array(
				'post_type'      => 'mdgs_website',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $websites as $website_id ) {
			$domains = get_post_meta( $website_id, '_mdgs_domains', true );

			if ( ! is_array( $domains ) ) {
				continue;
			}

			foreach ( $domains as $domain ) {
				$map[ $domain ] = $website_id;
			}
		}

		set_transient( self::CACHE_KEY, $map, 0 );

		return $map;
	}

	/**
	 * Find the Website ID that already owns a normalized domain, if any other than $exclude_post_id.
	 *
	 * @param string $normalized_domain Normalized hostname.
	 * @param int    $exclude_post_id   Website post ID to treat as "self" (never reported as a conflict).
	 * @return int|null Conflicting Website ID, or null if the domain is free (or only used by $exclude_post_id).
	 */
	public function find_conflicting_website( string $normalized_domain, int $exclude_post_id = 0 ): ?int {
		$map = $this->get_domain_map();

		if ( ! isset( $map[ $normalized_domain ] ) ) {
			return null;
		}

		if ( $map[ $normalized_domain ] === $exclude_post_id ) {
			return null;
		}

		return $map[ $normalized_domain ];
	}

	/**
	 * Invalidate the cached domain map. Call after any Website save.
	 *
	 * @return void
	 */
	public function invalidate_cache(): void {
		delete_transient( self::CACHE_KEY );
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter DomainRegistryTest`
Expected: PASS (all tests in the file, including Task 2's).

- [ ] **Step 5: Commit**

```bash
git add includes/Website/DomainRegistry.php tests/Website/DomainRegistryTest.php
git commit -m "feat: add cached domain map and conflict detection"
```

---

### Task 4: WebsiteRepository — read helpers

**Files:**
- Create: `includes/Website/WebsiteRepository.php`
- Test: `tests/Website/WebsiteRepositoryTest.php`

**Interfaces:**
- Consumes: `get_post_meta()`, `get_posts()` (WP core)
- Produces: `WebsiteRepository::get_domains(int $website_id): array`, `->get_variables(int $website_id): array`, `->get_default_website_id(): ?int`, `->get_global_styles_post_id(int $website_id): ?int`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace The_Another\Plugin\Multi_Domain_Global_Styles\Tests\Website;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\WebsiteRepository;

#[CoversClass( WebsiteRepository::class )]
class WebsiteRepositoryTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private WebsiteRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->repository = new WebsiteRepository();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_domains_returns_array_meta(): void {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_mdgs_domains', true )
			->andReturn( array( 'example.com' ) );

		$this->assertSame( array( 'example.com' ), $this->repository->get_domains( 5 ) );
	}

	public function test_get_domains_returns_empty_array_when_meta_missing(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '' );

		$this->assertSame( array(), $this->repository->get_domains( 5 ) );
	}

	public function test_get_variables_returns_array_meta(): void {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_mdgs_variables', true )
			->andReturn( array( 'name' => 'Acme' ) );

		$this->assertSame( array( 'name' => 'Acme' ), $this->repository->get_variables( 5 ) );
	}

	public function test_get_variables_returns_empty_array_when_meta_missing(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '' );

		$this->assertSame( array(), $this->repository->get_variables( 5 ) );
	}

	public function test_get_default_website_id_returns_id_when_found(): void {
		Functions\expect( 'get_posts' )
			->once()
			->with(
				array(
					'post_type'      => 'mdgs_website',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_mdgs_is_default',
					'meta_value'     => '1',
				)
			)
			->andReturn( array( 12 ) );

		$this->assertSame( 12, $this->repository->get_default_website_id() );
	}

	public function test_get_default_website_id_returns_null_when_none_found(): void {
		Functions\expect( 'get_posts' )->once()->andReturn( array() );

		$this->assertNull( $this->repository->get_default_website_id() );
	}

	public function test_get_global_styles_post_id_returns_int_when_set(): void {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_mdgs_global_styles_post_id', true )
			->andReturn( '42' );

		$this->assertSame( 42, $this->repository->get_global_styles_post_id( 5 ) );
	}

	public function test_get_global_styles_post_id_returns_null_when_unset(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '' );

		$this->assertNull( $this->repository->get_global_styles_post_id( 5 ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter WebsiteRepositoryTest`
Expected: FAIL with "Class ... WebsiteRepository not found".

- [ ] **Step 3: Write implementation**

```php
<?php
/**
 * Website Repository Service
 *
 * @package The_Another_Multi_Domain_Global_Styles
 * @since 1.0.0
 */

namespace The_Another\Plugin\Multi_Domain_Global_Styles\Website;

/**
 * Class WebsiteRepository
 *
 * Read-only helpers for `mdgs_website` post data.
 */
class WebsiteRepository {

	/**
	 * Get a Website's registered domains.
	 *
	 * @param int $website_id Website post ID.
	 * @return array<int, string> Normalized hostnames.
	 */
	public function get_domains( int $website_id ): array {
		$domains = get_post_meta( $website_id, '_mdgs_domains', true );

		return is_array( $domains ) ? $domains : array();
	}

	/**
	 * Get a Website's content variables.
	 *
	 * @param int $website_id Website post ID.
	 * @return array<string, string> Variable key => value.
	 */
	public function get_variables( int $website_id ): array {
		$variables = get_post_meta( $website_id, '_mdgs_variables', true );

		return is_array( $variables ) ? $variables : array();
	}

	/**
	 * Get the Website ID flagged as the fallback for unmatched domains.
	 *
	 * @return int|null Website post ID, or null if none is flagged.
	 */
	public function get_default_website_id(): ?int {
		$posts = get_posts(
			array(
				'post_type'      => 'mdgs_website',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_mdgs_is_default',
				'meta_value'     => '1',
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

	/**
	 * Get the ID of a Website's dedicated wp_global_styles post.
	 *
	 * @param int $website_id Website post ID.
	 * @return int|null Global styles post ID, or null if not yet created.
	 */
	public function get_global_styles_post_id( int $website_id ): ?int {
		$id = get_post_meta( $website_id, '_mdgs_global_styles_post_id', true );

		return $id ? (int) $id : null;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter WebsiteRepositoryTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/Website/WebsiteRepository.php tests/Website/WebsiteRepositoryTest.php
git commit -m "feat: add WebsiteRepository read helpers"
```

---

### Task 5: DomainResolver — HTTP_HOST to Website ID

**Files:**
- Create: `includes/Website/DomainResolver.php`
- Test: `tests/Website/DomainResolverTest.php`

**Interfaces:**
- Consumes: `DomainRegistry::normalize(string): string`, `->get_domain_map(): array` (Task 2/3); `WebsiteRepository::get_default_website_id(): ?int` (Task 4)
- Produces: `DomainResolver::__construct(DomainRegistry $domain_registry, WebsiteRepository $website_repository)`, `->resolve_host(string $host): ?int`, `->resolve_current_request(): ?int`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace The_Another\Plugin\Multi_Domain_Global_Styles\Tests\Website;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\DomainRegistry;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\DomainResolver;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\WebsiteRepository;

#[CoversClass( DomainResolver::class )]
class DomainResolverTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	public function test_resolve_host_returns_matched_website_id(): void {
		$registry = Mockery::mock( DomainRegistry::class );
		$registry->shouldReceive( 'normalize' )->with( 'example.com' )->andReturn( 'example.com' );
		$registry->shouldReceive( 'get_domain_map' )->andReturn( array( 'example.com' => 5 ) );

		$repository = Mockery::mock( WebsiteRepository::class );

		$resolver = new DomainResolver( $registry, $repository );

		$this->assertSame( 5, $resolver->resolve_host( 'example.com' ) );
	}

	public function test_resolve_host_falls_back_to_default_when_unmatched(): void {
		$registry = Mockery::mock( DomainRegistry::class );
		$registry->shouldReceive( 'normalize' )->with( 'unknown.test' )->andReturn( 'unknown.test' );
		$registry->shouldReceive( 'get_domain_map' )->andReturn( array( 'example.com' => 5 ) );

		$repository = Mockery::mock( WebsiteRepository::class );
		$repository->shouldReceive( 'get_default_website_id' )->once()->andReturn( 9 );

		$resolver = new DomainResolver( $registry, $repository );

		$this->assertSame( 9, $resolver->resolve_host( 'unknown.test' ) );
	}

	public function test_resolve_host_returns_null_when_unmatched_and_no_default(): void {
		$registry = Mockery::mock( DomainRegistry::class );
		$registry->shouldReceive( 'normalize' )->with( 'unknown.test' )->andReturn( 'unknown.test' );
		$registry->shouldReceive( 'get_domain_map' )->andReturn( array() );

		$repository = Mockery::mock( WebsiteRepository::class );
		$repository->shouldReceive( 'get_default_website_id' )->once()->andReturn( null );

		$resolver = new DomainResolver( $registry, $repository );

		$this->assertNull( $resolver->resolve_host( 'unknown.test' ) );
	}

	public function test_resolve_host_falls_back_to_default_for_empty_host(): void {
		$registry = Mockery::mock( DomainRegistry::class );
		$registry->shouldReceive( 'normalize' )->with( '' )->andReturn( '' );

		$repository = Mockery::mock( WebsiteRepository::class );
		$repository->shouldReceive( 'get_default_website_id' )->once()->andReturn( 9 );

		$resolver = new DomainResolver( $registry, $repository );

		$this->assertSame( 9, $resolver->resolve_host( '' ) );
	}

	public function test_resolve_current_request_reads_http_host_server_var(): void {
		$_SERVER['HTTP_HOST'] = 'example.com';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$registry = Mockery::mock( DomainRegistry::class );
		$registry->shouldReceive( 'normalize' )->with( 'example.com' )->andReturn( 'example.com' );
		$registry->shouldReceive( 'get_domain_map' )->andReturn( array( 'example.com' => 5 ) );

		$repository = Mockery::mock( WebsiteRepository::class );

		$resolver = new DomainResolver( $registry, $repository );

		$this->assertSame( 5, $resolver->resolve_current_request() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter DomainResolverTest`
Expected: FAIL with "Class ... DomainResolver not found".

- [ ] **Step 3: Write implementation**

```php
<?php
/**
 * Domain Resolver Service
 *
 * @package The_Another_Multi_Domain_Global_Styles
 * @since 1.0.0
 */

namespace The_Another\Plugin\Multi_Domain_Global_Styles\Website;

/**
 * Class DomainResolver
 *
 * Resolves the current request's hostname to a Website ID.
 */
class DomainResolver {

	/**
	 * Domain registry.
	 *
	 * @var DomainRegistry
	 */
	private DomainRegistry $domain_registry;

	/**
	 * Website repository.
	 *
	 * @var WebsiteRepository
	 */
	private WebsiteRepository $website_repository;

	/**
	 * Constructor.
	 *
	 * @param DomainRegistry    $domain_registry    Domain registry service.
	 * @param WebsiteRepository $website_repository Website repository service.
	 */
	public function __construct( DomainRegistry $domain_registry, WebsiteRepository $website_repository ) {
		$this->domain_registry    = $domain_registry;
		$this->website_repository = $website_repository;
	}

	/**
	 * Resolve the current request's HTTP_HOST to a Website ID.
	 *
	 * @return int|null Website post ID, or null if unmatched with no default.
	 */
	public function resolve_current_request(): ?int {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

		return $this->resolve_host( $host );
	}

	/**
	 * Resolve an arbitrary hostname to a Website ID.
	 *
	 * @param string $host Raw hostname (e.g. from HTTP_HOST).
	 * @return int|null Website post ID, or null if unmatched with no default.
	 */
	public function resolve_host( string $host ): ?int {
		$normalized = $this->domain_registry->normalize( $host );

		if ( '' === $normalized ) {
			return $this->website_repository->get_default_website_id();
		}

		$map = $this->domain_registry->get_domain_map();

		if ( isset( $map[ $normalized ] ) ) {
			return $map[ $normalized ];
		}

		return $this->website_repository->get_default_website_id();
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter DomainResolverTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/Website/DomainResolver.php tests/Website/DomainResolverTest.php
git commit -m "feat: add DomainResolver for HTTP_HOST to Website ID lookup"
```

---

### Task 6: VariableParser

**Files:**
- Create: `includes/ContentVariables/VariableParser.php`
- Test: `tests/ContentVariables/VariableParserTest.php`

**Interfaces:**
- Consumes: nothing (pure logic)
- Produces: `VariableParser::parse(string $raw): array<string, string>`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace The_Another\Plugin\Multi_Domain_Global_Styles\Tests\ContentVariables;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use The_Another\Plugin\Multi_Domain_Global_Styles\ContentVariables\VariableParser;

#[CoversClass( VariableParser::class )]
class VariableParserTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private VariableParser $parser;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->parser = new VariableParser();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_parses_key_value_lines(): void {
		$raw = "name = Acme Auctions\nphone = 555-0100";

		$this->assertSame(
			array(
				'name'  => 'Acme Auctions',
				'phone' => '555-0100',
			),
			$this->parser->parse( $raw )
		);
	}

	public function test_lowercases_and_strips_invalid_characters_from_keys(): void {
		$raw = "Website Name = Acme";

		$this->assertSame( array( 'websitename' => 'Acme' ), $this->parser->parse( $raw ) );
	}

	public function test_ignores_lines_without_equals_sign(): void {
		$raw = "not a variable line\nname = Acme";

		$this->assertSame( array( 'name' => 'Acme' ), $this->parser->parse( $raw ) );
	}

	public function test_ignores_lines_with_empty_key(): void {
		$raw = " = orphan value\nname = Acme";

		$this->assertSame( array( 'name' => 'Acme' ), $this->parser->parse( $raw ) );
	}

	public function test_value_may_contain_equals_signs(): void {
		$raw = 'formula = a=b+c';

		$this->assertSame( array( 'formula' => 'a=b+c' ), $this->parser->parse( $raw ) );
	}

	public function test_returns_empty_array_for_blank_input(): void {
		$this->assertSame( array(), $this->parser->parse( "\n \n" ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter VariableParserTest`
Expected: FAIL with "Class ... VariableParser not found".

- [ ] **Step 3: Write implementation**

```php
<?php
/**
 * Variable Parser Service
 *
 * @package The_Another_Multi_Domain_Global_Styles
 * @since 1.0.0
 */

namespace The_Another\Plugin\Multi_Domain_Global_Styles\ContentVariables;

/**
 * Class VariableParser
 *
 * Parses "key = value" textarea input into a variable map.
 */
class VariableParser {

	/**
	 * Parse "key = value" lines into an associative array.
	 *
	 * @param string $raw Raw textarea contents.
	 * @return array<string, string> Lowercased, alphanumeric+underscore key => trimmed value.
	 */
	public function parse( string $raw ): array {
		$variables = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( ! str_contains( $line, '=' ) ) {
				continue;
			}

			[ $key, $value ] = array_map( 'trim', explode( '=', $line, 2 ) );
			$key              = strtolower( preg_replace( '/[^a-z0-9_]/i', '', $key ) );

			if ( '' === $key ) {
				continue;
			}

			$variables[ $key ] = $value;
		}

		return $variables;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter VariableParserTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/ContentVariables/VariableParser.php tests/ContentVariables/VariableParserTest.php
git commit -m "feat: add VariableParser for key=value textarea input"
```

---

### Task 7: GlobalStylesPostService

**Files:**
- Create: `includes/GlobalStyles/GlobalStylesPostService.php`
- Test: `tests/GlobalStyles/GlobalStylesPostServiceTest.php`

**Interfaces:**
- Consumes: `wp_insert_post()`, `get_post_status()`, `get_post_meta()`, `update_post_meta()`, `get_post()`, `is_wp_error()`, `wp_json_encode()` (WP core)
- Produces: `GlobalStylesPostService::ensure_global_styles_post(int $website_id): int`, `->get_global_styles_data(int $global_styles_post_id): array`

**Note:** the created `wp_global_styles` post is deliberately **not** tagged with the `wp_theme` taxonomy for the active theme's stylesheet. Verified against WordPress core (`class-wp-theme-json-resolver.php`): the resolver that finds "the" canonical site-wide global styles post queries by that taxonomy term with `orderby => date desc, posts_per_page => 1` — tagging our posts would make whichever Website was saved most recently hijack that lookup and corrupt the real site-wide default everywhere core code resolves it untouched (e.g. for requests that don't match any Website).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace The_Another\Plugin\Multi_Domain_Global_Styles\Tests\GlobalStyles;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use The_Another\Plugin\Multi_Domain_Global_Styles\GlobalStyles\GlobalStylesPostService;

#[CoversClass( GlobalStylesPostService::class )]
class GlobalStylesPostServiceTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private GlobalStylesPostService $service;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->service = new GlobalStylesPostService();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_ensure_global_styles_post_returns_existing_published_post(): void {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_mdgs_global_styles_post_id', true )
			->andReturn( '42' );
		Functions\expect( 'get_post_status' )->once()->with( '42' )->andReturn( 'publish' );
		Functions\expect( 'wp_insert_post' )->never();

		$this->assertSame( 42, $this->service->ensure_global_styles_post( 5 ) );
	}

	public function test_ensure_global_styles_post_creates_when_missing(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '' );
		Functions\expect( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		Functions\expect( 'wp_insert_post' )
			->once()
			->with(
				Mockery::on(
					function ( $args ) {
						return 'wp_global_styles' === $args['post_type']
							&& 'publish' === $args['post_status']
							&& str_contains( $args['post_content'], '"isGlobalStylesUserThemeJSON":true' );
					}
				),
				true
			)
			->andReturn( 99 );
		// No is_wp_error mock: tests/bootstrap.php already defines the real stub
		// (Brain Monkey cannot redefine an already-defined function), and 99 is
		// an int, so it naturally returns false.
		Functions\expect( 'update_post_meta' )->once()->with( 5, '_mdgs_global_styles_post_id', 99 );

		$this->assertSame( 99, $this->service->ensure_global_styles_post( 5 ) );
	}

	public function test_ensure_global_styles_post_recreates_when_existing_post_gone(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '42' );
		Functions\expect( 'get_post_status' )->once()->with( '42' )->andReturn( false );
		Functions\expect( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( 100 );
		Functions\expect( 'update_post_meta' )->once()->with( 5, '_mdgs_global_styles_post_id', 100 );

		$this->assertSame( 100, $this->service->ensure_global_styles_post( 5 ) );
	}

	public function test_get_global_styles_data_decodes_post_content(): void {
		$post               = new \stdClass();
		$post->post_content = '{"version":3,"settings":{"color":{"palette":[]}},"styles":{}}';

		Functions\expect( 'get_post' )->once()->with( 42 )->andReturn( $post );

		$this->assertSame(
			array(
				'version'  => 3,
				'settings' => array( 'color' => array( 'palette' => array() ) ),
				'styles'   => array(),
			),
			$this->service->get_global_styles_data( 42 )
		);
	}

	public function test_get_global_styles_data_returns_empty_array_when_post_missing(): void {
		Functions\expect( 'get_post' )->once()->with( 42 )->andReturn( null );

		$this->assertSame( array(), $this->service->get_global_styles_data( 42 ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter GlobalStylesPostServiceTest`
Expected: FAIL with "Class ... GlobalStylesPostService not found".

- [ ] **Step 3: Write implementation**

```php
<?php
/**
 * Global Styles Post Service
 *
 * @package The_Another_Multi_Domain_Global_Styles
 * @since 1.0.0
 */

namespace The_Another\Plugin\Multi_Domain_Global_Styles\GlobalStyles;

use RuntimeException;

/**
 * Class GlobalStylesPostService
 *
 * Creates and reads the dedicated wp_global_styles post for each Website.
 *
 * Deliberately does NOT tag created posts with the wp_theme taxonomy — doing
 * so would make WP_Theme_JSON_Resolver's "find the canonical global styles
 * post for the active theme" query (ordered by date desc) pick up whichever
 * Website was saved most recently, corrupting the real site-wide default.
 */
class GlobalStylesPostService {

	/**
	 * Postmeta key storing the linked wp_global_styles post ID.
	 *
	 * @var string
	 */
	private const META_KEY = '_mdgs_global_styles_post_id';

	/**
	 * Ensure a Website has a wp_global_styles post, creating one if missing.
	 *
	 * @param int $website_id Website post ID.
	 * @return int The wp_global_styles post ID.
	 *
	 * @throws RuntimeException If post creation fails.
	 */
	public function ensure_global_styles_post( int $website_id ): int {
		$existing_id = get_post_meta( $website_id, self::META_KEY, true );

		if ( $existing_id && get_post_status( $existing_id ) ) {
			return (int) $existing_id;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'publish',
				'post_title'   => 'Custom Styles',
				'post_content' => wp_json_encode(
					array(
						'version'                      => 3,
						'isGlobalStylesUserThemeJSON'  => true,
						'settings'                     => new \stdClass(),
						'styles'                       => new \stdClass(),
					)
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( esc_html( $post_id->get_error_message() ) );
		}

		update_post_meta( $website_id, self::META_KEY, $post_id );

		return (int) $post_id;
	}

	/**
	 * Get the decoded settings/styles data for a wp_global_styles post.
	 *
	 * @param int $global_styles_post_id wp_global_styles post ID.
	 * @return array<string, mixed> Decoded content, or empty array if the post is missing/invalid.
	 */
	public function get_global_styles_data( int $global_styles_post_id ): array {
		$post = get_post( $global_styles_post_id );

		if ( ! $post ) {
			return array();
		}

		$decoded = json_decode( $post->post_content, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter GlobalStylesPostServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/GlobalStyles/GlobalStylesPostService.php tests/GlobalStyles/GlobalStylesPostServiceTest.php
git commit -m "feat: add GlobalStylesPostService to manage per-Website styles posts"
```

---

### Task 8: GlobalStylesOverride — frontend theme.json merge

**Files:**
- Create: `includes/GlobalStyles/GlobalStylesOverride.php`
- Test: `tests/GlobalStyles/GlobalStylesOverrideTest.php`

**Interfaces:**
- Consumes: `DomainResolver::resolve_current_request(): ?int` (Task 5), `WebsiteRepository::get_global_styles_post_id(int): ?int` (Task 4), `GlobalStylesPostService::get_global_styles_data(int): array` (Task 7), `is_admin()` (WP core)
- Produces: `GlobalStylesOverride::__construct(DomainResolver, WebsiteRepository, GlobalStylesPostService)`, `->filter_theme_json(mixed $theme_json): mixed` (hooked to `wp_theme_json_data_user`)

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace The_Another\Plugin\Multi_Domain_Global_Styles\Tests\GlobalStyles;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\DomainResolver;
use The_Another\Plugin\Multi_Domain_Global_Styles\GlobalStyles\GlobalStylesOverride;
use The_Another\Plugin\Multi_Domain_Global_Styles\GlobalStyles\GlobalStylesPostService;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\WebsiteRepository;

/**
 * Minimal stand-in for WP_Theme_JSON_Data, which isn't available outside a
 * full WordPress load. Only implements what GlobalStylesOverride calls.
 */
class FakeThemeJsonData {
	public array $received_update = array();

	public function update_with( array $data ): self {
		$this->received_update = $data;
		return $this;
	}
}

#[CoversClass( GlobalStylesOverride::class )]
class GlobalStylesOverrideTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_input_unchanged_in_admin(): void {
		Functions\expect( 'is_admin' )->once()->andReturn( true );

		$resolver   = Mockery::mock( DomainResolver::class );
		$repository = Mockery::mock( WebsiteRepository::class );
		$posts      = Mockery::mock( GlobalStylesPostService::class );

		$override    = new GlobalStylesOverride( $resolver, $repository, $posts );
		$theme_json  = new FakeThemeJsonData();

		$this->assertSame( $theme_json, $override->filter_theme_json( $theme_json ) );
	}

	public function test_returns_input_unchanged_when_no_website_resolved(): void {
		Functions\expect( 'is_admin' )->once()->andReturn( false );

		$resolver = Mockery::mock( DomainResolver::class );
		$resolver->shouldReceive( 'resolve_current_request' )->once()->andReturn( null );

		$repository = Mockery::mock( WebsiteRepository::class );
		$posts      = Mockery::mock( GlobalStylesPostService::class );

		$override   = new GlobalStylesOverride( $resolver, $repository, $posts );
		$theme_json = new FakeThemeJsonData();

		$this->assertSame( $theme_json, $override->filter_theme_json( $theme_json ) );
	}

	public function test_returns_input_unchanged_when_website_has_no_styles_post(): void {
		Functions\expect( 'is_admin' )->once()->andReturn( false );

		$resolver = Mockery::mock( DomainResolver::class );
		$resolver->shouldReceive( 'resolve_current_request' )->once()->andReturn( 5 );

		$repository = Mockery::mock( WebsiteRepository::class );
		$repository->shouldReceive( 'get_global_styles_post_id' )->once()->with( 5 )->andReturn( null );

		$posts = Mockery::mock( GlobalStylesPostService::class );

		$override   = new GlobalStylesOverride( $resolver, $repository, $posts );
		$theme_json = new FakeThemeJsonData();

		$this->assertSame( $theme_json, $override->filter_theme_json( $theme_json ) );
	}

	public function test_returns_input_unchanged_when_website_styles_are_empty(): void {
		Functions\expect( 'is_admin' )->once()->andReturn( false );

		$resolver = Mockery::mock( DomainResolver::class );
		$resolver->shouldReceive( 'resolve_current_request' )->once()->andReturn( 5 );

		$repository = Mockery::mock( WebsiteRepository::class );
		$repository->shouldReceive( 'get_global_styles_post_id' )->once()->with( 5 )->andReturn( 42 );

		$posts = Mockery::mock( GlobalStylesPostService::class );
		$posts->shouldReceive( 'get_global_styles_data' )->once()->with( 42 )->andReturn(
			array(
				'settings' => array(),
				'styles'   => array(),
			)
		);

		$override   = new GlobalStylesOverride( $resolver, $repository, $posts );
		$theme_json = new FakeThemeJsonData();

		$this->assertSame( $theme_json, $override->filter_theme_json( $theme_json ) );
	}

	public function test_merges_website_styles_over_input_when_present(): void {
		Functions\expect( 'is_admin' )->once()->andReturn( false );

		$resolver = Mockery::mock( DomainResolver::class );
		$resolver->shouldReceive( 'resolve_current_request' )->once()->andReturn( 5 );

		$repository = Mockery::mock( WebsiteRepository::class );
		$repository->shouldReceive( 'get_global_styles_post_id' )->once()->with( 5 )->andReturn( 42 );

		$settings = array( 'color' => array( 'palette' => array( array( 'slug' => 'brand-primary', 'color' => '#123456' ) ) ) );

		$posts = Mockery::mock( GlobalStylesPostService::class );
		$posts->shouldReceive( 'get_global_styles_data' )->once()->with( 42 )->andReturn(
			array(
				'settings' => $settings,
				'styles'   => array(),
			)
		);

		$override   = new GlobalStylesOverride( $resolver, $repository, $posts );
		$theme_json = new FakeThemeJsonData();

		$result = $override->filter_theme_json( $theme_json );

		$this->assertSame( $theme_json, $result );
		$this->assertSame( 3, $theme_json->received_update['version'] );
		$this->assertTrue( $theme_json->received_update['isGlobalStylesUserThemeJSON'] );
		$this->assertSame( $settings, $theme_json->received_update['settings'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter GlobalStylesOverrideTest`
Expected: FAIL with "Class ... GlobalStylesOverride not found".

- [ ] **Step 3: Write implementation**

```php
<?php
/**
 * Global Styles Override Service
 *
 * @package The_Another_Multi_Domain_Global_Styles
 * @since 1.0.0
 */

namespace The_Another\Plugin\Multi_Domain_Global_Styles\GlobalStyles;

/**
 * Class GlobalStylesOverride
 *
 * Hooked to `wp_theme_json_data_user` on the frontend: merges the resolved
 * Website's stored global-styles data over whatever the site's own user
 * global styles would otherwise be. Partial merge (update_with), not full
 * replacement — a Website only needs to define what differs from the theme.
 */
class GlobalStylesOverride {

	/**
	 * Domain resolver.
	 *
	 * @var DomainResolver
	 */
	private DomainResolver $domain_resolver;

	/**
	 * Website repository.
	 *
	 * @var WebsiteRepository
	 */
	private WebsiteRepository $website_repository;

	/**
	 * Global styles post service.
	 *
	 * @var GlobalStylesPostService
	 */
	private GlobalStylesPostService $global_styles_post_service;

	/**
	 * Constructor.
	 *
	 * @param DomainResolver             $domain_resolver             Domain resolver service.
	 * @param WebsiteRepository          $website_repository          Website repository service.
	 * @param GlobalStylesPostService  $global_styles_post_service  Global styles post service.
	 */
	public function __construct(
		DomainResolver $domain_resolver,
		WebsiteRepository $website_repository,
		GlobalStylesPostService $global_styles_post_service
	) {
		$this->domain_resolver            = $domain_resolver;
		$this->website_repository         = $website_repository;
		$this->global_styles_post_service = $global_styles_post_service;
	}

	/**
	 * Filter callback for `wp_theme_json_data_user`.
	 *
	 * @param mixed $theme_json WP_Theme_JSON_Data instance.
	 * @return mixed WP_Theme_JSON_Data instance, possibly merged with Website overrides.
	 */
	public function filter_theme_json( mixed $theme_json ): mixed {
		if ( is_admin() ) {
			return $theme_json;
		}

		$website_id = $this->domain_resolver->resolve_current_request();

		if ( null === $website_id ) {
			return $theme_json;
		}

		$global_styles_post_id = $this->website_repository->get_global_styles_post_id( $website_id );

		if ( null === $global_styles_post_id ) {
			return $theme_json;
		}

		$data = $this->global_styles_post_service->get_global_styles_data( $global_styles_post_id );

		if ( empty( $data['settings'] ) && empty( $data['styles'] ) ) {
			return $theme_json;
		}

		return $theme_json->update_with(
			array(
				'version'                     => 3,
				'isGlobalStylesUserThemeJSON' => true,
				'settings'                    => $data['settings'] ?? array(),
				'styles'                      => $data['styles'] ?? array(),
			)
		);
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter GlobalStylesOverrideTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/GlobalStyles/GlobalStylesOverride.php tests/GlobalStyles/GlobalStylesOverrideTest.php
git commit -m "feat: add GlobalStylesOverride frontend theme.json merge"
```

---

### Task 9: VariableSubstitutionService

**Files:**
- Create: `includes/ContentVariables/VariableSubstitutionService.php`
- Test: `tests/ContentVariables/VariableSubstitutionServiceTest.php`

**Interfaces:**
- Consumes: `DomainResolver::resolve_current_request(): ?int` (Task 5), `WebsiteRepository::get_variables(int): array` (Task 4), `is_admin()`, `wp_doing_ajax()` (WP core)
- Produces: `VariableSubstitutionService::__construct(DomainResolver, WebsiteRepository)`, `->replace(string $html): string`, `->start_buffer(): void` (hooked to `template_redirect`)

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace The_Another\Plugin\Multi_Domain_Global_Styles\Tests\ContentVariables;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\DomainResolver;
use The_Another\Plugin\Multi_Domain_Global_Styles\ContentVariables\VariableSubstitutionService;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\WebsiteRepository;

#[CoversClass( VariableSubstitutionService::class )]
class VariableSubstitutionServiceTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_service( ?int $website_id, array $variables = array() ): VariableSubstitutionService {
		$resolver = Mockery::mock( DomainResolver::class );
		$resolver->shouldReceive( 'resolve_current_request' )->andReturn( $website_id );

		$repository = Mockery::mock( WebsiteRepository::class );
		if ( null !== $website_id ) {
			$repository->shouldReceive( 'get_variables' )->with( $website_id )->andReturn( $variables );
		}

		return new VariableSubstitutionService( $resolver, $repository );
	}

	public function test_replaces_known_website_token(): void {
		Functions\when( 'esc_html' )->returnArg();

		$service = $this->make_service( 5, array( 'name' => 'Acme Auctions' ) );

		$this->assertSame(
			'Welcome to Acme Auctions today.',
			$service->replace( 'Welcome to %%website.name%% today.' )
		);
	}

	public function test_leaves_undefined_token_literal(): void {
		$service = $this->make_service( 5, array( 'name' => 'Acme Auctions' ) );

		$this->assertSame(
			'Call us at %%website.phone%%.',
			$service->replace( 'Call us at %%website.phone%%.' )
		);
	}

	public function test_ignores_tokens_outside_website_namespace(): void {
		$service = $this->make_service( 5, array( 'name' => 'Acme Auctions' ) );

		$this->assertSame(
			'%%other.name%% stays untouched.',
			$service->replace( '%%other.name%% stays untouched.' )
		);
	}

	public function test_replaces_multiple_adjacent_tokens(): void {
		Functions\when( 'esc_html' )->returnArg();

		$service = $this->make_service( 5, array( 'name' => 'Acme', 'phone' => '555-0100' ) );

		$this->assertSame(
			'AcmeCall 555-0100',
			$service->replace( '%%website.name%%Call %%website.phone%%' )
		);
	}

	public function test_escapes_variable_value(): void {
		Functions\when( 'esc_html' )->alias(
			static fn( $text ) => str_replace( '<', '&lt;', $text )
		);

		$service = $this->make_service( 5, array( 'name' => '<script>Acme</script>' ) );

		$this->assertSame(
			'&lt;script>Acme</script>',
			$service->replace( '%%website.name%%' )
		);
	}

	public function test_returns_html_unchanged_when_no_website_resolved(): void {
		$service = $this->make_service( null );

		$this->assertSame( '%%website.name%%', $service->replace( '%%website.name%%' ) );
	}

	public function test_returns_html_unchanged_when_no_variables_defined(): void {
		$service = $this->make_service( 5, array() );

		$this->assertSame( '%%website.name%%', $service->replace( '%%website.name%%' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter VariableSubstitutionServiceTest`
Expected: FAIL with "Class ... VariableSubstitutionService not found".

- [ ] **Step 3: Write implementation**

```php
<?php
/**
 * Variable Substitution Service
 *
 * @package The_Another_Multi_Domain_Global_Styles
 * @since 1.0.0
 */

namespace The_Another\Plugin\Multi_Domain_Global_Styles\ContentVariables;

/**
 * Class VariableSubstitutionService
 *
 * Replaces %%website.*%% tokens in the final rendered HTML with the resolved
 * Website's variable values. Runs as a whole-page output buffer so it covers
 * post content, template parts, patterns, widgets, and menus in one pass,
 * rather than hooking a dozen individual WP content filters.
 */
class VariableSubstitutionService {

	/**
	 * Domain resolver.
	 *
	 * @var DomainResolver
	 */
	private DomainResolver $domain_resolver;

	/**
	 * Website repository.
	 *
	 * @var WebsiteRepository
	 */
	private WebsiteRepository $website_repository;

	/**
	 * Constructor.
	 *
	 * @param DomainResolver    $domain_resolver    Domain resolver service.
	 * @param WebsiteRepository $website_repository Website repository service.
	 */
	public function __construct( DomainResolver $domain_resolver, WebsiteRepository $website_repository ) {
		$this->domain_resolver    = $domain_resolver;
		$this->website_repository = $website_repository;
	}

	/**
	 * Start the output buffer on frontend HTML requests. Hooked to `template_redirect`.
	 *
	 * @return void
	 */
	public function start_buffer(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		ob_start( array( $this, 'replace' ) );
	}

	/**
	 * Replace %%website.*%% tokens in HTML with the resolved Website's variable values.
	 *
	 * @param string $html Rendered page HTML.
	 * @return string HTML with known tokens replaced; unknown tokens are left literal.
	 */
	public function replace( string $html ): string {
		$website_id = $this->domain_resolver->resolve_current_request();

		if ( null === $website_id ) {
			return $html;
		}

		$variables = $this->website_repository->get_variables( $website_id );

		if ( empty( $variables ) ) {
			return $html;
		}

		return preg_replace_callback(
			'/%%website\.([a-z0-9_]+)%%/i',
			static function ( array $matches ) use ( $variables ) {
				$key = strtolower( $matches[1] );

				if ( ! isset( $variables[ $key ] ) ) {
					return $matches[0];
				}

				return esc_html( $variables[ $key ] );
			},
			$html
		);
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter VariableSubstitutionServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/ContentVariables/VariableSubstitutionService.php tests/ContentVariables/VariableSubstitutionServiceTest.php
git commit -m "feat: add VariableSubstitutionService for %%website.*%% tokens"
```

---

### Task 10: WebsitePostType — CPT, meta boxes, save glue

**Files:**
- Create: `includes/Website/WebsitePostType.php`
- Test: `tests/Website/WebsitePostTypeTest.php`

**Interfaces:**
- Consumes: `DomainRegistry::parse_domains_input(string): array`, `->find_conflicting_website(string, int): ?int`, `->invalidate_cache(): void` (Task 2/3); `VariableParser::parse(string): array` (Task 6); `GlobalStylesPostService::ensure_global_styles_post(int): int`, `->get_global_styles_data(int): array` (Task 7)
- Produces: `WebsitePostType::POST_TYPE = 'mdgs_website'`, `->__construct(DomainRegistry, VariableParser, GlobalStylesPostService)`, `->register(): void`, `->register_meta_boxes(): void`, `->save(int $post_id): void`

This task's test coverage focuses on `save()`'s branching logic (the part with real decisions to get wrong), matching the codebase's existing convention of not unit-testing render/glue methods that just echo HTML (see `render.php` files elsewhere in this environment, which aren't unit tested). The render methods are exercised manually in Task 13's smoke check instead.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace The_Another\Plugin\Multi_Domain_Global_Styles\Tests\Website;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\WebsitePostType;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\DomainRegistry;
use The_Another\Plugin\Multi_Domain_Global_Styles\GlobalStyles\GlobalStylesPostService;
use The_Another\Plugin\Multi_Domain_Global_Styles\ContentVariables\VariableParser;

#[CoversClass( WebsitePostType::class )]
class WebsitePostTypeTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$_POST['mdgs_website_nonce'] = 'valid';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $_POST );
		parent::tearDown();
	}

	private function make_post_type(
		DomainRegistry $domain_registry = null,
		VariableParser $variable_parser = null,
		GlobalStylesPostService $global_styles_post_service = null
	): WebsitePostType {
		return new WebsitePostType(
			$domain_registry ?? Mockery::mock( DomainRegistry::class ),
			$variable_parser ?? Mockery::mock( VariableParser::class ),
			$global_styles_post_service ?? Mockery::mock( GlobalStylesPostService::class )
		);
	}

	public function test_save_skips_when_nonce_missing(): void {
		unset( $_POST['mdgs_website_nonce'] );

		$domain_registry = Mockery::mock( DomainRegistry::class );
		$domain_registry->shouldNotReceive( 'parse_domains_input' );

		$post_type = $this->make_post_type( $domain_registry );

		$post_type->save( 5 );

		$this->assertTrue( true ); // No exception, no calls made — assertions are the shouldNotReceive expectations above.
	}

	public function test_save_accepts_domains_without_conflicts(): void {
		$_POST['mdgs_domains']   = "example.com\nexample.org";
		$_POST['mdgs_variables'] = '';

		$domain_registry = Mockery::mock( DomainRegistry::class );
		$domain_registry->shouldReceive( 'parse_domains_input' )
			->with( "example.com\nexample.org" )
			->andReturn( array( 'example.com', 'example.org' ) );
		$domain_registry->shouldReceive( 'find_conflicting_website' )->with( 'example.com', 5 )->andReturn( null );
		$domain_registry->shouldReceive( 'find_conflicting_website' )->with( 'example.org', 5 )->andReturn( null );
		$domain_registry->shouldReceive( 'invalidate_cache' )->once();

		$variable_parser = Mockery::mock( VariableParser::class );
		$variable_parser->shouldReceive( 'parse' )->with( '' )->andReturn( array() );

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'ensure_global_styles_post' )->once()->with( 5 )->andReturn( 42 );

		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_domains', array( 'example.com', 'example.org' ) )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_variables', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_is_default', '' )->once();

		$post_type = $this->make_post_type( $domain_registry, $variable_parser, $global_styles_post_service );

		$post_type->save( 5 );
	}

	public function test_save_drops_conflicting_domain_and_records_rejection(): void {
		$_POST['mdgs_domains']   = "example.com\ntaken.com";
		$_POST['mdgs_variables'] = '';

		$domain_registry = Mockery::mock( DomainRegistry::class );
		$domain_registry->shouldReceive( 'parse_domains_input' )->andReturn( array( 'example.com', 'taken.com' ) );
		$domain_registry->shouldReceive( 'find_conflicting_website' )->with( 'example.com', 5 )->andReturn( null );
		$domain_registry->shouldReceive( 'find_conflicting_website' )->with( 'taken.com', 5 )->andReturn( 9 );
		$domain_registry->shouldReceive( 'invalidate_cache' )->once();

		$variable_parser = Mockery::mock( VariableParser::class );
		$variable_parser->shouldReceive( 'parse' )->andReturn( array() );

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'ensure_global_styles_post' )->andReturn( 42 );

		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_domains', array( 'example.com' ) )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_variables', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_is_default', '' )->once();
		Functions\expect( 'set_transient' )
			->once()
			->with( 'mdgs_domain_conflict_1', array( 'taken.com' ), 30 );

		$post_type = $this->make_post_type( $domain_registry, $variable_parser, $global_styles_post_service );

		$post_type->save( 5 );
	}

	public function test_save_stores_parsed_variables(): void {
		$_POST['mdgs_domains']   = '';
		$_POST['mdgs_variables'] = 'name = Acme';

		$domain_registry = Mockery::mock( DomainRegistry::class );
		$domain_registry->shouldReceive( 'parse_domains_input' )->andReturn( array() );
		$domain_registry->shouldReceive( 'invalidate_cache' )->once();

		$variable_parser = Mockery::mock( VariableParser::class );
		$variable_parser->shouldReceive( 'parse' )->with( 'name = Acme' )->andReturn( array( 'name' => 'Acme' ) );

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'ensure_global_styles_post' )->andReturn( 42 );

		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_domains', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_variables', array( 'name' => 'Acme' ) )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_is_default', '' )->once();

		$post_type = $this->make_post_type( $domain_registry, $variable_parser, $global_styles_post_service );

		$post_type->save( 5 );
	}

	public function test_save_clears_other_defaults_when_marked_default(): void {
		$_POST['mdgs_domains']    = '';
		$_POST['mdgs_variables']  = '';
		$_POST['mdgs_is_default'] = '1';

		$domain_registry = Mockery::mock( DomainRegistry::class );
		$domain_registry->shouldReceive( 'parse_domains_input' )->andReturn( array() );
		$domain_registry->shouldReceive( 'invalidate_cache' )->once();

		$variable_parser = Mockery::mock( VariableParser::class );
		$variable_parser->shouldReceive( 'parse' )->andReturn( array() );

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'ensure_global_styles_post' )->andReturn( 42 );

		Functions\expect( 'get_posts' )
			->once()
			->with(
				array(
					'post_type'      => 'mdgs_website',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'post__not_in'   => array( 5 ),
					'meta_key'       => '_mdgs_is_default',
					'meta_value'     => '1',
				)
			)
			->andReturn( array( 7 ) );
		Functions\expect( 'delete_post_meta' )->once()->with( 7, '_mdgs_is_default' );

		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_domains', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_variables', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_is_default', '1' )->once();

		$post_type = $this->make_post_type( $domain_registry, $variable_parser, $global_styles_post_service );

		$post_type->save( 5 );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter WebsitePostTypeTest`
Expected: FAIL with "Class ... WebsitePostType not found".

- [ ] **Step 3: Write implementation**

```php
<?php
/**
 * Website Post Type
 *
 * @package The_Another_Multi_Domain_Global_Styles
 * @since 1.0.0
 */

namespace The_Another\Plugin\Multi_Domain_Global_Styles\Website;

use The_Another\Plugin\Multi_Domain_Global_Styles\GlobalStyles\GlobalStylesPostService;
use The_Another\Plugin\Multi_Domain_Global_Styles\ContentVariables\VariableParser;
use WP_Post;

/**
 * Class WebsitePostType
 *
 * Registers the `mdgs_website` CPT: domains, content variables, default
 * flag, and an interim raw-JSON global styles editor (Task 10 of the
 * foundation plan; replaced by a Site Editor redirect in the follow-up plan).
 */
class WebsitePostType {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'mdgs_website';

	/**
	 * Domain registry.
	 *
	 * @var DomainRegistry
	 */
	private DomainRegistry $domain_registry;

	/**
	 * Variable parser.
	 *
	 * @var VariableParser
	 */
	private VariableParser $variable_parser;

	/**
	 * Global styles post service.
	 *
	 * @var GlobalStylesPostService
	 */
	private GlobalStylesPostService $global_styles_post_service;

	/**
	 * Constructor.
	 *
	 * @param DomainRegistry            $domain_registry             Domain registry service.
	 * @param VariableParser            $variable_parser             Variable parser service.
	 * @param GlobalStylesPostService $global_styles_post_service  Global styles post service.
	 */
	public function __construct(
		DomainRegistry $domain_registry,
		VariableParser $variable_parser,
		GlobalStylesPostService $global_styles_post_service
	) {
		$this->domain_registry            = $domain_registry;
		$this->variable_parser            = $variable_parser;
		$this->global_styles_post_service = $global_styles_post_service;
	}

	/**
	 * Register the mdgs_website post type. Hooked to `init`.
	 *
	 * @return void
	 */
	public function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Websites', 'the-another-multi-domain-global-styles' ),
					'singular_name' => __( 'Website', 'the-another-multi-domain-global-styles' ),
					'add_new_item'  => __( 'Add New Website', 'the-another-multi-domain-global-styles' ),
					'edit_item'     => __( 'Edit Website', 'the-another-multi-domain-global-styles' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'menu_icon'    => 'dashicons-admin-multisite',
			)
		);
	}

	/**
	 * Register meta boxes. Hooked to `add_meta_boxes`.
	 *
	 * @return void
	 */
	public function register_meta_boxes(): void {
		add_meta_box( 'mdgs_domains', __( 'Domains', 'the-another-multi-domain-global-styles' ), array( $this, 'render_domains_meta_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'mdgs_variables', __( 'Content Variables', 'the-another-multi-domain-global-styles' ), array( $this, 'render_variables_meta_box' ), self::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'mdgs_default', __( 'Default Website', 'the-another-multi-domain-global-styles' ), array( $this, 'render_default_meta_box' ), self::POST_TYPE, 'side', 'default' );
		add_meta_box( 'mdgs_styles', __( 'Global Styles (raw JSON)', 'the-another-multi-domain-global-styles' ), array( $this, 'render_styles_meta_box' ), self::POST_TYPE, 'normal', 'default' );
	}

	/**
	 * Render the domains meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_domains_meta_box( WP_Post $post ): void {
		$domains = get_post_meta( $post->ID, '_mdgs_domains', true );
		$domains = is_array( $domains ) ? $domains : array();

		wp_nonce_field( 'mdgs_save_website', 'mdgs_website_nonce' );
		?>
		<p><?php esc_html_e( 'One hostname per line, e.g. example.com', 'the-another-multi-domain-global-styles' ); ?></p>
		<textarea name="mdgs_domains" rows="5" style="width:100%;"><?php echo esc_textarea( implode( "\n", $domains ) ); ?></textarea>
		<?php
	}

	/**
	 * Render the content variables meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_variables_meta_box( WP_Post $post ): void {
		$variables = get_post_meta( $post->ID, '_mdgs_variables', true );
		$variables = is_array( $variables ) ? $variables : array();

		$lines = array();
		foreach ( $variables as $key => $value ) {
			$lines[] = "{$key} = {$value}";
		}
		?>
		<p><?php esc_html_e( 'One variable per line, e.g. name = Acme Auctions. Reference in content as %%website.name%%.', 'the-another-multi-domain-global-styles' ); ?></p>
		<textarea name="mdgs_variables" rows="5" style="width:100%;"><?php echo esc_textarea( implode( "\n", $lines ) ); ?></textarea>
		<?php
	}

	/**
	 * Render the default-website meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_default_meta_box( WP_Post $post ): void {
		$is_default = get_post_meta( $post->ID, '_mdgs_is_default', true );
		?>
		<label>
			<input type="checkbox" name="mdgs_is_default" value="1" <?php checked( $is_default, '1' ); ?> />
			<?php esc_html_e( 'Use as fallback for unmatched domains', 'the-another-multi-domain-global-styles' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the raw-JSON global styles meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_styles_meta_box( WP_Post $post ): void {
		$global_styles_post_id = get_post_meta( $post->ID, '_mdgs_global_styles_post_id', true );
		$data                  = $global_styles_post_id ? $this->global_styles_post_service->get_global_styles_data( (int) $global_styles_post_id ) : array();
		?>
		<p><?php esc_html_e( 'Raw theme.json-shaped settings/styles for this Website. A richer visual editor is planned; this is the interim editing UI.', 'the-another-multi-domain-global-styles' ); ?></p>
		<textarea name="mdgs_styles_json" rows="12" style="width:100%;font-family:monospace;"><?php echo esc_textarea( wp_json_encode( $data, JSON_PRETTY_PRINT ) ); ?></textarea>
		<?php
	}

	/**
	 * Save handler. Hooked to `save_post_mdgs_website`.
	 *
	 * @param int $post_id Post ID being saved.
	 * @return void
	 */
	public function save( int $post_id ): void {
		if ( ! isset( $_POST['mdgs_website_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mdgs_website_nonce'] ) ), 'mdgs_save_website' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$this->save_domains( $post_id );
		$this->save_variables( $post_id );
		$this->save_default_flag( $post_id );
		$this->save_styles( $post_id );

		$this->domain_registry->invalidate_cache();
		$this->global_styles_post_service->ensure_global_styles_post( $post_id );
	}

	/**
	 * Parse, validate, and persist the domains field.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_domains( int $post_id ): void {
		$raw     = isset( $_POST['mdgs_domains'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mdgs_domains'] ) ) : '';
		$domains = $this->domain_registry->parse_domains_input( $raw );

		$accepted = array();
		$rejected = array();

		foreach ( $domains as $domain ) {
			if ( null !== $this->domain_registry->find_conflicting_website( $domain, $post_id ) ) {
				$rejected[] = $domain;
				continue;
			}
			$accepted[] = $domain;
		}

		if ( ! empty( $rejected ) ) {
			set_transient( 'mdgs_domain_conflict_' . get_current_user_id(), $rejected, 30 );
		}

		update_post_meta( $post_id, '_mdgs_domains', $accepted );
	}

	/**
	 * Parse and persist the content variables field.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_variables( int $post_id ): void {
		$raw = isset( $_POST['mdgs_variables'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mdgs_variables'] ) ) : '';

		update_post_meta( $post_id, '_mdgs_variables', $this->variable_parser->parse( $raw ) );
	}

	/**
	 * Persist the default-website flag, clearing it from any other Website.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_default_flag( int $post_id ): void {
		$is_default = ! empty( $_POST['mdgs_is_default'] ) ? '1' : '';

		if ( '1' === $is_default ) {
			$others = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'post__not_in'   => array( $post_id ),
					'meta_key'       => '_mdgs_is_default',
					'meta_value'     => '1',
				)
			);
			foreach ( $others as $other_id ) {
				delete_post_meta( $other_id, '_mdgs_is_default' );
			}
		}

		update_post_meta( $post_id, '_mdgs_is_default', $is_default );
	}

	/**
	 * Parse and persist the raw-JSON styles field into the linked wp_global_styles post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_styles( int $post_id ): void {
		$raw = isset( $_POST['mdgs_styles_json'] ) ? wp_unslash( $_POST['mdgs_styles_json'] ) : '';

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return;
		}

		$global_styles_post_id = $this->global_styles_post_service->ensure_global_styles_post( $post_id );

		wp_update_post(
			array(
				'ID'           => $global_styles_post_id,
				'post_content' => wp_json_encode(
					array(
						'version'                      => 3,
						'isGlobalStylesUserThemeJSON'  => true,
						'settings'                      => $decoded['settings'] ?? new \stdClass(),
						'styles'                        => $decoded['styles'] ?? new \stdClass(),
					)
				),
			)
		);
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter WebsitePostTypeTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/Website/WebsitePostType.php tests/Website/WebsitePostTypeTest.php
git commit -m "feat: add WebsitePostType with domains, variables, default flag, and raw-JSON styles editor"
```

---

### Task 11: AdminNotices — duplicate domain rejection

**Files:**
- Create: `includes/Website/AdminNotices.php`
- Test: `tests/Website/AdminNoticesTest.php`

**Interfaces:**
- Consumes: `get_current_user_id()`, `get_transient()`, `delete_transient()` (WP core)
- Produces: `AdminNotices::render(): void` (hooked to `admin_notices`)

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace The_Another\Plugin\Multi_Domain_Global_Styles\Tests\Website;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\AdminNotices;

#[CoversClass( AdminNotices::class )]
class AdminNoticesTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_render_outputs_nothing_when_no_rejection_recorded(): void {
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'get_transient' )->once()->with( 'mdgs_domain_conflict_1' )->andReturn( false );

		$notices = new AdminNotices();

		ob_start();
		$notices->render();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_render_outputs_notice_and_clears_transient_when_rejection_recorded(): void {
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'get_transient' )->once()->with( 'mdgs_domain_conflict_1' )->andReturn( array( 'taken.com' ) );
		Functions\expect( 'delete_transient' )->once()->with( 'mdgs_domain_conflict_1' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$notices = new AdminNotices();

		ob_start();
		$notices->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'taken.com', $output );
		$this->assertStringContainsString( 'notice-warning', $output );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter AdminNoticesTest`
Expected: FAIL with "Class ... AdminNotices not found".

- [ ] **Step 3: Write implementation**

```php
<?php
/**
 * Admin Notices
 *
 * @package The_Another_Multi_Domain_Global_Styles
 * @since 1.0.0
 */

namespace The_Another\Plugin\Multi_Domain_Global_Styles\Website;

/**
 * Class AdminNotices
 *
 * Surfaces the domain-conflict rejection recorded by WebsitePostType::save().
 */
class AdminNotices {

	/**
	 * Render pending admin notices. Hooked to `admin_notices`.
	 *
	 * @return void
	 */
	public function render(): void {
		$user_id       = get_current_user_id();
		$transient_key = 'mdgs_domain_conflict_' . $user_id;
		$rejected      = get_transient( $transient_key );

		if ( empty( $rejected ) ) {
			return;
		}

		delete_transient( $transient_key );

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of rejected domains */
					__( 'The following domains were not saved because they are already assigned to another Website: %s', 'the-another-multi-domain-global-styles' ),
					implode( ', ', (array) $rejected )
				)
			)
		);
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter AdminNoticesTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/Website/AdminNotices.php tests/Website/AdminNoticesTest.php
git commit -m "feat: add AdminNotices for domain conflict rejection"
```

---

### Task 12: Wire everything together in Plugin::start()

**Files:**
- Modify: `includes/Plugin.php`

**Interfaces:**
- Consumes: every service produced in Tasks 2–11
- Produces: a fully wired `Plugin::start()` — no new public interface for later tasks to consume (this is the top of the dependency graph)

- [ ] **Step 1: Replace includes/Plugin.php with the fully wired version**

```php
<?php
/**
 * Plugin Orchestrator Class
 *
 * @package The_Another_Multi_Domain_Global_Styles
 * @since 1.0.0
 */

namespace The_Another\Plugin\Multi_Domain_Global_Styles;

use The_Another\Plugin\Multi_Domain_Global_Styles\Website\AdminNotices;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\WebsitePostType;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\DomainRegistry;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\DomainResolver;
use The_Another\Plugin\Multi_Domain_Global_Styles\GlobalStyles\GlobalStylesOverride;
use The_Another\Plugin\Multi_Domain_Global_Styles\GlobalStyles\GlobalStylesPostService;
use The_Another\Plugin\Multi_Domain_Global_Styles\ContentVariables\VariableParser;
use The_Another\Plugin\Multi_Domain_Global_Styles\ContentVariables\VariableSubstitutionService;
use The_Another\Plugin\Multi_Domain_Global_Styles\Website\WebsiteRepository;

/**
 * Class Plugin
 *
 * Registers services and wires hooks.
 */
class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Container instance.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->container = Container::get_instance();
	}

	/**
	 * Get the plugin instance.
	 *
	 * @return Plugin Plugin instance.
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Start the plugin: register services and hooks.
	 *
	 * @return void
	 */
	public function start(): void {
		$this->register_services();

		$hooks = $this->container->get_hook_manager();

		$website_post_type = $this->container->get( 'website_post_type' );
		$hooks->register_action( 'init', array( $website_post_type, 'register' ) );
		$hooks->register_action( 'add_meta_boxes', array( $website_post_type, 'register_meta_boxes' ) );
		$hooks->register_action( 'save_post_' . WebsitePostType::POST_TYPE, array( $website_post_type, 'save' ) );

		// The domain-map transient never expires, and the nonce-gated save()
		// handler above doesn't run on trash/untrash/delete — invalidate
		// unconditionally on any Website status change so the map can't go
		// stale when a Website is trashed or permanently deleted.
		$domain_registry = $this->container->get( 'domain_registry' );
		$hooks->register_action( 'save_post_' . WebsitePostType::POST_TYPE, array( $domain_registry, 'invalidate_cache' ) );
		$hooks->register_action(
			'deleted_post',
			function ( $post_id, $post ) use ( $domain_registry ) {
				if ( $post && WebsitePostType::POST_TYPE === $post->post_type ) {
					$domain_registry->invalidate_cache();
				}
			},
			10,
			2
		);

		$global_styles_override = $this->container->get( 'global_styles_override' );
		$hooks->register_filter( 'wp_theme_json_data_user', array( $global_styles_override, 'filter_theme_json' ) );

		$variable_substitution_service = $this->container->get( 'variable_substitution_service' );
		$hooks->register_action( 'template_redirect', array( $variable_substitution_service, 'start_buffer' ) );

		$admin_notices = $this->container->get( 'admin_notices' );
		$hooks->register_action( 'admin_notices', array( $admin_notices, 'render' ) );
	}

	/**
	 * Register all services in the container.
	 *
	 * @return void
	 */
	private function register_services(): void {
		$this->container->register( 'domain_registry', fn() => new DomainRegistry() );
		$this->container->register( 'variable_parser', fn() => new VariableParser() );
		$this->container->register( 'website_repository', fn() => new WebsiteRepository() );
		$this->container->register( 'global_styles_post_service', fn() => new GlobalStylesPostService() );

		$this->container->register(
			'domain_resolver',
			fn( Container $c ) => new DomainResolver( $c->get( 'domain_registry' ), $c->get( 'website_repository' ) )
		);

		$this->container->register(
			'global_styles_override',
			fn( Container $c ) => new GlobalStylesOverride(
				$c->get( 'domain_resolver' ),
				$c->get( 'website_repository' ),
				$c->get( 'global_styles_post_service' )
			)
		);

		$this->container->register(
			'variable_substitution_service',
			fn( Container $c ) => new VariableSubstitutionService( $c->get( 'domain_resolver' ), $c->get( 'website_repository' ) )
		);

		$this->container->register(
			'website_post_type',
			fn( Container $c ) => new WebsitePostType(
				$c->get( 'domain_registry' ),
				$c->get( 'variable_parser' ),
				$c->get( 'global_styles_post_service' )
			)
		);

		$this->container->register( 'admin_notices', fn() => new AdminNotices() );
	}
}
```

- [ ] **Step 2: Run the full test suite**

Run: `composer test`
Expected: All tests across every file PASS. `ContainerTest`'s `Plugin`-instantiation paths aren't directly exercised here since `Plugin::start()` isn't unit tested (it's pure wiring — verified by manual smoke check in Task 13, matching how `aucteeno-nexus`'s own bootstrap wiring isn't unit tested either).

- [ ] **Step 3: Run phpcs across the whole plugin**

Run: `composer phpcs`
Expected: No errors.

- [ ] **Step 4: Commit**

```bash
git add includes/Plugin.php
git commit -m "feat: wire all services and hooks in Plugin::start()"
```

---

### Task 13: Manual smoke verification

**Files:** none (verification only)

This plugin's core value — domain resolution, style overrides, variable substitution — depends on real WordPress request handling (`$_SERVER['HTTP_HOST']`, `wp_theme_json_data_user`, output buffering) that Brain Monkey's mocked-function unit tests intentionally don't exercise end-to-end. Before considering the Foundation plan done, verify it manually against a real WordPress install:

- [ ] **Step 1: Install the plugin on a real WordPress 6.9+ site with a block theme active** (e.g. copy into a local install alongside `aucteeno-wp-theme`, or use the existing local dev environment for these plugins). Run `composer install --no-dev` (or full install if you also want dev tooling) inside the plugin directory first.

- [ ] **Step 2: Activate the plugin.** Confirm no fatal errors and a "Websites" menu item appears in wp-admin.

- [ ] **Step 3: Create two Website posts.**
  - Website A: domain `site-a.test` (or whatever local hostname you can point at this install), one variable `name = Site A`, styles JSON `{"settings":{"color":{"palette":[{"slug":"brand-primary","color":"#ff0000","name":"Brand Primary"}]}},"styles":{}}`.
  - Website B: domain `site-b.test`, variable `name = Site B`, styles JSON with `"color":"#0000ff"` for the same palette slug.

- [ ] **Step 4: Add `%%website.name%%` to a page's content** (e.g. the homepage). Visit via both hostnames (edit your local hosts file or equivalent to point both at this install) and confirm each shows its own Website's name, and that the raw theme.json (Appearance → Editor → Styles) on either domain still shows the *theme's own* unmodified defaults — confirming the override is per-request, not a global mutation.

- [ ] **Step 5: Confirm palette override.** Add a block using the "Brand Primary" palette color on the page, and confirm it renders red on `site-a.test` and blue on `site-b.test` from the *same* saved content.

- [ ] **Step 6: Confirm duplicate-domain rejection.** Try adding `site-a.test` to Website B and save; confirm the admin notice appears and the domain is not actually reassigned (re-check Website A still owns it).

- [ ] **Step 7: Confirm the unmatched-domain fallback.** Visit the install via a third hostname not registered to any Website (or via its default local hostname). Confirm the designated default Website's styles/variables apply if one is flagged, or theme defaults + literal `%%website.name%%` text if none is.

- [ ] **Step 8: Record results.** Note any discrepancies from expected behavior as follow-up tasks — do not silently patch and re-verify without documenting what broke.

---

## Self-Review Notes

- **Spec coverage:** Domain management (Tasks 2–3, 10), Website entity bundling domains+styles+variables (Task 10), global styles override without touching theme files (Task 8), variable substitution across content/blocks/widgets/menus via whole-page buffer (Task 9), default-website fallback (Tasks 4–5, 10), duplicate-domain rejection (Tasks 3, 10–11), container-based DI house style (Task 1), esc_html-escaped plain-text variables (Task 9). The one spec item NOT covered here — full native-Site-Editor-parity style editing — is explicitly deferred to the follow-up plan per "Relationship to Plan 2" above; Task 10 ships the interim raw-JSON editor instead.
- **Placeholder scan:** No TBD/TODO markers; every step has complete, runnable code.
- **Type consistency:** `DomainResolver::resolve_host(string): ?int` / `resolve_current_request(): ?int` used identically in Tasks 5, 8, 9. `WebsiteRepository::get_global_styles_post_id(int): ?int`, `get_variables(int): array`, `get_default_website_id(): ?int` used identically wherever referenced. `GlobalStylesPostService::ensure_global_styles_post(int): int` / `get_global_styles_data(int): array` used identically in Tasks 8, 10, 12. Postmeta keys (`_mdgs_domains`, `_mdgs_variables`, `_mdgs_is_default`, `_mdgs_global_styles_post_id`) and the transient key `mdgs_domain_map` are spelled identically everywhere they appear.
- **Domain-driven reorg consistency:** every `namespace`/`use` statement and file path in Tasks 1–12 was cross-checked against the "File Structure" bounded contexts (`Website`, `GlobalStyles`, `ContentVariables`) — no file references its old `Services`/`Post_Types`/`Admin` technical-layer location, and the one same-namespace `use` statement made redundant by the move (`DomainRegistry` inside `WebsitePostType`, both now in `Website`) was removed.
- **Post-rename verification pass (test-executability audit):** three latent defects found and fixed — (1) `GlobalStylesPostServiceTest` used `Mockery::on()` without importing `use Mockery;`; (2) `WebsitePostTypeTest` didn't stub `sanitize_text_field`, which `save()` calls on the nonce before anything else (Brain Monkey auto-defines escaping/translation functions but not `sanitize_*`); (3) the same test file mocked `is_wp_error` via `Functions\expect`, but `tests/bootstrap.php` already defines that function globally and Brain Monkey cannot redefine an existing function — the mocks were dropped in favor of the real stub. Also fixed a cache-lifecycle gap: the domain-map transient never expires and was only invalidated inside the nonce-gated `save()` handler, so Task 12 now registers an unconditional `invalidate_cache` on `save_post_mdgs_website` (fires on trash/untrash too, since those go through `wp_insert_post`) plus a `deleted_post` guard for permanent deletion.
