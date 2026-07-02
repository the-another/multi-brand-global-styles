<?php
/**
 * Plugin Orchestrator Class
 *
 * @package MultiDomainGlobalStyles
 * @since 1.0.0
 */

namespace TheAnother\Plugin\MultiDomainGlobalStyles;

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
