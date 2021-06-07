<?php


	declare( strict_types = 1 );


	namespace WPEmerge\Facade;


	/**
	 * @mixin WordpressApiMixin
	 */
	class WP extends WpFacade {


        protected static function getFacadeAccessor() : string
        {

			return WordpressApi::class;

		}

	}