<?php


	namespace Tests\wpunit\Kernels;

	use Closure;
	use Codeception\TestCase\WPTestCase;
	use Exception;
	use GuzzleHttp\Psr7;
	use Mockery;
	use Psr\Http\Message\ResponseInterface;
	use SniccoAdapter\BaseContainerAdapter;
	use WPEmerge\Application\Application;
	use WPEmerge\Application\GenericFactory;
	use WPEmerge\Contracts\ErrorHandlerInterface;
	use WPEmerge\Helpers\Handler;
	use WPEmerge\Helpers\HandlerFactory;
	use WPEmerge\Kernels\HttpKernel;
	use WPEmerge\Contracts\RequestInterface;
	use WPEmerge\Responses\ResponseService;
	use WPEmerge\Contracts\HasQueryFilterInterface;
	use WPEmerge\Contracts\RouteInterface;
	use WPEmerge\Routing\Router;
	use WPEmerge\Contracts\ViewInterface;
	use WPEmerge\View\ViewService;

	/**
	 * @coversDefaultClass \WPEmerge\Kernels\HttpKernel
	 */
	class HttpKernelTest extends WPTestCase {

		/**
		 * @var BaseContainerAdapter|
		 */
		private $container;
		/**
		 * @var GenericFactory
		 */
		private $factory;
		/**
		 * @var HandlerFactory
		 */
		private $handler_factory;
		/**
		 * @var RequestInterface
		 */
		private $request;
		/**
		 * @var ResponseService
		 */
		private $response_service;
		/**
		 * @var Router
		 */
		private $router;
		/**
		 * @var ViewService
		 */
		private $view_service;
		/**
		 * @var ErrorHandlerInterface
		 */
		private $error_handler;

		/**
		 * @var Handler
		 */
		private $factory_handler;

		/**
		 * @var HttpKernel
		 */
		private $subject;


		public function setUp() : void {


			parent::setUp();

			$this->container        = Mockery::mock( BaseContainerAdapter::class );
			$this->factory          = Mockery::mock( GenericFactory::class )->shouldIgnoreMissing();
			$this->handler_factory  = Mockery::mock( HandlerFactory::class )->shouldIgnoreMissing();
			$this->request          = Mockery::mock( RequestInterface::class );


			$this->response_service = Mockery::mock( ResponseService::class )
			                                 ->shouldIgnoreMissing();
			$this->router           = Mockery::mock( Router::class )->shouldIgnoreMissing();
			$this->view_service     = Mockery::mock( ViewService::class )->shouldIgnoreMissing();
			$this->error_handler    = Mockery::mock( ErrorHandlerInterface::class )
			                                 ->shouldIgnoreMissing();
			$this->factory_handler  = Mockery::mock( Handler::class );

			$app = Mockery::mock( Application::class );
			$this->container->shouldReceive( 'offsetGet' )
			                ->with( WPEMERGE_APPLICATION_KEY )
			                ->andReturn( $app );

			$this->container->shouldReceive( 'getCallable' )->andReturn( null );

			$app->shouldReceive( 'renderConfigExceptions' )
			    ->andReturnUsing( function ( $action ) {

				    return $action();
			    } );

			$this->handler_factory->shouldReceive( 'make' )
			                      ->andReturn( $this->factory_handler );

			$this->subject = new HttpKernel(

				$this->container,
				$this->factory,
				$this->handler_factory,
				$this->response_service,
				$this->request,
				$this->router,
				$this->view_service,
				$this->error_handler

			);
		}

		public function tearDown() : void {

			parent::tearDown();
			Mockery::close();

			unset( $this->container );
			unset( $this->factory );
			unset( $this->handler_factory );
			unset( $this->request );
			unset( $this->response_service );
			unset( $this->router );
			unset( $this->view_service );
			unset( $this->error_handler );
			unset( $this->factory_handler );
			unset( $this->subject );
		}

		/**
		 * @covers ::executeHandler
		 */
		public function testExecuteHandler_ValidResponse_Response() {

			$expected = Mockery::mock( ResponseInterface::class );
			$closure  = function () use ( $expected ) {

				return $expected;

			};

			$this->request->shouldReceive('getRouteParameters')->andReturn([]);

			$this->factory_handler->shouldReceive( 'controllerMiddleware' )->once()
			                      ->andReturn( [] );

			$this->factory_handler->shouldReceive( 'execute' )
			                      ->andReturnUsing( $closure );

			$this->assertSame( $expected, $this->subject->run( $this->request, [], $this->factory_handler ) );
		}

		/**
		 * @covers ::executeHandler
		 */
		public function testExecuteHandler_InvalidResponse_Exception() {

			$this->expectExceptionMessage( 'Response returned by controller is not valid' );

			$this->factory_handler->shouldReceive( 'controllerMiddleware' )->once()
			                      ->andReturn( [] );

			$this->request->shouldReceive('getRouteParameters')->andReturn([]);

			$closure = function () {

				return 1;

			};

			$error_handler = Mockery::mock( ErrorHandlerInterface::class )->shouldIgnoreMissing();
			$subject       = new HttpKernel(
				$this->container,
				$this->factory,
				$this->handler_factory,
				$this->response_service,
				$this->request,
				$this->router,
				$this->view_service,
				$error_handler
			);

			$this->factory_handler->shouldReceive( 'execute' )
			                      ->andReturnUsing( $closure );

			$error_handler->shouldReceive( 'getResponse' )
			              ->andReturnUsing( function ( $request, $exception ) {

				              throw $exception;
			              } );

			$subject->run( $this->request, [], $this->factory_handler );
		}

		/**
		 * @covers ::run
		 */
		public function testRun_Middleware_ExecutedInOrder() {

			$closure = function () {

				return ( new Psr7\Response() )->withBody( Psr7\stream_for( 'Handler' ) );
			};

			$this->request->shouldReceive('getRouteParameters')->andReturn([]);

			$this->factory_handler->shouldReceive( 'controllerMiddleware' )->once()
			                      ->andReturn( [] );

			// making the middleware
			$this->factory->shouldReceive( 'make' )
			              ->andReturnUsing( function ( $class ) {

				              return new $class();

			              } );

			$this->factory_handler->shouldReceive( 'execute' )
			                      ->andReturnUsing( $closure );

			$this->subject->setMiddleware( [
				'middleware2' => HttpKernelTestMiddlewareStub2::class,
				'middleware3' => HttpKernelTestMiddlewareStub3::class,
			] );

			$this->subject->setMiddlewareGroups( [
				'global' => [ HttpKernelTestMiddlewareStub1::class ],
			] );

			$this->subject->setMiddlewarePriority( [
				HttpKernelTestMiddlewareStub1::class,
				HttpKernelTestMiddlewareStub2::class,
			] );

			$response = $this->subject->run( $this->request, [
				'middleware3',
				'middleware2',
				'global',
			], $this->factory_handler );

			$this->assertEquals( 'FooBarBazHandler', $response->getBody()->read( 999 ) );
		}

		/**
		 * @covers ::run
		 */
		public function testRun_Exception_UseErrorHandler() {

			$this->expectExceptionMessage( 'Test exception handled' );

			$this->request->shouldReceive('getRouteParameters')->andReturn([]);

			$this->factory_handler->shouldReceive( 'controllerMiddleware' )->once()
			                      ->andReturn( [] );

			$exception = new Exception();
			$closure   = function () use ( $exception ) {

				throw $exception;
			};



			$this->factory_handler->shouldReceive( 'execute' )
			                      ->andReturnUsing( $closure );

			$this->error_handler->shouldReceive( 'getResponse' )
			                    ->with( $this->request, $exception )
			                    ->andReturnUsing( function () {

				                    throw new Exception( 'Test exception handled' );
			                    } );

			$this->subject->run( $this->request, [], $this->factory_handler );
		}

		/**
		 * @covers ::handleRequest
		 */
		public function testHandle_SatisfiedRequest_Response() {

			$request         = Mockery::mock( RequestInterface::class );
			$route           = Mockery::mock( RouteInterface::class );
			$response        = Mockery::mock( ResponseInterface::class );
			$arguments       = [ 'foo', 'bar' ];
			$route_arguments = [ 'baz' ];
			$subject         = Mockery::mock( HttpKernel::class, [
				$this->container,
				$this->factory,
				$this->handler_factory,
				$this->response_service,
				$request,
				$this->router,
				$this->view_service,
				$this->error_handler,
			] )->makePartial();

			$this->container->shouldReceive( 'offsetSet' );

			$this->router->shouldReceive( 'execute' )
			             ->andReturn( $route );

			$request->shouldReceive( 'withAttribute' )
			        ->andReturn( $request );

			$route->shouldReceive( 'getArguments' )
			      ->andReturn( $route_arguments );

			$route->shouldReceive( 'getAttribute' )
			      ->with( 'middleware', [] )
			      ->andReturn( [] );

			$route->shouldReceive( 'getAttribute' )
			      ->with( 'handler' )
			      ->andReturn( $this->factory_handler );

			$subject->shouldReceive( 'run' )
			        ->andReturnUsing( function ( $request, $middleware, $handler ) use ( $response ) {

				        return $response;
			        } );

			$request->shouldReceive('getRouteParameters')->andReturn([]);
			$request->shouldReceive('setRouteParameter')->andReturn($request);

			$this->assertSame( $response, $subject->handleRequest( $request, $arguments ) );
		}

		/**
		 * @covers ::handleRequest
		 */
		public function testHandle_UnsatisfiedRequest_Null() {

			$this->router->shouldReceive( 'execute' )
			             ->andReturn( null );

			$this->assertNull( $this->subject->handleRequest( $this->request, [] ) );
		}

		/**
		 * @covers ::respond
		 */
		public function testRespond_Response_Respond() {

			$response = Mockery::mock( ResponseInterface::class );

			$this->container->shouldReceive( 'offsetExists' )
			                ->with( WPEMERGE_RESPONSE_KEY )
			                ->andReturn( true );

			$this->container->shouldReceive( 'offsetGet' )
			                ->with( WPEMERGE_RESPONSE_KEY )
			                ->andReturn( $response );

			$this->response_service->shouldReceive( 'respond' )
			                       ->with( $response )
			                       ->once();

			$this->subject->respond();

			$this->assertTrue( true );
		}

		/**
		 * @covers ::respond
		 */
		public function testRespond_NoResponse_DoNotRespond() {

			$this->container->shouldReceive( 'offsetExists' )
			                ->with( WPEMERGE_RESPONSE_KEY )
			                ->andReturn( false );

			$this->response_service->shouldNotReceive( 'respond' );

			$this->subject->respond();

			$this->assertTrue( true );
		}

		/**
		 * @covers ::compose
		 */
		public function testCompose() {

			$expected = 'composed view output';
			$view     = Mockery::mock( ViewInterface::class );
			$subject  = Mockery::mock( HttpKernel::class, [
				$this->container,
				$this->factory,
				$this->handler_factory,
				$this->response_service,
				$this->request,
				$this->router,
				$this->view_service,
				$this->error_handler,
			] )->makePartial();

			$this->view_service->shouldReceive( 'make' )
			                   ->andReturn( $view );

			$view->shouldReceive( 'toString' )
			     ->andReturn( $expected );

			ob_start();
			$subject->compose();
			$this->assertEquals( $expected, ob_get_clean() );
		}

		/**
		 * @covers ::filterRequest
		 */
		public function testFilterRequest_NoFilter_Unfiltered() {

			$route1     = Mockery::mock( RouteInterface::class );
			$route2     = Mockery::mock( RouteInterface::class, HasQueryFilterInterface::class );
			$route3     = Mockery::mock( RouteInterface::class, HasQueryFilterInterface::class );
			$route4     = Mockery::mock( RouteInterface::class, HasQueryFilterInterface::class );
			$query_vars = [ 'unfiltered' ];

			$this->router->shouldReceive( 'getRoutes' )
			             ->andReturn( [ $route1, $route2, $route3 ] );

			$route1->shouldReceive( 'isSatisfied' )
			       ->andReturn( false );

			$route1->shouldNotReceive( 'applyQueryFilter' );

			$route2->shouldReceive( 'isSatisfied' )
			       ->andReturn( false );

			$route2->shouldNotReceive( 'applyQueryFilter' );

			$route3->shouldReceive( 'isSatisfied' )
			       ->andReturn( true )
			       ->once();

			$route3->shouldReceive( 'applyQueryFilter' )
			       ->with( $this->request, $query_vars )
			       ->andReturn( $query_vars );

			$route4->shouldNotReceive( 'isSatisfied' );

			$this->assertEquals( [ 'unfiltered' ], $this->subject->filterRequest( $query_vars ) );
		}

		/**
		 * @covers ::filterRequest
		 */
		public function testFilterRequest_Filter_Filtered() {

			$route1     = Mockery::mock( RouteInterface::class );
			$route2     = Mockery::mock( RouteInterface::class, HasQueryFilterInterface::class );
			$route3     = Mockery::mock( RouteInterface::class, HasQueryFilterInterface::class );
			$query_vars = [ 'unfiltered' ];

			$this->router->shouldReceive( 'getRoutes' )
			             ->andReturn( [ $route1, $route2, $route3 ] );

			$route1->shouldReceive( 'isSatisfied' )
			       ->andReturn( false );

			$route1->shouldNotReceive( 'applyQueryFilter' );

			$route2->shouldReceive( 'isSatisfied' )
			       ->andReturn( true );

			$route2->shouldReceive( 'applyQueryFilter' )
			       ->with( $this->request, $query_vars )
			       ->andReturn( [ 'filtered' ] );

			$route3->shouldNotReceive( 'isSatisfied' );

			$this->assertEquals( [ 'filtered' ], $this->subject->filterRequest( $query_vars ) );
		}

		/**
		 * @covers ::filterTemplateInclude
		 */
		public function testFilterTemplateInclude_Response_Override() {

			$response = Mockery::mock( ResponseInterface::class )->shouldIgnoreMissing();
			$subject  = Mockery::mock( HttpKernel::class, [
				$this->container,
				$this->factory,
				$this->handler_factory,
				$this->response_service,
				$this->request,
				$this->router,
				$this->view_service,
				$this->error_handler,
			] )->makePartial();

			$subject->shouldReceive( 'handleRequest' )
			        ->andReturn( $response );

			$this->container->shouldReceive( 'offsetSet' )
			                ->with( WPEMERGE_RESPONSE_KEY, $response );

			$this->assertEquals( WPEMERGE_DIR . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'view.php', $subject->filterTemplateInclude( '' ) );
		}

		/**
		 * @covers ::filterTemplateInclude
		 */
		public function testFilterTemplateInclude_404_ForcesWPQuery404() {

			global $wp_query;

			$response = Mockery::mock( ResponseInterface::class );
			$subject  = Mockery::mock( HttpKernel::class, [
				$this->container,
				$this->factory,
				$this->handler_factory,
				$this->response_service,
				$this->request,
				$this->router,
				$this->view_service,
				$this->error_handler,
			] )->makePartial();

			$response->shouldReceive( 'getStatusCode' )
			         ->andReturn( 404 );

			$subject->shouldReceive( 'handleRequest' )
			        ->andReturn( $response );

			$this->container->shouldReceive( 'offsetSet' )
			                ->with( WPEMERGE_RESPONSE_KEY, $response );

			$this->assertFalse( $wp_query->is_404() );
			$subject->filterTemplateInclude( '' );
			$this->assertTrue( $wp_query->is_404() );
		}

		/**
		 * @covers ::filterTemplateInclude
		 */
		public function testFilterTemplateInclude_NoResponseWithComposers_Compose() {

			$template = 'index.php';
			$composer = function () {
			};
			$subject  = Mockery::mock( HttpKernel::class, [
				$this->container,
				$this->factory,
				$this->handler_factory,
				$this->response_service,
				$this->request,
				$this->router,
				$this->view_service,
				$this->error_handler,
			] )->makePartial();

			$subject->shouldReceive( 'handle' )
			        ->andReturn( null );

			$this->view_service->shouldReceive( 'getComposersForView' )
			                   ->with( $template )
			                   ->andReturn( [ $composer ] );

			$this->assertEquals( WPEMERGE_DIR . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'view.php', $subject->filterTemplateInclude( $template ) );
		}

		/**
		 * @covers ::filterTemplateInclude
		 */
		public function testFilterTemplateInclude_NoResponseNoComposers_Passthrough() {

			$subject = Mockery::mock( HttpKernel::class, [
				$this->container,
				$this->factory,
				$this->handler_factory,
				$this->response_service,
				$this->request,
				$this->router,
				$this->view_service,
				$this->error_handler,
			] )->makePartial();

			$subject->shouldReceive( 'handle' )
			        ->andReturn( null );

			$this->assertEquals( 'foo', $subject->filterTemplateInclude( 'foo' ) );
		}

	}


	class HttpKernelTestMiddlewareStub1 {

		public function handle( RequestInterface $request, Closure $next ) {

			$response = $next( $request );

			return $response->withBody( Psr7\stream_for( 'Foo' . $response->getBody()
			                                                              ->read( 999 ) ) );
		}

	}


	class HttpKernelTestMiddlewareStub2 {

		public function handle( RequestInterface $request, Closure $next ) {

			$response = $next( $request );

			return $response->withBody( Psr7\stream_for( 'Bar' . $response->getBody()
			                                                              ->read( 999 ) ) );
		}

	}


	class HttpKernelTestMiddlewareStub3 {

		public function handle( RequestInterface $request, Closure $next ) {

			$response = $next( $request );

			return $response->withBody( Psr7\stream_for( 'Baz' . $response->getBody()
			                                                              ->read( 999 ) ) );
		}

	}


	class HttpKernelTestMiddlewareStubWithParameters {

		public function handle( RequestInterface $request, Closure $next, $param1, $param2 ) {

			$response = $next( $request );

			return $response->withBody( Psr7\stream_for( $param1 . $param2 . $response->getBody()
			                                                                          ->read( 999 ) ) );
		}

	}
