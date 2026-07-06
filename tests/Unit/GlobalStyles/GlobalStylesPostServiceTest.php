<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\GlobalStyles;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandSettings;
use TheAnother\Plugin\MultiBrandGlobalStyles\GlobalStyles\GlobalStylesPostService;

#[CoversClass( GlobalStylesPostService::class )]
#[UsesClass( BrandSettings::class )]
class GlobalStylesPostServiceTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private GlobalStylesPostService $service;

	/**
	 * @var BrandRepository&Mockery\MockInterface
	 */
	private $brand_repository;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html' )->returnArg();

		$this->brand_repository = Mockery::mock( BrandRepository::class );
		$this->service          = new GlobalStylesPostService( $this->brand_repository );
	}

	private function stub_stored_post_id( ?int $post_id ): void {
		$this->brand_repository->shouldReceive( 'get_settings' )
			->once()
			->with( 5 )
			->andReturn( BrandSettings::from_meta( null === $post_id ? array() : array( 'global_styles_post_id' => $post_id ) ) );
	}

	protected function tearDown(): void {
		\WP_Theme_JSON::$raw_data_override            = null;
		\WP_Theme_JSON::$insecure_properties_override = null;
		\WP_Theme_JSON::$insecure_properties_calls    = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_ensure_global_styles_post_returns_existing_published_post(): void {
		$this->stub_stored_post_id( 42 );
		Functions\expect( 'get_post_status' )->once()->with( 42 )->andReturn( 'publish' );
		Functions\expect( 'wp_insert_post' )->never();
		$this->brand_repository->shouldNotReceive( 'update_settings' );

		$this->assertSame( 42, $this->service->ensure_global_styles_post( 5 ) );
	}

	public function test_ensure_global_styles_post_creates_when_missing(): void {
		$this->stub_stored_post_id( null );
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
		$this->brand_repository->shouldReceive( 'update_settings' )
			->once()
			->with( 5, array( 'global_styles_post_id' => 99 ) );

		$this->assertSame( 99, $this->service->ensure_global_styles_post( 5 ) );
	}

	public function test_ensure_global_styles_post_recreates_when_existing_post_gone(): void {
		$this->stub_stored_post_id( 42 );
		Functions\expect( 'get_post_status' )->once()->with( 42 )->andReturn( false );
		Functions\expect( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( 100 );
		$this->brand_repository->shouldReceive( 'update_settings' )
			->once()
			->with( 5, array( 'global_styles_post_id' => 100 ) );

		$this->assertSame( 100, $this->service->ensure_global_styles_post( 5 ) );
	}

	public function test_ensure_global_styles_post_recreates_when_existing_post_trashed(): void {
		$this->stub_stored_post_id( 42 );
		Functions\expect( 'get_post_status' )->once()->with( 42 )->andReturn( 'trash' );
		Functions\expect( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( 101 );
		$this->brand_repository->shouldReceive( 'update_settings' )
			->once()
			->with( 5, array( 'global_styles_post_id' => 101 ) );

		$this->assertSame( 101, $this->service->ensure_global_styles_post( 5 ) );
	}

	public function test_ensure_global_styles_post_throws_when_insert_fails(): void {
		$this->stub_stored_post_id( null );
		Functions\expect( 'wp_json_encode' )->andReturnUsing( 'json_encode' );

		$error = new \WP_Error( 'db_insert_error', 'Could not insert post into the database.' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( $error );
		$this->brand_repository->shouldNotReceive( 'update_settings' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Could not insert post into the database.' );

		$this->service->ensure_global_styles_post( 5 );
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

	public function test_update_global_styles_routes_content_through_theme_json(): void {
		// Simulate core's WP_Theme_JSON normalization: a flat preset list is
		// returned in its origin-keyed form (the shape that survives core's
		// wp_filter_global_styles_post kses filter). The stub lets us assert the
		// service writes whatever WP_Theme_JSON hands back, not the raw input.
		\WP_Theme_JSON::$raw_data_override = array(
			'version'  => 3,
			'settings' => array( 'color' => array( 'palette' => array( 'custom' => array( array( 'slug' => 'accent-1', 'color' => '#1E40AF', 'name' => 'Accent 1' ) ) ) ) ),
			'styles'   => array( 'color' => array( 'background' => '#eeffee' ) ),
		);

		Functions\expect( 'wp_slash' )->once()->andReturnUsing( fn( $v ) => $v );
		Functions\expect( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		// No global-styles filter registered for this user (has unfiltered_html):
		// nothing to suspend or restore.
		Functions\expect( 'has_filter' )->once()->with( 'content_save_pre', 'wp_filter_global_styles_post' )->andReturn( false );
		Functions\expect( 'remove_filter' )->never();
		Functions\expect( 'add_filter' )->never();

		Functions\expect( 'wp_update_post' )
			->once()
			->with(
				Mockery::on(
					function ( $args ) {
						return 42 === $args['ID']
							&& is_string( $args['post_content'] )
							&& str_contains( $args['post_content'], '"isGlobalStylesUserThemeJSON":true' )
							// The origin-keyed palette from WP_Theme_JSON is what gets persisted.
							&& str_contains( $args['post_content'], '"palette":{"custom":[' )
							&& str_contains( $args['post_content'], '"background":"#eeffee"' );
					}
				)
			);

		$this->service->update_global_styles(
			42,
			array( 'settings' => array( 'color' => array( 'palette' => array( array( 'slug' => 'accent-1', 'color' => '#1E40AF', 'name' => 'Accent 1' ) ) ) ) )
		);

		// The persisted payload is routed through core's value-safety pass.
		$this->assertCount( 1, \WP_Theme_JSON::$insecure_properties_calls );
		$this->assertSame( 'custom', \WP_Theme_JSON::$insecure_properties_calls[0][1] );
	}

	public function test_update_global_styles_defaults_missing_subtrees_to_empty_objects(): void {
		// WP_Theme_JSON drops empty settings/styles from get_raw_data(); the
		// service must still persist them as empty objects so the stored post
		// keeps the canonical {version, isGlobalStylesUserThemeJSON, settings,
		// styles} shape.
		\WP_Theme_JSON::$raw_data_override = array( 'version' => 3 );

		Functions\expect( 'wp_slash' )->once()->andReturnUsing( fn( $v ) => $v );
		Functions\expect( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		Functions\expect( 'has_filter' )->once()->with( 'content_save_pre', 'wp_filter_global_styles_post' )->andReturn( false );
		Functions\expect( 'remove_filter' )->never();
		Functions\expect( 'add_filter' )->never();

		Functions\expect( 'wp_update_post' )
			->once()
			->with(
				Mockery::on(
					function ( $args ) {
						return 42 === $args['ID']
							&& str_contains( $args['post_content'], '"settings":{}' )
							&& str_contains( $args['post_content'], '"styles":{}' );
					}
				)
			);

		$this->service->update_global_styles( 42, array() );
	}

	public function test_update_global_styles_preserves_custom_css_stripped_by_core_kses(): void {
		// Core's wp_filter_global_styles_post is registered (user lacks
		// edit_css) and drops the top-level custom CSS. remove_insecure_properties
		// returns the value-safe payload WITHOUT the css; the service must
		// re-attach the Brand's own custom CSS and suspend the filter for the
		// write so it survives.
		\WP_Theme_JSON::$raw_data_override = array(
			'version'  => 3,
			'settings' => array(),
			'styles'   => array(
				'color' => array( 'background' => '#eeffee' ),
				'css'   => '.globalag{color:red} </style><script>x</script>',
			),
		);
		// Simulate core stripping the css (no edit_css) but keeping the rest.
		\WP_Theme_JSON::$insecure_properties_override = array(
			'version'  => 3,
			'settings' => array(),
			'styles'   => array( 'color' => array( 'background' => '#eeffee' ) ),
		);

		Functions\expect( 'wp_slash' )->once()->andReturnUsing( fn( $v ) => $v );
		Functions\expect( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		// Filter WAS registered at core's priority 9 → suspended at that exact
		// priority for the write, then restored.
		Functions\expect( 'has_filter' )->once()->with( 'content_save_pre', 'wp_filter_global_styles_post' )->andReturn( 9 );
		Functions\expect( 'remove_filter' )->once()->with( 'content_save_pre', 'wp_filter_global_styles_post', 9 );
		Functions\expect( 'add_filter' )->once()->with( 'content_save_pre', 'wp_filter_global_styles_post', 9 );

		Functions\expect( 'wp_update_post' )
			->once()
			->with(
				Mockery::on(
					function ( $args ) {
						$decoded = json_decode( $args['post_content'], true );
						$css     = $decoded['styles']['css'] ?? '';

						return 42 === $args['ID']
							// The Brand's custom CSS is re-attached...
							&& str_contains( $css, '.globalag{color:red}' )
							// ...with the </style> breakout neutralized...
							&& ! str_contains( $css, '</style' )
							&& str_contains( $css, '<\\/style' )
							// ...and the value-safe rest preserved.
							&& ( $decoded['styles']['color']['background'] ?? '' ) === '#eeffee';
					}
				)
			);

		$this->service->update_global_styles(
			42,
			array( 'styles' => array( 'css' => '.globalag{color:red} </style><script>x</script>' ) )
		);
	}
}
