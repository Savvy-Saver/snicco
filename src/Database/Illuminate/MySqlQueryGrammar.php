<?php

declare(strict_types=1);

namespace Snicco\Database\Illuminate;

use Illuminate\Database\Query\Grammars\MySqlGrammar as IlluminateQueryGrammar;

class MySqlQueryGrammar extends IlluminateQueryGrammar
{
    
    public function compileRollback() :string
    {
        
        return "ROLLBACK";
        
    }
    
}