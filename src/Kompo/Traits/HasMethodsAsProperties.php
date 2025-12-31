<?php

namespace Condoedge\Ai\Kompo\Traits;

trait HasMethodsAsProperties
{
    public function __get($property)
    {
        // The idea is that the we can use properties in snake_case to access theme methods in camelCase. $this->primary_gradient -> $this->theme()->primaryGradient()
        $snakeMethod = \Illuminate\Support\Str::snake($property);
        $camelMethod = \Illuminate\Support\Str::camel($snakeMethod);

        if (method_exists($this, $camelMethod)) {
            return ' ' . $this->$camelMethod() . ' ';
        }

        return null;
    }
}