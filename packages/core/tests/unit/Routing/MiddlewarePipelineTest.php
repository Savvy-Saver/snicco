<?php

declare(strict_types=1);

namespace Tests\Core\unit\Routing;

use Throwable;
use Psr\Log\LogLevel;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\Response;
use Tests\Core\RoutingTestCase;
use Snicco\Core\Http\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Snicco\Core\Contracts\ExceptionHandler;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Snicco\Core\Http\Psr7\Response as AppResponse;
use Snicco\Core\Middleware\Internal\MiddlewareFactory;
use Snicco\Core\Middleware\Internal\MiddlewarePipeline;
use Tests\Codeception\shared\helpers\CreatePsr17Factories;
use Snicco\Core\ExceptionHandling\Exceptions\HttpException;

class MiddlewarePipelineTest extends RoutingTestCase
{
    
    private MiddlewarePipeline $pipeline;
    private Request            $request;
    
    protected function setUp() :void
    {
        parent::setUp();
        
        $this->pipeline = new MiddlewarePipeline(
            new MiddlewareFactory($this->container),
            new PipelineTestExceptionHandler(),
        );
        $this->request = new Request(
            $this->psrServerRequestFactory()->createServerRequest('GET', 'https://foobar.com')
        );
    }
    
    /** @test */
    public function middleware_can_be_run()
    {
        $response = $this->pipeline
            ->send($this->request)
            ->through([Foo::class])
            ->then(function (ServerRequestInterface $request) {
                $foo = $request->getAttribute('test');
                $foo .= 'biz';
                
                return $this->response_factory->html($foo);
            });
        
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('foobiz', $response->getBody()->__toString());
    }
    
    /** @test */
    public function middleware_can_be_stacked()
    {
        $response = $this->pipeline
            ->send($this->request)
            ->through([Foo::class, Bar::class])
            ->then(function (ServerRequestInterface $request) {
                $foo = $request->getAttribute('test');
                $foo .= 'biz';
                
                return $this->response_factory->html($foo);
            });
        
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('foobarbiz', $response->getBody()->__toString());
    }
    
    /** @test */
    public function middleware_can_break_out_of_the_middleware_stack()
    {
        $response = $this->pipeline
            ->send($this->request)
            ->through([Foo::class, StopMiddleware::class, Bar::class])
            ->then(function (ServerRequestInterface $request) {
                $this->fail('This should not be called.');
            });
        
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('fooSTOP', $response->getBody()->__toString());
    }
    
    /** @test */
    public function middleware_responses_can_be_manipulated_by_middleware_higher_in_the_stack()
    {
        $response = $this->pipeline
            ->send($this->request)
            ->through([
                ChangeLastMiddleware::class,
                Foo::class,
                StopMiddleware::class,
                Bar::class,
            ])
            ->then(function (ServerRequestInterface $request) {
                $this->fail('This should not be called.');
            });
        
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('CHANGEDfooSTOP', $response->getBody()->__toString());
    }
    
    /** @test */
    public function middleware_can_be_resolved_from_the_container()
    {
        $this->container->instance(
            MiddlewareDependency::class,
            new MiddlewareDependency(new \Tests\Codeception\shared\TestDependencies\Bar())
        );
        
        $response = $this->pipeline
            ->send($this->request)
            ->through([MiddlewareDependency::class])
            ->then(function (ServerRequestInterface $request) {
                $this->fail('This should not be called.');
            });
        
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('BAR', $response->getBody()->__toString());
    }
    
    /** @test */
    public function middleware_can_receive_config_arguments()
    {
        $response = $this->pipeline
            ->send($this->request)
            ->through([[MiddlewareWithConfig::class, false]])
            ->then(function (ServerRequestInterface $request) {
                $this->fail('This should not be called');
            });
        
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(404, $response->getStatusCode());
        
        $response = $this->pipeline
            ->send($this->request)
            ->through([[MiddlewareWithConfig::class, true]])
            ->then(function (ServerRequestInterface $request) {
                return $this->response_factory->make();
            });
        
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }
    
    /** @test */
    public function an_anonymous_closure_can_be_middleware()
    {
        $response = $this->pipeline
            ->send($this->request)
            ->through([
                fn() => new AppResponse(new Response(201)),
            ])
            ->then(function (ServerRequestInterface $request) {
                $foo = $request->getAttribute('test');
                $foo .= 'biz';
                
                return $this->response_factory->html($foo);
            });
        
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(201, $response->getStatusCode());
    }
    
    /** @test */
    public function middleware_that_does_not_implement_the_correct_interface_throws_an_exception()
    {
        $this->expectExceptionMessage('Unsupported middleware type:');
        
        $this->pipeline
            ->send($this->request)
            ->through([WrongMiddleware::class])
            ->then(function () {
                $this->fail('Invalid middleware did not cause an exception');
            });
    }
    
