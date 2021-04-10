<?php

namespace Servebolt\Composer;

use Composer\EventDispatcher\EventDispatcher;

class PharRunner extends EventDispatcher {

    public function execute($pathAndArgs)
    {
        $exec = $this->getPhpExecCommand() . ' ' . $pathAndArgs;
        return $this->executeTty($exec);
    }

}
