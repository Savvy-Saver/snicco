<?php


	declare( strict_types = 1 );


	namespace WPEmerge\Contracts;


	use Psr\Http\Message\ResponseFactoryInterface;
    use WPEmerge\Http\Responses\InvalidResponse;
    use WPEmerge\Http\Responses\NullResponse;
    use WPEmerge\Http\Responses\RedirectResponse;
    use WPEmerge\Http\Psr7\Response;

    interface ResponseFactory extends ResponseFactoryInterface {


		public function view ( string $view, array $data = [], $status = 200, array $headers = []) : Response;

		public function toResponse ( $response ) : Response;

        /**
         *
         * Create a blank psr7 response object with given status code and reason phrase.
         *
         * @param  int  $status_code
         * @param  string  $reason_phrase
         *
         * @return \WPEmerge\Http\Psr7\Response
         */
        public function make(int $status_code, string $reason_phrase = '') : Response;

        /**
         *
         * Create a psr7 response with content type text/html and given status code.
         *
         * @param  string  $html
         * @param  int  $status_code
         *
         * @return Response
         */
        public function html(string $html, int $status_code = 200 ) : Response;


        /**
         *
         * Create a psr7 response with content type application/json and given status code.
         * The content will be be json_encoded by this method.
         *
         * @param  mixed  $content
         * @param  int  $status_code
         *
         * @return Response
         */
        public function json($content, int $status = 200 )  : Response;

         /**
          *
          * Create a null response with status code 204.
          *
          * @return \WPEmerge\Http\Responses\NullResponse
          */
        public function null() : NullResponse;

        public function redirect() : RedirectResponse;

        public function invalidResponse () :InvalidResponse;

        public function queryFiltered();


    }