    /** @test */
    public function exceptions_get_handled_on_every_middleware_process_and_dont_break_the_pipeline()
    {
        $response = $this->pipeline
            ->send($this->request)
            ->through([
                    ChangeLastMiddleware::class,
                    ExceptionMiddleware::class,
                    function () {
                        $this->fail('Middleware run after exception.');
                    },
                ]
            )
            ->then(function (ServerRequestInterface $request) {
                $this->fail(
                    'The route driver should have never be called if we have an exception.'
                );
            });
        
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('CHANGED-Error Message', (string) $response->getBody());
    }
    
    /** @test */
    public function the_same_pipeline_cant_be_run_twice_without_providing_a_new_request()
    {
        $response = $this->pipeline->send($this->request)
                                   ->through([])
                                   ->then(function (Request $request) {
                                       return $this->response_factory->html('foo');
                                   });
        
        $this->assertSame('foo', $response->getBody()->__toString());
        
        $this->expectExceptionMessage(
            'You cant run the middleware pipeline twice without providing a new request.'
        );
        
        $this->pipeline->run();
    }
    
    /** @test */
    public function test_exception_when_the_pipeline_is_run_without_sending_a_request()
    {
        $this->expectExceptionMessage(
            'You cant run a middleware pipeline without calling send() first.'
        );
        
        $this->pipeline->run();
    }
    
    /** @test */
    public function middleware_is_replaced_when_using_the_same_pipeline_twice()
    {
        $response = $this->pipeline
            ->send($this->request)
            ->through([Foo::class])
            ->then(function (ServerRequestInterface $request) {
                $foo = $request->getAttribute('test');
                $foo .= 'biz';
                
                return $this->response_factory->html($foo);
            });
        
        $this->assertSame('foobiz', $response->getBody()->__toString());
        
        $response = $this->pipeline
            ->send($this->request->withHeader('X-Test', 'foo'))
            ->through([Bar::class])
            ->then(function (ServerRequestInterface $request) {
                $foo = $request->getAttribute('test');
                $foo .= 'biz';
                
                return $this->response_factory->html($foo);
            });
        
        $this->assertSame('barbiz', $response->getBody()->__toString());
    }
    
}

class PipelineTestExceptionHandler implements ExceptionHandler
{
    
    use CreatePsr17Factories;
    
    public function toHttpResponse(Throwable $e, Request $request) :AppResponse
    {
        $code = $e instanceof HttpException ? $e->httpStatusCode() : 500;
        $body = $e instanceof HttpException ? $e->getMessage() : 'Internal Server Error';
        $body = $this->psrStreamFactory()->createStream($body);
        
        return new AppResponse(
            $this->psrResponseFactory()->createResponse((int) $code)
                 ->withBody($body)
        );
    }
    
    public function report(Throwable $e, Request $request, string $psr3_log_level = LogLevel::ERROR)
    {
    }
    
}

class Foo implements MiddlewareInterface
{
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) :ResponseInterface
    {
        $test = $request->getAttribute('test', '');
        
        $response = $handler->handle($request->withAttribute('test', $test .= 'foo'));
        
        return $response;
    }
    
}

class Bar implements MiddlewareInterface
{
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) :ResponseInterface
    {
        $test = $request->getAttribute('test', '');
        
        $response = $handler->handle($request->withAttribute('test', $test .= 'bar'));
        
        return $response;
    }
    
}

class ExceptionMiddleware implements MiddlewareInterface
{
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) :ResponseInterface
    {
        throw new HttpException(404, '-Error Message');
    }
    
}

class StopMiddleware implements MiddlewareInterface
{
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) :ResponseInterface
    {
        $test = $request->getAttribute('test', '');
        
        return new AppResponse(new Response(200, [], $test.'STOP'));
    }
    
}

class ChangeLastMiddleware implements MiddlewareInterface
{
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) :ResponseInterface
    {
        $response = $handler->handle($request);
        
        $value = $response->getBody()->__toString();
        
        return $response->withBody(Stream::create('CHANGED'.$value));
    }
    
}

class MiddlewareDependency implements MiddlewareInterface
{
    
    /**
     * @var Bar
     */
    private $bar;
    
    public function __construct(\Tests\Codeception\shared\TestDependencies\Bar $bar)
    {
        $this->bar = $bar;
    }
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) :ResponseInterface
    {
        return (new AppResponse(new Response ()))->withBody(
            Stream::create(strtoupper($this->bar->bar))
        );
    }
    
}

class MiddlewareWithConfig implements MiddlewareInterface
{
    
    /**
     * @var bool
     */
    private $delegate = false;
    
    public function __construct(bool $delegate)
    {
        $this->delegate = $delegate;
    }
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) :ResponseInterface
    {
        if ( ! $this->delegate) {
            return new AppResponse(new Response(404));
        }
        
        return $handler->handle($request);
    }
    
}

class WrongMiddleware
{
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) :ResponseInterface
    {
        return new AppResponse(new Response());
    }
    
}