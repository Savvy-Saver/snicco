<?php

declare(strict_types=1);

namespace Snicco\Component\HttpRouting\Http\Responses;

use Snicco\Component\HttpRouting\Http\Psr7\Response;

final class RedirectResponse extends Response
{
    
    private bool $bypass_validation = false;
    
    /**
     * @api
     */
    public function to(string $url)
    {
        return $this->withHeader('Location', $url);
    }
    
    /**
     * @interal
     */
    public function externalRedirectAllowed() :bool
    {
        return $this->bypass_validation;
    }
    
    /**
     * @interal
     */
    public function withExternalRedirectAllowed() :RedirectResponse
    {
        $res = clone $this;
        $res->bypass_validation = true;
        return $res;
    }
    
}
