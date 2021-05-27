<?php


    declare(strict_types = 1);


    namespace WPEmerge\Session;

    use Psr\Http\Message\ResponseInterface;
    use WPEmerge\Contracts\Middleware;
    use WPEmerge\Http\Delegate;
    use WPEmerge\Http\Psr7\Request;
    use WPEmerge\Http\Responses\NullResponse;
    use WPEmerge\Support\VariableBag;

    class StartSessionMiddleware extends Middleware
    {

        /**
         * @var SessionStore
         */
        private $session_store;

        /**
         * @var array|int[]
         */

        /**
         * @var array
         */
        private $config;

        public function __construct(SessionStore $session_store, array $config )
        {

            $this->session_store = $session_store;
            $this->config = $config;

        }

        public function handle(Request $request, Delegate $next)
        {

            $this->collectGarbage();

            $this->startSession(
                $session = $this->getSession($request),
                $request

            );

            return $this->handleStatefulRequest($request, $session, $next);


        }

        private function getSession(Request $request) : SessionStore
        {

            /** @var VariableBag $cookies */
            $cookies = $request->getAttribute('cookies');
            $cookie_name = $this->session_store->getName();

            $this->session_store->setId($cookies->get($cookie_name, ''));

            return $this->session_store;
        }

        private function startSession(SessionStore $session_store, Request $request)
        {

            $session_store->start();
            $session_store->getHandler()->setRequest($request);

        }

        private function handleStatefulRequest(Request $request, SessionStore $session, Delegate $next) : ResponseInterface
        {

            $request = $request->withAttribute('session', $session);

            $response = $next($request);

            $this->storePreviousUrl($response, $request,  $session);

            $this->saveSession($session);

            return $response;

        }

        private function storePreviousUrl(ResponseInterface $response, Request $request, SessionStore $session)
        {

            if ( $response instanceof NullResponse ) {

                return;

            }

            if ( $request->isGet() && ! $request->isAjax() ) {

                $session->setPreviousUrl($request->fullUrl());

            }


        }

        private function saveSession(SessionStore $session)
        {
            $session->save();
        }

        private function collectGarbage()
        {
            if ($this->configHitsLottery($this->config['lottery'])) {

                $this->session_store->getHandler()->gc($this->getSessionLifetimeInSeconds());

            }
        }

        private function configHitsLottery(array $lottery) : bool
        {
            return random_int(1, $lottery[1]) <= $lottery[0];
        }

        private function getSessionLifetimeInSeconds()
        {
            return $this->config['lifetime'] * 60;
        }

    }