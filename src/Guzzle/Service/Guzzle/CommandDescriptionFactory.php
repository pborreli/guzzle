<?php

namespace Guzzle\Service\Guzzle;

use Guzzle\Service\Command\CommandFactoryInterface;
use Guzzle\Service\Description\DescriptionInterface;

/**
 * Command factory used to create commands based on service descriptions
 */
class CommandDescriptionFactory implements CommandFactoryInterface
{
    /** @var DescriptionInterface */
    private $description;

    /**
     * @param DescriptionInterface $description Service description
     */
    public function __construct(DescriptionInterface $description)
    {
        $this->description = $description;
    }

    public function factory($name, array $args = array())
    {
        // If the command cannot be found, try again with a capital first letter
        if (!$this->description->hasOperation($name)) {
            $name = ucfirst($name);
        }

        $operation = $this->description->getOperation($name);
        if (!$operation) {
            return null;
        }

        $class = $operation->getMetadata('class') ?: 'Guzzle\Service\Guzzle\Command';

        return new $class($args, $operation);
    }
}